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
		// Acción de reintento en la página del pedido.
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'accion' ) );
		add_action( 'woocommerce_order_action_fcfdi_reintentar', array( __CLASS__, 'reintentar' ) );
	}

	/**
	 * Agrega la columna "CFDI".
	 *
	 * @param array $cols Columnas.
	 * @return array
	 */
	public static function columna( $cols ) {
		$cols['fcfdi_estatus'] = __( 'CFDI', 'facturacion-cfdi' );
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
			if ( 'timbrada' !== $estatus ) {
				$acciones['fcfdi_reintentar'] = __( 'Reintentar facturación CFDI', 'facturacion-cfdi' );
			}
		}
		return $acciones;
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
		$order->add_order_note( __( 'Reintento de facturación CFDI solicitado manualmente.', 'facturacion-cfdi' ) );

		if ( class_exists( 'FCFDI_Order_Handler' ) ) {
			FCFDI_Order_Handler::on_completed( $order->get_id() );
		}
	}
}
