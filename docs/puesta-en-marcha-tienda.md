# Puesta en marcha de una tienda

Hoja de entrega para el equipo de sistemas del comercio: qué se necesita, qué credenciales
se reciben y cómo queda vinculada la tienda con el puente de facturación. La guía detallada
paso a paso está en [implementacion-cliente-woocommerce.md](implementacion-cliente-woocommerce.md).

## 1. Enlaces

| Recurso | Enlace |
|---|---|
| Repositorio del plugin | https://github.com/yolovany/facturacionmozart-woocommerce-plugin |
| Descarga del ZIP (versión aprobada) | https://github.com/yolovany/facturacionmozart-woocommerce-plugin/releases |
| Guía de implementación (paso a paso) | [docs/implementacion-cliente-woocommerce.md](implementacion-cliente-woocommerce.md) |
| Recorrido de arranque (21 pantallas) | https://yolovany.github.io/facturacionmozart-woocommerce-plugin/arranque-cfdi-la-milpa.html |
| Contrato del API REST | https://github.com/yolovany/facturacionmozart-woocommerce-plugin#contrato-del-api-rest |

## 2. Requisitos de la tienda

- WordPress 6.0+, WooCommerce 6.0+, PHP 7.4+ (probado con WP 7.0 / Woo 10.9 / PHP 8.2, HPOS activo).
- La tienda debe salir a Internet y usar **HTTPS**; la URL del puente también es HTTPS.
- Respaldo reciente de archivos y base de datos antes de instalar.
- Cron real de WordPress recomendado si la tienda tiene poco tráfico (el timbrado corre en
  segundo plano con Action Scheduler).

## 3. Credenciales del comercio

El comercio debe estar dado de alta en el puente antes de instalar. Estos valores se
entregan **por canal seguro, no por correo ni chat**, y sólo se capturan en la pantalla de
ajustes del plugin:

| Dato | Valor |
|---|---|
| URL del puente | `https://…/api/v1/facturas` |
| Token de API (Bearer) | *(entrega aparte)* |
| Secreto del webhook | *(entrega aparte — es distinto del token)* |
| Dominio autorizado en el puente | dominio público exacto de la tienda |
| Ambiente | PRUEBAS / PRODUCCIÓN |

El token autentica lo que la tienda **envía**; el secreto firma lo que la tienda
**recibe**. No se reutiliza el mismo valor para los dos.

## 4. Instalación

1. Descarga el ZIP del release aprobado.
2. WordPress → **Plugins → Añadir nuevo → Subir plugin** → Instalar → **Activar**.
3. Verifica que WooCommerce siga activo.
4. No copies nada de la carpeta `demo/` a la tienda: es sólo para pruebas y demostraciones.

## 5. Configuración

**WooCommerce → Ajustes → Facturación CFDI**:

- **URL del puente**, **Token de API**, **Secreto del webhook** (los dos últimos quedan
  como "Guardado" y ya no se muestran: es lo esperado).
- **Facturar siempre**: actívalo sólo si TODOS los pedidos deben generar CFDI (público en
  general cuando el comprador no pide factura). Lo decide el dueño de la tienda.

Guarda y pulsa **Probar conexión con el puente**. Resultado esperado: "Conexión correcta"
con el nombre del comercio (y "modo PRUEBAS" si aplica).

Si falla:

- **401/403** → token, dominio autorizado o IP de origen.
- **Error de red** → URL pública, HTTPS, salida a Internet del hosting.
- **Aviso de webhook** → el secreto no coincide en ambos lados. Sin secreto el plugin sigue
  funcionando por sondeo, pero no recibe avisos inmediatos.

## 6. Catálogo y checkout

- En cada producto, captura si aplica **SAT ClaveProdServ** y **SAT ClaveUnidad**.
- Funciona con checkout clásico y con el bloque "Finalizar compra".
- El cliente verá "Requiero factura" y capturará RFC, razón social, CP, régimen fiscal y
  uso de CFDI. El plugin valida esos datos **antes de cobrar**.

## 7. Prueba de extremo a extremo (antes de habilitar operación)

1. Pedido de prueba solicitando factura.
2. **WooCommerce → Pedidos** → notas del pedido: envío al puente y luego UUID/resultado.
3. **Mi cuenta → Mis facturas**: descarga de XML y PDF.
4. Si está en alcance: cancelar un CFDI de prueba desde la página del pedido.
5. **WooCommerce → Estado → Acciones programadas**: el grupo
   `facturacionmozart-woocommerce-plugin` debe estar ejecutándose.

## 8. Actualizaciones

El plugin se actualiza desde el panel (*Escritorio → Actualizaciones*) o con
`wp plugin update facturacionmozart-woocommerce-plugin`. No hay que subir ZIPs a mano.

## 9. Cierre

La puesta en marcha termina cuando: la prueba de conexión es correcta, el dominio está
autorizado, la política de "Facturar siempre" está decidida, un pedido de prueba completa
el ciclo con XML/PDF disponibles, y está definido quién recibe los avisos de pedido
retenido y quién puede cancelar CFDI.
