<?php
/**
 * Доступ: только администраторам (manage_options) — полноценный wp-admin.
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Access
 */
class Umi_Access {

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_action( 'admin_init', array( __CLASS__, 'block_non_admin_backend' ), 1 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'show_admin_bar' ), 99 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
	}

	/**
	 * URL после входа и при «выталкивании» из бэкенда.
	 *
	 * @return string
	 */
	public static function user_area_url() {
		$default = home_url( '/' );
		/**
		 * Страница кабинета (например /kabinet/).
		 *
		 * @param string $url URL.
		 */
		return (string) apply_filters( 'umi_user_cabinet_url', $default );
	}

	/**
	 * Закрываем /wp-admin/ для залогиненных, у кого нет manage_options.
	 * Пропускаем admin-ajax, admin-post, AJAX-запросы, cron.
	 */
	public static function block_non_admin_backend() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}
		$script = isset( $_SERVER['PHP_SELF'] ) ? basename( wp_unslash( (string) $_SERVER['PHP_SELF'] ) ) : '';
		if ( in_array( $script, array( 'admin-ajax.php', 'admin-post.php' ), true ) ) {
			return;
		}
		wp_safe_redirect( self::user_area_url() );
		exit;
	}

	/**
	 * Панель WordPress (верх) только у администраторов.
	 *
	 * @param bool $show Show.
	 * @return bool
	 */
	public static function show_admin_bar( $show ) {
		if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return (bool) $show;
	}

	/**
	 * Не пускаем в консоль после логина по умолчанию.
	 *
	 * @param string         $redirect_to            Redirect.
	 * @param string         $requested_redirect_to Requested.
	 * @param WP_User|WP_Error $user                User.
	 * @return string
	 */
	public static function login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) || ! $user->exists() ) {
			return $redirect_to;
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return $redirect_to;
		}
		$req = (string) $requested_redirect_to;
		if ( $req !== '' ) {
			$valid = wp_validate_redirect( $req, false );
			if ( $valid && ( false === strpos( $valid, 'wp-admin' ) || false !== strpos( $valid, 'admin-ajax.php' ) || false !== strpos( $valid, 'admin-post.php' ) ) ) {
				return $valid;
			}
		}
		$to = (string) $redirect_to;
		if ( $to !== '' && false !== strpos( $to, 'wp-admin' ) && false === strpos( $to, 'admin-ajax.php' ) && false === strpos( $to, 'admin-post.php' ) ) {
			return self::user_area_url();
		}
		return $redirect_to;
	}
}
