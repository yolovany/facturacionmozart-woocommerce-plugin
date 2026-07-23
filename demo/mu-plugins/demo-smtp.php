<?php
/**
 * Plugin Name: Botica Serena - Correo de prueba (Demo)
 * Description: Enruta el correo saliente de WordPress a un buzón SMTP de prueba (Mailpit),
 *              para que en el demo se pueda ver y probar el "enlace de acceso" passwordless
 *              y demás correos. Solo actúa si está definida la constante FCFDI_DEMO_SMTP_HOST
 *              (la define el docker-compose.demo.yml); así el entorno de desarrollo, que no
 *              trae Mailpit, no se ve afectado.
 *
 * @package FacturacionCFDI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'FCFDI_DEMO_SMTP_HOST' ) || ! FCFDI_DEMO_SMTP_HOST ) {
	return; // Sin buzón de prueba configurado: no tocar el correo (p.ej. entorno de desarrollo).
}

add_action(
	'phpmailer_init',
	function ( $phpmailer ) {
		$phpmailer->isSMTP();
		$phpmailer->Host        = FCFDI_DEMO_SMTP_HOST;
		$phpmailer->Port        = defined( 'FCFDI_DEMO_SMTP_PORT' ) ? (int) FCFDI_DEMO_SMTP_PORT : 1025;
		$phpmailer->SMTPAuth    = false;
		$phpmailer->SMTPAutoTLS = false; // Mailpit escucha en texto plano.
	}
);

// Remitente estable y con dominio de la tienda demo (evita que el correo caiga por From vacío).
add_filter( 'wp_mail_from', function ( $from ) {
	return ( ! $from || strpos( $from, 'wordpress@' ) === 0 ) ? 'demo@botica-serena.test' : $from;
} );
add_filter( 'wp_mail_from_name', function ( $name ) {
	return 'Botica Serena (demo)';
} );
