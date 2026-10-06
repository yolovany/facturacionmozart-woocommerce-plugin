<?php
/**
 * Verificación de «Días para corregir datos fiscales»: si el SAT rechaza los datos de un
 * pedido retenido, la corrección se pide sola al cliente, con recordatorio un día antes y,
 * al vencer, factura a público en general y el pedido se libera. No llama al puente (el
 * envío que se encola al vencer se desprograma). Corre contra un WordPress con WooCommerce
 * y el plugin activos y configurados:
 *
 *   wp eval-file tests/correccion.php
 *
 * No entra en el zip del plugin (build.ps1 solo empaqueta el plugin).
 */

$fallas = 0;
$ok     = function ( $cond, $que ) use ( &$fallas ) {
	echo ( $cond ? 'ok    ' : 'FALLA ' ), $que, "\n";
	$fallas += $cond ? 0 : 1;
};
$correos = array();
$correo  = function ( $nulo, $atts ) use ( &$correos ) {
	$correos[] = $atts['to'] . ' | ' . $atts['subject'] . ' | ' . $atts['message'];
	return true; // No sale.
};
$hay = function ( $texto ) use ( &$correos ) {
	return count( preg_grep( '/' . preg_quote( $texto, '/' ) . '/u', $correos ) );
};
$ajuste = function ( $valor ) {
	$o = get_option( FCFDI_Settings::OPTION, array() );
	$o['dias_correccion'] = $valor;
	update_option( FCFDI_Settings::OPTION, $o );
};
$previo = get_option( FCFDI_Settings::OPTION, array() );
add_filter( 'pre_wp_mail', $correo, 10, 2 );

// Pedido que pidió factura, retenido, con el RFC rechazado.
$retenido = function () {
	$p = wc_create_order();
	$p->add_product( wc_get_product( 15434 ), 1 );
	$p->set_billing_email( 'prueba.correccion@example.com' );
	$p->calculate_totals();
	foreach ( array(
		'_fcfdi_requiere_factura'   => 'si',
		'_fcfdi_rfc'                => 'XXX010101XXX',
		'_fcfdi_retener_completado' => 'si',
		'_fcfdi_estatus_previo'     => 'processing',
		'_fcfdi_estatus'            => 'error',
		'_fcfdi_error'              => 'RFC_INVALIDO: el RFC no está en la lista del SAT',
		'_fcfdi_factura_id'         => 'prueba-correccion',
	) as $k => $v ) {
		$p->update_meta_data( $k, $v );
	}
	$p->set_status( 'on-hold' );
	$p->save();
	return $p;
};
$programada = function ( $hook, $p ) {
	return as_next_scheduled_action( $hook, array( 'order_id' => $p->get_id(), 'desde' => (int) $p->get_meta( '_fcfdi_correccion_desde' ) ), 'facturacionmozart-woocommerce-plugin' );
};

// Sin plazo: como antes, solo el aviso al personal.
$ajuste( '' );
$a = $retenido();
FCFDI_Order_Handler::escalar_si_retenido( $a, 'RFC_INVALIDO: el RFC no está en la lista del SAT' );
$ok( ! $hay( 'Necesitamos actualizar los datos de tu factura' ) && $hay( 'Solicitar actualización de datos al cliente' ) && ! $a->get_meta( '_fcfdi_correccion_desde' ), 'sin plazo: no escribe al cliente; el personal pide la corrección' );

// Con 3 días: correo al cliente al momento, con la fecha límite; recordatorio y vencimiento programados.
$ajuste( '3' );
$correos = array();
$b       = $retenido();
FCFDI_Order_Handler::escalar_si_retenido( $b, 'RFC_INVALIDO: el RFC no está en la lista del SAT' );
$b     = wc_get_order( $b->get_id() );
$desde = (int) $b->get_meta( '_fcfdi_correccion_desde' );
$ok( 1 === $hay( 'prueba.correccion@example.com | [' . get_bloginfo( 'name' ) . '] Necesitamos actualizar' ) && $hay( 'Si no los actualizas antes del' ) && $hay( 'público en general' ), 'con plazo: el correo de corrección sale solo, con la fecha límite y lo que pasa al vencer' );
$ok( $hay( 'Ya se le pidieron por correo' ) && $hay( 'tiene 3 días' ), 'con plazo: el aviso al personal dice que ya se le pidió y el plazo' );
$ok( $desde && abs( $programada( 'fcfdi_correccion_recordar', $b ) - ( $desde + 2 * DAY_IN_SECONDS ) ) < 5 && abs( $programada( 'fcfdi_correccion_vencer', $b ) - ( $desde + 3 * DAY_IN_SECONDS ) ) < 5, 'con plazo: recordatorio a las 48 h y vencimiento a los 3 días, programados' );
FCFDI_Admin_Orders::pedir_correccion( $b );
$ok( $desde === (int) wc_get_order( $b->get_id() )->get_meta( '_fcfdi_correccion_desde' ), 'reenviar la solicitud no alarga el plazo' );

// Recordatorio: solo si sigue esperando la misma corrección.
$correos = array();
FCFDI_Admin_Orders::recordar_correccion( $b->get_id(), $desde - 1 );
$ok( ! $correos, 'recordatorio de una solicitud anterior: no sale' );
FCFDI_Admin_Orders::recordar_correccion( $b->get_id(), $desde );
$ok( 1 === count( $correos ) && $hay( 'Recordatorio: actualiza los datos de tu factura' ) && $hay( 'Si no los actualizas antes del' ), 'recordatorio: correo al cliente con la fecha límite' );

// Si el cliente corrigió (el plazo se reinicia), el vencimiento programado no hace nada.
$c = $retenido();
$c->update_meta_data( '_fcfdi_correccion_desde', 111 );
$c->save();
FCFDI_Admin_Orders::vencer_correccion( $c->get_id(), 222 );
$ok( 'on-hold' === wc_get_order( $c->get_id() )->get_status(), 'vencimiento de una solicitud ya atendida: no hace nada' );

// Al vencer: público en general, el pedido se libera y la factura se encola.
FCFDI_Admin_Orders::vencer_correccion( $b->get_id(), $desde );
$b = wc_get_order( $b->get_id() );
$ok( 'processing' === $b->get_status() && ! FCFDI_Order_Handler::requiere_factura( $b ) && 'encolada' === $b->get_meta( '_fcfdi_estatus' ) && ! $b->get_meta( '_fcfdi_correccion_desde' ), 'al vencer: se libera a Procesando y se encola la factura a público en general' );
$ok( (bool) array_filter( wc_get_order_notes( array( 'order_id' => $b->get_id() ) ), fn( $n ) => false !== strpos( $n->content, 'Venció el plazo de 3 días' ) ), 'al vencer: nota en el pedido' );

remove_filter( 'pre_wp_mail', $correo, 10 );
update_option( FCFDI_Settings::OPTION, $previo );
foreach ( array( 'fcfdi_correccion_recordar', 'fcfdi_correccion_vencer' ) as $hook ) {
	as_unschedule_all_actions( $hook, array( 'order_id' => $b->get_id(), 'desde' => $desde ), 'facturacionmozart-woocommerce-plugin' );
}
foreach ( array( $a, $b, $c ) as $p ) {
	as_unschedule_all_actions( FCFDI_Order_Handler::HOOK_ENVIAR, array( 'order_id' => $p->get_id() ), 'facturacionmozart-woocommerce-plugin' );
	wc_get_order( $p->get_id() )->delete( true );
}

echo $fallas ? "\n$fallas falla(s)\n" : "\nTodo en orden\n";
