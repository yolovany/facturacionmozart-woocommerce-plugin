<?php
/**
 * Campos fiscales en el checkout (checkout clásico).
 *
 * @package FacturacionCFDI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCFDI_Checkout {

	public static function init() {
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'campos' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validar' ) );
		add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'guardar' ) );
	}

	const CACHE_KEY = 'fcfdi_catalogo_regimen_uso';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/** Catálogo mínimo de respaldo si el puente no responde (el checkout no debe quedar sin opciones). */
	const USOS_RESPALDO = array(
		'G01' => 'G01 - Adquisición de mercancías',
		'G03' => 'G03 - Gastos en general',
		'S01' => 'S01 - Sin efectos fiscales',
	);

	const REGIMENES_RESPALDO = array(
		'605' => '605 - Sueldos y salarios',
		'612' => '612 - Personas Físicas con Actividades Empresariales y Profesionales',
		'626' => '626 - Régimen Simplificado de Confianza (RESICO)',
		'601' => '601 - General de Ley Personas Morales',
	);

	/**
	 * Catálogo régimen/uso y matriz de compatibilidad, consultado al puente y cacheado.
	 * Fuente de verdad: la misma matriz SAT que usa el puente para validar el timbrado,
	 * así el checkout no deja capturar combinaciones que luego rechazaría el backend.
	 *
	 * @return array{regimenes:array,usos:array,matriz:array}
	 */
	public static function catalogo() {
		$cache = get_transient( self::CACHE_KEY );
		if ( is_array( $cache ) ) {
			return $cache;
		}

		$datos = array(
			'regimenes' => self::REGIMENES_RESPALDO,
			'usos'      => self::USOS_RESPALDO,
			'matriz'    => array(),
		);

		if ( class_exists( 'FCFDI_Settings' ) && FCFDI_Settings::esta_configurado() ) {
			$client = new FCFDI_Api_Client();
			$res    = $client->catalogo_regimen_uso();
			if ( ! is_wp_error( $res ) && 200 === (int) $res['code'] ) {
				$body = $res['body'];
				if ( ! empty( $body['regimenes'] ) && ! empty( $body['usos'] ) ) {
					$datos = array(
						'regimenes' => $body['regimenes'],
						'usos'      => $body['usos'],
						'matriz'    => isset( $body['matriz'] ) ? $body['matriz'] : array(),
					);
				}
			}
		}

		// Cache corta si vino del respaldo (para reintentar pronto), larga si vino del puente.
		$ttl = empty( $datos['matriz'] ) ? 5 * MINUTE_IN_SECONDS : self::CACHE_TTL;
		set_transient( self::CACHE_KEY, $datos, $ttl );
		return $datos;
	}

	/**
	 * Usos de CFDI disponibles.
	 *
	 * @return array
	 */
	public static function usos_cfdi() {
		return self::catalogo()['usos'];
	}

	/**
	 * Regímenes fiscales disponibles.
	 *
	 * @return array
	 */
	public static function regimenes() {
		return self::catalogo()['regimenes'];
	}

	/**
	 * Traduce un código de error del puente a un mensaje claro y accionable para el cliente.
	 *
	 * @param string $codigo   Código del puente (p.ej. USO_CFDI_INCOMPATIBLE).
	 * @param string $fallback Mensaje del puente si el código no está mapeado.
	 * @return string
	 */
	public static function mensaje_error( $codigo, $fallback = '' ) {
		$mapa = array(
			'RFC_FALTANTE'          => __( 'Captura tu RFC para poder facturar.', 'facturacionmozart-woocommerce-plugin' ),
			'RFC_FORMATO'           => __( 'El RFC no tiene un formato válido. Revísalo en tu Constancia de Situación Fiscal.', 'facturacionmozart-woocommerce-plugin' ),
			'REGIMEN_FALTANTE'      => __( 'Selecciona tu régimen fiscal.', 'facturacionmozart-woocommerce-plugin' ),
			'REGIMEN_INVALIDO'      => __( 'El régimen fiscal seleccionado no es válido.', 'facturacionmozart-woocommerce-plugin' ),
			'CP_FALTANTE'           => __( 'Captura tu código postal fiscal.', 'facturacionmozart-woocommerce-plugin' ),
			'CP_FORMATO'            => __( 'El código postal fiscal debe tener 5 dígitos.', 'facturacionmozart-woocommerce-plugin' ),
			'USO_CFDI_FALTANTE'     => __( 'Selecciona el uso de CFDI.', 'facturacionmozart-woocommerce-plugin' ),
			'USO_CFDI_INCOMPATIBLE' => __( 'El uso de CFDI no aplica a tu régimen fiscal. Elige uno de la lista.', 'facturacionmozart-woocommerce-plugin' ),
			'CFDI40147'             => __( 'Tu RFC y código postal no coinciden con el registro del SAT. Verifica ambos en tu Constancia de Situación Fiscal.', 'facturacionmozart-woocommerce-plugin' ),
			'CFDI40157'             => __( 'El régimen fiscal no corresponde a tu RFC ante el SAT. Revisa tu Constancia de Situación Fiscal.', 'facturacionmozart-woocommerce-plugin' ),
			'SIN_RECEPTOR'          => __( 'Faltan tus datos fiscales para facturar.', 'facturacionmozart-woocommerce-plugin' ),
		);
		if ( isset( $mapa[ $codigo ] ) ) {
			return $mapa[ $codigo ];
		}
		return $fallback ? $fallback : __( 'No pudimos validar tus datos fiscales. Revísalos e intenta de nuevo.', 'facturacionmozart-woocommerce-plugin' );
	}

	/**
	 * Pre-flight contra el puente: valida los datos fiscales del receptor ANTES de cobrar.
	 * Si el puente no responde (caído/red), NO bloquea — el timbrado async lo validará
	 * después (el pedido quedará retenido, no se pierde la venta).
	 *
	 * @param array $receptor rfc, razon_social, regimen_fiscal, cp, uso_cfdi.
	 * @return array{ok:bool,mensaje:string}
	 */
	public static function validar_receptor_remoto( $receptor ) {
		if ( ! class_exists( 'FCFDI_Settings' ) || ! FCFDI_Settings::esta_configurado() ) {
			return array( 'ok' => true, 'mensaje' => '' );
		}
		$client = new FCFDI_Api_Client();
		$res    = $client->validar_receptor( $receptor );

		// Puente inalcanzable o error de servidor: no bloquear el checkout (fail-open).
		if ( is_wp_error( $res ) || (int) $res['code'] >= 500 ) {
			return array( 'ok' => true, 'mensaje' => '' );
		}
		if ( 200 === (int) $res['code'] ) {
			return array( 'ok' => true, 'mensaje' => '' );
		}

		// 4xx: dato del cliente incorrecto. Traducir a mensaje accionable.
		$body   = is_array( $res['body'] ) ? $res['body'] : array();
		$codigo = isset( $body['codigo'] ) ? $body['codigo'] : '';
		$msg    = self::mensaje_error( $codigo, isset( $body['mensaje'] ) ? $body['mensaje'] : '' );
		return array( 'ok' => false, 'mensaje' => $msg );
	}

	/**
	 * True si el uso de CFDI es válido para el régimen fiscal, según la matriz cacheada.
	 * Si la matriz no está disponible (puente caído), no bloquea aquí — el puente
	 * hará la validación autoritativa al recibir el pedido.
	 *
	 * @param string $uso     Uso de CFDI.
	 * @param string $regimen Régimen fiscal.
	 * @return bool
	 */
	public static function combo_valido( $uso, $regimen ) {
		$matriz = self::catalogo()['matriz'];
		if ( empty( $matriz ) || ! isset( $matriz[ $uso ] ) ) {
			return true;
		}
		return in_array( (string) $regimen, array_map( 'strval', $matriz[ $uso ] ), true );
	}

	/**
	 * Renderiza los campos en el checkout.
	 *
	 * @param WC_Checkout $checkout Checkout.
	 */
	public static function campos( $checkout ) {
		echo '<div id="fcfdi-fiscal"><h3>' . esc_html__( 'Facturación (CFDI)', 'facturacionmozart-woocommerce-plugin' ) . '</h3>';

		woocommerce_form_field(
			'fcfdi_requiere_factura',
			array(
				'type'  => 'checkbox',
				'class' => array( 'fcfdi-requiere' ),
				'label' => __( 'Requiero factura', 'facturacionmozart-woocommerce-plugin' ),
			),
			$checkout->get_value( 'fcfdi_requiere_factura' )
		);

		echo '<div id="fcfdi-campos" style="display:none;">';

		woocommerce_form_field(
			'fcfdi_rfc',
			array(
				'type'        => 'text',
				'class'       => array( 'form-row-wide' ),
				'label'       => __( 'RFC', 'facturacionmozart-woocommerce-plugin' ),
				'placeholder' => 'XAXX010101000',
			),
			$checkout->get_value( 'fcfdi_rfc' )
		);

		woocommerce_form_field(
			'fcfdi_razon_social',
			array(
				'type'  => 'text',
				'class' => array( 'form-row-wide' ),
				'label' => __( 'Razón social (como en la Constancia de Situación Fiscal)', 'facturacionmozart-woocommerce-plugin' ),
			),
			$checkout->get_value( 'fcfdi_razon_social' )
		);

		woocommerce_form_field(
			'fcfdi_cp',
			array(
				'type'        => 'text',
				'class'       => array( 'form-row-first' ),
				'label'       => __( 'Código postal fiscal', 'facturacionmozart-woocommerce-plugin' ),
				'placeholder' => '00000',
			),
			$checkout->get_value( 'fcfdi_cp' )
		);

		woocommerce_form_field(
			'fcfdi_regimen_fiscal',
			array(
				'type'    => 'select',
				'class'   => array( 'form-row-last' ),
				'label'   => __( 'Régimen fiscal', 'facturacionmozart-woocommerce-plugin' ),
				'options' => array( '' => __( 'Selecciona…', 'facturacionmozart-woocommerce-plugin' ) ) + self::regimenes(),
			),
			$checkout->get_value( 'fcfdi_regimen_fiscal' )
		);

		woocommerce_form_field(
			'fcfdi_uso_cfdi',
			array(
				'type'    => 'select',
				'class'   => array( 'form-row-wide' ),
				'label'   => __( 'Uso de CFDI', 'facturacionmozart-woocommerce-plugin' ),
				'options' => array( '' => __( 'Selecciona…', 'facturacionmozart-woocommerce-plugin' ) ) + self::usos_cfdi(),
			),
			$checkout->get_value( 'fcfdi_uso_cfdi' )
		);

		echo '</div></div>';

		// Sólo muestra/oculta los campos fiscales según el checkbox "Requiero factura".
		// No se filtra el Uso de CFDI: la validación (local + pre-flight al puente) es la
		// que rechaza un combo uso/régimen inválido al enviar, con mensaje claro.
		?>
		<script>
		( function () {
			function sync() {
				var chk = document.getElementById( 'fcfdi_requiere_factura' );
				var box = document.getElementById( 'fcfdi-campos' );
				if ( chk && box ) { box.style.display = chk.checked ? 'block' : 'none'; }
			}
			document.addEventListener( 'change', function ( e ) {
				if ( e.target && e.target.id === 'fcfdi_requiere_factura' ) { sync(); }
			} );
			sync();
		} )();
		</script>
		<?php
	}

	/**
	 * Valida los campos cuando el cliente solicita factura.
	 */
	public static function validar() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce valida el nonce del checkout.
		if ( empty( $_POST['fcfdi_requiere_factura'] ) ) {
			return;
		}

		$rfc     = isset( $_POST['fcfdi_rfc'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['fcfdi_rfc'] ) ) ) : '';
		$razon   = isset( $_POST['fcfdi_razon_social'] ) ? sanitize_text_field( wp_unslash( $_POST['fcfdi_razon_social'] ) ) : '';
		$cp      = isset( $_POST['fcfdi_cp'] ) ? sanitize_text_field( wp_unslash( $_POST['fcfdi_cp'] ) ) : '';
		$regimen = isset( $_POST['fcfdi_regimen_fiscal'] ) ? sanitize_text_field( wp_unslash( $_POST['fcfdi_regimen_fiscal'] ) ) : '';
		$uso     = isset( $_POST['fcfdi_uso_cfdi'] ) ? sanitize_text_field( wp_unslash( $_POST['fcfdi_uso_cfdi'] ) ) : '';
		// phpcs:enable

		if ( ! preg_match( '/^([A-ZÑ&]{3,4})\d{6}([A-Z\d]{3})$/', $rfc ) ) {
			wc_add_notice( __( 'El RFC no tiene un formato válido.', 'facturacionmozart-woocommerce-plugin' ), 'error' );
		}
		if ( '' === $razon ) {
			wc_add_notice( __( 'Captura la razón social para facturar.', 'facturacionmozart-woocommerce-plugin' ), 'error' );
		}
		if ( ! preg_match( '/^\d{5}$/', $cp ) ) {
			wc_add_notice( __( 'El código postal fiscal debe tener 5 dígitos.', 'facturacionmozart-woocommerce-plugin' ), 'error' );
		}
		if ( '' === $regimen ) {
			wc_add_notice( __( 'Selecciona el régimen fiscal.', 'facturacionmozart-woocommerce-plugin' ), 'error' );
		}
		if ( '' === $uso ) {
			wc_add_notice( __( 'Selecciona el uso de CFDI.', 'facturacionmozart-woocommerce-plugin' ), 'error' );
		}
		if ( '' !== $uso && '' !== $regimen && ! self::combo_valido( $uso, $regimen ) ) {
			wc_add_notice( self::mensaje_error( 'USO_CFDI_INCOMPATIBLE' ), 'error' );
		}

		// Si el formato local pasó, valida contra el puente (pre-flight, dry-run). Esto
		// corre en woocommerce_checkout_process: ANTES de cobrar y sin vaciar el carrito.
		if ( 0 === wc_notice_count( 'error' ) ) {
			$pref = self::validar_receptor_remoto(
				array(
					'rfc'            => $rfc,
					'razon_social'   => $razon,
					'regimen_fiscal' => $regimen,
					'cp'             => $cp,
					'uso_cfdi'       => $uso,
				)
			);
			if ( ! $pref['ok'] ) {
				wc_add_notice( $pref['mensaje'], 'error' );
			}
		}
	}

	/**
	 * Guarda los datos fiscales en el pedido.
	 *
	 * @param int $order_id Id del pedido.
	 */
	public static function guardar( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce valida el nonce del checkout.
		$requiere = ! empty( $_POST['fcfdi_requiere_factura'] );
		$order->update_meta_data( '_fcfdi_requiere_factura', $requiere ? 'si' : 'no' );

		if ( $requiere ) {
			$order->update_meta_data( '_fcfdi_rfc', isset( $_POST['fcfdi_rfc'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['fcfdi_rfc'] ) ) ) : '' );
			$order->update_meta_data( '_fcfdi_razon_social', isset( $_POST['fcfdi_razon_social'] ) ? sanitize_text_field( wp_unslash( $_POST['fcfdi_razon_social'] ) ) : '' );
			$order->update_meta_data( '_fcfdi_cp', isset( $_POST['fcfdi_cp'] ) ? sanitize_text_field( wp_unslash( $_POST['fcfdi_cp'] ) ) : '' );
			$order->update_meta_data( '_fcfdi_regimen_fiscal', isset( $_POST['fcfdi_regimen_fiscal'] ) ? sanitize_text_field( wp_unslash( $_POST['fcfdi_regimen_fiscal'] ) ) : '' );
			$order->update_meta_data( '_fcfdi_uso_cfdi', isset( $_POST['fcfdi_uso_cfdi'] ) ? sanitize_text_field( wp_unslash( $_POST['fcfdi_uso_cfdi'] ) ) : '' );
		}
		// phpcs:enable

		$order->save();
	}
}
