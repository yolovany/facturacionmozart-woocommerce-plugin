<?php
/**
 * Demo "Botica Serena" — arma la tienda de simulación para probar el
 * plugin de facturación CFDI de forma inmersiva. Idempotente.
 *
 * Uso:  wp eval-file setup-store.php
 * Requisitos: WordPress + WooCommerce + plugin facturacionmozart-woocommerce-plugin activos.
 * Marca y productos ficticios, creados solo para esta demo.
 */

if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
	echo "WooCommerce no está activo.\n";
	return;
}

/* ------------------------------------------------------------------ *
 * 1) Limpieza de contenido genérico de WordPress/WooCommerce.
 * ------------------------------------------------------------------ */
$borrar_slugs_pages = array( 'sample-page', 'privacy-policy', 'nosotros' ); // Nosotros se fusiona en Inicio
foreach ( $borrar_slugs_pages as $slug ) {
	$pg = get_page_by_path( $slug );
	if ( $pg ) { wp_delete_post( $pg->ID, true ); }
}
// Post de ejemplo "Hello world!" y cualquier post de ejemplo.
$hello = get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => -1 ) );
foreach ( $hello as $h ) {
	if ( in_array( $h->post_name, array( 'hello-world' ), true ) || $h->post_title === 'Hello world!' ) {
		wp_delete_post( $h->ID, true );
	}
}
// Comentario de ejemplo.
foreach ( get_comments( array( 'status' => 'all' ) ) as $c ) { wp_delete_comment( $c->comment_ID, true ); }
// Producto genérico de QA, si quedó.
$qa = get_page_by_path( 'producto-qa-cfdi', OBJECT, 'product' );
if ( $qa ) { wp_delete_post( $qa->ID, true ); }

/* ------------------------------------------------------------------ *
 * 2) Identidad del sitio.
 * ------------------------------------------------------------------ */
update_option( 'blogname', 'Botica Serena' );
update_option( 'blogdescription', 'Bienestar natural para tu día a día' );
update_option( 'WPLANG', 'es_MX' ); // requiere el paquete de idioma instalado (ver README)

/* ------------------------------------------------------------------ *
 * 3) Método de pago de prueba (contra entrega).
 * ------------------------------------------------------------------ */
$cod = get_option( 'woocommerce_cod_settings', array() );
$cod['enabled'] = 'yes';
update_option( 'woocommerce_cod_settings', $cod );

// Títulos de las páginas de WooCommerce en español (los slugs/IDs no cambian).
$titulos_wc = array(
	'shop'      => 'Tienda',
	'cart'      => 'Carrito',
	'checkout'  => 'Finalizar compra',
	'myaccount' => 'Mi cuenta',
);
foreach ( $titulos_wc as $key => $titulo ) {
	$pid = wc_get_page_id( $key );
	if ( $pid > 0 ) { wp_update_post( array( 'ID' => $pid, 'post_title' => $titulo ) ); }
}
// Página placeholder de devoluciones (genérica): se elimina.
$ret = get_page_by_path( 'refund_returns' );
if ( $ret ) { wp_delete_post( $ret->ID, true ); }

/* ------------------------------------------------------------------ *
 * 4) Productos naturistas ficticios (upsert por SKU) con claves SAT.
 * ------------------------------------------------------------------ */
$productos = array(
	array( 'sku' => 'BSE-TE-001',   'name' => 'Té Verde Orgánico 100g',           'price' => '85',  'cps' => '50201706' ),
	array( 'sku' => 'BSE-MIEL-002', 'name' => 'Miel de Abeja Multifloral 500g',    'price' => '120', 'cps' => '50161509' ),
	array( 'sku' => 'BSE-MOR-003',  'name' => 'Cápsulas de Moringa 60 caps',       'price' => '180', 'cps' => '51142000' ),
	array( 'sku' => 'BSE-AE-004',   'name' => 'Aceite Esencial de Lavanda 15ml',   'price' => '95',  'cps' => '53131626' ),
	array( 'sku' => 'BSE-HOM-005',  'name' => 'Tintura Madre de Equinácea 30ml',   'price' => '110', 'cps' => '51241100' ),
	array( 'sku' => 'BSE-JAB-006',  'name' => 'Jabón Artesanal de Avena',          'price' => '45',  'cps' => '53131600' ),
);
foreach ( $productos as $data ) {
	$id = wc_get_product_id_by_sku( $data['sku'] );
	$product = $id ? wc_get_product( $id ) : new WC_Product_Simple();
	$product->set_name( $data['name'] );
	$product->set_sku( $data['sku'] );
	$product->set_regular_price( $data['price'] );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$pid = $product->save();
	update_post_meta( $pid, '_fcfdi_clave_prod_serv', $data['cps'] );
	update_post_meta( $pid, '_fcfdi_clave_unidad', 'H87' );
	echo "producto: {$data['name']} (id {$pid})\n";
}

/* ------------------------------------------------------------------ *
 * 5) Páginas: Inicio (portada) y Nosotros, con contenido ficticio.
 * ------------------------------------------------------------------ */
$servicios = array(
	'💊' => 'Farmacia Homeopática',
	'🩺' => 'Consultorio Naturopático',
	'🥗' => 'Alimentos naturales y orgánicos',
	'🍪' => 'Golosinas y botanas saludables',
	'🌿' => 'Herbolaria estandarizada',
	'🕯️' => 'Inciensos y aromaterapia',
	'🍵' => 'Té, Yerba Mate y Tisanas',
	'🧬' => 'Suplementos y nutracéuticos',
	'🧴' => 'Cosmecéutica y aseo natural',
	'📦' => 'Pedidos especiales',
);
$serv_html = '';
foreach ( $servicios as $ico => $s ) {
	$serv_html .= '<div><span class="bse-ico">' . $ico . '</span><span>' . esc_html( $s ) . '</span></div>';
}
$shop_url = esc_url( get_permalink( wc_get_page_id( 'shop' ) ) );

// Portada en un único bloque HTML para controlar el diseño y el espaciado
// (sin los huecos ni estilos genéricos del tema de bloques).
$home_content = '<!-- wp:html -->'
	. '<div class="bse-home">'
	.   '<section class="bse-hero">'
	.     '<span class="bse-desde">Tienda demo</span>'
	.     '<h1>Botica Serena</h1>'
	.     '<p>Bienestar natural para tu día a día. Productos 100&nbsp;% naturales, '
	.     'pensados para acompañar tu salud integral.</p>'
	.     '<a class="bse-cta" href="' . $shop_url . '">Ver la tienda</a>'
	.   '</section>'
	.   '<section class="bse-sec">'
	.     '<h2 class="bse-h2">Lo que ofrecemos</h2>'
	.     '<div class="bse-servicios">' . $serv_html . '</div>'
	.   '</section>'
	.   '<section class="bse-nosotros">'
	.     '<span class="bse-kicker">Nosotros</span>'
	.     '<h2 class="bse-h2">Nosotros</h2>'
	.     '<p>Botica Serena es una tienda ficticia creada para demostrar el plugin de '
	.     'Facturación CFDI: ofrecemos productos naturales, herbolaria, homeopatía, '
	.     'suplementos y asesoría en salud integral.</p>'
	.   '</section>'
	. '</div>'
	. '<!-- /wp:html -->';

$existing_home = get_page_by_path( 'inicio' );
$home_data = array(
	'post_title'   => 'Inicio',
	'post_content' => $home_content,
	'post_status'  => 'publish',
	'post_type'    => 'page',
	'post_name'    => 'inicio',
);
if ( $existing_home ) { $home_data['ID'] = $existing_home->ID; }
$home_id = wp_insert_post( $home_data );

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home_id );

/* ------------------------------------------------------------------ *
 * 5.b) Segunda página de checkout con el BLOQUE "Finalizar compra".
 *      WooCommerce crea la página de checkout por defecto con el shortcode
 *      [woocommerce_checkout] (clásico). Para poder probar TAMBIÉN el checkout de
 *      bloques (React + Store API), se crea una página aparte con el bloque completo.
 *      Idempotente por slug 'finalizar-compra-bloques'.
 * ------------------------------------------------------------------ */
$checkout_bloques = <<<'HTML'
<!-- wp:woocommerce/checkout -->
<div class="wp-block-woocommerce-checkout alignwide wc-block-checkout is-loading"><!-- wp:woocommerce/checkout-fields-block -->
<div class="wp-block-woocommerce-checkout-fields-block"><!-- wp:woocommerce/checkout-express-payment-block -->
<div class="wp-block-woocommerce-checkout-express-payment-block"></div>
<!-- /wp:woocommerce/checkout-express-payment-block -->

<!-- wp:woocommerce/checkout-contact-information-block -->
<div class="wp-block-woocommerce-checkout-contact-information-block"></div>
<!-- /wp:woocommerce/checkout-contact-information-block -->

<!-- wp:woocommerce/checkout-shipping-method-block -->
<div class="wp-block-woocommerce-checkout-shipping-method-block"></div>
<!-- /wp:woocommerce/checkout-shipping-method-block -->

<!-- wp:woocommerce/checkout-pickup-options-block -->
<div class="wp-block-woocommerce-checkout-pickup-options-block"></div>
<!-- /wp:woocommerce/checkout-pickup-options-block -->

<!-- wp:woocommerce/checkout-shipping-address-block -->
<div class="wp-block-woocommerce-checkout-shipping-address-block"></div>
<!-- /wp:woocommerce/checkout-shipping-address-block -->

<!-- wp:woocommerce/checkout-billing-address-block -->
<div class="wp-block-woocommerce-checkout-billing-address-block"></div>
<!-- /wp:woocommerce/checkout-billing-address-block -->

<!-- wp:woocommerce/checkout-shipping-methods-block -->
<div class="wp-block-woocommerce-checkout-shipping-methods-block"></div>
<!-- /wp:woocommerce/checkout-shipping-methods-block -->

<!-- wp:woocommerce/checkout-payment-block -->
<div class="wp-block-woocommerce-checkout-payment-block"></div>
<!-- /wp:woocommerce/checkout-payment-block -->

<!-- wp:woocommerce/checkout-additional-information-block -->
<div class="wp-block-woocommerce-checkout-additional-information-block"></div>
<!-- /wp:woocommerce/checkout-additional-information-block -->

<!-- wp:woocommerce/checkout-order-note-block -->
<div class="wp-block-woocommerce-checkout-order-note-block"></div>
<!-- /wp:woocommerce/checkout-order-note-block -->

<!-- wp:woocommerce/checkout-terms-block -->
<div class="wp-block-woocommerce-checkout-terms-block"></div>
<!-- /wp:woocommerce/checkout-terms-block -->

<!-- wp:woocommerce/checkout-actions-block -->
<div class="wp-block-woocommerce-checkout-actions-block"></div>
<!-- /wp:woocommerce/checkout-actions-block --></div>
<!-- /wp:woocommerce/checkout-fields-block -->

<!-- wp:woocommerce/checkout-totals-block -->
<div class="wp-block-woocommerce-checkout-totals-block"><!-- wp:woocommerce/checkout-order-summary-block -->
<div class="wp-block-woocommerce-checkout-order-summary-block"><!-- wp:woocommerce/checkout-order-summary-cart-items-block -->
<div class="wp-block-woocommerce-checkout-order-summary-cart-items-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-cart-items-block -->

<!-- wp:woocommerce/checkout-order-summary-totals-block -->
<div class="wp-block-woocommerce-checkout-order-summary-totals-block"><!-- wp:woocommerce/checkout-order-summary-subtotal-block -->
<div class="wp-block-woocommerce-checkout-order-summary-subtotal-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-subtotal-block -->

<!-- wp:woocommerce/checkout-order-summary-fee-block -->
<div class="wp-block-woocommerce-checkout-order-summary-fee-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-fee-block -->

<!-- wp:woocommerce/checkout-order-summary-discount-block -->
<div class="wp-block-woocommerce-checkout-order-summary-discount-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-discount-block -->

<!-- wp:woocommerce/checkout-order-summary-shipping-block -->
<div class="wp-block-woocommerce-checkout-order-summary-shipping-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-shipping-block -->

<!-- wp:woocommerce/checkout-order-summary-taxes-block -->
<div class="wp-block-woocommerce-checkout-order-summary-taxes-block"></div>
<!-- /wp:woocommerce/checkout-order-summary-taxes-block --></div>
<!-- /wp:woocommerce/checkout-order-summary-totals-block --></div>
<!-- /wp:woocommerce/checkout-order-summary-block --></div>
<!-- /wp:woocommerce/checkout-totals-block --></div>
<!-- /wp:woocommerce/checkout -->
HTML;

$existing_cob = get_page_by_path( 'finalizar-compra-bloques' );
$cob_data = array(
	'post_title'   => 'Finalizar compra (bloques)',
	'post_content' => $checkout_bloques,
	'post_status'  => 'publish',
	'post_type'    => 'page',
	'post_name'    => 'finalizar-compra-bloques',
);
if ( $existing_cob ) { $cob_data['ID'] = $existing_cob->ID; }
$cob_id = wp_insert_post( $cob_data );
echo "Checkout de bloques listo (id {$cob_id}): " . get_permalink( $cob_id ) . "\n";

/* ------------------------------------------------------------------ *
 * 6) Menú de navegación según el flujo de compra.
 * ------------------------------------------------------------------ */
$nav_items = array(
	array( 'Inicio',    home_url( '/' ) ),
	array( 'Tienda',    get_permalink( wc_get_page_id( 'shop' ) ) ),
	array( 'Carrito',   get_permalink( wc_get_page_id( 'cart' ) ) ),
	array( 'Mi cuenta', get_permalink( wc_get_page_id( 'myaccount' ) ) ),
);
$nav_blocks = '';
foreach ( $nav_items as $it ) {
	$nav_blocks .= '<!-- wp:navigation-link '
		. wp_json_encode( array( 'label' => $it[0], 'url' => $it[1], 'kind' => 'custom' ) )
		. ' /-->';
}
$nav      = get_posts( array( 'post_type' => 'wp_navigation', 'numberposts' => 1, 'post_status' => 'publish' ) );
$nav_data = array( 'post_type' => 'wp_navigation', 'post_title' => 'Menú principal', 'post_status' => 'publish', 'post_content' => $nav_blocks );
if ( $nav ) { $nav_data['ID'] = $nav[0]->ID; }
wp_insert_post( $nav_data );

/* ------------------------------------------------------------------ *
 * Carrito vacío: en español + botón "Comenzar a comprar" a la tienda
 * (en lugar de la sección "New in store" con productos, y sus textos en inglés).
 * ------------------------------------------------------------------ */
$cart_id = wc_get_page_id( 'cart' );
$cart    = $cart_id > 0 ? get_post( $cart_id ) : null;
if ( $cart ) {
	$c = $cart->post_content;
	$c = str_replace( 'Your cart is currently empty!', 'Tu carrito está vacío', $c );
	$c = str_replace( 'You may be interested in&hellip;', 'También te puede interesar…', $c );

	$tienda_url = get_permalink( wc_get_page_id( 'shop' ) );
	$boton = '<!-- wp:paragraph {"align":"center"} -->' . "\n"
		. '<p class="has-text-align-center">Aún no has agregado productos a tu carrito.</p>' . "\n"
		. '<!-- /wp:paragraph -->' . "\n\n"
		. '<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->' . "\n"
		. '<div class="wp-block-buttons"><!-- wp:button -->' . "\n"
		. '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="'
		. esc_url( $tienda_url ) . '">Comenzar a comprar</a></div>' . "\n"
		. '<!-- /wp:button --></div>' . "\n"
		. '<!-- /wp:buttons -->';

	// Reemplaza el separador de puntos + encabezado "New in store" + grilla de productos
	// por el botón. Si el markup cambia y no coincide, no altera nada (a prueba de fallos).
	$patron = '/<!-- wp:separator \{"className":"is-style-dots"\} -->.*?<!-- wp:woocommerce\/product-new [^>]*\/-->/s';
	$c      = preg_replace( $patron, $boton, $c, 1 );

	wp_update_post( array( 'ID' => $cart_id, 'post_content' => $c ) );
	echo "Carrito ajustado (español + botón a la tienda).\n";
}

echo "Tienda demo lista (español). Portada id={$home_id}. Ejecuta make-images.php para las imágenes.\n";
