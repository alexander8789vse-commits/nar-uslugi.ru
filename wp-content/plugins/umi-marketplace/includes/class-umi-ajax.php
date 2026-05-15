<?php
/**
 * AJAX handlers.
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Ajax
 */
class Umi_Ajax {

	const NONCE_ACTION  = 'umi_mp_chat';
	const NONCE_UPLOAD  = 'umi_mp_upload';
	const NONCE_ALERT   = 'umi_alert_admin';
	const MAX_UPLOAD_B  = 5242880;

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_action( 'wp_ajax_umi_mp_chat_poll', array( __CLASS__, 'chat_poll' ) );
		add_action( 'wp_ajax_umi_mp_chat_send', array( __CLASS__, 'chat_send' ) );
		add_action( 'wp_ajax_umi_mp_chat_delete_thread', array( __CLASS__, 'chat_delete_thread' ) );
		add_action( 'wp_ajax_umi_mp_unread', array( __CLASS__, 'unread' ) );
		add_action( 'wp_ajax_umi_mp_upload', array( __CLASS__, 'upload_image' ) );
		add_action( 'wp_ajax_umi_mp_dispute_upload', array( __CLASS__, 'dispute_upload_image' ) );
		add_action( 'wp_ajax_umi_mp_favorite', array( __CLASS__, 'favorite_toggle' ) );
		add_action( 'wp_ajax_umi_mp_alert_admin',        array( __CLASS__, 'alert_admin' ) );
		add_action( 'wp_ajax_nopriv_umi_mp_alert_admin', array( __CLASS__, 'alert_admin' ) );
	}

	/**
	 * Verify nonce.
	 */
	private static function verify() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'auth' ), 401 );
		}
	}

	/**
	 * Poll new messages.
	 */
	public static function chat_poll() {
		self::verify();
		$thread_id = isset( $_POST['thread_id'] ) ? (int) $_POST['thread_id'] : 0;
		$after_id  = isset( $_POST['after_id'] ) ? (int) $_POST['after_id'] : 0;
		if ( ! $thread_id || ! Umi_Chat::user_can_access_thread( $thread_id, get_current_user_id() ) ) {
			wp_send_json_error( array( 'message' => 'thread' ), 403 );
		}

		Umi_Chat::mark_thread_read( $thread_id, get_current_user_id() );

		$rows = Umi_Chat::messages_after( $thread_id, $after_id );
		$out  = array();
		foreach ( $rows as $row ) {
			$u  = get_userdata( (int) $row['sender_id'] );
			$aid = isset( $row['attachment_id'] ) ? (int) $row['attachment_id'] : 0;
			$aurl = '';
			if ( $aid > 0 ) {
				$u1 = wp_get_attachment_image_url( $aid, 'large' );
				$aurl = $u1 ? (string) $u1 : (string) wp_get_attachment_url( $aid );
			}
			$out[] = array(
				'id'              => (int) $row['id'],
				'sender'          => $u ? $u->display_name : '',
				'body'            => wp_kses_post( $row['body'] ),
				'created_at'      => $row['created_at'],
				'is_mine'         => (int) $row['sender_id'] === get_current_user_id(),
				'attachment_id'   => $aid,
				'attachment_url'  => $aurl,
			);
		}

		wp_send_json_success(
			array(
				'messages' => $out,
				'unread'   => Umi_Chat::unread_count_for_user( get_current_user_id() ),
			)
		);
	}

	/**
	 * Send chat message.
	 */
	public static function chat_send() {
		self::verify();
		$thread_id     = isset( $_POST['thread_id'] ) ? (int) $_POST['thread_id'] : 0;
		$message       = isset( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : '';
		$attachment_id = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;
		if ( ! $thread_id || ! Umi_Chat::user_can_access_thread( $thread_id, get_current_user_id() ) ) {
			wp_send_json_error( array( 'message' => 'thread' ), 403 );
		}

		$id = Umi_Chat::add_message( $thread_id, get_current_user_id(), $message, $attachment_id );
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => 'empty' ), 400 );
		}

		wp_send_json_success(
			array(
				'id'       => $id,
				'unread'   => Umi_Chat::unread_count_for_user( get_current_user_id() ),
			)
		);
	}

	/**
	 * Удалить диалог (и сообщения) — участник треда.
	 */
	public static function chat_delete_thread() {
		self::verify();
		$thread_id = isset( $_POST['thread_id'] ) ? (int) $_POST['thread_id'] : 0;
		$uid       = get_current_user_id();
		if ( ! $thread_id || ! Umi_Chat::user_can_access_thread( $thread_id, $uid ) ) {
			wp_send_json_error( array( 'message' => 'thread' ), 403 );
		}
		if ( ! Umi_Chat::delete_thread( $thread_id ) ) {
			wp_send_json_error( array( 'message' => 'delete' ), 500 );
		}
		wp_send_json_success(
			array(
				'unread' => Umi_Chat::unread_count_for_user( $uid ),
			)
		);
	}

	/**
	 * Unread count only.
	 */
	public static function unread() {
		self::verify();
		wp_send_json_success(
			array(
				'unread' => Umi_Chat::unread_count_for_user( get_current_user_id() ),
			)
		);
	}

	/**
	 * Загрузка изображения в кабинете (владелец вложения — текущий пользователь).
	 */
	public static function upload_image() {
		check_ajax_referer( self::NONCE_UPLOAD, 'nonce' );
		if ( ! is_user_logged_in() || ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => 'cap' ), 403 );
		}
		if ( empty( $_FILES['file'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => 'file' ), 400 );
		}
		$f = $_FILES['file'];
		if ( ! empty( $f['error'] ) || ! isset( $f['size'] ) || (int) $f['size'] < 1 ) {
			wp_send_json_error( array( 'message' => 'file' ), 400 );
		}
		$max = min( (int) wp_max_upload_size(), self::MAX_UPLOAD_B );
		if ( (int) $f['size'] > $max ) {
			wp_send_json_error( array( 'message' => 'size' ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$over = array( 'test_form' => false );
		$move = wp_handle_upload( $f, $over );
		if ( isset( $move['error'] ) ) {
			wp_send_json_error( array( 'message' => (string) $move['error'] ), 400 );
		}
		$type = isset( $move['type'] ) ? (string) $move['type'] : '';
		if ( $type === '' || 0 !== strpos( $type, 'image/' ) ) {
			if ( ! empty( $move['file'] ) && is_string( $move['file'] ) && is_file( $move['file'] ) ) {
				wp_delete_file( $move['file'] );
			}
			wp_send_json_error( array( 'message' => 'type' ), 400 );
		}

		$uid  = get_current_user_id();
		$att  = array(
			'post_mime_type' => $type,
			'post_title'     => sanitize_file_name( pathinfo( (string) $move['file'], PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => $uid,
		);
		$att_id = wp_insert_attachment( $att, (string) $move['file'] );
		if ( is_wp_error( $att_id ) || ! $att_id ) {
			if ( ! empty( $move['file'] ) && is_file( (string) $move['file'] ) ) {
				wp_delete_file( (string) $move['file'] );
			}
			wp_send_json_error( array( 'message' => 'attach' ), 500 );
		}
		$meta = wp_generate_attachment_metadata( (int) $att_id, (string) $move['file'] );
		if ( is_array( $meta ) ) {
			wp_update_attachment_metadata( (int) $att_id, $meta );
		}
		wp_send_json_success(
			array(
				'id'  => (int) $att_id,
				'url' => (string) wp_get_attachment_url( (int) $att_id ),
			)
		);
	}

	/**
	 * Изображение к сообщению в чате спора (макс. 2 МБ).
	 */
	public static function dispute_upload_image() {
		check_ajax_referer( self::NONCE_UPLOAD, 'nonce' );
		if ( ! is_user_logged_in() || ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => 'cap' ), 403 );
		}
		$thread_id = isset( $_POST['thread_id'] ) ? (int) $_POST['thread_id'] : 0;
		if ( ! $thread_id || ! Umi_Chat::user_can_access_thread( $thread_id, get_current_user_id() ) ) {
			wp_send_json_error( array( 'message' => 'thread' ), 403 );
		}
		$th = Umi_Chat::get_thread( $thread_id );
		if ( ! $th || Umi_Chat::TYPE_DISPUTE !== Umi_Chat::thread_type( $th ) ) {
			wp_send_json_error( array( 'message' => 'thread' ), 403 );
		}
		if ( empty( $_FILES['file'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => 'file' ), 400 );
		}
		$f = $_FILES['file'];
		if ( ! empty( $f['error'] ) || ! isset( $f['size'] ) || (int) $f['size'] < 1 ) {
			wp_send_json_error( array( 'message' => 'file' ), 400 );
		}
		if ( (int) $f['size'] > Umi_Chat::MAX_DISPUTE_IMAGE_B ) {
			wp_send_json_error( array( 'message' => 'size' ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$over = array( 'test_form' => false );
		$move = wp_handle_upload( $f, $over );
		if ( isset( $move['error'] ) ) {
			wp_send_json_error( array( 'message' => (string) $move['error'] ), 400 );
		}
		$type = isset( $move['type'] ) ? (string) $move['type'] : '';
		if ( $type === '' || 0 !== strpos( $type, 'image/' ) ) {
			if ( ! empty( $move['file'] ) && is_string( $move['file'] ) && is_file( $move['file'] ) ) {
				wp_delete_file( $move['file'] );
			}
			wp_send_json_error( array( 'message' => 'type' ), 400 );
		}

		$uid    = get_current_user_id();
		$att    = array(
			'post_mime_type' => $type,
			'post_title'     => sanitize_file_name( pathinfo( (string) $move['file'], PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => $uid,
		);
		$att_id = wp_insert_attachment( $att, (string) $move['file'] );
		if ( is_wp_error( $att_id ) || ! $att_id ) {
			if ( ! empty( $move['file'] ) && is_file( (string) $move['file'] ) ) {
				wp_delete_file( (string) $move['file'] );
			}
			wp_send_json_error( array( 'message' => 'attach' ), 500 );
		}
		$meta = wp_generate_attachment_metadata( (int) $att_id, (string) $move['file'] );
		if ( is_array( $meta ) ) {
			wp_update_attachment_metadata( (int) $att_id, $meta );
		}
		$path = get_attached_file( (int) $att_id );
		if ( $path && is_readable( $path ) && (int) filesize( $path ) > Umi_Chat::MAX_DISPUTE_IMAGE_B ) {
			wp_delete_attachment( (int) $att_id, true );
			wp_send_json_error( array( 'message' => 'size' ), 400 );
		}
		wp_send_json_success(
			array(
				'id'  => (int) $att_id,
				'url' => (string) wp_get_attachment_url( (int) $att_id ),
			)
		);
	}

	/**
	 * Уведомить администратора об объявлении.
	 */
	public static function alert_admin() {
		check_ajax_referer( self::NONCE_ALERT, 'nonce' );

		$listing_id = isset( $_POST['listing_id'] ) ? (int) $_POST['listing_id'] : 0;
		$post       = $listing_id ? get_post( $listing_id ) : null;
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'invalid_listing' ), 400 );
		}

		$listing_title = get_the_title( $post );
		$listing_url   = get_permalink( $post );

		if ( is_user_logged_in() ) {
			$user      = wp_get_current_user();
			$who_line  = sprintf( 'Пользователь: %s (ID %d, %s)', $user->display_name, $user->ID, $user->user_email );
		} else {
			$who_line  = 'Пользователь: не авторизован';
		}

		$to      = get_option( 'admin_email' );
		$subject = sprintf( '[%s] Запрос на привлечение администратора', get_bloginfo( 'name' ) );
		$message = implode( "\n", array(
			'Поступил запрос на привлечение администратора.',
			'',
			'Объявление: ' . $listing_title,
			'Ссылка: ' . $listing_url,
			$who_line,
			'IP: ' . ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ),
		) );

		$sent = wp_mail( $to, $subject, $message );
		if ( $sent ) {
			wp_send_json_success( array( 'message' => 'sent' ) );
		} else {
			wp_send_json_error( array( 'message' => 'mail_failed' ), 500 );
		}
	}

	/**
	 * Добавить/убрать объявление в избранном.
	 */
	public static function favorite_toggle() {
		self::verify();
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$r       = Umi_Favorites::toggle( get_current_user_id(), $post_id );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ), 400 );
		}
		wp_send_json_success(
			array(
				'favorited' => (bool) $r,
			)
		);
	}
}
