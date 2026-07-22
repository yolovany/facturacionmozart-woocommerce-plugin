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
		// Validación condicional cruzada en el checkout de bloques.
		add_filter( 'woocommerce_blocks_validate_location_order_fields', array( __CLASS__, 'validar_order' ), 10, 3 );
	}

	/**
	 * Si el cliente marca "Requiero factura", exige los datos fiscales (checkout de bloques).
	 *
	 * @param \WP_Error $errors Errores acumulados.
	 * @param array     $fields Valores de los campos de la ubicación 'order'.
	 * @param string    $group  Grupo (other).
	 * @return \WP_Error
	 */
	public static function validar_order( $errors, $fields, $group ) {
		if ( empty( $fields[ self::field_id( 'requiere-factura' ) ] ) ) {
			return $errors;
		}

		$rfc     = strtoupper( trim( (string) ( $fields[ self::field_id( 'rfc' ) ] ?? '' ) ) );
		$razon   = trim( (string) ( $fields[ self::field_id( 'razon-social' ) ] ?? '' ) );
		$cp      = trim( (string) ( $fields[ self::field_id( 'cp' ) ] ?? '' ) );
		$regimen = trim( (string) ( $fields[ self::field_id( 'regimen-fiscal' ) ] ?? '' ) );
		$uso     = trim( (string) ( $fields[ self::field_id( 'uso-cfdi' ) ] ?? '' ) );

		if ( ! preg_match( '/^([A-ZÑ&]{3,4})\d{6}([A-Z\d]{3})$/', $rfc ) ) {
			$errors->add( 'fcfdi_rfc', __( 'El RFC no tiene un formato válido.', 'facturacionmozart-woocommerce-plugin' ) );
		}
		if ( '' === $razon ) {
			$errors->add( 'fcfdi_razon', __( 'Captura la razón social para facturar.', 'facturacionmozart-woocommerce-plugin' ) );
		}
		if ( ! preg_match( '/^\d{5}$/', $cp ) ) {
			$errors->add( 'fcfdi_cp', __( 'El código postal fiscal debe tener 5 dígitos.', 'facturacionmozart-woocommerce-plugin' ) );
		}
		if ( '' === $regimen ) {
			$errors->add( 'fcfdi_regimen', __( 'Selecciona el régimen fiscal.', 'facturacionmozart-woocommerce-plugin' ) );
		}
		if ( '' === $uso ) {
			$errors->add( 'fcfdi_uso', __( 'Selecciona el uso de CFDI.', 'facturacionmozart-woocommerce-plugin' ) );
		}
		if ( '' !== $uso && '' !== $regimen && class_exists( 'FCFDI_Checkout' ) && ! FCFDI_Checkout::combo_valido( $uso, $regimen ) ) {
			$errors->add( 'fcfdi_uso_regimen', FCFDI_Checkout::mensaje_error( 'USO_CFDI_INCOMPATIBLE' ) );
		}

		// Pre-flight contra el puente sólo si el formato local pasó. Corre en la validación
		// de campos del Store API: antes de crear el pedido y cobrar, el carrito no se pierde.
		if ( ! $errors->has_errors() && class_exists( 'FCFDI_Checkout' ) ) {
			$pref = FCFDI_Checkout::validar_receptor_remoto(
				array(
					'rfc'            => $rfc,
					'razon_social'   => $razon,
					'regimen_fiscal' => $regimen,
					'cp'             => $cp,
					'uso_cfdi'       => $uso,
				)
			);
			if ( ! $pref['ok'] ) {
				$errors->add( 'fcfdi_receptor', $pref['mensaje'] );
			}
		}

		return $errors;
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
				'label'    => __( 'Requiero factura', 'facturacionmozart-woocommerce-plugin' ),
				'location' => 'order',
				'type'     => 'checkbox',
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::field_id( 'rfc' ),
				'label'    => __( 'RFC', 'facturacionmozart-woocommerce-plugin' ),
				'location' => 'order',
				'type'     => 'text',
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::field_id( 'razon-social' ),
				'label'    => __( 'Razón social', 'facturacionmozart-woocommerce-plugin' ),
				'location' => 'order',
				'type'     => 'text',
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::field_id( 'cp' ),
				'label'    => __( 'Código postal fiscal', 'facturacionmozart-woocommerce-plugin' ),
				'location' => 'order',
				'type'     => 'text',
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::field_id( 'regimen-fiscal' ),
				'label'    => __( 'Régimen fiscal', 'facturacionmozart-woocommerce-plugin' ),
				'location' => 'order',
				'type'     => 'select',
				'options'  => self::opciones( FCFDI_Checkout::regimenes() ),
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::field_id( 'uso-cfdi' ),
				'label'    => __( 'Uso de CFDI', 'facturacionmozart-woocommerce-plugin' ),
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
		// WooCommerce guarda los additional checkout fields de bloques con el prefijo
		// "_wc_other/". Se incluyen otros candidatos por compatibilidad entre versiones.
		foreach ( array( '_wc_other/' . $id, '_wc_order/' . $id, '_' . $id, $id ) as $key ) {
			$val = $order->get_meta( $key );
			if ( '' !== $val && null !== $val ) {
				return is_bool( $val ) ? ( $val ? '1' : '' ) : (string) $val;
			}
		}
		return '';
	}
}
