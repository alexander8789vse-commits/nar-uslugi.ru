<?php
/**
 * Chat threads and messages.
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Chat
 */
class Umi_Chat {

	const TYPE_LISTING = 'listing';
	const TYPE_ADMIN   = 'admin';
	const TYPE_DISPUTE = 'dispute';

	const MAX_DISPUTE_IMAGE_B = 2097152;

	/**
	 * Hooks.
	 */
	public static function hooks() {
		// AJAX registered in Umi_Ajax.
	}

	/**
	 * Listing type from CPT.
	 *
	 * @param string $post_type CPT.
	 * @return string service|product
	 */
	public static function listing_type_from_cpt( $post_type ) {
		if ( Umi_Cpt::PRODUCT === $post_type ) {
			return 'product';
		}
		return 'service';
	}

	/**
	 * Get or create thread.
	 *
	 * @param int    $listing_id Listing post ID.
	 * @param string $listing_type service|product.
	 * @param int    $buyer_id Buyer user ID.
	 * @param int    $seller_id Seller user ID.
	 * @return int Thread ID.
	 */
	public static function get_or_create_thread( $listing_id, $listing_type, $buyer_id, $seller_id ) {
		global $wpdb;
		$table = Umi_Database::threads_table();

		$listing_id = (int) $listing_id;
		$buyer_id   = (int) $buyer_id;
		$seller_id  = (int) $seller_id;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM $table WHERE listing_id = %d AND listing_type = %s AND buyer_id = %d AND thread_type = %s",
				$listing_id,
				$listing_type,
				$buyer_id,
				self::TYPE_LISTING
			)
		);
		if ( $existing ) {
			return (int) $existing;
		}

		$wpdb->insert(
			$table,
			array(
				'listing_id'   => $listing_id,
				'listing_type' => $listing_type,
				'buyer_id'     => $buyer_id,
				'seller_id'    => $seller_id,
				'thread_type'  => self::TYPE_LISTING,
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Тип треда из строки БД.
	 *
	 * @param array $thread Row.
	 * @return string
	 */
	public static function thread_type( array $thread ) {
		$t = isset( $thread['thread_type'] ) ? (string) $thread['thread_type'] : self::TYPE_LISTING;
		return in_array( $t, array( self::TYPE_LISTING, self::TYPE_ADMIN, self::TYPE_DISPUTE ), true ) ? $t : self::TYPE_LISTING;
	}

	/**
	 * Пользователь-администратор для чата поддержки.
	 *
	 * @return int
	 */
	public static function support_user_id() {
		$set = (int) Umi_Settings::get_var( 'support_user_id', 0 );
		if ( $set > 0 && user_can( $set, 'manage_options' ) ) {
			return $set;
		}
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => array( 'ID' ),
			)
		);
		if ( ! empty( $admins[0] ) && isset( $admins[0]->ID ) ) {
			return (int) $admins[0]->ID;
		}
		$u = get_user_by( 'email', (string) get_option( 'admin_email' ) );
		return $u ? (int) $u->ID : 1;
	}

	/**
	 * Чат с администратором: один тред на пользователя.
	 *
	 * @param int $user_id Клиент (не админ).
	 * @return int
	 */
	public static function get_or_create_admin_thread( $user_id ) {
		global $wpdb;
		$user_id  = (int) $user_id;
		$admin_id = (int) self::support_user_id();
		if ( $user_id < 1 || $user_id === $admin_id ) {
			return 0;
		}
		$table = Umi_Database::threads_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM $table WHERE buyer_id = %d AND seller_id = %d AND thread_type = %s AND listing_id = 0",
				$user_id,
				$admin_id,
				self::TYPE_ADMIN
			)
		);
		if ( $existing ) {
			return (int) $existing;
		}
		$wpdb->insert(
			$table,
			array(
				'listing_id'   => 0,
				'listing_type' => 'admin',
				'buyer_id'     => $user_id,
				'seller_id'    => $admin_id,
				'thread_type'  => self::TYPE_ADMIN,
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Тред чата спора по сделке.
	 *
	 * @param int $deal_id Deal post ID.
	 * @return int
	 */
	public static function get_or_create_dispute_thread( $deal_id ) {
		global $wpdb;
		$deal_id = (int) $deal_id;
		$post    = get_post( $deal_id );
		if ( ! $post || Umi_Cpt::DEAL !== $post->post_type ) {
			return 0;
		}
		$table = Umi_Database::threads_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE deal_id = %d AND thread_type = %s", $deal_id, self::TYPE_DISPUTE ) );
		if ( $existing ) {
			return (int) $existing;
		}
		$listing_id = Umi_Deals::get_listing_id( $deal_id );
		$buyer      = Umi_Deals::get_buyer_id( $deal_id );
		$seller     = Umi_Deals::get_seller_id( $deal_id );
		$l_post     = $listing_id ? get_post( $listing_id ) : null;
		if ( ! $l_post || ! in_array( $l_post->post_type, array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ), true ) ) {
			return 0;
		}
		$ltype = self::listing_type_from_cpt( $l_post->post_type );
		$wpdb->insert(
			$table,
			array(
				'listing_id'   => $listing_id,
				'listing_type' => $ltype,
				'buyer_id'     => $buyer,
				'seller_id'    => $seller,
				'thread_type'  => self::TYPE_DISPUTE,
				'deal_id'      => $deal_id,
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%d', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int $deal_id Deal ID.
	 * @return int
	 */
	public static function get_dispute_thread_id( $deal_id ) {
		global $wpdb;
		$table = Umi_Database::threads_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE deal_id = %d AND thread_type = %s", (int) $deal_id, self::TYPE_DISPUTE ) );
		return $v ? (int) $v : 0;
	}

	/**
	 * Использовать курсор чтения (не read_at) для треда.
	 *
	 * @param array $thread Thread row.
	 * @return bool
	 */
	public static function uses_read_cursor( array $thread ) {
		$t = self::thread_type( $thread );
		return $t === self::TYPE_ADMIN || $t === self::TYPE_DISPUTE;
	}

	/**
	 * Add message.
	 *
	 * @param int    $thread_id Thread ID.
	 * @param int    $sender_id Sender user ID.
	 * @param string $body Message body.
	 * @param int    $attachment_id Optional attachment.
	 * @param bool   $send_notify Email/SMS-уведомления другим участникам.
	 * @return int|false Message ID.
	 */
	public static function add_message( $thread_id, $sender_id, $body, $attachment_id = 0, $send_notify = true ) {
		global $wpdb;
		$body = sanitize_textarea_field( (string) $body );
		$att  = (int) $attachment_id;
		if ( $att > 0 ) {
			$thread = self::get_thread( $thread_id );
			if ( ! $thread || self::TYPE_DISPUTE !== self::thread_type( $thread ) ) {
				return false;
			}
			if ( ! self::user_can_access_thread( $thread_id, (int) $sender_id ) ) {
				return false;
			}
			$url = wp_get_attachment_url( $att );
			if ( ! $url ) {
				return false;
			}
			$mime = get_post_mime_type( $att );
			if ( ! $mime || 0 !== strpos( $mime, 'image/' ) ) {
				return false;
			}
			$path = get_attached_file( $att );
			if ( $path && is_readable( $path ) && (int) filesize( $path ) > self::MAX_DISPUTE_IMAGE_B ) {
				return false;
			}
		}
		if ( '' === $body && $att < 1 ) {
			return false;
		}

		$wpdb->insert(
			Umi_Database::messages_table(),
			array(
				'thread_id'      => (int) $thread_id,
				'sender_id'      => (int) $sender_id,
				'body'           => $body,
				'attachment_id'  => $att,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);

		$id = (int) $wpdb->insert_id;
		if ( $id && $send_notify ) {
			$excerpt = $body;
			if ( $att > 0 && '' === trim( $body ) ) {
				$excerpt = __( 'Изображение', 'umi-marketplace' );
			}
			self::notify_new_message( $thread_id, (int) $sender_id, $excerpt );
		}
		return $id;
	}

	/**
	 * События при открытии спора: тред, письмо, первое сообщение.
	 *
	 * @param int $deal_id Deal.
	 * @param int $opener_id User who opened.
	 */
	public static function on_dispute_opened( $deal_id, $opener_id ) {
		$deal_id = (int) $deal_id;
		$opener  = (int) $opener_id;
		$tid     = self::get_or_create_dispute_thread( $deal_id );
		if ( $tid < 1 ) {
			return;
		}
		$text = sprintf(
			/* translators: %d deal id */
			__( 'Открыт спор по сделке #%d. Администратор получил уведомление.', 'umi-marketplace' ),
			$deal_id
		);
		self::add_message( $tid, $opener, $text, 0, false );
		self::email_admins_dispute( $deal_id, $opener, $text );
	}

	/**
	 * Письмо администраторам о споре.
	 *
	 * @param int    $deal_id Deal.
	 * @param int    $opener_id Opener.
	 * @param string $note Text.
	 */
	public static function email_admins_dispute( $deal_id, $opener_id, $note = '' ) {
		$deal_id = (int) $deal_id;
		$cab     = (string) apply_filters( 'umi_header_url_cabinet', home_url( '/kabinet/' ) );
		$link    = add_query_arg( 'umi_deal', $deal_id, $cab ) . '#umi-deals';
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subj    = sprintf( /* translators: 1: site, 2: deal id */ __( '[%1$s] Спор по сделке #%2$d', 'umi-marketplace' ), $site, $deal_id );
		$u       = get_userdata( (int) $opener_id );
		$who     = $u ? (string) $u->display_name : '';
		$body    = $subj . "\n\n" . ( $note ? $note . "\n\n" : '' );
		$body   .= ( $who ? sprintf( /* translators: %s name */ __( 'Инициатор: %s', 'umi-marketplace' ), $who ) . "\n" : '' );
		$body   .= $link . "\n";

		$emails   = self::get_admin_notify_emails();
		$mail_sub = $subj;
		foreach ( $emails as $e ) {
			if ( is_email( $e ) ) {
				wp_mail( $e, $mail_sub, $body );
			}
		}
	}

	/**
	 * Кому слать критичные письма (модерация, спор).
	 *
	 * @return string[]
	 */
	public static function get_admin_notify_emails() {
		$primary = (string) Umi_Settings::get_var( 'admin_notify_email', '' );
		$out     = array();
		if ( $primary && is_email( $primary ) ) {
			$out[] = $primary;
		}
		$adm = get_option( 'admin_email' );
		if ( is_email( $adm ) && ! in_array( $adm, $out, true ) ) {
			$out[] = $adm;
		}
		$users = get_users( array( 'role' => 'administrator' ) );
		foreach ( $users as $usr ) {
			if ( ! is_object( $usr ) || empty( $usr->user_email ) ) {
				continue;
			}
			$e = (string) $usr->user_email;
			if ( is_email( $e ) && ! in_array( $e, $out, true ) ) {
				$out[] = $e;
			}
		}
		return $out;
	}

	/**
	 * Уведомить участников (email) о новом сообщении.
	 *
	 * @param int    $thread_id Thread.
	 * @param int    $sender_id Sender.
	 * @param string $body_excerpt Excerpt.
	 */
	public static function notify_new_message( $thread_id, $sender_id, $body_excerpt ) {
		$thread    = self::get_thread( $thread_id );
		$sender_id = (int) $sender_id;
		if ( ! $thread ) {
			return;
		}
		$tt = self::thread_type( $thread );

		$cab  = (string) apply_filters( 'umi_header_url_cabinet', home_url( '/kabinet/' ) );
		$link = home_url( '/' );
		if ( $tt === self::TYPE_DISPUTE && ! empty( $thread['deal_id'] ) ) {
			$link = add_query_arg( 'umi_deal', (int) $thread['deal_id'], $cab ) . '#umi-deals';
		} elseif ( $tt === self::TYPE_ADMIN ) {
			$link = $cab . '#umi-cabinet-admin-chat';
		}
		$recipients = array();
		$b = (int) $thread['buyer_id'];
		$s = (int) $thread['seller_id'];
		if ( $tt === self::TYPE_DISPUTE ) {
			$recipients = array( $b, $s, (int) self::support_user_id() );
		} elseif ( $tt === self::TYPE_ADMIN ) {
			$recipients = array( $b, $s );
		} else {
			$recipients = array( $b, $s );
		}
		$recipients = array_unique( array_filter( array_map( 'intval', $recipients ) ) );
		$subj = sprintf(
			/* translators: %s site name */
			__( '[%s] Новое сообщение в чате', 'umi-marketplace' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
		$excerpt = wp_trim_words( (string) $body_excerpt, 40, '…' );
		$message = $subj . "\n\n" . $excerpt . "\n\n" . $link . "\n";

		foreach ( $recipients as $rid ) {
			if ( (int) $rid === $sender_id ) {
				continue;
			}
			$user = get_userdata( (int) $rid );
			if ( $user && is_email( $user->user_email ) ) {
				wp_mail( $user->user_email, $subj, $message );
			}
		}
	}

	/**
	 * Get thread row.
	 *
	 * @param int $thread_id Thread ID.
	 * @return array|null
	 */
	public static function get_thread( $thread_id ) {
		global $wpdb;
		$table = Umi_Database::threads_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $thread_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Messages after ID.
	 *
	 * @param int $thread_id Thread ID.
	 * @param int $after_id After message ID.
	 * @return array
	 */
	public static function messages_after( $thread_id, $after_id = 0 ) {
		global $wpdb;
		$table = Umi_Database::messages_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE thread_id = %d AND id > %d ORDER BY id ASC",
				$thread_id,
				$after_id
			),
			ARRAY_A
		);
	}

	/**
	 * Mark messages as read for user in thread.
	 *
	 * @param int $thread_id Thread ID.
	 * @param int $user_id User marking read (recipient).
	 */
	public static function mark_thread_read( $thread_id, $user_id ) {
		global $wpdb;
		$thread = self::get_thread( $thread_id );
		if ( ! $thread || ! self::user_can_access_thread( $thread_id, (int) $user_id ) ) {
			return;
		}
		$thread_id = (int) $thread_id;
		$user_id   = (int) $user_id;

		if ( self::uses_read_cursor( $thread ) ) {
			$msgs  = Umi_Database::messages_table();
			$rst   = Umi_Database::read_state_table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM $msgs WHERE thread_id = %d", $thread_id ) );
			$wpdb->replace(
				$rst,
				array(
					'thread_id'     => $thread_id,
					'user_id'       => $user_id,
					'last_read_id'  => $max,
				),
				array( '%d', '%d', '%d' )
			);
			return;
		}

		$table = Umi_Database::messages_table();
		$now   = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET read_at = %s WHERE thread_id = %d AND sender_id != %d AND read_at IS NULL",
				$now,
				$thread_id,
				$user_id
			)
		);
	}

	/**
	 * Unread count for user.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function unread_count_for_user( $user_id ) {
		global $wpdb;
		$threads = Umi_Database::threads_table();
		$msgs    = Umi_Database::messages_table();
		$rst     = Umi_Database::read_state_table();
		$user_id = (int) $user_id;
		$is_mod  = user_can( $user_id, 'manage_options' ) ? 1 : 0;

		// Обычные треды объявлений: read_at.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n_list = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $msgs m
				INNER JOIN $threads t ON t.id = m.thread_id
				WHERE m.read_at IS NULL AND m.sender_id != %d
				AND t.thread_type = %s
				AND (t.buyer_id = %d OR t.seller_id = %d)",
				$user_id,
				self::TYPE_LISTING,
				$user_id,
				$user_id
			)
		);

		// Админ-чат и споры: курсор last_read_id.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM $msgs m
			INNER JOIN $threads t ON t.id = m.thread_id
			LEFT JOIN $rst r ON r.thread_id = t.id AND r.user_id = %d
			WHERE t.thread_type IN ('admin', 'dispute') AND m.sender_id != %d
			AND m.id > IFNULL(r.last_read_id, 0)
			AND (t.buyer_id = %d OR t.seller_id = %d OR (t.thread_type = %s AND %d = 1))",
			$user_id,
			$user_id,
			$user_id,
			$user_id,
			self::TYPE_DISPUTE,
			$is_mod
		);
		$n_ext = (int) $wpdb->get_var( $sql );

		return $n_list + $n_ext;
	}

	/**
	 * User can access thread.
	 *
	 * @param int $thread_id Thread ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_can_access_thread( $thread_id, $user_id ) {
		$thread = self::get_thread( $thread_id );
		if ( ! $thread ) {
			return false;
		}
		$uid = (int) $user_id;
		if ( (int) $uid === (int) $thread['buyer_id'] || (int) $uid === (int) $thread['seller_id'] ) {
			return true;
		}
		$tt = self::thread_type( $thread );
		if ( $tt === self::TYPE_DISPUTE && $uid > 0 && user_can( $uid, 'manage_options' ) ) {
			return true;
		}
		// Модерация: администратор читает/пишет в чате по объявлению (как в карточке сделки в wp-admin).
		if ( $tt === self::TYPE_LISTING && $uid > 0 && user_can( $uid, 'manage_options' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Тред «чат по объявлению» для пары и объекта сделки (get_or_create).
	 *
	 * @param int $deal_id Сделка.
	 * @return int ID треда или 0.
	 */
	public static function get_listing_thread_id_for_deal( $deal_id ) {
		$deal_id    = (int) $deal_id;
		$listing_id = Umi_Deals::get_listing_id( $deal_id );
		if ( $listing_id < 1 ) {
			return 0;
		}
		$l = get_post( $listing_id );
		if ( ! $l || ! in_array( $l->post_type, array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ), true ) ) {
			return 0;
		}
		$buyer  = Umi_Deals::get_buyer_id( $deal_id );
		$seller = Umi_Deals::get_seller_id( $deal_id );
		if ( $buyer < 1 || $seller < 1 ) {
			return 0;
		}
		$lt = self::listing_type_from_cpt( $l->post_type );
		return (int) self::get_or_create_thread( $listing_id, $lt, $buyer, $seller );
	}

	/**
	 * Удалить тред и все сообщения (для обоих участников).
	 *
	 * @param int $thread_id Thread ID.
	 * @return bool
	 */
	public static function delete_thread( $thread_id ) {
		global $wpdb;
		$thread_id = (int) $thread_id;
		if ( $thread_id < 1 ) {
			return false;
		}
		$thread = self::get_thread( $thread_id );
		if ( ! $thread || self::TYPE_LISTING !== self::thread_type( $thread ) ) {
			return false;
		}
		$msgs  = Umi_Database::messages_table();
		$table = Umi_Database::threads_table();
		$rst   = Umi_Database::read_state_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete( $msgs, array( 'thread_id' => $thread_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete( $rst, array( 'thread_id' => $thread_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok = (int) $wpdb->delete( $table, array( 'id' => $thread_id ), array( '%d' ) );
		return $ok > 0;
	}

	/**
	 * Непрочитанные в одном треде (для списка диалогов).
	 *
	 * @param int $thread_id Thread.
	 * @param int $user_id User.
	 * @return int
	 */
	public static function unread_in_thread_for_user( $thread_id, $user_id ) {
		global $wpdb;
		$thread_id = (int) $thread_id;
		$user_id   = (int) $user_id;
		$thread    = self::get_thread( $thread_id );
		if ( ! $thread || ! self::user_can_access_thread( $thread_id, $user_id ) ) {
			return 0;
		}
		$msgs = Umi_Database::messages_table();
		$rst  = Umi_Database::read_state_table();
		if ( self::uses_read_cursor( $thread ) ) {
			$is_mod = user_can( $user_id, 'manage_options' ) ? 1 : 0;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$lr = (int) $wpdb->get_var( $wpdb->prepare( "SELECT last_read_id FROM $rst WHERE thread_id = %d AND user_id = %d", $thread_id, $user_id ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM $msgs m WHERE m.thread_id = %d AND m.sender_id != %d AND m.id > %d",
					$thread_id,
					$user_id,
					$lr
				)
			);
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $msgs m WHERE m.thread_id = %d AND m.read_at IS NULL AND m.sender_id != %d",
				$thread_id,
				$user_id
			)
		);
	}

	/**
	 * Все треды по объявлению, где пользователь — продавец (для чата владельца на странице сингла).
	 *
	 * @param int $listing_id ID поста услуги/товара.
	 * @param int $seller_id   ID продавца (автор объявления).
	 * @return array<int, array<string, mixed>>
	 */
	public static function threads_for_listing_seller( $listing_id, $seller_id ) {
		global $wpdb;
		$listing_id = (int) $listing_id;
		$seller_id  = (int) $seller_id;
		$post       = get_post( $listing_id );
		if ( ! $post || ! in_array( $post->post_type, array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ), true ) ) {
			return array();
		}
		$lt    = self::listing_type_from_cpt( $post->post_type );
		$table = Umi_Database::threads_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, buyer_id FROM $table WHERE listing_id = %d AND listing_type = %s AND seller_id = %d AND thread_type = %s ORDER BY id DESC",
				$listing_id,
				$lt,
				$seller_id,
				self::TYPE_LISTING
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$bid = (int) $row['buyer_id'];
			$u   = $bid ? get_userdata( $bid ) : null;
			$out[] = array(
				'id'         => (int) $row['id'],
				'buyer_id'   => $bid,
				'other_name' => $u ? (string) $u->display_name : '',
			);
		}
		return $out;
	}

	/**
	 * Диалоги пользователя (для кабинета).
	 *
	 * @param int $user_id User.
	 * @param int $limit   Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function threads_for_user( $user_id, $limit = 30 ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$limit   = min( 100, max( 1, (int) $limit ) );
		$threads = Umi_Database::threads_table();
		$lim     = (int) $limit;
		$is_mod  = user_can( $user_id, 'manage_options' ) ? 1 : 0;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT t.id, t.listing_id, t.listing_type, t.buyer_id, t.seller_id, t.thread_type, t.deal_id, p.post_title
			FROM $threads t
			LEFT JOIN {$wpdb->posts} p ON p.ID = t.listing_id
			WHERE ((t.buyer_id = %d OR t.seller_id = %d) AND t.thread_type != %s)
			OR (t.thread_type = %s AND %d = 1)
			ORDER BY t.id DESC
			LIMIT " . (int) $lim,
			$user_id,
			$user_id,
			self::TYPE_ADMIN,
			self::TYPE_DISPUTE,
			$is_mod
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$tt = self::thread_type( $row );
			$oid = (int) ( $user_id === (int) $row['buyer_id'] ? $row['seller_id'] : $row['buyer_id'] );
			$other = $oid ? get_userdata( $oid ) : null;
			$other_name    = $other ? (string) $other->display_name : '';
			$listing_title = $row['post_title'] ? (string) $row['post_title'] : '';
			if ( self::TYPE_ADMIN === $tt ) {
				$sup = get_userdata( (int) self::support_user_id() );
				$other_name    = ( $user_id === (int) $row['buyer_id'] && $sup ) ? (string) $sup->display_name : $other_name;
				$listing_title = $listing_title ? $listing_title : __( 'Администратор', 'umi-marketplace' );
			} elseif ( self::TYPE_DISPUTE === $tt ) {
				$did = isset( $row['deal_id'] ) ? (int) $row['deal_id'] : 0;
				$listing_title = $did
					? (string) sprintf( /* translators: %d deal */ __( 'Спор · сделка #%d', 'umi-marketplace' ), $did )
					: __( 'Спор', 'umi-marketplace' );
			}
			$unread = self::unread_in_thread_for_user( (int) $row['id'], $user_id );
			$out[]  = array(
				'thread_id'     => (int) $row['id'],
				'listing_id'    => (int) $row['listing_id'],
				'listing_type'  => (string) $row['listing_type'],
				'thread_type'   => $tt,
				'deal_id'       => isset( $row['deal_id'] ) ? (int) $row['deal_id'] : 0,
				'listing_title' => $listing_title,
				'other_name'    => $other_name,
				'unread_count'  => $unread,
			);
		}
		return $out;
	}
}
