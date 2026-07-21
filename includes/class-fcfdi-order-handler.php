<?php
/**
 * Engancha el ciclo del pedido y procesa la facturación de forma asíncrona
 * mediante Action Scheduler (incluido en WooCommerce).
 *
 * @package FacturacionCFDI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCFDI_Order_Handler {

	const HOOK_ENVIAR    = 'fcfdi_enviar_factura';
	const HOOK_CONSULTAR = 'fcfdi_consultar_estatus';
	const MAX_POLLS      = 12;
	const INTERVALO_POLL = 20;
	const MAX_ENVIOS     = 5;
	const INTERVALO_ENVIO = 60;

	public static function init() {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_completed' ) );
		add_action( self::HOOK_ENVIAR, array( __CLASS__, 'enviar' ) );
		add_action( self::HOOK_CONSULTAR, array( __CLASS__, 'consultar' ) );
	}

	/**
	 * Al completarse el pedido, encola el envío al puente (si procede y no se hizo ya).
	 *
	 * @param int $order_id Id del pedido.
	 */
	public static function on_completed( $order_id ) {
		if ( ! FCFDI_Settings::esta_configurado() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		// Ya tiene factura o ya está en proceso.
		if ( $order->get_meta( '_fcfdi_factura_id' ) ) {
			return;
		}
		// Si no se factura siempre y el cliente no pidió factura, no hacemos nada.
		$siempre  = 'si' === FCFDI_Settings::get( 'facturar_siempre', 'si' );
		$requiere = self::requiere_factura( $order );
		if ( ! $siempre && ! $requiere ) {
			return;
		}

		$order->update_meta_data( '_fcfdi_estatus', 'encolada' );

		// El cliente pidió factura: el pedido no avanza como completado hasta que el
		// puente confirme el timbrado. Se retiene en "en espera" para que el flujo de
		// cumplimiento (envío, acceso a descargas, etc.) no trate el pedido como cerrado
		// con una factura pendiente o fallida.
		if ( $requiere && $order->has_status( 'completed' ) ) {
			$order->update_meta_data( '_fcfdi_retener_completado', 'si' );
			$order->save();
			$order->update_status( 'on-hold', __( 'Retenido: facturación CFDI en proceso.', 'facturacion-cfdi' ) );
		} else {
			$order->save();
		}

		as_enqueue_async_action( self::HOOK_ENVIAR, array( 'order_id' => $order_id ), 'facturacion-cfdi' );
	}

	/**
	 * Si el pedido se retuvo esperando el CFDI, lo regresa a completado.
	 *
	 * @param WC_Order $order Pedido.
	 */
	private static function liberar_si_retenido( $order ) {
		if ( 'si' !== $order->get_meta( '_fcfdi_retener_completado' ) ) {
			return;
		}
		$order->update_meta_data( '_fcfdi_retener_completado', '' );
		$order->save();
		if ( $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'completed', __( 'CFDI timbrado: se libera el pedido.', 'facturacion-cfdi' ) );
		}
	}

	/**
	 * Construye el payload y lo envía al puente. Programa el polling de estatus.
	 *
	 * @param int $order_id Id del pedido.
	 */
	public static function enviar( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_fcfdi_factura_id' ) ) {
			return;
		}

		$payload = self::construir_payload( $order );
		$client  = new FCFDI_Api_Client();
		$res     = $client->crear_factura( $payload, (string) $order->get_id() );

		// Error de red o 5xx del puente: reintentar con backoff (reprogramando el envío).
		if ( is_wp_error( $res ) || (int) $res['code'] >= 500 ) {
			$motivo = is_wp_error( $res ) ? $res->get_error_message() : ( 'HTTP ' . $res['code'] );
			self::reintentar_envio( $order, $motivo );
			return;
		}

		$code = (int) $res['code'];
		$body = $res['body'];

		if ( 202 === $code || 200 === $code ) {
			$factura_id = isset( $body['factura_id'] ) ? $body['factura_id'] : '';
			$order->update_meta_data( '_fcfdi_factura_id', $factura_id );
			$order->update_meta_data( '_fcfdi_estatus', isset( $body['estatus'] ) ? $body['estatus'] : 'en_proceso' );
			$order->update_meta_data( '_fcfdi_poll_intentos', 0 );
			$order->save();
			$order->add_order_note( __( 'CFDI encolado en el puente de facturación.', 'facturacion-cfdi' ) );

			as_schedule_single_action(
				time() + self::INTERVALO_POLL,
				self::HOOK_CONSULTAR,
				array( 'order_id' => $order_id ),
				'facturacion-cfdi'
			);
			return;
		}

		// Error de negocio (4xx): no reintentar, registrar para revisión.
		self::registrar_error( $order, $body, $code );
	}

	/**
	 * Reprograma el envío con backoff o marca error si se agotaron los intentos.
	 *
	 * @param WC_Order $order  Pedido.
	 * @param string   $motivo Motivo del reintento.
	 */
	private static function reintentar_envio( $order, $motivo ) {
		$intentos = (int) $order->get_meta( '_fcfdi_envio_intentos' ) + 1;
		$order->update_meta_data( '_fcfdi_envio_intentos', $intentos );
		$order->update_meta_data( '_fcfdi_estatus', 'reintentando' );
		$order->save();

		if ( $intentos >= self::MAX_ENVIOS ) {
			$order->update_meta_data( '_fcfdi_estatus', 'error' );
			$order->update_meta_data( '_fcfdi_error', $motivo );
			$order->save();
			$order->add_order_note( '⚠️ ' . sprintf( __( 'No se pudo enviar al puente tras varios intentos: %s', 'facturacion-cfdi' ), $motivo ) );
			self::escalar_si_retenido( $order, $motivo );
			return;
		}

		as_schedule_single_action(
			time() + self::INTERVALO_ENVIO,
			self::HOOK_ENVIAR,
			array( 'order_id' => $order->get_id() ),
			'facturacion-cfdi'
		);
	}

	/**
	 * Consulta el estatus y guarda el resultado; reprograma si sigue en proceso.
	 *
	 * @param int $order_id Id del pedido.
	 */
	public static function consultar( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$factura_id = $order->get_meta( '_fcfdi_factura_id' );
		if ( ! $factura_id ) {
			return;
		}

		$client = new FCFDI_Api_Client();
		$res    = $client->consultar_estatus( $factura_id );

		if ( is_wp_error( $res ) ) {
			self::reprogramar_o_fallar( $order, __( 'Sin respuesta del puente al consultar estatus.', 'facturacion-cfdi' ) );
			return;
		}

		$body    = $res['body'];
		$estatus = isset( $body['estatus'] ) ? $body['estatus'] : '';

		if ( 'timbrada' === $estatus ) {
			$order->update_meta_data( '_fcfdi_estatus', 'timbrada' );
			$order->update_meta_data( '_fcfdi_uuid', isset( $body['uuid'] ) ? $body['uuid'] : '' );
			$order->update_meta_data( '_fcfdi_xml_url', isset( $body['xml_url'] ) ? $body['xml_url'] : '' );
			$order->update_meta_data( '_fcfdi_pdf_url', isset( $body['pdf_url'] ) ? $body['pdf_url'] : '' );
			$order->save();
			$order->add_order_note(
				sprintf(
					/* translators: %s: UUID del CFDI */
					__( 'CFDI timbrado. UUID: %s', 'facturacion-cfdi' ),
					isset( $body['uuid'] ) ? $body['uuid'] : ''
				)
			);
			self::liberar_si_retenido( $order );
			return;
		}

		if ( 'error' === $estatus ) {
			self::registrar_error( $order, $body, (int) $res['code'] );
			return;
		}

		// Sigue en proceso: reprogramar el polling.
		self::reprogramar_o_fallar( $order, __( 'El timbrado sigue en proceso tras varios intentos.', 'facturacion-cfdi' ) );
	}

	/**
	 * Reprograma el polling o marca fallo si se agotaron los intentos.
	 *
	 * @param WC_Order $order   Pedido.
	 * @param string   $mensaje Mensaje de fallo.
	 */
	private static function reprogramar_o_fallar( $order, $mensaje ) {
		$intentos = (int) $order->get_meta( '_fcfdi_poll_intentos' ) + 1;
		$order->update_meta_data( '_fcfdi_poll_intentos', $intentos );
		$order->save();

		if ( $intentos >= self::MAX_POLLS ) {
			$order->update_meta_data( '_fcfdi_estatus', 'error' );
			$order->update_meta_data( '_fcfdi_error', $mensaje );
			$order->save();
			$order->add_order_note( '⚠️ ' . $mensaje );
			self::escalar_si_retenido( $order, $mensaje );
			return;
		}

		as_schedule_single_action(
			time() + self::INTERVALO_POLL,
			self::HOOK_CONSULTAR,
			array( 'order_id' => $order->get_id() ),
			'facturacion-cfdi'
		);
	}

	/**
	 * Registra un error de negocio en el pedido.
	 *
	 * @param WC_Order $order Pedido.
	 * @param array    $body  Cuerpo de respuesta.
	 * @param int      $code  HTTP code.
	 */
	private static function registrar_error( $order, $body, $code ) {
		$codigo  = isset( $body['codigo'] ) ? $body['codigo'] : 'HTTP_' . $code;
		$mensaje = isset( $body['mensaje'] ) ? $body['mensaje'] : __( 'Error desconocido del puente.', 'facturacion-cfdi' );
		$order->update_meta_data( '_fcfdi_estatus', 'error' );
		$order->update_meta_data( '_fcfdi_error', $codigo . ': ' . $mensaje );
		$order->save();
		$order->add_order_note( '⚠️ ' . sprintf( __( 'Error de facturación (%1$s): %2$s', 'facturacion-cfdi' ), $codigo, $mensaje ) );
		self::escalar_si_retenido( $order, $codigo . ': ' . $mensaje );
	}

	/**
	 * Si el pedido está retenido esperando CFDI y el timbrado ya no puede completarse
	 * solo (dato de negocio incorrecto o intentos agotados), permanece en "en espera"
	 * (nunca se libera solo) y se avisa al administrador para que lo resuelva a mano.
	 *
	 * @param WC_Order $order  Pedido.
	 * @param string   $motivo Motivo del fallo.
	 */
	private static function escalar_si_retenido( $order, $motivo ) {
		if ( 'si' !== $order->get_meta( '_fcfdi_retener_completado' ) ) {
			return;
		}
		$order->add_order_note(
			'🚨 ' . sprintf(
				/* translators: %s: motivo del fallo */
				__( 'Pedido retenido en espera de CFDI. Requiere atención manual: %s', 'facturacion-cfdi' ),
				$motivo
			)
		);
		/**
		 * Permite conectar una alerta (correo, Slack, etc.) cuando un pedido queda
		 * retenido sin poder facturarse automáticamente.
		 *
		 * @param WC_Order $order  Pedido.
		 * @param string   $motivo Motivo del fallo.
		 */
		do_action( 'fcfdi_facturacion_retenida', $order, $motivo );
		wp_mail(
			get_option( 'admin_email' ),
			sprintf( __( '[%1$s] Pedido #%2$s retenido: falló la facturación CFDI', 'facturacion-cfdi' ), get_bloginfo( 'name' ), $order->get_order_number() ),
			sprintf( __( "El pedido #%1\$s pidió factura pero el timbrado no se completó y quedó retenido (en espera) en vez de completado.\n\nMotivo: %2\$s\n\nRevísalo en el admin de WooCommerce.", 'facturacion-cfdi' ), $order->get_order_number(), $motivo )
		);
	}

	/**
	 * Determina si el cliente solicitó factura (checkout clásico o de bloques).
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	private static function requiere_factura( $order ) {
		if ( 'si' === $order->get_meta( '_fcfdi_requiere_factura' ) ) {
			return true;
		}
		if ( class_exists( 'FCFDI_Blocks' ) ) {
			$v = FCFDI_Blocks::leer( $order, 'requiere-factura' );
			return ( '1' === $v || 'true' === $v || 'si' === $v );
		}
		return false;
	}

	/**
	 * Lee un dato fiscal: meta clásica y, si está vacía, el campo de bloque.
	 *
	 * @param WC_Order $order      Pedido.
	 * @param string   $clasico    Meta key del checkout clásico.
	 * @param string   $block_slug Slug del campo de bloque.
	 * @return string
	 */
	private static function dato( $order, $clasico, $block_slug ) {
		$val = $order->get_meta( $clasico );
		if ( '' !== $val && null !== $val ) {
			return (string) $val;
		}
		return class_exists( 'FCFDI_Blocks' ) ? FCFDI_Blocks::leer( $order, $block_slug ) : '';
	}

	/**
	 * Construye el payload del contrato a partir del pedido.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array
	 */
	private static function construir_payload( $order ) {
		$requiere = self::requiere_factura( $order );

		if ( $requiere ) {
			$receptor = array(
				'tipo'           => 'rfc',
				'rfc'            => self::dato( $order, '_fcfdi_rfc', 'rfc' ),
				'razon_social'   => self::dato( $order, '_fcfdi_razon_social', 'razon-social' ),
				'regimen_fiscal' => self::dato( $order, '_fcfdi_regimen_fiscal', 'regimen-fiscal' ),
				'cp'             => self::dato( $order, '_fcfdi_cp', 'cp' ),
				'uso_cfdi'       => self::dato( $order, '_fcfdi_uso_cfdi', 'uso-cfdi' ),
				'email'          => $order->get_billing_email(),
			);
		} else {
			$receptor = array(
				'tipo'  => 'publico_general',
				'email' => $order->get_billing_email(),
			);
		}

		$conceptos = array();
		$subtotal  = 0.0;
		$descuento = 0.0;
		$impuestos = 0.0;

		foreach ( $order->get_items() as $item ) {
			$qty       = (float) $item->get_quantity();
			$linea_sub = (float) $item->get_subtotal();      // ex IVA, antes de descuento.
			$linea_tot = (float) $item->get_total();         // ex IVA, después de descuento.
			$linea_tax = (float) $item->get_total_tax();
			$desc_item = round( $linea_sub - $linea_tot, 2 );
			$base      = $linea_tot;
			$tasa      = $base > 0 ? round( $linea_tax / $base, 6 ) : 0.0;

			$product = $item->get_product();
			$clave   = $product ? $product->get_meta( '_fcfdi_clave_prod_serv' ) : '';
			$unidad  = $product ? $product->get_meta( '_fcfdi_clave_unidad' ) : '';

			$concepto = array(
				'sku'             => $product ? $product->get_sku() : '',
				'descripcion'     => $item->get_name(),
				'cantidad'        => $qty,
				'valor_unitario'  => $qty > 0 ? round( $linea_sub / $qty, 6 ) : round( $linea_sub, 2 ),
				'importe'         => round( $linea_sub, 2 ),
				'descuento'       => $desc_item,
				'objeto_impuesto' => $linea_tax > 0 ? '02' : '01',
			);
			if ( $clave ) {
				$concepto['clave_prod_serv'] = $clave;
			}
			if ( $unidad ) {
				$concepto['clave_unidad'] = $unidad;
			}
			if ( $linea_tax > 0 ) {
				$concepto['impuestos'] = array(
					array(
						'tipo'    => 'IVA',
						'tasa'    => $tasa,
						'importe' => round( $linea_tax, 2 ),
					),
				);
			}

			$conceptos[] = $concepto;
			$subtotal   += round( $linea_sub, 2 );
			$descuento  += $desc_item;
			$impuestos  += round( $linea_tax, 2 );
		}

		// Envío como concepto (si el pedido tiene costo de envío).
		$envio     = (float) $order->get_shipping_total();
		$envio_tax = (float) $order->get_shipping_tax();
		if ( $envio > 0 ) {
			$tasa_envio = $envio > 0 ? round( $envio_tax / $envio, 6 ) : 0.0;
			$concepto_envio = array(
				'sku'             => 'ENVIO',
				'descripcion'     => __( 'Servicio de envío', 'facturacion-cfdi' ),
				'cantidad'        => 1,
				'valor_unitario'  => round( $envio, 2 ),
				'importe'         => round( $envio, 2 ),
				'descuento'       => 0,
				'objeto_impuesto' => $envio_tax > 0 ? '02' : '01',
				'clave_prod_serv' => apply_filters( 'fcfdi_clave_prod_serv_envio', '78102200', $order ),
				'clave_unidad'    => apply_filters( 'fcfdi_clave_unidad_envio', 'E48', $order ),
			);
			if ( $envio_tax > 0 ) {
				$concepto_envio['impuestos'] = array(
					array(
						'tipo'    => 'IVA',
						'tasa'    => $tasa_envio,
						'importe' => round( $envio_tax, 2 ),
					),
				);
			}
			$conceptos[] = $concepto_envio;
			$subtotal   += round( $envio, 2 );
			$impuestos  += round( $envio_tax, 2 );
		}

		$subtotal  = round( $subtotal, 2 );
		$descuento = round( $descuento, 2 );
		$impuestos = round( $impuestos, 2 );
		$total     = round( $subtotal - $descuento + $impuestos, 2 );

		$payload = array(
			'order_id'         => (string) $order->get_id(),
			'fecha_pedido'     => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : gmdate( 'c' ),
			'requiere_factura' => $requiere,
			'callback_url'     => class_exists( 'FCFDI_Webhook' ) ? FCFDI_Webhook::url() : '',
			'receptor'         => $receptor,
			'conceptos'        => $conceptos,
			'totales'          => array(
				'subtotal'              => $subtotal,
				'descuento'             => $descuento,
				'impuestos_trasladados' => $impuestos,
				'total'                 => $total,
				'moneda'                => $order->get_currency(),
			),
			'pago'             => array(
				'forma_pago'  => apply_filters( 'fcfdi_forma_pago', '99', $order ),
				'metodo_pago' => apply_filters( 'fcfdi_metodo_pago', 'PUE', $order ),
			),
		);

		/**
		 * Permite ajustar el payload antes de enviarlo (p.ej. envío como concepto, claves SAT).
		 *
		 * @param array    $payload Payload.
		 * @param WC_Order $order   Pedido.
		 */
		return apply_filters( 'fcfdi_payload', $payload, $order );
	}
}
