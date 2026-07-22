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

	/**
	 * Backoff (segundos por intento) para reenvío ante error de INFRA (red/5xx del puente,
	 * p.ej. PAC caído). Ventana larga a propósito: un outage no debe escalar a "atención
	 * manual" en minutos. Con este escalón el último reintento cae ~6 h después del pago.
	 * El nº de intentos = tamaño del arreglo. Ajustable con el filtro 'fcfdi_backoff_envio'.
	 */
	const BACKOFF_ENVIO = array( 60, 300, 900, 1800, 3600, 3600, 7200, 7200 );

	/**
	 * Backoff (segundos por intento) para el polling de estatus mientras el puente/PAC
	 * sigue procesando. También con ventana amplia (último poll ~1 h) para timbrados lentos.
	 * Ajustable con el filtro 'fcfdi_backoff_poll'.
	 */
	const BACKOFF_POLL = array( 20, 20, 30, 60, 120, 300, 600, 900, 1800, 3600 );

	public static function init() {
		// Se factura al confirmarse el pago, no al completar (enviar) el pedido: el
		// reencuadre PAGADO→FACTURADO→LIBERADO exige factura tras el pago. Los productos
		// físicos quedan en 'processing' al pagar; los virtuales/descargables saltan
		// directo a 'completed'. Se enganchan ambos y una guarda evita doble timbrado.
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_pagado' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_pagado' ) );
		add_action( self::HOOK_ENVIAR, array( __CLASS__, 'enviar' ) );
		add_action( self::HOOK_CONSULTAR, array( __CLASS__, 'consultar' ) );
	}

	/**
	 * Al confirmarse el pago (processing o completed), encola el envío al puente
	 * (si procede y no se hizo ya).
	 *
	 * @param int $order_id Id del pedido.
	 */
	public static function on_pagado( $order_id ) {
		if ( ! FCFDI_Settings::esta_configurado() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		// Ya tiene factura, o ya se encoló/procesó (guarda anti-duplicado: el pedido
		// puede pasar por processing y luego completed antes de que el envío async fije
		// el factura_id). Un estatus 'error' tampoco re-dispara solo: retry es manual.
		if ( $order->get_meta( '_fcfdi_factura_id' ) || '' !== (string) $order->get_meta( '_fcfdi_estatus' ) ) {
			return;
		}
		// Si no se factura siempre y el cliente no pidió factura, no hacemos nada.
		$siempre  = 'si' === FCFDI_Settings::get( 'facturar_siempre', 'si' );
		$requiere = self::requiere_factura( $order );
		if ( ! $siempre && ! $requiere ) {
			return;
		}

		$order->update_meta_data( '_fcfdi_estatus', 'encolada' );

		// El cliente pidió factura: el pedido no debe salir (envío, acceso a descargas)
		// hasta que el puente confirme el timbrado. Se retiene en "en espera" y se guarda
		// el estatus previo (processing/completed) para restaurarlo al liberar.
		if ( $requiere ) {
			// Si ya está retenido (p.ej. reintento manual desde el admin), no se pisa el
			// estatus previo con 'on-hold' ni se re-mueve el pedido: se conserva el estado
			// real al que debe volver tras timbrar.
			if ( ! $order->has_status( 'on-hold' ) ) {
				$order->update_meta_data( '_fcfdi_estatus_previo', $order->get_status() );
			}
			$order->update_meta_data( '_fcfdi_retener_completado', 'si' );
			$order->save();
			if ( ! $order->has_status( 'on-hold' ) ) {
				$order->update_status( 'on-hold', __( 'Retenido: facturación CFDI en proceso.', 'facturacionmozart-woocommerce-plugin' ) );
			}
		} else {
			$order->save();
		}

		as_enqueue_async_action( self::HOOK_ENVIAR, array( 'order_id' => $order_id ), 'facturacionmozart-woocommerce-plugin' );
	}

	/**
	 * Si el pedido se retuvo esperando el CFDI, lo regresa a su estatus previo
	 * (processing si se pagó sin completar, completed si ya venía completado).
	 *
	 * @param WC_Order $order Pedido.
	 */
	private static function liberar_si_retenido( $order ) {
		if ( 'si' !== $order->get_meta( '_fcfdi_retener_completado' ) ) {
			return;
		}
		$previo = (string) $order->get_meta( '_fcfdi_estatus_previo' );
		$previo = '' !== $previo ? $previo : 'completed';
		$order->update_meta_data( '_fcfdi_retener_completado', '' );
		$order->update_meta_data( '_fcfdi_estatus_previo', '' );
		$order->save();
		if ( $order->has_status( 'on-hold' ) ) {
			$order->update_status( $previo, __( 'CFDI timbrado: se libera el pedido.', 'facturacionmozart-woocommerce-plugin' ) );
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
			$order->add_order_note( __( 'CFDI encolado en el puente de facturación.', 'facturacionmozart-woocommerce-plugin' ) );

			as_schedule_single_action(
				time() + self::backoff( self::BACKOFF_POLL, 0, 'fcfdi_backoff_poll' ),
				self::HOOK_CONSULTAR,
				array( 'order_id' => $order_id ),
				'facturacionmozart-woocommerce-plugin'
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
		$order->update_meta_data( '_fcfdi_error', $motivo ); // Motivo visible mientras se reintenta.
		$order->save();

		$backoff = self::backoff_schedule( self::BACKOFF_ENVIO, 'fcfdi_backoff_envio' );
		if ( $intentos >= count( $backoff ) ) {
			$order->update_meta_data( '_fcfdi_estatus', 'error' );
			$order->update_meta_data( '_fcfdi_error', $motivo );
			$order->save();
			$order->add_order_note( '⚠️ ' . sprintf( __( 'No se pudo enviar al puente tras varios intentos: %s', 'facturacionmozart-woocommerce-plugin' ), $motivo ) );
			self::escalar_si_retenido( $order, $motivo );
			return;
		}

		as_schedule_single_action(
			time() + self::backoff( self::BACKOFF_ENVIO, $intentos, 'fcfdi_backoff_envio' ),
			self::HOOK_ENVIAR,
			array( 'order_id' => $order->get_id() ),
			'facturacionmozart-woocommerce-plugin'
		);
	}

	/**
	 * Devuelve el arreglo de backoff (segundos por intento) aplicando su filtro.
	 *
	 * @param array  $default Arreglo por defecto.
	 * @param string $filtro  Nombre del filtro.
	 * @return array<int>
	 */
	private static function backoff_schedule( $default, $filtro ) {
		$sched = apply_filters( $filtro, $default );
		return ( is_array( $sched ) && ! empty( $sched ) ) ? array_values( $sched ) : $default;
	}

	/**
	 * Segundos a esperar antes del intento nº $intento (0-based) según el backoff.
	 * Si $intento excede el arreglo, usa el último escalón (meseta).
	 *
	 * @param array  $default Backoff por defecto.
	 * @param int    $intento Índice del intento (0-based).
	 * @param string $filtro  Filtro para overridear el backoff.
	 * @return int
	 */
	private static function backoff( $default, $intento, $filtro ) {
		$sched = self::backoff_schedule( $default, $filtro );
		$idx   = min( max( 0, (int) $intento ), count( $sched ) - 1 );
		return (int) $sched[ $idx ];
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
			self::reprogramar_o_fallar( $order, __( 'Sin respuesta del puente al consultar estatus.', 'facturacionmozart-woocommerce-plugin' ) );
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
					__( 'CFDI timbrado. UUID: %s', 'facturacionmozart-woocommerce-plugin' ),
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
		self::reprogramar_o_fallar( $order, __( 'El timbrado sigue en proceso tras varios intentos.', 'facturacionmozart-woocommerce-plugin' ) );
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

		$backoff = self::backoff_schedule( self::BACKOFF_POLL, 'fcfdi_backoff_poll' );
		if ( $intentos >= count( $backoff ) ) {
			$order->update_meta_data( '_fcfdi_estatus', 'error' );
			$order->update_meta_data( '_fcfdi_error', $mensaje );
			$order->save();
			$order->add_order_note( '⚠️ ' . $mensaje );
			self::escalar_si_retenido( $order, $mensaje );
			return;
		}

		as_schedule_single_action(
			time() + self::backoff( self::BACKOFF_POLL, $intentos, 'fcfdi_backoff_poll' ),
			self::HOOK_CONSULTAR,
			array( 'order_id' => $order->get_id() ),
			'facturacionmozart-woocommerce-plugin'
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
		$mensaje = isset( $body['mensaje'] ) ? $body['mensaje'] : __( 'Error desconocido del puente.', 'facturacionmozart-woocommerce-plugin' );
		$order->update_meta_data( '_fcfdi_estatus', 'error' );
		$order->update_meta_data( '_fcfdi_error', $codigo . ': ' . $mensaje );
		$order->save();
		$order->add_order_note( '⚠️ ' . sprintf( __( 'Error de facturación (%1$s): %2$s', 'facturacionmozart-woocommerce-plugin' ), $codigo, $mensaje ) );
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
				__( 'Pedido retenido en espera de CFDI. Requiere atención manual: %s', 'facturacionmozart-woocommerce-plugin' ),
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
			sprintf( __( '[%1$s] Pedido #%2$s retenido: falló la facturación CFDI', 'facturacionmozart-woocommerce-plugin' ), get_bloginfo( 'name' ), $order->get_order_number() ),
			sprintf( __( "El pedido #%1\$s pidió factura pero el timbrado no se completó y quedó retenido (en espera) en vez de completado.\n\nMotivo: %2\$s\n\nRevísalo en el admin de WooCommerce.", 'facturacionmozart-woocommerce-plugin' ), $order->get_order_number(), $motivo )
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
				'descripcion'     => __( 'Servicio de envío', 'facturacionmozart-woocommerce-plugin' ),
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
