# Facturación CFDI para WooCommerce

Plugin de WordPress/WooCommerce que genera facturas CFDI (México) automáticamente para cada pedido, a través de un puente REST propio de facturación.

- Checkout con captura opcional de datos fiscales (RFC, razón social, régimen, CP, uso de CFDI) — soporta checkout clásico y por bloques.
- Timbrado asíncrono vía Action Scheduler; la venta nunca se bloquea por el PAC.
- Descarga de XML/PDF en "Mi cuenta" vía proxy autenticado (el token nunca llega al navegador).
- Cancelación de CFDI ante el SAT desde la página del pedido.
- Webhook + polling para el estatus del timbrado.

## Requisitos

- WordPress 6.0+, WooCommerce 6.0+.
- PHP 7.4+.
- Acceso HTTPS a un puente REST de facturación compatible (ver `readme.txt` para el contrato del API).

## Instalación

1. Sube la carpeta del plugin a `wp-content/plugins/` o instala el zip desde Releases.
2. Activa el plugin.
3. WooCommerce → Facturación CFDI: captura la URL del puente y el token de API, y prueba la conexión.

## Demo y QA local

- [`demo/`](demo/) — tienda demo ficticia ("Botica Serena") para ver el plugin funcionando de forma inmersiva: identidad, productos con claves SAT, checkout. Útil para entender el alcance del plugin sin adivinar a partir del código.
- [`docker/`](docker/) — entorno Docker (WordPress + WooCommerce + plugin + demo) reproducible para desarrollo local. No incluye un backend de facturación: solo la tienda + plugin. Ver [docker/README.md](docker/README.md).

## Licencia

GPL-2.0-or-later. Ver [LICENSE](LICENSE).

## Documentación

Ver `readme.txt` (formato WordPress.org) para el listado completo de filtros disponibles y el historial de cambios.
