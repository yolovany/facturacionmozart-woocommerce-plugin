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
		if ( $order ) {
			self::cancelar_cfdi( $order );
		}
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
		if ( (float) $order->get_total_refunded() >= (float) $order->get_total() ) {
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
