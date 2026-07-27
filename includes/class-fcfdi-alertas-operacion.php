<?php
/**
 * Alertas operativas para administradores de WooCommerce.
 *
 * @package FacturacionCFDI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCFDI_Alertas_Operacion {

	const CRON_HOOK    = 'fcfdi_revisar_alertas_operacion';
	const OPTION_STATE = 'fcfdi_alertas_operacion';
	const TRANSIENT    = 'fcfdi_alertas_operacion_revision';

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'actualizar' ) );
		add_action( 'admin_init', array( __CLASS__, 'tal_vez_actualizar' ) );
		add_action( 'admin_notices', array( __CLASS__, 'mostrar' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	public static function desactivar() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Revisa al entrar al panel, como máximo una vez cada 15 minutos. El cron mantiene
	 * la revisión aun cuando ningún administrador abre la pantalla.
	 */
	public static function tal_vez_actualizar() {
		if ( ! current_user_can( 'manage_woocommerce' ) || get_transient( self::TRANSIENT ) ) {
			return;
		}
		set_transient( self::TRANSIENT, '1', 15 * MINUTE_IN_SECONDS );
		self::actualizar();
	}

	/**
	 * Consulta el estado del backend, actualiza los avisos y envía un correo únicamente
	 * cuando aparece un identificador nuevo (configuración distinta o nuevo umbral).
	 */
	public static function actualizar() {
		$estado_anterior = get_option(
			self::OPTION_STATE,
			array(
				'activas'     => array(),
				'notificadas' => array(),
			)
		);
		$notificadas = isset( $estado_anterior['notificadas'] ) && is_array( $estado_anterior['notificadas'] )
			? $estado_anterior['notificadas']
			: array();
		$alertas = array();

		if ( ! FCFDI_Settings::esta_configurado() ) {
			$alertas[] = array(
				'id'      => 'plugin:sin-configurar',
				'codigo'  => 'CONFIGURACION_PLUGIN_INCOMPLETA',
				'nivel'   => 'error',
				'mensaje' => __( 'El plugin está activo pero falta la URL del puente o el token. No se validan datos fiscales ni se generan CFDI.', 'facturacionmozart-woocommerce-plugin' ),
				'accion'  => __( 'Completa la conexión en WooCommerce → Facturación CFDI.', 'facturacionmozart-woocommerce-plugin' ),
			);
		} else {
			if ( ! FCFDI_Settings::get_webhook_secret() ) {
				$alertas[] = array(
					'id'      => 'plugin:webhook-sin-configurar',
					'codigo'  => 'CONFIGURACION_PLUGIN_INCOMPLETA',
					'nivel'   => 'warning',
					'mensaje' => __( 'Falta el secreto del webhook. Los avisos inmediatos del backend no pueden autenticarse y el estado depende del sondeo.', 'facturacionmozart-woocommerce-plugin' ),
					'accion'  => __( 'Captura el mismo secreto configurado para esta tienda en el backend.', 'facturacionmozart-woocommerce-plugin' ),
				);
			}
			$respuesta = ( new FCFDI_Api_Client() )->health();
			if ( is_wp_error( $respuesta ) || 200 !== (int) $respuesta['code'] ) {
				// Una caída temporal no borra el estado ni provoca que el mismo problema
				// vuelva a enviarse por correo cuando se recupere la conexión.
				return;
			}
			$remotas = isset( $respuesta['body']['alertas'] ) && is_array( $respuesta['body']['alertas'] )
				? $respuesta['body']['alertas']
				: array();
			foreach ( $remotas as $alerta ) {
				$normalizada = self::normalizar( $alerta );
				if ( $normalizada ) {
					$alertas[] = $normalizada;
				}
			}
		}

		$activas = array();
		foreach ( $alertas as $alerta ) {
			$clave            = hash( 'sha256', $alerta['id'] );
			$activas[ $clave ] = $alerta;
		}

		$nuevas_notificadas = array_intersect_key( $notificadas, $activas );
		foreach ( $activas as $clave => $alerta ) {
			if ( isset( $notificadas[ $clave ] ) ) {
				continue;
			}
			if ( self::enviar_correo( $alerta ) ) {
				$nuevas_notificadas[ $clave ] = time();
			}
		}

		update_option(
			self::OPTION_STATE,
			array(
				'activas'     => $activas,
				'notificadas' => $nuevas_notificadas,
				'revisado'    => time(),
			),
			false
		);
	}

	private static function normalizar( $alerta ) {
		if ( ! is_array( $alerta ) || empty( $alerta['id'] ) || empty( $alerta['mensaje'] ) ) {
			return null;
		}
		return array(
			'id'      => sanitize_text_field( $alerta['id'] ),
			'codigo'  => isset( $alerta['codigo'] ) ? sanitize_key( $alerta['codigo'] ) : 'alerta_operacion',
			'nivel'   => isset( $alerta['nivel'] ) && 'error' === $alerta['nivel'] ? 'error' : 'warning',
			'mensaje' => sanitize_text_field( $alerta['mensaje'] ),
			'accion'  => isset( $alerta['accion'] ) ? sanitize_text_field( $alerta['accion'] ) : '',
		);
	}

	private static function enviar_correo( $alerta ) {
		$destinatarios = apply_filters( 'fcfdi_alertas_destinatarios', get_option( 'admin_email' ) );
		if ( empty( $destinatarios ) ) {
			return false;
		}

		$asunto = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Atención requerida en facturación CFDI', 'facturacionmozart-woocommerce-plugin' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
		$mensaje  = __( 'Se detectó una condición que requiere atención:', 'facturacionmozart-woocommerce-plugin' ) . "\n\n";
		$mensaje .= $alerta['mensaje'] . "\n";
		if ( ! empty( $alerta['accion'] ) ) {
			$mensaje .= $alerta['accion'] . "\n";
		}
		$mensaje .= "\n" . admin_url( 'admin.php?page=fcfdi-settings' );

		return (bool) wp_mail( $destinatarios, $asunto, $mensaje );
	}

	public static function mostrar() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$estado = get_option( self::OPTION_STATE, array() );
		$activas = isset( $estado['activas'] ) && is_array( $estado['activas'] )
			? $estado['activas']
			: array();

		foreach ( $activas as $alerta ) {
			$clase = isset( $alerta['nivel'] ) && 'error' === $alerta['nivel']
				? 'notice notice-error'
				: 'notice notice-warning';
			echo '<div class="' . esc_attr( $clase ) . '"><p><strong>'
				. esc_html__( 'Facturación CFDI:', 'facturacionmozart-woocommerce-plugin' )
				. '</strong> ' . esc_html( $alerta['mensaje'] );
			if ( ! empty( $alerta['accion'] ) ) {
				echo ' ' . esc_html( $alerta['accion'] );
			}
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=fcfdi-settings' ) ) . '">'
				. esc_html__( 'Revisar conexión', 'facturacionmozart-woocommerce-plugin' )
				. '</a></p></div>';
		}
	}
}
