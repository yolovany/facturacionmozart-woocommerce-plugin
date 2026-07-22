<?php
/**
 * Genera imágenes de producto con la marca ficticia Botica Serena (GD) y las
 * asigna como imagen destacada. Ejecutar: wp eval-file make-images.php
 */

// Resuelve fuentes multiplataforma: Arial en Windows, DejaVu/Liberation en Linux (Docker).
$bse_pick_font = static function ( array $candidatos ) {
	foreach ( $candidatos as $f ) {
		if ( file_exists( $f ) ) { return $f; }
	}
	return $candidatos[0]; // último recurso; GD avisará si no existe.
};
$fontBold = $bse_pick_font( array(
	'C:/Windows/Fonts/arialbd.ttf',
	'C:/Windows/Fonts/Arialbd.ttf',
	'/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
	'/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
	'/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
) );
$fontReg = $bse_pick_font( array(
	'C:/Windows/Fonts/arial.ttf',
	'C:/Windows/Fonts/Arial.ttf',
	'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
	'/usr/share/fonts/dejavu/DejaVuSans.ttf',
	'/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
) );

// Paletas pastel (arriba -> abajo): mezcla de verde menta y lavanda suave.
$palettes = array(
	array( array(143,217,182), array(47,79,68) ),
	array( array(184,169,222), array(58,52,84) ),
	array( array(167,214,196), array(47,79,68) ),
	array( array(143,217,182), array(58,52,84) ),
	array( array(200,180,225), array(47,79,68) ),
	array( array(160,210,190), array(47,79,68) ),
);

function bse_center_text( $img, $font, $size, $y, $text, $color, $w ) {
	$bbox = imagettfbbox( $size, 0, $font, $text );
	$tw   = $bbox[2] - $bbox[0];
	$x    = (int) ( ( $w - $tw ) / 2 );
	imagettftext( $img, $size, 0, $x, $y, $color, $font, $text );
}

function bse_wrap( $font, $size, $text, $maxw ) {
	$words = explode( ' ', $text );
	$lines = array();
	$cur   = '';
	foreach ( $words as $word ) {
		$try  = trim( $cur . ' ' . $word );
		$bbox = imagettfbbox( $size, 0, $font, $try );
		if ( ( $bbox[2] - $bbox[0] ) > $maxw && $cur !== '' ) {
			$lines[] = $cur;
			$cur     = $word;
		} else {
			$cur = $try;
		}
	}
	if ( $cur !== '' ) { $lines[] = $cur; }
	return $lines;
}

$products = get_posts( array( 'post_type' => 'product', 'numberposts' => -1, 'post_status' => 'publish' ) );
$upload   = wp_upload_dir();
$i        = 0;

foreach ( $products as $p ) {
	$pal = $palettes[ $i % count( $palettes ) ];
	$i++;
	$w = 700; $h = 700;
	$img = imagecreatetruecolor( $w, $h );

	// Degradado vertical.
	for ( $y = 0; $y < $h; $y++ ) {
		$t = $y / $h;
		$r = (int) ( $pal[0][0] + ( $pal[1][0] - $pal[0][0] ) * $t );
		$g = (int) ( $pal[0][1] + ( $pal[1][1] - $pal[0][1] ) * $t );
		$b = (int) ( $pal[0][2] + ( $pal[1][2] - $pal[0][2] ) * $t );
		imageline( $img, 0, $y, $w, $y, imagecolorallocate( $img, $r, $g, $b ) );
	}

	$white  = imagecolorallocate( $img, 255, 255, 255 );
	$soft   = imagecolorallocatealpha( $img, 255, 255, 255, 100 );
	$soft2  = imagecolorallocatealpha( $img, 255, 255, 255, 118 );

	// Círculos decorativos (badge).
	imagefilledellipse( $img, $w / 2, 250, 300, 300, $soft2 );
	imagefilledellipse( $img, $w / 2, 250, 210, 210, $soft );

	// Hoja simple (dos elipses) dentro del badge.
	$leaf = imagecolorallocate( $img, 255, 255, 255 );
	imagefilledellipse( $img, $w / 2 - 22, 250, 70, 130, $leaf );
	imagefilledellipse( $img, $w / 2 + 22, 250, 70, 130, $leaf );
	$g2 = imagecolorallocate( $img, $pal[1][0], $pal[1][1], $pal[1][2] );
	imagesetthickness( $img, 4 );
	imageline( $img, $w / 2, 190, $w / 2, 310, $g2 );

	// Nombre del producto (wrap, centrado).
	$lines = bse_wrap( $fontBold, 30, $p->post_title, $w - 120 );
	$y = 470;
	foreach ( $lines as $ln ) {
		bse_center_text( $img, $fontBold, 30, $y, $ln, $white, $w );
		$y += 44;
	}

	// Marca.
	bse_center_text( $img, $fontReg, 15, 640, 'BOTICA SERENA', $soft, $w );

	// Guardar en uploads y adjuntar.
	$slug = sanitize_title( $p->post_title );
	$file = trailingslashit( $upload['path'] ) . 'bse-' . $slug . '.png';
	imagepng( $img, $file );
	imagedestroy( $img );

	$filetype = wp_check_filetype( basename( $file ), null );
	$attach   = array(
		'guid'           => trailingslashit( $upload['url'] ) . basename( $file ),
		'post_mime_type' => $filetype['type'],
		'post_title'     => $p->post_title,
		'post_content'   => '',
		'post_status'    => 'inherit',
	);
	$attach_id = wp_insert_attachment( $attach, $file, $p->ID );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$meta = wp_generate_attachment_metadata( $attach_id, $file );
	wp_update_attachment_metadata( $attach_id, $meta );
	set_post_thumbnail( $p->ID, $attach_id );

	echo "img -> {$p->post_title} (att {$attach_id})\n";
}
echo "listo\n";
