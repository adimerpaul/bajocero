import '../../modelos/cliente.dart';
import '../servicios/api_servicio.dart';

/// Padrón de clientes para la factura: búsqueda por documento o nombre y
/// verificación del NIT contra el SIN. Siempre en línea, sin cache.
class ClienteRepositorio {
  ClienteRepositorio(this._api);

  final ApiServicio _api;

  Future<List<Cliente>> buscar(String texto) async {
    final respuesta =
        await _api.obtener('/clientes-buscar', parametros: {'q': texto.trim()}) as List;
    return respuesta.map((c) => Cliente.desdeApi(Map<String, dynamic>.from(c as Map))).toList();
  }

  /// `true` activo, `false` no figura en el padrón, `null` si Impuestos no respondió.
  Future<bool?> verificarNit(String nit) async {
    final respuesta =
        await _api.obtener('/clientes-verificar-nit', parametros: {'nit': nit.trim()})
            as Map<String, dynamic>;
    final valido = respuesta['valido'];
    return valido is bool ? valido : null;
  }
}
