<?php
/**
 * Campos SAT por producto en el admin de WooCommerce
 * (ClaveProdServ y ClaveUnidad). Si no se capturan, el puente aplica su default.
 *
 * @package FacturacionCFDI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCFDI_Product_Admin {

	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'campos' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'guardar' ) );
	}

	/**
	 * Renderiza los campos en la pestaña General del producto.
	 */
	public static function campos() {
		echo '<div class="options_group">';

		woocommerce_wp_text_input(
			array(
				'id'          => '_fcfdi_clave_prod_serv',
				'label'       => __( 'SAT ClaveProdServ', 'facturacion-cfdi' ),
				'description' => __( 'Clave de producto/servicio del catálogo del SAT. Si se deja vacío se usa el default del comercio.', 'facturacion-cfdi' ),
				'desc_tip'    => true,
				'custom_attributes' => array( 'maxlength' => '8' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => '_fcfdi_clave_unidad',
				'label'       => __( 'SAT ClaveUnidad', 'facturacion-cfdi' ),
				'description' => __( 'Clave de unidad del catálogo del SAT (p.ej. H87 = Pieza).', 'facturacion-cfdi' ),
				'desc_tip'    => true,
				'custom_attributes' => array( 'maxlength' => '3' ),
			)
		);

		echo '</div>';
	}

	/**
	 * Guarda los campos del producto.
	 *
	 * @param int $product_id Id del producto.
	 */
	public static function guardar( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce valida el nonce del editor de producto.
		$clave  = isset( $_POST['_fcfdi_clave_prod_serv'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['_fcfdi_clave_prod_serv'] ) ) ) : '';
		$unidad = isset( $_POST['_fcfdi_clave_unidad'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['_fcfdi_clave_unidad'] ) ) ) : '';
		// phpcs:enable

		$product->update_meta_data( '_fcfdi_clave_prod_serv', $clave );
		$product->update_meta_data( '_fcfdi_clave_unidad', $unidad );
		$product->save();
	}
}
