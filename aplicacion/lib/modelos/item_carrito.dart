import '../core/formato.dart';
import 'producto.dart';

/// Una línea del carrito de venta en curso.
///
/// [id] es el identificador local de la línea: un mismo producto puede
/// agregarse varias veces y cada vez genera una línea distinta.
class ItemCarrito {
  ItemCarrito({
    this.id,
    required this.productoId,
    required this.codigo,
    required this.nombre,
    required this.unidad,
    required this.precioVenta,
    required this.cantidad,
    required this.stock,
    this.foto,
  });

  final int? id;
  final int productoId;
  final String codigo;
  final String nombre;
  final String unidad;
  final double precioVenta;
  final double cantidad;
  final double stock;
  final String? foto;

  /// Igual que el backend: el total de la línea se redondea a centavos.
  double get total => (precioVenta * cantidad * 100).roundToDouble() / 100;

  bool get pesable => esPesable(unidad);

  ItemCarrito copiar({int? id, double? cantidad}) => ItemCarrito(
    id: id ?? this.id,
    productoId: productoId,
    codigo: codigo,
    nombre: nombre,
    unidad: unidad,
    precioVenta: precioVenta,
    cantidad: cantidad ?? this.cantidad,
    stock: stock,
    foto: foto,
  );

  factory ItemCarrito.desdeProducto(Producto producto, {double cantidad = 1}) => ItemCarrito(
    productoId: producto.id,
    codigo: producto.codigo,
    nombre: producto.nombre,
    unidad: producto.unidad,
    precioVenta: producto.precioVenta,
    cantidad: cantidad,
    stock: producto.stock,
    foto: producto.foto,
  );

  factory ItemCarrito.desdeFila(Map<String, dynamic> fila) => ItemCarrito(
    id: fila['id'] == null ? null : aEntero(fila['id']),
    productoId: aEntero(fila['producto_id']),
    codigo: (fila['codigo'] ?? '').toString(),
    nombre: (fila['nombre'] ?? '').toString(),
    unidad: (fila['unidad'] ?? 'UND').toString(),
    precioVenta: aDouble(fila['precio_venta']),
    cantidad: aDouble(fila['cantidad']),
    stock: aDouble(fila['stock']),
    foto: fila['foto']?.toString(),
  );

  /// Sin el `id`: lo asigna SQLite al insertar la línea.
  Map<String, Object?> aFila() => {
    'producto_id': productoId,
    'codigo': codigo,
    'nombre': nombre,
    'unidad': unidad,
    'foto': foto,
    'precio_venta': precioVenta,
    'stock': stock,
    'cantidad': cantidad,
    'agregado': DateTime.now().toIso8601String(),
  };
}
