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

	/**
	 * Códigos HTTP que son fallo de INFRAESTRUCTURA/configuración, no de negocio: se
	 * reintentan con backoff en vez de marcar el pedido como error definitivo.
	 * 401/403: token rotado o inválido, IP fuera de la whitelist. 408/429: timeout del
	 * proxy y rate-limit. Todos se resuelven solos al corregir la configuración.
	 */
	/**
	 * Respuestas que no son culpa del pedido, sino de la configuración o del canal, y que
	 * por tanto se reintentan en vez de marcar el pedido en error definitivo:
	 * credenciales (401/403), tiempo de espera del proxy (408), límite de peticiones (429)
	 * y plugin por debajo de la versión mínima que exige el puente (426).
	 *
	 * En todos ellos, el pedido en vuelo se recupera solo en cuanto se corrige la causa
	 * —se arregla el token, se actualiza el plugin—, sin intervención manual.
	 */
	const CODIGOS_INFRA = array( 401, 403, 408, 426, 429 );

	/**
	 * Errores que el comprador sí puede resolver actualizando los datos del CFDI.
	 * El resto requiere atención de la tienda o del servicio y nunca debe presentarse
	 * como si los datos fiscales del cliente fueran incorrectos.
	 */
	const CODIGOS_ACCION_CLIENTE = array(
		'RFC_FALTANTE',
		'RFC_FORMATO',
		'RFC_INVALIDO',
		'REGIMEN_FALTANTE',
		'REGIMEN_INVALIDO',
		'CP_FALTANTE',
		'CP_FORMATO',
		'USO_CFDI_FALTANTE',
		'USO_CFDI_INCOMPATIBLE',
		'CFDI40147',
		'CFDI40157',
		'SIN_RECEPTOR',
		'RECEPTOR_EN_LISTA_69B',
	);

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
	 * Obtiene el código estable guardado como "CODIGO: mensaje".
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	public static function codigo_error( $order ) {
		$guardado = (string) $order->get_meta( '_fcfdi_error' );
		return ( false !== strpos( $guardado, ':' ) ) ? trim( strstr( $guardado, ':', true ) ) : trim( $guardado );
	}

	/**
	 * Indica si la recuperación requiere que el comprador actualice datos fiscales.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	public static function requiere_accion_cliente( $order ) {
		return in_array( self::codigo_error( $order ), self::CODIGOS_ACCION_CLIENTE, true );
	}

	/**
	 * Mensaje seguro para el comprador. Los detalles técnicos sólo se muestran al
	 * administrador; un fallo del servicio no debe culpar a los datos fiscales.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	public static function mensaje_error_cliente( $order ) {
		if ( self::requiere_accion_cliente( $order ) && class_exists( 'FCFDI_Checkout' ) ) {
			return FCFDI_Checkout::mensaje_error( self::codigo_error( $order ) );
		}
		return __( 'Tu pago está confirmado, pero la factura no pudo generarse por una incidencia del servicio. No necesitas modificar tus datos fiscales. La tienda ya puede revisar el problema y reintentar la facturación.', 'facturacionmozart-woocommerce-plugin' );
	}

	/**
	 * Si el pedido se retuvo esperando el CFDI, lo regresa a su estatus previo
	 * (processing si se pagó sin completar, completed si ya venía completado).
	 *
	 * Pública: también la invoca el webhook al recibir el resultado del timbrado.
	 *
	 * @param WC_Order $order Pedido.
	 */
	public static function liberar_si_retenido( $order ) {
		if ( 'si' !== $order->get_meta( '_fcfdi_retener_completado' ) ) {
			return;
		}
		$previo = (string) $order->get_meta( '_fcfdi_estatus_previo' );
		$previo = '' !== $previo ? $previo : 'completed';
		$order->update_meta_data( '_fcfdi_retener_completado', '' );
		$order->update_meta_data( '_fcfdi_estatus_previo', '' );
		$order->save();
		if ( $order->has_status( 'on-hold' ) ) {
			// El cliente ya recibió el correo de ese estado al pagar; al volver a él tras
			// timbrar, WooCommerce lo mandaría otra vez.
			$filtro      = 'woocommerce_email_enabled_customer_' . $previo . '_order';
			$sin_repetir = function ( $activo, $pedido ) use ( $order ) {
				return $pedido instanceof WC_Order && $pedido->get_id() === $order->get_id() ? false : $activo;
			};
			add_filter( $filtro, $sin_repetir, 10, 2 );
			$order->update_status( $previo, __( 'CFDI timbrado: se libera el pedido.', 'facturacionmozart-woocommerce-plugin' ) );
			remove_filter( $filtro, $sin_repetir, 10 );
		}
	}

	/**
	 * Desprograma cualquier envío/poll pendiente del pedido en Action Scheduler.
	 * Se usa al abortar la facturación (pedido cancelado) o cuando el webhook ya
	 * entregó el resultado y el polling sobra.
	 *
	 * @param int $order_id Id del pedido.
	 */
	public static function detener_programadas( $order_id ) {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		as_unschedule_all_actions( self::HOOK_ENVIAR, array( 'order_id' => $order_id ), 'facturacionmozart-woocommerce-plugin' );
		as_unschedule_all_actions( self::HOOK_CONSULTAR, array( 'order_id' => $order_id ), 'facturacionmozart-woocommerce-plugin' );
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
		// Solo se envía si el pedido sigue en la ruta de envío. Si la facturación se
		// abortó (p.ej. el pedido se canceló antes de timbrar), el estatus ya no es
		// 'encolada'/'reintentando' y esta acción encolada debe morir en silencio.
		if ( ! in_array( (string) $order->get_meta( '_fcfdi_estatus' ), array( 'encolada', 'reintentando' ), true ) ) {
			return;
		}

		$payload = self::construir_payload( $order );
		$client  = new FCFDI_Api_Client();
		$res     = $client->crear_factura( $payload, (string) $order->get_id() );

		// Error de red o 5xx del puente: reintentar con backoff (reprogramando el envío).
		// También son de INFRA (no de negocio) los fallos de credencial/acceso y el 429:
		// un token rotado, una IP fuera de la whitelist o un rate-limit son condiciones
		// transitorias de configuración. Marcarlos como error definitivo quemaría pedidos
		// legítimos; con reintento se recuperan solos al corregir la configuración.
		if ( is_wp_error( $res ) || in_array( (int) $res['code'], self::CODIGOS_INFRA, true ) || (int) $res['code'] >= 500 ) {
			$motivo = is_wp_error( $res ) ? $res->get_error_message() : ( 'HTTP ' . $res['code'] );
			self::reintentar_envio( $order, $motivo );
			return;
		}

		$code = (int) $res['code'];
		$body = $res['body'];

		if ( 202 === $code || 200 === $code ) {
			$factura_id = isset( $body['factura_id'] ) ? sanitize_text_field( (string) $body['factura_id'] ) : '';
			$order->update_meta_data( '_fcfdi_factura_id', $factura_id );
			$order->update_meta_data( '_fcfdi_estatus', isset( $body['estatus'] ) ? sanitize_text_field( (string) $body['estatus'] ) : 'en_proceso' );
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
			// El puente es de confianza, pero estos valores terminan en headers HTTP
			// (Content-Disposition) y en el HTML de la cuenta: se sanean igual (defensa en
			// profundidad contra inyección de header/HTML si el puente devolviera algo raro).
			$order->update_meta_data( '_fcfdi_uuid', isset( $body['uuid'] ) ? sanitize_text_field( (string) $body['uuid'] ) : '' );
			$order->update_meta_data( '_fcfdi_xml_url', isset( $body['xml_url'] ) ? esc_url_raw( (string) $body['xml_url'] ) : '' );
			$order->update_meta_data( '_fcfdi_pdf_url', isset( $body['pdf_url'] ) ? esc_url_raw( (string) $body['pdf_url'] ) : '' );
			$order->save();
			$order->add_order_note(
				sprintf(
					/* translators: %s: UUID del CFDI */
					__( 'CFDI timbrado. UUID: %s', 'facturacionmozart-woocommerce-plugin' ),
					isset( $body['uuid'] ) ? $body['uuid'] : ''
				)
			);
			self::tras_timbrar( $order, $body );
			// El pedido se canceló/reembolsó mientras el puente timbraba: el CFDI recién
			// timbrado ya no corresponde a una venta y se cancela ante el SAT de inmediato.
			if ( self::cancelar_pendiente_si_aplica( $order ) ) {
				return;
			}
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
	 * Después de timbrar. Si el CFDI sustituye a uno a público en general, lo anota en el
	 * pedido. Si es a público en general, guarda hasta cuándo el cliente puede pedirlo a su
	 * nombre (el puente lo informa en la consulta de estatus, campo opcional
	 * sustituible_hasta; un puente que no lo envíe deja el botón siempre y él decide).
	 * Pública: también la invoca el webhook.
	 *
	 * @param WC_Order   $order   Pedido ya en 'timbrada'.
	 * @param array|null $estatus Respuesta de la consulta de estatus, si ya se tiene.
	 */
	public static function tras_timbrar( $order, $estatus = null ) {
		$anterior = (string) $order->get_meta( '_fcfdi_uuid_sustituido' );
		if ( '' !== $anterior ) {
			$order->delete_meta_data( '_fcfdi_uuid_sustituido' );
			$order->add_order_note(
				sprintf(
					/* translators: %s: UUID del CFDI a público en general */
					__( 'Esta factura a nombre del cliente sustituye al CFDI %s (público en general), que el puente cancela ante el SAT con motivo 01.', 'facturacionmozart-woocommerce-plugin' ),
					$anterior
				)
			);
		}
		if ( ! self::requiere_factura( $order ) ) {
			if ( null === $estatus ) {
				$res     = ( new FCFDI_Api_Client() )->consultar_estatus( $order->get_meta( '_fcfdi_factura_id' ) );
				$estatus = is_wp_error( $res ) ? array() : (array) $res['body'];
			}
			$hasta = isset( $estatus['sustituible_hasta'] ) ? (string) $estatus['sustituible_hasta'] : '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $hasta ) ) {
				$order->update_meta_data( '_fcfdi_sustituible_hasta', $hasta );
			}
		}
		$order->save();
	}

	/**
	 * Programa la consulta del estatus del CFDI (la usa la solicitud de factura a nombre del
	 * cliente, que ya tiene factura_id).
	 *
	 * @param int $order_id Id del pedido.
	 */
	public static function programar_consulta( $order_id ) {
		as_schedule_single_action(
			time() + self::backoff( self::BACKOFF_POLL, 0, 'fcfdi_backoff_poll' ),
			self::HOOK_CONSULTAR,
			array( 'order_id' => $order_id ),
			'facturacionmozart-woocommerce-plugin'
		);
	}

	/**
	 * Si el pedido quedó marcado para cancelar su CFDI al timbrarse (se canceló o
	 * reembolsó con el timbrado en vuelo), lo cancela ante el SAT ahora que ya existe.
	 * Pública: también la invoca el webhook al notificarse el timbrado.
	 *
	 * @param WC_Order $order Pedido (con _fcfdi_estatus ya en 'timbrada').
	 * @return bool true si aplicaba y se procesó la cancelación.
	 */
	public static function cancelar_pendiente_si_aplica( $order ) {
		if ( 'si' !== $order->get_meta( '_fcfdi_cancelar_al_timbrar' ) ) {
			return false;
		}
		$order->delete_meta_data( '_fcfdi_cancelar_al_timbrar' );
		$order->save();
		if ( class_exists( 'FCFDI_Cancel' ) && FCFDI_Cancel::cancelar_cfdi( $order ) ) {
			return true;
		}
		$order->add_order_note( '⚠️ ' . __( 'El CFDI se timbró tras cancelarse el pedido y NO se pudo cancelar automáticamente ante el SAT. Cancélalo manualmente (acción "Cancelar CFDI ante el SAT").', 'facturacionmozart-woocommerce-plugin' ) );
		return true;
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
	public static function registrar_error( $order, $body, $code ) {
		$codigo       = isset( $body['codigo'] ) ? sanitize_text_field( (string) $body['codigo'] ) : 'HTTP_' . $code;
		$mensaje      = isset( $body['mensaje'] ) ? sanitize_text_field( (string) $body['mensaje'] ) : __( 'Error desconocido del puente.', 'facturacionmozart-woocommerce-plugin' );
		$reintentable = ! empty( $body['reintentable'] );
		$order->update_meta_data( '_fcfdi_estatus', 'error' );
		$order->update_meta_data( '_fcfdi_error', $codigo . ': ' . $mensaje );
		$order->update_meta_data( '_fcfdi_error_tipo', in_array( $codigo, self::CODIGOS_ACCION_CLIENTE, true ) ? 'cliente' : 'servicio' );
		$order->update_meta_data( '_fcfdi_error_reintentable', $reintentable ? 'si' : 'no' );
		$order->save();
		$order->add_order_note( '⚠️ ' . sprintf( __( 'Error de facturación (%1$s): %2$s', 'facturacionmozart-woocommerce-plugin' ), $codigo, $mensaje ) );
		self::escalar_si_retenido( $order, $codigo . ': ' . $mensaje );
	}

	/**
	 * Si el pedido está retenido esperando CFDI y el timbrado ya no puede completarse
	 * solo (dato de negocio incorrecto o intentos agotados), permanece en "en espera"
	 * (nunca se libera solo) y se avisa al administrador para que lo resuelva a mano.
	 *
	 * Pública: también la invoca el webhook cuando el puente notifica un error.
	 *
	 * @param WC_Order $order  Pedido.
	 * @param string   $motivo Motivo del fallo.
	 */
	public static function escalar_si_retenido( $order, $motivo ) {
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
		$pasos = self::requiere_accion_cliente( $order )
			? __( "El error requiere datos del cliente:\n1. Abre el pedido en WooCommerce.\n2. Pulsa “Solicitar actualización de datos al cliente”.\n3. El cliente podrá corregirlos y reintentar desde el enlace recibido.", 'facturacionmozart-woocommerce-plugin' )
			: __( "El error corresponde al servicio o a la configuración, no a los datos fiscales del cliente:\n1. Revisa y corrige la incidencia indicada.\n2. Abre el pedido en WooCommerce.\n3. Pulsa “Reintentar ahora”.", 'facturacionmozart-woocommerce-plugin' );
		wp_mail(
			get_option( 'admin_email' ),
			sprintf( __( '[%1$s] Pedido #%2$s retenido: falló la facturación CFDI', 'facturacionmozart-woocommerce-plugin' ), get_bloginfo( 'name' ), $order->get_order_number() ),
			sprintf(
				__( "El pedido #%1\$s pidió factura, pero el timbrado no se completó y quedó retenido (en espera).\n\nMotivo técnico: %2\$s\n\nQué hacer:\n%3\$s\n\nAbrir pedido: %4\$s", 'facturacionmozart-woocommerce-plugin' ),
				$order->get_order_number(),
				$motivo,
				$pasos,
				$order->get_edit_order_url()
			)
		);
	}

	/**
	 * Determina si el cliente solicitó factura (checkout clásico o de bloques).
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	public static function requiere_factura( $order ) {
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
	 * Impuesto de una línea para el CFDI, o null si no es objeto de impuesto.
	 *
	 * Decide por la tasa que WooCommerce aplicó, no por el importe: un producto con tasa
	 * 0 % sí es objeto de impuesto (IVA al 0 %); solo una línea sin ninguna tasa aplicada
	 * es "no objeto". La tasa sale de la tabla de tasas y no de dividir importes, que con
	 * redondeos o descuentos da valores como 0.160001 que el catálogo del SAT rechaza.
	 *
	 * @param array $taxes    get_taxes() de la línea (o la unión de las de envío).
	 * @param float $importe  Impuesto total de la línea.
	 * @return array|null
	 */
	private static function impuesto_cfdi( $taxes, $importe ) {
		$tasas = array_keys( (array) ( $taxes['total'] ?? array() ) );
		if ( ! $tasas ) {
			return null;
		}
		return array(
			'tipo'    => 'IVA',
			'tasa'    => round( (float) WC_Tax::get_rate_percent_value( $tasas[0] ) / 100, 6 ),
			'importe' => round( (float) $importe, 2 ),
		);
	}

	/**
	 * Forma de pago del SAT (c_FormaPago) según la pasarela con que se pagó el pedido.
	 *
	 * Tarjeta: 04 (crédito), o 28 (débito) si la pasarela lo registra en el pedido.
	 * Efectivo (OXXO y similares, pago en tienda): 01. Transferencia: 03. Lo que no se
	 * reconoce queda en 99 (por definir), como antes. Ajustable con el filtro fcfdi_forma_pago.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	private static function forma_pago_sat( $order ) {
		$metodo = $order->get_payment_method();

		// "Efectivo" de Mercado Pago incluye STP (transferencia a CLABE, [Payment Type
		// bank_transfer]): eso es transferencia (03), no efectivo.
		if ( 'woo-mercado-pago-ticket' === $metodo && self::meta_de_pago_dice( $order, '/bank_transfer|clabe/i' ) ) {
			return '03';
		}
		if ( in_array( $metodo, array( 'cod', 'woo-mercado-pago-ticket', 'stripe_oxxo' ), true ) ) {
			return '01';
		}
		if ( 'bacs' === $metodo ) {
			return '03';
		}
		if ( in_array( $metodo, array( 'stripe', 'stripe_cc', 'woo-mercado-pago-custom', 'woocommerce_payments' ), true ) ) {
			// Stripe: "...funding" = debit. Mercado Pago: "Mercado Pago - Payment <id>" con
			// "[Payment Type debit_card]" en el valor.
			return self::meta_de_pago_dice( $order, '/debit/i' ) ? '28' : '04';
		}
		return '99';
	}

	/**
	 * ¿Algún dato del pago que guarda la pasarela (claves con "funding" o "payment")
	 * coincide con el patrón?
	 *
	 * @param WC_Order $order  Pedido.
	 * @param string   $patron Expresión regular sobre el valor.
	 * @return bool
	 */
	private static function meta_de_pago_dice( $order, $patron ) {
		foreach ( $order->get_meta_data() as $meta ) {
			if ( preg_match( '/funding|payment/i', $meta->key ) && is_string( $meta->value ) && preg_match( $patron, $meta->value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Base (sin IVA) de una línea para el CFDI.
	 *
	 * Con precios con IVA incluido, WooCommerce guarda la base y el IVA redondeados a
	 * centavos, y el CFDI (IVA = base × tasa, a 6 decimales) terminaría un centavo arriba o
	 * abajo de lo cobrado. La base se recalcula desde lo que pagó el cliente para que el total
	 * del CFDI sea exactamente ese monto.
	 *
	 * @param float $neto Importe sin IVA según WooCommerce.
	 * @param float $iva  IVA de la línea según WooCommerce.
	 * @param float $tasa Tasa aplicada (0.16, 0.0…).
	 * @return float
	 */
	private static function base_cfdi( $neto, $iva, $tasa ) {
		if ( $tasa > 0 && wc_prices_include_tax() ) {
			// El precio con IVA que vio el cliente, a centavos: WooCommerce puede guardar la
			// base redondeada y el IVA sin redondear (pasa con el envío).
			return round( round( (float) $neto + (float) $iva, 2 ) / ( 1 + $tasa ), 6 );
		}
		return round( (float) $neto, 6 );
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
			$linea_tax = (float) $item->get_total_tax();
			$impuesto  = self::impuesto_cfdi( $item->get_taxes(), $linea_tax );
			$tasa      = $impuesto ? $impuesto['tasa'] : 0.0;
			// Ex IVA, antes y después de descuento.
			$linea_sub = self::base_cfdi( $item->get_subtotal(), $item->get_subtotal_tax(), $tasa );
			$linea_tot = self::base_cfdi( $item->get_total(), $linea_tax, $tasa );
			$desc_item = round( $linea_sub - $linea_tot, 6 );

			$product = $item->get_product();
			$clave   = $product ? $product->get_meta( '_fcfdi_clave_prod_serv' ) : '';
			$unidad  = $product ? $product->get_meta( '_fcfdi_clave_unidad' ) : '';

			$concepto = array(
				'sku'             => $product ? $product->get_sku() : '',
				'descripcion'     => $item->get_name(),
				'cantidad'        => $qty,
				'valor_unitario'  => $qty > 0 ? round( $linea_sub / $qty, 6 ) : round( $linea_sub, 6 ),
				'importe'         => round( $linea_sub, 6 ),
				'descuento'       => $desc_item,
				'objeto_impuesto' => $impuesto ? '02' : '01',
			);
			if ( $clave ) {
				$concepto['clave_prod_serv'] = $clave;
			}
			if ( $unidad ) {
				$concepto['clave_unidad'] = $unidad;
			}
			if ( $impuesto ) {
				$concepto['impuestos'] = array( $impuesto );
			}

			$conceptos[] = $concepto;
			$subtotal   += $linea_sub;
			$descuento  += $desc_item;
			// Base × tasa, igual que el CFDI (el importe redondeado de WooCommerce no cuadra
			// con el SAT cuando los precios incluyen IVA).
			$impuestos  += $impuesto ? $linea_tot * $impuesto['tasa'] : 0;
		}

		// Envío como concepto (si el pedido tiene costo de envío).
		$envio     = (float) $order->get_shipping_total();
		$envio_tax = (float) $order->get_shipping_tax();
		if ( $envio > 0 ) {
			$tasas_envio = array( 'total' => array() );
			foreach ( $order->get_items( 'shipping' ) as $linea_envio ) {
				$tasas_envio['total'] += (array) ( $linea_envio->get_taxes()['total'] ?? array() );
			}
			$impuesto_envio = self::impuesto_cfdi( $tasas_envio, $envio_tax );
			$envio          = self::base_cfdi( $envio, $envio_tax, $impuesto_envio ? $impuesto_envio['tasa'] : 0.0 );
			$concepto_envio = array(
				'sku'             => 'ENVIO',
				'descripcion'     => __( 'Servicio de envío', 'facturacionmozart-woocommerce-plugin' ),
				'cantidad'        => 1,
				'valor_unitario'  => round( $envio, 6 ),
				'importe'         => round( $envio, 6 ),
				'descuento'       => 0,
				'objeto_impuesto' => $impuesto_envio ? '02' : '01',
				'clave_prod_serv' => apply_filters( 'fcfdi_clave_prod_serv_envio', '78102200', $order ),
				'clave_unidad'    => apply_filters( 'fcfdi_clave_unidad_envio', 'E48', $order ),
			);
			if ( $impuesto_envio ) {
				$concepto_envio['impuestos'] = array( $impuesto_envio );
			}
			$conceptos[] = $concepto_envio;
			$subtotal   += $envio;
			$impuestos  += $impuesto_envio ? $envio * $impuesto_envio['tasa'] : 0;
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
				'forma_pago'  => apply_filters( 'fcfdi_forma_pago', self::forma_pago_sat( $order ), $order ),
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
