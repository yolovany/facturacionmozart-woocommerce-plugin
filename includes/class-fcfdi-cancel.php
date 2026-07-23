<?php
/**
 * Cancelación del CFDI cuando el pedido se cancela o reembolsa en WooCommerce.
 *
 * @package FacturacionCFDI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCFDI_Cancel {

	public static function init() {
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_cancelado' ) );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_reembolsado' ), 10, 2 );
	}

	public static function on_cancelado( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && ! self::abortar_pendiente( $order ) ) {
			self::cancelar_cfdi( $order );
		}
	}

	/**
	 * Maneja la cancelación cuando el CFDI aún NO está timbrado:
	 * - Envío todavía en el plugin (encolada/reintentando sin factura_id): se desprograman
	 *   las acciones pendientes y se limpia el estado — el CFDI nunca se emite.
	 * - Ya aceptado por el puente (hay factura_id y sigue en proceso): no se puede detener
	 *   desde aquí; se marca el pedido para cancelar el CFDI ante el SAT en cuanto se timbre
	 *   (lo resuelve FCFDI_Order_Handler::cancelar_pendiente_si_aplica vía poll o webhook).
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool true si el caso quedó manejado aquí (no hay CFDI timbrado que cancelar).
	 */
	private static function abortar_pendiente( $order ) {
		$estatus    = (string) $order->get_meta( '_fcfdi_estatus' );
		$factura_id = $order->get_meta( '_fcfdi_factura_id' );

		if ( ! in_array( $estatus, array( 'encolada', 'reintentando', 'en_proceso' ), true ) ) {
			return false;
		}

		if ( ! $factura_id ) {
			// El puente aún no conoce este pedido: basta con abortar localmente.
			if ( class_exists( 'FCFDI_Order_Handler' ) ) {
				FCFDI_Order_Handler::detener_programadas( $order->get_id() );
			}
			foreach ( array( '_fcfdi_estatus', '_fcfdi_error', '_fcfdi_envio_intentos', '_fcfdi_poll_intentos', '_fcfdi_retener_completado', '_fcfdi_estatus_previo' ) as $meta ) {
				$order->delete_meta_data( $meta );
			}
			$order->save();
			$order->add_order_note( __( 'Facturación CFDI abortada: el pedido se canceló antes de enviarse al puente.', 'facturacionmozart-woocommerce-plugin' ) );
			return true;
		}

		// El puente ya lo tiene en proceso: cancelar el CFDI en cuanto exista.
		$order->update_meta_data( '_fcfdi_cancelar_al_timbrar', 'si' );
		$order->save();
		$order->add_order_note( __( 'El timbrado sigue en proceso en el puente; el CFDI se cancelará ante el SAT automáticamente en cuanto se timbre.', 'facturacionmozart-woocommerce-plugin' ) );
		return true;
	}

	/**
	 * Solo cancela el CFDI ante un reembolso total (un reembolso parcial no invalida la factura).
	 *
	 * @param int $order_id  Pedido.
	 * @param int $refund_id Reembolso.
	 */
	public static function on_reembolsado( $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( (float) $order->get_total_refunded() >= (float) $order->get_total() && ! self::abortar_pendiente( $order ) ) {
			self::cancelar_cfdi( $order );
		}
	}

	/**
	 * Cancela el CFDI del pedido si está timbrado. Idempotente.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool true si quedó cancelado.
	 */
	public static function cancelar_cfdi( $order ) {
		if ( ! FCFDI_Settings::esta_configurado() ) {
			return false;
		}
		$factura_id = $order->get_meta( '_fcfdi_factura_id' );
		$estatus    = $order->get_meta( '_fcfdi_estatus' );

		if ( ! $factura_id || 'timbrada' !== $estatus ) {
			return false; // No hay CFDI timbrado que cancelar.
		}

		$motivo = apply_filters( 'fcfdi_motivo_cancelacion', '02', $order );
		$folio  = apply_filters( 'fcfdi_folio_sustitucion', '', $order );

		$client = new FCFDI_Api_Client();
		$res    = $client->cancelar( $factura_id, $motivo, $folio );

		if ( is_wp_error( $res ) ) {
			$order->add_order_note( '⚠️ ' . sprintf( __( 'No se pudo cancelar el CFDI: %s', 'facturacionmozart-woocommerce-plugin' ), $res->get_error_message() ) );
			return false;
		}

		$code = (int) $res['code'];
		$body = $res['body'];

		if ( 200 === $code && isset( $body['estatus'] ) && 'cancelada' === $body['estatus'] ) {
			$order->update_meta_data( '_fcfdi_estatus', 'cancelada' );
			$order->save();
			$order->add_order_note( __( 'CFDI cancelado ante el SAT.', 'facturacionmozart-woocommerce-plugin' ) );
			return true;
		}

		$codigo  = isset( $body['codigo'] ) ? $body['codigo'] : ( 'HTTP_' . $code );
		$mensaje = isset( $body['mensaje'] ) ? $body['mensaje'] : '';
		$order->add_order_note( '⚠️ ' . sprintf( __( 'Cancelación de CFDI rechazada (%1$s): %2$s', 'facturacionmozart-woocommerce-plugin' ), $codigo, $mensaje ) );
		return false;
	}
}
