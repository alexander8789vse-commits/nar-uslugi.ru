<?php
/**
 * Astra Child Theme functions and definitions
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package Astra Child
 * @since 1.0.0
 */

/**
 * Define Constants
 */
define( 'CHILD_THEME_ASTRA_CHILD_VERSION', '1.0.0' );

/**
 * Enqueue styles
 */
function child_enqueue_styles() {

	wp_enqueue_style( 'astra-child-theme-css', get_stylesheet_directory_uri() . '/style.css', array('astra-theme-css'), CHILD_THEME_ASTRA_CHILD_VERSION, 'all' );

}

add_action( 'wp_enqueue_scripts', 'child_enqueue_styles', 15 );

add_action( 'astra_content_before', function () {
	echo '<div style="background:#D97757;text-align:center;padding:0.6rem 1rem;font-weight:700;font-size:1rem;letter-spacing:0.05em;width:100%;"><a href="https://xn--80agceqqkchtpxc1i.xn--p1ai/novosti-afisha/" style="color:#fff;text-decoration:none;">ВНИМАНИЕ! ВНИМАНИЕ! ВНИМАНИЕ! БИТВА БАРМЕНОВ!</a></div>';
} );