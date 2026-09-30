<?php
/**
 * Plugin Name: UMC Image Sizes
 * Description: Sprjecava generiranje nepotrebnih velicina slika pri uploadu (Enfold + WordPress core).
 *              Velicine se samo ne generiraju; postojece se cisti skriptom unused-media-cleanup.php.
 * Version:     1.0
 *
 * Instalacija: kopiraj u wp-content/mu-plugins/ (mu-plugin se ucitava automatski, bez aktivacije).
 * Uklanjanje:  obrisi datoteku; nove slike opet dobivaju sve velicine.
 *
 * Zasto filter 'intermediate_image_sizes_advanced', a ne izmjena teme: velicine ostaju registrirane
 * (tema ne dobiva "undefined index" upozorenja), ali se za nove uploade ne generiraju. Ako neki element
 * zatrazi ispustenu velicinu, WordPress vrati punu sliku - stranica radi, samo je slika teza.
 *
 * Zadrzane velicine (provjereno crawlom zivih stranica): thumbnail/square 180, medium 300, large 1030,
 * widget 36, portfolio 495x400, gallery 845x684, featured 1500x430, masonry 705, shop_thumbnail 120,
 * shop_catalog 450x450, shop_single 450xN.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('intermediate_image_sizes_advanced', function ($sizes) {
    // Ime velicine kako je registrirana (add_image_size / WordPress core).
    $drop = apply_filters('umc_drop_image_sizes', array(
        'medium_large',          // WP core 768
        '1536x1536',             // WP core
        '2048x2048',             // WP core
        'featured_large',        // Enfold 1500x630
        'entry_with_sidebar',    // Enfold 845x321
        'entry_without_sidebar', // Enfold 1210x423
        'magazine',              // Enfold 710x375
        'portfolio_small',       // Enfold 260x185
        'extra_large',           // Enfold 1500x1500
    ));
    foreach ($drop as $name) {
        unset($sizes[$name]);
    }
    return $sizes;
}, 99);
