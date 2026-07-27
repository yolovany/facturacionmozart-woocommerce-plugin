<?php
/**
 * Integración con el admin de pedidos: columna de estatus CFDI y acción de reintento.
 * Compatible con HPOS y con la tabla de posts legacy.
 *
 * @package FacturacionCFDI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCFDI_Admin_Orders {

	public static function init() {
		// Columna (HPOS).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'columna' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'celda_hpos' ), 10, 2 );
		// Columna (legacy posts).
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'columna' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'celda_legacy' ), 10, 2 );
		// Acciones en la página del pedido.
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'accion' ) );
		add_action( 'woocommerce_order_action_fcfdi_reintentar', array( __CLASS__, 'reintentar' ) );
		add_action( 'woocommerce_order_action_fcfdi_cancelar', array( __CLASS__, 'cancelar' ) );
		add_action( 'woocommerce_order_action_fcfdi_pedir_correccion', array( __CLASS__, 'pedir_correccion' ) );
		// Panel visible y acciones directas; no obliga a descubrir el selector genérico
		// de "Acciones del pedido" para saber cómo recuperar una factura.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'panel_recuperacion' ) );
		add_action( 'admin_post_fcfdi_admin_reintentar', array( __CLASS__, 'procesar_reintento_directo' ) );
		add_action( 'admin_post_fcfdi_admin_pedir_correccion', array( __CLASS__, 'procesar_correccion_directa' ) );
	}

	/**
	 * Agrega la columna "CFDI".
	 *
	 * @param array $cols Columnas.
	 * @return array
	 */
	public static function columna( $cols ) {
		$cols['fcfdi_estatus'] = __( 'CFDI', 'facturacionmozart-woocommerce-plugin' );
		return $cols;
	}

	public static function celda_hpos( $col, $order ) {
		if ( 'fcfdi_estatus' === $col && $order instanceof WC_Order ) {
			self::pintar( $order );
		}
	}

	public static function celda_legacy( $col, $post_id ) {
		if ( 'fcfdi_estatus' === $col ) {
			$order = wc_get_order( $post_id );
			if ( $order ) {
				self::pintar( $order );
			}
		}
	}

	/**
	 * Pinta el estatus con color.
	 *
	 * @param WC_Order $order Pedido.
	 */
	private static function pintar( $order ) {
		$estatus = $order->get_meta( '_fcfdi_estatus' );
		if ( ! $estatus ) {
			echo '—';
			return;
		}
		$colores = array(
			'timbrada'     => '#2e7d32',
			'error'        => '#c62828',
			'en_proceso'   => '#f9a825',
			'reintentando' => '#f9a825',
			'encolada'     => '#1565c0',
			'cancelada'    => '#9e9e9e',
		);
		$color = isset( $colores[ $estatus ] ) ? $colores[ $estatus ] : '#616161';
		printf( '<span style="color:%1$s;font-weight:600;">%2$s</span>', esc_attr( $color ), esc_html( $estatus ) );
		if ( 'timbrada' === $estatus && $order->get_meta( '_fcfdi_uuid' ) ) {
			printf( '<br><small>%s</small>', esc_html( $order->get_meta( '_fcfdi_uuid' ) ) );
		}
	}

	/**
	 * Agrega la acción "Reintentar facturación" si el pedido falló o no se ha timbrado.
	 *
	 * @param array $acciones Acciones.
	 * @return array
	 */
	public static function accion( $acciones ) {
		global $theorder;
		if ( $theorder instanceof WC_Order ) {
			$estatus = $theorder->get_meta( '_fcfdi_estatus' );
			if ( ! $estatus ) {
				return $acciones;
			}
			if ( 'timbrada' === $estatus ) {
				$acciones['fcfdi_cancelar'] = __( 'Cancelar CFDI ante el SAT', 'facturacionmozart-woocommerce-plugin' );
			} elseif ( 'cancelada' !== $estatus ) {
				$acciones['fcfdi_reintentar'] = __( 'Reintentar facturación CFDI', 'facturacionmozart-woocommerce-plugin' );
				// Sólo ofrece "pedir corrección" si el fallo es un dato que el cliente arregla.
				if ( 'error' === $estatus && FCFDI_Order_Handler::requiere_accion_cliente( $theorder ) ) {
					$acciones['fcfdi_pedir_correccion'] = __( 'Solicitar actualización de datos al cliente', 'facturacionmozart-woocommerce-plugin' );
				}
			}
		}
		return $acciones;
	}

	/**
	 * Cancela el CFDI del pedido ante el SAT (acción manual).
	 *
	 * @param WC_Order $order Pedido.
	 */
	public static function cancelar( $order ) {
		if ( class_exists( 'FCFDI_Cancel' ) ) {
			FCFDI_Cancel::cancelar_cfdi( $order );
		}
	}

	/**
	 * Reinicia el estado de facturación y re-encola el pedido.
	 *
	 * @param WC_Order $order Pedido.
	 */
	public static function reintentar( $order ) {
		if ( class_exists( 'FCFDI_Order_Handler' ) ) {
			FCFDI_Order_Handler::detener_programadas( $order->get_id() );
		}
		foreach ( array( '_fcfdi_factura_id', '_fcfdi_estatus', '_fcfdi_error', '_fcfdi_error_tipo', '_fcfdi_error_reintentable', '_fcfdi_envio_intentos', '_fcfdi_poll_intentos' ) as $meta ) {
			$order->delete_meta_data( $meta );
		}
		$order->save();
		$order->add_order_note( __( 'Reintento de facturación CFDI solicitado manualmente.', 'facturacionmozart-woocommerce-plugin' ) );

		if ( class_exists( 'FCFDI_Order_Handler' ) ) {
			FCFDI_Order_Handler::on_pagado( $order->get_id() );
		}
	}

	/**
	 * Envía al cliente un correo pidiéndole corregir sus datos fiscales, con el motivo
	 * legible del rechazo y un enlace al pedido donde puede reingresarlos y re-solicitar
	 * la factura. Marca el pedido para permitir la corrección desde "Mi cuenta".
	 *
	 * @param WC_Order $order Pedido.
	 */
	public static function pedir_correccion( $order ) {
		$codigo = FCFDI_Order_Handler::codigo_error( $order );
		$motivo = class_exists( 'FCFDI_Checkout' )
			? FCFDI_Checkout::mensaje_error( $codigo )
			: __( 'Revisa tus datos fiscales.', 'facturacionmozart-woocommerce-plugin' );

		// Habilita el formulario de corrección en la vista de pedido del cliente.
		$order->update_meta_data( '_fcfdi_correccion_solicitada', 'si' );
		$order->save();

		$para  = $order->get_billing_email();
		$url   = $order->get_view_order_url();
		$tienda = get_bloginfo( 'name' );
		$asunto = sprintf(
			/* translators: 1: nombre tienda, 2: número de pedido */
			__( '[%1$s] Necesitamos actualizar los datos de tu factura (pedido #%2$s)', 'facturacionmozart-woocommerce-plugin' ),
			$tienda,
			$order->get_order_number()
		);
		$cuerpo = sprintf(
			/* translators: 1: número de pedido, 2: motivo, 3: URL del pedido */
			__(
				"Hola,\n\nTu pago del pedido #%1\$s quedó registrado, pero no pudimos generar tu factura (CFDI) por lo siguiente:\n\n%2\$s\n\nPuedes actualizar los datos necesarios y reintentar la factura directamente desde tu pedido:\n%3\$s\n\nGracias.",
				'facturacionmozart-woocommerce-plugin'
			),
			$order->get_order_number(),
			$motivo,
			$url
		);

		$enviado = $para ? wp_mail( $para, $asunto, $cuerpo ) : false;
		$order->add_order_note(
			$enviado
				/* translators: %s: correo del cliente */
				? sprintf( __( '✉️ Se pidió al cliente (%s) actualizar los datos necesarios para su CFDI.', 'facturacionmozart-woocommerce-plugin' ), $para )
				: __( '⚠️ No se pudo enviar el correo de corrección al cliente.', 'facturacionmozart-woocommerce-plugin' )
		);
	}

	/**
	 * Muestra diagnóstico y próximos pasos directamente en la edición del pedido.
	 *
	 * @param WC_Order $order Pedido.
	 */
	public static function panel_recuperacion( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$estatus = (string) $order->get_meta( '_fcfdi_estatus' );
		if ( '' === $estatus ) {
			return;
		}

		echo '<div class="order_data_column" style="width:100%;clear:both;padding-top:12px;">';
		echo '<h3>' . esc_html__( 'Facturación CFDI', 'facturacionmozart-woocommerce-plugin' ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Estado:', 'facturacionmozart-woocommerce-plugin' ) . '</strong> ' . esc_html( $estatus ) . '</p>';

		if ( 'error' === $estatus ) {
			$detalle = (string) $order->get_meta( '_fcfdi_error' );
			echo '<div style="border-left:4px solid #d63638;background:#fff7f7;padding:10px 12px;margin:8px 0 12px;">';
			if ( FCFDI_Order_Handler::requiere_accion_cliente( $order ) ) {
				echo '<p><strong>' . esc_html__( 'Acción necesaria del cliente.', 'facturacionmozart-woocommerce-plugin' ) . '</strong> ';
				echo esc_html__( 'Los datos del CFDI deben actualizarse. Envíale la solicitud; recibirá un enlace directo a su pedido para corregirlos y reintentar.', 'facturacionmozart-woocommerce-plugin' ) . '</p>';
			} else {
				echo '<p><strong>' . esc_html__( 'Incidencia del servicio o de configuración.', 'facturacionmozart-woocommerce-plugin' ) . '</strong> ';
				echo esc_html__( 'No solicites al cliente modificar sus datos fiscales. Revisa la incidencia indicada y, después de corregirla, pulsa “Reintentar ahora”.', 'facturacionmozart-woocommerce-plugin' ) . '</p>';
			}
			if ( '' !== $detalle ) {
				echo '<p><strong>' . esc_html__( 'Detalle técnico:', 'facturacionmozart-woocommerce-plugin' ) . '</strong> <code>' . esc_html( $detalle ) . '</code></p>';
			}
			echo '</div>';

			self::formulario_accion_directa( $order, 'fcfdi_admin_reintentar', __( 'Reintentar ahora', 'facturacionmozart-woocommerce-plugin' ), 'button button-primary' );
			if ( FCFDI_Order_Handler::requiere_accion_cliente( $order ) ) {
				self::formulario_accion_directa( $order, 'fcfdi_admin_pedir_correccion', __( 'Solicitar actualización de datos al cliente', 'facturacionmozart-woocommerce-plugin' ), 'button' );
			}
		} elseif ( in_array( $estatus, array( 'encolada', 'en_proceso', 'reintentando' ), true ) ) {
			echo '<p>' . esc_html__( 'La factura sigue en proceso. El sistema continuará intentando automáticamente; el cliente no necesita modificar sus datos.', 'facturacionmozart-woocommerce-plugin' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Formulario POST pequeño para una acción directa del panel.
	 */
	private static function formulario_accion_directa( $order, $action, $label, $class ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 8px 0;">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
		wp_nonce_field( $action . '_' . $order->get_id() );
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * Valida una acción directa del administrador y devuelve el pedido.
	 *
	 * @param string $action Acción admin-post.
	 * @return WC_Order
	 */
	private static function pedido_desde_accion( $action ) {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'No tienes permisos para administrar pedidos.', 'facturacionmozart-woocommerce-plugin' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action . '_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Pedido no encontrado.', 'facturacionmozart-woocommerce-plugin' ), '', array( 'response' => 404 ) );
		}
		return $order;
	}

	public static function procesar_reintento_directo() {
		$order = self::pedido_desde_accion( 'fcfdi_admin_reintentar' );
		self::reintentar( $order );
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	public static function procesar_correccion_directa() {
		$order = self::pedido_desde_accion( 'fcfdi_admin_pedir_correccion' );
		if ( FCFDI_Order_Handler::requiere_accion_cliente( $order ) ) {
			self::pedir_correccion( $order );
		}
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}
}
