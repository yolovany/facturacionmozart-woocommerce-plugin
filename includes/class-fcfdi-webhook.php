<?php
/**
 * Receptor de webhooks del puente: el puente notifica el resultado del timbrado
 * (alternativa al polling). Autenticado con el token del comercio (header X-FCFDI-Token).
 *
 * NOTA: requiere validación en WordPress real (endpoint REST).
 *
 * @package FacturacionCFDI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCFDI_Webhook {

	const REST_NS    = 'facturacion-cfdi/v1';
	const REST_ROUTE = '/callback';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'registrar' ) );
	}

	/**
	 * URL pública del receptor (para enviar como callback_url al puente).
	 *
	 * @return string
	 */
	public static function url() {
		return rest_url( self::REST_NS . self::REST_ROUTE );
	}

	public static function registrar() {
		register_rest_route(
			self::REST_NS,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'recibir' ),
				'permission_callback' => array( __CLASS__, 'autorizar' ),
			)
		);
	}

	/**
	 * Autoriza la petición comparando el token del header con el configurado.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return bool
	 */
	public static function autorizar( $request ) {
		$token = $request->get_header( 'x-fcfdi-token' );
		$config = FCFDI_Settings::get_api_token();
		return ! empty( $config ) && hash_equals( (string) $config, (string) $token );
	}

	/**
	 * Procesa la notificación: actualiza el pedido con el resultado.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response
	 */
	public static function recibir( $request ) {
		$order_id = absint( $request->get_param( 'order_id' ) );
		$estatus  = sanitize_text_field( (string) $request->get_param( 'estatus' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'order_not_found' ), 404 );
		}

		// Idempotente: si ya está timbrada, no reprocesar.
		if ( 'timbrada' === $order->get_meta( '_fcfdi_estatus' ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'noop' => true ), 200 );
		}

		if ( 'timbrada' === $estatus ) {
			$order->update_meta_data( '_fcfdi_estatus', 'timbrada' );
			$order->update_meta_data( '_fcfdi_uuid', sanitize_text_field( (string) $request->get_param( 'uuid' ) ) );
			if ( ! $order->get_meta( '_fcfdi_factura_id' ) ) {
				$order->update_meta_data( '_fcfdi_factura_id', sanitize_text_field( (string) $request->get_param( 'factura_id' ) ) );
			}
			$order->save();
			$order->add_order_note(
				sprintf( __( 'CFDI timbrado (webhook). UUID: %s', 'facturacionmozart-woocommerce-plugin' ), $request->get_param( 'uuid' ) )
			);
		} elseif ( 'error' === $estatus ) {
			$codigo  = sanitize_text_field( (string) $request->get_param( 'codigo' ) );
			$mensaje = sanitize_text_field( (string) $request->get_param( 'mensaje' ) );
			$order->update_meta_data( '_fcfdi_estatus', 'error' );
			$order->update_meta_data( '_fcfdi_error', $codigo . ': ' . $mensaje );
			$order->save();
			$order->add_order_note( '⚠️ ' . sprintf( __( 'Error de facturación (webhook) %1$s: %2$s', 'facturacionmozart-woocommerce-plugin' ), $codigo, $mensaje ) );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
