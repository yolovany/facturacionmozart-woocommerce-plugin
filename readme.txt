=== Facturación CFDI para WooCommerce ===
Contributors: infotek
Tags: woocommerce, cfdi, facturacion, sat, mexico
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 9.0
Stable tag: 1.0.0
License: GPLv2 or later

Genera facturas CFDI automáticamente para cada pedido de WooCommerce a través del puente REST del sistema de facturación.

== Description ==

El plugin conecta tu tienda WooCommerce con el sistema de facturación CFDI mediante un puente REST. El flujo es asíncrono: el pedido se completa de inmediato y el timbrado ocurre en segundo plano usando Action Scheduler, por lo que la venta nunca se bloquea aunque el PAC tarde o falle.

* El cliente puede marcar "Requiero factura" en el checkout y capturar RFC, razón social, régimen, código postal y uso de CFDI.
* Si no solicita factura, el pedido se factura a público en general.
* El CFDI timbrado (XML y PDF) queda disponible para descarga en "Mi cuenta", servido a través de un proxy autenticado (el token nunca llega al navegador).

== Configuración ==

1. WooCommerce → Facturación CFDI.
2. Captura la URL del puente (p.ej. https://tu-servidor/api/v1/facturas) y el token entregado por el proveedor.
3. Pulsa "Probar conexión".

== Requisitos ==

* WooCommerce 6.0+ (incluye Action Scheduler).
* Acceso HTTPS al puente REST de facturación.

== Filtros disponibles ==

* `fcfdi_payload` — ajusta el payload antes de enviarlo.
* `fcfdi_forma_pago` — forma de pago SAT (por defecto 99).
* `fcfdi_metodo_pago` — método de pago SAT (por defecto PUE).
* `fcfdi_clave_prod_serv_envio` — ClaveProdServ del concepto de envío (por defecto 78102200).
* `fcfdi_clave_unidad_envio` — ClaveUnidad del concepto de envío (por defecto E48).

== Changelog ==

= 1.1.0 =
* Envío/shipping facturado como concepto.
* Campos SAT (ClaveProdServ / ClaveUnidad) por producto en el admin.
* Soporte de checkout por bloques (additional checkout fields, WC 8.9+). Requiere validación en WordPress.

= 1.0.0 =
* Versión inicial: checkout, envío asíncrono, polling de estatus y descarga en Mi cuenta.
