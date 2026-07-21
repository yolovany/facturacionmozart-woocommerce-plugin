<?php
/**
 * Portal del cliente (Mi Cuenta): pestaña "Mis Facturas", perfil fiscal guardado con
 * autorrelleno del checkout, y solicitud/corrección de factura después de comprar.
 *
 * @package FacturacionCFDI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCFDI_Cliente {

	const ENDPOINT = 'mis-facturas';
	const ACTION   = 'fcfdi_solicitar';

	/** Meta de usuario del perfil fiscal <-> meta de pedido (clásica) equivalente. */
	const PERFIL = array(
		'rfc'            => '_fcfdi_rfc',
		'razon_social'  => '_fcfdi_razon_social',
		'cp'            => '_fcfdi_cp',
		'regimen_fiscal' => '_fcfdi_regimen_fiscal',
		'uso_cfdi'      => '_fcfdi_uso_cfdi',
	);

	public static function init() {
		// C1: pestaña "Mis Facturas".
		add_action( 'init', array( __CLASS__, 'registrar_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_mis_facturas' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );

		// C2: guardar perfil fiscal desde ambos checkouts y autorrellenar el clásico.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'guardar_perfil_de_pedido' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'guardar_perfil_de_pedido' ) );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'autorrelleno' ), 10, 2 );

		// C4: formulario "solicitar factura después de comprar" + su envío.
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'form_solicitar' ), 20 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'procesar_solicitud' ) );
	}

	/**
	 * Registra el endpoint de Mi Cuenta y hace flush de reglas una sola vez.
	 */
	public static function registrar_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
		if ( 'si' !== get_option( 'fcfdi_endpoint_flushed' ) ) {
			flush_rewrite_rules( false );
			update_option( 'fcfdi_endpoint_flushed', 'si' );
		}
	}

	/**
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Inserta "Mis Facturas" en el menú de Mi Cuenta, antes de "Cerrar sesión".
	 *
	 * @param array $items Ítems.
	 * @return array
	 */
	public static function menu( $items ) {
		$nuevo = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$nuevo[ self::ENDPOINT ] = __( 'Mis Facturas', 'facturacion-cfdi' );
			}
			$nuevo[ $key ] = $label;
		}
		if ( ! isset( $nuevo[ self::ENDPOINT ] ) ) {
			$nuevo[ self::ENDPOINT ] = __( 'Mis Facturas', 'facturacion-cfdi' );
		}
		return $nuevo;
	}

	/**
	 * Tabla de todas las facturas del cliente + su perfil fiscal editable.
	 * Se arma desde los propios pedidos de WooCommerce (sin depender de un endpoint
	 * de listado en el puente): cada pedido con CFDI aporta una fila.
	 */
	public static function render_mis_facturas() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		self::render_perfil_fiscal( $user_id );

		$pedidos = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => -1,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'meta_key'    => '_fcfdi_estatus', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
			)
		);

		$con_cfdi = array_filter(
			$pedidos,
			function ( $o ) {
				return in_array( $o->get_meta( '_fcfdi_estatus' ), array( 'timbrada', 'cancelada' ), true );
			}
		);

		echo '<h2>' . esc_html__( 'Mis Facturas', 'facturacion-cfdi' ) . '</h2>';

		if ( empty( $con_cfdi ) ) {
			echo '<p>' . esc_html__( 'Aún no tienes facturas timbradas. Cuando generemos un CFDI de tus pedidos aparecerá aquí.', 'facturacion-cfdi' ) . '</p>';
			return;
		}

		echo '<table class="woocommerce-orders-table shop_table shop_table_responsive">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Pedido', 'facturacion-cfdi' ) . '</th>';
		echo '<th>' . esc_html__( 'Fecha', 'facturacion-cfdi' ) . '</th>';
		echo '<th>' . esc_html__( 'UUID', 'facturacion-cfdi' ) . '</th>';
		echo '<th>' . esc_html__( 'Estatus', 'facturacion-cfdi' ) . '</th>';
		echo '<th>' . esc_html__( 'Descargar', 'facturacion-cfdi' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $con_cfdi as $o ) {
			$estatus   = $o->get_meta( '_fcfdi_estatus' );
			$uuid      = $o->get_meta( '_fcfdi_uuid' );
			$etiqueta  = 'cancelada' === $estatus ? __( 'Cancelada', 'facturacion-cfdi' ) : __( 'Timbrada', 'facturacion-cfdi' );
			$fecha     = $o->get_date_created() ? wc_format_datetime( $o->get_date_created() ) : '';
			$pdf       = FCFDI_My_Account::url_descarga_publica( $o->get_id(), 'pdf' );
			$xml       = FCFDI_My_Account::url_descarga_publica( $o->get_id(), 'xml' );
			echo '<tr>';
			echo '<td><a href="' . esc_url( $o->get_view_order_url() ) . '">#' . esc_html( $o->get_order_number() ) . '</a></td>';
			echo '<td>' . esc_html( $fecha ) . '</td>';
			echo '<td><code>' . esc_html( $uuid ) . '</code></td>';
			echo '<td>' . esc_html( $etiqueta ) . '</td>';
			echo '<td><a href="' . esc_url( $pdf ) . '">PDF</a> · <a href="' . esc_url( $xml ) . '">XML</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Sección de perfil fiscal guardado (editable). Autorrellena el checkout clásico.
	 *
	 * @param int $user_id Id de usuario.
	 */
	private static function render_perfil_fiscal( $user_id ) {
		// Guardado del propio formulario de perfil.
		if ( isset( $_POST['fcfdi_guardar_perfil'] ) && check_admin_referer( 'fcfdi_perfil' ) ) {
			self::guardar_perfil_desde_post( $user_id );
			wc_add_notice( __( 'Perfil fiscal guardado.', 'facturacion-cfdi' ), 'success' );
		}

		$val = function ( $campo ) use ( $user_id ) {
			return esc_attr( (string) get_user_meta( $user_id, 'fcfdi_perfil_' . $campo, true ) );
		};

		echo '<h2>' . esc_html__( 'Perfil fiscal', 'facturacion-cfdi' ) . '</h2>';
		echo '<p>' . esc_html__( 'Guarda tus datos fiscales para autocompletar el checkout la próxima vez.', 'facturacion-cfdi' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'fcfdi_perfil' );
		echo '<p><label>' . esc_html__( 'RFC', 'facturacion-cfdi' ) . '<br><input type="text" name="fcfdi_rfc" value="' . $val( 'rfc' ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Razón social', 'facturacion-cfdi' ) . '<br><input type="text" name="fcfdi_razon_social" value="' . $val( 'razon_social' ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Código postal fiscal', 'facturacion-cfdi' ) . '<br><input type="text" name="fcfdi_cp" value="' . $val( 'cp' ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Régimen fiscal', 'facturacion-cfdi' );
		self::select( 'fcfdi_regimen_fiscal', FCFDI_Checkout::regimenes(), $val( 'regimen_fiscal' ) );
		echo '</label></p>';
		echo '<p><label>' . esc_html__( 'Uso de CFDI', 'facturacion-cfdi' );
		self::select( 'fcfdi_uso_cfdi', FCFDI_Checkout::usos_cfdi(), $val( 'uso_cfdi' ) );
		echo '</label></p>';
		echo '<p><button type="submit" class="button" name="fcfdi_guardar_perfil" value="1">' . esc_html__( 'Guardar perfil fiscal', 'facturacion-cfdi' ) . '</button></p>';
		echo '</form><hr>';
	}

	/**
	 * Imprime un <select> simple.
	 *
	 * @param string $name    Nombre.
	 * @param array  $opciones Mapa value=>label.
	 * @param string $sel     Valor seleccionado.
	 */
	private static function select( $name, $opciones, $sel ) {
		echo '<br><select name="' . esc_attr( $name ) . '"><option value="">' . esc_html__( 'Selecciona…', 'facturacion-cfdi' ) . '</option>';
		foreach ( $opciones as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( (string) $value, $sel, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Guarda el perfil fiscal desde $_POST (formulario de Mi Cuenta o de solicitud).
	 *
	 * @param int $user_id Id de usuario.
	 */
	private static function guardar_perfil_desde_post( $user_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- el nonce lo verifica quien llama.
		$mapa = array(
			'rfc'            => isset( $_POST['fcfdi_rfc'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['fcfdi_rfc'] ) ) ) : '',
			'razon_social'  => isset( $_POST['fcfdi_razon_social'] ) ? sanitize_text_field( wp_unslash( $_POST['fcfdi_razon_social'] ) ) : '',
			'cp'            => isset( $_POST['fcfdi_cp'] ) ? sanitize_text_field( wp_unslash( $_POST['fcfdi_cp'] ) ) : '',
			'regimen_fiscal' => isset( $_POST['fcfdi_regimen_fiscal'] ) ? sanitize_text_field( wp_unslash( $_POST['fcfdi_regimen_fiscal'] ) ) : '',
			'uso_cfdi'      => isset( $_POST['fcfdi_uso_cfdi'] ) ? sanitize_text_field( wp_unslash( $_POST['fcfdi_uso_cfdi'] ) ) : '',
		);
		// phpcs:enable
		foreach ( $mapa as $campo => $valor ) {
			update_user_meta( $user_id, 'fcfdi_perfil_' . $campo, $valor );
		}
	}

	/**
	 * Copia los datos fiscales de un pedido facturable al perfil del usuario (para que la
	 * próxima compra se autocomplete). Se dispara al procesarse el pedido en cualquier
	 * checkout (clásico o de bloques).
	 *
	 * @param int $order_id Id del pedido.
	 */
	public static function guardar_perfil_de_pedido( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			return;
		}
		// Sólo si el pedido pidió factura (hay datos fiscales que valga la pena guardar).
		$requiere = 'si' === $order->get_meta( '_fcfdi_requiere_factura' )
			|| ( class_exists( 'FCFDI_Blocks' ) && in_array( FCFDI_Blocks::leer( $order, 'requiere-factura' ), array( '1', 'true', 'si' ), true ) );
		if ( ! $requiere ) {
			return;
		}
		foreach ( self::PERFIL as $campo => $meta_clasica ) {
			$valor = $order->get_meta( $meta_clasica );
			if ( '' === $valor && class_exists( 'FCFDI_Blocks' ) ) {
				$valor = FCFDI_Blocks::leer( $order, str_replace( '_', '-', $campo ) );
			}
			if ( '' !== $valor ) {
				update_user_meta( $user_id, 'fcfdi_perfil_' . $campo, $valor );
			}
		}
	}

	/**
	 * Autorrelleno de los campos fiscales del checkout clásico con el perfil guardado.
	 *
	 * @param mixed  $valor Valor actual.
	 * @param string $input Nombre del campo.
	 * @return mixed
	 */
	public static function autorrelleno( $valor, $input ) {
		if ( ! empty( $valor ) || 0 !== strpos( (string) $input, 'fcfdi_' ) ) {
			return $valor;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $valor;
		}
		$campo = substr( $input, strlen( 'fcfdi_' ) );
		if ( 'requiere_factura' === $campo || ! array_key_exists( $campo, self::PERFIL ) ) {
			return $valor;
		}
		$perfil = get_user_meta( $user_id, 'fcfdi_perfil_' . $campo, true );
		return '' !== $perfil ? $perfil : $valor;
	}

	/**
	 * Formulario para solicitar factura después de comprar (o corregir datos rechazados).
	 * Se muestra cuando el pedido no tiene un CFDI vigente y el cliente es su dueño.
	 *
	 * @param WC_Order $order Pedido.
	 */
	public static function form_solicitar( $order ) {
		if ( ! is_user_logged_in() || (int) $order->get_user_id() !== get_current_user_id() ) {
			return;
		}
		$estatus = $order->get_meta( '_fcfdi_estatus' );
		// Ya hay (o se está generando) un CFDI vigente: nada que solicitar.
		if ( in_array( $estatus, array( 'timbrada', 'cancelada', 'encolada', 'en_proceso', 'reintentando' ), true ) ) {
			return;
		}
		// Sólo tiene sentido facturar un pedido pagado.
		if ( ! $order->is_paid() ) {
			return;
		}

		$es_correccion = 'error' === $estatus || 'si' === $order->get_meta( '_fcfdi_correccion_solicitada' );
		$titulo = $es_correccion
			? __( 'Corregir datos de tu factura', 'facturacion-cfdi' )
			: __( 'Solicitar factura', 'facturacion-cfdi' );

		$user_id = get_current_user_id();
		$pref    = function ( $campo, $meta ) use ( $order, $user_id ) {
			$v = $order->get_meta( $meta );
			if ( '' === $v ) {
				$v = get_user_meta( $user_id, 'fcfdi_perfil_' . $campo, true );
			}
			return esc_attr( (string) $v );
		};

		echo '<section class="fcfdi-solicitar"><h2>' . esc_html( $titulo ) . '</h2>';
		if ( $es_correccion ) {
			echo '<p>' . esc_html__( 'Revisa y corrige tus datos fiscales para volver a intentar la factura.', 'facturacion-cfdi' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Captura tus datos fiscales para generar el CFDI de este pedido.', 'facturacion-cfdi' ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
		wp_nonce_field( self::ACTION . '_' . $order->get_id() );
		echo '<p><label>' . esc_html__( 'RFC', 'facturacion-cfdi' ) . '<br><input type="text" name="fcfdi_rfc" value="' . $pref( 'rfc', '_fcfdi_rfc' ) . '" required></label></p>';
		echo '<p><label>' . esc_html__( 'Razón social', 'facturacion-cfdi' ) . '<br><input type="text" name="fcfdi_razon_social" value="' . $pref( 'razon_social', '_fcfdi_razon_social' ) . '" required></label></p>';
		echo '<p><label>' . esc_html__( 'Código postal fiscal', 'facturacion-cfdi' ) . '<br><input type="text" name="fcfdi_cp" value="' . $pref( 'cp', '_fcfdi_cp' ) . '" required></label></p>';
		echo '<p><label>' . esc_html__( 'Régimen fiscal', 'facturacion-cfdi' );
		self::select( 'fcfdi_regimen_fiscal', FCFDI_Checkout::regimenes(), $pref( 'regimen_fiscal', '_fcfdi_regimen_fiscal' ) );
		echo '</label></p>';
		echo '<p><label>' . esc_html__( 'Uso de CFDI', 'facturacion-cfdi' );
		self::select( 'fcfdi_uso_cfdi', FCFDI_Checkout::usos_cfdi(), $pref( 'uso_cfdi', '_fcfdi_uso_cfdi' ) );
		echo '</label></p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Solicitar factura', 'facturacion-cfdi' ) . '</button></p>';
		echo '</form></section>';
	}

	/**
	 * Procesa la solicitud de factura post-compra: valida propiedad y datos, guarda el
	 * receptor en el pedido y re-encola el timbrado. El pre-flight del puente rechaza
	 * combos inválidos con mensaje claro, sin timbrar de más.
	 */
	public static function procesar_solicitud() {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Debes iniciar sesión.', 'facturacion-cfdi' ), '', array( 'response' => 401 ) );
		}
		check_admin_referer( self::ACTION . '_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order || (int) $order->get_user_id() !== get_current_user_id() ) {
			wp_die( esc_html__( 'No autorizado.', 'facturacion-cfdi' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verificado arriba.
		$rfc     = isset( $_POST['fcfdi_rfc'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['fcfdi_rfc'] ) ) ) : '';
		$razon   = isset( $_POST['fcfdi_razon_social'] ) ? sanitize_text_field( wp_unslash( $_POST['fcfdi_razon_social'] ) ) : '';
		$cp      = isset( $_POST['fcfdi_cp'] ) ? sanitize_text_field( wp_unslash( $_POST['fcfdi_cp'] ) ) : '';
		$regimen = isset( $_POST['fcfdi_regimen_fiscal'] ) ? sanitize_text_field( wp_unslash( $_POST['fcfdi_regimen_fiscal'] ) ) : '';
		$uso     = isset( $_POST['fcfdi_uso_cfdi'] ) ? sanitize_text_field( wp_unslash( $_POST['fcfdi_uso_cfdi'] ) ) : '';
		// phpcs:enable

		// Validación de formato local (misma que el checkout).
		$errores = array();
		if ( ! preg_match( '/^([A-ZÑ&]{3,4})\d{6}([A-Z\d]{3})$/', $rfc ) ) {
			$errores[] = __( 'El RFC no tiene un formato válido.', 'facturacion-cfdi' );
		}
		if ( '' === $razon ) {
			$errores[] = __( 'Captura la razón social.', 'facturacion-cfdi' );
		}
		if ( ! preg_match( '/^\d{5}$/', $cp ) ) {
			$errores[] = __( 'El código postal fiscal debe tener 5 dígitos.', 'facturacion-cfdi' );
		}
		if ( '' === $regimen ) {
			$errores[] = __( 'Selecciona el régimen fiscal.', 'facturacion-cfdi' );
		}
		if ( '' === $uso ) {
			$errores[] = __( 'Selecciona el uso de CFDI.', 'facturacion-cfdi' );
		}
		if ( '' !== $uso && '' !== $regimen && class_exists( 'FCFDI_Checkout' ) && ! FCFDI_Checkout::combo_valido( $uso, $regimen ) ) {
			$errores[] = FCFDI_Checkout::mensaje_error( 'USO_CFDI_INCOMPATIBLE' );
		}

		// Pre-flight contra el puente si el formato local pasó.
		if ( empty( $errores ) && class_exists( 'FCFDI_Checkout' ) ) {
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
				$errores[] = $pref['mensaje'];
			}
		}

		if ( ! empty( $errores ) ) {
			foreach ( $errores as $e ) {
				wc_add_notice( $e, 'error' );
			}
			wp_safe_redirect( $order->get_view_order_url() );
			exit;
		}

		// Guarda el receptor en el pedido y en el perfil del usuario.
		$order->update_meta_data( '_fcfdi_requiere_factura', 'si' );
		$order->update_meta_data( '_fcfdi_rfc', $rfc );
		$order->update_meta_data( '_fcfdi_razon_social', $razon );
		$order->update_meta_data( '_fcfdi_cp', $cp );
		$order->update_meta_data( '_fcfdi_regimen_fiscal', $regimen );
		$order->update_meta_data( '_fcfdi_uso_cfdi', $uso );
		$order->update_meta_data( '_fcfdi_correccion_solicitada', '' );
		// Limpia estado de facturación previo para permitir el reintento.
		foreach ( array( '_fcfdi_factura_id', '_fcfdi_estatus', '_fcfdi_error', '_fcfdi_envio_intentos', '_fcfdi_poll_intentos' ) as $meta ) {
			$order->delete_meta_data( $meta );
		}
		$order->save();

		self::guardar_perfil_desde_post( get_current_user_id() );

		if ( class_exists( 'FCFDI_Order_Handler' ) ) {
			FCFDI_Order_Handler::on_pagado( $order->get_id() );
		}

		$order->add_order_note( __( 'El cliente solicitó/corrigió su factura desde Mi Cuenta.', 'facturacion-cfdi' ) );
		wc_add_notice( __( 'Recibimos tu solicitud. Tu factura se generará en unos minutos.', 'facturacion-cfdi' ), 'success' );
		wp_safe_redirect( $order->get_view_order_url() );
		exit;
	}
}
