<?php
/**
 * Configuración del plugin: URL del puente y token de API.
 *
 * @package FacturacionCFDI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCFDI_Settings {

	const OPTION = 'fcfdi_settings';

	/** Texto que hay que escribir en un campo secreto para dejarlo vacío. */
	const BORRAR = 'borrar';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'wp_ajax_fcfdi_probar_conexion', array( __CLASS__, 'ajax_probar_conexion' ) );
		add_action( 'admin_notices', array( __CLASS__, 'aviso_url_insegura' ) );
	}

	/**
	 * Aviso persistente si el plugin está activo pero sin configurar: sin URL/token el
	 * plugin NO valida ni factura y lo hace en silencio (fail-open). Una mala config en
	 * producción desactivaría la facturación sin señal; este banner la hace visible.
	 * Se muestra sólo a quien puede gestionar WooCommerce y no en la propia pantalla de
	 * ajustes (ahí el formulario ya es evidente).
	 */
	/**
	 * True si la URL del puente manda el token por un canal sin cifrar.
	 *
	 * El token de API viaja en la cabecera Authorization de CADA petición. Con una URL http
	 * queda expuesto a cualquiera que observe la red entre la tienda y el puente. El puente
	 * rechaza esas peticiones, pero eso ocurre DESPUÉS: el token ya viajó en claro.
	 *
	 * Se exceptúan los destinos locales, donde el tráfico no sale de la máquina o de la red
	 * del entorno de pruebas, para no estorbar en QA.
	 *
	 * @return bool
	 */
	public static function url_insegura() {
		$url = self::get_api_url();
		if ( '' === $url ) {
			return false;
		}

		$partes = wp_parse_url( $url );
		if ( empty( $partes['scheme'] ) || 'https' === strtolower( $partes['scheme'] ) ) {
			return false;
		}

		$host = isset( $partes['host'] ) ? strtolower( $partes['host'] ) : '';
		$locales = array( 'localhost', '127.0.0.1', '::1', 'host.docker.internal' );
		if ( in_array( $host, $locales, true ) ) {
			return false;
		}
		// Nombres de servicio sin punto (p. ej. "bridge" en Docker) y sufijos de red interna.
		if ( false === strpos( $host, '.' ) || preg_match( '/\.(local|test|localhost|internal)$/', $host ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Avisa si el token se está enviando por un canal sin cifrar.
	 */
	public static function aviso_url_insegura() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::url_insegura() ) {
			return;
		}
		$url = admin_url( 'admin.php?page=fcfdi-settings' );
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Facturación CFDI', 'facturacionmozart-woocommerce-plugin' ) . ':</strong> '
			. esc_html__( 'la URL del puente no usa HTTPS. El token de API se envía en cada petición y viaja sin cifrar, al alcance de cualquiera que observe la red. Corrige la dirección para que empiece por https://.', 'facturacionmozart-woocommerce-plugin' )
			. ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Revisar la configuración', 'facturacionmozart-woocommerce-plugin' ) . '</a>.</p></div>';
	}

	public static function aviso_sin_configurar() {
		if ( ! current_user_can( 'manage_woocommerce' ) || self::esta_configurado() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && isset( $screen->id ) && false !== strpos( $screen->id, 'fcfdi-settings' ) ) {
			return;
		}
		$url = admin_url( 'admin.php?page=fcfdi-settings' );
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Facturación CFDI', 'facturacionmozart-woocommerce-plugin' ) . ':</strong> '
			. esc_html__( 'el plugin está activo pero sin configurar (falta la URL del puente o el token). Mientras tanto NO se validan datos fiscales en el checkout ni se generan CFDI.', 'facturacionmozart-woocommerce-plugin' )
			. ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Configurar ahora', 'facturacionmozart-woocommerce-plugin' ) . '</a>.</p></div>';
	}

	/**
	 * Devuelve un valor de configuración.
	 *
	 * @param string $clave   Clave.
	 * @param mixed  $default Valor por defecto.
	 * @return mixed
	 */
	public static function get( $clave, $default = '' ) {
		$opts = get_option( self::OPTION, array() );
		return isset( $opts[ $clave ] ) ? $opts[ $clave ] : $default;
	}

	public static function get_api_url() {
		return untrailingslashit( trim( self::get( 'api_url' ) ) );
	}

	public static function get_api_token() {
		return trim( self::get( 'api_token' ) );
	}

	/**
	 * Secreto con el que el puente firma sus notificaciones (webhook). Es un secreto
	 * distinto del token de API y no tiene sustituto.
	 *
	 * Antes se caía al token de API cuando este campo quedaba vacío. Eso hacía que la
	 * credencial con la que la tienda se autentica ante el puente fuera también la clave
	 * con la que el puente firma lo que le envía: un solo valor comprometido rompía las
	 * dos direcciones. Sin secreto capturado, el webhook queda cerrado y el pedido se
	 * actualiza por sondeo, que es la vía primaria.
	 *
	 * @return string Cadena vacía si no se ha configurado.
	 */
	public static function get_webhook_secret() {
		return trim( self::get( 'webhook_secret' ) );
	}

	public static function esta_configurado() {
		return self::get_api_url() && self::get_api_token();
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Facturación CFDI', 'facturacionmozart-woocommerce-plugin' ),
			__( 'Facturación CFDI', 'facturacionmozart-woocommerce-plugin' ),
			'manage_woocommerce',
			'fcfdi-settings',
			array( __CLASS__, 'render' )
		);
	}

	public static function register() {
		register_setting(
			'fcfdi_settings_group',
			self::OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) )
		);
	}

	/**
	 * Saneo de la configuración.
	 *
	 * @param array $input Entrada.
	 * @return array
	 */
	public static function sanitize( $input ) {
		return array(
			'api_url'          => esc_url_raw( isset( $input['api_url'] ) ? $input['api_url'] : '' ),
			'api_token'        => self::sanear_secreto( $input, 'api_token' ),
			'webhook_secret'   => self::sanear_secreto( $input, 'webhook_secret' ),
			'facturar_siempre' => empty( $input['facturar_siempre'] ) ? 'no' : 'si',
		);
	}

	/**
	 * Sanea un campo secreto conservando el valor guardado cuando llega vacío.
	 *
	 * El formulario ya no reimprime estos valores (ver render), así que un campo vacío
	 * significa "no lo estoy cambiando", no "bórralo". Para dejarlo sin valor se escribe el
	 * texto de borrado, que es una acción explícita y no un descuido al guardar la página.
	 *
	 * @param array  $input Entrada del formulario.
	 * @param string $clave Clave del secreto.
	 * @return string
	 */
	private static function sanear_secreto( $input, $clave ) {
		$valor = isset( $input[ $clave ] ) ? trim( sanitize_text_field( $input[ $clave ] ) ) : '';

		if ( '' === $valor ) {
			return (string) self::get( $clave );
		}

		if ( self::BORRAR === strtolower( $valor ) ) {
			return '';
		}

		return $valor;
	}

	/**
	 * Texto del campo vacío: dice si el secreto ya está guardado, sin revelarlo.
	 *
	 * Estos campos ya no reimprimen su valor. Antes lo hacían, así que el token y el secreto
	 * viajaban al navegador en el HTML de la página de ajustes cada vez que se abría —
	 * visibles en el código fuente y al alcance de cualquier XSS del panel—, sin que hubiera
	 * ninguna razón para mandarlos de vuelta: quien los conoce es quien los escribió.
	 *
	 * @param string $clave Clave del secreto.
	 * @return string
	 */
	private static function placeholder_secreto( $clave ) {
		return '' !== (string) self::get( $clave )
			? __( 'Guardado — escribe uno nuevo para reemplazarlo', 'facturacionmozart-woocommerce-plugin' )
			: __( 'Sin configurar', 'facturacionmozart-woocommerce-plugin' );
	}

	/**
	 * Aclaración de qué pasa al guardar con el campo vacío.
	 *
	 * @return string
	 */
	private static function ayuda_secreto() {
		/* translators: %s: palabra que hay que escribir para borrar el valor guardado. */
		return sprintf(
			__( 'Déjalo vacío para conservar el valor guardado; escribe «%s» para dejarlo sin valor.', 'facturacionmozart-woocommerce-plugin' ),
			self::BORRAR
		);
	}

	public static function render() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Facturación CFDI para WooCommerce', 'facturacionmozart-woocommerce-plugin' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'fcfdi_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fcfdi_api_url"><?php esc_html_e( 'URL del puente', 'facturacionmozart-woocommerce-plugin' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[api_url]" id="fcfdi_api_url" type="url"
								class="regular-text" placeholder="https://tu-servidor/api/v1/facturas"
								value="<?php echo esc_attr( self::get( 'api_url' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Endpoint base del puente REST, p.ej. https://tu-servidor/api/v1/facturas', 'facturacionmozart-woocommerce-plugin' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fcfdi_api_token"><?php esc_html_e( 'Token de API', 'facturacionmozart-woocommerce-plugin' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[api_token]" id="fcfdi_api_token" type="password"
								class="regular-text" autocomplete="off" value=""
								placeholder="<?php echo esc_attr( self::placeholder_secreto( 'api_token' ) ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Token Bearer entregado por el proveedor de facturación.', 'facturacionmozart-woocommerce-plugin' ); ?>
								<?php echo esc_html( self::ayuda_secreto() ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fcfdi_webhook_secret"><?php esc_html_e( 'Secreto del webhook', 'facturacionmozart-woocommerce-plugin' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[webhook_secret]" id="fcfdi_webhook_secret" type="password"
								class="regular-text" autocomplete="off" value=""
								placeholder="<?php echo esc_attr( self::placeholder_secreto( 'webhook_secret' ) ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Secreto con el que el puente firma sus avisos de timbrado. Te lo entrega tu proveedor de facturación junto con el token, y es un valor distinto: el token autentica lo que esta tienda envía, y el secreto verifica lo que recibe. Si se deja vacío, los avisos se rechazan y los pedidos se actualizan sólo por sondeo.', 'facturacionmozart-woocommerce-plugin' ); ?>
								<?php echo esc_html( self::ayuda_secreto() ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Facturar siempre', 'facturacionmozart-woocommerce-plugin' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[facturar_siempre]" value="si"
									<?php checked( 'si', self::get( 'facturar_siempre', 'si' ) ); ?> />
								<?php esc_html_e( 'Generar CFDI para todos los pedidos (público en general si el cliente no pide factura).', 'facturacionmozart-woocommerce-plugin' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Probar conexión', 'facturacionmozart-woocommerce-plugin' ); ?></h2>
			<p>
				<button type="button" class="button" id="fcfdi-probar"><?php esc_html_e( 'Probar conexión con el puente', 'facturacionmozart-woocommerce-plugin' ); ?></button>
				<span id="fcfdi-probar-resultado" style="margin-left:10px;"></span>
			</p>
			<script>
			( function () {
				var btn = document.getElementById( 'fcfdi-probar' );
				if ( ! btn ) { return; }
				btn.addEventListener( 'click', function () {
					var out = document.getElementById( 'fcfdi-probar-resultado' );
					out.textContent = '<?php echo esc_js( __( 'Probando…', 'facturacionmozart-woocommerce-plugin' ) ); ?>';
					var data = new FormData();
					data.append( 'action', 'fcfdi_probar_conexion' );
					data.append( '_wpnonce', '<?php echo esc_js( wp_create_nonce( 'fcfdi_probar' ) ); ?>' );
					fetch( ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( j ) { out.textContent = j.data; } )
						.catch( function () { out.textContent = '<?php echo esc_js( __( 'Error de red.', 'facturacionmozart-woocommerce-plugin' ) ); ?>'; } );
				} );
			} )();
			</script>
		</div>
		<?php
	}

	/**
	 * Prueba de conexión: consulta un id inexistente. 404 = auth OK y puente alcanzable;
	 * 401 = token inválido; otro = error.
	 */
	public static function ajax_probar_conexion() {
		check_ajax_referer( 'fcfdi_probar' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Sin permisos.', 'facturacionmozart-woocommerce-plugin' ) );
		}
		if ( ! self::esta_configurado() ) {
			wp_send_json_error( __( 'Configura primero la URL y el token.', 'facturacionmozart-woocommerce-plugin' ) );
		}

		$client = new FCFDI_Api_Client();
		$res     = $client->health();

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( sprintf( __( 'No se pudo conectar: %s', 'facturacionmozart-woocommerce-plugin' ), $res->get_error_message() ) );
		}
		$code = (int) $res['code'];
		if ( 200 === $code && isset( $res['body']['status'] ) && 'ok' === $res['body']['status'] ) {
			$body     = $res['body'];
			$comercio = isset( $body['comercio'] ) ? $body['comercio'] : '';
			$pruebas  = ! empty( $body['timbrado_pruebas'] ) ? __( ' (modo PRUEBAS)', 'facturacionmozart-woocommerce-plugin' ) : '';
			$mensaje  = '✅ ' . sprintf( __( 'Conexión correcta. Comercio: %1$s%2$s', 'facturacionmozart-woocommerce-plugin' ), $comercio, $pruebas );

			// Diagnóstico opcional que el puente puede reportar en /health. Son avisos, no
			// errores: la conexión funciona igual, pero señalan una configuración a medias.
			// Los textos describen el estado observable, sin asumir cómo esté implementado
			// el backend: cualquiera compatible puede enviar estos campos.
			$avisos = array();
			if ( isset( $body['esquema_fase2'] ) && ! $body['esquema_fase2'] ) {
				$avisos[] = __( 'el puente reporta que su almacenamiento no está al día', 'facturacionmozart-woocommerce-plugin' );
			}
			if ( isset( $body['secreto_webhook'] ) && ! $body['secreto_webhook'] ) {
				$avisos[] = __( 'este comercio no tiene secreto de webhook propio, así que no recibirá avisos de timbrado', 'facturacionmozart-woocommerce-plugin' );
			}
			if ( isset( $body['token_hasheado'] ) && ! $body['token_hasheado'] ) {
				$avisos[] = __( 'el puente guarda el token de forma recuperable, en vez de sólo su hash', 'facturacionmozart-woocommerce-plugin' );
			}
			if ( isset( $body['dominio_configurado'] ) && ! $body['dominio_configurado'] ) {
				$avisos[] = __( 'no hay dominio autorizado configurado para este comercio', 'facturacionmozart-woocommerce-plugin' );
			}
			if ( ! empty( $avisos ) ) {
				$mensaje .= ' — ⚠️ ' . __( 'Pendiente:', 'facturacionmozart-woocommerce-plugin' ) . ' ' . implode( '; ', $avisos ) . '.';
			}

			wp_send_json_success( $mensaje );
		}
		if ( 401 === $code || 403 === $code ) {
			wp_send_json_error( '❌ ' . __( 'Token inválido o IP no autorizada.', 'facturacionmozart-woocommerce-plugin' ) );
		}
		wp_send_json_error( sprintf( '⚠️ ' . __( 'Respuesta inesperada del puente (HTTP %d).', 'facturacionmozart-woocommerce-plugin' ), $code ) );
	}
}
