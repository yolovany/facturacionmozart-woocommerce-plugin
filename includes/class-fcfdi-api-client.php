<?php
/**
 * Cliente HTTP del puente REST de facturación.
 *
 * @package FacturacionCFDI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCFDI_Api_Client {

	/** @var string */
	private $base_url;

	/** @var string */
	private $token;

	public function __construct() {
		$this->base_url = FCFDI_Settings::get_api_url();
		$this->token    = FCFDI_Settings::get_api_token();
	}

	/**
	 * Encola una factura en el puente.
	 *
	 * @param array  $payload  Cuerpo del pedido (contrato FacturaRequest).
	 * @param string $order_id Identificador del pedido (clave de idempotencia).
	 * @return array|WP_Error  array( 'code' => int, 'body' => array )
	 */
	public function crear_factura( array $payload, $order_id ) {
		$res = wp_remote_post(
			$this->base_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization'   => 'Bearer ' . $this->token,
					'Content-Type'    => 'application/json',
					'Idempotency-Key' => (string) $order_id,
					'Accept'          => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		return $this->normalizar( $res );
	}

	/**
	 * Consulta el estatus de una factura.
	 *
	 * @param string $factura_id Identificador devuelto por el puente.
	 * @return array|WP_Error
	 */
	public function consultar_estatus( $factura_id ) {
		$res = wp_remote_get(
			$this->base_url . '/' . rawurlencode( $factura_id ),
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/json',
				),
			)
		);
		return $this->normalizar( $res );
	}

	/**
	 * Devuelve la URL de descarga (xml|pdf) para un factura_id.
	 *
	 * @param string $factura_id Id.
	 * @param string $formato    'xml' o 'pdf'.
	 * @return string
	 */
	public function url_descarga( $factura_id, $formato ) {
		return $this->base_url . '/' . rawurlencode( $factura_id ) . '/' . ( 'pdf' === $formato ? 'pdf' : 'xml' );
	}

	/**
	 * Descarga el XML o PDF desde el puente (autenticado con el token).
	 *
	 * @param string $factura_id Id.
	 * @param string $formato    'xml' o 'pdf'.
	 * @return array|WP_Error  array( 'code' => int, 'content_type' => string, 'body' => string )
	 */
	public function descargar( $factura_id, $formato ) {
		$res = wp_remote_get(
			$this->url_descarga( $factura_id, $formato ),
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'code'         => wp_remote_retrieve_response_code( $res ),
			'content_type' => wp_remote_retrieve_header( $res, 'content-type' ),
			'body'         => wp_remote_retrieve_body( $res ),
		);
	}

	/**
	 * Normaliza la respuesta de wp_remote_* a array( code, body ).
	 *
	 * @param array|WP_Error $res Respuesta.
	 * @return array|WP_Error
	 */
	private function normalizar( $res ) {
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return array(
			'code' => $code,
			'body' => is_array( $body ) ? $body : array(),
		);
	}
}
