<?php
/**
 * Main plugin class.
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Marketplace
 */
class Umi_Marketplace {

	/**
	 * Instance.
	 *
	 * @var Umi_Marketplace|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Umi_Marketplace
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Umi_Marketplace constructor.
	 */
	private function __construct() {
		$this->includes();
		add_action( 'init', array( $this, 'init' ), 5 );
		add_action( 'plugins_loaded', array( $this, 'db_maybe' ), 1 );
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'user_register', array( 'Umi_Roles', 'set_default_role' ), 10, 1 );
	}

	/**
	 * DB updates without re-activation.
	 */
	public function db_maybe() {
		Umi_Database::maybe_install();
	}

	/**
	 * Переводы.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'umi-marketplace', false, dirname( UMI_MP_BASENAME ) . '/languages' );
	}

	/**
	 * Load files.
	 */
	private function includes() {
		require_once UMI_MP_PATH . 'includes/class-umi-database.php';
		require_once UMI_MP_PATH . 'includes/class-umi-roles.php';
		require_once UMI_MP_PATH . 'includes/class-umi-access.php';
		require_once UMI_MP_PATH . 'includes/class-umi-email-verification.php';
		require_once UMI_MP_PATH . 'includes/class-umi-settings.php';
		require_once UMI_MP_PATH . 'includes/class-umi-balance.php';
		require_once UMI_MP_PATH . 'includes/class-umi-ledger.php';
		require_once UMI_MP_PATH . 'includes/class-umi-cpt.php';
		require_once UMI_MP_PATH . 'includes/class-umi-meta-boxes.php';
		require_once UMI_MP_PATH . 'includes/class-umi-limits.php';
		require_once UMI_MP_PATH . 'includes/class-umi-capabilities.php';
		require_once UMI_MP_PATH . 'includes/class-umi-deals.php';
		require_once UMI_MP_PATH . 'includes/class-umi-chat.php';
		require_once UMI_MP_PATH . 'includes/class-umi-reviews.php';
		require_once UMI_MP_PATH . 'includes/class-umi-favorites.php';
		require_once UMI_MP_PATH . 'includes/class-umi-shortcodes.php';
		require_once UMI_MP_PATH . 'includes/class-umi-ajax.php';
		require_once UMI_MP_PATH . 'includes/class-umi-admin.php';
		require_once UMI_MP_PATH . 'includes/class-umi-assets.php';
	}

	/**
	 * Init hooks.
	 */
	public function init() {
		Umi_Roles::ensure_seller_publish_caps();
		Umi_Roles::ensure_upload_caps();
		Umi_Access::hooks();
		Umi_Email_Verification::hooks();
		Umi_Capabilities::hooks();
		Umi_Cpt::register();
		Umi_Meta_Boxes::hooks();
		Umi_Limits::hooks();
		Umi_Deals::hooks();
		Umi_Chat::hooks();
		Umi_Reviews::hooks();
		Umi_Shortcodes::register();
		Umi_Ajax::hooks();
		Umi_Admin::hooks();
		Umi_Assets::hooks();
	}
}
