<?php
/**
 * Plugin Name: Botica Serena - Marca (Demo)
 * Description: Paleta y estilos pastel de la tienda demo "Botica Serena" (tienda + checkout).
 */
add_action( 'wp_head', function () {
	?>
	<style id="bse-brand">
		:root{
			--bse-verde:#8FD9B6; --bse-verde-osc:#2F4F44; --bse-lavanda:#B8A9DE;
			--bse-crema:#FAF7F2; --bse-borde:#e9e3da; --bse-pastel:#D7F0E3;
		}
		body{ background:var(--bse-crema); color:#2c332e; }
		.wp-site-blocks{ --wp--style--global--content-size:1200px; --wp--style--global--wide-size:1280px; }
		h1,h2,h3,.wp-block-post-title,.woocommerce-loop-product__title,.wc-block-components-product-name{ color:var(--bse-verde-osc)!important; }
		a{ color:var(--bse-verde-osc); }
		a:hover{ color:var(--bse-verde); }

		/* Encabezado del sitio */
		.wp-block-site-title a{ color:var(--bse-verde-osc)!important; font-weight:800; letter-spacing:.2px; }
		.wp-block-site-tagline{ color:var(--bse-lavanda); font-style:italic; }

		/* Botones */
		.wp-element-button,.wp-block-button__link,.wc-block-components-button,.button,
		button.single_add_to_cart_button,#place_order,.wc-block-cart__submit-button,
		.wc-block-components-checkout-place-order-button,a.add_to_cart_button,a.checkout-button{
			background-color:var(--bse-verde)!important;border:0!important;color:var(--bse-verde-osc)!important;
			font-weight:700!important;border-radius:999px!important;padding:.7em 1.4em!important;box-shadow:0 2px 8px rgba(47,79,68,.15);
		}
		.wp-element-button:hover,.wp-block-button__link:hover,.wc-block-components-button:hover,
		button.single_add_to_cart_button:hover,#place_order:hover,a.add_to_cart_button:hover{
			background-color:var(--bse-verde-osc)!important;color:#fff!important;
		}

		/* Grid de tienda: tarjetas (compactas) */
		ul.products{ display:grid!important;grid-template-columns:repeat(auto-fill,minmax(170px,1fr))!important;gap:16px!important;margin:0!important; }
		ul.products li.product{ background:#fff;border:1px solid var(--bse-borde);border-radius:14px;padding:10px!important;
			box-shadow:0 4px 14px rgba(47,79,68,.06);transition:transform .15s,box-shadow .15s;text-align:center;list-style:none; }
		ul.products li.product:hover{ transform:translateY(-4px);box-shadow:0 12px 28px rgba(47,79,68,.14); }
		ul.products li.product img{ border-radius:10px!important;aspect-ratio:1/1;object-fit:cover;margin:0 auto 8px!important; }
		ul.products li.product .woocommerce-loop-product__title{ font-size:.9rem!important;padding:.15em 0!important;min-height:2.2em;line-height:1.25; }
		ul.products li.product .price{ color:var(--bse-verde-osc)!important;font-weight:800;font-size:.95rem; }
		ul.products li.product .button,ul.products li.product a.add_to_cart_button{ font-size:.82rem!important;padding:.5em 1em!important; }

		/* Barra de la tienda: "Ordenar por" (select.orderby) y conteo de resultados */
		.woocommerce-ordering select.orderby,
		.woocommerce .woocommerce-ordering select{
			-webkit-appearance:none;-moz-appearance:none;appearance:none;
			background-color:#fff;
			background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%232F4F44' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
			background-repeat:no-repeat;background-position:right 14px center;background-size:12px;
			border:1px solid var(--bse-borde);border-radius:999px;
			color:var(--bse-verde-osc);font-weight:600;line-height:1.2;
			padding:.55em 2.6em .55em 1.15em;cursor:pointer;max-width:100%;
			box-shadow:0 2px 8px rgba(47,79,68,.06);transition:border-color .15s,box-shadow .15s;
		}
		.woocommerce-ordering select.orderby:hover{ border-color:var(--bse-verde); }
		.woocommerce-ordering select.orderby:focus{ outline:none;border-color:var(--bse-verde);box-shadow:0 0 0 3px var(--bse-pastel); }
		.woocommerce .woocommerce-result-count{ color:var(--bse-lavanda);font-weight:600; }

		/* Ficha de producto */
		.woocommerce div.product div.images img{ border-radius:16px; }

		/* Campos de formulario (login, cupón, checkout clásico, mi cuenta) */
		.woocommerce form .form-row input.input-text,
		.woocommerce form .form-row textarea,
		.woocommerce .woocommerce-input-wrapper input.input-text,
		.woocommerce form select,
		.woocommerce input.input-text{
			border:1px solid var(--bse-borde);border-radius:12px;padding:.6em .9em;
			background:#fff;color:#2c332e;box-shadow:0 1px 4px rgba(47,79,68,.05);
		}
		.woocommerce form .form-row input.input-text:focus,
		.woocommerce form select:focus,
		.woocommerce input.input-text:focus{
			outline:none;border-color:var(--bse-verde);box-shadow:0 0 0 3px var(--bse-pastel);
		}

		/* Cantidad (stepper) */
		.woocommerce .quantity input.qty{
			border:1px solid var(--bse-borde);border-radius:999px;padding:.5em .4em;
			text-align:center;background:#fff;color:var(--bse-verde-osc);font-weight:700;
		}
		.woocommerce-cart .coupon{ display:flex;gap:8px;flex-wrap:wrap; }

		/* Avisos (mensaje / info / error) */
		.woocommerce-message,.woocommerce-info,.woocommerce-error{
			border-radius:14px;border:1px solid var(--bse-borde);background:#fff;
			padding:14px 16px;box-shadow:0 4px 14px rgba(47,79,68,.06);list-style:none;
		}
		.woocommerce-message{ border-left:4px solid var(--bse-verde); }
		.woocommerce-info{ border-left:4px solid var(--bse-lavanda); }
		.woocommerce-error{ border-left:4px solid #c0392b; }

		/* Pestañas de producto */
		.woocommerce div.product .woocommerce-tabs ul.tabs{ border:0;padding:0; }
		.woocommerce div.product .woocommerce-tabs ul.tabs::before{ border:0; }
		.woocommerce div.product .woocommerce-tabs ul.tabs li{
			background:#fff;border:1px solid var(--bse-borde)!important;border-radius:999px!important;
			margin:0 8px 8px 0;padding:0;
		}
		.woocommerce div.product .woocommerce-tabs ul.tabs li.active{ background:var(--bse-pastel);border-color:var(--bse-verde)!important; }
		.woocommerce div.product .woocommerce-tabs ul.tabs li::before,
		.woocommerce div.product .woocommerce-tabs ul.tabs li::after{ display:none; }
		.woocommerce div.product .woocommerce-tabs ul.tabs li a{ color:var(--bse-verde-osc)!important;font-weight:600; }

		/* Valoraciones (reviews) */
		.woocommerce #reviews h2,.woocommerce-Reviews-title,.woocommerce #reviews #comments h3,
		.woocommerce #reviews #respond .comment-reply-title{ color:var(--bse-verde-osc)!important; }
		.woocommerce #reviews #comments ol.commentlist{ list-style:none;margin:0;padding:0; }
		.woocommerce #reviews #comments ol.commentlist li{ background:#fff;border:1px solid var(--bse-borde);
			border-radius:16px;padding:16px 18px;margin:0 0 14px;box-shadow:0 4px 14px rgba(47,79,68,.05); }
		.woocommerce #reviews #comments ol.commentlist li .comment_container{ display:flex;gap:14px;align-items:flex-start; }
		.woocommerce #reviews #comments ol.commentlist li .comment-text{ border:0!important;margin:0!important;padding:0!important;flex:1;min-width:0; }
		.woocommerce #reviews #comments ol.commentlist li .comment-text .meta{ margin:0 0 6px; }
		.woocommerce #reviews #comments ol.commentlist li .comment-text .description p:last-child{ margin-bottom:0; }
		.woocommerce #reviews #comments ol.commentlist li img.avatar{ position:static!important;float:none!important;
			width:48px;height:48px;margin:0!important;border:1px solid var(--bse-borde);border-radius:50%;background:#fff;padding:2px; }
		.woocommerce #review_form_wrapper #respond,.woocommerce #reviews #respond{
			background:#fff;border:1px solid var(--bse-borde);border-radius:16px;padding:18px 20px;box-shadow:0 4px 14px rgba(47,79,68,.05); }
		.woocommerce .star-rating::before,.woocommerce p.stars a::before{ color:#d9d0e8; }
		.woocommerce .star-rating span::before{ color:var(--bse-verde-osc); }
		.woocommerce p.stars a:hover::before,.woocommerce p.stars.selected a.active::before,
		.woocommerce p.stars:hover a::before{ color:var(--bse-verde); }
		.woocommerce p.stars.selected a:not(.active)::before{ color:var(--bse-verde-osc); }
		.comment-form input[type="text"],.comment-form input[type="email"],.comment-form input[type="url"],
		.comment-form textarea,#respond input[type="text"],#respond input[type="email"],#respond textarea{
			width:100%;box-sizing:border-box;border:1px solid var(--bse-borde);border-radius:12px;
			padding:.6em .9em;background:#fff;color:#2c332e;box-shadow:0 1px 4px rgba(47,79,68,.05);font:inherit;
		}
		.comment-form input[type="text"]:focus,.comment-form input[type="email"]:focus,
		.comment-form input[type="url"]:focus,.comment-form textarea:focus,
		#respond input:focus,#respond textarea:focus{
			outline:none;border-color:var(--bse-verde);box-shadow:0 0 0 3px var(--bse-pastel);
		}
		.comment-form .comment-form-author label,.comment-form .comment-form-email label,
		.comment-form .comment-form-url label,.comment-form .comment-form-comment label{ display:block;margin-bottom:5px; }
		.comment-form .comment-form-author,.comment-form .comment-form-email,.comment-form .comment-form-url{ max-width:460px; }

		/* Migas / breadcrumb */
		.woocommerce-breadcrumb{ color:var(--bse-lavanda);font-size:.9rem;margin-bottom:16px; }
		.woocommerce-breadcrumb a{ color:var(--bse-verde-osc); }

		/* Paginación de la tienda */
		.woocommerce-pagination ul{ border:0!important;display:flex;gap:8px;justify-content:center; }
		.woocommerce-pagination ul li{ border:0!important; }
		.woocommerce-pagination ul li a,.woocommerce-pagination ul li span{
			border:1px solid var(--bse-borde)!important;border-radius:999px!important;background:#fff;
			color:var(--bse-verde-osc)!important;padding:.4em .9em!important;
		}
		.woocommerce-pagination ul li span.current{ background:var(--bse-verde)!important;color:var(--bse-verde-osc)!important; }

		/* Checkout / carrito: contener y encuadrar */
		.wc-block-checkout,.woocommerce-checkout .woocommerce,.wc-block-cart{ max-width:1100px;margin-inline:auto; }
		.wc-block-components-sidebar,.wc-block-components-order-summary,.woocommerce-checkout-review-order{
			background:#fff;border:1px solid var(--bse-borde);border-radius:16px;padding:18px; }
		.wc-block-checkout__main{ background:#fff;border:1px solid var(--bse-borde);border-radius:16px;padding:22px; }
		.wc-block-components-product-metadata img,.wc-block-cart-item__image img,td.product-thumbnail img{
			border-radius:10px;border:1px solid var(--bse-borde); }
		.wc-block-cart-item__image,.wc-block-cart-item__image img{ width:64px!important;max-width:64px!important;height:auto!important; }
		.woocommerce-cart td.product-thumbnail img{ width:64px!important;max-width:64px!important;height:auto; }
		.wc-block-cart-items__row td,.woocommerce-cart table.cart td{ vertical-align:middle; }

		/* Campos de factura destacados */
		#contact-fields, .wc-block-checkout__additional-fields{ border-top:2px dashed var(--bse-verde);padding-top:8px; }

		/* ===== Portada ===== */
		.home .wp-block-post-title,.page-id-29 .wp-block-post-title{ display:none!important; }
		.home .wp-block-post-content,.home .entry-content{ margin-block-start:0!important; }
		.home main>.wp-block-group:first-child,.home .wp-block-group.has-global-padding:first-child{ padding-top:clamp(10px,2vw,22px)!important; }
		.bse-home{ max-width:1120px;margin:0 auto; }
		.bse-home > section{ margin:0 0 clamp(34px,6vw,60px); }
		.bse-h2{ text-align:center;font-size:1.7rem;margin:0 0 22px;color:var(--bse-verde-osc)!important; }
		.bse-h2::after{ content:"";display:block;width:56px;height:4px;background:var(--bse-verde);border-radius:4px;margin:12px auto 0; }

		.bse-hero{ background:var(--bse-pastel);color:var(--bse-verde-osc);border-radius:24px;
			padding:clamp(40px,7vw,68px) 32px;text-align:center;box-shadow:0 8px 26px rgba(47,79,68,.08); }
		.bse-hero h1{ color:var(--bse-verde-osc)!important;font-size:clamp(2rem,4.5vw,3rem);line-height:1.1;margin:8px 0 12px;font-weight:800; }
		.bse-hero p{ color:#3d4b41;font-size:clamp(1rem,2vw,1.2rem);margin:0 auto 22px;max-width:620px; }
		.bse-desde,.bse-kicker{ display:inline-block;background:var(--bse-lavanda);color:#3a2f57;border-radius:999px;
			padding:5px 16px;font-size:.78rem;font-weight:700;letter-spacing:.5px;text-transform:uppercase; }
		.bse-cta{ display:inline-block;background:var(--bse-verde-osc);color:#fff!important;font-weight:800;
			border-radius:999px;padding:.85em 2em;text-decoration:none;box-shadow:0 6px 16px rgba(47,79,68,.18);transition:transform .15s; }
		.bse-cta:hover{ transform:translateY(-2px);color:#fff!important;background:#1d332b; }

		.bse-servicios{ display:grid;grid-template-columns:repeat(5,1fr);gap:12px; }
		.bse-servicios div{ display:flex;flex-direction:column;align-items:center;text-align:center;gap:8px;
			background:#fff;border:1px solid var(--bse-borde);border-radius:16px;padding:14px 10px;font-weight:600;
			font-size:.9rem;color:var(--bse-verde-osc);box-shadow:0 4px 14px rgba(47,79,68,.05);
			transition:transform .15s,box-shadow .15s; }
		.bse-servicios div:hover{ transform:translateY(-3px);box-shadow:0 12px 24px rgba(47,79,68,.12);border-color:var(--bse-verde); }
		.bse-ico{ flex:0 0 44px;width:44px;height:44px;display:grid;place-items:center;font-size:1.4rem;
			background:var(--bse-pastel);border-radius:14px;line-height:1; }
		@media (max-width:1000px){ .bse-servicios{ grid-template-columns:repeat(3,1fr); } }
		@media (max-width:620px){ .bse-servicios{ grid-template-columns:repeat(2,1fr); } }

		.bse-nosotros{ background:#fff;border:1px solid var(--bse-borde);border-radius:22px;
			padding:clamp(28px,5vw,48px);text-align:center;box-shadow:0 8px 26px rgba(47,79,68,.06); }
		.bse-nosotros .bse-kicker{ background:var(--bse-lavanda);color:#3a2f57;margin-bottom:6px; }
		.bse-nosotros p{ max-width:720px;margin:14px auto 0;color:#3c4a42;font-size:1.05rem;line-height:1.75; }

		/* Ocultar el footer genérico del tema */
		.wp-site-blocks > footer, footer.wp-block-template-part{ display:none!important; }
		html{ height:auto; }
		body{ min-height:100vh;display:flex;flex-direction:column; }
		body > .wp-site-blocks{ flex:1 0 auto; }
		.bse-footer{ flex-shrink:0;background:var(--bse-verde-osc);color:#eaf3d9;text-align:center;padding:30px 20px;margin-top:44px;line-height:1.7; }
		.bse-footer strong{ color:#fff;font-size:1.1rem; }
		.bse-footer small{ opacity:.75; }
	</style>
	<?php
} );

// Footer branded de la tienda demo (reemplaza al genérico del tema).
add_action( 'wp_footer', function () {
	?>
	<footer class="bse-footer">
		<strong>Botica Serena</strong><br>
		Bienestar natural para tu día a día.<br>
		<small>Entorno de demostración para el plugin — sin valor comercial, marca ficticia.</small>
	</footer>
	<?php
}, 99 );
