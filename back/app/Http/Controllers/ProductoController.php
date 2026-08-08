<?php

namespace App\Http\Controllers;

use App\Exports\PlantillaStockExport;
use App\Exports\ProductosExport;
use App\Imports\StockImport;
use App\Models\Categoria;
use App\Models\Lote;
use App\Models\Producto;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class ProductoController extends Controller
{
    /** Columnas por las que se permite ordenar el listado. */
    private const SORTABLE = [
        'nombre', 'codigo', 'codigo_barras', 'categoria', 'unidad',
        'precio_compra', 'precio_venta', 'stock_inicial', 'created_at',
    ];

    public function index(Request $request)
    {
        $this->authorizeAction($request, 'Ver Productos');
        $perPage = (int) $request->input('per_page', 20);

        return response()->json($this->filteredQuery($request)->paginate($perPage === 0 ? 500 : min(max($perPage, 1), 500)));
    }

    public function exportExcel(Request $request)
    {
        $this->authorizeAction($request, 'Ver Productos');

        return Excel::download(
            new ProductosExport($this->filteredQuery($request)->get()),
            'productos_'.now()->format('Ymd_His').'.xlsx'
        );
    }

    public function exportPdf(Request $request)
    {
        $this->authorizeAction($request, 'Ver Productos');
        // DomPDF consume ~0.5 MB por fila: el catálogo completo (1800+) necesita cerca de 1 GB y 15 s.
        ini_set('memory_limit', '2048M');
        set_time_limit(300);
        $query = $this->filteredQuery($request);
        abort_if($query->count() > 3000, 422, 'El reporte PDF admite hasta 3000 productos. Aplica filtros o descarga el Excel.');
        $productos = $query->get();
        $resumen = [
            'cantidad' => $productos->count(),
            'valor_compra' => $productos->sum(fn ($p) => (float) $p->stock_inicial * (float) $p->precio_compra),
            'valor_venta' => $productos->sum(fn ($p) => (float) $p->stock_inicial * (float) $p->precio_venta),
        ];
        $filtros = collect([
            ($search = trim((string) $request->input('q'))) ? "Búsqueda: {$search}" : null,
            ($categoriaId = $request->integer('categoria_id')) ? 'Categoría: '.(Categoria::find($categoriaId)?->nombre ?? $categoriaId) : null,
            match ($request->input('stock')) {
                'con' => 'Solo con stock',
                'sin' => 'Solo sin stock',
                'bajo' => 'Stock bajo (hasta 10)',
                default => null,
            },
        ])->filter()->implode(' · ');

        return Pdf::loadView('productos.reporte', compact('productos', 'resumen', 'filtros'))
            ->setPaper('letter', 'landscape')
            ->download('productos_'.now()->format('Ymd_His').'.pdf');
    }

    /** Descarga el modelo de llenado rápido (Código, Producto, Cantidad) respetando los filtros vigentes. */
    public function plantillaStock(Request $request)
    {
        $this->authorizeAction($request, 'Editar Productos');

        return Excel::download(
            new PlantillaStockExport($this->filteredQuery($request)->get()),
            'modelo_cantidades_'.now()->format('Ymd_His').'.xlsx'
        );
    }

    /**
     * Lee el modelo llenado y devuelve los cambios que provocaría.
     * Con confirmar=1 los aplica; sin él solo previsualiza, que es lo que alimenta el diálogo.
     */
    public function importarStock(Request $request)
    {
        $this->authorizeAction($request, 'Editar Productos');
        $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:8192'],
            'confirmar' => ['nullable', 'boolean'],
        ]);

        [$changes, $errors, $unchanged] = $this->readStockSheet($request->file('archivo'));

        if (! $request->boolean('confirmar')) {
            return response()->json([
                'cambios' => array_values($changes),
                'errores' => $errors,
                'sin_cambio' => $unchanged,
                'total_cambios' => count($changes),
            ]);
        }

        abort_if($changes === [], 422, 'El archivo no tiene cantidades distintas a las actuales');

        $applied = DB::transaction(function () use ($changes) {
            $done = 0;
            foreach ($changes as $change) {
                $product = Producto::lockForUpdate()->find($change['producto_id']);
                if (! $product) {
                    continue;
                }
                // Mismo criterio que AlmacenController@apply: la cantidad del archivo manda y los lotes se reconcilian.
                $before = round((float) $product->stock_inicial, 3);
                $counted = round((float) $change['nuevo'], 3);
                $difference = round($counted - $before, 3);

                if ($difference > 0.0001) {
                    Lote::create([
                        'producto_id' => $product->id,
                        'lote' => null,
                        'fecha_vencimiento' => null,
                        'cantidad_inicial' => $difference,
                        'cantidad_disponible' => $difference,
                    ]);
                } elseif ($difference < -0.0001) {
                    $this->consumeLots($product->id, abs($difference));
                }

                $product->update(['stock_inicial' => $counted]);
                $done++;
            }

            return $done;
        });

        return response()->json([
            'message' => "Se actualizaron {$applied} productos",
            'actualizados' => $applied,
            'errores' => $errors,
        ]);
    }

    /** Devuelve [cambios, errores, filas sin cambio] a partir del archivo subido. */
    private function readStockSheet($file): array
    {
        $sheets = Excel::toArray(new StockImport, $file);
        $rows = $sheets[0] ?? [];
        abort_if(count($rows) < 2, 422, 'El archivo está vacío o no tiene el formato del modelo');

        $changes = [];
        $errors = [];
        $unchanged = 0;
        $qtyIndex = $this->quantityColumn($rows[0] ?? []);

        foreach ($rows as $index => $row) {
            if ($index === 0) {
                continue; // encabezado
            }
            $line = $index + 1;
            $code = $this->normalizeCode($row[0] ?? '');
            $quantity = $row[$qtyIndex] ?? null;

            if ($code === '') {
                continue; // fila vacía
            }
            if ($quantity === null || trim((string) $quantity) === '') {
                continue; // sin cantidad anotada: la fila no se toca
            }
            if (! is_numeric($quantity)) {
                $errors[] = "Fila {$line} ({$code}): la cantidad «{$quantity}» no es un número";

                continue;
            }
            if ((float) $quantity < 0) {
                $errors[] = "Fila {$line} ({$code}): la cantidad no puede ser negativa";

                continue;
            }
            if (isset($changes[$code])) {
                $errors[] = "Fila {$line}: el código {$code} está repetido en el archivo";

                continue;
            }

            $product = $this->findByCode($code);
            if (! $product) {
                $errors[] = "Fila {$line}: no existe un producto con código {$code}";

                continue;
            }

            $current = round((float) $product->stock_inicial, 3);
            $next = round((float) $quantity, 3);
            if (abs($next - $current) < 0.0001) {
                $unchanged++;

                continue;
            }

            $changes[$code] = [
                'producto_id' => $product->id,
                'codigo' => $product->codigo,
                'nombre' => $product->nombre,
                'unidad' => $product->unidad,
                'actual' => $current,
                'nuevo' => $next,
                'diferencia' => round($next - $current, 3),
            ];
        }

        return [$changes, $errors, $unchanged];
    }

    /**
     * Localiza la columna que se importa: "Cantidad anotada" en el modelo actual,
     * o "Cantidad" en modelos descargados antes de que existiera esa columna.
     */
    private function quantityColumn(array $header): int
    {
        $labels = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $header);
        foreach ($labels as $index => $label) {
            if (str_contains($label, 'anotada')) {
                return $index;
            }
        }
        foreach ($labels as $index => $label) {
            if (str_contains($label, 'cantidad')) {
                return $index;
            }
        }

        return 2;
    }

    private function normalizeCode($value): string
    {
        return mb_strtoupper(trim((string) $value));
    }

    /**
     * Busca el producto tolerando la notación científica.
     * Hace falta por partida doble: Excel convierte los códigos largos a número, y además
     * hay productos cuyo código quedó guardado ya en notación científica ("3.5685...E+16").
     * Por eso se prueba el texto tal cual y, si no aparece, su equivalente en entero.
     */
    private function findByCode(string $code): ?Producto
    {
        $product = Producto::where('codigo', $code)->first();
        if ($product || ! is_numeric($code)) {
            return $product;
        }

        $plain = sprintf('%.0F', (float) $code);

        return $plain === $code ? null : Producto::where('codigo', $plain)->first();
    }

    /** Descuenta la diferencia de los lotes vigentes, FIFO por vencimiento. */
    private function consumeLots(int $productId, float $quantity): void
    {
        $remaining = $quantity;
        $lots = Lote::where('producto_id', $productId)->where('cantidad_disponible', '>', 0)
            ->orderByRaw('fecha_vencimiento IS NULL')
            ->orderBy('fecha_vencimiento')->orderBy('id')->lockForUpdate()->get();

        foreach ($lots as $lot) {
            if ($remaining <= 0.0001) {
                break;
            }
            $taken = min($remaining, (float) $lot->cantidad_disponible);
            $lot->decrement('cantidad_disponible', $taken);
            $remaining = round($remaining - $taken, 3);
        }
    }

    private function filteredQuery(Request $request)
    {
        $query = Producto::with('categoriaRelacion:id,nombre,color');

        if ($search = trim((string) $request->input('q'))) {
            $query->where(fn ($q) => $q->where('codigo', 'like', "%{$search}%")
                ->orWhere('nombre', 'like', "%{$search}%")
                ->orWhere('codigo_barras', 'like', "%{$search}%")
                ->orWhere('categoria', 'like', "%{$search}%"));
        }
        if ($categoriaId = $request->integer('categoria_id')) {
            $query->where('categoria_id', $categoriaId);
        }
        if ($unidad = trim((string) $request->input('unidad'))) {
            $query->where('unidad', mb_strtoupper($unidad));
        }
        match ($request->input('stock')) {
            'con' => $query->where('stock_inicial', '>', 0),
            'sin' => $query->where('stock_inicial', '<=', 0),
            'bajo' => $query->whereBetween('stock_inicial', [0.001, 10]),
            default => null,
        };

        $sortBy = in_array($request->input('sort_by'), self::SORTABLE, true) ? $request->input('sort_by') : 'nombre';
        $direction = $request->input('sort_dir') === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($sortBy, $direction)->orderBy('id');
    }

    public function catalogos(Request $request)
    {
        $this->authorizeAction($request, 'Ver Productos');

        return response()->json([
            'categorias' => Categoria::orderBy('nombre')->get(['id', 'nombre', 'color']),
            'unidades' => Producto::whereNotNull('unidad')->distinct()->orderBy('unidad')->pluck('unidad'),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAction($request, 'Crear Productos');

        return response()->json(Producto::create($this->validatedData($request)), 201);
    }

    public function storeCategoria(Request $request)
    {
        $this->authorizeAction($request, 'Crear Productos');
        $data = $request->validate(['nombre' => ['required', 'string', 'max:100'], 'color' => ['nullable', 'string', 'max:30']]);
        $data['nombre'] = mb_strtoupper(trim($data['nombre']));

        return response()->json(Categoria::create($data), 201);
    }

    public function updateCategoria(Request $request, Categoria $categoria)
    {
        $this->authorizeAction($request, 'Editar Productos');
        $data = $request->validate(['nombre' => ['required', 'string', 'max:100'], 'color' => ['nullable', 'string', 'max:30']]);
        $data['nombre'] = mb_strtoupper(trim($data['nombre']));
        $categoria->update($data);
        Producto::where('categoria_id', $categoria->id)->update(['categoria' => $categoria->nombre]);

        return response()->json($categoria->fresh());
    }

    public function destroyCategoria(Request $request, Categoria $categoria)
    {
        $this->authorizeAction($request, 'Eliminar Productos');
        abort_if($categoria->productos()->exists(), 422, 'No se puede eliminar una categoría que tiene productos');
        $categoria->delete();

        return response()->json(['message' => 'Categoría eliminada']);
    }

    public function updateBarcode(Request $request, Producto $producto)
    {
        $this->authorizeAction($request, 'Editar Productos');
        $data = $request->validate([
            'codigo_barras' => ['nullable', 'string', 'max:100', Rule::unique('productos')->whereNull('deleted_at')->ignore($producto)],
        ]);
        $producto->update($data);

        return response()->json($producto->fresh());
    }

    public function update(Request $request, Producto $producto)
    {
        $this->authorizeAction($request, 'Editar Productos');
        $producto->update($this->validatedData($request, $producto));

        return response()->json($producto->fresh());
    }

    public function destroy(Request $request, Producto $producto)
    {
        $this->authorizeAction($request, 'Eliminar Productos');
        $this->deletePhoto($producto);
        $producto->delete();

        return response()->json(['message' => 'Producto eliminado correctamente']);
    }

    private function validatedData(Request $request, ?Producto $producto = null): array
    {
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:50', Rule::unique('productos')->whereNull('deleted_at')->ignore($producto)],
            'codigo_barras' => ['nullable', 'string', 'max:100', Rule::unique('productos')->whereNull('deleted_at')->ignore($producto)],
            'nombre' => ['required', 'string', 'max:255'],
            'categoria' => ['nullable', 'string', 'max:100'],
            'categoria_id' => ['nullable', 'exists:categorias,id'],
            'unidad' => ['required', 'string', 'max:20'],
            'precio_compra' => ['required', 'numeric', 'min:0'],
            'precio_venta' => ['required', 'numeric', 'min:0'],
            'stock_inicial' => ['required', 'numeric', 'min:0', 'decimal:0,3'],
        ]);
        foreach (['codigo', 'nombre', 'categoria', 'unidad'] as $field) {
            $data[$field] = isset($data[$field]) && $data[$field] !== null
                ? mb_strtoupper(trim($data[$field])) : null;
        }
        if (! empty($data['categoria_id'])) {
            $data['categoria'] = Categoria::find($data['categoria_id'])?->nombre;
        }

        return $data;
    }

    public function uploadPhoto(Request $request, Producto $producto)
    {
        $this->authorizeAction($request, 'Editar Productos');
        $request->validate(['foto' => ['required', 'image', 'max:8192']]);

        $file = $request->file('foto');

        return $this->savePhoto($producto, file_get_contents($file->getPathname()));
    }

    public function uploadPhotoFromUrl(Request $request, Producto $producto)
    {
        $this->authorizeAction($request, 'Editar Productos');
        $data = $request->validate(['url' => ['required', 'url:http,https', 'max:2048']]);
        $host = parse_url($data['url'], PHP_URL_HOST);
        abort_unless($host && $this->isPublicHost($host), 422, 'La dirección de imagen no está permitida');

        $response = Http::timeout(12)->connectTimeout(5)
            ->withHeaders(['User-Agent' => 'BajoCero/1.0'])
            ->get($data['url']);
        abort_unless($response->successful(), 422, 'No se pudo descargar la imagen');
        abort_unless(str_starts_with(strtolower($response->header('Content-Type', '')), 'image/'), 422, 'La dirección no corresponde a una imagen');
        abort_if(strlen($response->body()) > 8 * 1024 * 1024, 422, 'La imagen supera el máximo de 8 MB');

        return $this->savePhoto($producto, $response->body());
    }

    private function savePhoto(Producto $producto, string $contents)
    {
        $this->deletePhoto($producto);
        $image = imagecreatefromstring($contents);
        abort_unless($image, 422, 'No se pudo procesar la fotografía');

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, 700 / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));
        $output = imagecreatetruecolor($newWidth, $newHeight);
        $white = imagecolorallocate($output, 255, 255, 255);
        imagefill($output, 0, 0, $white);
        imagecopyresampled($output, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $directory = public_path('images/productos');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $filename = "producto_{$producto->id}_".time().'.webp';
        imagewebp($output, "{$directory}/{$filename}", 85);
        imagedestroy($image);
        imagedestroy($output);

        $producto->update(['foto' => "productos/{$filename}"]);

        return response()->json($producto->fresh());
    }

    private function isPublicHost(string $host): bool
    {
        $addresses = gethostbynamel($host) ?: [];
        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    private function deletePhoto(Producto $producto): void
    {
        if ($producto->foto) {
            $path = public_path('images/'.$producto->foto);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function authorizeAction(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermissionTo($permission), 403, 'No tiene permiso para realizar esta acción');
    }
}
