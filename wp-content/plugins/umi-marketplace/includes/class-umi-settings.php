<?php
/**
 * Plugin settings (options).
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Settings
 */
class Umi_Settings {

	const OPTION_KEY = 'umi_mp_settings';

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'deposit_rub_per_share'   => 100,
			'withdraw_rub_per_share'  => 120,
			'limit_services_default'  => 5,
			'limit_products_default'  => 5,
			'chat_poll_interval_ms'   => 5000,
			'admin_notify_email'      => '',
			'support_user_id'         => 0,
			'seller_profile_page_id'  => 0,
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Get one key.
	 *
	 * @param string $key Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function get_var( $key, $default = null ) {
		$all = self::get();
		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	/**
	 * Sanitize settings array from admin form.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$prev  = self::get();
		$clean = array();

		$clean['deposit_rub_per_share'] = isset( $input['deposit_rub_per_share'] )
			? max( 1, (int) round( (float) $input['deposit_rub_per_share'] ) )
			: $prev['deposit_rub_per_share'];

		$clean['withdraw_rub_per_share'] = isset( $input['withdraw_rub_per_share'] )
			? max( 1, (int) round( (float) $input['withdraw_rub_per_share'] ) )
			: $prev['withdraw_rub_per_share'];

		$clean['limit_services_default'] = isset( $input['limit_services_default'] )
			? max( 1, (int) $input['limit_services_default'] )
			: $prev['limit_services_default'];

		$clean['limit_products_default'] = isset( $input['limit_products_default'] )
			? max( 1, (int) $input['limit_products_default'] )
			: $prev['limit_products_default'];

		$clean['chat_poll_interval_ms'] = isset( $input['chat_poll_interval_ms'] )
			? max( 2000, (int) $input['chat_poll_interval_ms'] )
			: $prev['chat_poll_interval_ms'];

		$clean['admin_notify_email'] = isset( $input['admin_notify_email'] )
			? sanitize_email( $input['admin_notify_email'] )
			: $prev['admin_notify_email'];

		$clean['support_user_id'] = isset( $input['support_user_id'] )
			? max( 0, (int) $input['support_user_id'] )
			: $prev['support_user_id'];

		$clean['seller_profile_page_id'] = isset( $input['seller_profile_page_id'] )
			? max( 0, (int) $input['seller_profile_page_id'] )
			: ( isset( $prev['seller_profile_page_id'] ) ? (int) $prev['seller_profile_page_id'] : 0 );

		return $clean;
	}
}
