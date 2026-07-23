=== Facturación CFDI para WooCommerce ===
Contributors: infotek
Tags: woocommerce, cfdi, facturacion, sat, mexico
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 9.0
Stable tag: 1.12.1
License: GPLv2 or later

Genera facturas CFDI automáticamente para cada pedido de WooCommerce a través del puente REST del sistema de facturación.

== Description ==

El plugin conecta tu tienda WooCommerce con el sistema de facturación CFDI mediante un puente REST. El flujo es asíncrono: el pedido se completa de inmediato y el timbrado ocurre en segundo plano usando Action Scheduler, por lo que la venta nunca se bloquea aunque el PAC tarde o falle.

* El cliente puede marcar "Requiero factura" en el checkout y capturar RFC, razón social, régimen, código postal y uso de CFDI.
* Si no solicita factura, el pedido se factura a público en general.
* El CFDI timbrado (XML y PDF) queda disponible para descarga en "Mi cuenta", servido a través de un proxy autenticado (el token nunca llega al navegador).
* Cuenta sin fricción: al comprar se crea automáticamente la cuenta del cliente (sin pedir contraseña) y se guardan sus datos de facturación/envío, para conservar su historial de facturas y autocompletar la próxima compra. El acceso posterior es por "enlace de acceso" al correo (un solo uso, con caducidad), sin contraseñas.

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
* `fcfdi_motivo_cancelacion` — motivo SAT de cancelación (por defecto 02).
* `fcfdi_folio_sustitucion` — UUID sustituto para cancelación con motivo 01.
* `fcfdi_emails_con_cfdi` — correos de WooCommerce donde se adjunta el CFDI.
* `fcfdi_crear_cuenta_silenciosa` — activa/desactiva la creación automática de cuenta al comprar (por defecto true).
* `fcfdi_suprimir_email_cuenta_nueva` — suprime el correo nativo "Cuenta nueva" de WooCommerce en la creación silenciosa (por defecto true).

== Changelog ==

= 1.12.1 =
* Webhook: al notificarse el timbrado se libera de inmediato el pedido retenido y se
  desprograma el polling pendiente (antes el pedido podía quedar "en espera" hasta 1 h);
  ante un error definitivo del puente, el webhook también escala al administrador si el
  pedido está retenido.
* Cancelación con CFDI en vuelo: si el pedido se cancela o reembolsa por completo antes
  de timbrar, se aborta el envío al puente (no se emite CFDI); si el puente ya lo tenía
  en proceso, el CFDI se cancela ante el SAT automáticamente en cuanto se timbra.
* El envío encolado ya no procede si la facturación fue abortada (guarda de estatus).
* La prueba de conexión responde con éxito correcto (wp_send_json_success).
* Los archivos temporales del CFDI que se crean para adjuntarlo a un correo (cuando se
  activa fcfdi_adjuntar_cfdi_email) ahora se borran al terminar la petición, en vez de
  quedar acumulados en el directorio temporal del servidor.

= 1.12.0 =
* Cuenta silenciosa: al comprar como invitado se crea automáticamente la cuenta del
  cliente (sin pedirle contraseña) si su correo aún no está registrado, y se le vinculan
  el pedido y los datos capturados en el checkout (facturación y envío). Así conserva su
  historial de facturas y la próxima compra se autocompleta.
* Acceso sin contraseña ("enlace mágico"): desde Mi Cuenta el cliente pide un enlace de
  acceso con su correo y entra con un token de un solo uso y con caducidad (30 min), sin
  contraseñas que recordar. Filtro fcfdi_crear_cuenta_silenciosa para desactivar la
  creación automática de cuenta.
* Seguridad: si el correo ya pertenece a una cuenta, no se vincula ni se inicia sesión
  automáticamente (evita apropiarse de una cuenta ajena); la respuesta a la solicitud de
  enlace es uniforme (no revela qué correos existen). Límite de 1 envío de enlace por
  minuto por cuenta (anti email-bombing).
* En "pedido recibido" se avisa al comprador que se creó su cuenta y cómo entrar (sin
  contraseña), solo cuando la cuenta se creó en silencio para ese pedido.
* Se suprime el correo nativo "Cuenta nueva" de WooCommerce durante la creación
  silenciosa (contradice el modelo sin contraseña y duplica el aviso propio); filtro
  fcfdi_suprimir_email_cuenta_nueva para reactivarlo.
* Entrega del enlace de acceso POR EL PUENTE: el correo se envía a través del backend
  (nuevo endpoint POST /api/v1/notificaciones), reusando el SMTP del emisor —el mismo con
  el que se entrega el CFDI—, para que el acceso del cliente no dependa del servidor de
  correo del WordPress de la tienda. Si el puente no está configurado o falla, cae a
  wp_mail (fallback).
* Robustez de correo: si el envío falla (puente y fallback), se registra en el log y se
  dispara la acción fcfdi_enlace_acceso_no_enviado, para que un correo roto sea
  detectable en vez de dejar al cliente esperando.

= 1.6.0 =
* Entrega del CFDI: adjunta el XML y PDF al correo de pedido completado / factura de
  WooCommerce, para que el cliente (incluido el invitado) reciba su factura.
  Filtro fcfdi_emails_con_cfdi para elegir en qué correos se adjunta.

= 1.5.0 =
* Cancelación de CFDI ante el SAT al cancelar o reembolsar totalmente el pedido (Fase 6).
* Acción manual "Cancelar CFDI ante el SAT" en la página del pedido.
* Filtros fcfdi_motivo_cancelacion (por defecto 02) y fcfdi_folio_sustitucion.

= 1.4.0 =
* Validación condicional en el checkout por bloques: si el cliente marca "Requiero factura",
  el RFC, razón social, CP, régimen y uso de CFDI pasan a obligatorios (con formato).

= 1.3.1 =
* Corrige la lectura de los campos fiscales del checkout por bloques (clave de meta `_wc_other/`).
  Antes, un pedido por bloques con factura se trataba como público en general.

= 1.3.0 =
* Columna "CFDI" con el estatus de facturación en la lista de pedidos (HPOS y legacy).
* Acción "Reintentar facturación CFDI" en la página del pedido.

= 1.2.0 =
* Webhook: recibe la notificación del puente al timbrar (alternativa al polling), autenticado por token.
* Prueba de conexión usa el endpoint /health del puente.

= 1.1.0 =
* Envío/shipping facturado como concepto.
* Campos SAT (ClaveProdServ / ClaveUnidad) por producto en el admin.
* Soporte de checkout por bloques (additional checkout fields, WC 8.9+). Requiere validación en WordPress.

= 1.0.0 =
* Versión inicial: checkout, envío asíncrono, polling de estatus y descarga en Mi cuenta.
