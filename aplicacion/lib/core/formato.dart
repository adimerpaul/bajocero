import 'package:intl/intl.dart';

const String kMoneda = 'Bs';

final _numero = NumberFormat('#,##0.00', 'es');
final _fechaHora = DateFormat('dd/MM/yyyy HH:mm');
final _fecha = DateFormat('dd/MM/yyyy');

String dinero(num valor) => '$kMoneda ${_numero.format(valor)}';

String numero(num valor) => _numero.format(valor);

String fechaHora(DateTime? valor) => valor == null ? '' : _fechaHora.format(valor.toLocal());

String fecha(DateTime? valor) => valor == null ? '' : _fecha.format(valor.toLocal());

String fechaApi(DateTime valor) => DateFormat('yyyy-MM-dd').format(valor);

double aDouble(dynamic valor) {
  if (valor == null) return 0;
  if (valor is num) return valor.toDouble();
  return double.tryParse(valor.toString()) ?? 0;
}

int aEntero(dynamic valor) {
  if (valor == null) return 0;
  if (valor is num) return valor.round();
  return int.tryParse(valor.toString()) ?? aDouble(valor).round();
}

/// Unidad `KG`: producto pesado (pollo, embutidos). Se vende con 3 decimales
/// y el precio es por kilo, igual que en el panel web.
bool esPesable(String unidad) => unidad.toUpperCase() == 'KG';

final _cantidadKg = NumberFormat('#,##0.000', 'es');
final _cantidadUnidad = NumberFormat('#,##0.###', 'es');

/// Cantidad para mostrar: los pesados siempre con 3 decimales (1,250), el
/// resto sin decimales si es entera.
String cantidadTexto(num valor, String unidad) =>
    esPesable(unidad) ? _cantidadKg.format(valor) : _cantidadUnidad.format(valor);

/// Redondeo a gramos: `venta_detalles.cantidad` es decimal(12,3).
double redondearCantidad(num valor) => (valor * 1000).roundToDouble() / 1000;

/// Etiqueta EAN-13 de balanza: `2` + código de 6 dígitos + 5 dígitos de gramos
/// + dígito verificador. Mismo formato que `parseScaleBarcode` del panel web:
/// el código del producto son los 7 primeros dígitos (incluido el `2`).
({String codigo, double peso})? leerEtiquetaBalanza(String valor) {
  final codigo = valor.trim();
  if (!RegExp(r'^2\d{12}$').hasMatch(codigo)) return null;
  if (digitoEan13(codigo.substring(0, 12)) != int.parse(codigo[12])) return null;
  return (codigo: codigo.substring(0, 7), peso: int.parse(codigo.substring(7, 12)) / 1000);
}

int digitoEan13(String primeros12) {
  var suma = 0;
  for (var i = 0; i < primeros12.length; i++) {
    suma += int.parse(primeros12[i]) * (i.isEven ? 1 : 3);
  }
  return (10 - suma % 10) % 10;
}
