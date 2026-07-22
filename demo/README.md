# Tienda demo — Botica Serena (ficticia)

Entorno de simulación WooCommerce para probar el plugin de facturación CFDI
(`facturacion-cfdi`) de forma inmersiva: identidad ficticia, productos naturistas,
portada y paleta de color pastel. **Marca inventada solo para esta demo — sin
relación con ningún negocio real.**

No versiona WordPress ni la base de datos: solo los **scripts reproducibles** que
arman la tienda sobre una instalación limpia.

## Contenido

| Archivo | Qué hace |
|---------|----------|
| `setup-store.php` | Limpia contenido genérico, crea identidad, productos (con claves SAT), páginas y fija la portada. Idempotente. |
| `make-images.php` | Genera imágenes de producto con la marca (GD) y las asigna como imagen destacada. |
| `mu-plugins/demo-brand.php` | Paleta pastel y estilos de la tienda demo (tienda + checkout). Va en `wp-content/mu-plugins/`. |
| `mu-plugins/demo-i18n.php` | Ajusta textos de WooCommerce Blocks que el paquete es_MX no traduce. |

## Montaje

Requisitos: PHP 8.2 (con `gd` + `mysqli`), WordPress, WooCommerce y el plugin
`facturacion-cfdi` activos, y [WP-CLI](https://wp-cli.org/).

```sh
# 0) Español (México): instala el paquete de idioma de WordPress y WooCommerce.
#    WooCommerce debe ser una versión estable (las betas no traen es_MX).
wp language core install es_MX --activate
wp language plugin install woocommerce es_MX

# 1) Copiar la marca (mu-plugins de carga obligatoria)
cp mu-plugins/*.php <wordpress>/wp-content/mu-plugins/

# 2) Armar la tienda (idempotente): limpia lo genérico, crea identidad, productos,
#    portada (con sección Nosotros), traduce las páginas de WooCommerce y arma el
#    menú del flujo de compra (Inicio · Tienda · Carrito · Mi cuenta).
wp eval-file setup-store.php

# 3) Generar imágenes de producto
wp eval-file make-images.php
```

Luego configurar el plugin (Ajustes → Facturación CFDI): URL del puente y token
del emisor. Método de pago: contra entrega (lo habilita `setup-store.php`).

## Flujo de prueba

Pedido → completar → el plugin timbra vía el puente → descarga XML/PDF →
cancelar el pedido → se cancela el CFDI. Para factura con RFC real, marcar
"Requiero factura" en el checkout y capturar los datos fiscales.

> **Nota importante:** esta demo arma solo la tienda (WordPress + WooCommerce +
> plugin). El timbrado real requiere tu propio backend que implemente el
> contrato REST descrito en `readme.txt` (raíz del plugin) — no se distribuye
> aquí. Sin ese backend, el checkout y la UI funcionan igual, pero el CFDI no
> se genera (el plugin queda en modo "no configurado": no bloquea la venta,
> solo no timbra).
