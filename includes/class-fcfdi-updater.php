<?php
/**
 * Actualizaciones del plugin desde los releases publicados.
 *
 * Este plugin no está en el directorio de WordPress.org, así que WordPress no sabe por sí
 * solo que existe una versión nueva: quien lo instaló por ZIP se queda en esa versión hasta
 * que alguien le avisa. Eso es un problema cuando lo que se publica es una corrección de
 * seguridad.
 *
 * Aquí se engancha al mecanismo nativo de actualizaciones (cabecera `Update URI` más el
 * filtro `update_plugins_{host}`, disponible desde WordPress 5.8). Con eso, la actualización
 * aparece en **Escritorio → Actualizaciones** y en la lista de plugins, y funciona también
 * por línea de comandos con `wp plugin update`. No hace falta que el comerciante descargue
 * ni suba nada.
 *
 * @package FacturacionCFDI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCFDI_Updater {

	/** Host que declara la cabecera Update URI; define qué filtro nos corresponde. */
	const HOST = 'github.com';

	/** Repositorio público de donde salen los releases. */
	const REPO = 'yolovany/facturacionmozart-woocommerce-plugin';

	/** Cuánto se recuerda la consulta al origen. */
	const CACHE = 12 * HOUR_IN_SECONDS;

	const TRANSIENT = 'fcfdi_ultima_version';

	public static function init() {
		add_filter( 'update_plugins_' . self::HOST, array( __CLASS__, 'comprobar' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'aviso_version_nueva' ) );
	}

	/**
	 * Responde al comprobador de actualizaciones de WordPress.
	 *
	 * @param array|false $update Lo que hayan devuelto otros filtros.
	 * @param array       $datos  Cabeceras del plugin.
	 * @param string      $file   Ruta relativa del plugin.
	 * @return array|false
	 */
	public static function comprobar( $update, $datos, $file ) {
		if ( plugin_basename( FCFDI_PLUGIN_FILE ) !== $file ) {
			return $update;
		}

		$release = self::ultimo_release();
		if ( ! $release || version_compare( $release['version'], FCFDI_VERSION, '<=' ) ) {
			return $update;
		}

		return array(
			'slug'    => dirname( plugin_basename( FCFDI_PLUGIN_FILE ) ),
			'version' => $release['version'],
			'url'     => 'https://' . self::HOST . '/' . self::REPO,
			'package' => $release['paquete'],
		);
	}

	/**
	 * Última versión publicada, o null si no se pudo averiguar.
	 *
	 * Se guarda en caché —incluido el fallo— para no consultar el origen en cada carga del
	 * panel ni castigar a una tienda sin salida a internet.
	 *
	 * @return array|null array( version, paquete )
	 */
	private static function ultimo_release() {
		$cache = get_transient( self::TRANSIENT );
		if ( is_array( $cache ) ) {
			return empty( $cache['version'] ) ? null : $cache;
		}

		$res = wp_remote_get(
			'https://api.' . self::HOST . '/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'facturacionmozart-woocommerce-plugin/' . FCFDI_VERSION,
				),
			)
		);

		$datos = ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) )
			? json_decode( wp_remote_retrieve_body( $res ), true )
			: null;

		$release = array( 'version' => '', 'paquete' => '' );

		if ( is_array( $datos ) && ! empty( $datos['tag_name'] ) ) {
			// El paquete es el .zip adjunto al release. Deliberadamente NO se cae al
			// zipball de GitHub: ese contiene el repositorio, no el plugin empaquetado,
			// y al instalarlo dejaría una carpeta con otro nombre y con archivos de
			// desarrollo dentro.
			foreach ( (array) ( isset( $datos['assets'] ) ? $datos['assets'] : array() ) as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] )
					&& '.zip' === substr( $asset['name'], -4 ) ) {
					$release['version'] = ltrim( (string) $datos['tag_name'], 'vV' );
					$release['paquete'] = esc_url_raw( $asset['browser_download_url'] );
					break;
				}
			}
		}

		set_transient( self::TRANSIENT, $release, self::CACHE );
		return empty( $release['version'] ) ? null : $release;
	}

	/**
	 * Aviso en el panel cuando hay una versión más reciente.
	 *
	 * Se apoya en lo que reporte el puente (`plugin_version_disponible` en /health), que es
	 * el dato que controla quien opera el servicio, y cae a la consulta del origen si el
	 * puente no lo informa.
	 */
	public static function aviso_version_nueva() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$disponible = self::version_segun_puente();
		if ( ! $disponible ) {
			$release    = self::ultimo_release();
			$disponible = $release ? $release['version'] : '';
		}

		if ( ! $disponible || version_compare( $disponible, FCFDI_VERSION, '<=' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s:</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Facturación CFDI', 'facturacionmozart-woocommerce-plugin' ),
			esc_html(
				sprintf(
					/* translators: 1: versión disponible, 2: versión instalada. */
					__( 'hay una versión más reciente (%1$s; tienes la %2$s). Las actualizaciones incluyen correcciones de seguridad.', 'facturacionmozart-woocommerce-plugin' ),
					$disponible,
					FCFDI_VERSION
				)
			),
			esc_url( admin_url( 'update-core.php' ) ),
			esc_html__( 'Ir a Actualizaciones', 'facturacionmozart-woocommerce-plugin' )
		);
	}

	/**
	 * Versión que el puente declara como vigente, si la informa.
	 *
	 * @return string
	 */
	private static function version_segun_puente() {
		$cache = get_transient( 'fcfdi_version_puente' );
		if ( false !== $cache ) {
			return (string) $cache;
		}

		$version = '';
		if ( class_exists( 'FCFDI_Settings' ) && FCFDI_Settings::esta_configurado() ) {
			$res = ( new FCFDI_Api_Client() )->health();
			if ( ! is_wp_error( $res ) && 200 === (int) $res['code']
				&& ! empty( $res['body']['plugin_version_disponible'] ) ) {
				$version = sanitize_text_field( (string) $res['body']['plugin_version_disponible'] );
			}
		}

		set_transient( 'fcfdi_version_puente', $version, self::CACHE );
		return $version;
	}
}
