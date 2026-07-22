[← Volver al README principal](../README.md)

# Entorno Docker de desarrollo local — Plugin Facturación CFDI

Levanta un WordPress + WooCommerce real (checkout clásico **y** de bloques) con el
plugin montado en vivo desde el repo, para probar el checkout, el pre-flight y el
filtro uso/régimen. Sirve como guía de desarrollo: no requiere tocar un WordPress
manualmente cada vez que cambias el plugin.

> Este entorno **no incluye un backend de facturación**. El plugin solo habla REST con
> un backend propio (ver el contrato en [`../readme.txt`](../readme.txt)); aquí puedes
> montar el tuyo, o simplemente probar el checkout/UI sin backend (el plugin queda "no
> configurado": no bloquea la venta, pero tampoco timbra).

## Contenido

- [Por qué Docker y no `php -S`](#por-qué-docker-y-no-php--s)
- [Requisitos](#requisitos)
- [Cómo alcanza el contenedor a un backend en el host](#cómo-alcanza-el-contenedor-a-un-backend-en-el-host)
- [Puesta en marcha](#puesta-en-marcha)
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

## Puesta en marcha

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

## Configurar el plugin

**WooCommerce → Ajustes → Facturación CFDI:**

- **URL del puente:** `http://bridge/api/v1/facturas` (ya preconfigurada por
  `setup.sh`).
- **Token:** el `api_token` de tu backend/emisor de pruebas.
- "Probar conexión" debe dar OK (usa `/health`, ver contrato en
  [`../readme.txt`](../readme.txt)).

## Escenarios de prueba sugeridos

El objetivo: **ningún dato fiscal malo debe cruzar el pago, y el carrito NO debe
vaciarse**. Prueba en los **dos** checkouts (crea dos páginas: una con el shortcode
`[woocommerce_checkout]` = clásico, y otra con el bloque "Finalizar compra" = bloques).

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
docker compose run --rm --entrypoint wp wpcli --allow-root plugin get facturacion-cfdi --field=version
docker compose run --rm --entrypoint wp wpcli --allow-root user update admin --user_pass=admin
```

## Solución de problemas

| Síntoma | Causa / solución |
|---|---|
| `failed to connect to the docker API ... dockerDesktopLinuxEngine` | Docker Desktop no está corriendo. Ábrelo y espera a que la ballena quede fija. |
| Pull falla con `EOF` desde `cloudfront.docker.com` | Tu red corta el CDN de Docker Hub. Agrega un espejo en `~/.docker/daemon.json`: `{ "registry-mirrors": ["https://mirror.gcr.io"] }` y reinicia Docker Desktop. |
| `Could not create directory ... wp-content/upgrade` | Choque de permisos entre la imagen `cli` (Alpine) y `apache` (Debian). Ya resuelto: los servicios cli corren como `user: root`. |
| El puerto 8000 está ocupado | Cierra cualquier WordPress previo antes de `up`. |

## Limpieza

```powershell
docker compose down -v
```
