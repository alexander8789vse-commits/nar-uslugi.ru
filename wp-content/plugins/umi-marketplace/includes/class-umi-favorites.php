<?php
/**
 * Избранные объявления (user meta).
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Favorites
 */
class Umi_Favorites {

	const META_KEY = 'umi_fav_listing_ids';

	/**
	 * ID объявлений (порядок: последние добавленные первыми).
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	public static function get_ids( $user_id ) {
		$raw = get_user_meta( (int) $user_id, self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * @param int $user_id User.
	 * @param int $post_id Listing post ID.
	 * @return bool
	 */
	public static function is_favorite( $user_id, $post_id ) {
		$ids = self::get_ids( $user_id );
		return in_array( (int) $post_id, $ids, true );
	}

	/**
	 * @param int   $user_id User.
	 * @param int[] $ids     Ordered IDs.
	 */
	public static function save_ids( $user_id, $ids ) {
		$uid = (int) $user_id;
		$clean = array();
		$seen  = array();
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( $id < 1 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ]  = true;
			$clean[]      = $id;
		}
		update_user_meta( $uid, self::META_KEY, $clean );
	}

	/**
	 * @param int $user_id User.
	 * @param int $post_id Post ID.
	 * @return true|WP_Error { favorited: bool } on success.
	 */
	public static function toggle( $user_id, $post_id ) {
		$uid = (int) $user_id;
		$pid = (int) $post_id;
		if ( $uid < 1 || $pid < 1 ) {
			return new WP_Error( 'umi_fav_param', __( 'Некорректный запрос.', 'umi-marketplace' ) );
		}
		$p = get_post( $pid );
		if ( ! $p || ! in_array( $p->post_type, array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ), true ) ) {
			return new WP_Error( 'umi_fav_post', __( 'Объявление не найдено.', 'umi-marketplace' ) );
		}
		if ( 'publish' !== $p->post_status ) {
			return new WP_Error( 'umi_fav_post', __( 'Объявление недоступно для избранного.', 'umi-marketplace' ) );
		}
		if ( (int) $p->post_author === $uid ) {
			return new WP_Error( 'umi_fav_own', __( 'Нельзя добавить своё объявление в избранное.', 'umi-marketplace' ) );
		}
		$ids = self::get_ids( $uid );
		$key = array_search( $pid, $ids, true );
		if ( false !== $key ) {
			unset( $ids[ $key ] );
			$ids  = array_values( $ids );
			$fav  = false;
		} else {
			array_unshift( $ids, $pid );
			$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
			$fav = true;
		}
		self::save_ids( $uid, $ids );
		return $fav;
	}
}
