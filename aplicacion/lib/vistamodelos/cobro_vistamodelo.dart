import 'dart:async';

import '../core/uuid.dart';
import '../datos/repositorios/carrito_repositorio.dart';
import '../datos/repositorios/cliente_repositorio.dart';
import '../datos/repositorios/producto_repositorio.dart';
import '../datos/repositorios/venta_repositorio.dart';
import '../modelos/cliente.dart';
import '../modelos/item_carrito.dart';
import '../modelos/venta.dart';
import 'vista_modelo_base.dart';

enum TipoPago {
  efectivo('EFECTIVO', 'Efectivo'),
  qr('QR', 'QR'),
  combinado('COMBINADO', 'Combinado');

  const TipoPago(this.valor, this.etiqueta);

  final String valor;
  final String etiqueta;
}

/// ViewModel de la pantalla "Revisar orden": descuento, forma de pago y cobro.
class CobroVistaModelo extends VistaModeloBase {
  CobroVistaModelo(this._items, this._ventas, this._carritos, this._productos, this._clientes);

  final List<ItemCarrito> _items;
  final VentaRepositorio _ventas;
  final CarritoRepositorio _carritos;
  final ProductoRepositorio _productos;
  final ClienteRepositorio _clientes;

  /// Uno por pantalla de cobro: si el primer intento falla sin respuesta, el
  /// reintento manda el mismo uuid y el backend no la registra dos veces.
  final String _uuid = nuevoUuid();

  final DatosFactura cliente = DatosFactura();
  List<Cliente> _sugerencias = [];
  bool _buscandoCliente = false;
  bool _verificandoNit = false;
  bool? _nitValido;
  Timer? _reboteCliente;
  Timer? _reboteNit;
  int _pedido = 0;

  TipoPago _tipoPago = TipoPago.efectivo;
  double _descuento = 0;
  double _efectivoCombinado = 0;
  double _recibido = 0;
  String _observacion = '';

  List<ItemCarrito> get items => List.unmodifiable(_items);
  TipoPago get tipoPago => _tipoPago;
  double get descuento => _descuento;
  double get recibido => _recibido;
  String get observacion => _observacion;
  List<Cliente> get sugerencias => List.unmodifiable(_sugerencias);
  bool get buscandoCliente => _buscandoCliente;
  bool get verificandoNit => _verificandoNit;

  /// Padrón del SIN: `true` activo, `false` no figura, `null` sin verificar.
  bool? get nitValido => _nitValido;

  double get subtotal => _items.fold(0.0, (suma, item) => suma + item.total);
  double get total => _redondear(subtotal - _descuento);
  int get cantidadItems => _items.length;

  double get montoEfectivo => switch (_tipoPago) {
    TipoPago.efectivo => total,
    TipoPago.qr => 0,
    TipoPago.combinado => _redondear(_efectivoCombinado.clamp(0, total).toDouble()),
  };

  double get montoQr => _redondear(total - montoEfectivo);

  /// Cambio a devolver cuando el cliente entrega un billete mayor.
  double get cambio {
    if (_tipoPago == TipoPago.qr) return 0;
    final diferencia = _recibido - montoEfectivo;
    return diferencia > 0 ? _redondear(diferencia) : 0;
  }

  String? get advertencia {
    if (_items.isEmpty) return 'El carrito está vacío';
    if (_descuento > subtotal) return 'El descuento no puede superar el subtotal';
    if (total <= 0) return 'El total debe ser mayor a cero';
    if (_tipoPago == TipoPago.combinado && montoEfectivo <= 0) {
      return 'Indique cuánto paga en efectivo';
    }
    if (cliente.factura && cliente.nombre.trim().isEmpty) {
      return 'Ingrese el nombre o razón social del cliente';
    }
    return null;
  }

  bool get puedeCobrar => advertencia == null && !cargando;

  void cambiarTipoPago(TipoPago tipo) {
    _tipoPago = tipo;
    if (tipo == TipoPago.combinado && _efectivoCombinado <= 0) {
      _efectivoCombinado = 0;
    }
    notificar();
  }

  void cambiarDescuento(String texto) {
    _descuento = _aNumero(texto);
    notificar();
  }

  void cambiarEfectivoCombinado(String texto) {
    _efectivoCombinado = _aNumero(texto);
    notificar();
  }

  void cambiarRecibido(String texto) {
    _recibido = _aNumero(texto);
    notificar();
  }

  void cambiarObservacion(String texto) {
    _observacion = texto;
  }

  // ── Datos del cliente (factura) ──────────────────────────
  // Misma lógica que `ClienteFactura.vue` del panel web.

  void cambiarTipoDocumento(TipoDocumento tipo) {
    cliente.tipoDocumento = tipo;
    _nitValido = null;
    if (tipo != TipoDocumento.nit) cliente.codigoExcepcion = false;
    if (tipo != TipoDocumento.ci) cliente.complemento = '';
    cliente.numeroDocumento = _limpiarDocumento(cliente.numeroDocumento, tipo);
    notificar();
    _alCambiarDocumento();
  }

  /// Devuelve el documento ya limpio para que la vista lo refleje.
  String cambiarDocumento(String texto) {
    cliente.numeroDocumento = _limpiarDocumento(texto, cliente.tipoDocumento);
    _nitValido = null;
    notificar();
    _alCambiarDocumento();
    return cliente.numeroDocumento;
  }

  void cambiarComplemento(String texto) => cliente.complemento = texto;

  void cambiarNombre(String texto) {
    cliente.nombre = texto;
    notificar();
  }

  void cambiarEmail(String texto) => cliente.email = texto;

  void cambiarExcepcion(bool valor) {
    cliente.codigoExcepcion = valor;
    notificar();
  }

  void elegirCliente(Cliente elegido) {
    cliente
      ..tipoDocumento = elegido.tipoDocumento
      ..numeroDocumento = _limpiarDocumento(elegido.numeroDocumento, elegido.tipoDocumento)
      ..complemento = elegido.complemento
      ..nombre = elegido.nombre
      ..email = elegido.email;
    _sugerencias = [];
    notificar();
    if (cliente.tipoDocumento == TipoDocumento.nit) verificarNit();
  }

  void cerrarSugerencias() {
    if (_sugerencias.isEmpty) return;
    _sugerencias = [];
    notificar();
  }

  void _alCambiarDocumento() {
    final documento = cliente.documento;
    _reboteCliente?.cancel();
    _reboteNit?.cancel();
    if (documento.length < 3) {
      _sugerencias = [];
    } else {
      _reboteCliente = Timer(const Duration(milliseconds: 300), () => _buscarCliente(documento));
    }
    if (cliente.tipoDocumento == TipoDocumento.nit && documento.length >= 5) {
      _reboteNit = Timer(const Duration(milliseconds: 500), verificarNit);
    }
  }

  Future<void> _buscarCliente(String documento) async {
    final pedido = ++_pedido;
    _buscandoCliente = true;
    notificar();
    try {
      final encontrados = await _clientes.buscar(documento);
      if (pedido != _pedido) return;
      // Coincidencia exacta y sin nombre escrito: se completa sola.
      final exacto = encontrados.where(
        (c) => c.numeroDocumento == documento && c.tipoDocumento == cliente.tipoDocumento,
      );
      if (exacto.isNotEmpty && cliente.nombre.trim().isEmpty) {
        final c = exacto.first;
        cliente
          ..complemento = c.complemento
          ..nombre = c.nombre
          ..email = c.email;
      }
      _sugerencias = encontrados;
    } catch (_) {
      // Sin conexión o sin permiso: se escribe el nombre a mano.
      _sugerencias = [];
    } finally {
      if (pedido == _pedido) _buscandoCliente = false;
      notificar();
    }
  }

  /// Consulta el NIT en el padrón del SIN. Si no figura se marca la excepción
  /// (código 1), igual que el panel web; el vendedor puede desmarcarla.
  Future<void> verificarNit() async {
    if (cliente.tipoDocumento != TipoDocumento.nit || cliente.documento.length < 5) return;
    final nit = cliente.documento;
    _verificandoNit = true;
    notificar();
    try {
      final valido = await _clientes.verificarNit(nit);
      if (nit != cliente.documento) return;
      _nitValido = valido;
      if (valido == false) cliente.codigoExcepcion = true;
    } catch (_) {
      _nitValido = null;
    } finally {
      _verificandoNit = false;
      notificar();
    }
  }

  String _limpiarDocumento(String texto, TipoDocumento tipo) => tipo.soloNumeros
      ? texto.replaceAll(RegExp(r'\D'), '')
      : texto.replaceAll(RegExp(r'[^a-zA-Z0-9]'), '').toUpperCase();

  @override
  void dispose() {
    _reboteCliente?.cancel();
    _reboteNit?.cancel();
    super.dispose();
  }

  /// Envía la venta a la API, vacía el carrito y ajusta el stock cacheado.
  Future<Venta?> cobrar() async {
    if (advertencia != null) {
      establecerError(advertencia);
      return null;
    }

    return ejecutar(() async {
      final venta = await _ventas.registrar(
        items: _items,
        descuento: _redondear(_descuento),
        tipoPago: _tipoPago.valor,
        montoEfectivo: montoEfectivo,
        montoQr: montoQr,
        uuid: _uuid,
        cliente: cliente,
        observacion: _observacion.trim(),
      );

      await _carritos.limpiar();

      // Varias líneas pueden ser del mismo producto: se suman antes de
      // descontar el stock cacheado.
      final descuentos = <int, double>{};
      for (final item in _items) {
        descuentos[item.productoId] = (descuentos[item.productoId] ?? 0) + item.cantidad;
      }
      await _productos.descontarStock(descuentos);

      return venta;
    });
  }

  double _redondear(double valor) => (valor * 100).roundToDouble() / 100;

  double _aNumero(String texto) {
    final limpio = texto.trim().replaceAll(',', '.');
    return double.tryParse(limpio) ?? 0;
  }
}
