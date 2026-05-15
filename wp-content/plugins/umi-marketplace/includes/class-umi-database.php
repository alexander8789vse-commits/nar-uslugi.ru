<?php
/**
 * Custom tables.
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Database
 */
class Umi_Database {

	const DB_VERSION = '1.1.0';

	/**
	 * Create / upgrade tables.
	 */
	public static function install() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$ledger = $wpdb->prefix . 'umi_ledger';
		$threads = $wpdb->prefix . 'umi_chat_threads';
		$messages = $wpdb->prefix . 'umi_chat_messages';
		$readst = $wpdb->prefix . 'umi_chat_read_state';

		$sql_ledger = "CREATE TABLE $ledger (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			admin_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL,
			type varchar(32) NOT NULL,
			shares_delta decimal(18,4) NOT NULL DEFAULT 0,
			rub_delta decimal(18,2) DEFAULT NULL,
			rub_rate_used decimal(18,6) DEFAULT NULL,
			comment text,
			deal_id bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY created_at (created_at),
			KEY type (type)
		) $charset_collate;";

		$sql_threads = "CREATE TABLE $threads (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			listing_id bigint(20) unsigned NOT NULL,
			listing_type varchar(20) NOT NULL,
			buyer_id bigint(20) unsigned NOT NULL,
			seller_id bigint(20) unsigned NOT NULL,
			thread_type varchar(20) NOT NULL DEFAULT 'listing',
			deal_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY umi_thread_uk (listing_id, listing_type(20), buyer_id, thread_type(20)),
			KEY seller_id (seller_id),
			KEY buyer_id (buyer_id),
			KEY thread_type (thread_type),
			KEY deal_id (deal_id)
		) $charset_collate;";

		$sql_messages = "CREATE TABLE $messages (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			thread_id bigint(20) unsigned NOT NULL,
			sender_id bigint(20) unsigned NOT NULL,
			body longtext NOT NULL,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			read_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY thread_id (thread_id),
			KEY sender_id (sender_id)
		) $charset_collate;";

		$sql_read = "CREATE TABLE $readst (
			thread_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			last_read_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (thread_id, user_id),
			KEY user_id (user_id)
		) $charset_collate;";

		dbDelta( $sql_ledger );
		dbDelta( $sql_threads );
		dbDelta( $sql_messages );
		dbDelta( $sql_read );

		self::upgrade_schema( $threads, $messages, $readst );

		update_option( 'umi_mp_db_version', self::DB_VERSION );
	}

	/**
	 * Migrations for sites activated before 1.1.0.
	 *
	 * @param string $threads Threads table.
	 * @param string $messages Messages table.
	 * @param string $readst Read state table.
	 */
	private static function upgrade_schema( $threads, $messages, $readst ) {
		global $wpdb;

		$v = get_option( 'umi_mp_db_version', '0' );
		if ( version_compare( (string) $v, '1.1.0', '>=' ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_tt = $wpdb->get_results( "SHOW COLUMNS FROM {$threads} LIKE 'thread_type'" );
		if ( empty( $has_tt ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE {$threads} ADD COLUMN thread_type varchar(20) NOT NULL DEFAULT 'listing' AFTER seller_id" );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$has_deal = $wpdb->get_results( "SHOW COLUMNS FROM {$threads} LIKE 'deal_id'" );
		if ( empty( $has_deal ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE {$threads} ADD COLUMN deal_id bigint(20) unsigned DEFAULT NULL AFTER thread_type" );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$old_idx = $wpdb->get_results( "SHOW INDEX FROM {$threads} WHERE Key_name = 'listing_buyer'" );
		if ( ! empty( $old_idx ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE {$threads} DROP INDEX listing_buyer" );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$new_idx = $wpdb->get_results( "SHOW INDEX FROM {$threads} WHERE Key_name = 'umi_thread_uk'" );
		if ( empty( $new_idx ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE {$threads} ADD UNIQUE KEY umi_thread_uk (listing_id, listing_type(20), buyer_id, thread_type(20))" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$has_att = $wpdb->get_results( "SHOW COLUMNS FROM {$messages} LIKE 'attachment_id'" );
		if ( empty( $has_att ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE {$messages} ADD COLUMN attachment_id bigint(20) unsigned NOT NULL DEFAULT 0 AFTER body" );
		}

		$readst_esc = esc_sql( $readst );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$read_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$readst_esc}'" );
		if ( $read_exists !== $readst ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$charset_collate = $wpdb->get_charset_collate();
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			dbDelta(
				"CREATE TABLE {$readst} (
					thread_id bigint(20) unsigned NOT NULL,
					user_id bigint(20) unsigned NOT NULL,
					last_read_id bigint(20) unsigned NOT NULL DEFAULT 0,
					PRIMARY KEY (thread_id, user_id),
					KEY user_id (user_id)
				) $charset_collate;"
			);
		}
	}

	/**
	 * Run on init if plugin updated without re-activation.
	 */
	public static function maybe_install() {
		$v = get_option( 'umi_mp_db_version', '0' );
		if ( version_compare( (string) $v, self::DB_VERSION, '<' ) ) {
			self::install();
		}
	}

	/**
	 * Ledger table name.
	 */
	public static function ledger_table() {
		global $wpdb;
		return $wpdb->prefix . 'umi_ledger';
	}

	/**
	 * Chat threads table.
	 */
	public static function threads_table() {
		global $wpdb;
		return $wpdb->prefix . 'umi_chat_threads';
	}

	/**
	 * Chat messages table.
	 */
	public static function messages_table() {
		global $wpdb;
		return $wpdb->prefix . 'umi_chat_messages';
	}

	/**
	 * Per-user read cursor (admin / dispute threads).
	 */
	public static function read_state_table() {
		global $wpdb;
		return $wpdb->prefix . 'umi_chat_read_state';
	}
}
