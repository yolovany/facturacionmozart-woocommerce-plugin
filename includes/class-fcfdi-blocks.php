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
		// El checkout de bloques guarda el borrador (PUT) con cada cambio: validar ahí mostraba
		// todos los errores fiscales en cuanto el cliente marcaba la casilla, antes de escribir.
		// Se valida al realizar el pedido (POST), antes de crear el pedido y cobrar.
		if ( empty( $fields[ self::field_id( 'requiere-factura' ) ] ) || self::es_guardado_parcial() ) {
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
	 * ¿La petición actual es un guardado parcial del checkout (PUT/PATCH)? apiFetch los manda
	 * como POST con X-HTTP-Method-Override, igual que los interpreta la API REST de WordPress.
	 *
	 * @return bool
	 */
	private static function es_guardado_parcial() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo se lee el método.
		$metodo = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ( $_GET['_method'] ?? ( $_SERVER['REQUEST_METHOD'] ?? '' ) );
		return in_array( strtoupper( sanitize_text_field( wp_unslash( $metodo ) ) ), array( 'PUT', 'PATCH' ), true );
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

		// Los campos de bloque no tienen descripción: el aviso va en la etiqueta. Sin el
		// «(opcional)» que WooCommerce agrega, que en una casilla no dice nada.
		$etiqueta = FCFDI_Cliente::facturar_despues()
			? __( 'Requiero factura', 'facturacionmozart-woocommerce-plugin' )
			: __( 'Requiero factura (si no la pides ahora, después ya no podrás pedirla)', 'facturacionmozart-woocommerce-plugin' );
		woocommerce_register_additional_checkout_field(
			array(
				'id'            => self::field_id( 'requiere-factura' ),
				'label'         => $etiqueta,
				'optionalLabel' => $etiqueta,
				'location'      => 'order',
				'type'          => 'checkbox',
			)
		);

		$reglas = self::reglas_datos_fiscales();

		woocommerce_register_additional_checkout_field(
			$reglas + array(
				'id'       => self::field_id( 'rfc' ),
				'label'    => __( 'RFC', 'facturacionmozart-woocommerce-plugin' ),
				'location' => 'order',
				'type'     => 'text',
			)
		);

		woocommerce_register_additional_checkout_field(
			$reglas + array(
				'id'       => self::field_id( 'razon-social' ),
				'label'    => __( 'Razón social', 'facturacionmozart-woocommerce-plugin' ),
				'location' => 'order',
				'type'     => 'text',
			)
		);

		woocommerce_register_additional_checkout_field(
			$reglas + array(
				'id'       => self::field_id( 'cp' ),
				'label'    => __( 'Código postal fiscal', 'facturacionmozart-woocommerce-plugin' ),
				'location' => 'order',
				'type'     => 'text',
			)
		);

		woocommerce_register_additional_checkout_field(
			$reglas + array(
				'id'       => self::field_id( 'regimen-fiscal' ),
				'label'    => __( 'Régimen fiscal', 'facturacionmozart-woocommerce-plugin' ),
				'location' => 'order',
				'type'     => 'select',
				'options'  => self::opciones( FCFDI_Checkout::regimenes() ),
			)
		);

		woocommerce_register_additional_checkout_field(
			$reglas + array(
				'id'       => self::field_id( 'uso-cfdi' ),
				'label'    => __( 'Uso de CFDI', 'facturacionmozart-woocommerce-plugin' ),
				'location' => 'order',
				'type'     => 'select',
				'options'  => self::opciones( FCFDI_Checkout::usos_cfdi() ),
			)
		);
	}

	/**
	 * Reglas de los datos fiscales: ocultos hasta marcar "Requiero factura" y obligatorios
	 * al marcarlo. Antes se mostraban siempre como "(opcional)" aunque el comprador pidiera
	 * factura. Requiere WooCommerce 9.9+ (reglas condicionales de campos); en versiones
	 * anteriores los campos quedan visibles como antes y la validación del servidor
	 * (validar_order) sigue siendo la que exige los datos.
	 *
	 * @return array
	 */
	private static function reglas_datos_fiscales() {
		if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '9.9', '<' ) ) {
			return array();
		}

		$casilla = self::field_id( 'requiere-factura' );
		$campos  = function ( $condicion ) {
			return array(
				'checkout' => array(
					'properties' => array(
						'additional_fields' => $condicion,
					),
				),
			);
		};

		return array(
			'required' => $campos(
				array(
					'properties' => array( $casilla => array( 'const' => true ) ),
					'required'   => array( $casilla ),
				)
			),
			'hidden'   => $campos(
				array(
					'properties' => array( $casilla => array( 'not' => array( 'const' => true ) ) ),
				)
			),
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
