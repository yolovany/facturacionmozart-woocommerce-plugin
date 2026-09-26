<?php
/**
 * Verificación de la lógica fiscal del payload (forma de pago, tasa 0 %, bases con IVA
 * incluido). Corre contra un WordPress con WooCommerce y el plugin activos:
 *
 *   wp eval-file tests/fiscal.php
 *
 * No entra en el zip del plugin (build.ps1 solo empaqueta el plugin).
 */

$metodo = function ( $nombre ) {
	$m = new ReflectionMethod( 'FCFDI_Order_Handler', $nombre );
	$m->setAccessible( true );
	return $m;
};
$fallas = 0;
$igual  = function ( $que, $esperado, $obtenido ) use ( &$fallas ) {
	$ok = is_float( $esperado ) ? abs( $esperado - $obtenido ) < 0.0000005 : $esperado === $obtenido;
	echo ( $ok ? 'ok    ' : 'FALLA ' ), $que, ' => ', var_export( $obtenido, true ), ( $ok ? '' : ' (esperado ' . var_export( $esperado, true ) . ')' ), "\n";
	$fallas += $ok ? 0 : 1;
};

// Forma de pago según pasarela.
$forma = $metodo( 'forma_pago_sat' );
foreach ( array(
	array( 'woo-mercado-pago-custom', 'Mercado Pago - Payment 1', '[Payment Type credit_card]', '04' ),
	array( 'woo-mercado-pago-custom', 'Mercado Pago - Payment 2', '[Payment Type debit_card]', '28' ),
	array( 'stripe', '_stripe_card_funding', 'debit', '28' ),
	array( 'stripe', '', '', '04' ),
	array( 'woo-mercado-pago-ticket', '', '', '01' ),
	array( 'woo-mercado-pago-ticket', 'Mercado Pago - Payment 3', '[Payment Type ticket]/[Payment Method oxxo]', '01' ),
	array( 'woo-mercado-pago-ticket', 'Mercado Pago - Payment 4', '[Payment Type bank_transfer]/[Payment Method clabe]', '03' ),
	array( 'bacs', '', '', '03' ),
	array( 'otra', '', '', '99' ),
) as list( $pasarela, $clave, $valor, $esperado ) ) {
	$pedido = new WC_Order();
	$pedido->set_payment_method( $pasarela );
	if ( $clave ) {
		$pedido->update_meta_data( $clave, $valor );
	}
	$igual( "forma de pago $pasarela $valor", $esperado, $forma->invoke( null, $pedido ) );
}

// Tasa 0 %: objeto de impuesto con IVA 0, no "no objeto".
$impuesto = $metodo( 'impuesto_cfdi' );
$tasas    = WC_Tax::find_rates( array( 'country' => 'MX' ) ) + WC_Tax::find_rates( array( 'country' => 'MX', 'tax_class' => 'tasa-cero' ) );
foreach ( $tasas as $id => $tasa ) {
	$r = $impuesto->invoke( null, array( 'total' => array( $id => '0' ) ), 0 );
	$igual( "tasa {$tasa['rate']} es objeto de impuesto", true, is_array( $r ) );
}
$igual( 'sin tasa aplicada es "no objeto"', null, $impuesto->invoke( null, array( 'total' => array() ), 0 ) );

// Bases con IVA incluido: el CFDI debe sumar lo cobrado.
$base   = $metodo( 'base_cfdi' );
$previo = get_option( 'woocommerce_prices_include_tax' );
update_option( 'woocommerce_prices_include_tax', 'yes' );
$igual( 'base de $30 con IVA', 25.862069, $base->invoke( null, 25.86, 4.14, 0.16 ) );
$igual( 'base del envío $199 (base redondeada, IVA sin redondear)', 171.551724, $base->invoke( null, 171.55, 27.448276, 0.16 ) );
$igual( 'tasa 0: la base es el neto', 250.0, $base->invoke( null, 250, 0, 0.0 ) );
update_option( 'woocommerce_prices_include_tax', $previo );

echo $fallas ? "\n$fallas falla(s)\n" : "\nTodo en orden\n";
