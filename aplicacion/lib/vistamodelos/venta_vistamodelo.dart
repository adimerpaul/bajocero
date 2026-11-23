import 'dart:async';

import 'package:flutter/foundation.dart';

import '../core/formato.dart';
import '../datos/repositorios/carrito_repositorio.dart';
import '../datos/repositorios/producto_repositorio.dart';
import '../modelos/item_carrito.dart';
import '../modelos/producto.dart';
import 'vista_modelo_base.dart';

/// Aviso puntual para la vista: el texto y si hay que tratarlo como error.
///
/// La vista lo usa para dos cosas: el color del mensaje y cuál de los dos
/// pitidos suena.
class Aviso {
  const Aviso(this.mensaje, {this.esError = false});

  final String mensaje;
  final bool esError;
}

/// ViewModel del punto de venta: carrito en curso y buscador de productos.
class VentaVistaModelo extends VistaModeloBase {
  VentaVistaModelo(this._carritos, this._productos);

  final CarritoRepositorio _carritos;
  final ProductoRepositorio _productos;

  final List<ItemCarrito> _items = [];
  List<Producto> _resultados = [];
  String _busqueda = '';
  bool _escaneando = false;
  Timer? _rebote;

  /// Mensajes puntuales para la vista (producto agregado, sin stock, …).
  ///
  /// Va aparte de `notifyListeners` a propósito: la vista lo escucha con un
  /// listener y muestra el aviso fuera del `build`, nunca durante uno.
  final ValueNotifier<Aviso?> avisos = ValueNotifier<Aviso?>(null);

  /// Producto por kilo que entró por su código normal (sin etiqueta de
  /// balanza): la vista abre el teclado para escribir el peso.
  final ValueNotifier<Producto?> pedirPeso = ValueNotifier<Producto?>(null);

  List<ItemCarrito> get items => List.unmodifiable(_items);
  List<Producto> get resultados => List.unmodifiable(_resultados);
  String get busqueda => _busqueda;
  bool get escaneando => _escaneando;
  bool get buscando => _busqueda.trim().isNotEmpty;
  bool get vacio => _items.isEmpty;

  /// Líneas del carrito (con productos por kilo no se suman cantidades).
  int get cantidadItems => _items.length;
  int get lineas => _items.length;
  double get subtotal => _items.fold(0.0, (suma, item) => suma + item.total);

  /// Cantidad total comprometida de un producto sumando todas sus líneas.
  double cantidadDeProducto(int productoId, {int? exceptoLinea}) => _items
      .where((item) => item.productoId == productoId && item.id != exceptoLinea)
      .fold(0.0, (suma, item) => suma + item.cantidad);

  /// Paso de los botones «+» / «−»: 100 g para los pesados, 1 para el resto.
  static double paso(String unidad) => esPesable(unidad) ? 0.1 : 1;

  /// Mínimo vendible: 1 g para los pesados, 1 unidad para el resto.
  static double minimo(String unidad) => esPesable(unidad) ? 0.001 : 1;

  Future<void> iniciar() async {
    await ejecutar(() async {
      _items
        ..clear()
        ..addAll(await _carritos.listar());
      _resultados = await _productos.buscarLocal('');
    });
  }

  void alternarEscaner() {
    _escaneando = !_escaneando;
    notificar();
  }

  void cerrarEscaner() {
    if (!_escaneando) return;
    _escaneando = false;
    notificar();
  }

  /// Búsqueda con rebote para no consultar SQLite en cada tecla.
  void buscar(String texto) {
    _busqueda = texto;
    notificar();
    _rebote?.cancel();
    _rebote = Timer(const Duration(milliseconds: 220), () async {
      _resultados = await _productos.buscarLocal(texto);
      notificar();
    });
  }

  void limpiarBusqueda() {
    _rebote?.cancel();
    _busqueda = '';
    buscar('');
  }

  /// Agrega el producto **como una línea nueva**, aunque ya esté en el carrito.
  /// Devuelve `false` si no hay stock suficiente contando las demás líneas.
  ///
  /// Como en el backend, un producto **con stock cero se vende igual** (la
  /// venta no lo descuenta); el control sólo aplica si tiene stock cargado.
  ///
  /// `codigoLeido` llega sólo cuando el producto entró por el escáner; se
  /// antepone al aviso para que el vendedor vea qué código se leyó.
  Future<bool> agregar(Producto producto, {double cantidad = 1, String? codigoLeido}) async {
    final prefijo = codigoLeido == null ? '' : '$codigoLeido · ';
    cantidad = redondearCantidad(cantidad);
    if (cantidad <= 0) return false;

    final comprometido = cantidadDeProducto(producto.id);
    if (producto.stock > 0 && comprometido + cantidad > producto.stock + 0.0001) {
      final quedan = producto.stock - comprometido;
      _avisar(
        '${prefijo}Sólo quedan ${cantidadTexto(quedan < 0 ? 0 : quedan, producto.unidad)} '
        '${producto.unidad} de ${producto.nombre}',
        esError: true,
      );
      return false;
    }

    final item = ItemCarrito.desdeProducto(producto, cantidad: cantidad);
    final id = await _carritos.agregar(item);
    _items.add(item.copiar(id: id));
    notificar();
    final detalle = esPesable(producto.unidad) || cantidad != 1
        ? ' (${cantidadTexto(cantidad, producto.unidad)} ${producto.unidad})'
        : '';
    _avisar('$prefijo${producto.nombre}$detalle agregado');
    return true;
  }

  Future<void> cambiarCantidad(ItemCarrito item, double cantidad) async {
    cantidad = redondearCantidad(cantidad);
    if (cantidad < minimo(item.unidad)) return quitar(item);

    final otras = cantidadDeProducto(item.productoId, exceptoLinea: item.id);
    if (item.stock > 0 && otras + cantidad > item.stock + 0.0001) {
      final quedan = item.stock - otras;
      _avisar(
        'Sólo quedan ${cantidadTexto(quedan < 0 ? 0 : quedan, item.unidad)} ${item.unidad} '
        'de ${item.nombre}',
        esError: true,
      );
      return;
    }

    final indice = _items.indexWhere((i) => i.id == item.id);
    if (indice < 0) return;
    _items[indice] = item.copiar(cantidad: cantidad);
    notificar();
    if (item.id != null) await _carritos.actualizarCantidad(item.id!, cantidad);
  }

  Future<void> aumentar(ItemCarrito item) =>
      cambiarCantidad(item, item.cantidad + paso(item.unidad));

  Future<void> disminuir(ItemCarrito item) =>
      cambiarCantidad(item, item.cantidad - paso(item.unidad));

  Future<void> quitar(ItemCarrito item) async {
    _items.removeWhere((i) => i.id == item.id);
    notificar();
    if (item.id != null) await _carritos.quitar(item.id!);
  }

  Future<void> limpiar() async {
    _items.clear();
    notificar();
    await _carritos.limpiar();
  }

  /// Agrega el producto leído por el escáner. Devuelve `true` si entró al
  /// carrito; `false` si el código no existe o no hay stock.
  ///
  /// Una **etiqueta de balanza** (EAN-13 que empieza con 2) trae el código y
  /// el peso: entra de un solo golpe con ese peso, igual que en el panel web.
  /// Un producto por kilo leído por su código normal pide el peso a la vista.
  Future<bool> agregarPorCodigo(String codigo) async {
    final balanza = leerEtiquetaBalanza(codigo);
    if (balanza != null) {
      final producto = await _productos.porCodigoBalanza(balanza.codigo);
      if (producto == null) {
        _avisar('No existe un producto con código de balanza ${balanza.codigo}', esError: true);
        return false;
      }
      if (!esPesable(producto.unidad)) {
        _avisar('${producto.nombre} debe tener unidad KG', esError: true);
        return false;
      }
      return agregar(producto, cantidad: balanza.peso, codigoLeido: codigo);
    }

    final producto = await _productos.porCodigoBarras(codigo);
    if (producto == null) {
      _avisar('Código $codigo no registrado', esError: true);
      return false;
    }
    if (esPesable(producto.unidad)) {
      pedirPeso.value = null;
      pedirPeso.value = producto;
      return true;
    }
    return agregar(producto, codigoLeido: codigo);
  }

  /// Tras registrar una venta el carrito ya se vació en la base local.
  Future<void> recargarDespuesDeVenta() async {
    _items
      ..clear()
      ..addAll(await _carritos.listar());
    _resultados = await _productos.buscarLocal(_busqueda);
    notificar();
  }

  void _avisar(String mensaje, {bool esError = false}) {
    // El null intermedio hace que el aviso se emita aunque se repita el texto
    // (agregar dos veces el mismo producto, por ejemplo).
    avisos.value = null;
    avisos.value = Aviso(mensaje, esError: esError);
  }

  @override
  void dispose() {
    _rebote?.cancel();
    avisos.dispose();
    pedirPeso.dispose();
    super.dispose();
  }
}
