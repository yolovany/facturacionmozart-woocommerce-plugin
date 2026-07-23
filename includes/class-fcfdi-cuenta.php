<?php
/**
 * Cuenta silenciosa + acceso sin contraseña (enlace mágico).
 *
 * Objetivo: el cliente no tiene que "registrarse". Al comprar, si su correo aún no tiene
 * cuenta, se le crea una automáticamente —sin pedirle contraseña— y se le vinculan el
 * pedido y los datos que capturó en el checkout (facturación y envío). Así conserva su
 * historial de facturas y la próxima compra se autocompleta.
 *
 * Para volver a entrar no usa contraseña: pide un "enlace de acceso" con su correo y le
 * llega un enlace de un solo uso y con caducidad que lo deja dentro de "Mis Facturas".
 *
 * Notas de seguridad:
 * - Solo se crea/auto-inicia sesión cuando el correo NO pertenece a una cuenta existente.
 *   Si ya existe, no se vincula ni se inicia sesión (evita apropiarse de una cuenta ajena
 *   comprando con su correo). Ese cliente debe iniciar sesión antes de comprar.
 * - El token del enlace se guarda hasheado (SHA-256) con caducidad; se compara con
 *   hash_equals y se invalida al usarse (un solo uso).
 * - La respuesta a "solicitar acceso" es uniforme: no revela si un correo existe o no.
 *
 * @package FacturacionCFDI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCFDI_Cuenta {

	/** admin-post: solicitar el enlace de acceso. */
	const REQ_ACTION = 'fcfdi_solicitar_acceso';
	/** Query var en la página de Mi Cuenta que porta el token del enlace. */
	const LOGIN_QV = 'fcfdi_acceso';
	/** Meta de usuario: hash del token, su caducidad (epoch) y el último envío (epoch). */
	const META_HASH = '_fcfdi_acceso_hash';
	const META_EXP  = '_fcfdi_acceso_exp';
	const META_LAST = '_fcfdi_acceso_last';
	/** Vigencia del enlace, en segundos. */
	const TTL = 1800; // 30 min.
	/** Mínimo entre envíos de enlace por cuenta (anti email-bombing), en segundos. */
	const THROTTLE = 60;

	/**
	 * Bandera: mientras es true, se suprime el correo nativo "Cuenta nueva" de WooCommerce.
	 * Se activa solo durante nuestra creación silenciosa (no afecta otros flujos de alta).
	 *
	 * @var bool
	 */
	private static $suprimiendo_email = false;

	public static function init() {
		// 1) Cuenta silenciosa al procesar el pedido (checkout clásico y de bloques).
		//    Prioridad 5: corre ANTES de FCFDI_Cliente::guardar_perfil_de_pedido (10), para
		//    que al vincular la cuenta el perfil fiscal se guarde de forma natural.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'crear_cuenta_silenciosa' ), 5 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'crear_cuenta_silenciosa' ), 5 );

		// 2) Acceso sin contraseña.
		add_action( 'template_redirect', array( __CLASS__, 'consumir_enlace' ) );
		add_action( 'admin_post_nopriv_' . self::REQ_ACTION, array( __CLASS__, 'procesar_solicitud_acceso' ) );
		add_action( 'admin_post_' . self::REQ_ACTION, array( __CLASS__, 'procesar_solicitud_acceso' ) );
		// Formulario "envíame un enlace de acceso" sobre el login de Mi Cuenta.
		add_action( 'woocommerce_login_form_start', array( __CLASS__, 'form_acceso' ) );
		// Aviso "te enviamos un enlace" tras solicitarlo (se pinta en Mi Cuenta, no en admin-post).
		add_action( 'woocommerce_before_customer_login_form', array( __CLASS__, 'aviso_enlace_solicitado' ) );

		// 3) Aviso en "pedido recibido" cuando se creó la cuenta en silencio.
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'aviso_cuenta_creada' ) );

		// 4) Suprime el correo nativo "Cuenta nueva" de WooCommerce durante la creación
		//    silenciosa: contradice el modelo sin contraseña (habla de "pon tu contraseña")
		//    y duplica el aviso propio. Solo aplica a NUESTRAS altas, no a otras.
		add_filter( 'woocommerce_email_enabled_customer_new_account', array( __CLASS__, 'filtrar_email_cuenta_nueva' ), 99 );
	}

	/**
	 * Desactiva el correo "Cuenta nueva" mientras dura nuestra creación silenciosa.
	 *
	 * @param bool $enabled Si el correo está activo.
	 * @return bool
	 */
	public static function filtrar_email_cuenta_nueva( $enabled ) {
		if ( self::$suprimiendo_email && apply_filters( 'fcfdi_suprimir_email_cuenta_nueva', true ) ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * Crea (si hace falta) la cuenta del comprador y le vincula el pedido y sus datos.
	 *
	 * @param int $order_id Id del pedido recién procesado.
	 */
	public static function crear_cuenta_silenciosa( $order_id ) {
		/**
		 * Permite desactivar la creación automática de cuenta al comprar.
		 *
		 * @param bool $activo Default true.
		 */
		if ( ! apply_filters( 'fcfdi_crear_cuenta_silenciosa', true ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Ya hay cuenta (cliente logueado, o WooCommerce la creó): solo asegura los datos.
		if ( $order->get_user_id() ) {
			self::guardar_datos_checkout( $order->get_user_id(), $order );
			return;
		}

		$email = $order->get_billing_email();
		if ( ! $email || ! is_email( $email ) ) {
			return;
		}

		// Correo de una cuenta existente: NO vincular ni auto-loguear (ver notas de seguridad).
		if ( email_exists( $email ) ) {
			return;
		}

		self::$suprimiendo_email = true;
		$user_id = wc_create_new_customer(
			$email,
			'', // username: lo genera WooCommerce.
			'', // password: vacío -> se genera uno (cuenta sin contraseña que el cliente conozca).
			array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
			)
		);
		self::$suprimiendo_email = false;
		if ( is_wp_error( $user_id ) ) {
			return;
		}

		$order->set_customer_id( $user_id );
		// Marca para avisar en "pedido recibido" que se creó la cuenta (solo cuentas nuevas).
		$order->update_meta_data( '_fcfdi_cuenta_creada', 'si' );
		$order->save();

		self::guardar_datos_checkout( $user_id, $order );

		// Auto-login: es el propio comprador en su navegador, con una cuenta recién creada
		// (sin datos previos que proteger). Igual que WooCommerce al crear cuenta en el checkout.
		wc_set_customer_auth_cookie( $user_id );
	}

	/**
	 * Copia los datos de facturación y envío del pedido al perfil del cliente, para que la
	 * próxima compra se autocomplete. El perfil fiscal (RFC, etc.) lo guarda FCFDI_Cliente.
	 *
	 * @param int      $user_id Id de usuario.
	 * @param WC_Order $order   Pedido.
	 */
	private static function guardar_datos_checkout( $user_id, $order ) {
		$customer = new WC_Customer( $user_id );

		$campos_billing  = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email' );
		$campos_shipping = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

		foreach ( $campos_billing as $f ) {
			$get = "get_billing_{$f}";
			$set = "set_billing_{$f}";
			if ( is_callable( array( $order, $get ) ) && is_callable( array( $customer, $set ) ) ) {
				$valor = $order->$get();
				if ( '' !== (string) $valor ) {
					$customer->$set( $valor );
				}
			}
		}
		foreach ( $campos_shipping as $f ) {
			$get = "get_shipping_{$f}";
			$set = "set_shipping_{$f}";
			if ( is_callable( array( $order, $get ) ) && is_callable( array( $customer, $set ) ) ) {
				$valor = $order->$get();
				if ( '' !== (string) $valor ) {
					$customer->$set( $valor );
				}
			}
		}

		$customer->save();
	}

	/**
	 * Renderiza el formulario "acceder con mi correo" encima del login de Mi Cuenta.
	 */
	public static function form_acceso() {
		if ( is_user_logged_in() ) {
			return;
		}
		echo '<div class="fcfdi-acceso" style="margin:0 0 1.5em;padding:1em 1.25em;border:1px solid rgba(0,0,0,.12);border-radius:8px;">';
		echo '<h3 style="margin-top:0;">' . esc_html__( 'Acceder con mi correo', 'facturacionmozart-woocommerce-plugin' ) . '</h3>';
		echo '<p>' . esc_html__( 'Sin contraseñas: escribe el correo de tus compras y te enviamos un enlace de acceso.', 'facturacionmozart-woocommerce-plugin' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::REQ_ACTION ) . '">';
		wp_nonce_field( self::REQ_ACTION );
		echo '<p class="woocommerce-form-row form-row form-row-wide">';
		echo '<input type="email" name="fcfdi_email" required class="woocommerce-Input input-text" placeholder="' . esc_attr__( 'tucorreo@ejemplo.com', 'facturacionmozart-woocommerce-plugin' ) . '">';
		echo '</p>';
		echo '<p><button type="submit" class="button woocommerce-Button">' . esc_html__( 'Enviar enlace de acceso', 'facturacionmozart-woocommerce-plugin' ) . '</button></p>';
		echo '</form></div>';
	}

	/**
	 * Procesa la petición de enlace de acceso: si el correo tiene cuenta, le envía el enlace.
	 * Responde igual exista o no el correo (no filtra qué correos están registrados).
	 */
	public static function procesar_solicitud_acceso() {
		check_admin_referer( self::REQ_ACTION );

		$email  = isset( $_POST['fcfdi_email'] ) ? sanitize_email( wp_unslash( $_POST['fcfdi_email'] ) ) : '';
		$destino = wc_get_page_permalink( 'myaccount' );

		if ( $email && is_email( $email ) ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				self::enviar_enlace( $user );
			}
		}

		// OJO: este handler corre en admin-post.php (contexto admin), donde wc_add_notice()
		// NO está cargada. El aviso se pinta en Mi Cuenta vía un parámetro en la URL.
		wp_safe_redirect( add_query_arg( 'fcfdi_acceso_msg', 'enviado', $destino ) );
		exit;
	}

	/**
	 * Pinta el aviso "te enviamos un enlace" en la página de Mi Cuenta (frontend), tras
	 * volver del handler admin-post. Mensaje uniforme: no revela si el correo existe.
	 */
	public static function aviso_enlace_solicitado() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo muestra un aviso informativo.
		if ( empty( $_GET['fcfdi_acceso_msg'] ) ) {
			return;
		}
		echo '<div class="woocommerce-message" role="alert">'
			. esc_html__( 'Si ese correo tiene compras con nosotros, te enviamos un enlace de acceso. Revisa tu bandeja (y la carpeta de spam).', 'facturacionmozart-woocommerce-plugin' )
			. '</div>';
	}

	/**
	 * Genera un token de un solo uso, lo guarda hasheado con caducidad y envía el enlace.
	 *
	 * @param WP_User $user Usuario destino.
	 */
	private static function enviar_enlace( $user ) {
		// Throttle: si ya se envió un enlace hace poco, no reenvía (evita bombardear el
		// correo del cliente). El token anterior sigue vigente hasta su caducidad.
		$last = (int) get_user_meta( $user->ID, self::META_LAST, true );
		if ( $last && ( time() - $last ) < self::THROTTLE ) {
			return;
		}
		update_user_meta( $user->ID, self::META_LAST, time() );

		$token = wp_generate_password( 40, false );
		update_user_meta( $user->ID, self::META_HASH, hash( 'sha256', $token ) );
		update_user_meta( $user->ID, self::META_EXP, time() + self::TTL );

		$url = add_query_arg(
			array(
				self::LOGIN_QV => rawurlencode( $token ),
				'uid'          => $user->ID,
			),
			wc_get_page_permalink( 'myaccount' )
		);

		$minutos = (int) round( self::TTL / 60 );
		$asunto  = __( 'Tu enlace de acceso', 'facturacionmozart-woocommerce-plugin' );

		/* translators: %d: minutos de vigencia del enlace. */
		$intro = sprintf( __( 'Usa este enlace para entrar a tu cuenta y ver tus facturas (válido %d minutos, un solo uso):', 'facturacionmozart-woocommerce-plugin' ), $minutos );
		$aviso = __( 'Si no lo solicitaste, ignora este correo.', 'facturacionmozart-woocommerce-plugin' );
		$mensaje_html = '<p>' . esc_html( $intro ) . '</p>'
			. '<p><a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p>'
			. '<p>' . esc_html( $aviso ) . '</p>';

		// Entrega preferente POR EL PUENTE: usa el SMTP del emisor (el mismo con el que se
		// entrega el CFDI), de modo que el acceso del cliente no dependa del servidor de
		// correo del WordPress de la tienda. Si el puente no está configurado o falla, cae
		// a wp_mail (p.ej. el demo sin backend, o SMTP propio de la tienda).
		$enviado = false;
		if ( class_exists( 'FCFDI_Settings' ) && FCFDI_Settings::esta_configurado() && class_exists( 'FCFDI_Api_Client' ) ) {
			$client  = new FCFDI_Api_Client();
			$res     = $client->enviar_notificacion( $user->user_email, $asunto, $mensaje_html );
			$enviado = ! is_wp_error( $res ) && 200 === (int) $res['code'];
		}

		if ( ! $enviado ) {
			$enviado = wp_mail(
				$user->user_email,
				$asunto,
				$mensaje_html,
				array( 'Content-Type: text/html; charset=UTF-8' )
			);
		}

		// El acceso del cliente depende de este correo. Si el envío falla (SMTP mal
		// configurado, etc.), déjalo en el log para que el operador lo detecte: de lo
		// contrario el cliente esperaría un correo que nunca llega. Al usuario se le
		// responde igual (mensaje uniforme) para no revelar qué correos existen.
		if ( ! $enviado ) {
			error_log( '[FCFDI] No se pudo enviar el enlace de acceso a un cliente (revisa la configuración de correo/SMTP).' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			/**
			 * Se dispara cuando falla el envío del enlace de acceso. Útil para alertar al
			 * admin (correo alterno, log externo, etc.).
			 *
			 * @param int $user_id Id del usuario destinatario.
			 */
			do_action( 'fcfdi_enlace_acceso_no_enviado', $user->ID );
		}
	}

	/**
	 * Aviso en la página de "pedido recibido": informa al comprador que se le creó una
	 * cuenta y cómo entrar (sin contraseña). Solo cuando la cuenta se creó en silencio para
	 * este pedido, no para clientes que ya tenían cuenta.
	 *
	 * @param int $order_id Id del pedido.
	 */
	public static function aviso_cuenta_creada( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'si' !== $order->get_meta( '_fcfdi_cuenta_creada' ) ) {
			return;
		}
		$mi_cuenta = wc_get_page_permalink( 'myaccount' );
		echo '<div class="woocommerce-info fcfdi-aviso-cuenta">';
		printf(
			/* translators: %s: enlace a la página de Mi Cuenta. */
			wp_kses_post( __( 'Creamos una cuenta con tu correo para que consultes tus facturas cuando quieras. Para entrar, ve a %s y pide tu enlace de acceso con el mismo correo (sin contraseñas).', 'facturacionmozart-woocommerce-plugin' ) ),
			'<a href="' . esc_url( $mi_cuenta ) . '">' . esc_html__( 'Mi Cuenta', 'facturacionmozart-woocommerce-plugin' ) . '</a>'
		);
		echo '</div>';
	}

	/**
	 * Detecta el token en la página de Mi Cuenta, valida y —si procede— inicia sesión.
	 */
	public static function consumir_enlace() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- el token ES la credencial.
		if ( empty( $_GET[ self::LOGIN_QV ] ) || empty( $_GET['uid'] ) ) {
			return;
		}
		$uid   = absint( $_GET['uid'] );
		$token = sanitize_text_field( wp_unslash( $_GET[ self::LOGIN_QV ] ) );
		// phpcs:enable

		$destino_ok    = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( FCFDI_Cliente::ENDPOINT ) : wc_get_page_permalink( 'myaccount' );
		$destino_error = wc_get_page_permalink( 'myaccount' );

		if ( self::validar_token( $uid, $token ) ) {
			// Un solo uso: invalida el token antes de iniciar sesión.
			delete_user_meta( $uid, self::META_HASH );
			delete_user_meta( $uid, self::META_EXP );
			wc_set_customer_auth_cookie( $uid );
			wp_safe_redirect( $destino_ok );
			exit;
		}

		wc_add_notice(
			__( 'El enlace de acceso no es válido o ya expiró. Solicita uno nuevo.', 'facturacionmozart-woocommerce-plugin' ),
			'error'
		);
		wp_safe_redirect( $destino_error );
		exit;
	}

	/**
	 * Valida un token contra el hash y la caducidad guardados.
	 *
	 * @param int    $uid   Id de usuario.
	 * @param string $token Token en claro recibido.
	 * @return bool
	 */
	private static function validar_token( $uid, $token ) {
		if ( ! $uid || '' === $token ) {
			return false;
		}
		$hash = (string) get_user_meta( $uid, self::META_HASH, true );
		$exp  = (int) get_user_meta( $uid, self::META_EXP, true );
		if ( '' === $hash || ! $exp || time() > $exp ) {
			return false;
		}
		return hash_equals( $hash, hash( 'sha256', $token ) );
	}
}
