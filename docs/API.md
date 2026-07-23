# Contrato del puente REST de facturación

Este plugin **no timbra** por sí mismo: es un cliente REST que habla con un backend de
facturación propio (el "puente"), autenticado con un Bearer token. Este documento define
el **contrato** que ese backend debe cumplir para ser compatible con el plugin —
independientemente de con qué tecnología esté implementado.

> La implementación de referencia es el sistema **FacturacionMozart** (.NET), que expone
> este contrato bajo `api/v1/`. Si usas ese backend, su README documenta además el
> despliegue y la operación. Este documento describe únicamente el contrato observable
> desde el plugin, para que puedas implementar (o auditar) un backend compatible.

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

---

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

---

## Webhook (callback)

Si el `FacturaRequest` trae `callback_url`, el backend debe hacer `POST` a esa URL al
resolverse el timbrado, autenticándose con el header **`X-FCFDI-Token: {token del comercio}`**.
El plugin verifica ese token antes de aceptar la notificación.

**Cuerpo esperado:** `{ factura_id, order_id, estatus, uuid, codigo, mensaje }`.

El webhook es un acelerador, no un requisito: el plugin mantiene el polling como respaldo,
así que un backend puede implementar solo polling. Si implementa webhook, el plugin cierra
el ciclo al recibirlo (libera el pedido retenido, deja de hacer polling).

---

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
