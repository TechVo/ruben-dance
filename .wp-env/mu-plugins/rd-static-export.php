<?php
/**
 * Plugin Name: RD static export helper (temporary)
 * Description: Strips ?ver= query strings from asset URLs so a wget crawl can
 *              mirror the site into plain static files. Dev-only, temporary.
 */

add_filter( 'script_loader_src', 'rd_static_strip_ver', 9999 );
add_filter( 'style_loader_src', 'rd_static_strip_ver', 9999 );

function rd_static_strip_ver( $src ) {
	return remove_query_arg( 'ver', $src );
}

// Emoji script/styles add nothing to a static mirror.
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
