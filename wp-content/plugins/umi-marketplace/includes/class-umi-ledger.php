<?php
/**
 * Ledger operations.
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Ledger
 */
class Umi_Ledger {

	/**
	 * Insert ledger row and adjust balance if type implies shares change.
	 *
	 * @param array $args Arguments.
	 * @return int|false Insert ID.
	 */
	public static function record( $args ) {
		global $wpdb;

		$defaults = array(
			'admin_user_id'  => get_current_user_id(),
			'user_id'        => 0,
			'type'           => 'manual',
			'shares_delta'   => '0',
			'rub_delta'      => null,
			'rub_rate_used'  => null,
			'comment'        => '',
			'deal_id'        => null,
			'adjust_balance' => true,
		);
		$args = wp_parse_args( $args, $defaults );

		$table = Umi_Database::ledger_table();

		$rub_delta     = null === $args['rub_delta'] ? null : round( (float) $args['rub_delta'], 0 );
		$rub_rate_used = null === $args['rub_rate_used'] ? null : (float) $args['rub_rate_used'];
		$deal_id       = $args['deal_id'] ? (int) $args['deal_id'] : 0;

		$wpdb->insert(
			$table,
			array(
				'created_at'     => current_time( 'mysql' ),
				'admin_user_id'  => (int) $args['admin_user_id'],
				'user_id'        => (int) $args['user_id'],
				'type'           => sanitize_key( $args['type'] ),
				'shares_delta'   => Umi_Balance::normalize( $args['shares_delta'] ),
				'rub_delta'      => null === $rub_delta ? 0 : $rub_delta,
				'rub_rate_used'  => null === $rub_rate_used ? 0 : $rub_rate_used,
				'comment'        => sanitize_textarea_field( $args['comment'] ),
				'deal_id'        => $deal_id,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%f', '%f', '%s', '%d' )
		);

		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return false;
		}

		if ( $args['adjust_balance'] && (float) $args['shares_delta'] !== 0.0 ) {
			Umi_Balance::add( (int) $args['user_id'], $args['shares_delta'] );
		}

		return $id;
	}

	/**
	 * Credit shares from admin receiving RUB (deposit).
	 *
	 * @param int    $admin_id Admin user.
	 * @param int    $user_id Target user.
	 * @param float  $rub_amount Rubles received.
	 * @param string $comment Comment.
	 * @return int|false
	 */
	public static function credit_from_rub_deposit( $admin_id, $user_id, $rub_amount, $comment = '' ) {
		$rate = (float) Umi_Settings::get_var( 'deposit_rub_per_share', 100 );
		if ( $rate <= 0 ) {
			return false;
		}
		$shares = max( 0, (int) round( (float) $rub_amount / $rate ) );
		return self::record(
			array(
				'admin_user_id'  => $admin_id,
				'user_id'        => $user_id,
				'type'           => 'deposit_rub',
				'shares_delta'   => (string) $shares,
				'rub_delta'      => (float) $rub_amount,
				'rub_rate_used'  => $rate,
				'comment'        => $comment,
				'adjust_balance' => true,
			)
		);
	}

	/**
	 * Withdraw shares to RUB (records negative shares; admin pays rub offline).
	 *
	 * @param int    $admin_id Admin user.
	 * @param int    $user_id Target user.
	 * @param float  $shares_amount Shares to withdraw.
	 * @param string $comment Comment.
	 * @return int|false
	 */
	public static function withdraw_shares_to_rub( $admin_id, $user_id, $shares_amount, $comment = '' ) {
		$shares_amount = (float) $shares_amount;
		if ( $shares_amount <= 0 ) {
			return false;
		}
		$balance = (float) Umi_Balance::get( $user_id );
		if ( (int) Umi_Balance::get( $user_id ) < (int) round( $shares_amount ) ) {
			return false;
		}
		$rate = (float) Umi_Settings::get_var( 'withdraw_rub_per_share', 120 );
		$rub  = max( 0, (int) round( $shares_amount * $rate ) );
		return self::record(
			array(
				'admin_user_id'  => $admin_id,
				'user_id'        => $user_id,
				'type'           => 'withdraw_shares',
				'shares_delta'   => (string) ( -1 * $shares_amount ),
				'rub_delta'      => $rub,
				'rub_rate_used'  => $rate,
				'comment'        => $comment,
				'adjust_balance' => true,
			)
		);
	}

	/**
	 * Recent rows.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function recent( $limit = 100 ) {
		global $wpdb;
		$table = Umi_Database::ledger_table();
		$limit = (int) $limit;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
	}

	/**
	 * Записи журнала по пользователю.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function for_user( $user_id, $limit = 200 ) {
		global $wpdb;
		$table   = Umi_Database::ledger_table();
		$uid     = (int) $user_id;
		$limit   = (int) $limit;
		if ( $uid < 1 || $limit < 1 ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE user_id = %d ORDER BY id DESC LIMIT %d", $uid, $limit ), ARRAY_A );
	}

	/**
	 * Подпись типа операции для админки.
	 *
	 * @param string $type Type slug.
	 * @return string
	 */
	public static function type_label( $type ) {
		$t = (string) $type;
		$map = array(
			'manual'         => __( 'Вручную', 'umi-marketplace' ),
			'deposit_rub'    => __( 'Пополнение (₽ → доли)', 'umi-marketplace' ),
			'withdraw_shares' => __( 'Вывод (доли → ₽)', 'umi-marketplace' ),
			'deal_spend'     => __( 'Списание по сделке', 'umi-marketplace' ),
			'deal_earn'      => __( 'Начисление по сделке', 'umi-marketplace' ),
			'deal_refund'    => __( 'Возврат по сделке', 'umi-marketplace' ),
			'admin_credit'   => __( 'Начисление админом', 'umi-marketplace' ),
			'admin_debit'    => __( 'Списание админом', 'umi-marketplace' ),
		);
		return isset( $map[ $t ] ) ? $map[ $t ] : $t;
	}

	/**
	 * Перевод долей в рамках сделки: списание у покупателя, начисление продавцу. Две проводки в журнале.
	 *
	 * @param int    $buyer_id Покупатель.
	 * @param int    $seller_id Продавец.
	 * @param float  $shares Сумма в долях.
	 * @param int    $deal_id ID сделки.
	 * @param string $comment_base Комментарий.
	 * @return true|WP_Error
	 */
	public static function deal_shares_transfer( $buyer_id, $seller_id, $shares, $deal_id, $comment_base = '' ) {
		$buyer_id  = (int) $buyer_id;
		$seller_id = (int) $seller_id;
		$deal_id   = (int) $deal_id;
		$shares = (int) Umi_Balance::normalize( $shares );
		if ( $shares < 1 ) {
			return new WP_Error( 'umi_ledger_amount', __( 'Сумма долей должна быть больше нуля.', 'umi-marketplace' ) );
		}
		if ( $buyer_id === $seller_id ) {
			return new WP_Error( 'umi_ledger_parties', __( 'Некорректные участники.', 'umi-marketplace' ) );
		}
		$bal = (int) Umi_Balance::get( $buyer_id );
		if ( $bal < $shares ) {
			return new WP_Error( 'umi_ledger_funds', __( 'Недостаточно долей на балансе.', 'umi-marketplace' ) );
		}

		$neg = self::record(
			array(
				'admin_user_id'  => 0,
				'user_id'        => $buyer_id,
				'type'           => 'deal_spend',
				'shares_delta'   => (string) ( -1 * $shares ),
				'rub_delta'      => null,
				'rub_rate_used'  => null,
				'comment'        => trim( (string) $comment_base . ' ' . __( '(списание по сделке)', 'umi-marketplace' ) ),
				'deal_id'        => $deal_id,
				'adjust_balance' => true,
			)
		);
		if ( ! $neg ) {
			return new WP_Error( 'umi_ledger_write', __( 'Ошибка записи в журнал.', 'umi-marketplace' ) );
		}

		$pos = self::record(
			array(
				'admin_user_id'  => 0,
				'user_id'        => $seller_id,
				'type'           => 'deal_earn',
				'shares_delta'   => (string) $shares,
				'rub_delta'      => null,
				'rub_rate_used'  => null,
				'comment'        => trim( (string) $comment_base . ' ' . __( '(начисление по сделке)', 'umi-marketplace' ) ),
				'deal_id'        => $deal_id,
				'adjust_balance' => true,
			)
		);
		if ( ! $pos ) {
			self::record(
				array(
					'admin_user_id'  => 0,
					'user_id'        => $buyer_id,
					'type'           => 'deal_refund',
					'shares_delta'   => (string) $shares,
					'rub_delta'      => null,
					'rub_rate_used'  => null,
					'comment'        => __( 'Откат: не удалось зачислить продавцу.', 'umi-marketplace' ),
					'deal_id'        => $deal_id,
					'adjust_balance' => true,
				)
			);
			return new WP_Error( 'umi_ledger_write', __( 'Ошибка зачисления продавцу, платёж отменён.', 'umi-marketplace' ) );
		}

		return true;
	}
}
