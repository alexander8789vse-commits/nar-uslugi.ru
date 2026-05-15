<?php
/**
 * Deals (сделки).
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Deals
 */
class Umi_Deals {

	const STATUS_NEGOTIATED   = 'negotiated';
	const STATUS_WAIT_SHARES  = 'waiting_shares';
	const STATUS_PAID_SHARES  = 'paid_shares';
	const STATUS_PAID_RUB     = 'paid_rub';
	const STATUS_COMPLETED    = 'completed';
	const STATUS_DISPUTE      = 'dispute';

	const PAY_RUB   = 'rub';
	const PAY_SHARE = 'shares';

	/**
	 * Hooks.
	 */
	public static function hooks() {
		// Intentionally minimal; use Umi_Shortcodes / Umi_Ajax for UI.
	}

	/**
	 * Valid statuses.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array(
			self::STATUS_NEGOTIATED,
			self::STATUS_WAIT_SHARES,
			self::STATUS_PAID_SHARES,
			self::STATUS_PAID_RUB,
			self::STATUS_COMPLETED,
			self::STATUS_DISPUTE,
		);
	}

	/**
	 * Status labels for display.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels() {
		return array(
			self::STATUS_NEGOTIATED   => __( 'Договорились', 'umi-marketplace' ),
			self::STATUS_WAIT_SHARES  => __( 'Ждём доли', 'umi-marketplace' ),
			self::STATUS_PAID_SHARES  => __( 'Оплачено долями', 'umi-marketplace' ),
			self::STATUS_PAID_RUB     => __( 'Оплачено рублями (вне сайта)', 'umi-marketplace' ),
			self::STATUS_COMPLETED    => __( 'Завершено', 'umi-marketplace' ),
			self::STATUS_DISPUTE      => __( 'Спор', 'umi-marketplace' ),
		);
	}

	/**
	 * Get buyer id.
	 *
	 * @param int $deal_id Deal.
	 * @return int
	 */
	public static function get_buyer_id( $deal_id ) {
		return (int) get_post_meta( (int) $deal_id, '_umi_buyer_id', true );
	}

	/**
	 * Get seller id.
	 *
	 * @param int $deal_id Deal.
	 * @return int
	 */
	public static function get_seller_id( $deal_id ) {
		return (int) get_post_meta( (int) $deal_id, '_umi_seller_id', true );
	}

	/**
	 * @param int $deal_id Deal.
	 * @return int
	 */
	public static function get_listing_id( $deal_id ) {
		return (int) get_post_meta( (int) $deal_id, '_umi_listing_id', true );
	}

	/**
	 * RUB at deal creation.
	 *
	 * @param int $deal_id Deal.
	 * @return float
	 */
	public static function get_amount_rub( $deal_id ) {
		return (float) get_post_meta( (int) $deal_id, '_umi_amount_rub', true );
	}

	/**
	 * Сумма в долях (фикс на момент создания сделки).
	 *
	 * @param int $deal_id Deal.
	 * @return string
	 */
	public static function get_amount_shares( $deal_id ) {
		$s = (string) get_post_meta( (int) $deal_id, '_umi_amount_shares', true );
		return $s ? Umi_Balance::normalize( $s ) : '0';
	}

	/**
	 * @param int $deal_id Deal.
	 * @return bool
	 */
	public static function user_is_party( $deal_id, $user_id ) {
		$uid = (int) $user_id;
		return $uid > 0 && ( $uid === self::get_buyer_id( $deal_id ) || $uid === self::get_seller_id( $deal_id ) );
	}

	/**
	 * Сделка: участники или администратор.
	 *
	 * @param int $deal_id Deal.
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function user_can_access_deal( $deal_id, $user_id ) {
		$uid = (int) $user_id;
		if ( $uid < 1 ) {
			return false;
		}
		if ( user_can( $uid, 'manage_options' ) ) {
			return true;
		}
		return self::user_is_party( $deal_id, $uid );
	}

	/**
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function can_be_buyer( $user_id ) {
		$uid = (int) $user_id;
		return $uid > 0 && (bool) get_userdata( $uid );
	}

	/**
	 * Курс: доли = ₽ / курс покупки долей.
	 *
	 * @param float $rub RUB.
	 * @return string
	 */
	public static function shares_from_rub( $rub ) {
		$rate = (float) Umi_Settings::get_var( 'deposit_rub_per_share', 100 );
		if ( $rate <= 0 ) {
			return '0';
		}
		$r = (float) $rub;
		if ( $r <= 0 ) {
			return '0';
		}
		return Umi_Balance::normalize( max( 0, (int) round( $r / $rate ) ) );
	}

	/**
	 * @param int $post_id Listing.
	 * @return float
	 */
	public static function listing_price_rub( $post_id ) {
		$p = (float) get_post_meta( (int) $post_id, '_umi_price', true );
		return $p > 0 ? $p : 0;
	}

	/**
	 * @param int $deal_id Deal.
	 * @return bool
	 */
	public static function shares_payment_possible( $deal_id ) {
		$lid = self::get_listing_id( $deal_id );
		return $lid > 0 && Umi_Meta_Boxes::listing_allows_shares_payment( $lid );
	}

	/**
	 * @param int $deal_id Deal.
	 * @return string
	 */
	public static function get_payment_type( $deal_id ) {
		return (string) get_post_meta( (int) $deal_id, '_umi_payment_type', true );
	}

	/**
	 * Активная = любая, кроме «Завершено».
	 *
	 * @param int $listing_id Listing.
	 * @param int $buyer_id Buyer.
	 * @param int $seller_id Seller.
	 * @return int 0 or deal id.
	 */
	public static function find_active_deal( $listing_id, $buyer_id, $seller_id ) {
		$q = new WP_Query(
			array(
				'post_type'      => Umi_Cpt::DEAL,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_umi_listing_id',
						'value' => (int) $listing_id,
					),
					array(
						'key'   => '_umi_buyer_id',
						'value' => (int) $buyer_id,
					),
					array(
						'key'   => '_umi_seller_id',
						'value' => (int) $seller_id,
					),
				),
			)
		);
		if ( ! $q->have_posts() ) {
			return 0;
		}
		$id = (int) $q->posts[0];
		$st = self::get_status( $id );
		if ( self::STATUS_COMPLETED === $st ) {
			return 0;
		}
		return $id;
	}

	/**
	 * List deals for user.
	 *
	 * @param int   $user_id User.
	 * @param int   $per_page Per page.
	 * @return WP_Post[]
	 */
	public static function deals_for_user( $user_id, $per_page = 50 ) {
		$uid = (int) $user_id;
		$q   = new WP_Query(
			array(
				'post_type'      => Umi_Cpt::DEAL,
				'post_status'    => 'publish',
				'posts_per_page' => (int) $per_page,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'   => '_umi_buyer_id',
						'value' => $uid,
					),
					array(
						'key'   => '_umi_seller_id',
						'value' => $uid,
					),
				),
			)
		);
		return $q->have_posts() ? $q->posts : array();
	}

	/**
	 * Сделки пользователя в статусе «спор» (активные споры).
	 *
	 * @param int $user_id User.
	 * @param int $per_page Per page.
	 * @return WP_Post[]
	 */
	public static function disputes_for_user( $user_id, $per_page = 50 ) {
		$uid = (int) $user_id;
		if ( $uid < 1 ) {
			return array();
		}
		$q = new WP_Query(
			array(
				'post_type'      => Umi_Cpt::DEAL,
				'post_status'    => 'publish',
				'posts_per_page' => (int) $per_page,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_umi_status',
						'value' => self::STATUS_DISPUTE,
					),
					array(
						'relation' => 'OR',
						array(
							'key'   => '_umi_buyer_id',
							'value' => $uid,
						),
						array(
							'key'   => '_umi_seller_id',
							'value' => $uid,
						),
					),
				),
			)
		);
		return $q->have_posts() ? $q->posts : array();
	}

	/**
	 * Create deal post.
	 *
	 * @param array $args Args.
	 * @return int|WP_Error Post ID or error.
	 */
	public static function create( $args ) {
		$defaults = array(
			'listing_id'   => 0,
			'listing_type' => 'service',
			'buyer_id'     => 0,
			'seller_id'    => 0,
			'status'       => self::STATUS_NEGOTIATED,
			'title'        => '',
		);
		$args         = wp_parse_args( $args, $defaults );
		$listing_id   = (int) $args['listing_id'];
		$buyer_id     = (int) $args['buyer_id'];
		$seller_id    = (int) $args['seller_id'];
		$listing_type = sanitize_key( $args['listing_type'] );

		if ( ! $listing_id || ! $buyer_id || ! $seller_id || $buyer_id === $seller_id ) {
			return new WP_Error( 'umi_deal_missing', __( 'Недостаточно данных для сделки.', 'umi-marketplace' ) );
		}

		$lp = get_post( $listing_id );
		if ( ! $lp || ! in_array( $lp->post_type, array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ), true ) ) {
			return new WP_Error( 'umi_deal_listing', __( 'Объявление не найдено.', 'umi-marketplace' ) );
		}
		if ( 'publish' !== $lp->post_status ) {
			return new WP_Error( 'umi_deal_listing', __( 'Объявление не опубликовано.', 'umi-marketplace' ) );
		}
		if ( (int) $lp->post_author !== $seller_id ) {
			return new WP_Error( 'umi_deal_seller', __( 'Продавец не совпадает с владельцем объявления.', 'umi-marketplace' ) );
		}
		$listing_type = $lp->post_type;
		$active       = self::find_active_deal( $listing_id, $buyer_id, $seller_id );
		if ( $active > 0 ) {
			return new WP_Error( 'umi_deal_exists', __( 'По этому объявлению уже есть незавершённая сделка.', 'umi-marketplace' ) );
		}

		$rub   = self::listing_price_rub( $listing_id );
		$sh    = $rub > 0 ? self::shares_from_rub( $rub ) : '0';
		$title = $args['title'] ? (string) $args['title'] : sprintf(
			/* translators: %d listing id */
			__( 'Сделка #%d', 'umi-marketplace' ),
			$listing_id
		);

		$post_id = wp_insert_post(
			array(
				'post_type'   => Umi_Cpt::DEAL,
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_author' => $buyer_id,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_umi_listing_id', $listing_id );
		update_post_meta( $post_id, '_umi_listing_type', $listing_type );
		update_post_meta( $post_id, '_umi_buyer_id', $buyer_id );
		update_post_meta( $post_id, '_umi_seller_id', $seller_id );
		update_post_meta( $post_id, '_umi_status', sanitize_key( $args['status'] ) );
		update_post_meta( $post_id, '_umi_payment_type', '' );
		update_post_meta( $post_id, '_umi_amount_rub', max( 0, (int) round( $rub ) ) );
		update_post_meta( $post_id, '_umi_amount_shares', $sh );

		return (int) $post_id;
	}

	/**
	 * Создать сделку с места: покупатель (не владелец).
	 *
	 * @param int $listing_id Listing.
	 * @param int $buyer_id Current buyer.
	 * @return int|WP_Error
	 */
	public static function create_by_buyer( $listing_id, $buyer_id ) {
		$listing_id = (int) $listing_id;
		$buyer_id   = (int) $buyer_id;
		$lp         = get_post( $listing_id );
		if ( ! $lp || 'publish' !== $lp->post_status ) {
			return new WP_Error( 'umi_deal_listing', __( 'Объявление не найдено.', 'umi-marketplace' ) );
		}
		$seller_id = (int) $lp->post_author;
		if ( $buyer_id === $seller_id ) {
			return new WP_Error( 'umi_deal_self', __( 'Нельзя оформить сделку с самим собой.', 'umi-marketplace' ) );
		}
		return self::create(
			array(
				'listing_id'   => $listing_id,
				'listing_type' => $lp->post_type,
				'buyer_id'     => $buyer_id,
				'seller_id'    => $seller_id,
			)
		);
	}

	/**
	 * Update deal status (admin or trusted handlers).
	 *
	 * @param int    $deal_id Deal post ID.
	 * @param string $status Status slug.
	 * @return bool
	 */
	public static function set_status( $deal_id, $status ) {
		$status = sanitize_key( $status );
		if ( ! in_array( $status, self::statuses(), true ) ) {
			return false;
		}
		return (bool) update_post_meta( $deal_id, '_umi_status', $status );
	}

	/**
	 * Get status.
	 *
	 * @param int $deal_id Deal ID.
	 * @return string
	 */
	public static function get_status( $deal_id ) {
		return (string) get_post_meta( $deal_id, '_umi_status', true );
	}

	/**
	 * Можно ли провести сделку с оплатой долями по этому объявлению.
	 *
	 * @param int $listing_id ID услуги или товара.
	 * @return bool
	 */
	public static function listing_allows_shares_payment( $listing_id ) {
		return Umi_Meta_Boxes::listing_allows_shares_payment( (int) $listing_id );
	}

	/**
	 * Действия участника.
	 * $action: to_waiting|pay_shares|mark_paid_rub|complete|open_dispute
	 *
	 * @param string $action Action.
	 * @param int    $deal_id Deal.
	 * @param int    $user_id User.
	 * @return true|WP_Error
	 */
	public static function do_action( $action, $deal_id, $user_id ) {
		$action  = sanitize_key( $action );
		$deal_id = (int) $deal_id;
		$user_id = (int) $user_id;
		$post    = get_post( $deal_id );
		if ( ! $post || Umi_Cpt::DEAL !== $post->post_type ) {
			return new WP_Error( 'umi_deal_bad', __( 'Сделка не найдена.', 'umi-marketplace' ) );
		}
		$is_party = self::user_is_party( $deal_id, $user_id );
		if ( ! $is_party && ! ( 'close_dispute' === $action && user_can( $user_id, 'manage_options' ) ) ) {
			return new WP_Error( 'umi_deal_cap', __( 'Нет доступа к сделке.', 'umi-marketplace' ) );
		}

		$status = self::get_status( $deal_id );
		$buyer  = self::get_buyer_id( $deal_id );
		$sell   = self::get_seller_id( $deal_id );
		$is_buy = ( $user_id === $buyer );
		$is_sel = ( $user_id === $sell );

		switch ( $action ) {
			case 'to_waiting':
				if ( ! $is_buy || self::STATUS_NEGOTIATED !== $status ) {
					return new WP_Error( 'umi_deal_state', __( 'Действие недоступно.', 'umi-marketplace' ) );
				}
				if ( ! self::shares_payment_possible( $deal_id ) ) {
					return new WP_Error( 'umi_deal_no_shares', __( 'По этому объявлению нельзя оплатить долями.', 'umi-marketplace' ) );
				}
				if ( ! self::set_status( $deal_id, self::STATUS_WAIT_SHARES ) ) {
					return new WP_Error( 'umi_deal_state', __( 'Не удалось обновить статус.', 'umi-marketplace' ) );
				}
				return true;

			case 'pay_shares':
				if ( ! $is_buy || ! in_array( $status, array( self::STATUS_NEGOTIATED, self::STATUS_WAIT_SHARES ), true ) ) {
					return new WP_Error( 'umi_deal_state', __( 'Оплата долями сейчас недоступна.', 'umi-marketplace' ) );
				}
				if ( ! self::shares_payment_possible( $deal_id ) ) {
					return new WP_Error( 'umi_deal_no_shares', __( 'По этому объявлению нельзя оплатить долями.', 'umi-marketplace' ) );
				}
				$sh = self::get_amount_shares( $deal_id );
				if ( (int) $sh < 1 ) {
					return new WP_Error( 'umi_deal_zero', __( 'Сумма в долях не определена (проверьте цену в объявлении).', 'umi-marketplace' ) );
				}
				$r = Umi_Ledger::deal_shares_transfer( $user_id, $sell, (int) $sh, (int) $deal_id, sprintf( /* translators: %d */ __( 'Сделка #%d', 'umi-marketplace' ), (int) $deal_id ) );
				if ( is_wp_error( $r ) ) {
					if ( 'umi_ledger_funds' === $r->get_error_code() && self::STATUS_WAIT_SHARES !== $status ) {
						self::set_status( $deal_id, self::STATUS_WAIT_SHARES );
					}
					return $r;
				}
				update_post_meta( $deal_id, '_umi_payment_type', self::PAY_SHARE );
				if ( ! self::set_status( $deal_id, self::STATUS_PAID_SHARES ) ) {
					return new WP_Error( 'umi_deal_state', __( 'Платёж прошёл, но статус не обновлён. Обратитесь к администратору.', 'umi-marketplace' ) );
				}
				return true;

			case 'mark_paid_rub':
				if ( ! $is_sel || self::STATUS_NEGOTIATED !== $status ) {
					return new WP_Error( 'umi_deal_state', __( 'Действие недоступно.', 'umi-marketplace' ) );
				}
				update_post_meta( $deal_id, '_umi_payment_type', self::PAY_RUB );
				if ( ! self::set_status( $deal_id, self::STATUS_PAID_RUB ) ) {
					return new WP_Error( 'umi_deal_state', __( 'Не удалось обновить статус.', 'umi-marketplace' ) );
				}
				return true;

			case 'complete':
				if ( ! $is_sel || ! in_array( $status, array( self::STATUS_PAID_SHARES, self::STATUS_PAID_RUB ), true ) ) {
					return new WP_Error( 'umi_deal_state', __( 'Завершение доступно после оплаты.', 'umi-marketplace' ) );
				}
				if ( ! self::set_status( $deal_id, self::STATUS_COMPLETED ) ) {
					return new WP_Error( 'umi_deal_state', __( 'Не удалось обновить статус.', 'umi-marketplace' ) );
				}
				return true;

			case 'open_dispute':
				if ( ! in_array( $status, array( self::STATUS_PAID_SHARES, self::STATUS_PAID_RUB, self::STATUS_NEGOTIATED ), true ) ) {
					return new WP_Error( 'umi_deal_state', __( 'Спор нельзя открыть на текущем этапе.', 'umi-marketplace' ) );
				}
				if ( ! $is_buy && ! $is_sel ) {
					return new WP_Error( 'umi_deal_state', __( 'Действие недоступно.', 'umi-marketplace' ) );
				}
				update_post_meta( $deal_id, '_umi_status_before_dispute', $status );
				update_post_meta( $deal_id, '_umi_dispute_opened_by', $user_id );
				if ( ! self::set_status( $deal_id, self::STATUS_DISPUTE ) ) {
					return new WP_Error( 'umi_deal_state', __( 'Не удалось обновить статус.', 'umi-marketplace' ) );
				}
				if ( class_exists( 'Umi_Chat' ) ) {
					Umi_Chat::on_dispute_opened( $deal_id, $user_id );
				}
				return true;

			case 'close_dispute':
				if ( self::STATUS_DISPUTE !== $status ) {
					return new WP_Error( 'umi_deal_state', __( 'Сделка не в статусе спора.', 'umi-marketplace' ) );
				}
				$opened_by = (int) get_post_meta( $deal_id, '_umi_dispute_opened_by', true );
				$is_admin  = user_can( $user_id, 'manage_options' );
				if ( ! $is_admin && (int) $user_id !== $opened_by ) {
					return new WP_Error( 'umi_deal_state', __( 'Закрыть спор могут администратор или инициатор.', 'umi-marketplace' ) );
				}
				$before = (string) get_post_meta( $deal_id, '_umi_status_before_dispute', true );
				if ( $is_admin ) {
					if ( ! self::set_status( $deal_id, self::STATUS_COMPLETED ) ) {
						return new WP_Error( 'umi_deal_state', __( 'Не удалось обновить статус.', 'umi-marketplace' ) );
					}
					delete_post_meta( $deal_id, '_umi_status_before_dispute' );
					delete_post_meta( $deal_id, '_umi_dispute_opened_by' );
					return true;
				}
				$prev = in_array( $before, self::statuses(), true ) ? $before : self::STATUS_NEGOTIATED;
				if ( ! self::set_status( $deal_id, $prev ) ) {
					return new WP_Error( 'umi_deal_state', __( 'Не удалось восстановить статус.', 'umi-marketplace' ) );
				}
				delete_post_meta( $deal_id, '_umi_status_before_dispute' );
				delete_post_meta( $deal_id, '_umi_dispute_opened_by' );
				return true;
		}

		return new WP_Error( 'umi_deal_action', __( 'Неизвестное действие.', 'umi-marketplace' ) );
	}
}
