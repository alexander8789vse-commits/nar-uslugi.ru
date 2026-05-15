<?php
/**
 * Reviews after completed deals.
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Reviews
 */
class Umi_Reviews {

	/**
	 * Hooks.
	 */
	public static function hooks() {
		// Reserved.
	}

	/**
	 * Check if user already reviewed counterparty on deal.
	 *
	 * @param int $deal_id Deal ID.
	 * @param int $from_user Reviewer ID.
	 * @param int $to_user Target ID.
	 * @return bool
	 */
	public static function exists( $deal_id, $from_user, $to_user ) {
		$q = new WP_Query(
			array(
				'post_type'      => Umi_Cpt::REVIEW,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array(
						'key'   => '_umi_review_deal_id',
						'value' => (int) $deal_id,
					),
					array(
						'key'   => '_umi_from_user',
						'value' => (int) $from_user,
					),
					array(
						'key'   => '_umi_to_user',
						'value' => (int) $to_user,
					),
				),
				'fields'         => 'ids',
			)
		);
		return $q->have_posts();
	}

	/**
	 * Create review if allowed.
	 *
	 * @param array $args Args.
	 * @return int|WP_Error
	 */
	public static function create( $args ) {
		$defaults = array(
			'deal_id'   => 0,
			'from_user' => 0,
			'to_user'   => 0,
			'rating'    => 5,
			'content'   => '',
		);
		$args = wp_parse_args( $args, $defaults );

		$deal_id   = (int) $args['deal_id'];
		$from_user = (int) $args['from_user'];
		$to_user   = (int) $args['to_user'];

		if ( ! $deal_id || ! $from_user || ! $to_user || $from_user === $to_user ) {
			return new WP_Error( 'umi_review_bad', __( 'Некорректные данные отзыва.', 'umi-marketplace' ) );
		}

		$status = Umi_Deals::get_status( $deal_id );
		if ( Umi_Deals::STATUS_COMPLETED !== $status ) {
			return new WP_Error( 'umi_review_not_done', __( 'Отзыв доступен только после завершённой сделки.', 'umi-marketplace' ) );
		}

		$buyer_id  = (int) get_post_meta( $deal_id, '_umi_buyer_id', true );
		$seller_id = (int) get_post_meta( $deal_id, '_umi_seller_id', true );
		if ( ! in_array( $from_user, array( $buyer_id, $seller_id ), true ) || ! in_array( $to_user, array( $buyer_id, $seller_id ), true ) ) {
			return new WP_Error( 'umi_review_party', __( 'Участники сделки не совпадают.', 'umi-marketplace' ) );
		}

		if ( self::exists( $deal_id, $from_user, $to_user ) ) {
			return new WP_Error( 'umi_review_dup', __( 'Отзыв уже оставлен.', 'umi-marketplace' ) );
		}

		$rating = max( 1, min( 5, (int) $args['rating'] ) );

		$post_id = wp_insert_post(
			array(
				'post_type'    => Umi_Cpt::REVIEW,
				'post_status'  => 'publish',
				'post_title'   => sprintf(
					/* translators: 1: deal id 2: from 3: to */
					__( 'Отзыв по сделке #%1$d (%2$d→%3$d)', 'umi-marketplace' ),
					$deal_id,
					$from_user,
					$to_user
				),
				'post_content' => wp_kses_post( $args['content'] ),
				'post_author'  => $from_user,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_umi_review_deal_id', $deal_id );
		update_post_meta( $post_id, '_umi_from_user', $from_user );
		update_post_meta( $post_id, '_umi_to_user', $to_user );
		update_post_meta( $post_id, '_umi_rating', $rating );

		return (int) $post_id;
	}

	/**
	 * Опубликованные отзывы, адресованные пользователю (для публичного профиля; CPT не public).
	 *
	 * @param int $to_user_id User ID.
	 * @param int $limit      Max count.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_published_to_user( $to_user_id, $limit = 20 ) {
		$to_user_id = (int) $to_user_id;
		if ( $to_user_id < 1 ) {
			return array();
		}
		$limit = max( 1, min( 50, (int) $limit ) );
		global $wpdb;
		$pt  = Umi_Cpt::REVIEW;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_umi_to_user' AND m.meta_value = %s
				WHERE p.post_type = %s AND p.post_status = 'publish'
				ORDER BY p.post_date_gmt DESC
				LIMIT %d",
				(string) $to_user_id,
				$pt,
				$limit
			)
		);
		if ( ! is_array( $ids ) || ! $ids ) {
			return array();
		}
		$out = array();
		foreach ( $ids as $rid ) {
			$rid  = (int) $rid;
			$from = (int) get_post_meta( $rid, '_umi_from_user', true );
			$fu   = $from ? get_userdata( $from ) : null;
			$out[] = array(
				'id'             => $rid,
				'content'        => (string) get_post_field( 'post_content', $rid ),
				'rating'         => max( 1, min( 5, (int) get_post_meta( $rid, '_umi_rating', true ) ) ),
				'author_name'    => $fu ? (string) $fu->display_name : '',
				'date_iso'       => (string) get_post_time( 'c', true, $rid ),
				'date_formatted' => (string) get_post_time( get_option( 'date_format' ), true, $rid ),
			);
		}
		return $out;
	}
}
