<?php
/**
 * Plugin Name:       Facturación CFDI para WooCommerce
 * Plugin URI:        https://github.com/yolovany/facturacionmozart-woocommerce-plugin
 * Description:        Genera facturas CFDI automáticamente para cada pedido de WooCommerce a través del puente REST del sistema de facturación. El cliente puede solicitar factura con su RFC en el checkout; si no, se factura a público en general.
 * Version:           1.12.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Infotek
 * License:           GPL-2.0-or-later
 * Text Domain:       facturacionmozart-woocommerce-plugin
 * WC requires at least: 6.0
 * WC tested up to:   9.0
 *
 * @package FacturacionCFDI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Acceso directo no permitido.
}

define( 'FCFDI_VERSION', '1.12.1' );
define( 'FCFDI_PLUGIN_FILE', __FILE__ );
define( 'FCFDI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FCFDI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declarar compatibilidad con HPOS (High-Performance Order Storage) de WooCommerce.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FCFDI_PLUGIN_FILE, true );
		}
	}
);

/**
 * Arranque del plugin: solo si WooCommerce está activo.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>';
					esc_html_e( 'Facturación CFDI para WooCommerce requiere que WooCommerce esté instalado y activo.', 'facturacionmozart-woocommerce-plugin' );
					echo '</p></div>';
				}
			);
			return;
		}

		require_once FCFDI_PLUGIN_DIR . 'includes/class-fcfdi-settings.php';
		require_once FCFDI_PLUGIN_DIR . 'includes/class-fcfdi-api-client.php';
		require_once FCFDI_PLUGIN_DIR . 'includes/class-fcfdi-checkout.php';
		require_once FCFDI_PLUGIN_DIR . 'includes/class-fcfdi-blocks.php';
		require_once FCFDI_PLUGIN_DIR . 'includes/class-fcfdi-product-admin.php';
		require_once FCFDI_PLUGIN_DIR . 'includes/class-fcfdi-order-handler.php';
		require_once FCFDI_PLUGIN_DIR . 'includes/class-fcfdi-my-account.php';
		require_once FCFDI_PLUGIN_DIR . 'includes/class-fcfdi-webhook.php';
		require_once FCFDI_PLUGIN_DIR . 'includes/class-fcfdi-admin-orders.php';
		require_once FCFDI_PLUGIN_DIR . 'includes/class-fcfdi-cancel.php';
		require_once FCFDI_PLUGIN_DIR . 'includes/class-fcfdi-cliente.php';
		require_once FCFDI_PLUGIN_DIR . 'includes/class-fcfdi-cuenta.php';

		FCFDI_Settings::init();
		FCFDI_Checkout::init();
		FCFDI_Blocks::init();
		FCFDI_Product_Admin::init();
		FCFDI_Order_Handler::init();
		FCFDI_My_Account::init();
		FCFDI_Webhook::init();
		FCFDI_Admin_Orders::init();
		FCFDI_Cancel::init();
		FCFDI_Cliente::init();
		FCFDI_Cuenta::init();

		load_plugin_textdomain( 'facturacionmozart-woocommerce-plugin', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);
