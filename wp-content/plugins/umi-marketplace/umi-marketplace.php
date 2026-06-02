<?php
/**
 * Plugin Name:       UMI Marketplace
 * Description:       Услуги, товары, доли, сделки, чат, модерация (маркетплейс).
 * Version:           1.0.5
 * Requires at least: 6.0
 * Requires PHP:       7.4
 * Author:            UMI
 * Text Domain:       umi-marketplace
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

define( 'UMI_MP_VERSION', '1.0.5' );
define( 'UMI_MP_PATH', plugin_dir_path( __FILE__ ) );
define( 'UMI_MP_URL', plugin_dir_url( __FILE__ ) );
define( 'UMI_MP_BASENAME', plugin_basename( __FILE__ ) );

require_once UMI_MP_PATH . 'includes/class-umi-activator.php';
require_once UMI_MP_PATH . 'includes/class-umi-marketplace.php';

register_activation_hook( __FILE__, array( 'Umi_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Umi_Activator', 'deactivate' ) );

Umi_Marketplace::instance();
