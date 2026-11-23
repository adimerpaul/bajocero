/// Tipos de documento del SIN, en el orden del panel web (`ClienteFactura.vue`).
enum TipoDocumento {
  ci('CI', 'CI'),
  nit('NIT', 'NIT'),
  cex('CEX', 'CEX (Extranjero)'),
  pas('PAS', 'Pasaporte'),
  od('OD', 'Otro documento');

  const TipoDocumento(this.valor, this.etiqueta);

  final String valor;
  final String etiqueta;

  /// CI y NIT sólo llevan dígitos; el resto es alfanumérico.
  bool get soloNumeros => this == ci || this == nit;

  static TipoDocumento desde(String? valor) =>
      values.firstWhere((t) => t.valor == valor, orElse: () => ci);
}

/// Cliente del padrón (`GET /clientes-buscar`), para autocompletar.
class Cliente {
  Cliente({
    required this.tipoDocumento,
    required this.numeroDocumento,
    required this.nombre,
    this.complemento = '',
    this.email = '',
  });

  final TipoDocumento tipoDocumento;
  final String numeroDocumento;
  final String complemento;
  final String nombre;
  final String email;

  String get documento =>
      '${tipoDocumento.valor} $numeroDocumento${complemento.isEmpty ? '' : '-$complemento'}';

  factory Cliente.desdeApi(Map<String, dynamic> json) => Cliente(
    tipoDocumento: TipoDocumento.desde(json['tipo_documento']?.toString()),
    numeroDocumento: (json['numero_documento'] ?? '').toString(),
    complemento: (json['complemento'] ?? '').toString(),
    nombre: (json['nombre'] ?? '').toString(),
    email: (json['email'] ?? '').toString(),
  );
}

/// Datos del comprador que viajan con la venta.
///
/// Misma regla que el panel web: **con documento se factura**; vacío o `0`
/// la venta sale como RECIBO, sin factura.
class DatosFactura {
  DatosFactura({
    this.tipoDocumento = TipoDocumento.ci,
    this.numeroDocumento = '',
    this.complemento = '',
    this.nombre = '',
    this.email = '',
    this.codigoExcepcion = false,
  });

  TipoDocumento tipoDocumento;
  String numeroDocumento;
  String complemento;
  String nombre;
  String email;

  /// NIT que no figura en el padrón: se envía con código de excepción 1.
  bool codigoExcepcion;

  String get documento => numeroDocumento.trim();

  bool get factura => documento.isNotEmpty && documento != '0';

  String get tipoComprobante => factura ? 'FACTURA' : 'RECIBO';

  Map<String, Object?> aJson() => {
    'tipo_comprobante': tipoComprobante,
    if (factura) ...{
      'tipo_documento': tipoDocumento.valor,
      'numero_documento': documento,
      'complemento': tipoDocumento == TipoDocumento.ci ? complemento.trim().toUpperCase() : '',
      'cliente_nombre': nombre.trim().toUpperCase(),
      if (email.trim().isNotEmpty) 'cliente_email': email.trim(),
      'codigo_excepcion': tipoDocumento == TipoDocumento.nit && codigoExcepcion,
    },
  };
}
