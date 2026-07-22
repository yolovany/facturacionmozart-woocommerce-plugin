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
		/**
		 * La entrega del CFDI por correo la hace el puente (.NET) al timbrar, reusando
		 * el SMTP propio de la empresa (WorkerReenvio). Por eso el adjunto por WordPress
		 * viene DESACTIVADO por defecto: evita correos duplicados y no depende de que WP
		 * tenga SMTP configurado. Se puede reactivar con el filtro si se prefiere que
		 * WooCommerce sea quien entregue el CFDI.
		 *
		 * @param bool $activo Si WP debe adjuntar el CFDI a sus correos. Default false.
		 */
		if ( ! apply_filters( 'fcfdi_adjuntar_cfdi_email', false ) ) {
			return $attachments;
		}
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

		echo '<section class="fcfdi-factura woocommerce-order-fcfdi"><h2>' . esc_html__( 'Factura (CFDI)', 'facturacionmozart-woocommerce-plugin' ) . '</h2>';

		if ( 'timbrada' === $estatus ) {
			echo '<p>' . esc_html__( 'UUID:', 'facturacionmozart-woocommerce-plugin' ) . ' <code>' . esc_html( $order->get_meta( '_fcfdi_uuid' ) ) . '</code></p>';
			self::botones_descarga( $order->get_id() );
		} elseif ( 'cancelada' === $estatus ) {
			// El SAT obliga a conservar el CFDI cancelado: se mantiene la descarga.
			echo '<div class="woocommerce-info">' . esc_html__( 'Esta factura fue cancelada ante el SAT. Conserva el comprobante para tus registros.', 'facturacionmozart-woocommerce-plugin' ) . '</div>';
			if ( $order->get_meta( '_fcfdi_uuid' ) ) {
				echo '<p>' . esc_html__( 'UUID:', 'facturacionmozart-woocommerce-plugin' ) . ' <code>' . esc_html( $order->get_meta( '_fcfdi_uuid' ) ) . '</code></p>';
			}
			self::botones_descarga( $order->get_id() );
		} elseif ( 'error' === $estatus ) {
			// Muestra el motivo real (mapeado a lenguaje claro) en vez de un genérico.
			$guardado = (string) $order->get_meta( '_fcfdi_error' );
			$codigo   = ( false !== strpos( $guardado, ':' ) ) ? trim( strstr( $guardado, ':', true ) ) : '';
			$motivo   = class_exists( 'FCFDI_Checkout' ) ? FCFDI_Checkout::mensaje_error( $codigo ) : '';
			$aviso    = __( 'No pudimos generar tu factura automáticamente.', 'facturacionmozart-woocommerce-plugin' );
			if ( $motivo ) {
				$aviso .= ' ' . $motivo;
			}
			echo '<div class="woocommerce-error" role="alert">' . esc_html( $aviso ) . '</div>';
			echo '<p>' . esc_html__( 'Tu pago está registrado. Corrige tus datos abajo para volver a intentar tu factura.', 'facturacionmozart-woocommerce-plugin' ) . '</p>';
		} else {
			echo '<div class="woocommerce-info">' . esc_html__( 'Tu factura se está generando automáticamente y estará disponible aquí en cuanto se emita.', 'facturacionmozart-woocommerce-plugin' ) . '</div>';
		}

		echo '</section>';
	}

	/**
	 * Imprime los botones de descarga PDF/XML del CFDI, con estilo de WooCommerce.
	 *
	 * @param int $order_id Id del pedido.
	 */
	private static function botones_descarga( $order_id ) {
		$pdf = self::url_descarga( $order_id, 'pdf' );
		$xml = self::url_descarga( $order_id, 'xml' );
		echo '<p>';
		echo '<a class="woocommerce-button button" href="' . esc_url( $pdf ) . '">' . esc_html__( 'Descargar PDF', 'facturacionmozart-woocommerce-plugin' ) . '</a> ';
		echo '<a class="woocommerce-button button" href="' . esc_url( $xml ) . '">' . esc_html__( 'Descargar XML', 'facturacionmozart-woocommerce-plugin' ) . '</a>';
		echo '</p>';
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
	 * Igual que url_descarga() pero público, para que otras clases (p.ej. la pestaña
	 * "Mis Facturas") generen el enlace al mismo proxy autenticado.
	 *
	 * @param int    $order_id Id del pedido.
	 * @param string $formato  'xml' o 'pdf'.
	 * @return string
	 */
	public static function url_descarga_publica( $order_id, $formato ) {
		return self::url_descarga( $order_id, $formato );
	}

	/**
	 * Proxy de descarga: valida propiedad del pedido, descarga del puente y hace stream.
	 */
	public static function descargar() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$formato  = ( isset( $_GET['formato'] ) && 'pdf' === $_GET['formato'] ) ? 'pdf' : 'xml';

		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Debes iniciar sesión.', 'facturacionmozart-woocommerce-plugin' ), '', array( 'response' => 401 ) );
		}
		check_admin_referer( self::ACTION . '_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Pedido no encontrado.', 'facturacionmozart-woocommerce-plugin' ), '', array( 'response' => 404 ) );
		}

		// Solo el dueño del pedido (o quien pueda gestionar pedidos) descarga.
		$es_dueno = (int) $order->get_user_id() === get_current_user_id();
		if ( ! $es_dueno && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'No autorizado.', 'facturacionmozart-woocommerce-plugin' ), '', array( 'response' => 403 ) );
		}

		$factura_id = $order->get_meta( '_fcfdi_factura_id' );
		$estatus    = $order->get_meta( '_fcfdi_estatus' );
		// Timbrada o cancelada: en ambos casos el CFDI existe y debe poder descargarse
		// (el SAT obliga a conservar el cancelado).
		if ( ! $factura_id || ! in_array( $estatus, array( 'timbrada', 'cancelada' ), true ) ) {
			wp_die( esc_html__( 'La factura aún no está disponible.', 'facturacionmozart-woocommerce-plugin' ), '', array( 'response' => 409 ) );
		}

		$client = new FCFDI_Api_Client();
		$res    = $client->descargar( $factura_id, $formato );

		if ( is_wp_error( $res ) || (int) $res['code'] !== 200 ) {
			wp_die( esc_html__( 'No fue posible obtener el archivo del puente.', 'facturacionmozart-woocommerce-plugin' ), '', array( 'response' => 502 ) );
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
