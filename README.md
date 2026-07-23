# Plugin de FacturaciónMozart para WooCommerce

![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg)
![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0%2B-96588a.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)
![Version](https://img.shields.io/badge/version-1.12.1-success.svg)

Plugin de WordPress/WooCommerce que genera **facturas CFDI (México)** automáticamente
para cada pedido, hablando por REST con un backend/puente de facturación propio.

> **Índice:** usa el botón de índice (☰) arriba a la derecha del README en GitHub para
> saltar entre secciones. Los integradores encontrarán el contrato del API en
> [Contrato del API REST](#contrato-del-api-rest).

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
- Cuenta sin fricción: al comprar se crea la cuenta del cliente automáticamente (sin
  contraseña) y se guardan sus datos de facturación/envío; el acceso posterior es por
  "enlace de acceso" al correo (un solo uso, con caducidad), sin contraseñas que recordar.
- Webhook + polling para el estatus del timbrado; columna y acción de reintento en el
  admin de pedidos (HPOS y legacy).

## Cómo funciona (arquitectura)

Este repositorio contiene **solo el plugin de WordPress**. No incluye el backend que
genera y timbra el CFDI — el plugin es un cliente REST que habla con tu propio servicio
de facturación (el "puente"), autenticado con Bearer token. El contrato completo (endpoints,
payloads, webhook, códigos de error) está más abajo, en
[Contrato del API REST](#contrato-del-api-rest): sirve para implementar o auditar un
backend compatible.

```
WooCommerce (checkout / pedido) → plugin → REST (Bearer token) → tu backend de facturación → PAC/SAT
```

Si no tienes un backend propio, puedes explorar el alcance del plugin con la
[demo](#demo-y-desarrollo-local) incluida (sin timbrado real).

## Requisitos

- WordPress 6.0+, WooCommerce 6.0+.
- PHP 7.4+.
- Un backend/puente REST de facturación compatible (ver [Contrato del API
  REST](#contrato-del-api-rest)).

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
| [`docker/`](docker/) | Entorno reproducible (WordPress + WooCommerce + MySQL + cron real) que levanta todo desde cero y usa `demo/` para poblar la tienda. | No tienes WordPress a mano, o quieres un entorno limpio de desarrollo/QA. |

El entorno `docker/` tiene dos modos:

- **Desarrollo** (`docker compose up -d`): monta el plugin **en vivo** desde el repo —
  editas y refrescas.
- **Demo / cliente** (`docker compose -f docker-compose.demo.yml up -d`): **instala el
  plugin desde el `.zip` del release**, reproduciendo lo que hará el cliente final, con
  un solo comando. Ver [`docker/README.md`](docker/README.md).

Ninguno incluye un backend de facturación real — sirven para ver el plugin y el checkout
funcionando; el timbrado real requiere tu propio backend (ver "Cómo funciona" arriba).

---

# Contrato del API REST

Este plugin **no timbra** por sí mismo: es un cliente REST que habla con un backend de
facturación propio (el "puente"), autenticado con un Bearer token. Esta sección define el
**contrato** que ese backend debe cumplir para ser compatible con el plugin —
independientemente de con qué tecnología esté implementado.

> La implementación de referencia es el sistema **FacturacionMozart** (.NET), que expone
> este contrato bajo `api/v1/`. Si usas ese backend, su README documenta además el
> despliegue y la operación. Aquí se describe únicamente el contrato observable desde el
> plugin, para que puedas implementar (o auditar) un backend compatible.

## Convenciones

- **Base URL:** la que configures en *WooCommerce → Ajustes → Facturación CFDI → URL del
  puente*, p.ej. `https://tu-servidor/api/v1/facturas`. El plugin deriva de ella la raíz
  (quitando `/facturas`) para los endpoints hermanos (`/health`, `/validar-receptor`,
  `/notificaciones`, `/catalogos/regimen-uso`).
- **Autenticación:** todas las peticiones llevan `Authorization: Bearer {token}`. El
  backend resuelve el comercio a partir del token. Se recomienda además forzar HTTPS y,
  opcionalmente, whitelist de IP.
- **Formato:** JSON en request y response (`Content-Type: application/json`,
  `Accept: application/json`), salvo las descargas de XML/PDF (binario).
- **Errores:** cuerpo uniforme `{ "estatus":"error", "codigo":"…", "mensaje":"…", "reintentable":bool }`.
  El plugin usa `codigo` para traducir a un mensaje accionable al cliente; ver
  [Códigos de error](#códigos-de-error).

## Modelo asíncrono

El plugin espera que el timbrado sea **asíncrono**: `POST /facturas` responde de inmediato
(`202`/`200`) sin bloquear la venta, y el resultado se obtiene después por **polling**
(`GET /facturas/{id}`) o **webhook** (`callback_url`). El plugin implementa ambos, con
backoff; el backend debe soportar al menos el polling. Estados esperados en `estatus`:
`en_proceso`, `timbrada`, `error`, `cancelada`.

## Endpoints

### `POST /facturas` — encolar un pedido para timbrar

Se llama al confirmarse el pago. Headers: `Idempotency-Key: {order_id}` (opcional; si se
envía, el backend debe exigir que coincida con `order_id`).

**Request** (`FacturaRequest`):

```jsonc
{
  "order_id": "1234",                 // clave de idempotencia (obligatorio)
  "fecha_pedido": "2026-07-23T10:00:00-06:00",
  "requiere_factura": true,           // false = público en general
  "callback_url": "https://tienda/wp-json/facturacion-cfdi/v1/callback",
  "receptor": {                       // requerido si requiere_factura=true
    "tipo": "rfc",                    // "rfc" | "publico_general"
    "rfc": "XAXX010101000",
    "razon_social": "…",
    "regimen_fiscal": "612",
    "cp": "22880",
    "uso_cfdi": "G03",
    "email": "cliente@correo.com"
  },
  "conceptos": [ {
    "sku": "ABC",
    "descripcion": "Producto",
    "cantidad": 1,
    "valor_unitario": 100.00,         // ex IVA
    "importe": 100.00,                // ex IVA, antes de descuento
    "descuento": 0,
    "objeto_impuesto": "02",          // "01" no objeto, "02" sí objeto de impuesto
    "clave_prod_serv": "01010101",    // opcional; si falta, el backend aplica su default
    "clave_unidad": "H87",            // opcional
    "impuestos": [ { "tipo": "IVA", "tasa": 0.160000, "importe": 16.00 } ]
  } ],
  "totales": {
    "subtotal": 100.00,
    "descuento": 0,
    "impuestos_trasladados": 16.00,
    "total": 116.00,
    "moneda": "MXN"
  },
  "pago": { "forma_pago": "99", "metodo_pago": "PUE" }
}
```

El envío del pedido (WooCommerce shipping) se manda como un concepto más (SKU `ENVIO`),
con claves SAT ajustables por filtro en el plugin.

**Response:**

- `202 Accepted` (nuevo) o `200 OK` (ya existía — idempotente): `{ factura_id, order_id, estatus }`.
- `400` con código de negocio si el payload es inválido (ver códigos).
- `>=500` / error de red → el plugin **reintenta con backoff** (no marca error definitivo).

> **Idempotencia:** la clave es `(comercio, order_id)`. Un reintento del mismo pedido debe
> devolver el registro existente, no duplicar el timbrado.

### `POST /facturas/validar-receptor` — pre-flight (dry-run, no timbra)

Valida los datos fiscales del receptor **antes de cobrar**, en el checkout. Permite
atrapar errores corregibles por el cliente sin perder la venta.

**Request** (`ReceptorDto`): `{ rfc, razon_social, regimen_fiscal, cp, uso_cfdi }`.
**Response:** `200 { "valido": true }` u `400` con `{ codigo, mensaje }`.

> Si el backend está caído (`>=500`) o inalcanzable, el plugin **no bloquea** el checkout
> (fail-open): el timbrado asíncrono validará después.

### `GET /facturas/{facturaId}` — estatus

**Response `200`** según estatus:

```jsonc
// en proceso
{ "factura_id":"f_…", "order_id":"1234", "estatus":"en_proceso" }
// timbrada
{ "estatus":"timbrada", "uuid":"…", "fecha_timbrado":"…",
  "xml_url":"…", "pdf_url":"…" }
// error
{ "estatus":"error", "codigo":"…", "mensaje":"…", "reintentable":bool }
```

### `GET /facturas/{facturaId}/xml` y `/pdf` — descarga

Devuelven el XML timbrado (`application/xml`) o el PDF (`application/pdf`) como adjunto.
El plugin las sirve al cliente vía un **proxy autenticado** (el token nunca llega al
navegador). Requieren el Bearer token.

### `POST /facturas/{facturaId}/cancelar` — cancelar ante el SAT

**Request:** `{ "motivo":"02", "folio_sustitucion":"" }`. Motivo `01` exige
`folio_sustitucion` (UUID sustituto). **Response:** `200 { estatus:"cancelada", … }`;
idempotente si ya estaba cancelada; `409` si no es cancelable.

### `GET /health` — salud del puente

Usado por el botón "Probar conexión". **Response:**
`200 { "status":"ok", "version":"…", "timbrado_pruebas":bool, "comercio":"…" }`.

### `GET /catalogos/regimen-uso` — catálogo y matriz de compatibilidad

Régimen fiscal / uso de CFDI y qué combinaciones son válidas (Anexo 20 SAT). El plugin lo
cachea y lo usa para no dejar capturar combinaciones que el timbrado rechazaría.

**Response:** `{ "regimenes": {clave:label}, "usos": {clave:label}, "matriz": {uso:[regimenes]} }`.

### `POST /notificaciones` — enviar un correo por el SMTP del emisor

Permite que el correo (p.ej. el "enlace de acceso" passwordless) salga por el SMTP del
comercio, no por el de WordPress. **Request:** `{ destinatario, asunto, mensaje_html }`.
**Response:** `200 { estatus:"enviado" }`; `4xx/5xx` con código si falla (el plugin cae a
`wp_mail` como respaldo).

## Webhook (callback)

Si el `FacturaRequest` trae `callback_url`, el backend debe hacer `POST` a esa URL al
resolverse el timbrado, autenticándose con el header **`X-FCFDI-Token`**. El plugin
verifica ese valor antes de aceptar la notificación.

El valor esperado es el **secreto del webhook** configurado en los ajustes del plugin
("Secreto del webhook"). Es un secreto **distinto del token de API**, para que el backend
pueda almacenar el token de entrada solo hasheado. Si ese ajuste se deja vacío, el plugin
espera el token de API — un backend que no implemente secretos separados sigue siendo
compatible.

**Cuerpo esperado:** `{ factura_id, order_id, estatus, uuid, codigo, mensaje }`.

El webhook es un acelerador, no un requisito: el plugin mantiene el polling como respaldo,
así que un backend puede implementar solo polling. Si implementa webhook, el plugin cierra
el ciclo al recibirlo (libera el pedido retenido, deja de hacer polling).

## Códigos de error

El plugin traduce estos `codigo` a mensajes accionables para el cliente:

| Código | Significado |
|---|---|
| `RFC_FALTANTE` / `RFC_FORMATO` | RFC ausente o con formato inválido. |
| `REGIMEN_FALTANTE` / `REGIMEN_INVALIDO` | Régimen fiscal ausente o no válido. |
| `CP_FALTANTE` / `CP_FORMATO` | CP fiscal ausente o no son 5 dígitos. |
| `USO_CFDI_FALTANTE` / `USO_CFDI_INCOMPATIBLE` | Uso de CFDI ausente o incompatible con el régimen. |
| `CFDI40147` | RFC y CP no coinciden con el registro del SAT. |
| `CFDI40157` | El régimen no corresponde al RFC ante el SAT. |
| `SIN_RECEPTOR` | Faltan los datos fiscales del receptor. |

Otros códigos de payload/timbrado (`PAYLOAD_INVALIDO`, `ORDER_ID_FALTANTE`,
`SIN_CONCEPTOS`, `TOTAL_DESCUADRADO`, `IDEMPOTENCY_MISMATCH`, `PAC_NO_DISPONIBLE`, …) se
muestran con su mensaje tal cual. Los `reintentable:true` (p.ej. PAC caído) llevan al
plugin a reprogramar en vez de marcar error definitivo.

Los códigos marcados como corregibles por el cliente habilitan, en el admin del pedido, la
acción "Pedir al cliente corregir datos fiscales".

---

## Licencia

GPL-2.0-or-later. Ver [LICENSE](LICENSE).
