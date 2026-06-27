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

	/**
	 * Catálogo mínimo de usos de CFDI más comunes en e-commerce.
	 *
	 * @return array
	 */
	public static function usos_cfdi() {
		return array(
			'G01' => 'G01 - Adquisición de mercancías',
			'G03' => 'G03 - Gastos en general',
			'I08' => 'I08 - Otra maquinaria y equipo',
			'S01' => 'S01 - Sin efectos fiscales',
			'P01' => 'P01 - Por definir',
		);
	}

	/**
	 * Catálogo de regímenes fiscales más usados por receptores.
	 *
	 * @return array
	 */
	public static function regimenes() {
		return array(
			'605' => '605 - Sueldos y salarios',
			'606' => '606 - Arrendamiento',
			'608' => '608 - Demás ingresos',
			'612' => '612 - Personas Físicas con Actividades Empresariales y Profesionales',
			'614' => '614 - Ingresos por intereses',
			'616' => '616 - Sin obligaciones fiscales',
			'621' => '621 - Incorporación Fiscal',
			'626' => '626 - Régimen Simplificado de Confianza (RESICO)',
			'601' => '601 - General de Ley Personas Morales',
			'603' => '603 - Personas Morales con Fines no Lucrativos',
		);
	}

	/**
	 * Renderiza los campos en el checkout.
	 *
	 * @param WC_Checkout $checkout Checkout.
	 */
	public static function campos( $checkout ) {
		echo '<div id="fcfdi-fiscal"><h3>' . esc_html__( 'Facturación (CFDI)', 'facturacion-cfdi' ) . '</h3>';

		woocommerce_form_field(
			'fcfdi_requiere_factura',
			array(
				'type'  => 'checkbox',
				'class' => array( 'fcfdi-requiere' ),
				'label' => __( 'Requiero factura', 'facturacion-cfdi' ),
			),
			$checkout->get_value( 'fcfdi_requiere_factura' )
		);

		echo '<div id="fcfdi-campos" style="display:none;">';

		woocommerce_form_field(
			'fcfdi_rfc',
			array(
				'type'        => 'text',
				'class'       => array( 'form-row-wide' ),
				'label'       => __( 'RFC', 'facturacion-cfdi' ),
				'placeholder' => 'XAXX010101000',
			),
			$checkout->get_value( 'fcfdi_rfc' )
		);

		woocommerce_form_field(
			'fcfdi_razon_social',
			array(
				'type'  => 'text',
				'class' => array( 'form-row-wide' ),
				'label' => __( 'Razón social (como en la Constancia de Situación Fiscal)', 'facturacion-cfdi' ),
			),
			$checkout->get_value( 'fcfdi_razon_social' )
		);

		woocommerce_form_field(
			'fcfdi_cp',
			array(
				'type'        => 'text',
				'class'       => array( 'form-row-first' ),
				'label'       => __( 'Código postal fiscal', 'facturacion-cfdi' ),
				'placeholder' => '00000',
			),
			$checkout->get_value( 'fcfdi_cp' )
		);

		woocommerce_form_field(
			'fcfdi_regimen_fiscal',
			array(
				'type'    => 'select',
				'class'   => array( 'form-row-last' ),
				'label'   => __( 'Régimen fiscal', 'facturacion-cfdi' ),
				'options' => array( '' => __( 'Selecciona…', 'facturacion-cfdi' ) ) + self::regimenes(),
			),
			$checkout->get_value( 'fcfdi_regimen_fiscal' )
		);

		woocommerce_form_field(
			'fcfdi_uso_cfdi',
			array(
				'type'    => 'select',
				'class'   => array( 'form-row-wide' ),
				'label'   => __( 'Uso de CFDI', 'facturacion-cfdi' ),
				'options' => array( '' => __( 'Selecciona…', 'facturacion-cfdi' ) ) + self::usos_cfdi(),
			),
			$checkout->get_value( 'fcfdi_uso_cfdi' )
		);

		echo '</div></div>';

		// Toggle de visibilidad de los campos según el checkbox.
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
			wc_add_notice( __( 'El RFC no tiene un formato válido.', 'facturacion-cfdi' ), 'error' );
		}
		if ( '' === $razon ) {
			wc_add_notice( __( 'Captura la razón social para facturar.', 'facturacion-cfdi' ), 'error' );
		}
		if ( ! preg_match( '/^\d{5}$/', $cp ) ) {
			wc_add_notice( __( 'El código postal fiscal debe tener 5 dígitos.', 'facturacion-cfdi' ), 'error' );
		}
		if ( '' === $regimen ) {
			wc_add_notice( __( 'Selecciona el régimen fiscal.', 'facturacion-cfdi' ), 'error' );
		}
		if ( '' === $uso ) {
			wc_add_notice( __( 'Selecciona el uso de CFDI.', 'facturacion-cfdi' ), 'error' );
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
