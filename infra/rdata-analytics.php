<?php
/**
 * Plugin Name: rData Analytics (Umami)
 * Description: Injects self-hosted Umami analytics tracking script.
 * Version: 1.0
 */

add_action('wp_head', function () {
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    // All blog variants (blog, bloggr, blogfr, blognl) share the blog UUID
    if (preg_match('/^blog/', $host)) {
        $id = '1faf3e11-797c-4733-90c3-c7f11a2592c5';
    } else {
        $id = '818b3200-081b-470d-a7e6-b1c25c367ff8';
    }
    echo '<script defer src="https://rdata.online/collect.js" data-website-id="' . esc_attr($id) . '"></script>' . "\n";
}, 1);
