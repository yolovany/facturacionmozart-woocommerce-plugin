<?php
/**
 * Muestra el estatus del CFDI y links de descarga en la vista de pedido del cliente,
 * con descarga vía proxy autenticado (el token nunca llega al navegador).
 *
 * @package FacturacionCFDI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCFDI_My_Account {

	const ACTION = 'fcfdi_descargar';

	public static function init() {
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'mostrar' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'descargar' ) );
		// Adjunta el CFDI (XML+PDF) al correo de pedido completado / factura.
		add_filter( 'woocommerce_email_attachments', array( __CLASS__, 'adjuntar_email' ), 10, 3 );
	}

	/**
	 * Adjunta el XML y PDF del CFDI a los correos de WooCommerce, de modo que el cliente
	 * (incluido el invitado sin cuenta) reciba su factura.
	 *
	 * @param array  $attachments Rutas de archivos adjuntos.
	 * @param string $email_id    Id del correo.
	 * @param mixed  $order       Pedido (u otro objeto).
	 * @return array
	 */
	public static function adjuntar_email( $attachments, $email_id, $order ) {
		if ( ! ( $order instanceof WC_Order ) ) {
			return $attachments;
		}
		$correos = apply_filters(
			'fcfdi_emails_con_cfdi',
			array( 'customer_completed_order', 'customer_invoice' )
		);
		if ( ! in_array( $email_id, $correos, true ) ) {
			return $attachments;
		}
		if ( 'timbrada' !== $order->get_meta( '_fcfdi_estatus' ) ) {
			return $attachments;
		}
		$factura_id = $order->get_meta( '_fcfdi_factura_id' );
		if ( ! $factura_id ) {
			return $attachments;
		}

		$client = new FCFDI_Api_Client();
		$nombre = $order->get_meta( '_fcfdi_uuid' ) ? $order->get_meta( '_fcfdi_uuid' ) : $factura_id;

		foreach ( array( 'xml', 'pdf' ) as $formato ) {
			$res = $client->descargar( $factura_id, $formato );
			if ( is_wp_error( $res ) || (int) $res['code'] !== 200 || empty( $res['body'] ) ) {
				continue;
			}
			$ruta = trailingslashit( get_temp_dir() ) . 'cfdi-' . sanitize_file_name( $nombre ) . '.' . $formato;
			if ( false !== file_put_contents( $ruta, $res['body'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$attachments[] = $ruta;
			}
		}
		return $attachments;
	}

	/**
	 * Renderiza el bloque de factura en el detalle del pedido.
	 *
	 * @param WC_Order $order Pedido.
	 */
	public static function mostrar( $order ) {
		$estatus = $order->get_meta( '_fcfdi_estatus' );
		if ( ! $estatus ) {
			return;
		}

		echo '<section class="fcfdi-factura"><h2>' . esc_html__( 'Factura (CFDI)', 'facturacion-cfdi' ) . '</h2>';

		if ( 'timbrada' === $estatus ) {
			$uuid = $order->get_meta( '_fcfdi_uuid' );
			echo '<p>' . esc_html__( 'UUID:', 'facturacion-cfdi' ) . ' <code>' . esc_html( $uuid ) . '</code></p>';

			$xml = self::url_descarga( $order->get_id(), 'xml' );
			$pdf = self::url_descarga( $order->get_id(), 'pdf' );
			echo '<p>';
			echo '<a class="button" href="' . esc_url( $pdf ) . '">' . esc_html__( 'Descargar PDF', 'facturacion-cfdi' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $xml ) . '">' . esc_html__( 'Descargar XML', 'facturacion-cfdi' ) . '</a>';
			echo '</p>';
		} elseif ( 'cancelada' === $estatus ) {
			echo '<p>' . esc_html__( 'Esta factura fue cancelada ante el SAT.', 'facturacion-cfdi' ) . '</p>';
		} elseif ( 'error' === $estatus ) {
			echo '<p>' . esc_html__( 'Hubo un problema al generar la factura. Contacta a la tienda.', 'facturacion-cfdi' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Tu factura se está generando. Estará disponible en unos minutos.', 'facturacion-cfdi' ) . '</p>';
		}

		echo '</section>';
	}

	/**
	 * URL del proxy de descarga.
	 *
	 * @param int    $order_id Id del pedido.
	 * @param string $formato  'xml' o 'pdf'.
	 * @return string
	 */
	private static function url_descarga( $order_id, $formato ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION . '&order_id=' . $order_id . '&formato=' . $formato ),
			self::ACTION . '_' . $order_id
		);
	}

	/**
	 * Proxy de descarga: valida propiedad del pedido, descarga del puente y hace stream.
	 */
	public static function descargar() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$formato  = ( isset( $_GET['formato'] ) && 'pdf' === $_GET['formato'] ) ? 'pdf' : 'xml';

		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Debes iniciar sesión.', 'facturacion-cfdi' ), '', array( 'response' => 401 ) );
		}
		check_admin_referer( self::ACTION . '_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Pedido no encontrado.', 'facturacion-cfdi' ), '', array( 'response' => 404 ) );
		}

		// Solo el dueño del pedido (o quien pueda gestionar pedidos) descarga.
		$es_dueno = (int) $order->get_user_id() === get_current_user_id();
		if ( ! $es_dueno && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'No autorizado.', 'facturacion-cfdi' ), '', array( 'response' => 403 ) );
		}

		$factura_id = $order->get_meta( '_fcfdi_factura_id' );
		if ( ! $factura_id || 'timbrada' !== $order->get_meta( '_fcfdi_estatus' ) ) {
			wp_die( esc_html__( 'La factura aún no está disponible.', 'facturacion-cfdi' ), '', array( 'response' => 409 ) );
		}

		$client = new FCFDI_Api_Client();
		$res    = $client->descargar( $factura_id, $formato );

		if ( is_wp_error( $res ) || (int) $res['code'] !== 200 ) {
			wp_die( esc_html__( 'No fue posible obtener el archivo del puente.', 'facturacion-cfdi' ), '', array( 'response' => 502 ) );
		}

		$tipo     = ( 'pdf' === $formato ) ? 'application/pdf' : 'application/xml';
		$nombre   = ( $order->get_meta( '_fcfdi_uuid' ) ? $order->get_meta( '_fcfdi_uuid' ) : $factura_id ) . '.' . $formato;

		nocache_headers();
		header( 'Content-Type: ' . $tipo );
		header( 'Content-Disposition: attachment; filename="' . $nombre . '"' );
		header( 'Content-Length: ' . strlen( $res['body'] ) );
		echo $res['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binario/archivo CFDI.
		exit;
	}
}
