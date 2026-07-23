[← Volver al README principal](../README.md)

# Entorno Docker de desarrollo local — Plugin Facturación CFDI

Levanta un WordPress + WooCommerce real (checkout clásico **y** de bloques) con la
tienda demo "Botica Serena", para probar el checkout, el pre-flight y el filtro
uso/régimen. Dos modos:

- **Desarrollo** (`docker-compose.yml`): monta el código del plugin **en vivo** desde el
  repo. Editas y refrescas, sin reinstalar. Es la guía de desarrollo/QA del día a día.
- **Demo / cliente** (`docker-compose.demo.yml`): **instala el plugin desde el `.zip` del
  release** (igual que "Plugins → Subir plugin"), no monta el código. Reproduce
  exactamente lo que hará el cliente final y valida el artefacto publicado. Queda listo
  con **un solo comando**. Ver [Reproducir la instalación del cliente](#reproducir-la-instalación-del-cliente-desde-el-zip-del-release).

> Este entorno **no incluye un backend de facturación**. El plugin solo habla REST con
> un backend propio (ver el contrato en [`../readme.txt`](../readme.txt)); aquí puedes
> montar el tuyo, o simplemente probar el checkout/UI sin backend (el plugin queda "no
> configurado": no bloquea la venta, pero tampoco timbra).

## Contenido

- [Por qué Docker y no `php -S`](#por-qué-docker-y-no-php--s)
- [Requisitos](#requisitos)
- [Cómo alcanza el contenedor a un backend en el host](#cómo-alcanza-el-contenedor-a-un-backend-en-el-host)
- [Puesta en marcha (desarrollo)](#puesta-en-marcha-desarrollo)
- [Reproducir la instalación del cliente (desde el `.zip` del release)](#reproducir-la-instalación-del-cliente-desde-el-zip-del-release)
- [Configurar el plugin](#configurar-el-plugin)
- [Escenarios de prueba sugeridos](#escenarios-de-prueba-sugeridos)
- [Trabajo diario](#trabajo-diario)
- [Comandos wp ad-hoc](#comandos-wp-ad-hoc)
- [Solución de problemas](#solución-de-problemas)
- [Limpieza](#limpieza)

## Por qué Docker y no `php -S`

Este entorno busca parecerse a **producción**, no solo "que funcione":

| Aspecto | `php -S` (portable) | **Docker (este entorno)** | Producción |
|---|---|---|---|
| Servidor web | Un solo hilo, de desarrollo | **Apache real** | Apache/Nginx |
| Base de datos | remota/variable | **MariaDB dedicada** | MySQL/MariaDB |
| PHP | según lo instalado | **8.2** (fijo) | 8.x |
| Cron (timbrado async) | inexistente/manual | **cron real cada 60s** | cron del sistema |
| Aislamiento | comparte tu máquina | **contenedores limpios** | servidor propio |

El timbrado es **asíncrono** (Action Scheduler): sin un cron real, en `php -S` queda
diferido o no corre. Aquí un servicio `cron` lo dispara cada minuto, como en producción.

## Requisitos

- Docker Desktop (con soporte de contenedores Linux).
- *(Opcional, para timbrar de verdad)* tu propio backend/puente REST en
  `127.0.0.1:8080`, implementando el contrato de [`../readme.txt`](../readme.txt), con
  HTTPS desactivado solo para este entorno (loopback).

## Cómo alcanza el contenedor a un backend en el host

Si tu backend corre en el host atado a loopback (p.ej. IIS Express en
`127.0.0.1:8080`, que solo acepta Host header `localhost`), el compose incluye un
servicio **`bridge`** (nginx) que:

- reenvía a `host.docker.internal:8080` (el loopback del host, alcanzable desde Docker
  Desktop), y
- reescribe el `Host` a `localhost:8080` si tu servidor lo exige.

El plugin apunta a `http://bridge/api/v1/facturas` (se preconfigura solo en
`setup.sh`). Ajusta `bridge-proxy.conf` si tu backend usa otro puerto o no necesita la
reescritura de Host.

## Puesta en marcha (desarrollo)

Modo con el plugin montado **en vivo** desde el repo (editas y refrescas):

```powershell
# 1) (Opcional) Levanta tu backend/puente en :8080 con HTTPS desactivado para loopback.

# 2) Levantar el stack (WordPress + DB + cron + proxy del puente)
cd docker
docker compose up -d

# 3) Instalar WP + WooCommerce + activar el plugin (una vez)
docker compose run --rm wpcli
```

Abre <http://localhost:8000> — admin: `admin` / `admin`.

El `wpcli` deja la tienda demo **Botica Serena** (ficticia) lista: idioma `es_MX`,
marca pastel (mu-plugins), 6 productos naturistas con claves SAT e imágenes, portada y
método "contra entrega". Es la misma demo de [`../demo/`](../demo/), ahora sobre
Docker.

## Reproducir la instalación del cliente (desde el `.zip` del release)

Para verificar **lo mismo que hará el cliente final** —instalar el paquete publicado del
plugin y probar la tienda— usa `docker-compose.demo.yml`. A diferencia del modo de
desarrollo, **no monta el código**: instala el plugin desde `../dist/*.zip` igual que
"WordPress → Plugins → Subir plugin". Así se valida el artefacto real (p.ej. que el `.zip`
quedó bien empaquetado y se instala limpio en Linux), y queda listo con **un solo comando**.

```powershell
cd docker

# 1) Generar el .zip del release (queda en ../dist/). dist/ está en .gitignore, así que
#    no viaja en el repo: hay que generarlo, o copiar ahí el .zip descargado del release.
powershell -ExecutionPolicy Bypass -File ..\build.ps1

# 2) Levantar y provisionar TODO de un tiro (WP + WooCommerce + demo + plugin desde el zip)
docker compose -f docker-compose.demo.yml up -d
```

Espera ~1-2 min (la primera vez descarga WooCommerce y el idioma) y abre
<http://localhost:8000> — admin: `admin` / `admin`. No hay paso manual de `wpcli`: el
servicio de setup corre solo con el `up` y termina.

### Correo de prueba (Mailpit) — para probar el "enlace de acceso"

El plugin crea una cuenta al comprar y el cliente vuelve a entrar con un **enlace de
acceso** que llega por **correo** (sin contraseña). Para poder verlo, el demo incluye
**Mailpit** (un buzón de prueba): captura todo el correo saliente y lo muestra en una UI.

- Abre <http://localhost:8025> para ver los correos (el enlace de acceso, avisos, etc.).
- Flujo: en **Mi Cuenta**, escribe el correo en "Acceder con mi correo" → abre Mailpit →
  clic en el enlace → entras a "Mis Facturas".

> **Producción:** este buzón es solo para el demo. En un sitio real el envío de correo
> (**SMTP**) debe estar configurado y funcionando: el acceso del cliente depende de que el
> enlace llegue. Si el envío falla, el plugin lo deja en el log (`error_log`) y dispara la
> acción `fcfdi_enlace_acceso_no_enviado`.

Reinstalar desde cero (sobrescribe la instancia, borra WP + BD):

```powershell
docker compose -f docker-compose.demo.yml down -v
docker compose -f docker-compose.demo.yml up -d
```

> Igual que el modo de desarrollo, este demo **no** trae backend de facturación: el
> plugin queda "no configurado" (no bloquea la venta, pero tampoco timbra). Para timbrar,
> levanta tu backend en `:8080` y captura el token en Ajustes → Facturación CFDI.

## Configurar el plugin

**WooCommerce → Ajustes → Facturación CFDI:**

- **URL del puente:** `http://bridge/api/v1/facturas` (ya preconfigurada por
  `setup.sh`).
- **Token:** el `api_token` de tu backend/emisor de pruebas.
- "Probar conexión" debe dar OK (usa `/health`, ver contrato en
  [`../readme.txt`](../readme.txt)).

## Escenarios de prueba sugeridos

El objetivo: **ningún dato fiscal malo debe cruzar el pago, y el carrito NO debe
vaciarse**. Prueba en los **dos** checkouts, que el demo ya deja creados:

- **Clásico** (shortcode `[woocommerce_checkout]`): la página "Finalizar compra" en
  `/checkout/`.
- **De bloques** (bloque "Finalizar compra"): la página en `/finalizar-compra-bloques/`.

| # | Escenario | Datos | Esperado |
|---|---|---|---|
| 1 | **Combo inválido bloqueado** | Marca "Requiero factura", Régimen **605** | El dropdown de Uso **no** ofrece G03. Si se fuerza, el checkout bloquea con mensaje claro. **Carrito intacto.** |
| 2 | **Dato SAT malo** | RFC ficticio o CP que no cuadra | Bloquea **antes de cobrar** con mensaje mapeado ("Tu RFC y CP no coinciden…"). **Carrito intacto.** |
| 3 | **Combo válido timbra** | RFC real inscrito en tu backend, Régimen 601 + Uso G03 | Pasa el checkout → pedido a **on-hold** → al timbrar pasa a **completado**, aparece UUID + descargas. |
| 4 | **Puente caído (fail-open)** | Detén tu backend, intenta comprar con factura | El checkout **no** se bloquea; el pedido queda **retenido** esperando CFDI (no se pierde la venta). |

**Qué verificar en cada uno:**

- **Carrito:** tras un bloqueo (1 y 2), el carrito debe seguir con el producto.
- **On-hold:** en 3 y 4 el pedido debe quedar "En espera", no "Completado", hasta tener
  CFDI.
- **Mensaje:** el error debe ser específico (no "contacta a la tienda").

> **Ojo con "Contra entrega" (COD):** el timbrado dispara cuando el pedido pasa a
> **Completado**, no antes. Un pedido COD queda en "Procesando" y **no** se factura
> solo. Para probar el timbrado/retén: en el admin, abre el pedido y cámbialo a
> **Completado** (o usa un método de pago que complete el pedido). Con el servicio
> `cron`, el timbrado corre en ~1 min tras completar.

## Trabajo diario

- Editas el plugin en [`../`](../) (raíz del repo) → refrescas el navegador (montado en
  vivo).
- Ver logs de PHP: `docker compose logs -f wordpress`.
- Resetear todo: `docker compose down -v` (borra WP y la BD; vuelve a correr `wpcli`).

## Comandos wp ad-hoc

El servicio `wpcli` tiene como entrypoint el `setup.sh`, así que para correr un comando
`wp` suelto hay que sobreescribir el entrypoint y usar `--allow-root` (corre como
root):

```powershell
docker compose run --rm --entrypoint wp wpcli --allow-root plugin get facturacionmozart-woocommerce-plugin --field=version
docker compose run --rm --entrypoint wp wpcli --allow-root user update admin --user_pass=admin
```

## Solución de problemas

| Síntoma | Causa / solución |
|---|---|
| `failed to connect to the docker API ... dockerDesktopLinuxEngine` | Docker Desktop no está corriendo. Ábrelo y espera a que la ballena quede fija. |
| Pull falla con `EOF` desde `cloudfront.docker.com` | Tu red corta el CDN de Docker Hub. Agrega un espejo en `~/.docker/daemon.json`: `{ "registry-mirrors": ["https://mirror.gcr.io"] }` y reinicia Docker Desktop. |
| `Could not create directory ... wp-content/upgrade` (o `uploads`) | Los directorios `upgrade`/`uploads` no existían o no eran escribibles, y WooCommerce/el plugin no podían descomprimirse → el setup abortaba. Ya resuelto: `setup.sh` los pre-crea y ajusta el dueño (`www-data`) antes de instalar. |
| El puerto 8000 está ocupado | Cierra cualquier WordPress previo antes de `up`. |

## Limpieza

```powershell
docker compose down -v
```
