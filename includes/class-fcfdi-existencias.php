<?php
/**
 * Venta en línea: existencias del almacén de Mozart y movimientos de la tienda hacia Mozart.
 *
 * - Cada 5 minutos fija la existencia de cada producto (y variación) con la del almacén de venta en línea, cruzando
 *   SKU = código de barras. Lo que no cruza queda en 0 (agotado). Si el puente o Mozart no responden, no toca nada.
 * - Lo que WooCommerce descuenta (al apartar el pedido) sale de Mozart como salida; lo que regresa (pedido cancelado o
 *   reembolso con «Reponer existencias») entra como devolución. Se manda al terminar la petición y lo que falle se
 *   reintenta en cada sincronía.
 * - Solo actúa si el emisor tiene almacén de venta en línea en el puente (si responde 404, la tienda no cambia).
 *
 * Diseño: FacturacionMozart docs/arquitectura/06-sincronia-mozart.md.
 *
 * @package FacturacionCFDI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCFDI_Existencias {

	const CRON_HOOK  = 'fcfdi_sincronizar_existencias';
	const HOOK_ENVIO = 'fcfdi_enviar_movimientos_mozart';
	const OPTION     = 'fcfdi_existencias';
	const PENDIENTES = 'fcfdi_existencias_pendientes';
	const META_MOVS  = '_fcfdi_movimientos_mozart';
	const SIN_SYNC   = HOUR_IN_SECONDS;

	/** @var array "order_id|tipo" => índice del movimiento abierto en esta petición (un movimiento por pedido y tipo). */
	private static $abiertos = array();

	/** @var array order_id => true: pedidos con movimientos nuevos que mandar al terminar la petición. */
	private static $por_enviar = array();

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'intervalo' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'sincronizar' ) );
		add_action( self::HOOK_ENVIO, array( __CLASS__, 'enviar_pedido' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'fcfdi_cada_5_min', self::CRON_HOOK );
		}

		add_action( 'woocommerce_reduce_order_item_stock', array( __CLASS__, 'al_descontar' ), 10, 3 );
		add_action( 'woocommerce_restore_order_item_stock', array( __CLASS__, 'al_restaurar' ), 10, 4 );
		add_action( 'woocommerce_restock_refunded_item', array( __CLASS__, 'al_reponer' ), 10, 5 );
		add_action( 'shutdown', array( __CLASS__, 'programar_envios' ) );
	}

	public static function intervalo( $schedules ) {
		$schedules['fcfdi_cada_5_min'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Cada 5 minutos (FacturacionMozart)', 'facturacionmozart-woocommerce-plugin' ),
		);
		return $schedules;
	}

	public static function desactivar() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function estado() {
		$estado = get_option( self::OPTION, array() );
		return is_array( $estado ) ? $estado : array();
	}

	/** La función está activa: el puente respondió existencias al menos una vez y no ha dicho 404 después. */
	public static function activa() {
		$estado = self::estado();
		return ! empty( $estado['activa'] );
	}

	/**
	 * Segundos desde la última sincronía buena, o 0 si no aplica (inactiva o al día).
	 */
	public static function sin_sincronia() {
		$estado = self::estado();
		if ( empty( $estado['activa'] ) || empty( $estado['ultima_ok'] ) ) {
			return 0;
		}
		$atraso = time() - (int) $estado['ultima_ok'];
		return $atraso > self::SIN_SYNC ? $atraso : 0;
	}

	// ---- Movimientos de la tienda hacia Mozart ------------------------------------------------------------------

	public static function al_descontar( $item, $change, $order ) {
		$cantidad = isset( $change['from'], $change['to'] ) ? (float) $change['from'] - (float) $change['to'] : 0;
		self::acumular( $order, 'salida', isset( $change['product'] ) ? $change['product'] : null, $cantidad );
	}

	public static function al_restaurar( $item, $new_stock, $old_stock, $order ) {
		self::acumular( $order, 'devolucion', $item->get_product(), (float) $new_stock - (float) $old_stock );
	}

	public static function al_reponer( $product_id, $old_stock, $new_stock, $order, $product ) {
		self::acumular( $order, 'devolucion', $product, (float) $new_stock - (float) $old_stock );
	}

	/**
	 * Guarda el movimiento en el pedido y lo marca pendiente en el mismo instante en que WooCommerce mueve su
	 * inventario: así una sincronía que corra a la par ya lo descuenta (ver ajuste_pendiente).
	 */
	private static function acumular( $order, $tipo, $product, $cantidad ) {
		if ( ! self::activa() || ! $order || ! $product || $cantidad <= 0 ) {
			return;
		}
		$sku = trim( (string) $product->get_sku() );
		if ( '' === $sku ) {
			return; // Sin SKU no hay artículo en Mozart (y la sincronía lo deja agotado).
		}
		$order_id = $order->get_id();
		$movs     = self::movimientos( $order );
		$clave    = $order_id . '|' . $tipo;
		if ( ! isset( self::$abiertos[ $clave ] ) ) {
			$n      = 1 + count( wp_list_filter( $movs, array( 'tipo' => $tipo ) ) );
			$movs[] = array(
				'tipo'      => $tipo,
				// Salidas: WEB-{pedido}, WEB-{pedido}-2…; devoluciones: WEB-{pedido}-D1, -D2…
				'ref'       => 'salida' === $tipo ? ( 1 === $n ? (string) $order_id : $order_id . '-' . $n ) : (string) $n,
				'renglones' => array(),
				'estado'    => 'pendiente',
			);
			self::$abiertos[ $clave ] = count( $movs ) - 1;
		}
		$i = self::$abiertos[ $clave ];
		$movs[ $i ]['renglones'][ $sku ] = ( isset( $movs[ $i ]['renglones'][ $sku ] ) ? $movs[ $i ]['renglones'][ $sku ] : 0 ) + $cantidad;

		$order->update_meta_data( self::META_MOVS, $movs );
		$order->save_meta_data();
		self::marcar_pendiente( $order_id, true );
		self::$por_enviar[ $order_id ] = true;
	}

	/**
	 * Al terminar la petición se programa el envío: una caída de Mozart no frena el pago. Si no hay Action Scheduler,
	 * lo manda la siguiente sincronía.
	 */
	public static function programar_envios() {
		foreach ( array_keys( self::$por_enviar ) as $order_id ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::HOOK_ENVIO, array( 'order_id' => $order_id ), 'facturacionmozart-woocommerce-plugin' );
			}
		}
		self::$abiertos   = array();
		self::$por_enviar = array();
	}

	private static function movimientos( $order ) {
		$movs = $order->get_meta( self::META_MOVS, true );
		return is_array( $movs ) ? $movs : array();
	}

	private static function marcar_pendiente( $order_id, $pendiente ) {
		$lista = get_option( self::PENDIENTES, array() );
		$lista = is_array( $lista ) ? $lista : array();
		if ( $pendiente ) {
			$lista[ $order_id ] = time();
		} else {
			unset( $lista[ $order_id ] );
		}
		update_option( self::PENDIENTES, $lista, false );
	}

	/**
	 * Manda los movimientos pendientes del pedido. Los que Mozart rechaza (SKU sin artículo) quedan en error con nota
	 * en el pedido; los que no llegan se reintentan en la siguiente sincronía.
	 */
	public static function enviar_pedido( $order_id ) {
		$order = FCFDI_Order_Handler::pedido_fresco( $order_id );
		if ( ! $order ) {
			self::marcar_pendiente( $order_id, false );
			return;
		}
		$client     = new FCFDI_Api_Client();
		$resultados = array();
		foreach ( self::movimientos( $order ) as $i => $mov ) {
			if ( 'pendiente' !== $mov['estado'] ) {
				continue;
			}
			$ruta = 'salida' === $mov['tipo'] ? 'salidas/' . $mov['ref'] : 'salidas/' . $order_id . '/devoluciones/' . $mov['ref'];
			$res  = $client->movimiento_inventario( $ruta, $mov['renglones'] );
			$code = is_wp_error( $res ) ? 0 : (int) $res['code'];
			if ( 200 === $code ) {
				$resultados[ $i ] = array( $mov, $res['body']['resultado'], '' );
				$order->add_order_note( sprintf(
					/* translators: 1: tipo de movimiento, 2: referencia en Mozart. */
					__( 'Mozart: %1$s %2$s registrada.', 'facturacionmozart-woocommerce-plugin' ),
					'salida' === $mov['tipo'] ? __( 'salida', 'facturacionmozart-woocommerce-plugin' ) : __( 'entrada por devolución', 'facturacionmozart-woocommerce-plugin' ),
					$res['body']['referencia']
				) );
			} elseif ( in_array( $code, array( 400, 404, 422 ), true ) ) {
				$mensaje          = isset( $res['body']['mensaje'] ) ? $res['body']['mensaje'] : (string) $code;
				$resultados[ $i ] = array( $mov, 'error', $mensaje );
				$order->add_order_note( sprintf(
					/* translators: %s: mensaje del puente. */
					__( 'Mozart no registró el movimiento de inventario de este pedido: %s Hay que hacerlo a mano en Mozart.', 'facturacionmozart-woocommerce-plugin' ),
					$mensaje
				) );
			}
			// Otro código: puente o Mozart sin respuesta; sigue pendiente y se reintenta.
		}

		// Se relee antes de guardar: otra petición pudo agregar un movimiento (p. ej. una cancelación) mientras se mandaba.
		$order = FCFDI_Order_Handler::pedido_fresco( $order_id );
		$movs  = self::movimientos( $order );
		foreach ( $resultados as $i => list( $mov, $estado, $error ) ) {
			if ( isset( $movs[ $i ] ) && $movs[ $i ]['tipo'] === $mov['tipo'] && $movs[ $i ]['ref'] === $mov['ref'] ) {
				$movs[ $i ]['estado'] = $estado;
				if ( $error ) {
					$movs[ $i ]['error'] = $error;
				}
			}
		}
		$order->update_meta_data( self::META_MOVS, $movs );
		$order->save_meta_data();
		self::marcar_pendiente( $order_id, (bool) wp_list_filter( $movs, array( 'estado' => 'pendiente' ) ) );
	}

	/**
	 * Cantidad por SKU que la tienda ya movió y Mozart todavía no: salidas restan, devoluciones suman.
	 */
	private static function ajuste_pendiente() {
		$ajuste = array();
		foreach ( array_keys( (array) get_option( self::PENDIENTES, array() ) ) as $order_id ) {
			$order = FCFDI_Order_Handler::pedido_fresco( $order_id );
			if ( ! $order ) {
				continue;
			}
			foreach ( self::movimientos( $order ) as $mov ) {
				if ( 'pendiente' !== $mov['estado'] ) {
					continue;
				}
				foreach ( $mov['renglones'] as $sku => $cantidad ) {
					$ajuste[ $sku ] = ( isset( $ajuste[ $sku ] ) ? $ajuste[ $sku ] : 0 ) + ( 'salida' === $mov['tipo'] ? -$cantidad : $cantidad );
				}
			}
		}
		return $ajuste;
	}

	/**
	 * SKUs de los pedidos que quedaron pendientes después de $antes. Se lee de la base y no del caché de opciones ni del
	 * de pedidos, que no ven lo que otra petición acaba de guardar.
	 * ponytail: una consulta (y la relectura de cada pedido nuevo) por producto; si el catálogo crece a miles, leerla cada N
	 * productos.
	 */
	private static function skus_nuevos( $antes ) {
		global $wpdb;
		$lista = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::PENDIENTES ) ) );
		$skus  = array();
		foreach ( array_diff( array_keys( is_array( $lista ) ? $lista : array() ), $antes ) as $order_id ) {
			$order = FCFDI_Order_Handler::pedido_fresco( $order_id );
			foreach ( $order ? self::movimientos( $order ) : array() as $mov ) {
				$skus += array_fill_keys( array_keys( $mov['renglones'] ), true );
			}
		}
		return $skus;
	}

	// ---- Sincronía de existencias -------------------------------------------------------------------------------

	public static function sincronizar() {
		if ( ! FCFDI_Settings::esta_configurado() ) {
			return;
		}
		$estado = self::estado();

		// Primero lo que la tienda debe a Mozart: así la existencia que llega ya lo incluye.
		foreach ( array_keys( (array) get_option( self::PENDIENTES, array() ) ) as $order_id ) {
			self::enviar_pedido( $order_id );
		}

		// Pendientes antes que Mozart: si una salida se registra entre las dos lecturas, cuenta doble y la tienda queda
		// abajo hasta la siguiente sincronía (nunca arriba, que sería vender lo que no hay).
		$antes  = array_keys( (array) get_option( self::PENDIENTES, array() ) );
		$ajuste = self::ajuste_pendiente();
		$res    = ( new FCFDI_Api_Client() )->existencias();
		$code   = is_wp_error( $res ) ? 0 : (int) $res['code'];
		$estado['ultimo_intento'] = time();

		if ( 404 === $code ) {
			$estado['activa'] = false; // El emisor no tiene almacén en línea: la tienda maneja su inventario como siempre.
			$estado['error']  = '';
			update_option( self::OPTION, $estado, false );
			return;
		}
		if ( 200 !== $code || ! isset( $res['body']['existencias'] ) || ! is_array( $res['body']['existencias'] ) ) {
			$estado['error'] = is_wp_error( $res ) ? $res->get_error_message() : ( isset( $res['body']['mensaje'] ) ? $res['body']['mensaje'] : 'HTTP ' . $code );
			update_option( self::OPTION, $estado, false ); // Sin respuesta: no se toca ninguna existencia.
			return;
		}

		$mozart = array();
		foreach ( $res['body']['existencias'] as $fila ) {
			if ( isset( $fila['sku'], $fila['existencia'] ) ) {
				$mozart[ trim( (string) $fila['sku'] ) ] = (float) $fila['existencia'];
			}
		}
		$sin_sku     = array();
		$fuera       = array();
		$cambiados   = 0;
		$productos   = wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'private', 'draft' ), 'type' => array( 'simple', 'variation' ), 'return' => 'ids' ) );
		foreach ( $productos as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$sku = trim( (string) $product->get_sku() );
			if ( '' === $sku ) {
				$sin_sku[] = $id;
			} elseif ( ! isset( $mozart[ $sku ] ) ) {
				$fuera[] = $id;
			}
			$cantidad = '' !== $sku && isset( $mozart[ $sku ] ) ? $mozart[ $sku ] + ( isset( $ajuste[ $sku ] ) ? $ajuste[ $sku ] : 0 ) : 0;
			$cantidad = max( 0, (int) floor( $cantidad ) ); // Mozart puede traer decimales o negativos; la tienda vende piezas.

			if ( ! $product->get_manage_stock() || 'no' !== $product->get_backorders() || null === $product->get_stock_quantity() ) {
				$product->set_manage_stock( true );
				$product->set_backorders( 'no' );
				if ( null === $product->get_stock_quantity() ) {
					$product->set_stock_quantity( 0 ); // Un _stock vacío no admite la suma atómica (MySQL estricto la rechaza).
				}
				$product->save();
			}
			// Vendido (o repuesto) mientras corría esta sincronía: el ajuste ya no lo incluye y el producto sí; se deja
			// para la siguiente, si no la venta se «devuelve» a la tienda.
			if ( '' !== $sku && isset( self::skus_nuevos( $antes )[ $sku ] ) ) {
				continue;
			}
			// Suma o resta atómica (no 'set'): una venta que ocurra a la par no se pierde.
			$diferencia = $cantidad - (int) $product->get_stock_quantity();
			if ( 0 !== $diferencia ) {
				wc_update_product_stock( $product, abs( $diferencia ), $diferencia > 0 ? 'increase' : 'decrease' );
				$cambiados++;
			}
		}

		$estado = array_merge(
			$estado,
			array(
				'activa'    => true,
				'ultima_ok' => time(),
				'error'     => '',
				'almacen'   => isset( $res['body']['almacen'] ) ? $res['body']['almacen'] : '',
				'en_mozart' => count( $mozart ),
				'cambiados' => $cambiados,
				'sin_sku'   => $sin_sku,
				'fuera'     => $fuera,
			)
		);
		update_option( self::OPTION, $estado, false );
	}

	// ---- Panel --------------------------------------------------------------------------------------------------

	public static function render_estado() {
		$estado = self::estado();
		if ( empty( $estado['activa'] ) ) {
			return;
		}
		$fecha = function ( $ts ) {
			return $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '—';
		};
		$lista = function ( $ids ) {
			$enlaces = array();
			foreach ( array_slice( (array) $ids, 0, 200 ) as $id ) {
				$p = wc_get_product( $id );
				if ( $p ) {
					$enlaces[] = '<a href="' . esc_url( get_edit_post_link( $p->get_parent_id() ? $p->get_parent_id() : $id ) ) . '">' . esc_html( $p->get_name() ) . '</a>';
				}
			}
			return implode( ', ', $enlaces );
		};
		?>
		<h2><?php esc_html_e( 'Existencias de Mozart', 'facturacionmozart-woocommerce-plugin' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: almacén, 2: fecha de la última sincronía, 3: códigos en Mozart. */
				esc_html__( 'Almacén %1$s. Última sincronía: %2$s (%3$d códigos en el almacén).', 'facturacionmozart-woocommerce-plugin' ),
				esc_html( isset( $estado['almacen'] ) ? $estado['almacen'] : '' ),
				esc_html( $fecha( isset( $estado['ultima_ok'] ) ? $estado['ultima_ok'] : 0 ) ),
				(int) ( isset( $estado['en_mozart'] ) ? $estado['en_mozart'] : 0 )
			);
			if ( ! empty( $estado['error'] ) ) {
				echo ' <strong>' . esc_html__( 'Último intento fallido:', 'facturacionmozart-woocommerce-plugin' ) . '</strong> ' . esc_html( $estado['error'] );
			}
			?>
		</p>
		<?php if ( ! empty( $estado['sin_sku'] ) ) : ?>
			<details><summary>
				<?php
				/* translators: %d: número de productos. */
				printf( esc_html__( '%d productos sin SKU: quedan agotados. Captura su código de barras como SKU.', 'facturacionmozart-woocommerce-plugin' ), count( $estado['sin_sku'] ) );
				?>
			</summary><p><?php echo wp_kses_post( $lista( $estado['sin_sku'] ) ); ?></p></details>
		<?php endif; ?>
		<?php if ( ! empty( $estado['fuera'] ) ) : ?>
			<details><summary>
				<?php
				/* translators: %d: número de productos. */
				printf( esc_html__( '%d productos con SKU que no está en el almacén: quedan agotados.', 'facturacionmozart-woocommerce-plugin' ), count( $estado['fuera'] ) );
				?>
			</summary><p><?php echo wp_kses_post( $lista( $estado['fuera'] ) ); ?></p></details>
		<?php endif; ?>
		<?php
	}
}
