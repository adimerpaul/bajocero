import 'dart:convert';

import '../core/formato.dart';

class DetalleVenta {
  DetalleVenta({
    required this.nombre,
    required this.cantidad,
    required this.unidad,
    this.precioVenta = 0,
    this.total = 0,
    this.foto,
  });

  final String nombre;
  final double cantidad;
  final String unidad;
  final double precioVenta;
  final double total;
  final String? foto;

  factory DetalleVenta.desdeJson(Map<String, dynamic> json) => DetalleVenta(
    nombre: (json['nombre'] ?? '').toString(),
    cantidad: aDouble(json['cantidad']),
    unidad: (json['unidad'] ?? '').toString(),
    precioVenta: aDouble(json['precio_venta']),
    total: aDouble(json['total']),
    foto: json['foto']?.toString(),
  );

  Map<String, Object?> aJson() => {
    'nombre': nombre,
    'cantidad': cantidad,
    'unidad': unidad,
    'precio_venta': precioVenta,
    'total': total,
    'foto': foto,
  };
}

class Venta {
  Venta({
    required this.id,
    required this.numero,
    required this.fecha,
    required this.subtotal,
    required this.descuento,
    required this.total,
    required this.tipoPago,
    required this.montoEfectivo,
    required this.montoQr,
    required this.estado,
    required this.usuarioNombre,
    required this.detalles,
    this.observacion,
    this.tipoComprobante = 'RECIBO',
    this.numeroFactura,
    this.estadoSiat,
    this.siatMensaje,
    this.cuf,
    this.clienteNombre = '',
    this.numeroDocumento = '',
  });

  final int id;
  final String numero;
  final DateTime? fecha;
  final double subtotal;
  final double descuento;
  final double total;
  final String tipoPago;
  final double montoEfectivo;
  final double montoQr;
  final String estado;
  final String usuarioNombre;
  final List<DetalleVenta> detalles;
  final String? observacion;

  // Facturación SIAT: con documento del cliente la venta sale como FACTURA.
  final String tipoComprobante;
  final int? numeroFactura;

  /// PENDIENTE, VALIDADA, PENDIENTE_EVENTO (emitida sin conexión), RECHAZADA, ANULADA.
  final String? estadoSiat;
  final String? siatMensaje;
  final String? cuf;
  final String clienteNombre;
  final String numeroDocumento;

  bool get esFactura => tipoComprobante == 'FACTURA';

  bool get anulada => estado == 'ANULADA';

  /// Líneas de la venta: con productos por kilo no tiene sentido sumar cantidades.
  int get cantidadItems => detalles.length;

  factory Venta.desdeApi(Map<String, dynamic> json) => Venta(
    id: aEntero(json['id']),
    numero: (json['numero'] ?? '').toString(),
    fecha: DateTime.tryParse((json['fecha'] ?? '').toString()),
    subtotal: aDouble(json['subtotal']),
    descuento: aDouble(json['descuento']),
    total: aDouble(json['total']),
    tipoPago: (json['tipo_pago'] ?? '').toString(),
    montoEfectivo: aDouble(json['monto_efectivo']),
    montoQr: aDouble(json['monto_qr']),
    estado: (json['estado'] ?? 'COMPLETADA').toString(),
    usuarioNombre: (json['usuario_nombre'] ?? '').toString(),
    observacion: json['observacion']?.toString(),
    tipoComprobante: (json['tipo_comprobante'] ?? 'RECIBO').toString(),
    numeroFactura: json['numero_factura'] == null ? null : aEntero(json['numero_factura']),
    estadoSiat: json['estado_siat']?.toString(),
    siatMensaje: json['siat_mensaje']?.toString(),
    cuf: json['cuf']?.toString(),
    clienteNombre: (json['cliente_nombre'] ?? '').toString(),
    numeroDocumento: (json['numero_documento'] ?? '').toString(),
    detalles: ((json['detalles'] ?? const []) as List)
        .map((d) => DetalleVenta.desdeJson(Map<String, dynamic>.from(d as Map)))
        .toList(),
  );

  factory Venta.desdeFila(Map<String, dynamic> fila) => Venta(
    id: aEntero(fila['id']),
    numero: (fila['numero'] ?? '').toString(),
    fecha: DateTime.tryParse((fila['fecha'] ?? '').toString()),
    subtotal: aDouble(fila['subtotal']),
    descuento: aDouble(fila['descuento']),
    total: aDouble(fila['total']),
    tipoPago: (fila['tipo_pago'] ?? '').toString(),
    montoEfectivo: aDouble(fila['monto_efectivo']),
    montoQr: aDouble(fila['monto_qr']),
    estado: (fila['estado'] ?? 'COMPLETADA').toString(),
    usuarioNombre: (fila['usuario_nombre'] ?? '').toString(),
    tipoComprobante: (fila['tipo_comprobante'] ?? 'RECIBO').toString(),
    numeroFactura: fila['numero_factura'] == null ? null : aEntero(fila['numero_factura']),
    estadoSiat: fila['estado_siat']?.toString(),
    cuf: fila['cuf']?.toString(),
    clienteNombre: (fila['cliente_nombre'] ?? '').toString(),
    numeroDocumento: (fila['numero_documento'] ?? '').toString(),
    detalles: (jsonDecode((fila['detalles'] ?? '[]').toString()) as List)
        .map((d) => DetalleVenta.desdeJson(Map<String, dynamic>.from(d as Map)))
        .toList(),
  );

  Map<String, Object?> aFila() => {
    'id': id,
    'numero': numero,
    'fecha': fecha?.toIso8601String(),
    'subtotal': subtotal,
    'descuento': descuento,
    'total': total,
    'tipo_pago': tipoPago,
    'monto_efectivo': montoEfectivo,
    'monto_qr': montoQr,
    'estado': estado,
    'usuario_nombre': usuarioNombre,
    'tipo_comprobante': tipoComprobante,
    'numero_factura': numeroFactura,
    'estado_siat': estadoSiat,
    'cuf': cuf,
    'cliente_nombre': clienteNombre,
    'numero_documento': numeroDocumento,
    'detalles': jsonEncode(detalles.map((d) => d.aJson()).toList()),
  };
}
