<?php
/**
 * Activation / deactivation.
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Activator
 */
class Umi_Activator {

	/**
	 * Activate plugin.
	 */
	public static function activate() {
		require_once UMI_MP_PATH . 'includes/class-umi-database.php';
		Umi_Database::install();
		require_once UMI_MP_PATH . 'includes/class-umi-roles.php';
		Umi_Roles::install_roles();
		require_once UMI_MP_PATH . 'includes/class-umi-settings.php';
		if ( false === get_option( Umi_Settings::OPTION_KEY, false ) ) {
			add_option( Umi_Settings::OPTION_KEY, Umi_Settings::defaults() );
		}
		flush_rewrite_rules();
	}

	/**
	 * Deactivate plugin.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
