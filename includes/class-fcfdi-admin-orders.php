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
	}

	/**
	 * Códigos de error del puente que el CLIENTE puede corregir por sí mismo (dato fiscal
	 * mal capturado), a diferencia de fallos de infra o de negocio no accionables.
	 */
	const CODIGOS_CORREGIBLES = array(
		'RFC_FALTANTE',
		'RFC_FORMATO',
		'REGIMEN_FALTANTE',
		'REGIMEN_INVALIDO',
		'CP_FALTANTE',
		'CP_FORMATO',
		'USO_CFDI_FALTANTE',
		'USO_CFDI_INCOMPATIBLE',
		'CFDI40147',
		'CFDI40157',
		'SIN_RECEPTOR',
	);

	/**
	 * Extrae el código de error guardado (formato "CODIGO: mensaje").
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	private static function codigo_error( $order ) {
		$guardado = (string) $order->get_meta( '_fcfdi_error' );
		return ( false !== strpos( $guardado, ':' ) ) ? trim( strstr( $guardado, ':', true ) ) : $guardado;
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
				if ( 'error' === $estatus && in_array( self::codigo_error( $theorder ), self::CODIGOS_CORREGIBLES, true ) ) {
					$acciones['fcfdi_pedir_correccion'] = __( 'Pedir al cliente corregir datos fiscales', 'facturacionmozart-woocommerce-plugin' );
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
		foreach ( array( '_fcfdi_factura_id', '_fcfdi_estatus', '_fcfdi_error', '_fcfdi_envio_intentos', '_fcfdi_poll_intentos' ) as $meta ) {
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
		$codigo = self::codigo_error( $order );
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
			__( '[%1$s] Necesitamos corregir los datos de tu factura (pedido #%2$s)', 'facturacionmozart-woocommerce-plugin' ),
			$tienda,
			$order->get_order_number()
		);
		$cuerpo = sprintf(
			/* translators: 1: número de pedido, 2: motivo, 3: URL del pedido */
			__(
				"Hola,\n\nTu pago del pedido #%1\$s quedó registrado, pero no pudimos generar tu factura (CFDI) por lo siguiente:\n\n%2\$s\n\nPor favor corrige tus datos fiscales y vuelve a solicitar la factura desde tu pedido:\n%3\$s\n\nGracias.",
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
				? sprintf( __( '✉️ Se pidió al cliente (%s) corregir sus datos fiscales.', 'facturacionmozart-woocommerce-plugin' ), $para )
				: __( '⚠️ No se pudo enviar el correo de corrección al cliente.', 'facturacionmozart-woocommerce-plugin' )
		);
	}
}
