import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/formato.dart';
import '../../core/tema.dart';
import '../../datos/repositorios/carrito_repositorio.dart';
import '../../datos/repositorios/cliente_repositorio.dart';
import '../../datos/repositorios/producto_repositorio.dart';
import '../../datos/repositorios/venta_repositorio.dart';
import '../../modelos/cliente.dart';
import '../../modelos/item_carrito.dart';
import '../../vistamodelos/cobro_vistamodelo.dart';
import '../widgets/comunes.dart';
import '../widgets/foto_producto.dart';
import 'venta_registrada_vista.dart';

/// "Revisar orden": confirma el detalle, aplica descuento y cobra.
class CobroVista extends StatelessWidget {
  const CobroVista({super.key, required this.items});

  final List<ItemCarrito> items;

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider(
      create: (_) => CobroVistaModelo(
        items,
        context.read<VentaRepositorio>(),
        context.read<CarritoRepositorio>(),
        context.read<ProductoRepositorio>(),
        context.read<ClienteRepositorio>(),
      ),
      child: const _CobroCuerpo(),
    );
  }
}

class _CobroCuerpo extends StatelessWidget {
  const _CobroCuerpo();

  Future<void> _cobrar(BuildContext context) async {
    final vm = context.read<CobroVistaModelo>();
    final navegador = Navigator.of(context);
    final venta = await vm.cobrar();

    if (!context.mounted) return;
    if (venta == null) {
      mostrarMensaje(context, vm.error ?? 'No se pudo registrar la venta', esError: true);
      return;
    }

    await navegador.push<void>(
      MaterialPageRoute<void>(
        builder: (_) => VentaRegistradaVista(venta: venta, cambio: vm.cambio),
      ),
    );
    navegador.pop(true);
  }

  @override
  Widget build(BuildContext context) {
    final vm = context.watch<CobroVistaModelo>();

    return Scaffold(
      appBar: AppBar(
        title: const Text('Revisar orden'),
        leading: IconButton(
          icon: const Icon(Icons.arrow_back),
          onPressed: () => Navigator.pop(context, false),
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(14, 14, 14, 20),
        children: [
          Tarjeta(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
            hijo: Column(
              children: [
                for (final item in vm.items)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    child: Row(
                      children: [
                        FotoProducto(foto: item.foto, tamano: 38, radio: 10),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                item.nombre,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(fontWeight: FontWeight.w600),
                              ),
                              Text(
                                '${cantidadTexto(item.cantidad, item.unidad)} ${item.unidad} × ${dinero(item.precioVenta)}',
                                style: const TextStyle(fontSize: 12, color: AppColores.textoSuave),
                              ),
                            ],
                          ),
                        ),
                        Text(
                          dinero(item.total),
                          style: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 14),
          const _DatosCliente(),
          const SizedBox(height: 14),
          Tarjeta(
            hijo: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Forma de pago', style: TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: 10),
                Row(
                  children: [
                    for (final tipo in TipoPago.values) ...[
                      Expanded(
                        child: _OpcionPago(
                          tipo: tipo,
                          seleccionado: vm.tipoPago == tipo,
                          alTocar: () => vm.cambiarTipoPago(tipo),
                        ),
                      ),
                      if (tipo != TipoPago.values.last) const SizedBox(width: 8),
                    ],
                  ],
                ),
                if (vm.tipoPago == TipoPago.combinado) ...[
                  const SizedBox(height: 12),
                  TextField(
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))],
                    onChanged: vm.cambiarEfectivoCombinado,
                    decoration: InputDecoration(
                      labelText: 'Monto en efectivo',
                      prefixText: '$kMoneda ',
                      helperText: 'El resto se cobra por QR: ${dinero(vm.montoQr)}',
                    ),
                  ),
                ],
                const SizedBox(height: 12),
                Row(
                  children: [
                    Expanded(
                      child: TextField(
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))],
                        onChanged: vm.cambiarDescuento,
                        decoration: const InputDecoration(
                          labelText: 'Descuento',
                          prefixText: '$kMoneda ',
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: TextField(
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))],
                        onChanged: vm.cambiarRecibido,
                        enabled: vm.tipoPago != TipoPago.qr,
                        decoration: const InputDecoration(
                          labelText: 'Paga con',
                          prefixText: '$kMoneda ',
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                TextField(
                  maxLines: 2,
                  textCapitalization: TextCapitalization.sentences,
                  onChanged: vm.cambiarObservacion,
                  decoration: const InputDecoration(labelText: 'Observación (opcional)'),
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),
          Tarjeta(
            hijo: Column(
              children: [
                _Linea(
                  etiqueta: 'Subtotal (${vm.cantidadItems} items)',
                  valor: dinero(vm.subtotal),
                ),
                if (vm.descuento > 0)
                  _Linea(
                    etiqueta: 'Descuento',
                    valor: '- ${dinero(vm.descuento)}',
                    color: AppColores.rojo,
                  ),
                const Divider(height: 20),
                _Linea(etiqueta: 'Total a cobrar', valor: dinero(vm.total), destacado: true),
                if (vm.tipoPago == TipoPago.combinado) ...[
                  const SizedBox(height: 6),
                  _Linea(etiqueta: 'Efectivo', valor: dinero(vm.montoEfectivo)),
                  _Linea(etiqueta: 'QR', valor: dinero(vm.montoQr)),
                ],
                if (vm.cambio > 0) ...[
                  const SizedBox(height: 6),
                  _Linea(
                    etiqueta: 'Cambio',
                    valor: dinero(vm.cambio),
                    color: AppColores.ambar,
                    destacado: true,
                  ),
                ],
              ],
            ),
          ),
          if (vm.advertencia != null) ...[
            const SizedBox(height: 12),
            Row(
              children: [
                const Icon(Icons.info_outline, size: 16, color: AppColores.rojo),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    vm.advertencia!,
                    style: const TextStyle(color: AppColores.rojo, fontSize: 12.5),
                  ),
                ),
              ],
            ),
          ],
          const SizedBox(height: 18),
          SizedBox(
            height: 54,
            child: ElevatedButton.icon(
              onPressed: vm.puedeCobrar ? () => _cobrar(context) : null,
              icon: vm.cargando
                  ? const SizedBox(
                      height: 20,
                      width: 20,
                      child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
                    )
                  : const Icon(Icons.check_circle_outline),
              label: Text(
                vm.cargando
                    ? (vm.cliente.factura ? 'Facturando...' : 'Registrando...')
                    : '${vm.cliente.factura ? 'Cobrar y facturar' : 'Cobrar'} ${dinero(vm.total)}',
              ),
              style: ElevatedButton.styleFrom(
                disabledBackgroundColor: AppColores.borde,
                disabledForegroundColor: AppColores.textoSuave,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _OpcionPago extends StatelessWidget {
  const _OpcionPago({required this.tipo, required this.seleccionado, required this.alTocar});

  final TipoPago tipo;
  final bool seleccionado;
  final VoidCallback alTocar;

  IconData get _icono => switch (tipo) {
    TipoPago.efectivo => Icons.payments_outlined,
    TipoPago.qr => Icons.qr_code_2,
    TipoPago.combinado => Icons.call_split,
  };

  @override
  Widget build(BuildContext context) {
    return Material(
      color: seleccionado ? AppColores.primarioSuave : Colors.white,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        onTap: alTocar,
        borderRadius: BorderRadius.circular(12),
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 12),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(12),
            border: Border.all(
              color: seleccionado ? AppColores.primario : AppColores.borde,
              width: seleccionado ? 1.6 : 1,
            ),
          ),
          child: Column(
            children: [
              Icon(
                _icono,
                color: seleccionado ? AppColores.primario : AppColores.textoSuave,
                size: 22,
              ),
              const SizedBox(height: 4),
              Text(
                tipo.etiqueta,
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: seleccionado ? AppColores.primario : AppColores.textoSuave,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Linea extends StatelessWidget {
  const _Linea({required this.etiqueta, required this.valor, this.color, this.destacado = false});

  final String etiqueta;
  final String valor;
  final Color? color;
  final bool destacado;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            etiqueta,
            style: TextStyle(
              color: destacado ? AppColores.texto : AppColores.textoSuave,
              fontWeight: destacado ? FontWeight.w700 : FontWeight.w500,
            ),
          ),
          Text(
            valor,
            style: TextStyle(
              fontSize: destacado ? 18 : 14,
              fontWeight: destacado ? FontWeight.w800 : FontWeight.w600,
              color: color ?? (destacado ? AppColores.primario : AppColores.texto),
            ),
          ),
        ],
      ),
    );
  }
}

/// Carnet o NIT del comprador. Con documento la venta se factura; vacío o `0`
/// sale como recibo. Igual que `ClienteFactura.vue` del panel web.
class _DatosCliente extends StatefulWidget {
  const _DatosCliente();

  @override
  State<_DatosCliente> createState() => _DatosClienteState();
}

class _DatosClienteState extends State<_DatosCliente> {
  final _documento = TextEditingController();
  final _complemento = TextEditingController();
  final _nombre = TextEditingController();
  final _email = TextEditingController();

  @override
  void dispose() {
    _documento.dispose();
    _complemento.dispose();
    _nombre.dispose();
    _email.dispose();
    super.dispose();
  }

  /// El ViewModel puede cambiar los datos (limpieza del documento, cliente
  /// elegido o autocompletado): se copian a los campos sólo si difieren, para
  /// no mover el cursor mientras se escribe.
  void _sincronizar(TextEditingController control, String valor) {
    if (control.text == valor) return;
    control.value = TextEditingValue(
      text: valor,
      selection: TextSelection.collapsed(offset: valor.length),
    );
  }

  @override
  Widget build(BuildContext context) {
    final vm = context.watch<CobroVistaModelo>();
    final cliente = vm.cliente;
    _sincronizar(_documento, cliente.numeroDocumento);
    _sincronizar(_complemento, cliente.complemento);
    _sincronizar(_nombre, cliente.nombre);
    _sincronizar(_email, cliente.email);

    return Tarjeta(
      hijo: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Expanded(
                child: Text('Datos del cliente', style: TextStyle(fontWeight: FontWeight.w700)),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: cliente.factura ? AppColores.primarioSuave : AppColores.fondo,
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(
                  cliente.factura ? 'FACTURA' : 'SIN FACTURA',
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w800,
                    color: cliente.factura ? AppColores.primario : AppColores.textoSuave,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                width: 92,
                child: DropdownButtonFormField<TipoDocumento>(
                  initialValue: cliente.tipoDocumento,
                  isDense: true,
                  decoration: const InputDecoration(labelText: 'Doc.'),
                  items: [
                    for (final tipo in TipoDocumento.values)
                      DropdownMenuItem(value: tipo, child: Text(tipo.valor)),
                  ],
                  onChanged: (tipo) {
                    if (tipo != null) vm.cambiarTipoDocumento(tipo);
                  },
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: TextField(
                  controller: _documento,
                  keyboardType: cliente.tipoDocumento.soloNumeros
                      ? TextInputType.number
                      : TextInputType.text,
                  textCapitalization: TextCapitalization.characters,
                  onChanged: vm.cambiarDocumento,
                  decoration: InputDecoration(
                    labelText: cliente.tipoDocumento.etiqueta,
                    hintText: 'Vacío = sin factura',
                    suffixIcon: _iconoDocumento(vm),
                  ),
                ),
              ),
              if (cliente.tipoDocumento == TipoDocumento.ci && cliente.factura) ...[
                const SizedBox(width: 8),
                SizedBox(
                  width: 74,
                  child: TextField(
                    controller: _complemento,
                    maxLength: 5,
                    textCapitalization: TextCapitalization.characters,
                    onChanged: vm.cambiarComplemento,
                    decoration: const InputDecoration(labelText: 'Compl.', counterText: ''),
                  ),
                ),
              ],
            ],
          ),
          if (vm.sugerencias.isNotEmpty)
            Container(
              margin: const EdgeInsets.only(top: 6),
              decoration: BoxDecoration(
                border: Border.all(color: AppColores.borde),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Column(
                children: [
                  for (final sugerido in vm.sugerencias.take(5))
                    ListTile(
                      dense: true,
                      visualDensity: VisualDensity.compact,
                      leading: const Icon(Icons.person_outline, size: 20),
                      title: Text(sugerido.nombre, maxLines: 1, overflow: TextOverflow.ellipsis),
                      subtitle: Text(sugerido.documento),
                      onTap: () {
                        FocusScope.of(context).unfocus();
                        vm.elegirCliente(sugerido);
                      },
                    ),
                ],
              ),
            ),
          if (cliente.factura) ...[
            const SizedBox(height: 10),
            TextField(
              controller: _nombre,
              textCapitalization: TextCapitalization.characters,
              onChanged: vm.cambiarNombre,
              onTap: vm.cerrarSugerencias,
              decoration: const InputDecoration(labelText: 'Nombre / razón social'),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              onChanged: vm.cambiarEmail,
              onTap: vm.cerrarSugerencias,
              decoration: const InputDecoration(
                labelText: 'Correo (opcional)',
                helperText: 'Se le envía la factura por correo',
              ),
            ),
            if (cliente.tipoDocumento == TipoDocumento.nit) ...[
              const SizedBox(height: 6),
              CheckboxListTile(
                value: cliente.codigoExcepcion,
                onChanged: (valor) => vm.cambiarExcepcion(valor ?? false),
                dense: true,
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                activeColor: AppColores.ambar,
                title: const Text('Excepción de NIT (código 1 al SIN)'),
                subtitle: Text(
                  switch (vm.nitValido) {
                    true => 'NIT activo en el padrón del SIN',
                    false => 'El NIT no figura en el padrón',
                    null => vm.verificandoNit ? 'Verificando en el padrón…' : 'NIT sin verificar',
                  },
                  style: TextStyle(
                    color: switch (vm.nitValido) {
                      true => AppColores.verde,
                      false => AppColores.rojo,
                      null => AppColores.textoSuave,
                    },
                  ),
                ),
              ),
            ],
          ] else
            const Padding(
              padding: EdgeInsets.only(top: 8),
              child: Text(
                'Sin documento (o con 0) la venta se registra sin factura.',
                style: TextStyle(fontSize: 12, color: AppColores.textoSuave),
              ),
            ),
        ],
      ),
    );
  }

  Widget? _iconoDocumento(CobroVistaModelo vm) {
    if (vm.buscandoCliente || vm.verificandoNit) {
      return const Padding(
        padding: EdgeInsets.all(14),
        child: SizedBox(height: 16, width: 16, child: CircularProgressIndicator(strokeWidth: 2)),
      );
    }
    if (vm.cliente.tipoDocumento != TipoDocumento.nit || !vm.cliente.factura) return null;
    return switch (vm.nitValido) {
      true => const Icon(Icons.check_circle, color: AppColores.verde, size: 20),
      false => const Icon(Icons.warning_amber_rounded, color: AppColores.rojo, size: 20),
      null => null,
    };
  }
}
