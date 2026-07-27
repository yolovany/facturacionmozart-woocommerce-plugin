# Implementación para una tienda WordPress/WooCommerce existente

Esta guía instala **Facturación CFDI para WooCommerce** en una tienda ya operativa. No crea productos, páginas, temas ni contenido de demostración.

## Antes de empezar

Confirma lo siguiente con el cliente:

- Tiene una copia de respaldo reciente de archivos y base de datos.
- WordPress, WooCommerce y PHP cumplen los requisitos del plugin.
- La tienda y el puente de facturación usan HTTPS.
- Se cuenta con la URL del puente, un token API y, si se usarán avisos inmediatos, un secreto de webhook. El token y el secreto son credenciales diferentes.
- El comercio está dado de alta en el puente y tiene autorizado el dominio público exacto de la tienda (por ejemplo, `tienda.ejemplo.com`).

No envíes tokens ni secretos por correo, chat o capturas. Entrégalos por un canal seguro y guárdalos sólo en la pantalla de ajustes del plugin.

## 1. Instalar el plugin

1. Descarga el archivo ZIP de la versión aprobada desde los [releases](../../releases).
2. En WordPress, abre **Plugins → Añadir nuevo → Subir plugin**.
3. Selecciona el ZIP, pulsa **Instalar ahora** y después **Activar plugin**.
4. Comprueba que WooCommerce sigue activo. Si no lo está, el plugin mostrará un aviso y no procesará facturas.

> No copies ni ejecutes los archivos de `demo/` en la tienda del cliente.

## 2. Configurar la conexión de facturación

En WordPress abre **WooCommerce → Facturación CFDI** y captura:

| Campo | Valor esperado |
|---|---|
| **URL del puente** | Endpoint base de facturas entregado por el proveedor, por ejemplo `https://api.ejemplo.com/api/v1/facturas`. Debe iniciar con `https://`. |
| **Token de API** | Token Bearer del comercio. |
| **Secreto del webhook** | Secreto propio con el que el puente firma las notificaciones a la tienda. No reutilizar el token API. |
| **Facturar siempre** | Actívalo sólo si todos los pedidos deben generar CFDI (público en general cuando el comprador no solicita factura). Déjalo desactivado si sólo se deben facturar los pedidos solicitados por el comprador. |

Pulsa **Guardar cambios**. Los campos de token y secreto no vuelven a mostrar su contenido: que aparezcan como “Guardado” es el comportamiento esperado.

## 3. Validar la conexión

Pulsa **Probar conexión con el puente**.

El resultado esperado es “Conexión correcta” e identifica el comercio. Si el ambiente es de pruebas, también mostrará “modo PRUEBAS”. No habilites facturación real hasta que el responsable fiscal confirme el paso a producción.

Si falla:

- **401 o 403:** revisa token, dominio autorizado en el comercio e IP/origen permitido por el puente.
- **Error de red:** revisa que la URL sea pública, use HTTPS y que el hosting pueda salir a Internet.
- **Aviso sobre webhook:** revisa que el secreto del webhook coincida en ambos sistemas. Sin secreto, el plugin conserva la consulta periódica de estado como respaldo, pero no acepta avisos inmediatos.

## 4. Preparar el catálogo y el checkout

1. Revisa los productos que se venderán. En la sección de datos de cada producto, captura las claves SAT específicas si el comercio las requiere:
   - **SAT ClaveProdServ**
   - **SAT ClaveUnidad**
2. Verifica el checkout que usa la tienda. El plugin es compatible tanto con el checkout clásico como con el bloque “Finalizar compra”.
3. Asegúrate de que la tienda tenga una forma de pago de prueba o un ambiente de pruebas del proveedor de pagos.

El cliente verá “Requiero factura”. Al marcarlo, debe poder capturar RFC, razón social, código postal, régimen fiscal y uso de CFDI. El plugin valida los datos antes del cobro cuando el puente está configurado.

## 5. Prueba controlada de extremo a extremo

Antes de habilitar la operación normal, realiza un pedido de prueba con datos fiscales de prueba autorizados por el emisor.

1. Haz un pedido y solicita factura en el checkout.
2. En **WooCommerce → Pedidos**, abre el pedido y revisa sus notas. Deben registrar el envío al puente y posteriormente el UUID o el resultado del timbrado.
3. Comprueba que el pedido no quede retenido sin explicación. Si el timbrado sigue en proceso, el plugin lo consulta y reintenta en segundo plano.
4. Desde **Mi cuenta → Mis facturas**, verifica la descarga de XML y PDF cuando el CFDI esté disponible.
5. Si el alcance del proyecto lo incluye, cancela un pedido de prueba y verifica la acción de cancelación de CFDI.

## 6. Verificación operativa

- En **WooCommerce → Estado → Acciones programadas**, revisa que las acciones del grupo `facturacionmozart-woocommerce-plugin` se ejecuten. El plugin usa Action Scheduler para no bloquear la compra mientras se timbra.
- Configura el cron real de WordPress si el hosting tiene poco tráfico; evita depender sólo de visitas para procesar tareas pendientes.
- Conserva el acceso de administrador de WooCommerce para revisar pedidos retenidos, reintentar facturación y atender errores.
- Define con el cliente quién recibe los avisos de pedido retenido y quién puede cancelar CFDI.

## Entrega al cliente

La implementación se considera terminada cuando:

- El plugin está activo y la prueba de conexión es exitosa.
- El dominio de la tienda está autorizado en el puente.
- La política de “Facturar siempre” está decidida y documentada.
- Un pedido de prueba completa el ciclo esperado y el XML/PDF está disponible.
- Se documentaron los responsables operativos y el ambiente activo: **PRUEBAS** o **PRODUCCIÓN**.

