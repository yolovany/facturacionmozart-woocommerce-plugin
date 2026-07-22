<?php
/**
 * Plugin Name: Botica Serena - Traducciones (Demo)
 * Description: Fuerza al español algunos textos de WooCommerce Blocks que el paquete es_MX
 *              no traduce (checkout React / badges). Se inyectan en el store de wp.i18n.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! wp_script_is( 'wp-i18n', 'registered' ) ) {
		return;
	}
	// Claves = msgid exactos del código de WooCommerce Blocks. Valores en formato Jed
	// (array; para plurales, [singular, plural]).
	$data = array(
		''                          => array( 'domain' => 'woocommerce', 'plural_forms' => 'nplurals=2; plural=(n != 1);' ),
		'Create an account with %s' => array( 'Crea una cuenta con %s' ),
		'Create a password'         => array( 'Crea una contraseña' ),
		'%d in cart'                => array( '%d en el carrito', '%d en el carrito' ),
		'in your cart'              => array( 'en tu carrito' ),
	);
	$js = 'window.wp&&wp.i18n&&wp.i18n.setLocaleData&&wp.i18n.setLocaleData(' . wp_json_encode( $data ) . ',"woocommerce");';
	// 'after' de wp-i18n: corre antes que los scripts de wc-blocks (que dependen de wp-i18n).
	wp_add_inline_script( 'wp-i18n', $js, 'after' );
}, 99 );
