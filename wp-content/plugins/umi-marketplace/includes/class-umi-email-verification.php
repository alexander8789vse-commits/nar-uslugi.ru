<?php
/**
 * Подтверждение email при регистрации на фронте.
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Email_Verification
 */
class Umi_Email_Verification {

	const META_VERIFIED = 'umi_email_verified';
	const META_KEY      = 'umi_email_verify_key';

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_verify_link' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_resend_post' ), 6 );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_unverified' ), 20, 2 );
	}

	/**
	 * Страница входа после подтверждения и для формы «отправить снова».
	 *
	 * @return string
	 */
	public static function login_page_url() {
		/**
		 * URL страницы с шорткодом [umi_login] (например /vhod/).
		 *
		 * @param string $url URL.
		 */
		return (string) apply_filters( 'umi_login_url', home_url( '/vhod/' ) );
	}

	/**
	 * Нужно ли блокировать вход (ожидание подтверждения).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_must_verify( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return false;
		}
		$v = get_user_meta( $user_id, self::META_VERIFIED, true );
		return '0' === (string) $v;
	}

	/**
	 * Вызывается после успешной регистрации через [umi_register].
	 *
	 * @param int $user_id User ID.
	 */
	public static function after_registration( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return;
		}
		update_user_meta( $user_id, self::META_VERIFIED, '0' );
		$plain = wp_generate_password( 48, false, false );
		update_user_meta( $user_id, self::META_KEY, wp_hash_password( $plain ) );
		self::send_verification_email( $user_id, $plain );
	}

	/**
	 * @param int    $user_id User ID.
	 * @param string $plain_key Одноразовый ключ (до хеширования в meta).
	 */
	private static function send_verification_email( $user_id, $plain_key ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}
		$link = add_query_arg(
			array(
				'umi_verify' => '1',
				'user'       => $user_id,
				'key'        => rawurlencode( $plain_key ),
			),
			home_url( '/' )
		);
		$blog = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Подтверждение email', 'umi-marketplace' ), $blog );
		$message = sprintf(
			/* translators: 1: site name, 2: confirmation link */
			__(
				"Здравствуйте!

Подтвердите регистрацию на сайте «%1\$s», перейдя по ссылке:

%2\$s

Если вы не регистрировались, проигнорируйте письмо.
",
				'umi-marketplace'
			),
			$blog,
			$link
		);
		wp_mail( $user->user_email, $subject, $message );
	}

	/**
	 * Обработка ссылки из письма.
	 */
	public static function handle_verify_link() {
		if ( ! isset( $_GET['umi_verify'] ) || '1' !== (string) wp_unslash( $_GET['umi_verify'] ) ) {
			return;
		}
		$uid = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( rawurldecode( (string) $_GET['key'] ) ) ) : '';
		if ( $uid < 1 || $key === '' ) {
			self::redirect_after_verify( 'invalid' );
		}
		$stored = get_user_meta( $uid, self::META_KEY, true );
		if ( ! is_string( $stored ) || $stored === '' || ! wp_check_password( $key, $stored ) ) {
			self::redirect_after_verify( 'invalid' );
		}
		update_user_meta( $uid, self::META_VERIFIED, '1' );
		delete_user_meta( $uid, self::META_KEY );
		self::redirect_after_verify( 'ok' );
	}

	/**
	 * @param string $status ok|invalid.
	 */
	private static function redirect_after_verify( $status ) {
		$login = self::login_page_url();
		if ( 'ok' === $status ) {
			wp_safe_redirect( add_query_arg( 'umi_verified', '1', $login ) );
		} else {
			wp_safe_redirect( add_query_arg( 'umi_verify', 'invalid', $login ) );
		}
		exit;
	}

	/**
	 * Повторная отправка письма (форма на странице входа).
	 */
	public static function handle_resend_post() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}
		if ( empty( $_POST['umi_resend_action'] ) ) {
			return;
		}
		if ( ! isset( $_POST['umi_resend_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['umi_resend_nonce'] ) ), 'umi_resend_verification' ) ) {
			return;
		}
		$email = isset( $_POST['umi_resend_email'] ) ? sanitize_email( wp_unslash( $_POST['umi_resend_email'] ) ) : '';
		$redirect_base = self::login_page_url();
		if ( ! is_email( $email ) ) {
			wp_safe_redirect( add_query_arg( 'umi_resend', 'bad_email', $redirect_base ) );
			exit;
		}
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			wp_safe_redirect( add_query_arg( 'umi_resend', 'sent', $redirect_base ) );
			exit;
		}
		if ( user_can( $user, 'manage_options' ) || ! self::user_must_verify( $user->ID ) ) {
			wp_safe_redirect( add_query_arg( 'umi_resend', 'already', $redirect_base ) );
			exit;
		}
		$plain = wp_generate_password( 48, false, false );
		update_user_meta( $user->ID, self::META_KEY, wp_hash_password( $plain ) );
		self::send_verification_email( $user->ID, $plain );
		wp_safe_redirect( add_query_arg( 'umi_resend', 'sent', $redirect_base ) );
		exit;
	}

	/**
	 * @param WP_User|WP_Error $user     User или ошибка.
	 * @param string           $password Пароль (не используем).
	 * @return WP_User|WP_Error
	 */
	public static function block_unverified( $user, $password ) {
		unset( $password );
		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			return $user;
		}
		/**
		 * Отключить проверку email (например на тестовом стенде).
		 *
		 * @param bool   $required Требовать подтверждение.
		 * @param WP_User $user    Пользователь.
		 */
		if ( ! apply_filters( 'umi_require_email_verification', true, $user ) ) {
			return $user;
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return $user;
		}
		if ( self::user_must_verify( $user->ID ) ) {
			return new WP_Error(
				'umi_email_not_verified',
				__( 'Сначала подтвердите email — перейдите по ссылке из письма или запросите письмо снова ниже.', 'umi-marketplace' )
			);
		}
		return $user;
	}
}
