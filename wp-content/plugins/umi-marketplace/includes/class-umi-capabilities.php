<?php
/**
 * Meta capability mapping.
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Capabilities
 */
class Umi_Capabilities {

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 4 );
	}

	/**
	 * Map caps for deal CPT: только чтение участникам, полный доступ админам.
	 *
	 * @param string[] $caps Caps.
	 * @param string   $cap Cap.
	 * @param int      $user_id User.
	 * @param array    $args Args.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'edit_post', 'read_post', 'delete_post' ), true ) ) {
			return $caps;
		}

		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( ! $post_id ) {
			return $caps;
		}

		$post = get_post( $post_id );
		if ( ! $post || Umi_Cpt::DEAL !== $post->post_type ) {
			return $caps;
		}

		if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'manage_umi_marketplace' ) ) {
			return array();
		}

		$buyer_id  = (int) get_post_meta( $post_id, '_umi_buyer_id', true );
		$seller_id = (int) get_post_meta( $post_id, '_umi_seller_id', true );
		$is_party  = ( (int) $user_id === $buyer_id || (int) $user_id === $seller_id );

		if ( 'read_post' === $cap && $is_party ) {
			return array( 'read' );
		}

		if ( in_array( $cap, array( 'edit_post', 'delete_post' ), true ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}
}
