<?php
/**
 * Soporte para el checkout por bloques (WooCommerce Blocks / Store API, WC 8.9+).
 * Registra los campos fiscales como "additional checkout fields".
 *
 * NOTA: requiere validación en una instancia WooCommerce real con checkout de bloques.
 * El checkout clásico (FCFDI_Checkout) no depende de esta clase.
 *
 * @package FacturacionCFDI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCFDI_Blocks {

	const NS = 'facturacion-cfdi';

	public static function init() {
		add_action( 'woocommerce_init', array( __CLASS__, 'registrar' ) );
	}

	/**
	 * Devuelve el id de campo de bloque para un slug.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function field_id( $slug ) {
		return self::NS . '/' . $slug;
	}

	/**
	 * Registra los campos en el checkout de bloques (si la API existe).
	 */
	public static function registrar() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return; // WooCommerce sin soporte de additional checkout fields.
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::field_id( 'requiere-factura' ),
				'label'    => __( 'Requiero factura', 'facturacion-cfdi' ),
				'location' => 'order',
				'type'     => 'checkbox',
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::field_id( 'rfc' ),
				'label'    => __( 'RFC', 'facturacion-cfdi' ),
				'location' => 'order',
				'type'     => 'text',
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::field_id( 'razon-social' ),
				'label'    => __( 'Razón social', 'facturacion-cfdi' ),
				'location' => 'order',
				'type'     => 'text',
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::field_id( 'cp' ),
				'label'    => __( 'Código postal fiscal', 'facturacion-cfdi' ),
				'location' => 'order',
				'type'     => 'text',
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::field_id( 'regimen-fiscal' ),
				'label'    => __( 'Régimen fiscal', 'facturacion-cfdi' ),
				'location' => 'order',
				'type'     => 'select',
				'options'  => self::opciones( FCFDI_Checkout::regimenes() ),
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::field_id( 'uso-cfdi' ),
				'label'    => __( 'Uso de CFDI', 'facturacion-cfdi' ),
				'location' => 'order',
				'type'     => 'select',
				'options'  => self::opciones( FCFDI_Checkout::usos_cfdi() ),
			)
		);
	}

	/**
	 * Convierte un mapa clave=>texto al formato de opciones de bloques.
	 *
	 * @param array $mapa Mapa.
	 * @return array
	 */
	private static function opciones( $mapa ) {
		$out = array();
		foreach ( $mapa as $value => $label ) {
			$out[] = array(
				'value' => (string) $value,
				'label' => $label,
			);
		}
		return $out;
	}

	/**
	 * Lee un campo de bloque guardado en el pedido (varios formatos de clave por compatibilidad).
	 *
	 * @param WC_Order $order Pedido.
	 * @param string   $slug  Slug del campo.
	 * @return string
	 */
	public static function leer( $order, $slug ) {
		$id = self::field_id( $slug );
		foreach ( array( '_wc_order/' . $id, '_' . $id, $id ) as $key ) {
			$val = $order->get_meta( $key );
			if ( '' !== $val && null !== $val ) {
				return is_bool( $val ) ? ( $val ? '1' : '' ) : (string) $val;
			}
		}
		return '';
	}
}
