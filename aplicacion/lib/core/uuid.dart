import 'dart:math';

final _azar = Random.secure();

/// UUID v4 para la venta. El backend es idempotente sobre este valor
/// (`ventas.uuid` único): reintentar un cobro nunca duplica la venta.
String nuevoUuid() {
  final b = List<int>.generate(16, (_) => _azar.nextInt(256));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  final h = b.map((x) => x.toRadixString(16).padLeft(2, '0')).join();
  return '${h.substring(0, 8)}-${h.substring(8, 12)}-${h.substring(12, 16)}-'
      '${h.substring(16, 20)}-${h.substring(20)}';
}
