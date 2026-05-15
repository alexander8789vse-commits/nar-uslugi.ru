<?php
/**
 * Listing limits per seller.
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Limits
 */
class Umi_Limits {

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'filter_insert_data' ), 50, 2 );
	}

	/**
	 * Count active listings excluding one post ID.
	 *
	 * @param int    $user_id Author ID.
	 * @param string $post_type CPT slug.
	 * @param int    $exclude_post_id Exclude post ID.
	 * @return int
	 */
	public static function count_active_except( $user_id, $post_type, $exclude_post_id = 0 ) {
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => array( 'publish', 'pending' ),
			'author'         => (int) $user_id,
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		);
		if ( $exclude_post_id ) {
			$args['post__not_in'] = array( (int) $exclude_post_id );
		}
		$q = new WP_Query( $args );
		return (int) $q->post_count;
	}

	/**
	 * Max allowed for user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $which services|products.
	 * @return int
	 */
	public static function max_for_user( $user_id, $which ) {
		$settings = Umi_Settings::get();
		if ( 'services' === $which ) {
			$def = (int) $settings['limit_services_default'];
			$ov  = get_user_meta( $user_id, 'umi_limit_services_override', true );
			return ( '' !== $ov && null !== $ov && false !== $ov ) ? max( 1, (int) $ov ) : $def;
		}
		$def = (int) $settings['limit_products_default'];
		$ov  = get_user_meta( $user_id, 'umi_limit_products_override', true );
		return ( '' !== $ov && null !== $ov && false !== $ov ) ? max( 1, (int) $ov ) : $def;
	}

	/**
	 * Limit check on insert/update.
	 *
	 * @param array $data An array of slashed post data.
	 * @param array $postarr An array of sanitized (and slashed) but otherwise unmodified post data.
	 * @return array
	 */
	public static function filter_insert_data( $data, $postarr ) {
		if ( ! in_array( $data['post_type'], array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ), true ) ) {
			return $data;
		}

		if ( in_array( $data['post_status'], array( 'auto-draft', 'inherit', 'trash' ), true ) ) {
			return $data;
		}

		$author_id = isset( $postarr['post_author'] ) ? (int) $postarr['post_author'] : (int) $data['post_author'];
		if ( ! $author_id ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$which   = ( Umi_Cpt::SERVICE === $data['post_type'] ) ? 'services' : 'products';
		$max     = self::max_for_user( $author_id, $which );

		$new_active = in_array( $data['post_status'], array( 'publish', 'pending' ), true );
		if ( ! $new_active ) {
			return $data;
		}

		$was_active = false;
		if ( $post_id ) {
			$old = get_post_status( $post_id );
			$was_active = $old && in_array( $old, array( 'publish', 'pending' ), true );
		}

		$others = self::count_active_except( $author_id, $data['post_type'], $post_id );

		if ( $was_active ) {
			return $data;
		}

		if ( $others + 1 > $max ) {
			$data['post_status'] = 'draft';
			$user                = get_userdata( $author_id );
			if ( $user ) {
				set_transient(
					'umi_limit_notice_' . $author_id,
					sprintf(
						/* translators: %d max listings */
						__( 'Лимит объявлений (%d) достигнут. Запись сохранена как черновик.', 'umi-marketplace' ),
						$max
					),
					60
				);
			}
		}

		return $data;
	}
}
