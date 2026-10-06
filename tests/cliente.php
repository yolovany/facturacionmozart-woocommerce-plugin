<?php
/**
 * Verificación de «Factura después de comprar»: con el ajuste marcado el cliente pide la
 * factura a su nombre desde Mi cuenta (sustitución o solicitud); sin marcar, solo quien la
 * pidió al comprar puede corregir sus datos. No llama al puente. Corre contra un WordPress
 * con WooCommerce y el plugin activos:
 *
 *   wp eval-file tests/cliente.php
 *
 * No entra en el zip del plugin (build.ps1 solo empaqueta el plugin).
 */

$fallas = 0;
$igual  = function ( $que, $esperado, $obtenido ) use ( &$fallas ) {
	$ok = $esperado === $obtenido;
	echo ( $ok ? 'ok    ' : 'FALLA ' ), $que, ' => ', var_export( $obtenido, true ), ( $ok ? '' : ' (esperado ' . var_export( $esperado, true ) . ')' ), "\n";
	$fallas += $ok ? 0 : 1;
};
$puede = function ( $nombre, $pedido ) {
	$m = new ReflectionMethod( 'FCFDI_Cliente', $nombre );
	$m->setAccessible( true );
	return $m->invoke( null, $pedido );
};
$ajuste = function ( $valor ) {
	$o = get_option( FCFDI_Settings::OPTION, array() );
	$o['facturar_despues'] = $valor;
	update_option( FCFDI_Settings::OPTION, $o );
};

$previo  = get_option( FCFDI_Settings::OPTION, array() );
$usuario = wp_insert_user( array( 'user_login' => 'prueba-cliente-' . time(), 'user_pass' => wp_generate_password(), 'user_email' => 'prueba-cliente-' . time() . '@example.com' ) );
wp_set_current_user( $usuario );
$pedido = function ( array $meta, $estado = 'processing' ) use ( $usuario ) {
	$p = wc_create_order( array( 'customer_id' => $usuario ) );
	$p->set_status( $estado );
	foreach ( $meta as $k => $v ) {
		$p->update_meta_data( $k, $v );
	}
	$p->save();
	return $p;
};
$publico   = $pedido( array( '_fcfdi_estatus' => 'timbrada', '_fcfdi_factura_id' => 'prueba-1' ) );
$sin_cfdi  = $pedido( array() );
$rechazada = $pedido( array( '_fcfdi_requiere_factura' => 'si', '_fcfdi_estatus' => 'error' ) );
// Como queda en la tienda: pagado y retenido «en espera» hasta timbrar, con los datos rechazados por el SAT.
$retenida  = $pedido( array( '_fcfdi_requiere_factura' => 'si', '_fcfdi_estatus' => 'error', '_fcfdi_error' => 'RFC_INVALIDO: nombre', '_fcfdi_retener_completado' => 'si', '_fcfdi_estatus_previo' => 'processing' ), 'on-hold' );
$sin_pagar = $pedido( array( '_fcfdi_requiere_factura' => 'si' ), 'on-hold' );
$formulario = function ( $p ) {
	ob_start();
	FCFDI_Cliente::form_solicitar( $p );
	return false !== strpos( ob_get_clean(), 'id="fcfdi-solicitar"' );
};

$ajuste( 'si' );
$igual( 'permitido: sustituir la de público en general', true, $puede( 'puede_sustituir', $publico ) );
$igual( 'permitido: formulario en el pedido', true, $formulario( $publico ) );
$igual( 'permitido: pedirla antes de timbrar', true, $puede( 'puede_solicitar', $sin_cfdi ) );

$ajuste( 'no' );
$igual( 'solo al comprar: no sustituye', false, $puede( 'puede_sustituir', $publico ) );
$igual( 'solo al comprar: sin formulario en el pedido', false, $formulario( $publico ) );
$igual( 'solo al comprar: no la pide antes de timbrar', false, $puede( 'puede_solicitar', $sin_cfdi ) );
$igual( 'solo al comprar: quien la pidió al comprar corrige sus datos', true, $puede( 'puede_solicitar', $rechazada ) );
$igual( 'retenido en espera con datos rechazados: formulario para corregirlos', true, $formulario( $retenida ) );
$igual( 'en espera sin pagar: no la pide', false, $puede( 'puede_solicitar', $sin_pagar ) );

update_option( FCFDI_Settings::OPTION, $previo );
foreach ( array( $publico, $sin_cfdi, $rechazada, $retenida, $sin_pagar ) as $p ) {
	$p->delete( true );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $usuario );

echo $fallas ? "\n$fallas falla(s)\n" : "\nTodo en orden\n";
