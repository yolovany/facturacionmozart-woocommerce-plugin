# Plugin de FacturaciónMozart para WooCommerce

![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg)
![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0%2B-96588a.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)
![Version](https://img.shields.io/badge/version-1.11.5-success.svg)

Plugin de WordPress/WooCommerce que genera **facturas CFDI (México)** automáticamente
para cada pedido, hablando por REST con un backend/puente de facturación propio.

## Características

- Checkout con captura opcional de datos fiscales (RFC, razón social, régimen, CP, uso de
  CFDI) — soporta checkout clásico y por bloques.
- Validación de datos fiscales **antes de cobrar** (pre-flight): un combo inválido se
  bloquea con mensaje claro, sin vaciar el carrito ni perder la venta.
- Timbrado asíncrono vía Action Scheduler; la venta nunca se bloquea por el PAC.
- Retención del pedido hasta tener CFDI si el cliente lo solicitó, con backoff largo y
  aviso al admin si el PAC está caído.
- Descarga de XML/PDF en "Mi cuenta" vía proxy autenticado (el token nunca llega al
  navegador) y adjunto automático por correo.
- Cancelación de CFDI ante el SAT desde la página del pedido.
- Portal del cliente: pestaña "Mis Facturas", perfil fiscal reutilizable con autorrelleno
  del checkout, solicitud de factura post-compra.
- Webhook + polling para el estatus del timbrado; columna y acción de reintento en el
  admin de pedidos (HPOS y legacy).

## Cómo funciona (arquitectura)

Este repositorio contiene **solo el plugin de WordPress**. No incluye el backend que
genera y timbra el CFDI — el plugin es un cliente REST que habla con tu propio servicio
de facturación (el "puente"), autenticado con Bearer token. El contrato del API
(endpoints, payloads, códigos de error) está documentado en [`readme.txt`](readme.txt).

```
WooCommerce (checkout / pedido) → plugin → REST (Bearer token) → tu backend de facturación → PAC/SAT
```

Si no tienes un backend propio, puedes explorar el alcance del plugin con la
[demo](#demo-y-desarrollo-local) incluida (sin timbrado real).

## Requisitos

- WordPress 6.0+, WooCommerce 6.0+.
- PHP 7.4+.
- Un backend/puente REST de facturación compatible (ver [`readme.txt`](readme.txt) para
  el contrato del API).

## Instalación

1. Descarga el `.zip` desde [Releases](../../releases) e instálalo desde
   **Plugins → Añadir nuevo → Subir plugin**, o copia la carpeta a `wp-content/plugins/`.
2. Activa el plugin.
3. **WooCommerce → Ajustes → Facturación CFDI**: captura la URL de tu backend y el token
   de API, y pulsa "Probar conexión".

## Filtros disponibles

El plugin expone varios filtros (`fcfdi_payload`, `fcfdi_forma_pago`,
`fcfdi_clave_prod_serv_envio`, `fcfdi_motivo_cancelacion`, etc.) para ajustar el
comportamiento sin tocar el código. Ver el listado completo y el changelog en
[`readme.txt`](readme.txt).

## Demo y desarrollo local

| Carpeta | Qué es | Cuándo usarla |
|---|---|---|
| [`demo/`](demo/) | Contenido de una tienda ficticia ("Botica Serena"): identidad, productos con claves SAT, portada. | Ya tienes un WordPress propio corriendo y quieres poblarlo para ver el plugin en acción. |
| [`docker/`](docker/) | Entorno reproducible (WordPress + WooCommerce + MySQL + cron real) que levanta todo desde cero y monta el plugin en vivo. Usa `demo/` para poblar la tienda. | No tienes WordPress a mano, o quieres un entorno limpio de desarrollo/QA. |

Ninguno de los dos incluye un backend de facturación real — sirven para ver el plugin y
el checkout funcionando; el timbrado real requiere tu propio backend (ver
"Cómo funciona" arriba).

## Licencia

GPL-2.0-or-later. Ver [LICENSE](LICENSE).
