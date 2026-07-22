#!/usr/bin/env bash
# Instala WordPress + WooCommerce y activa el plugin Facturación CFDI.
# Idempotente: se puede correr varias veces.
set -e

WP="wp --allow-root --path=/var/www/html"

echo "==> Esperando a la base de datos..."
until $WP db check >/dev/null 2>&1; do
  # Si WP aún no está instalado, db check falla; intentamos install más abajo.
  if $WP core is-installed >/dev/null 2>&1; then break; fi
  # Probar conexión cruda a la DB.
  if $WP db query "SELECT 1" >/dev/null 2>&1; then break; fi
  sleep 3
done

if ! $WP core is-installed >/dev/null 2>&1; then
  echo "==> Instalando WordPress..."
  $WP core install \
    --url="http://localhost:8000" \
    --title="QA Facturación CFDI" \
    --admin_user="admin" \
    --admin_password="admin" \
    --admin_email="qa@example.test" \
    --skip-email
else
  echo "==> WordPress ya instalado."
fi

echo "==> Instalando/activando WooCommerce..."
$WP plugin is-installed woocommerce >/dev/null 2>&1 || $WP plugin install woocommerce --activate
$WP plugin activate woocommerce || true

echo "==> Activando plugin Facturación CFDI..."
$WP plugin activate facturacionmozart-woocommerce-plugin

echo "==> Datos de tienda mínimos (MXN, país MX)..."
$WP option update woocommerce_currency "MXN"
$WP option update woocommerce_default_country "MX:BCN"

# WooCommerce nuevo arranca en modo "Próximamente" (Launch Your Store) y oculta toda la
# tienda (portada/carrito/checkout). Se lanza la tienda para poder probar el checkout.
$WP option update woocommerce_coming_soon "no"
$WP option update woocommerce_store_pages_only "no"

echo "==> Permalinks bonitos (WooCommerce los recomienda; /checkout/ etc.)..."
$WP rewrite structure '/%postname%/' >/dev/null 2>&1 || true
$WP rewrite flush --hard >/dev/null 2>&1 || true

echo "==> Español (México)..."
$WP language core install es_MX --activate || true
$WP language plugin install woocommerce es_MX || true

echo "==> Armando la tienda demo 'Botica Serena' (idempotente)..."
if [ -f /demo/setup-store.php ]; then
  $WP eval-file /demo/setup-store.php || echo "  (aviso: setup-store devolvió error)"
  # Fuente TTF para el texto de marca en las imágenes (la imagen cli es Alpine).
  command -v apk >/dev/null 2>&1 && apk add --no-cache ttf-dejavu >/dev/null 2>&1 || true
  # Imágenes de producto con la marca (requiere GD + fuente).
  $WP eval-file /demo/make-images.php || echo "  (aviso: make-images devolvió error)"
else
  echo "  /demo no montado; se omite Botica Serena."
fi

echo "==> Preconfigurando el plugin (URL del proxy)..."
# URL al proxy 'bridge'. El token NO se preconfigura aquí: es el de TU backend/puente
# (emisor propio), no algo que este repo pueda traer de fábrica. Sin token el plugin se
# considera "no configurado" y TODAS las validaciones fiscales quedan en fail-open (nada
# bloquea la venta, pero tampoco se timbra) — captúralo en la UI o exporta
# QA_BRIDGE_TOKEN antes de correr este script.
QA_TOKEN="${QA_BRIDGE_TOKEN:-}"
$WP eval "\$o = get_option('fcfdi_settings', array()); \$o['api_url'] = 'http://bridge/api/v1/facturas'; if ('${QA_TOKEN}' !== '') { \$o['api_token'] = '${QA_TOKEN}'; } update_option('fcfdi_settings', \$o);"
# Refresca el catálogo régimen/uso cacheado (por si quedó vacío de una corrida sin token).
$WP transient delete fcfdi_catalogo_regimen_uso >/dev/null 2>&1 || true

echo ""
echo "======================================================================"
echo " LISTO. Abre http://localhost:8000  (admin / admin)"
echo " Plugin en: WooCommerce > Ajustes > Facturación CFDI"
echo "   URL del puente: http://bridge/api/v1/facturas  (ya preconfigurada)"
echo "   Falta: capturar el Token de tu backend/emisor y 'Probar conexión'."
echo " Requisitos del host: tu backend/puente REST en :8080 (HTTP, sin TLS, para QA)."
echo " Este demo NO trae un backend/puente de facturación: solo la tienda WooCommerce"
echo " + el plugin. Para timbrar de verdad necesitas tu propia implementación del"
echo " contrato REST descrito en ../readme.txt."
echo "======================================================================"
