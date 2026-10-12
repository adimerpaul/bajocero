import 'package:bajocero/core/formato.dart';
import 'package:bajocero/datos/repositorios/carrito_repositorio.dart';
import 'package:bajocero/datos/repositorios/producto_repositorio.dart';
import 'package:bajocero/datos/servicios/api_servicio.dart';
import 'package:bajocero/modelos/cliente.dart';
import 'package:bajocero/modelos/item_carrito.dart';
import 'package:bajocero/modelos/producto.dart';
import 'package:bajocero/vistamodelos/venta_vistamodelo.dart';
import 'package:flutter_test/flutter_test.dart';

/// Carrito en memoria: reemplaza a SQLite para poder probar el ViewModel.
class CarritoFalso implements CarritoRepositorio {
  final List<ItemCarrito> lineas = [];
  int _siguienteId = 0;

  @override
  Future<int> agregar(ItemCarrito item) async {
    final id = ++_siguienteId;
    lineas.add(item.copiar(id: id));
    return id;
  }

  @override
  Future<void> actualizarCantidad(int id, double cantidad) async {
    final indice = lineas.indexWhere((l) => l.id == id);
    if (indice >= 0) lineas[indice] = lineas[indice].copiar(cantidad: cantidad);
  }

  @override
  Future<void> quitar(int id) async => lineas.removeWhere((l) => l.id == id);

  @override
  Future<void> limpiar() async => lineas.clear();

  @override
  Future<List<ItemCarrito>> listar() async => List.of(lineas);
}

class ProductosFalso extends ProductoRepositorio {
  ProductosFalso({this.porCodigo, this.porBalanza})
    : super(ApiServicio(urlBase: 'http://localhost/api'));

  /// Producto que devuelve el escáner; `null` simula un código desconocido.
  final Producto? porCodigo;

  /// Producto de la etiqueta de balanza.
  final Producto? porBalanza;

  @override
  Future<List<Producto>> buscarLocal(String texto, {int limite = 50}) async => const [];

  @override
  Future<Producto?> porCodigoBarras(String codigo) async => porCodigo;

  @override
  Future<Producto?> porCodigoBalanza(String codigo) async =>
      porBalanza?.codigo == codigo ? porBalanza : null;
}

Producto crearProducto({double stock = 10}) => Producto(
  id: 1,
  codigo: 'P-1',
  nombre: 'LECHE PIL 1L',
  unidad: 'UND',
  precioCompra: 5,
  precioVenta: 8,
  stock: stock,
);

/// Producto por kilo con código de balanza (7 dígitos, empieza con 2).
Producto crearPollo({double stock = 20}) => Producto(
  id: 2,
  codigo: '2000123',
  nombre: 'PECHUGA DE POLLO',
  unidad: 'KG',
  precioCompra: 20,
  precioVenta: 28.5,
  stock: stock,
);

/// Etiqueta EAN-13 de balanza con el dígito verificador correcto.
String etiqueta(String codigo7, int gramos) {
  final doce = '$codigo7${gramos.toString().padLeft(5, '0')}';
  return '$doce${digitoEan13(doce)}';
}

void main() {
  late CarritoFalso carritos;
  late VentaVistaModelo vm;

  setUp(() {
    carritos = CarritoFalso();
    vm = VentaVistaModelo(carritos, ProductosFalso());
  });

  test('agregar dos veces el mismo producto crea dos líneas', () async {
    final producto = crearProducto();

    expect(await vm.agregar(producto), isTrue);
    expect(await vm.agregar(producto), isTrue);

    expect(vm.lineas, 2);
    expect(vm.cantidadItems, 2);
    expect(vm.items.map((i) => i.id).toSet().length, 2, reason: 'cada línea tiene su id');
    expect(carritos.lineas.length, 2, reason: 'se guardan las dos líneas en la base local');
  });

  test('el stock se controla sumando todas las líneas del producto', () async {
    final producto = crearProducto(stock: 3);

    expect(await vm.agregar(producto, cantidad: 2), isTrue);
    expect(await vm.agregar(producto, cantidad: 2), isFalse, reason: 'sólo queda 1');
    expect(await vm.agregar(producto), isTrue, reason: 'el último sí entra');

    expect(vm.cantidadItems, 2, reason: 'cuenta líneas, no unidades');
    expect(vm.cantidadDeProducto(producto.id), 3);
  });

  test('cambiar la cantidad de una línea considera a las otras', () async {
    final producto = crearProducto(stock: 5);
    await vm.agregar(producto, cantidad: 2);
    await vm.agregar(producto, cantidad: 2);

    await vm.cambiarCantidad(vm.items.first, 4);
    expect(vm.items.first.cantidad, 2, reason: 'excede el stock, no cambia');

    await vm.cambiarCantidad(vm.items.first, 3);
    expect(vm.items.first.cantidad, 3);
    expect(vm.cantidadDeProducto(producto.id), 5);
  });

  test('cantidad cero quita sólo esa línea', () async {
    final producto = crearProducto();
    await vm.agregar(producto);
    await vm.agregar(producto, cantidad: 4);

    await vm.cambiarCantidad(vm.items.first, 0);

    expect(vm.lineas, 1);
    expect(vm.items.single.cantidad, 4);
    expect(carritos.lineas.length, 1);
  });

  test('avisa al agregar y cuando no hay stock', () async {
    final avisos = <Aviso>[];
    vm.avisos.addListener(() {
      final aviso = vm.avisos.value;
      if (aviso != null) avisos.add(aviso);
    });

    await vm.agregar(crearProducto(stock: 1));
    await vm.agregar(crearProducto(stock: 1));

    expect(avisos.first.mensaje, contains('agregado'));
    expect(avisos.first.esError, isFalse, reason: 'suena el pitido de éxito');
    expect(avisos.last.mensaje, contains('Sólo quedan 0'));
    expect(avisos.last.esError, isTrue, reason: 'suena el pitido de error');
  });

  group('escáner', () {
    test('el aviso lleva el código leído adelante del producto', () async {
      final vm = VentaVistaModelo(carritos, ProductosFalso(porCodigo: crearProducto()));
      final avisos = <Aviso>[];
      vm.avisos.addListener(() {
        final aviso = vm.avisos.value;
        if (aviso != null) avisos.add(aviso);
      });

      expect(await vm.agregarPorCodigo('7501234567890'), isTrue);

      expect(avisos.single.mensaje, '7501234567890 · LECHE PIL 1L agregado');
      expect(avisos.single.esError, isFalse);
    });

    test('un código desconocido avisa como error y no toca el carrito', () async {
      final vm = VentaVistaModelo(carritos, ProductosFalso());
      final avisos = <Aviso>[];
      vm.avisos.addListener(() {
        final aviso = vm.avisos.value;
        if (aviso != null) avisos.add(aviso);
      });

      expect(await vm.agregarPorCodigo('0000000000000'), isFalse);

      expect(avisos.single.mensaje, contains('no registrado'));
      expect(avisos.single.esError, isTrue);
      expect(vm.vacio, isTrue);
    });
  });
  test('un producto con stock cero se vende igual, como en el backend', () async {
    expect(await vm.agregar(crearProducto(stock: 0), cantidad: 3), isTrue);
    expect(vm.items.single.cantidad, 3);
  });

  test('los pesados guardan 3 decimales y el total se redondea a centavos', () async {
    final pollo = crearPollo();
    expect(await vm.agregar(pollo, cantidad: 1.2345), isTrue);
    expect(vm.items.single.cantidad, 1.235, reason: 'se redondea a gramos');
    expect(vm.items.single.total, 35.2, reason: '28.5 × 1.235 = 35.1975');

    await vm.aumentar(vm.items.single);
    expect(vm.items.single.cantidad, closeTo(1.335, 1e-9), reason: '«+» suma 100 g');
  });

  group('etiqueta de balanza', () {
    test('entra el producto con el peso de la etiqueta en un solo paso', () async {
      final vm = VentaVistaModelo(carritos, ProductosFalso(porBalanza: crearPollo()));
      expect(await vm.agregarPorCodigo(etiqueta('2000123', 1250)), isTrue);
      expect(vm.items.single.nombre, 'PECHUGA DE POLLO');
      expect(vm.items.single.cantidad, 1.25);
    });

    test('un dígito verificador incorrecto no se toma como etiqueta', () {
      final buena = etiqueta('2000123', 1250);
      final mala = '${buena.substring(0, 12)}${(int.parse(buena[12]) + 1) % 10}';
      expect(leerEtiquetaBalanza(buena), isNotNull);
      expect(leerEtiquetaBalanza(mala), isNull);
    });

    test('rechaza la etiqueta si el producto no es KG', () async {
      final unidad = Producto(
        id: 3,
        codigo: '2000999',
        nombre: 'YOGURT',
        unidad: 'UND',
        precioCompra: 1,
        precioVenta: 2,
        stock: 10,
      );
      final vm = VentaVistaModelo(carritos, ProductosFalso(porBalanza: unidad));
      expect(await vm.agregarPorCodigo(etiqueta('2000999', 500)), isFalse);
      expect(vm.vacio, isTrue);
    });
  });

  group('factura', () {
    test('sin documento o con 0 sale como recibo', () {
      expect(DatosFactura().tipoComprobante, 'RECIBO');
      expect(DatosFactura(numeroDocumento: '0').tipoComprobante, 'RECIBO');
      expect(DatosFactura(numeroDocumento: ' ').aJson(), {'tipo_comprobante': 'RECIBO'});
    });

    test('con NIT se factura y manda los datos del cliente', () {
      final datos = DatosFactura(
        tipoDocumento: TipoDocumento.nit,
        numeroDocumento: '1020304050',
        nombre: 'empresa srl',
        codigoExcepcion: true,
      ).aJson();
      expect(datos['tipo_comprobante'], 'FACTURA');
      expect(datos['tipo_documento'], 'NIT');
      expect(datos['cliente_nombre'], 'EMPRESA SRL');
      expect(datos['codigo_excepcion'], isTrue);
    });
  });
}
