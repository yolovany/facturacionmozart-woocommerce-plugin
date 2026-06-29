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

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'wp_ajax_fcfdi_probar_conexion', array( __CLASS__, 'ajax_probar_conexion' ) );
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

	public static function esta_configurado() {
		return self::get_api_url() && self::get_api_token();
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Facturación CFDI', 'facturacion-cfdi' ),
			__( 'Facturación CFDI', 'facturacion-cfdi' ),
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
			'api_token'        => sanitize_text_field( isset( $input['api_token'] ) ? $input['api_token'] : '' ),
			'facturar_siempre' => empty( $input['facturar_siempre'] ) ? 'no' : 'si',
		);
	}

	public static function render() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Facturación CFDI para WooCommerce', 'facturacion-cfdi' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'fcfdi_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fcfdi_api_url"><?php esc_html_e( 'URL del puente', 'facturacion-cfdi' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[api_url]" id="fcfdi_api_url" type="url"
								class="regular-text" placeholder="https://tu-servidor/api/v1/facturas"
								value="<?php echo esc_attr( self::get( 'api_url' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Endpoint base del puente REST, p.ej. https://tu-servidor/api/v1/facturas', 'facturacion-cfdi' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fcfdi_api_token"><?php esc_html_e( 'Token de API', 'facturacion-cfdi' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[api_token]" id="fcfdi_api_token" type="password"
								class="regular-text" autocomplete="off"
								value="<?php echo esc_attr( self::get( 'api_token' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Token Bearer entregado por el proveedor de facturación.', 'facturacion-cfdi' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Facturar siempre', 'facturacion-cfdi' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[facturar_siempre]" value="si"
									<?php checked( 'si', self::get( 'facturar_siempre', 'si' ) ); ?> />
								<?php esc_html_e( 'Generar CFDI para todos los pedidos (público en general si el cliente no pide factura).', 'facturacion-cfdi' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Probar conexión', 'facturacion-cfdi' ); ?></h2>
			<p>
				<button type="button" class="button" id="fcfdi-probar"><?php esc_html_e( 'Probar conexión con el puente', 'facturacion-cfdi' ); ?></button>
				<span id="fcfdi-probar-resultado" style="margin-left:10px;"></span>
			</p>
			<script>
			( function () {
				var btn = document.getElementById( 'fcfdi-probar' );
				if ( ! btn ) { return; }
				btn.addEventListener( 'click', function () {
					var out = document.getElementById( 'fcfdi-probar-resultado' );
					out.textContent = '<?php echo esc_js( __( 'Probando…', 'facturacion-cfdi' ) ); ?>';
					var data = new FormData();
					data.append( 'action', 'fcfdi_probar_conexion' );
					data.append( '_wpnonce', '<?php echo esc_js( wp_create_nonce( 'fcfdi_probar' ) ); ?>' );
					fetch( ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( j ) { out.textContent = j.data; } )
						.catch( function () { out.textContent = '<?php echo esc_js( __( 'Error de red.', 'facturacion-cfdi' ) ); ?>'; } );
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
			wp_send_json_error( __( 'Sin permisos.', 'facturacion-cfdi' ) );
		}
		if ( ! self::esta_configurado() ) {
			wp_send_json_error( __( 'Configura primero la URL y el token.', 'facturacion-cfdi' ) );
		}

		$client = new FCFDI_Api_Client();
		$res     = $client->health();

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( sprintf( __( 'No se pudo conectar: %s', 'facturacion-cfdi' ), $res->get_error_message() ) );
		}
		$code = (int) $res['code'];
		if ( 200 === $code && isset( $res['body']['status'] ) && 'ok' === $res['body']['status'] ) {
			$comercio = isset( $res['body']['comercio'] ) ? $res['body']['comercio'] : '';
			$pruebas  = ! empty( $res['body']['timbrado_pruebas'] ) ? __( ' (modo PRUEBAS)', 'facturacion-cfdi' ) : '';
			wp_send_json_error( '✅ ' . sprintf( __( 'Conexión correcta. Comercio: %1$s%2$s', 'facturacion-cfdi' ), $comercio, $pruebas ) );
		}
		if ( 401 === $code || 403 === $code ) {
			wp_send_json_error( '❌ ' . __( 'Token inválido o IP no autorizada.', 'facturacion-cfdi' ) );
		}
		wp_send_json_error( sprintf( '⚠️ ' . __( 'Respuesta inesperada del puente (HTTP %d).', 'facturacion-cfdi' ), $code ) );
	}
}
