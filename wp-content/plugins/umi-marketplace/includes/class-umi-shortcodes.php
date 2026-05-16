<?php
/**
 * Front-end shortcodes.
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Shortcodes
 */
class Umi_Shortcodes {

	/**
	 * Объявления, для которых блок чата уже выведен (дедупликация [umi_listing_card] + [umi_chat]).
	 *
	 * @var array<int, true>
	 */
	private static $umi_chat_listing_done = array();

	/**
	 * Текущая страница для WP_Query (страница записи vs архив).
	 *
	 * @return int
	 */
	private static function current_page() {
		$paged = (int) get_query_var( 'paged' );
		if ( $paged > 0 ) {
			return $paged;
		}
		$page = (int) get_query_var( 'page' );
		if ( $page > 0 ) {
			return $page;
		}
		if ( isset( $_GET['paged'] ) ) {
			return max( 1, (int) $_GET['paged'] );
		}
		return 1;
	}

	/**
	 * Register shortcodes.
	 */
	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'deal_post_handler' ), 4 );
		add_action( 'template_redirect', array( __CLASS__, 'cabinet_post_handler' ), 5 );
		add_shortcode( 'umi_services', array( __CLASS__, 'services' ) );
		add_shortcode( 'umi_products', array( __CLASS__, 'products' ) );
		add_shortcode( 'umi_become_seller', array( __CLASS__, 'become_seller' ) );
		add_shortcode( 'umi_user_cabinet', array( __CLASS__, 'user_cabinet' ) );
		add_shortcode( 'umi_seller_cabinet', array( __CLASS__, 'seller_cabinet' ) );
		add_shortcode( 'umi_register', array( __CLASS__, 'register_form' ) );
		add_shortcode( 'umi_login', array( __CLASS__, 'login_form' ) );
		add_shortcode( 'umi_balance', array( __CLASS__, 'balance' ) );
		add_shortcode( 'umi_chat', array( __CLASS__, 'chat' ) );
		add_shortcode( 'umi_unread_badge', array( __CLASS__, 'unread_badge' ) );
		add_shortcode( 'umi_contact_seller', array( __CLASS__, 'contact_seller' ) );
		add_shortcode( 'umi_header_toolbar', array( __CLASS__, 'header_toolbar' ) );
		add_shortcode( 'umi_listing_card', array( __CLASS__, 'listing_card' ) );
		add_shortcode( 'umi_seller_profile', array( __CLASS__, 'seller_profile' ) );
		add_shortcode( 'umi_deals', array( __CLASS__, 'deals' ) );
		add_shortcode( 'umi_favorites', array( __CLASS__, 'favorites' ) );
		add_action( 'wp', array( __CLASS__, 'maybe_seller_profile_seo' ), 5 );
	}

	/**
	 * POST: создание сделки, действия, отзыв.
	 */
	public static function deal_post_handler() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '' ) ) {
			return;
		}
		if ( empty( $_POST['umi_deal_form'] ) || '1' !== (string) $_POST['umi_deal_form'] ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( ! isset( $_POST['umi_deal_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['umi_deal_nonce'] ) ), 'umi_deal_action' ) ) {
			return;
		}
		$uid    = get_current_user_id();
		$return = isset( $_POST['umi_deal_return'] ) ? esc_url_raw( wp_unslash( $_POST['umi_deal_return'] ) ) : wp_get_referer();
		$return = wp_validate_redirect( $return, home_url( '/' ) );
		$sub    = isset( $_POST['umi_deal_sub'] ) ? sanitize_key( wp_unslash( $_POST['umi_deal_sub'] ) ) : '';

		if ( 'create' === $sub ) {
			$lid = isset( $_POST['umi_listing_id'] ) ? (int) $_POST['umi_listing_id'] : 0;
			$r   = Umi_Deals::create_by_buyer( $lid, $uid );
			if ( is_wp_error( $r ) ) {
				set_transient( 'umi_deal_flash_' . $uid, array( 'error' => $r->get_error_message() ), 120 );
			} else {
				set_transient( 'umi_deal_flash_' . $uid, array( 'success' => __( 'Сделка создана.', 'umi-marketplace' ) ), 120 );
				$return = add_query_arg( 'umi_deal', (int) $r, $return );
			}
			wp_safe_redirect( $return . '#umi-deals' );
			exit;
		}

		if ( 'apply' === $sub ) {
			$deal_id = isset( $_POST['umi_deal_id'] ) ? (int) $_POST['umi_deal_id'] : 0;
			$action  = isset( $_POST['umi_deal_action_name'] ) ? sanitize_key( wp_unslash( $_POST['umi_deal_action_name'] ) ) : '';
			$r       = Umi_Deals::do_action( $action, $deal_id, $uid );
			if ( is_wp_error( $r ) ) {
				set_transient( 'umi_deal_flash_' . $uid, array( 'error' => $r->get_error_message() ), 120 );
			} else {
				set_transient( 'umi_deal_flash_' . $uid, array( 'success' => __( 'Готово.', 'umi-marketplace' ) ), 120 );
			}
			$return = remove_query_arg( 'umi_deal', $return );
			$return = add_query_arg( 'umi_deal', $deal_id, $return );
			wp_safe_redirect( $return . '#umi-deals' );
			exit;
		}

		if ( 'review' === $sub ) {
			if ( ! isset( $_POST['umi_deal_review_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['umi_deal_review_nonce'] ) ), 'umi_deal_review' ) ) {
				return;
			}
			$deal_id = isset( $_POST['umi_review_deal_id'] ) ? (int) $_POST['umi_review_deal_id'] : 0;
			$to_user = isset( $_POST['umi_review_to'] ) ? (int) $_POST['umi_review_to'] : 0;
			$rating  = isset( $_POST['umi_review_rating'] ) ? (int) $_POST['umi_review_rating'] : 5;
			$raw     = isset( $_POST['umi_review_content'] ) ? wp_unslash( $_POST['umi_review_content'] ) : '';
			$content = wp_kses_post( $raw );
			$r       = Umi_Reviews::create(
				array(
					'deal_id'   => $deal_id,
					'from_user' => $uid,
					'to_user'   => $to_user,
					'rating'    => $rating,
					'content'   => $content,
				)
			);
			if ( is_wp_error( $r ) ) {
				set_transient( 'umi_deal_flash_' . $uid, array( 'error' => $r->get_error_message() ), 120 );
			} else {
				set_transient( 'umi_deal_flash_' . $uid, array( 'success' => __( 'Отзыв сохранён.', 'umi-marketplace' ) ), 120 );
			}
			$return = remove_query_arg( 'umi_deal', $return );
			$return = add_query_arg( 'umi_deal', $deal_id, $return );
			wp_safe_redirect( $return . '#umi-deals' );
			exit;
		}
	}

	/**
	 * Обработка форм кабинета (до вывода HTML).
	 */
	public static function cabinet_post_handler() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '' ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( empty( $_POST['umi_cabinet'] ) || '1' !== (string) $_POST['umi_cabinet'] ) {
			return;
		}
		$action = isset( $_POST['umi_cabinet_action'] ) ? sanitize_key( wp_unslash( $_POST['umi_cabinet_action'] ) ) : '';
		if ( 'service' === $action ) {
			if ( ! isset( $_POST['umi_cabinet_nonce_s'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['umi_cabinet_nonce_s'] ) ), 'umi_cabinet_add_service' ) ) {
				return;
			}
			self::process_cabinet_add( Umi_Cpt::SERVICE );
		} elseif ( 'product' === $action ) {
			if ( ! isset( $_POST['umi_cabinet_nonce_p'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['umi_cabinet_nonce_p'] ) ), 'umi_cabinet_add_product' ) ) {
				return;
			}
			self::process_cabinet_add( Umi_Cpt::PRODUCT );
		} elseif ( 'edit_service' === $action ) {
			if ( ! isset( $_POST['umi_cabinet_nonce_edit_s'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['umi_cabinet_nonce_edit_s'] ) ), 'umi_cabinet_edit_service' ) ) {
				return;
			}
			self::process_cabinet_edit( Umi_Cpt::SERVICE );
		} elseif ( 'edit_product' === $action ) {
			if ( ! isset( $_POST['umi_cabinet_nonce_edit_p'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['umi_cabinet_nonce_edit_p'] ) ), 'umi_cabinet_edit_product' ) ) {
				return;
			}
			self::process_cabinet_edit( Umi_Cpt::PRODUCT );
		} elseif ( 'delete_listing' === $action ) {
			if ( ! isset( $_POST['umi_cabinet_nonce_del'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['umi_cabinet_nonce_del'] ) ), 'umi_cabinet_delete_listing' ) ) {
				return;
			}
			self::process_cabinet_delete();
		} elseif ( 'profile' === $action ) {
			if ( ! isset( $_POST['umi_cabinet_nonce_profile'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['umi_cabinet_nonce_profile'] ) ), 'umi_cabinet_profile' ) ) {
				return;
			}
			$return = isset( $_POST['umi_cabinet_return'] ) ? esc_url_raw( wp_unslash( $_POST['umi_cabinet_return'] ) ) : home_url( '/' );
			$return = wp_validate_redirect( $return, home_url( '/' ) );
			$uid    = get_current_user_id();
			$att    = isset( $_POST['umi_profile_photo_id'] ) ? (int) $_POST['umi_profile_photo_id'] : 0;
			$clear  = ! empty( $_POST['umi_profile_photo_clear'] ) && '1' === (string) $_POST['umi_profile_photo_clear'];
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $clear ) {
				delete_user_meta( $uid, 'umi_profile_photo' );
				set_transient( 'umi_cabinet_flash_' . $uid, array( 'success' => __( 'Профиль обновлён.', 'umi-marketplace' ) ), 60 );
			} elseif ( $att > 0 ) {
				$p = get_post( $att );
				$ok = $p && 'attachment' === $p->post_type && (int) $p->post_author === $uid
					&& 0 === strpos( (string) $p->post_mime_type, 'image/' );
				if ( $ok ) {
					update_user_meta( $uid, 'umi_profile_photo', $att );
					set_transient( 'umi_cabinet_flash_' . $uid, array( 'success' => __( 'Профиль обновлён.', 'umi-marketplace' ) ), 60 );
				} else {
					set_transient( 'umi_cabinet_flash_' . $uid, array( 'error' => __( 'Не удалось сохранить фото профиля.', 'umi-marketplace' ) ), 60 );
				}
			}
			wp_safe_redirect( $return . '#umi-cabinet' );
			exit;
		}
	}

	/**
	 * Создание объявления из кабинета.
	 *
	 * @param string $post_type CPT.
	 */
	private static function process_cabinet_add( $post_type ) {
		$user = wp_get_current_user();
		if ( ! in_array( Umi_Roles::ROLE_SELLER, (array) $user->roles, true ) ) {
			return;
		}
		$return = isset( $_POST['umi_cabinet_return'] ) ? esc_url_raw( wp_unslash( $_POST['umi_cabinet_return'] ) ) : home_url( '/' );
		$return = wp_validate_redirect( $return, home_url( '/' ) );

		$cap = ( Umi_Cpt::SERVICE === $post_type ) ? 'edit_umi_services' : 'edit_umi_products';
		if ( ! current_user_can( $cap ) ) {
			return;
		}

		$prefix = ( Umi_Cpt::SERVICE === $post_type ) ? 'umi_s_' : 'umi_p_';
		$title  = isset( $_POST[ $prefix . 'title' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $prefix . 'title' ] ) ) : '';
		$raw    = isset( $_POST[ $prefix . 'content' ] ) ? wp_unslash( $_POST[ $prefix . 'content' ] ) : '';
		$content = wp_kses_post( $raw );
		if ( ! $title || ! trim( wp_strip_all_tags( $content ) ) ) {
			set_transient( 'umi_cabinet_flash_' . get_current_user_id(), array( 'error' => __( 'Укажите название и описание.', 'umi-marketplace' ) ), 60 );
			wp_safe_redirect( $return . '#umi-cabinet' );
			exit;
		}

		$price = isset( $_POST[ $prefix . 'price' ] ) ? max( 0, (int) round( (float) $_POST[ $prefix . 'price' ] ) ) : 0;
		$city  = isset( $_POST[ $prefix . 'city' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $prefix . 'city' ] ) ) : '';
		if ( '' === trim( (string) $city ) ) {
			$city = (string) get_user_meta( get_current_user_id(), 'umi_profile_city', true );
		}
		$thumb = isset( $_POST[ $prefix . 'thumb' ] ) ? (int) $_POST[ $prefix . 'thumb' ] : 0;
		$gkey  = $prefix . 'gallery';
		$gallery = array();
		if ( isset( $_POST[ $gkey ] ) && is_array( $_POST[ $gkey ] ) ) {
			foreach ( wp_unslash( $_POST[ $gkey ] ) as $g ) {
				$g = (int) $g;
				if ( $g > 0 ) {
					$gallery[] = $g;
				}
				if ( count( $gallery ) >= 3 ) {
					break;
				}
			}
		}
		if ( $thumb < 1 && count( $gallery ) > 0 ) {
			$thumb = (int) $gallery[0];
		}

		$uid = get_current_user_id();
		$post_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_status'  => 'pending',
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => wp_trim_words( wp_strip_all_tags( (string) $content ), 40, '…' ),
				'post_author'  => $uid,
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			set_transient( 'umi_cabinet_flash_' . $uid, array( 'error' => __( 'Не удалось создать объявление. Повторите попытку.', 'umi-marketplace' ) ), 60 );
			wp_safe_redirect( $return . '#umi-cabinet' );
			exit;
		}

		$pay_shares = ! empty( $_POST[ $prefix . 'pay_shares' ] );
		if ( Umi_Cpt::PRODUCT === $post_type ) {
			$author_product = ! empty( $_POST['umi_p_author'] );
			if ( ! $author_product ) {
				$pay_shares = true;
			}
			update_post_meta( $post_id, '_umi_author_product', $author_product ? '1' : '0' );
		}
		Umi_Meta_Boxes::set_listing_meta( $post_id, $price, $city, $gallery, $thumb, $uid, $pay_shares );
		if ( Umi_Cpt::SERVICE === $post_type ) {
			$intent_raw = isset( $_POST['umi_s_intent'] ) ? wp_unslash( $_POST['umi_s_intent'] ) : 'offer';
			Umi_Meta_Boxes::set_service_intent( $post_id, $intent_raw );
		}

		$p = get_post( $post_id );
		if ( $p && 'draft' === $p->post_status ) {
			set_transient(
				'umi_cabinet_flash_' . $uid,
				array(
					'notice' => __( 'Лимит опубликованных объявлений исчерпан: запись сохранена как черновик. Освободите слот или обратитесь к администратору.', 'umi-marketplace' ),
				),
				60
			);
		} else {
			set_transient( 'umi_cabinet_flash_' . $uid, array( 'success' => __( 'Объявление отправлено на модерацию. После проверки оно появится в каталоге.', 'umi-marketplace' ) ), 60 );
		}

		wp_safe_redirect( add_query_arg( 'umi_cab', '1', $return ) . '#umi-cabinet' );
		exit;
	}

	/**
	 * Редактирование объявления из кабинета (повторная модерация).
	 *
	 * @param string $post_type CPT.
	 */
	private static function process_cabinet_edit( $post_type ) {
		$user = wp_get_current_user();
		if ( ! in_array( Umi_Roles::ROLE_SELLER, (array) $user->roles, true ) ) {
			return;
		}
		$return = isset( $_POST['umi_cabinet_return'] ) ? esc_url_raw( wp_unslash( $_POST['umi_cabinet_return'] ) ) : home_url( '/' );
		$return = wp_validate_redirect( $return, home_url( '/' ) );
		$pid    = isset( $_POST['umi_edit_post_id'] ) ? (int) $_POST['umi_edit_post_id'] : 0;
		$post   = $pid > 0 ? get_post( $pid ) : null;
		if ( ! $post || (int) $post->post_author !== (int) get_current_user_id() || $post->post_type !== $post_type ) {
			set_transient( 'umi_cabinet_flash_' . get_current_user_id(), array( 'error' => __( 'Объявление не найдено.', 'umi-marketplace' ) ), 60 );
			wp_safe_redirect( $return . '#umi-cabinet' );
			exit;
		}
		$cap = ( Umi_Cpt::SERVICE === $post_type ) ? 'edit_umi_services' : 'edit_umi_products';
		if ( ! current_user_can( $cap ) || ! current_user_can( 'edit_post', $pid ) ) {
			return;
		}
		$prefix = ( Umi_Cpt::SERVICE === $post_type ) ? 'umi_s_' : 'umi_p_';
		$title  = isset( $_POST[ $prefix . 'title' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $prefix . 'title' ] ) ) : '';
		$raw    = isset( $_POST[ $prefix . 'content' ] ) ? wp_unslash( $_POST[ $prefix . 'content' ] ) : '';
		$content = wp_kses_post( $raw );
		if ( ! $title || ! trim( wp_strip_all_tags( $content ) ) ) {
			set_transient( 'umi_cabinet_flash_' . get_current_user_id(), array( 'error' => __( 'Укажите название и описание.', 'umi-marketplace' ) ), 60 );
			wp_safe_redirect( $return . '#umi-cabinet' );
			exit;
		}
		$price = isset( $_POST[ $prefix . 'price' ] ) ? max( 0, (int) round( (float) $_POST[ $prefix . 'price' ] ) ) : 0;
		$city  = isset( $_POST[ $prefix . 'city' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $prefix . 'city' ] ) ) : '';
		if ( '' === trim( (string) $city ) ) {
			$city = (string) get_user_meta( get_current_user_id(), 'umi_profile_city', true );
		}
		$thumb = isset( $_POST[ $prefix . 'thumb' ] ) ? (int) $_POST[ $prefix . 'thumb' ] : 0;
		$gkey  = $prefix . 'gallery';
		$gallery = array();
		if ( isset( $_POST[ $gkey ] ) && is_array( $_POST[ $gkey ] ) ) {
			foreach ( wp_unslash( $_POST[ $gkey ] ) as $g ) {
				$g = (int) $g;
				if ( $g > 0 ) {
					$gallery[] = $g;
				}
				if ( count( $gallery ) >= 3 ) {
					break;
				}
			}
		}
		if ( $thumb < 1 && count( $gallery ) > 0 ) {
			$thumb = (int) $gallery[0];
		}
		$uid = (int) get_current_user_id();
		$up  = wp_update_post(
			array(
				'ID'           => $pid,
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => wp_trim_words( wp_strip_all_tags( (string) $content ), 40, '…' ),
				'post_status'  => 'pending',
			),
			true
		);
		if ( is_wp_error( $up ) ) {
			set_transient( 'umi_cabinet_flash_' . $uid, array( 'error' => __( 'Не удалось сохранить изменения.', 'umi-marketplace' ) ), 60 );
			wp_safe_redirect( $return . '#umi-cabinet' );
			exit;
		}
		$pay_shares = ! empty( $_POST[ $prefix . 'pay_shares' ] );
		if ( Umi_Cpt::PRODUCT === $post_type ) {
			$author_product = ! empty( $_POST['umi_p_author'] );
			if ( ! $author_product ) {
				$pay_shares = true;
			}
			update_post_meta( $pid, '_umi_author_product', $author_product ? '1' : '0' );
		}
		Umi_Meta_Boxes::set_listing_meta( $pid, $price, $city, $gallery, $thumb, $uid, $pay_shares );
		if ( Umi_Cpt::SERVICE === $post_type ) {
			$intent_raw = isset( $_POST['umi_s_intent'] ) ? wp_unslash( $_POST['umi_s_intent'] ) : 'offer';
			Umi_Meta_Boxes::set_service_intent( $pid, $intent_raw );
		}
		set_transient( 'umi_cabinet_flash_' . $uid, array( 'success' => __( 'Изменения отправлены на модерацию.', 'umi-marketplace' ) ), 60 );
		wp_safe_redirect( remove_query_arg( 'umi_edit', $return ) . '#umi-cabinet' );
		exit;
	}

	/**
	 * Удаление объявления (в корзину).
	 */
	private static function process_cabinet_delete() {
		$return = isset( $_POST['umi_cabinet_return'] ) ? esc_url_raw( wp_unslash( $_POST['umi_cabinet_return'] ) ) : home_url( '/' );
		$return = wp_validate_redirect( $return, home_url( '/' ) );
		$pid    = isset( $_POST['umi_delete_post_id'] ) ? (int) $_POST['umi_delete_post_id'] : 0;
		$uid    = get_current_user_id();
		$post   = $pid > 0 ? get_post( $pid ) : null;
		if ( ! $post || (int) $post->post_author !== (int) $uid ) {
			set_transient( 'umi_cabinet_flash_' . $uid, array( 'error' => __( 'Объявление не найдено.', 'umi-marketplace' ) ), 60 );
			wp_safe_redirect( $return . '#umi-cabinet' );
			exit;
		}
		if ( ! in_array( $post->post_type, array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ), true ) || ! current_user_can( 'delete_post', $pid ) ) {
			set_transient( 'umi_cabinet_flash_' . $uid, array( 'error' => __( 'Нельзя удалить это объявление.', 'umi-marketplace' ) ), 60 );
			wp_safe_redirect( $return . '#umi-cabinet' );
			exit;
		}
		if ( ! wp_trash_post( $pid ) ) {
			set_transient( 'umi_cabinet_flash_' . $uid, array( 'error' => __( 'Не удалось удалить объявление.', 'umi-marketplace' ) ), 60 );
		} else {
			set_transient( 'umi_cabinet_flash_' . $uid, array( 'success' => __( 'Объявление удалено.', 'umi-marketplace' ) ), 60 );
		}
		wp_safe_redirect( remove_query_arg( 'umi_edit', $return ) . '#umi-cabinet' );
		exit;
	}

	/**
	 * Service cities for filter (only published services).
	 *
	 * @return string[]
	 */
	public static function service_cities() {
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_type = %s AND p.post_status = 'publish'
			AND pm.meta_key = %s AND pm.meta_value != ''
			ORDER BY pm.meta_value ASC",
			Umi_Cpt::SERVICE,
			'_umi_city'
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$vals = $wpdb->get_col( $sql );
		return array_map( 'strval', array_filter( $vals ) );
	}

	/**
	 * Services list + filter form.
	 *
	 * @param array $atts Atts.
	 * @return string
	 */
	public static function services( $atts ) {
		$atts = shortcode_atts(
			array(
				'per_page' => 12,
			),
			$atts,
			'umi_services'
		);

		$city  = isset( $_GET['umi_city'] ) ? Umi_Meta_Boxes::normalize_listing_city( (string) sanitize_text_field( wp_unslash( $_GET['umi_city'] ) ) ) : '';
		$q     = isset( $_GET['umi_q'] ) ? trim( (string) sanitize_text_field( wp_unslash( $_GET['umi_q'] ) ) ) : '';
		$level = isset( $_GET['umi_level'] ) ? sanitize_key( wp_unslash( $_GET['umi_level'] ) ) : '';
		$pay = isset( $_GET['umi_pay'] ) ? sanitize_key( wp_unslash( $_GET['umi_pay'] ) ) : 'both';
		if ( ! in_array( $pay, array( 'rub', 'shares', 'both' ), true ) ) {
			$pay = 'both';
		}
		$intent = isset( $_GET['umi_intent'] ) ? sanitize_key( wp_unslash( $_GET['umi_intent'] ) ) : 'offer';

		$cities = self::service_cities();

		$args = array(
			'post_type'      => Umi_Cpt::SERVICE,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $atts['per_page'],
			'paged'          => self::current_page(),
		);

		if ( $q ) {
			$args['s'] = $q;
		}

		$meta_parts = array();
		if ( $city ) {
			// Для compare LIKE WordPress сам добавляет % и esc_like; не дублировать — иначе в выдаче 0 постов.
			$meta_parts[] = array(
				'key'     => '_umi_city',
				'value'   => $city,
				'compare' => 'LIKE',
			);
		}
		$pay_q = Umi_Meta_Boxes::services_payment_filter_meta_query( $pay );
		if ( $pay_q ) {
			$meta_parts[] = $pay_q;
		}
		$intent_q = Umi_Meta_Boxes::services_intent_filter_meta_query( $intent );
		if ( $intent_q ) {
			$meta_parts[] = $intent_q;
		}
		if ( ! empty( $meta_parts ) ) {
			// Всегда массив под-запросов: один плоский array( 'key'=>… ) WP_Meta_Query игнорирует.
			$args['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta_parts );
		}

		if ( $level && in_array( $level, array( 'novice', 'amateur', 'pro' ), true ) ) {
			$users = get_users(
				array(
					'meta_key'   => 'umi_profile_level',
					'meta_value' => $level,
					'fields'     => 'ID',
				)
			);
			$args['author__in'] = array_map( 'intval', $users );
			if ( empty( $args['author__in'] ) ) {
				$args['author__in'] = array( 0 );
			}
		}

		$query = new WP_Query( $args );

		$str_intent_offer = __( 'Представлены объявления, в которых люди предлагают услуги.', 'umi-marketplace' );
		$str_intent_seek  = __( 'Представлены объявления людей, которые ищут услугу или сотрудника.', 'umi-marketplace' );
		$intent_hint      = '';
		if ( 'offer' === $intent ) {
			$intent_hint = $str_intent_offer;
		} elseif ( 'seek' === $intent ) {
			$intent_hint = $str_intent_seek;
		}

		ob_start();
		?>
		<div class="umi-services umi-catalog">
			<form method="get" class="umi-filter umi-filter-services umi-filter--row">
				<div class="umi-filter-row umi-filter-row--intent">
					<label class="umi-label" for="umi-srv-intent"><?php esc_html_e( 'Объявления', 'umi-marketplace' ); ?></label>
					<select name="umi_intent" id="umi-srv-intent" class="umi-input">
						<option value="" data-umi-intent-hint=""><?php esc_html_e( 'Все', 'umi-marketplace' ); ?></option>
						<option value="offer" data-umi-intent-hint="<?php echo esc_attr( $str_intent_offer ); ?>" <?php selected( $intent, 'offer' ); ?>><?php esc_html_e( 'Предложение услуг', 'umi-marketplace' ); ?></option>
						<option value="seek" data-umi-intent-hint="<?php echo esc_attr( $str_intent_seek ); ?>" <?php selected( $intent, 'seek' ); ?>><?php esc_html_e( 'Поиск услуги', 'umi-marketplace' ); ?></option>
					</select>
				</div>
				<div class="umi-filter-row umi-filter-row--city">
					<label class="umi-label" for="umi-srv-city-input"><?php esc_html_e( 'Город', 'umi-marketplace' ); ?></label>
					<input
						type="text"
						name="umi_city"
						id="umi-srv-city-input"
						class="umi-input"
						value="<?php echo esc_attr( $city ); ?>"
						list="umi-services-city-suggestions"
						autocomplete="off"
						placeholder="<?php esc_attr_e( 'Начните вводить…', 'umi-marketplace' ); ?>"
					/>
					<datalist id="umi-services-city-suggestions">
						<?php foreach ( $cities as $c ) : ?>
							<option value="<?php echo esc_attr( $c ); ?>"></option>
						<?php endforeach; ?>
					</datalist>
				</div>
				<div class="umi-filter-row umi-filter-row--q">
					<label class="umi-label" for="umi-srv-q"><?php esc_html_e( 'Ключевое слово', 'umi-marketplace' ); ?></label>
					<input type="search" name="umi_q" id="umi-srv-q" value="<?php echo esc_attr( $q ); ?>" class="umi-input" />
				</div>
				<div class="umi-filter-row umi-filter-row--level">
					<label class="umi-label" for="umi-srv-level"><?php esc_html_e( 'Уровень', 'umi-marketplace' ); ?></label>
					<select name="umi_level" id="umi-srv-level" class="umi-input">
						<option value=""><?php esc_html_e( 'Любой', 'umi-marketplace' ); ?></option>
						<option value="novice" <?php selected( $level, 'novice' ); ?>><?php esc_html_e( 'Новичок', 'umi-marketplace' ); ?></option>
						<option value="amateur" <?php selected( $level, 'amateur' ); ?>><?php esc_html_e( 'Любитель', 'umi-marketplace' ); ?></option>
						<option value="pro" <?php selected( $level, 'pro' ); ?>><?php esc_html_e( 'Профессионал', 'umi-marketplace' ); ?></option>
					</select>
				</div>
				<div class="umi-filter-row umi-filter-row--pay">
					<label class="umi-label" for="umi-srv-pay"><?php esc_html_e( 'Оплата', 'umi-marketplace' ); ?></label>
					<select name="umi_pay" id="umi-srv-pay" class="umi-input">
						<option value="both" <?php selected( $pay, 'both' ); ?>><?php esc_html_e( 'За рубли и за доли', 'umi-marketplace' ); ?></option>
						<option value="rub" <?php selected( $pay, 'rub' ); ?>><?php esc_html_e( 'За рубли', 'umi-marketplace' ); ?></option>
						<option value="shares" <?php selected( $pay, 'shares' ); ?>><?php esc_html_e( 'За доли', 'umi-marketplace' ); ?></option>
					</select>
				</div>
				<div class="umi-filter-row umi-filter-row--submit">
					<span class="umi-label umi-label--spacer" aria-hidden="true">&nbsp;</span>
					<button type="submit" class="umi-btn"><?php esc_html_e( 'Искать', 'umi-marketplace' ); ?></button>
				</div>
			</form>
			<p id="umi-srv-intent-hint" class="umi-catalog-intent-hint"<?php echo $intent_hint ? '' : ' hidden'; ?>><?php echo $intent_hint ? esc_html( $intent_hint ) : ''; ?></p>
			<script>
			(function(){var s=document.getElementById('umi-srv-intent'),h=document.getElementById('umi-srv-intent-hint');if(!s||!h)return;
			function sync(){var o=s.options[s.selectedIndex],t=o?o.getAttribute('data-umi-intent-hint'):'';if(!t){h.setAttribute('hidden','hidden');h.textContent='';}else{h.removeAttribute('hidden');h.textContent=t;}}
			s.addEventListener('change',sync);sync();
			})();
			</script>

			<div class="umi-grid">
				<?php
				if ( $query->have_posts() ) :
					while ( $query->have_posts() ) :
						$query->the_post();
						echo self::render_catalog_card_article( (int) get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					endwhile;
				else :
					echo '<p class="umi-empty">' . esc_html__( 'Ничего не найдено.', 'umi-marketplace' ) . '</p>';
				endif;
				?>
			</div>
			<?php
			$big      = 999999999;
			$add_args = array_filter(
				array(
					'umi_city'   => $city,
					'umi_q'      => $q,
					'umi_level'  => $level,
					'umi_pay'    => $pay,
					'umi_intent' => $intent,
				),
				static function ( $v ) {
					return null !== $v && '' !== (string) $v;
				}
			);
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
						'format'    => '?paged=%#%',
						'current'   => max( 1, (int) $args['paged'] ),
						'total'     => (int) $query->max_num_pages,
						'add_args'  => $add_args,
					)
				)
			);
			wp_reset_postdata();
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Products list.
	 *
	 * @param array $atts Atts.
	 * @return string
	 */
	public static function products( $atts ) {
		$atts = shortcode_atts(
			array(
				'per_page' => 12,
			),
			$atts,
			'umi_products'
		);

		$q   = isset( $_GET['umi_q'] ) ? trim( (string) sanitize_text_field( wp_unslash( $_GET['umi_q'] ) ) ) : '';
		$pay = isset( $_GET['umi_pay'] ) ? sanitize_key( wp_unslash( $_GET['umi_pay'] ) ) : 'both';
		if ( ! in_array( $pay, array( 'rub', 'shares', 'both' ), true ) ) {
			$pay = 'both';
		}
		$author_only = ! empty( $_GET['umi_author'] ) && '1' === (string) sanitize_text_field( wp_unslash( $_GET['umi_author'] ) );

		$args = array(
			'post_type'      => Umi_Cpt::PRODUCT,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $atts['per_page'],
			'paged'          => self::current_page(),
		);
		if ( $q ) {
			$args['s'] = $q;
		}
		$meta_parts = array();
		$pay_q      = Umi_Meta_Boxes::products_payment_filter_meta_query( $pay );
		if ( $pay_q ) {
			$meta_parts[] = $pay_q;
		}
		$auth_q = Umi_Meta_Boxes::products_author_filter_meta_query( $author_only );
		if ( $auth_q ) {
			$meta_parts[] = $auth_q;
		}
		if ( count( $meta_parts ) > 0 ) {
			$args['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta_parts );
		}

		$query = new WP_Query( $args );

		ob_start();
		?>
		<div class="umi-products umi-catalog">
			<form method="get" class="umi-filter umi-filter-products umi-filter--row">
				<div class="umi-filter-row umi-filter-row--q">
					<label class="umi-label" for="umi-prd-q"><?php esc_html_e( 'Ключевое слово', 'umi-marketplace' ); ?></label>
					<input type="search" name="umi_q" id="umi-prd-q" value="<?php echo esc_attr( $q ); ?>" class="umi-input" />
				</div>
				<div class="umi-filter-row umi-filter-row--pay">
					<label class="umi-label" for="umi-prd-pay"><?php esc_html_e( 'Оплата', 'umi-marketplace' ); ?></label>
					<select name="umi_pay" id="umi-prd-pay" class="umi-input">
						<option value="both" <?php selected( $pay, 'both' ); ?>><?php esc_html_e( 'За рубли и за доли', 'umi-marketplace' ); ?></option>
						<option value="rub" <?php selected( $pay, 'rub' ); ?>><?php esc_html_e( 'За рубли', 'umi-marketplace' ); ?></option>
						<option value="shares" <?php selected( $pay, 'shares' ); ?>><?php esc_html_e( 'За доли', 'umi-marketplace' ); ?></option>
					</select>
				</div>
				<div class="umi-filter-row umi-filter-row--author">
					<span class="umi-label" id="umi-prd-author-label"><?php esc_html_e( 'Тип', 'umi-marketplace' ); ?></span>
					<div role="group" aria-labelledby="umi-prd-author-label">
						<label class="umi-check-label umi-filter-check">
							<input type="checkbox" name="umi_author" value="1" <?php checked( $author_only ); ?> />
							<?php esc_html_e( 'Только авторские товары', 'umi-marketplace' ); ?>
						</label>
					</div>
				</div>
				<div class="umi-filter-row umi-filter-row--submit">
					<span class="umi-label umi-label--spacer" aria-hidden="true">&nbsp;</span>
					<button type="submit" class="umi-btn"><?php esc_html_e( 'Искать', 'umi-marketplace' ); ?></button>
				</div>
			</form>
			<div class="umi-grid">
				<?php
				if ( $query->have_posts() ) :
					while ( $query->have_posts() ) :
						$query->the_post();
						echo self::render_catalog_card_article( (int) get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					endwhile;
				else :
					echo '<p class="umi-empty">' . esc_html__( 'Ничего не найдено.', 'umi-marketplace' ) . '</p>';
				endif;
				?>
			</div>
			<?php
			$big      = 999999999;
			$add_args = array_filter(
				array(
					'umi_q'       => $q,
					'umi_pay'     => $pay,
					'umi_author'  => $author_only ? '1' : '',
				),
				static function ( $v ) {
					return null !== $v && '' !== (string) $v;
				}
			);
			echo wp_kses_post(
				paginate_links(
					array(
						'base'     => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
						'format'   => '?paged=%#%',
						'current'  => max( 1, (int) $args['paged'] ),
						'total'    => (int) $query->max_num_pages,
						'add_args' => $add_args,
					)
				)
			);
			wp_reset_postdata();
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Become seller form.
	 *
	 * @return string
	 */
	public static function become_seller() {
		if ( ! is_user_logged_in() ) {
			return '<p class="umi-notice">' . esc_html__( 'Войдите, чтобы стать продавцом.', 'umi-marketplace' ) . '</p>';
		}

		$user = wp_get_current_user();
		if ( in_array( Umi_Roles::ROLE_SELLER, (array) $user->roles, true ) ) {
			return '<p class="umi-notice">' . esc_html__( 'Вы уже продавец.', 'umi-marketplace' ) . '</p>';
		}

		if ( isset( $_POST['umi_become_seller_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['umi_become_seller_nonce'] ) ), 'umi_become_seller' ) ) {
			$city       = isset( $_POST['umi_profile_city'] ) ? sanitize_text_field( wp_unslash( $_POST['umi_profile_city'] ) ) : '';
			$profession = isset( $_POST['umi_profile_profession'] ) ? sanitize_text_field( wp_unslash( $_POST['umi_profile_profession'] ) ) : '';
			$level      = isset( $_POST['umi_profile_level'] ) ? sanitize_key( wp_unslash( $_POST['umi_profile_level'] ) ) : 'novice';
			if ( ! in_array( $level, array( 'novice', 'amateur', 'pro' ), true ) ) {
				$level = 'novice';
			}
			update_user_meta( $user->ID, 'umi_profile_city', $city );
			update_user_meta( $user->ID, 'umi_profile_profession', $profession );
			update_user_meta( $user->ID, 'umi_profile_level', $level );
			$user->set_role( Umi_Roles::ROLE_SELLER );
			return '<p class="umi-success">' . esc_html__( 'Профиль продавца сохранён.', 'umi-marketplace' ) . '</p>';
		}

		$city       = get_user_meta( $user->ID, 'umi_profile_city', true );
		$profession = get_user_meta( $user->ID, 'umi_profile_profession', true );
		$level      = get_user_meta( $user->ID, 'umi_profile_level', true );
		if ( ! $level ) {
			$level = 'novice';
		}

		ob_start();
		?>
		<form method="post" class="umi-form umi-become-seller">
			<?php wp_nonce_field( 'umi_become_seller', 'umi_become_seller_nonce' ); ?>
			<p>
				<label class="umi-label"><?php esc_html_e( 'Ваш город', 'umi-marketplace' ); ?></label>
				<input type="text" name="umi_profile_city" class="umi-input" required value="<?php echo esc_attr( (string) $city ); ?>" />
			</p>
			<p>
				<label class="umi-label"><?php esc_html_e( 'Профессия', 'umi-marketplace' ); ?></label>
				<input type="text" name="umi_profile_profession" class="umi-input" required value="<?php echo esc_attr( (string) $profession ); ?>" />
			</p>
			<p>
				<label class="umi-label"><?php esc_html_e( 'Уровень', 'umi-marketplace' ); ?></label>
				<select name="umi_profile_level" class="umi-input">
					<option value="novice" <?php selected( $level, 'novice' ); ?>><?php esc_html_e( 'Новичок', 'umi-marketplace' ); ?></option>
					<option value="amateur" <?php selected( $level, 'amateur' ); ?>><?php esc_html_e( 'Любитель', 'umi-marketplace' ); ?></option>
					<option value="pro" <?php selected( $level, 'pro' ); ?>><?php esc_html_e( 'Профессионал', 'umi-marketplace' ); ?></option>
				</select>
			</p>
			<button type="submit" class="umi-btn"><?php esc_html_e( 'Стать продавцом', 'umi-marketplace' ); ?></button>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Кабинет покупателя / продавца: баланс, диалоги, для продавца — объявления и подача без wp-admin.
	 *
	 * @return string
	 */
	public static function user_cabinet() {
		$uid = get_current_user_id();
		$out = '<div class="umi-cabinet umi-page-root umi-cabinet--v2" id="umi-cabinet">';

		if ( ! is_user_logged_in() ) {
			$here = ( is_singular() && get_queried_object_id() ) ? get_permalink( get_queried_object_id() ) : home_url( '/' );
			$out .= '<p class="umi-notice">' . esc_html__( 'Войдите, чтобы открыть личный кабинет.', 'umi-marketplace' ) . ' ';
			$out .= '<a class="umi-link" href="' . esc_url( wp_login_url( $here ) ) . '">' . esc_html__( 'Войти', 'umi-marketplace' ) . '</a></p></div>';
			return $out;
		}

		$user       = wp_get_current_user();
		$is_seller  = in_array( Umi_Roles::ROLE_SELLER, (array) $user->roles, true );
		$return_url = ( is_singular() && get_queried_object_id() ) ? get_permalink( get_queried_object_id() ) : home_url( '/' );
		$return_url = esc_url( $return_url );

		// Flash messages.
		$flash_key  = 'umi_cabinet_flash_' . $uid;
		$flash      = get_transient( $flash_key );
		$flash_html = '';
		if ( false !== $flash && is_array( $flash ) ) {
			delete_transient( $flash_key );
			if ( ! empty( $flash['error'] ) ) {
				$flash_html .= '<p class="umi-error" role="alert">' . esc_html( (string) $flash['error'] ) . '</p>';
			}
			if ( ! empty( $flash['success'] ) ) {
				$flash_html .= '<p class="umi-success" role="status">' . esc_html( (string) $flash['success'] ) . '</p>';
			}
			if ( ! empty( $flash['notice'] ) ) {
				$flash_html .= '<p class="umi-notice" role="status">' . esc_html( (string) $flash['notice'] ) . '</p>';
			}
		}
		if ( $flash_html ) {
			$out .= '<div class="umi-cabinet-flash">' . $flash_html . '</div>';
		}

		// Avatar.
		$photo_id = (int) get_user_meta( $uid, 'umi_profile_photo', true );
		$ava_url  = ( $photo_id > 0 ) ? wp_get_attachment_image_url( $photo_id, 'thumbnail' ) : '';

		// Which modal to auto-open on page load.
		$auto_open = '';

		// Edit-listing: only rendered if umi_edit in URL.
		$edit_post = null;
		$edit_get  = isset( $_GET['umi_edit'] ) ? (int) $_GET['umi_edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $edit_get > 0 && $is_seller ) {
			$e = get_post( $edit_get );
			if ( $e && (int) $e->post_author === (int) $uid
				&& in_array( $e->post_type, array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ), true )
				&& in_array( $e->post_status, array( 'publish', 'pending', 'draft' ), true ) ) {
				$edit_post = $e;
				$auto_open = 'umi-modal-edit-' . (int) $e->ID;
			}
		}

		// Deal: auto-open deals modal if umi_deal is in URL.
		$deal_view = isset( $_GET['umi_deal'] ) ? (int) $_GET['umi_deal'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $auto_open && $deal_view > 0 && Umi_Deals::user_can_access_deal( $deal_view, $uid ) ) {
			$auto_open = 'umi-modal-deals';
		}

		// Threads (used in modal and sidebar badge).
		$threads = Umi_Chat::threads_for_user( $uid, 25 );

		// Admin chat thread.
		$adm_thread = (int) Umi_Chat::get_or_create_admin_thread( $uid );

		// ===================== MODALS =====================

		// Helper: open modal wrapper.
		$mo = function ( $id, $title_id, $title_text ) use ( $auto_open ) {
			$hidden = ( $auto_open === $id ) ? '' : ' hidden';
			return '<div class="umi-modal" id="' . esc_attr( $id ) . '"' . $hidden . ' role="dialog" aria-modal="true" aria-labelledby="' . esc_attr( $title_id ) . '">'
				. '<div class="umi-modal__backdrop" data-umi-close-modal></div>'
				. '<div class="umi-modal__box">'
				. '<div class="umi-modal__head">'
				. '<h2 class="umi-modal__title" id="' . esc_attr( $title_id ) . '">' . esc_html( $title_text ) . '</h2>'
				. '<button type="button" class="umi-modal__close" data-umi-close-modal aria-label="' . esc_attr__( 'Закрыть', 'umi-marketplace' ) . '">&#x2715;</button>'
				. '</div>'
				. '<div class="umi-modal__body">';
		};
		$mc = '</div></div></div>'; // close .umi-modal__body + .umi-modal__box + .umi-modal

		// 1. Avatar modal.
		$out .= $mo( 'umi-modal-avatar', 'umi-modal-avatar-title', __( 'Фото профиля', 'umi-marketplace' ) );
		$out .= '<p class="umi-text-muted">' . esc_html__( 'Будет отображаться в кабинете. Можно заменить или убрать.', 'umi-marketplace' ) . '</p>';
		$out .= '<form method="post" class="umi-form umi-cabinet-profile-form" action="' . esc_url( $return_url ) . '#umi-cabinet" enctype="multipart/form-data">';
		$out .= '<input type="hidden" name="umi_cabinet" value="1" />';
		$out .= '<input type="hidden" name="umi_cabinet_action" value="profile" />';
		$out .= '<input type="hidden" name="umi_cabinet_return" value="' . esc_url( $return_url ) . '" />';
		$out .= '<input type="hidden" name="umi_profile_photo_clear" value="0" class="umi-profile-clear-flag" />';
		$out .= wp_nonce_field( 'umi_cabinet_profile', 'umi_cabinet_nonce_profile', true, false );
		$out .= '<div class="umi-cabinet-upload umi-cabinet-profile-upload" data-umi-cabinet-upload>';
		$out .= '<input type="hidden" class="umi-cabinet-attachment-id" name="umi_profile_photo_id" value="' . ( $photo_id > 0 ? (int) $photo_id : '' ) . '" id="umi_prof_hid" />';
		$out .= '<div class="umi-cabinet-upload-preview"' . ( $photo_id ? '' : ' hidden' ) . '><img src="' . ( $ava_url ? esc_url( $ava_url ) : '' ) . '" alt="" width="200" height="200" style="object-fit:cover;border-radius:8px;max-width:100%;height:auto" /></div>';
		$out .= '<p class="umi-cabinet-upload-actions"><label class="umi-cabinet-file-label umi-btn umi-btn--secondary"><input type="file" id="umi_prof_file" class="umi-cabinet-file" accept="image/jpeg,image/png,image/gif,image/webp" style="position:absolute;opacity:0;width:0;height:0" tabindex="-1" />';
		$out .= '<span>' . esc_html__( 'Выбрать изображение', 'umi-marketplace' ) . '</span></label> ';
		$out .= '<button type="button" class="umi-cabinet-upload-clear umi-link" style="display:' . ( $photo_id ? 'inline' : 'none' ) . '"' . ( $photo_id ? '' : ' hidden' ) . '>' . esc_html__( 'Убрать', 'umi-marketplace' ) . '</button></p></div>';
		$out .= '<p><button type="submit" class="umi-btn umi-btn--primary">' . esc_html__( 'Сохранить', 'umi-marketplace' ) . '</button></p></form>';
		$out .= $mc;

		// 2. Deals modal.
		$out .= $mo( 'umi-modal-deals', 'umi-modal-deals-title', __( 'Мои сделки', 'umi-marketplace' ) );
		$out .= self::render_deals_inner( $return_url );
		$out .= $mc;

		// 3. Threads/dialogs modal.
		$out .= $mo( 'umi-modal-threads', 'umi-modal-threads-title', __( 'Мои диалоги', 'umi-marketplace' ) );
		if ( count( $threads ) ) {
			$out .= '<ul class="umi-cabinet-threads">';
			foreach ( $threads as $t ) {
				$tt   = isset( $t['thread_type'] ) ? (string) $t['thread_type'] : Umi_Chat::TYPE_LISTING;
				$deal = isset( $t['deal_id'] ) ? (int) $t['deal_id'] : 0;
				$lid  = (int) $t['listing_id'];
				if ( Umi_Chat::TYPE_DISPUTE === $tt && $deal > 0 ) {
					$chat_href = add_query_arg( 'umi_deal', $deal, $return_url ) . '#umi-deals';
				} elseif ( Umi_Chat::TYPE_ADMIN === $tt ) {
					$chat_href = $return_url . '#umi-cabinet-admin-chat';
				} else {
					$chat_href = $lid > 0
						? ( add_query_arg( 'umi_thread', (int) $t['thread_id'], get_permalink( $lid ) ) . '#umi-chat' )
						: $return_url;
				}
				$label  = $t['listing_title'] ? $t['listing_title'] : ( $lid > 0
					? sprintf( /* translators: %d listing id */ __( 'Объявление #%d', 'umi-marketplace' ), $lid )
					: __( 'Переписка', 'umi-marketplace' ) );
				$unread     = isset( $t['unread_count'] ) ? (int) $t['unread_count'] : 0;
				$li_classes = array( 'umi-cabinet-threads__item' );
				if ( $unread > 0 ) {
					$li_classes[] = 'umi-cabinet-threads__item--unread';
				}
				$out .= '<li class="' . esc_attr( implode( ' ', $li_classes ) ) . '"><div class="umi-cabinet-threads__row">';
				$out .= '<a class="umi-cabinet-threads__link" href="' . esc_url( $chat_href ) . '"><span class="umi-cabinet-threads__title">' . esc_html( $label ) . '</span>';
				if ( (string) $t['other_name'] !== '' && Umi_Chat::TYPE_DISPUTE !== $tt ) {
					$out .= ' <span class="umi-cabinet-threads__peer">(' . esc_html( sprintf( /* translators: %s other user */ __( 'с: %s', 'umi-marketplace' ), (string) $t['other_name'] ) ) . ')</span>';
				}
				if ( $unread > 0 ) {
					$out .= ' <span class="umi-cabinet-threads__unread" title="' . esc_attr__( 'Новые сообщения', 'umi-marketplace' ) . '" aria-label="' . esc_attr( sprintf( /* translators: %d unread count */ __( 'Непрочитанных сообщений: %d', 'umi-marketplace' ), $unread ) ) . '">' . (int) $unread . '</span>';
				}
				$out .= '</a>';
				if ( Umi_Chat::TYPE_LISTING === $tt ) {
					$out .= '<button type="button" class="umi-cabinet-threads__delete" data-umi-delete-thread="' . (int) $t['thread_id'] . '" title="' . esc_attr__( 'Удалить диалог', 'umi-marketplace' ) . '" aria-label="' . esc_attr__( 'Удалить диалог', 'umi-marketplace' ) . '">';
					$out .= '<span class="umi-cabinet-threads__delete-x" aria-hidden="true">×</span><span class="screen-reader-text"> ' . esc_html__( 'Удалить', 'umi-marketplace' ) . '</span>';
					$out .= '</button>';
				}
				$out .= '</div></li>';
			}
			$out .= '</ul>';
		} else {
			$out .= '<p class="umi-cabinet-empty">' . esc_html__( 'Пока нет переписок. Откройте объявление и нажмите «Написать продавцу».', 'umi-marketplace' ) . '</p>';
		}
		$out .= $mc;

		// 4. Add listing modal.
		$out .= $mo( 'umi-modal-add', 'umi-modal-add-title', __( 'Добавить объявление', 'umi-marketplace' ) );
		if ( $is_seller ) {
			$out .= '<div class="umi-cabinet-panels" data-umi-cabinet-panels>';
			$out .= '<nav class="umi-cabinet-nav" role="tablist" aria-label="' . esc_attr__( 'Добавить объявление', 'umi-marketplace' ) . '">';
			$out .= '<button type="button" class="umi-cabinet-tab is-active" role="tab" id="tab-umi-s" aria-selected="true" aria-controls="panel-umi-s" data-umi-cabinet-tab="s">' . esc_html__( 'Добавить услугу', 'umi-marketplace' ) . '</button>';
			$out .= '<button type="button" class="umi-cabinet-tab" role="tab" id="tab-umi-p" aria-selected="false" aria-controls="panel-umi-p" data-umi-cabinet-tab="p">' . esc_html__( 'Добавить товар', 'umi-marketplace' ) . '</button></nav>';
			$out .= '<div class="umi-cabinet-panel is-active" id="panel-umi-s" role="tabpanel" aria-labelledby="tab-umi-s" data-umi-cabinet-panel="s">';
			$out .= '<h3 class="umi-cabinet-subh">' . esc_html__( 'Новая услуга', 'umi-marketplace' ) . '</h3>';
			$out .= self::render_cabinet_add_form( Umi_Cpt::SERVICE, $return_url );
			$out .= '</div>';
			$out .= '<div class="umi-cabinet-panel" id="panel-umi-p" role="tabpanel" aria-labelledby="tab-umi-p" hidden data-umi-cabinet-panel="p">';
			$out .= '<h3 class="umi-cabinet-subh">' . esc_html__( 'Новый товар', 'umi-marketplace' ) . '</h3>';
			$out .= self::render_cabinet_add_form( Umi_Cpt::PRODUCT, $return_url );
			$out .= '</div></div>';
		} else {
			$out .= '<p class="umi-notice">' . esc_html__( 'Чтобы добавлять услуги и товары, оформите профиль продавца.', 'umi-marketplace' ) . '</p>';
			$out .= self::become_seller();
		}
		$out .= $mc;

		// 5. Admin chat modal.
		$out .= $mo( 'umi-modal-admin-chat', 'umi-modal-admin-chat-title', __( 'Чат с администратором', 'umi-marketplace' ) );
		if ( $adm_thread > 0 ) {
			wp_enqueue_script( 'umi-mp-chat' );
			$out .= '<p class="umi-text-muted">' . esc_html__( 'Вопросы по сайту, модерации и сделкам. Ответы не мгновенны.', 'umi-marketplace' ) . '</p>';
			$out .= self::render_chat_thread_box( $adm_thread );
		} else {
			$out .= '<p class="umi-cabinet-empty">' . esc_html__( 'Чат с администратором недоступен.', 'umi-marketplace' ) . '</p>';
		}
		$out .= $mc;

		// 6. Edit listing modal (only when umi_edit is in URL).
		if ( $edit_post ) {
			$pid    = (int) $edit_post->ID;
			$mid    = 'umi-modal-edit-' . $pid;
			$cancel = esc_url( remove_query_arg( 'umi_edit', $return_url ) . '#umi-cabinet' );
			$out   .= $mo( $mid, $mid . '-title', __( 'Редактирование объявления', 'umi-marketplace' ) );
			$out   .= '<p class="umi-text-muted"><a class="umi-link" href="' . $cancel . '">' . esc_html__( '← Отмена, к списку объявлений', 'umi-marketplace' ) . '</a></p>';
			$out   .= self::render_cabinet_edit_form( $edit_post, $return_url );
			$out   .= $mc;
		}

		// ===================== TWO-COLUMN LAYOUT =====================

		$out .= '<div class="umi-cabinet-layout">';

		// --- Left: sidebar ---
		$out .= '<aside class="umi-cabinet-sidebar">';

		// Profile card: avatar (clickable) + name + role.
		$out .= '<div class="umi-cabinet-profile-card">';
		$out .= '<button type="button" class="umi-cabinet-avatar-btn" data-umi-open-modal="umi-modal-avatar" aria-label="' . esc_attr__( 'Изменить фото профиля', 'umi-marketplace' ) . '">';
		if ( $ava_url ) {
			$out .= '<img class="umi-cabinet-avatar" src="' . esc_url( $ava_url ) . '" alt="" width="64" height="64" />';
		} else {
			$out .= get_avatar( $uid, 64, '', '', array( 'class' => 'umi-cabinet-avatar' ) );
		}
		$out .= '<span class="umi-cabinet-avatar-badge" aria-hidden="true">';
		$out .= '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z" fill="currentColor"/></svg>';
		$out .= '</span></button>';
		$out .= '<div class="umi-cabinet-profile-info">';
		$out .= '<p class="umi-cabinet-profile-name">' . esc_html( $user->display_name ? $user->display_name : $user->user_login ) . '</p>';
		$out .= '<p class="umi-cabinet-profile-role">' . ( $is_seller ? esc_html__( 'Продавец', 'umi-marketplace' ) : esc_html__( 'Покупатель', 'umi-marketplace' ) ) . '</p>';
		$out .= '</div></div>';

		// Balance.
		$out .= '<div class="umi-cabinet-sidebar-balance">' . do_shortcode( '[umi_balance]' ) . '</div>';

		// Nav items.
		$out .= '<nav class="umi-cabinet-sidebar-nav" aria-label="' . esc_attr__( 'Меню кабинета', 'umi-marketplace' ) . '">';

		$deals_list  = Umi_Deals::deals_for_user( $uid, 50 );
		$deals_count = count( $deals_list );
		$out .= '<button type="button" class="umi-cabinet-nav-item" data-umi-open-modal="umi-modal-deals">';
		$out .= '<span class="umi-cabinet-nav-item__label">' . esc_html__( 'Мои сделки', 'umi-marketplace' ) . '</span>';
		if ( $deals_count > 0 ) {
			$out .= '<span class="umi-cabinet-nav-item__count">' . (int) $deals_count . '</span>';
		}
		$out .= '</button>';

		$unread_total = 0;
		foreach ( $threads as $t ) {
			$unread_total += isset( $t['unread_count'] ) ? (int) $t['unread_count'] : 0;
		}
		$out .= '<button type="button" class="umi-cabinet-nav-item" data-umi-open-modal="umi-modal-threads">';
		$out .= '<span class="umi-cabinet-nav-item__label">' . esc_html__( 'Мои диалоги', 'umi-marketplace' ) . '</span>';
		$badge_empty = $unread_total < 1 ? ' umi-chat-badge--empty' : '';
		$out .= '<span class="umi-chat-badge umi-cabinet-nav-item__badge' . $badge_empty . '" data-umi-unread="' . (int) $unread_total . '">' . ( $unread_total > 0 ? (int) $unread_total : '' ) . '</span>';
		$out .= '</button>';

		if ( $is_seller ) {
			$out .= '<button type="button" class="umi-cabinet-nav-item" data-umi-open-modal="umi-modal-add">';
			$out .= '<span class="umi-cabinet-nav-item__label">' . esc_html__( 'Добавить объявление', 'umi-marketplace' ) . '</span>';
			$out .= '</button>';
		} else {
			$out .= '<button type="button" class="umi-cabinet-nav-item" data-umi-open-modal="umi-modal-add">';
			$out .= '<span class="umi-cabinet-nav-item__label">' . esc_html__( 'Стать продавцом', 'umi-marketplace' ) . '</span>';
			$out .= '</button>';
		}

		$out .= '<button type="button" class="umi-cabinet-nav-item" data-umi-open-modal="umi-modal-admin-chat">';
		$out .= '<span class="umi-cabinet-nav-item__label">' . esc_html__( 'Чат с администратором', 'umi-marketplace' ) . '</span>';
		$out .= '</button>';

		$out .= '</nav>';  // end .umi-cabinet-sidebar-nav
		$out .= '</aside>'; // end .umi-cabinet-sidebar

		// --- Right: listings table ---
		$out .= '<div class="umi-cabinet-main">';
		$out .= '<div class="umi-cabinet-main-header">';
		$out .= '<h2 class="umi-cabinet-heading">' . esc_html__( 'Мои объявления', 'umi-marketplace' ) . '</h2>';
		if ( $is_seller ) {
			$out .= '<button type="button" class="umi-btn umi-btn--primary umi-btn--sm" data-umi-open-modal="umi-modal-add">' . esc_html__( '+ Добавить', 'umi-marketplace' ) . '</button>';
		}
		$out .= '</div>';

		if ( $is_seller ) {
			$q = new WP_Query(
				array(
					'post_type'      => array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ),
					'author'         => $uid,
					'post_status'    => array( 'publish', 'pending', 'draft' ),
					'posts_per_page' => 100,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);
			if ( $q->have_posts() ) {
				$st_label = array(
					'publish' => __( 'Опубликовано', 'umi-marketplace' ),
					'pending' => __( 'На модерации', 'umi-marketplace' ),
					'draft'   => __( 'Черновик', 'umi-marketplace' ),
				);
				$out .= '<table class="umi-cabinet-table">';
				$out .= '<thead><tr>';
				$out .= '<th>' . esc_html__( 'Тип', 'umi-marketplace' ) . '</th>';
				$out .= '<th>' . esc_html__( 'Название', 'umi-marketplace' ) . '</th>';
				$out .= '<th>' . esc_html__( 'Статус', 'umi-marketplace' ) . '</th>';
				$out .= '<th>' . esc_html__( 'Действия', 'umi-marketplace' ) . '</th>';
				$out .= '</tr></thead><tbody>';
				while ( $q->have_posts() ) {
					$q->the_post();
					$lpid     = (int) get_the_ID();
					$ltype    = get_post_type( $lpid );
					$type_l   = ( Umi_Cpt::SERVICE === $ltype ) ? __( 'Услуга', 'umi-marketplace' ) : __( 'Товар', 'umi-marketplace' );
					$lst      = get_post_status( $lpid );
					$lst_text = isset( $st_label[ $lst ] ) ? $st_label[ $lst ] : $lst;
					$view_url = ( 'publish' === $lst ) ? get_permalink( $lpid ) : '';
					$edit_url = esc_url( add_query_arg( 'umi_edit', $lpid, $return_url ) . '#umi-cabinet' );
					$thumb    = get_the_post_thumbnail( $lpid, array( 40, 40 ), array( 'style' => 'width:36px;height:36px;object-fit:cover;border-radius:4px;flex-shrink:0;display:block' ) );
					$out .= '<tr>';
					$out .= '<td><span class="umi-cabinet-table-type">' . esc_html( $type_l ) . '</span></td>';
					$out .= '<td><div class="umi-cabinet-table-title-cell">';
					if ( $thumb ) {
						$out .= $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					$title_text = esc_html( get_the_title() );
					if ( $view_url ) {
						$out .= '<a class="umi-link" href="' . esc_url( $view_url ) . '" target="_blank" rel="noopener">' . $title_text . '</a>';
					} else {
						$out .= '<span>' . $title_text . '</span>';
					}
					$out .= '</div></td>';
					$out .= '<td><span class="umi-cabinet-table-status umi-cabinet-table-status--' . esc_attr( $lst ) . '">' . esc_html( $lst_text ) . '</span></td>';
					$svg_edit   = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zm17.71-10.21a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z" fill="currentColor"/></svg>';
					$svg_trash  = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M6 19c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" fill="currentColor"/></svg>';
					$out .= '<td class="umi-cabinet-table-actions"><div class="umi-cabinet-table-actions-inner">';
					$out .= '<a class="umi-cabinet-icon-btn umi-cabinet-icon-btn--edit" href="' . $edit_url . '" title="' . esc_attr__( 'Изменить', 'umi-marketplace' ) . '" aria-label="' . esc_attr__( 'Изменить', 'umi-marketplace' ) . '">' . $svg_edit . '</a>';
					$out .= '<form method="post" class="umi-cabinet-list-delete" action="' . esc_url( $return_url ) . '" onsubmit="return window.confirm(' . "'" . esc_js( __( 'Удалить объявление? Его можно будет восстановить в админке.', 'umi-marketplace' ) ) . "'" . ');">';
					$out .= '<input type="hidden" name="umi_cabinet" value="1" /><input type="hidden" name="umi_cabinet_action" value="delete_listing" />';
					$out .= '<input type="hidden" name="umi_cabinet_return" value="' . esc_url( $return_url ) . '" />';
					$out .= '<input type="hidden" name="umi_delete_post_id" value="' . (int) $lpid . '" />';
					$out .= wp_nonce_field( 'umi_cabinet_delete_listing', 'umi_cabinet_nonce_del', true, false );
					$out .= '<button type="submit" class="umi-cabinet-icon-btn umi-cabinet-icon-btn--delete" title="' . esc_attr__( 'Удалить', 'umi-marketplace' ) . '" aria-label="' . esc_attr__( 'Удалить', 'umi-marketplace' ) . '">' . $svg_trash . '</button></form>';
					$out .= '</div></td></tr>';
				}
				$out .= '</tbody></table>';
				wp_reset_postdata();
			} else {
				$out .= '<p class="umi-cabinet-empty">' . esc_html__( 'Пока нет объявлений. Нажмите «+ Добавить», чтобы разместить первое.', 'umi-marketplace' ) . '</p>';
			}
		} else {
			$out .= '<p class="umi-cabinet-empty">' . esc_html__( 'Чтобы размещать объявления, оформите профиль продавца.', 'umi-marketplace' ) . '</p>';
		}
		$out .= '</div>'; // end .umi-cabinet-main

		$out .= '</div>'; // end .umi-cabinet-layout
		$out .= '</div>'; // end .umi-cabinet

		return $out;
	}

	/**
	 * @deprecated 1.0.1 Используйте [umi_user_cabinet]; оставлено для совместимости.
	 *
	 * @return string
	 */
	public static function seller_cabinet() {
		return self::user_cabinet();
	}

	/**
	 * Один слот загрузки изображения (AJAX, владелец — текущий пользователь).
	 *
	 * @param string $html_id   Уникальный префикс id.
	 * @param string $name      Атрибут name у скрытого поля.
	 * @param string $labelledby Optional aria-labelledby.
	 * @param int    $initial_id Существующее вложение-изображение.
	 */
	private static function render_cabinet_upload_field( $html_id, $name, $labelledby = '', $initial_id = 0 ) {
		$id_b = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $html_id );
		if ( $id_b === '' ) {
			$id_b = 'umiup';
		}
		$init    = (int) $initial_id;
		$init_ok = $init > 0 && get_post( $init ) && 0 === strpos( (string) get_post_mime_type( $init ), 'image/' );
		$init    = $init_ok ? $init : 0;
		$iurl    = $init ? (string) wp_get_attachment_image_url( $init, 'medium' ) : '';
		$phid    = $init ? (string) $init : '';
		?>
		<div class="umi-cabinet-upload" data-umi-cabinet-upload>
			<input type="hidden" class="umi-cabinet-attachment-id" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $phid ); ?>" id="<?php echo esc_attr( $id_b ); ?>_hid" />
			<div class="umi-cabinet-upload-preview"<?php echo $iurl ? '' : ' hidden'; ?>>
				<?php if ( $iurl ) : ?>
					<img src="<?php echo esc_url( $iurl ); ?>" alt="" width="200" height="200" style="object-fit:cover;border-radius:8px;max-width:100%;height:auto" />
				<?php else : ?>
					<img src="" alt="" width="200" height="200" style="object-fit:cover;border-radius:8px;max-width:100%;height:auto" />
				<?php endif; ?>
			</div>
			<p class="umi-cabinet-upload-actions">
				<label class="umi-cabinet-file-label umi-btn umi-btn--secondary"<?php echo $labelledby ? ' aria-labelledby="' . esc_attr( $labelledby ) . '"' : ''; ?>>
					<input type="file" id="<?php echo esc_attr( $id_b ); ?>_file" class="umi-cabinet-file" accept="image/jpeg,image/png,image/gif,image/webp" style="position:absolute;opacity:0;width:0;height:0" tabindex="-1" />
					<span><?php esc_html_e( 'Добавить файл', 'umi-marketplace' ); ?></span>
				</label>
				<button type="button" class="umi-cabinet-upload-clear umi-link" style="display:<?php echo $init ? 'inline' : 'none'; ?>" <?php echo $init ? '' : 'hidden'; ?>><?php esc_html_e( 'Убрать', 'umi-marketplace' ); ?></button>
			</p>
		</div>
		<?php
	}

	/**
	 * Форма добавления в кабинете.
	 *
	 * @param string $post_type CPT.
	 * @param string $return_url Return URL.
	 * @return string
	 */
	private static function render_cabinet_add_form( $post_type, $return_url ) {
		$is_service   = ( Umi_Cpt::SERVICE === $post_type );
		$prefix       = $is_service ? 'umi_s_' : 'umi_p_';
		$action_value = $is_service ? 'service' : 'product';
		$nonce        = $is_service ? 'umi_cabinet_nonce_s' : 'umi_cabinet_nonce_p';
		$nonce_action = $is_service ? 'umi_cabinet_add_service' : 'umi_cabinet_add_product';
		ob_start();
		?>
		<form method="post" class="umi-form umi-cabinet-form" action="<?php echo esc_url( $return_url ); ?>#umi-cabinet">
			<input type="hidden" name="umi_cabinet" value="1" />
			<input type="hidden" name="umi_cabinet_action" value="<?php echo esc_attr( $action_value ); ?>" />
			<input type="hidden" name="umi_cabinet_return" value="<?php echo esc_url( $return_url ); ?>" />
			<?php wp_nonce_field( $nonce_action, $nonce, false, true ); ?>
			<p class="umi-form-row">
				<label class="umi-label" for="<?php echo esc_attr( $prefix . 'title' ); ?>"><?php esc_html_e( 'Название', 'umi-marketplace' ); ?></label>
				<input type="text" class="umi-input" id="<?php echo esc_attr( $prefix . 'title' ); ?>" name="<?php echo esc_attr( $prefix . 'title' ); ?>" required />
			</p>
			<p class="umi-form-row">
				<label class="umi-label" for="<?php echo esc_attr( $prefix . 'content' ); ?>"><?php esc_html_e( 'Описание', 'umi-marketplace' ); ?></label>
				<textarea class="umi-input" id="<?php echo esc_attr( $prefix . 'content' ); ?>" name="<?php echo esc_attr( $prefix . 'content' ); ?>" rows="6" required></textarea>
			</p>
			<p class="umi-form-row">
				<?php if ( $is_service ) : ?>
				<label class="umi-label" for="<?php echo esc_attr( $prefix . 'price' ); ?>"><?php esc_html_e( 'Стоимость, ₽', 'umi-marketplace' ); ?></label>
				<?php else : ?>
				<label class="umi-label" for="<?php echo esc_attr( $prefix . 'price' ); ?>" id="umi_p_price_label"><?php esc_html_e( 'Можно купить только за доли', 'umi-marketplace' ); ?></label>
				<?php endif; ?>
				<input type="number" class="umi-input" id="<?php echo esc_attr( $prefix . 'price' ); ?>" name="<?php echo esc_attr( $prefix . 'price' ); ?>" step="1" min="0" value="0" />
			</p>
			<p class="umi-form-row">
				<label class="umi-label" for="<?php echo esc_attr( $prefix . 'city' ); ?>"><?php esc_html_e( 'Город', 'umi-marketplace' ); ?></label>
				<input type="text" class="umi-input" id="<?php echo esc_attr( $prefix . 'city' ); ?>" name="<?php echo esc_attr( $prefix . 'city' ); ?>" placeholder="<?php esc_attr_e( 'По умолчанию — из профиля', 'umi-marketplace' ); ?>" />
			</p>
			<?php if ( $is_service ) : ?>
			<fieldset class="umi-form-row umi-service-intent-fieldset">
				<legend class="umi-label"><?php esc_html_e( 'Тип объявления', 'umi-marketplace' ); ?></legend>
				<div class="umi-service-intent-radios">
					<label class="umi-check-label">
						<input type="radio" name="umi_s_intent" value="offer" checked />
						<?php esc_html_e( 'Предлагаю услугу', 'umi-marketplace' ); ?>
					</label>
					<label class="umi-check-label">
						<input type="radio" name="umi_s_intent" value="seek" />
						<?php esc_html_e( 'Ищу услугу', 'umi-marketplace' ); ?>
					</label>
				</div>
			</fieldset>
			<p class="umi-form-row umi-form-row--check">
				<label class="umi-check-label" for="<?php echo esc_attr( $prefix . 'pay_shares' ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $prefix . 'pay_shares' ); ?>" name="<?php echo esc_attr( $prefix . 'pay_shares' ); ?>" value="1" checked />
					<?php esc_html_e( 'Можно купить за доли (сделка с оплатой долями на сайте)', 'umi-marketplace' ); ?>
				</label>
			</p>
			<?php else : ?>
			<div class="umi-product-author-fields" data-umi-product-author>
				<p class="umi-form-row umi-form-row--check">
					<label class="umi-check-label" for="umi_p_author">
						<input type="checkbox" id="umi_p_author" name="umi_p_author" value="1" />
						<?php esc_html_e( 'Авторский товар', 'umi-marketplace' ); ?>
					</label>
				</p>
				<p class="umi-form-hint"><?php esc_html_e( 'Авторский товар можно продать и за доли на сайте, и за рубли (без оплаты долями на сайте). Любой другой товар — только за доли на сайте; галочка ниже для такого товара всегда включена.', 'umi-marketplace' ); ?></p>
				<p class="umi-form-row umi-form-row--check">
					<label class="umi-check-label" for="<?php echo esc_attr( $prefix . 'pay_shares' ); ?>">
						<input type="checkbox" id="<?php echo esc_attr( $prefix . 'pay_shares' ); ?>" name="<?php echo esc_attr( $prefix . 'pay_shares' ); ?>" value="1" checked />
						<?php esc_html_e( 'Можно купить за доли (сделка с оплатой долями на сайте)', 'umi-marketplace' ); ?>
					</label>
				</p>
			</div>
			<?php endif; ?>
			<div class="umi-form-row">
				<span class="umi-label"><?php esc_html_e( 'Обложка', 'umi-marketplace' ); ?></span>
				<?php self::render_cabinet_upload_field( $prefix . 'cov', (string) $prefix . 'thumb' ); ?>
			</div>
			<div class="umi-form-row umi-cabinet-grid" aria-label="<?php echo esc_attr__( 'Галерея', 'umi-marketplace' ); ?>">
				<?php
				for ( $i = 0; $i < 3; $i++ ) {
					$fid   = $prefix . 'g' . $i;
					$up_id = $prefix . 'upg' . $i;
					$glab  = sprintf(
						/* translators: %d photo number */
						__( 'Фото %d', 'umi-marketplace' ),
						$i + 1
					);
					echo '<div class="umi-cabinet-upload-wrap">';
					echo '<p class="umi-label" id="' . esc_attr( 'lab_' . $fid ) . '">' . esc_html( $glab ) . '</p>';
					self::render_cabinet_upload_field( $up_id, $prefix . 'gallery[]', 'lab_' . $fid );
					echo '</div>';
				}
				?>
			</div>
			<p class="umi-form-hint"><?php esc_html_e( 'Изображения загружаются в ваше личное хранилище; в объявлении будут использованы только выбранные файлы. Размещение в каталоге — после модерации и при неисчерпанном лимите.', 'umi-marketplace' ); ?></p>
			<p class="umi-form-row">
				<button type="submit" class="umi-btn"><?php echo $is_service ? esc_html__( 'Отправить услугу на модерацию', 'umi-marketplace' ) : esc_html__( 'Отправить товар на модерацию', 'umi-marketplace' ); ?></button>
			</p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Редактирование существующего объявления.
	 *
	 * @param WP_Post $post      Пост.
	 * @param string  $return_url Return URL.
	 * @return string
	 */
	private static function render_cabinet_edit_form( $post, $return_url ) {
		$post   = is_object( $post ) && isset( $post->ID ) ? $post : null;
		if ( ! $post || ! in_array( $post->post_type, array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ), true ) ) {
			return '';
		}
		$is_service   = ( Umi_Cpt::SERVICE === $post->post_type );
		$prefix       = $is_service ? 'umi_s_' : 'umi_p_';
		$action_value = $is_service ? 'edit_service' : 'edit_product';
		$nonce        = $is_service ? 'umi_cabinet_nonce_edit_s' : 'umi_cabinet_nonce_edit_p';
		$nonce_action = $is_service ? 'umi_cabinet_edit_service' : 'umi_cabinet_edit_product';
		$price        = max( 0, (int) get_post_meta( $post->ID, '_umi_price', true ) );
		$city         = (string) get_post_meta( $post->ID, '_umi_city', true );
		$pay_m        = (string) get_post_meta( $post->ID, '_umi_pay_shares', true );
		$author_m     = (string) get_post_meta( $post->ID, '_umi_author_product', true );
		$pay_shares   = $is_service ? ( '' === $pay_m || '1' === $pay_m ) : ( '1' === $pay_m );
		$author_ch    = $is_service ? true : ( '0' !== $author_m );
		$svc_intent   = $is_service ? Umi_Meta_Boxes::get_service_intent( (int) $post->ID ) : 'offer';
		$tid          = (int) get_post_thumbnail_id( $post->ID );
		$graw         = (string) get_post_meta( $post->ID, '_umi_gallery', true );
		$gall         = array_filter( array_map( 'intval', explode( ',', $graw ) ) );
		$gall         = array_slice( $gall, 0, 3 );
		while ( count( $gall ) < 3 ) {
			$gall[] = 0;
		}
		ob_start();
		?>
		<form method="post" class="umi-form umi-cabinet-form" action="<?php echo esc_url( $return_url ); ?>#umi-cabinet" id="umi-cabinet-edit-form">
			<input type="hidden" name="umi_cabinet" value="1" />
			<input type="hidden" name="umi_cabinet_action" value="<?php echo esc_attr( $action_value ); ?>" />
			<input type="hidden" name="umi_cabinet_return" value="<?php echo esc_url( $return_url ); ?>" />
			<input type="hidden" name="umi_edit_post_id" value="<?php echo (int) $post->ID; ?>" />
			<?php wp_nonce_field( $nonce_action, $nonce, false, true ); ?>
			<p class="umi-form-row">
				<label class="umi-label" for="<?php echo esc_attr( $prefix . 'title' ); ?>"><?php esc_html_e( 'Название', 'umi-marketplace' ); ?></label>
				<input type="text" class="umi-input" id="<?php echo esc_attr( $prefix . 'title' ); ?>" name="<?php echo esc_attr( $prefix . 'title' ); ?>" required value="<?php echo esc_attr( get_the_title( $post ) ); ?>" />
			</p>
			<p class="umi-form-row">
				<label class="umi-label" for="<?php echo esc_attr( $prefix . 'content' ); ?>"><?php esc_html_e( 'Описание', 'umi-marketplace' ); ?></label>
				<textarea class="umi-input" id="<?php echo esc_attr( $prefix . 'content' ); ?>" name="<?php echo esc_attr( $prefix . 'content' ); ?>" rows="6" required><?php echo esc_textarea( (string) $post->post_content ); ?></textarea>
			</p>
			<p class="umi-form-row">
				<?php if ( $is_service ) : ?>
				<label class="umi-label" for="<?php echo esc_attr( $prefix . 'price' ); ?>"><?php esc_html_e( 'Стоимость, ₽', 'umi-marketplace' ); ?></label>
				<?php else : ?>
				<label class="umi-label" for="<?php echo esc_attr( $prefix . 'price' ); ?>" id="umi_p_price_label"><?php esc_html_e( 'Стоимость, ₽', 'umi-marketplace' ); ?></label>
				<?php endif; ?>
				<input type="number" class="umi-input" id="<?php echo esc_attr( $prefix . 'price' ); ?>" name="<?php echo esc_attr( $prefix . 'price' ); ?>" step="1" min="0" value="<?php echo (int) $price; ?>" />
			</p>
			<p class="umi-form-row">
				<label class="umi-label" for="<?php echo esc_attr( $prefix . 'city' ); ?>"><?php esc_html_e( 'Город', 'umi-marketplace' ); ?></label>
				<input type="text" class="umi-input" id="<?php echo esc_attr( $prefix . 'city' ); ?>" name="<?php echo esc_attr( $prefix . 'city' ); ?>" placeholder="<?php esc_attr_e( 'По умолчанию — из профиля', 'umi-marketplace' ); ?>" value="<?php echo esc_attr( $city ); ?>" />
			</p>
			<?php if ( $is_service ) : ?>
			<fieldset class="umi-form-row umi-service-intent-fieldset">
				<legend class="umi-label"><?php esc_html_e( 'Тип объявления', 'umi-marketplace' ); ?></legend>
				<div class="umi-service-intent-radios">
					<label class="umi-check-label">
						<input type="radio" name="umi_s_intent" value="offer" <?php checked( $svc_intent, 'offer' ); ?> />
						<?php esc_html_e( 'Предлагаю услугу', 'umi-marketplace' ); ?>
					</label>
					<label class="umi-check-label">
						<input type="radio" name="umi_s_intent" value="seek" <?php checked( $svc_intent, 'seek' ); ?> />
						<?php esc_html_e( 'Ищу услугу', 'umi-marketplace' ); ?>
					</label>
				</div>
			</fieldset>
			<p class="umi-form-row umi-form-row--check">
				<label class="umi-check-label" for="<?php echo esc_attr( $prefix . 'pay_shares' ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $prefix . 'pay_shares' ); ?>" name="<?php echo esc_attr( $prefix . 'pay_shares' ); ?>" value="1" <?php checked( $pay_shares ); ?> />
					<?php esc_html_e( 'Можно купить за доли (сделка с оплатой долями на сайте)', 'umi-marketplace' ); ?>
				</label>
			</p>
			<?php else : ?>
			<div class="umi-product-author-fields" data-umi-product-author>
				<p class="umi-form-row umi-form-row--check">
					<label class="umi-check-label" for="umi_p_author">
						<input type="checkbox" id="umi_p_author" name="umi_p_author" value="1" <?php checked( $author_ch ); ?> />
						<?php esc_html_e( 'Авторский товар', 'umi-marketplace' ); ?>
					</label>
				</p>
				<p class="umi-form-hint"><?php esc_html_e( 'Авторский товар можно продать и за доли на сайте, и за рубли (без оплаты долями на сайте). Любой другой товар — только за доли на сайте; галочка ниже для такого товара всегда включена.', 'umi-marketplace' ); ?></p>
				<p class="umi-form-row umi-form-row--check">
					<label class="umi-check-label" for="<?php echo esc_attr( $prefix . 'pay_shares' ); ?>">
						<input type="checkbox" id="<?php echo esc_attr( $prefix . 'pay_shares' ); ?>" name="<?php echo esc_attr( $prefix . 'pay_shares' ); ?>" value="1" <?php checked( $pay_shares ); ?> />
						<?php esc_html_e( 'Можно купить за доли (сделка с оплатой долями на сайте)', 'umi-marketplace' ); ?>
					</label>
				</p>
			</div>
			<?php endif; ?>
			<div class="umi-form-row">
				<span class="umi-label"><?php esc_html_e( 'Обложка', 'umi-marketplace' ); ?></span>
				<?php self::render_cabinet_upload_field( $prefix . 'cov', (string) $prefix . 'thumb', '', $tid ); ?>
			</div>
			<div class="umi-form-row umi-cabinet-grid" aria-label="<?php echo esc_attr__( 'Галерея', 'umi-marketplace' ); ?>">
				<?php
				for ( $i = 0; $i < 3; $i++ ) {
					$fid   = $prefix . 'g' . $i;
					$up_id = $prefix . 'upg' . $i;
					$glab  = sprintf(
						__( 'Фото %d', 'umi-marketplace' ),
						$i + 1
					);
					$slot_id = isset( $gall[ $i ] ) ? (int) $gall[ $i ] : 0;
					if ( (int) $slot_id === (int) $tid ) {
						$slot_id = 0;
					}
					echo '<div class="umi-cabinet-upload-wrap">';
					echo '<p class="umi-label" id="' . esc_attr( 'lab_' . $fid ) . '">' . esc_html( $glab ) . '</p>';
					self::render_cabinet_upload_field( $up_id, $prefix . 'gallery[]', 'lab_' . $fid, $slot_id );
					echo '</div>';
				}
				?>
			</div>
			<p class="umi-form-hint"><?php esc_html_e( 'После сохранения объявление снова попадёт на модерацию.', 'umi-marketplace' ); ?></p>
			<p class="umi-form-row">
				<button type="submit" class="umi-btn umi-btn--primary"><?php esc_html_e( 'Сохранить изменения', 'umi-marketplace' ); ?></button>
			</p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Registration form (requires WP "Anyone can register").
	 *
	 * @return string
	 */
	public static function register_form() {
		if ( is_user_logged_in() ) {
			return '';
		}
		if ( ! get_option( 'users_can_register' ) ) {
			return '<p class="umi-notice">' . esc_html__( 'Регистрация отключена в настройках сайта.', 'umi-marketplace' ) . '</p>';
		}

		$err = '';
		if ( isset( $_POST['umi_reg_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['umi_reg_nonce'] ) ), 'umi_register' ) ) {
			$login    = isset( $_POST['user_login'] ) ? sanitize_user( wp_unslash( $_POST['user_login'] ), true ) : '';
			$email    = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
			$pass     = isset( $_POST['user_pass'] ) ? (string) wp_unslash( $_POST['user_pass'] ) : '';
			$display  = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
			$phone    = isset( $_POST['umi_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['umi_phone'] ) ) : '';

			if ( strlen( $pass ) < 8 ) {
				$err = __( 'Пароль не короче 8 символов.', 'umi-marketplace' );
			} elseif ( ! is_email( $email ) ) {
				$err = __( 'Некорректный email.', 'umi-marketplace' );
			} else {
				$uid = wp_create_user( $login, $pass, $email );
				if ( is_wp_error( $uid ) ) {
					$err = $uid->get_error_message();
				} else {
					wp_update_user(
						array(
							'ID'           => $uid,
							'display_name' => $display ? $display : $login,
							'first_name'   => $display,
						)
					);
					update_user_meta( $uid, 'umi_phone', $phone );
					Umi_Email_Verification::after_registration( $uid );
					return '<p class="umi-success">' . esc_html__( 'Регистрация успешна. На вашу почту отправлена ссылка для подтверждения email. После подтверждения можно войти.', 'umi-marketplace' ) . '</p>';
				}
			}
		}

		$old_login   = isset( $login ) ? esc_attr( $login ) : '';
		$old_email   = isset( $email ) ? esc_attr( $email ) : '';
		$old_display = isset( $display ) ? esc_attr( $display ) : '';
		$old_phone   = isset( $phone ) ? esc_attr( $phone ) : '';

		ob_start();
		if ( $err ) {
			echo '<p class="umi-error">' . esc_html( $err ) . '</p>';
		}
		?>
		<form method="post" class="umi-form umi-register">
			<?php wp_nonce_field( 'umi_register', 'umi_reg_nonce' ); ?>
			<p>
				<label class="umi-label"><?php esc_html_e( 'Логин', 'umi-marketplace' ); ?></label>
				<input type="text" name="user_login" class="umi-input" required autocomplete="username" value="<?php echo $old_login; ?>" />
			</p>
			<p>
				<label class="umi-label"><?php esc_html_e( 'Email', 'umi-marketplace' ); ?></label>
				<input type="email" name="user_email" class="umi-input" required autocomplete="email" value="<?php echo $old_email; ?>" />
			</p>
			<p>
				<label class="umi-label"><?php esc_html_e( 'Имя', 'umi-marketplace' ); ?></label>
				<input type="text" name="display_name" class="umi-input" required value="<?php echo $old_display; ?>" />
			</p>
			<p>
				<label class="umi-label"><?php esc_html_e( 'Телефон', 'umi-marketplace' ); ?></label>
				<input type="text" name="umi_phone" class="umi-input" required value="<?php echo $old_phone; ?>" />
			</p>
			<p>
				<label class="umi-label"><?php esc_html_e( 'Пароль', 'umi-marketplace' ); ?></label>
				<input type="password" name="user_pass" class="umi-input" required autocomplete="new-password" />
				<small class="umi-field-hint"><?php esc_html_e( 'от 8 символов', 'umi-marketplace' ); ?></small>
			</p>
			<button type="submit" class="umi-btn"><?php esc_html_e( 'Зарегистрироваться', 'umi-marketplace' ); ?></button>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Login form.
	 *
	 * @return string
	 */
	public static function login_form() {
		if ( is_user_logged_in() ) {
			return '';
		}
		$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
		if ( ! $redirect ) {
			$redirect = home_url( '/' );
		}
		$reg_url     = (string) apply_filters( 'umi_header_url_register', home_url( '/registracziya/' ) );
		$login_self  = Umi_Email_Verification::login_page_url();
		$verify_ok   = isset( $_GET['umi_verified'] ) && '1' === (string) wp_unslash( $_GET['umi_verified'] );
		$verify_bad  = isset( $_GET['umi_verify'] ) && 'invalid' === (string) wp_unslash( $_GET['umi_verify'] );
		$resend_note = isset( $_GET['umi_resend'] ) ? sanitize_text_field( wp_unslash( $_GET['umi_resend'] ) ) : '';
		ob_start();
		?>
		<div class="umi-page-root umi-auth-page">
			<div class="umi-auth-card">
				<h1 class="umi-auth-card__title"><?php esc_html_e( 'Вход', 'umi-marketplace' ); ?></h1>
				<?php if ( $verify_ok ) : ?>
					<p class="umi-success" role="status"><?php esc_html_e( 'Email подтверждён. Можно войти.', 'umi-marketplace' ); ?></p>
				<?php elseif ( $verify_bad ) : ?>
					<p class="umi-error" role="alert"><?php esc_html_e( 'Ссылка недействительна или устарела. Запросите письмо снова ниже.', 'umi-marketplace' ); ?></p>
				<?php endif; ?>
				<?php
				if ( 'sent' === $resend_note ) {
					echo '<p class="umi-success" role="status">' . esc_html__( 'Если указанный email зарегистрирован и не подтверждён, мы отправили новое письмо.', 'umi-marketplace' ) . '</p>';
				} elseif ( 'already' === $resend_note ) {
					echo '<p class="umi-notice" role="status">' . esc_html__( 'Этот email уже подтверждён — войдите как обычно.', 'umi-marketplace' ) . '</p>';
				} elseif ( 'bad_email' === $resend_note ) {
					echo '<p class="umi-error" role="alert">' . esc_html__( 'Введите корректный email.', 'umi-marketplace' ) . '</p>';
				}
				?>
				<p class="umi-auth-card__lead">
					<?php esc_html_e( 'Вход или', 'umi-marketplace' ); ?>
					<a class="umi-auth-card__link" href="<?php echo esc_url( $reg_url ); ?>"><?php esc_html_e( 'зарегистрироваться', 'umi-marketplace' ); ?></a>
				</p>
				<form name="loginform" method="post" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>" class="umi-form umi-auth-card__form umi-login">
					<p class="umi-auth-field">
						<label class="umi-label" for="umi-login-log"><?php esc_html_e( 'Логин или email', 'umi-marketplace' ); ?></label>
						<input id="umi-login-log" type="text" name="log" class="umi-input" required autocomplete="username" />
					</p>
					<p class="umi-auth-field">
						<label class="umi-label" for="umi-login-pwd"><?php esc_html_e( 'Пароль', 'umi-marketplace' ); ?></label>
						<input id="umi-login-pwd" type="password" name="pwd" class="umi-input" required autocomplete="current-password" />
					</p>
					<p class="umi-login-remember">
						<label><input type="checkbox" name="rememberme" value="forever" /> <?php esc_html_e( 'Запомнить', 'umi-marketplace' ); ?></label>
					</p>
					<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>" />
					<p class="umi-auth-field umi-auth-field--submit">
						<button type="submit" name="wp-submit" id="wp-submit" class="umi-btn umi-btn--primary umi-auth-card__submit"><?php esc_html_e( 'Войти', 'umi-marketplace' ); ?></button>
					</p>
				</form>
				<div class="umi-auth-resend">
					<p class="umi-auth-card__lead"><?php esc_html_e( 'Не пришло письмо с подтверждением?', 'umi-marketplace' ); ?></p>
					<form method="post" class="umi-form umi-auth-resend-form" action="<?php echo esc_url( $login_self ); ?>">
						<?php wp_nonce_field( 'umi_resend_verification', 'umi_resend_nonce' ); ?>
						<input type="hidden" name="umi_resend_action" value="1" />
						<p class="umi-auth-field">
							<label class="umi-label" for="umi-resend-email"><?php esc_html_e( 'Email', 'umi-marketplace' ); ?></label>
							<input id="umi-resend-email" type="email" name="umi_resend_email" class="umi-input" required autocomplete="email" />
						</p>
						<p class="umi-auth-field umi-auth-field--submit">
							<button type="submit" class="umi-btn umi-btn--outline"><?php esc_html_e( 'Отправить ссылку снова', 'umi-marketplace' ); ?></button>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Balance display.
	 *
	 * @return string
	 */
	public static function balance() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$b  = (int) round( (float) Umi_Balance::get( get_current_user_id() ) );
		$bf = number_format_i18n( $b, 0 );
		return '<span class="umi-balance">' . esc_html( sprintf( __( 'Доли: %s', 'umi-marketplace' ), $bf ) ) . '</span>';
	}

	/**
	 * Unread badge for header.
	 *
	 * @return string
	 */
	public static function unread_badge() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$n = Umi_Chat::unread_count_for_user( get_current_user_id() );
		if ( $n < 1 ) {
			return '<span class="umi-chat-badge umi-chat-badge--empty" data-umi-unread="0" aria-hidden="true"></span>';
		}
		return '<span class="umi-chat-badge" data-umi-unread="' . (int) $n . '" title="' . esc_attr__( 'Непрочитанные сообщения', 'umi-marketplace' ) . '">' . (int) $n . '</span>';
	}

	/**
	 * Чат с администратором внизу кабинета: кнопка и раскрываемая панель.
	 *
	 * @param int $adm_thread Thread ID.
	 * @return string
	 */
	private static function render_cabinet_admin_chat_bar( $adm_thread ) {
		$adm_thread = (int) $adm_thread;
		if ( $adm_thread < 1 ) {
			return '';
		}
		wp_enqueue_script( 'umi-mp-chat' );
		$out  = '<section class="umi-cabinet-section umi-cabinet-admin-chat-bar" id="umi-cabinet-admin-chat" aria-label="' . esc_attr__( 'Сообщения администратору', 'umi-marketplace' ) . '">';
		$out .= '<div class="umi-cabinet-admin-chat-launch">';
		$out .= '<button type="button" class="umi-btn umi-btn--outline umi-cabinet-admin-chat-btn" data-umi-admin-chat-toggle aria-expanded="false" aria-controls="umi-cabinet-admin-chat-panel">';
		$out .= esc_html__( 'Чат с администратором', 'umi-marketplace' );
		$out .= '</button></div>';
		$out .= '<div class="umi-cabinet-admin-chat-panel" id="umi-cabinet-admin-chat-panel" data-umi-admin-chat-panel hidden>';
		$out .= '<h2 class="umi-cabinet-heading">' . esc_html__( 'Чат с администратором', 'umi-marketplace' ) . '</h2>';
		$out .= '<p class="umi-cabinet-lead umi-text-muted">' . esc_html__( 'Вопросы по сайту, модерации и сделкам. Ответы не мгновенны.', 'umi-marketplace' ) . '</p>';
		$out .= self::render_chat_thread_box( $adm_thread );
		$out .= '</div></section>';
		return $out;
	}

	/**
	 * Разметка виджета чата для существующего треда (покупатель или продавец).
	 *
	 * @param int $thread_id Thread ID.
	 * @return string
	 */
	private static function render_chat_thread_box( $thread_id ) {
		$thread_id = (int) $thread_id;
		if ( $thread_id < 1 ) {
			return '';
		}
		wp_enqueue_script( 'umi-mp-chat' );
		ob_start();
		?>
		<div class="umi-chat umi-chat--listing" id="umi-chat" data-thread="<?php echo (int) $thread_id; ?>">
			<div class="umi-chat-log" data-last-id="0"></div>
			<form class="umi-chat-form">
				<input type="hidden" name="thread_id" value="<?php echo (int) $thread_id; ?>" />
				<textarea name="message" class="umi-input" rows="3" required placeholder="<?php esc_attr_e( 'Сообщение…', 'umi-marketplace' ); ?>"></textarea>
				<button type="submit" class="umi-btn umi-btn--primary"><?php esc_html_e( 'Отправить', 'umi-marketplace' ); ?></button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Чат спора: текст + вложение-картинка (до 2 МБ, AJAX).
	 *
	 * @param int $thread_id Thread.
	 * @return string
	 */
	private static function render_dispute_chat_box( $thread_id ) {
		$thread_id = (int) $thread_id;
		if ( $thread_id < 1 ) {
			return '';
		}
		wp_enqueue_script( 'umi-mp-chat' );
		ob_start();
		?>
		<div class="umi-chat umi-chat--dispute" id="umi-dispute-chat-<?php echo (int) $thread_id; ?>" data-thread="<?php echo (int) $thread_id; ?>" data-umi-dispute="1">
			<div class="umi-chat-log" data-last-id="0"></div>
			<form class="umi-chat-form umi-chat-form--dispute" data-umi-dispute-form>
				<div class="umi-dispute-attach">
					<label class="umi-btn umi-btn--secondary umi-dispute-attach__btn">
						<input type="file" class="umi-dispute-file" id="umi-dispute-file-<?php echo (int) $thread_id; ?>" accept="image/jpeg,image/png,image/gif,image/webp" />
						<span><?php esc_html_e( 'Прикрепить фото (до 2 МБ)', 'umi-marketplace' ); ?></span>
					</label>
					<input type="hidden" class="umi-dispute-attachment-id" name="dispute_attachment_id" value="" autocomplete="off" />
					<span class="umi-dispute-attach-preview" hidden></span>
					<button type="button" class="umi-link umi-dispute-attach-clear" hidden><?php esc_html_e( 'Снять вложение', 'umi-marketplace' ); ?></button>
				</div>
				<textarea name="message" class="umi-input" rows="3" placeholder="<?php esc_attr_e( 'Текст или только фото…', 'umi-marketplace' ); ?>"></textarea>
				<button type="submit" class="umi-btn umi-btn--primary"><?php esc_html_e( 'Отправить', 'umi-marketplace' ); ?></button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Какой тред показать владельцу объявления: из ?umi_thread= или единственный.
	 *
	 * @param int   $listing_id  Listing post ID.
	 * @param int   $seller_uid  Current user (seller).
	 * @param array $threads     Rows from Umi_Chat::threads_for_listing_seller.
	 * @return int 0 = нужен выбор из нескольких.
	 */
	private static function resolve_seller_thread_for_listing( $listing_id, $seller_uid, array $threads ) {
		if ( empty( $threads ) ) {
			return 0;
		}
		$requested = 0;
		if ( isset( $_GET['umi_thread'] ) ) {
			$requested = (int) $_GET['umi_thread'];
		}
		if ( $requested > 0 ) {
			$row = Umi_Chat::get_thread( $requested );
			if (
				$row
				&& (int) $row['listing_id'] === (int) $listing_id
				&& (int) $row['seller_id'] === (int) $seller_uid
				&& Umi_Chat::TYPE_LISTING === Umi_Chat::thread_type( $row )
			) {
				return $requested;
			}
		}
		if ( 1 === count( $threads ) ) {
			return (int) $threads[0]['id'];
		}
		return 0;
	}

	/**
	 * Переключатель диалогов (несколько покупателей по одному объявлению).
	 *
	 * @param int   $listing_id Post ID.
	 * @param array $threads    Threads rows.
	 * @param int   $active_id  Current thread or 0.
	 * @return string
	 */
	private static function render_seller_thread_switcher( $listing_id, array $threads, $active_id ) {
		$listing_id = (int) $listing_id;
		$active_id  = (int) $active_id;
		$base       = get_permalink( $listing_id );
		$out        = '<div class="umi-chat-seller-threads" role="navigation" aria-label="' . esc_attr__( 'Диалоги с покупателями', 'umi-marketplace' ) . '">';
		$out       .= '<p class="umi-chat-seller-threads__label">' . esc_html__( 'С кем ведёте переписку', 'umi-marketplace' ) . '</p><ul class="umi-chat-seller-threads__list">';
		foreach ( $threads as $t ) {
			$tid  = (int) $t['id'];
			$href = esc_url( add_query_arg( 'umi_thread', $tid, $base ) . '#umi-chat' );
			$name = (string) $t['other_name'];
			if ( '' === $name ) {
				$name = sprintf( /* translators: %d user id */ __( 'Пользователь #%d', 'umi-marketplace' ), (int) $t['buyer_id'] );
			}
			$licls = 'umi-chat-seller-threads__item' . ( ( $active_id > 0 && $tid === $active_id ) ? ' is-active' : '' );
			$out    .= '<li class="' . esc_attr( $licls ) . '"><a href="' . $href . '">' . esc_html( $name ) . '</a></li>';
		}
		$out .= '</ul></div>';
		return $out;
	}

	/**
	 * HTML чата по объявлению (и для [umi_chat], и внутри [umi_listing_card]).
	 *
	 * @param int $listing_id Post ID.
	 * @return string
	 */
	private static function render_listing_chat_markup( $listing_id ) {
		$listing_id = (int) $listing_id;
		if ( $listing_id < 1 ) {
			return '';
		}
		if ( ! empty( self::$umi_chat_listing_done[ $listing_id ] ) ) {
			return '';
		}
		$out = '';
		if ( ! is_user_logged_in() ) {
			$out = '<p class="umi-notice">' . esc_html__( 'Войдите, чтобы открыть чат.', 'umi-marketplace' ) . '</p>';
		} else {
			$post = get_post( $listing_id );
			if ( ! $post || ! in_array( $post->post_type, array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ), true ) ) {
				return '';
			}
			$current_uid = get_current_user_id();
			$seller_id   = (int) $post->post_author;
			if ( (int) $current_uid === $seller_id ) {
				$threads = Umi_Chat::threads_for_listing_seller( $listing_id, $current_uid );
				if ( ! count( $threads ) ) {
					$out = '<p class="umi-notice"><strong>' . esc_html__( 'Это ваше объявление.', 'umi-marketplace' ) . '</strong> ';
					$out .= esc_html__( 'Сообщения от покупателей появятся здесь, когда кто-то нажмёт «Написать продавцу».', 'umi-marketplace' ) . '</p>';
				} else {
					$pick  = self::resolve_seller_thread_for_listing( $listing_id, $current_uid, $threads );
					$parts = array();
					if ( count( $threads ) > 1 ) {
						$parts[] = self::render_seller_thread_switcher( $listing_id, $threads, $pick );
					}
					if ( $pick > 0 ) {
						$parts[] = self::render_chat_thread_box( $pick );
					} else {
						$parts[] = '<p class="umi-notice">' . esc_html__( 'Выберите диалог выше, чтобы открыть переписку.', 'umi-marketplace' ) . '</p>';
					}
					$out = implode( '', array_filter( $parts ) );
				}
			} else {
				$type   = Umi_Chat::listing_type_from_cpt( $post->post_type );
				$thread = Umi_Chat::get_or_create_thread( $listing_id, $type, $current_uid, $seller_id );
				$out    = self::render_chat_thread_box( $thread );
			}
		}
		if ( '' !== $out ) {
			self::$umi_chat_listing_done[ $listing_id ] = true;
		}
		return $out;
	}

	/**
	 * Open chat UI for listing.
	 *
	 * @param array $atts Atts.
	 * @return string
	 */
	public static function chat( $atts ) {
		$atts = shortcode_atts(
			array(
				'id' => 0,
			),
			$atts,
			'umi_chat'
		);
		$listing_id = (int) $atts['id'];
		if ( $listing_id < 1 && is_singular( array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ) ) ) {
			$listing_id = (int) get_queried_object_id();
		}
		return self::render_listing_chat_markup( $listing_id );
	}

	/**
	 * Человекочитаемая метка уровня продавца.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function seller_level_label( $key ) {
		$key = sanitize_key( $key );
		$map = array(
			'novice'  => __( 'Новичок', 'umi-marketplace' ),
			'amateur' => __( 'Любитель', 'umi-marketplace' ),
			'pro'     => __( 'Профессионал', 'umi-marketplace' ),
		);
		return isset( $map[ $key ] ) ? $map[ $key ] : '';
	}

	/**
	 * Показывать ли публичный профиль: роль продавца или есть опубликованные объявления.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function user_has_public_seller_profile( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return false;
		}
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			return false;
		}
		if ( in_array( Umi_Roles::ROLE_SELLER, (array) $u->roles, true ) ) {
			return true;
		}
		$q = new WP_Query(
			array(
				'post_type'              => array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ),
				'post_status'            => 'publish',
				'author'                 => $user_id,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$ok = $q->have_posts();
		wp_reset_postdata();
		return $ok;
	}

	/**
	 * Ссылка на публичный профиль продавца (?umi_seller=ID на заданной странице).
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function get_seller_profile_url( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return '';
		}
		$page_id = (int) Umi_Settings::get_var( 'seller_profile_page_id', 0 );
		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			$url = get_permalink( $page_id );
			return $url ? (string) add_query_arg( 'umi_seller', $user_id, $url ) : '';
		}
		return (string) add_query_arg( 'umi_seller', $user_id, home_url( '/' ) );
	}

	/**
	 * Заголовок окна браузера на странице профиля продавца.
	 */
	public static function maybe_seller_profile_seo() {
		if ( is_admin() ) {
			return;
		}
		$page_id = (int) Umi_Settings::get_var( 'seller_profile_page_id', 0 );
		if ( $page_id < 1 || ! is_page( $page_id ) ) {
			return;
		}
		$uid = isset( $_GET['umi_seller'] ) ? (int) $_GET['umi_seller'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $uid < 1 ) {
			return;
		}
		$u = get_userdata( $uid );
		if ( ! $u || ! self::user_has_public_seller_profile( $uid ) ) {
			return;
		}
		add_filter(
			'document_title_parts',
			static function ( $parts ) use ( $u ) {
				$parts['title'] = sprintf(
					/* translators: %s: display name */
					__( '%s — продавец', 'umi-marketplace' ),
					$u->display_name
				);
				return $parts;
			},
			20
		);
	}

	/**
	 * Аватар: фото из кабинета или Gravatar.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $img_class Class for image.
	 * @return string
	 */
	private static function render_seller_profile_hero_image( $user_id, $img_class = 'umi-seller-profile__hero-img' ) {
		$user_id   = (int) $user_id;
		$photo_att = (int) get_user_meta( $user_id, 'umi_profile_photo', true );
		if ( $photo_att > 0 ) {
			$html = wp_get_attachment_image(
				$photo_att,
				'large',
				false,
				array( 'class' => $img_class, 'loading' => 'eager' )
			);
			if ( $html ) {
				return $html;
			}
		}
		$av = get_avatar( $user_id, 400, '', '', array( 'class' => $img_class ) );
		return $av ? (string) $av : '';
	}

	/**
	 * Публичный профиль продавца. Параметр в URL: umi_seller, либо id="N" в шорткоде.
	 * На странице вставьте только шорткод; в UMI — настройки — укажите эту страницу.
	 *
	 * @param array $atts Atts.
	 * @return string
	 */
	public static function seller_profile( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'        => 0,
				'per_page'  => 12,
			),
			$atts,
			'umi_seller_profile'
		);
		$uid = (int) $atts['id'];
		if ( $uid < 1 && isset( $_GET['umi_seller'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$uid = (int) $_GET['umi_seller'];
		}
		if ( $uid < 1 ) {
			return '<p class="umi-notice">' . esc_html__( 'Укажите продавца в ссылке (параметр umi_seller) или id в шорткоде.', 'umi-marketplace' ) . '</p>';
		}
		$user = get_userdata( $uid );
		if ( ! $user ) {
			return '<p class="umi-notice">' . esc_html__( 'Пользователь не найден.', 'umi-marketplace' ) . '</p>';
		}
		if ( ! self::user_has_public_seller_profile( $uid ) ) {
			return '<p class="umi-notice">' . esc_html__( 'Публичный профиль продавца недоступен.', 'umi-marketplace' ) . '</p>';
		}

		$city         = (string) get_user_meta( $uid, 'umi_profile_city', true );
		$profession   = (string) get_user_meta( $uid, 'umi_profile_profession', true );
		$level        = self::seller_level_label( (string) get_user_meta( $uid, 'umi_profile_level', true ) );
		$subline      = array_filter( array( $profession, $level, $city ) );
		$per_page     = max( 1, min( 48, (int) $atts['per_page'] ) );
		$paged        = self::current_page();
		$list_q       = new WP_Query(
			array(
				'post_type'      => array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ),
				'post_status'   => 'publish',
				'author'         => $uid,
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$reviews = Umi_Reviews::get_published_to_user( $uid, 15 );
		$sum     = 0;
		$nrev    = count( $reviews );
		if ( $nrev > 0 ) {
			foreach ( $reviews as $r ) {
				$sum += isset( $r['rating'] ) ? (int) $r['rating'] : 0;
			}
		}
		$avg = $nrev > 0 ? round( $sum / $nrev, 1 ) : 0;

		ob_start();
		?>
		<div class="umi-seller-profile umi-page-root">
			<header class="umi-seller-profile__head">
				<div class="umi-seller-profile__visual">
					<?php echo self::render_seller_profile_hero_image( $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="umi-seller-profile__intro">
					<p class="umi-seller-profile__kicker"><?php esc_html_e( 'Продавец', 'umi-marketplace' ); ?></p>
					<h1 class="umi-seller-profile__name"><?php echo esc_html( $user->display_name ); ?></h1>
					<?php if ( count( $subline ) ) : ?>
						<p class="umi-seller-profile__sub"><?php echo esc_html( implode( ' · ', $subline ) ); ?></p>
					<?php endif; ?>
					<?php if ( $nrev > 0 ) : ?>
						<p class="umi-seller-profile__rating" aria-label="<?php esc_attr_e( 'Средняя оценка по отзывам', 'umi-marketplace' ); ?>">
							<span class="umi-seller-profile__rating-val"><?php echo esc_html( (string) $avg ); ?></span>
							<span class="umi-seller-profile__rating-star" aria-hidden="true">★</span>
							<span class="umi-seller-profile__rating-cnt">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: count */
										_n( '%d отзыв', '%d отзывов', $nrev, 'umi-marketplace' ),
										$nrev
									)
								);
								?>
							</span>
						</p>
					<?php endif; ?>
				</div>
			</header>
			<?php if ( $list_q->have_posts() ) : ?>
				<section class="umi-sec umi-seller-profile__listings" aria-label="<?php esc_attr_e( 'Объявления продавца', 'umi-marketplace' ); ?>">
					<h2 class="umi-h2 umi-seller-profile__h2"><?php esc_html_e( 'Объявления', 'umi-marketplace' ); ?></h2>
					<div class="umi-catalog-grid umi-seller-profile__grid">
						<?php
						while ( $list_q->have_posts() ) {
							$list_q->the_post();
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							echo self::render_catalog_card_article( (int) get_the_ID() );
						}
						wp_reset_postdata();
						?>
					</div>
					<?php
					$max_p = (int) $list_q->max_num_pages;
					if ( $max_p > 1 ) {
						$pagination = paginate_links(
							array(
								'total'     => $max_p,
								'current'   => $paged,
								'add_args'  => array( 'umi_seller' => (string) $uid ),
								'type'      => 'list',
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						);
						if ( $pagination ) {
							echo '<nav class="umi-seller-profile__nav" aria-label="' . esc_attr__( 'Страницы объявлений', 'umi-marketplace' ) . '">' . $pagination . '</nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
					}
					?>
				</section>
			<?php else : ?>
				<p class="umi-seller-profile__empty"><?php esc_html_e( 'Сейчас нет опубликованных объявлений.', 'umi-marketplace' ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $reviews ) ) : ?>
				<section class="umi-sec umi-seller-profile__reviews" aria-label="<?php esc_attr_e( 'Отзывы', 'umi-marketplace' ); ?>">
					<h2 class="umi-h2 umi-seller-profile__h2"><?php esc_html_e( 'Отзывы', 'umi-marketplace' ); ?></h2>
					<ul class="umi-seller-profile__review-list">
						<?php foreach ( $reviews as $rev ) : ?>
							<li class="umi-seller-profile__review">
								<div class="umi-seller-profile__review-head">
									<?php
									$ra = isset( $rev['rating'] ) ? (int) $rev['rating'] : 5;
									for ( $i = 1; $i <= 5; $i++ ) {
										echo '<span class="umi-seller-profile__star' . ( $i <= $ra ? ' is-on' : '' ) . '" aria-hidden="true">★</span>';
									}
									?>
									<?php if ( ! empty( $rev['date_formatted'] ) ) : ?>
										<time class="umi-seller-profile__review-time" datetime="<?php echo esc_attr( isset( $rev['date_iso'] ) ? (string) $rev['date_iso'] : '' ); ?>"><?php echo esc_html( (string) $rev['date_formatted'] ); ?></time>
									<?php endif; ?>
								</div>
								<?php if ( ! empty( $rev['author_name'] ) ) : ?>
									<p class="umi-seller-profile__review-by"><?php echo esc_html( sprintf( /* translators: %s: name */ __( 'От: %s', 'umi-marketplace' ), (string) $rev['author_name'] ) ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $rev['content'] ) ) : ?>
									<div class="umi-seller-profile__review-text"><?php echo wp_kses_post( wpautop( (string) $rev['content'] ) ); ?></div>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Верх объявления: 1 / 2 / 3 снимка или плейсхолдер.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return string
	 */
	private static function render_listing_hero_images( array $ids ) {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		$n   = count( $ids );
		if ( 0 === $n ) {
			return '<div class="umi-listing-hero umi-listing-hero--empty" role="img" aria-label="' . esc_attr__( 'Нет фото', 'umi-marketplace' ) . '"><div class="umi-listing-hero__placeholder"></div></div>';
		}
		if ( 1 === $n ) {
			return '<div class="umi-listing-hero umi-listing-hero--one">' . wp_get_attachment_image( $ids[0], 'large', false, array( 'class' => 'umi-listing-hero__img' ) ) . '</div>';
		}
		$mod = 2 === $n ? 'umi-listing-hero__grid--2' : 'umi-listing-hero__grid--3';
		$out = '<div class="umi-listing-hero"><div class="umi-listing-hero__grid ' . esc_attr( $mod ) . '">';
		foreach ( $ids as $aid ) {
			$out .= '<div class="umi-listing-hero__cell">' . wp_get_attachment_image( $aid, 'large', false, array( 'class' => 'umi-listing-hero__img' ) ) . '</div>';
		}
		$out .= '</div></div>';
		return $out;
	}

	/**
	 * Кнопка «В избранное» (карточка / страница объявления).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function render_favorite_button( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id < 1 ) {
			return '';
		}
		$permalink = get_permalink( $post_id );
		if ( is_user_logged_in() && (int) get_post_field( 'post_author', $post_id ) === (int) get_current_user_id() ) {
			return '';
		}
		if ( ! is_user_logged_in() ) {
			$login = wp_login_url( $permalink ? $permalink : home_url( '/' ) );
			return '<a class="umi-fav-btn umi-fav-btn--login" href="' . esc_url( $login ) . '" title="' . esc_attr__( 'Войти, чтобы добавить в избранное', 'umi-marketplace' ) . '">'
				. '<span class="umi-fav-btn__ic" aria-hidden="true">'
				. '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="2" focusable="false"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>'
				. '</span><span class="screen-reader-text">' . esc_html__( 'В избранное', 'umi-marketplace' ) . '</span></a>';
		}
		$on    = Umi_Favorites::is_favorite( get_current_user_id(), $post_id );
		$cls   = 'umi-fav-btn' . ( $on ? ' is-active' : '' );
		$label = $on ? __( 'Убрать из избранного', 'umi-marketplace' ) : __( 'В избранное', 'umi-marketplace' );
		return '<button type="button" class="' . esc_attr( $cls ) . '" data-umi-fav="' . (int) $post_id . '" title="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '">'
			. '<span class="umi-fav-btn__ic" aria-hidden="true">'
			. '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="2" focusable="false"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>'
			. '</span><span class="screen-reader-text">' . esc_html( $label ) . '</span></button>';
	}

	/**
	 * Карточка каталога (услуга/товар) — единая вёрстка.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function render_catalog_card_article( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ), true ) ) {
			return '';
		}
		$is_service  = ( Umi_Cpt::SERVICE === $post->post_type );
		$type_label  = $is_service ? __( 'Услуга', 'umi-marketplace' ) : __( 'Товар', 'umi-marketplace' );
		$price       = (float) get_post_meta( $post_id, '_umi_price', true );
		$city        = (string) get_post_meta( $post_id, '_umi_city', true );
		$images      = Umi_Meta_Boxes::get_listing_image_ids( $post_id );
		$first       = $images ? (int) $images[0] : 0;
		$shares_ok   = Umi_Meta_Boxes::listing_allows_shares_payment( $post_id );
		$author        = get_userdata( (int) $post->post_author );
		$author_name   = $author ? $author->display_name : '';
		$author_id     = (int) $post->post_author;
		$author_href   = ( $author_name && self::user_has_public_seller_profile( $author_id ) ) ? self::get_seller_profile_url( $author_id ) : '';
		$excerpt     = get_the_excerpt( $post );
		if ( ! $excerpt ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 20, '…' );
		}
		$permalink = get_permalink( $post_id );
		$title     = get_the_title( $post_id );

		ob_start();
		?>
		<article class="umi-card umi-listing-card umi-listing-card--compact">
			<div class="umi-listing-card__media-wrap">
				<a class="umi-listing-card__media" href="<?php echo esc_url( $permalink ); ?>">
					<?php if ( $first ) : ?>
						<?php echo wp_get_attachment_image( $first, 'medium_large', false, array( 'class' => 'umi-listing-card__img', 'loading' => 'lazy' ) ); ?>
					<?php else : ?>
						<div class="umi-listing-card__placeholder" aria-hidden="true"></div>
					<?php endif; ?>
					<span class="umi-listing-card__type"><?php echo esc_html( $type_label ); ?></span>
				</a>
				<?php echo self::render_favorite_button( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<div class="umi-listing-card__body">
				<h3 class="umi-listing-card__title"><a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a></h3>
				<?php if ( $excerpt ) : ?>
					<p class="umi-listing-card__excerpt"><?php echo esc_html( wp_strip_all_tags( $excerpt ) ); ?></p>
				<?php endif; ?>
				<div class="umi-listing-card__meta">
					<span class="umi-listing-card__price"><?php echo esc_html( sprintf( /* translators: %s: price */ __( 'от %s ₽', 'umi-marketplace' ), number_format_i18n( $price, 0 ) ) ); ?></span>
					<?php if ( $city ) : ?>
						<span class="umi-listing-card__dot" aria-hidden="true"></span>
						<span class="umi-listing-card__city"><?php echo esc_html( $city ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( $shares_ok ) : ?>
					<p class="umi-listing-card__chips">
						<span class="umi-pill umi-pill--shares"><?php esc_html_e( 'Оплата долями', 'umi-marketplace' ); ?></span>
					</p>
				<?php endif; ?>
				<?php if ( $author_name ) : ?>
					<p class="umi-listing-card__author">
						<?php
						if ( $author_href ) {
							/* translators: 1: "Продавец:" 2: URL 3: author name */
							printf(
								'<span class="umi-listing-card__author-line">%1$s <a class="umi-listing-card__author-link" href="%2$s">%3$s</a></span>',
								esc_html__( 'Продавец:', 'umi-marketplace' ),
								esc_url( $author_href ),
								esc_html( $author_name )
							);
						} else {
							echo esc_html( sprintf( /* translators: %s: author */ __( 'Продавец: %s', 'umi-marketplace' ), $author_name ) );
						}
						?>
					</p>
				<?php endif; ?>
				<div class="umi-listing-card__actions">
					<?php echo do_shortcode( '[umi_contact_seller id="' . (int) $post_id . '" class="umi-btn umi-btn--primary umi-listing-card__btn"]' ); ?>
				</div>
			</div>
		</article>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Крупная карточка на странице объявления: медиа, заголовок, условия, автор, CTA.
	 * На сингле можно вызывать без id: [umi_listing_card]
	 *
	 * @param array $atts Atts.
	 * @return string
	 */
	public static function listing_card( $atts ) {
		$atts = shortcode_atts(
			array(
				'id' => 0,
			),
			$atts,
			'umi_listing_card'
		);
		$id = (int) $atts['id'];
		if ( $id < 1 && is_singular( array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ) ) ) {
			$id = (int) get_queried_object_id();
		}
		if ( $id < 1 ) {
			return '';
		}
		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ), true ) ) {
			return '';
		}
		$type_label   = ( Umi_Cpt::SERVICE === $post->post_type )
			? __( 'Услуга', 'umi-marketplace' )
			: __( 'Товар', 'umi-marketplace' );
		$price        = (float) get_post_meta( $id, '_umi_price', true );
		$city         = (string) get_post_meta( $id, '_umi_city', true );
		$images       = Umi_Meta_Boxes::get_listing_image_ids( $id );
		$shares_ok    = Umi_Meta_Boxes::listing_allows_shares_payment( $id );
		$author       = get_userdata( (int) $post->post_author );
		$author_name  = $author ? $author->display_name : __( 'Продавец', 'umi-marketplace' );
		$level        = self::seller_level_label( (string) get_user_meta( (int) $post->post_author, 'umi_profile_level', true ) );
		$profession   = (string) get_user_meta( (int) $post->post_author, 'umi_profile_profession', true );
		$excerpt      = $post->post_excerpt ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 60, '…' );
		$hero         = self::render_listing_hero_images( $images );
		$author_profile_url = ( self::user_has_public_seller_profile( (int) $post->post_author ) ) ? self::get_seller_profile_url( (int) $post->post_author ) : '';
		$avatar       = get_avatar( (int) $post->post_author, 64, '', '', array( 'class' => 'umi-listing-author__avatar' ) );
		$chat_markup       = self::render_listing_chat_markup( $id );
		$is_listing_owner  = is_user_logged_in() && get_current_user_id() === (int) $post->post_author;
		$chat_section_title = $is_listing_owner
			? __( 'Сообщения по объявлению', 'umi-marketplace' )
			: __( 'Чат с продавцом', 'umi-marketplace' );

		ob_start();
		?>
		<div class="umi-listing-page">
			<div class="umi-listing-card umi-listing-card--single" data-umi-listing-id="<?php echo (int) $id; ?>">
				<div class="umi-listing-card__media-block">
					<?php echo $hero; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="umi-listing-card__content">
					<div class="umi-listing-card__head">
						<div class="umi-listing-card__head-row">
							<span class="umi-listing-card__badge"><?php echo esc_html( $type_label ); ?></span>
							<?php
							$own_h = is_user_logged_in() && (int) get_current_user_id() === (int) $post->post_author;
							if ( ! $own_h ) {
								echo self::render_favorite_button( (int) $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
							?>
						</div>
						<h1 class="umi-listing-card__h1"><?php echo esc_html( get_the_title( $post ) ); ?></h1>
					</div>
					<div class="umi-listing-card__terms" role="group" aria-label="<?php esc_attr_e( 'Условия', 'umi-marketplace' ); ?>">
						<div class="umi-listing-card__term umi-listing-card__term--price">
							<span class="umi-listing-card__term-label"><?php esc_html_e( 'Стоимость', 'umi-marketplace' ); ?></span>
							<span class="umi-listing-card__term-value"><?php echo esc_html( sprintf( /* translators: %s: price */ __( 'от %s ₽', 'umi-marketplace' ), number_format_i18n( $price, 0 ) ) ); ?></span>
						</div>
						<?php if ( $city ) : ?>
						<div class="umi-listing-card__term">
							<span class="umi-listing-card__term-label"><?php esc_html_e( 'Город', 'umi-marketplace' ); ?></span>
							<span class="umi-listing-card__term-value"><?php echo esc_html( $city ); ?></span>
						</div>
						<?php endif; ?>
						<div class="umi-listing-card__term umi-listing-card__term--pay">
							<span class="umi-listing-card__term-label"><?php esc_html_e( 'Оплата', 'umi-marketplace' ); ?></span>
							<span class="umi-listing-card__term-value">
							<?php
							if ( $shares_ok ) {
								esc_html_e( 'доли на сайте и/или согласование рублями', 'umi-marketplace' );
							} else {
								esc_html_e( 'согласование с продавцом (рубли / без долей на сайте)', 'umi-marketplace' );
							}
							?>
							</span>
						</div>
					</div>
					<?php if ( $excerpt ) : ?>
					<div class="umi-listing-card__lead">
						<?php echo wp_kses_post( wpautop( $excerpt ) ); ?>
					</div>
					<?php endif; ?>
					<?php if ( $author_profile_url ) : ?>
					<a class="umi-listing-card__author umi-listing-author umi-listing-author--link" href="<?php echo esc_url( $author_profile_url ); ?>">
						<?php echo $avatar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div class="umi-listing-author__text">
							<p class="umi-listing-author__name"><?php echo esc_html( $author_name ); ?></p>
							<?php
							$sub = array_filter( array( $profession, $level ) );
							if ( count( $sub ) ) {
								?>
								<p class="umi-listing-author__meta"><?php echo esc_html( implode( ' · ', $sub ) ); ?></p>
							<?php } ?>
						</div>
					</a>
					<?php else : ?>
					<div class="umi-listing-card__author umi-listing-author">
						<?php echo $avatar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div class="umi-listing-author__text">
							<p class="umi-listing-author__name"><?php echo esc_html( $author_name ); ?></p>
							<?php
							$sub = array_filter( array( $profession, $level ) );
							if ( count( $sub ) ) {
								?>
								<p class="umi-listing-author__meta"><?php echo esc_html( implode( ' · ', $sub ) ); ?></p>
							<?php } ?>
						</div>
					</div>
					<?php endif; ?>
					<div class="umi-listing-cta">
						<?php echo do_shortcode( '[umi_contact_seller id="' . (int) $id . '" class="umi-btn umi-btn--primary umi-listing-cta__btn"]' ); ?>
					</div>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper
					echo self::render_listing_deal_start( (int) $id );
					?>
					<?php if ( $chat_markup ) : ?>
					<section class="umi-listing-card__chat" aria-label="<?php esc_attr_e( 'Переписка по объявлению', 'umi-marketplace' ); ?>">
						<h2 class="umi-listing-card__chat-h"><?php echo esc_html( $chat_section_title ); ?></h2>
						<?php echo $chat_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</section>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Список сделок / карточка сделки (GET umi_deal).
	 *
	 * @param array $atts Atts.
	 * @return string
	 */
	public static function deals( $atts ) {
		unset( $atts );
		$return = ( is_singular() && get_queried_object_id() ) ? get_permalink( get_queried_object_id() ) : home_url( '/' );
		return self::render_deals_inner( $return );
	}

	/**
	 * Страница «Избранное»: опубликованные объявления из списка пользователя.
	 *
	 * @param array $atts Atts.
	 * @return string
	 */
	public static function favorites( $atts ) {
		unset( $atts );
		if ( ! is_user_logged_in() ) {
			$here = ( is_singular() && get_queried_object_id() ) ? get_permalink( get_queried_object_id() ) : home_url( '/' );
			return '<div class="umi-favorites umi-page-root"><p class="umi-notice">' . esc_html__( 'Войдите, чтобы видеть избранное.', 'umi-marketplace' ) . ' <a class="umi-link" href="' . esc_url( wp_login_url( $here ) ) . '">' . esc_html__( 'Войти', 'umi-marketplace' ) . '</a></p></div>';
		}
		$uid = get_current_user_id();
		$ids = Umi_Favorites::get_ids( $uid );
		$ids = array_values(
			array_filter(
				$ids,
				static function ( $id ) {
					$p = get_post( (int) $id );
					return $p
						&& 'publish' === $p->post_status
						&& in_array( $p->post_type, array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ), true );
				}
			)
		);
		if ( ! count( $ids ) ) {
			return '<div class="umi-favorites umi-page-root umi-catalog"><p class="umi-empty">' . esc_html__( 'В избранном пока пусто. Добавьте товары и услуги с помощью кнопки с сердечком в карточке.', 'umi-marketplace' ) . '</p></div>';
		}
		$q = new WP_Query(
			array(
				'post_type'      => array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ),
				'post_status'    => 'publish',
				'post__in'       => $ids,
				'orderby'        => 'post__in',
				'posts_per_page' => -1,
			)
		);
		ob_start();
		?>
		<div class="umi-favorites umi-catalog umi-page-root">
			<div class="umi-grid">
				<?php
				if ( $q->have_posts() ) :
					while ( $q->have_posts() ) :
						$q->the_post();
						echo self::render_catalog_card_article( (int) get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					endwhile;
				else :
					echo '<p class="umi-empty">' . esc_html__( 'В избранном пока пусто.', 'umi-marketplace' ) . '</p>';
				endif;
				?>
			</div>
		</div>
		<?php
		wp_reset_postdata();
		return (string) ob_get_clean();
	}

	/**
	 * @param string $return_url Return URL for forms.
	 * @return string
	 */
	private static function render_deals_inner( $return_url ) {
		$return_url = esc_url( $return_url );
		if ( ! is_user_logged_in() ) {
			$cab = (string) apply_filters( 'umi_header_url_cabinet', home_url( '/kabinet/' ) );
			return '<div class="umi-deals" id="umi-deals"><p class="umi-notice">' . esc_html__( 'Войдите, чтобы видеть сделки.', 'umi-marketplace' ) . ' <a class="umi-link" href="' . esc_url( wp_login_url( $return_url ) ) . '">' . esc_html__( 'Войти', 'umi-marketplace' ) . '</a></p></div>';
		}
		$uid       = get_current_user_id();
		$out       = '<div class="umi-deals" id="umi-deals">';
		$flash_key = 'umi_deal_flash_' . $uid;
		$flash     = get_transient( $flash_key );
		if ( false !== $flash && is_array( $flash ) ) {
			delete_transient( $flash_key );
			if ( ! empty( $flash['error'] ) ) {
				$out .= '<p class="umi-error" role="alert">' . esc_html( (string) $flash['error'] ) . '</p>';
			}
			if ( ! empty( $flash['success'] ) ) {
				$out .= '<p class="umi-success" role="status">' . esc_html( (string) $flash['success'] ) . '</p>';
			}
		}
		$deal_view = isset( $_GET['umi_deal'] ) ? (int) $_GET['umi_deal'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $deal_view > 0 && Umi_Deals::user_can_access_deal( $deal_view, $uid ) ) {
			$out .= self::render_deal_single( $deal_view, $return_url );
		} else {
			$out .= self::render_deal_list( $uid, $return_url );
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 * @param int    $uid User.
	 * @param string $return_url Return.
	 * @return string
	 */
	private static function render_deal_list( $uid, $return_url ) {
		$uid = (int) $uid;
		$out = '<section class="umi-deals__section" aria-label="' . esc_attr__( 'Сделки', 'umi-marketplace' ) . '">';
		$out .= '<h2 class="umi-cabinet-heading">' . esc_html__( 'Сделки', 'umi-marketplace' ) . '</h2>';
		$list = Umi_Deals::deals_for_user( $uid, 50 );
		if ( ! count( $list ) ) {
			$out .= '<p class="umi-cabinet-empty">' . esc_html__( 'Пока нет сделок. На странице объявления нажмите «Начать сделку».', 'umi-marketplace' ) . '</p>';
			$out .= '</section>';
			return $out;
		}
		$labels = Umi_Deals::status_labels();
		$out   .= '<ul class="umi-deals__list">';
		foreach ( $list as $p ) {
			$pid    = (int) $p->ID;
			$st     = Umi_Deals::get_status( $pid );
			$st_lbl = isset( $labels[ $st ] ) ? $labels[ $st ] : $st;
			$lid    = Umi_Deals::get_listing_id( $pid );
			$lt     = $lid ? get_the_title( $lid ) : (string) $p->post_title;
			$href   = add_query_arg( 'umi_deal', $pid, $return_url ) . '#umi-deals';
			$out   .= '<li class="umi-deals__item"><a class="umi-deals__link" href="' . esc_url( $href ) . '"><span class="umi-deals__item-title">' . esc_html( $lt ) . '</span>';
			$out   .= ' <span class="umi-deals__item-st">' . esc_html( $st_lbl ) . '</span></a></li>';
		}
		$out .= '</ul></section>';
		return $out;
	}

	/**
	 * @param int    $deal_id Deal.
	 * @param string $return_url Base.
	 * @return string
	 */
	private static function render_deal_single( $deal_id, $return_url ) {
		$deal_id   = (int) $deal_id;
		$uid       = get_current_user_id();
		$is_admin  = $uid > 0 && current_user_can( 'manage_options' );
		$status    = Umi_Deals::get_status( $deal_id );
		$buyer_id  = Umi_Deals::get_buyer_id( $deal_id );
		$seller_id = Umi_Deals::get_seller_id( $deal_id );
		$lid       = Umi_Deals::get_listing_id( $deal_id );
		$is_buyer  = ( $uid === $buyer_id );
		$is_seller = ( $uid === $seller_id );
		$labels    = Umi_Deals::status_labels();
		$st_lbl    = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
		$rub       = Umi_Deals::get_amount_rub( $deal_id );
		$sh        = Umi_Deals::get_amount_shares( $deal_id );
		$shares_ok = Umi_Deals::shares_payment_possible( $deal_id );
		$listing   = $lid ? get_permalink( $lid ) : '';
		$back      = remove_query_arg( 'umi_deal', $return_url ) . '#umi-deals';
		$other_id  = $is_buyer ? $seller_id : $buyer_id;
		$other     = get_userdata( $other_id );
		$other_n   = $other ? $other->display_name : '';

		ob_start();
		?>
		<section class="umi-deals__section umi-deals__single" aria-label="<?php esc_attr_e( 'Сделка', 'umi-marketplace' ); ?>">
			<p class="umi-deals__back"><a class="umi-link" href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( '← Ко всем сделкам', 'umi-marketplace' ); ?></a></p>
			<h2 class="umi-cabinet-heading"><?php echo esc_html( sprintf( /* translators: %d */ __( 'Сделка #%d', 'umi-marketplace' ), (int) $deal_id ) ); ?></h2>
			<div class="umi-deals__meta" role="group" aria-label="<?php esc_attr_e( 'Параметры', 'umi-marketplace' ); ?>">
				<p><strong><?php esc_html_e( 'Статус', 'umi-marketplace' ); ?>:</strong> <?php echo esc_html( $st_lbl ); ?></p>
				<?php if ( $listing ) : ?>
					<p><strong><?php esc_html_e( 'Объявление', 'umi-marketplace' ); ?>:</strong> <a class="umi-link" href="<?php echo esc_url( $listing ); ?>"><?php echo esc_html( get_the_title( $lid ) ); ?></a></p>
				<?php endif; ?>
				<?php
				$by_u = get_userdata( $buyer_id );
				$se_u = get_userdata( $seller_id );
				$by_n = $by_u ? $by_u->display_name : (string) $buyer_id;
				$se_n = $se_u ? $se_u->display_name : (string) $seller_id;
				if ( $is_admin ) :
					?>
					<p><strong><?php esc_html_e( 'Покупатель', 'umi-marketplace' ); ?>:</strong> <?php echo esc_html( $by_n ? $by_n : '#' . (int) $buyer_id ); ?></p>
					<p><strong><?php esc_html_e( 'Продавец', 'umi-marketplace' ); ?>:</strong> <?php echo esc_html( $se_n ? $se_n : '#' . (int) $seller_id ); ?></p>
				<?php else : ?>
					<p><strong><?php echo $is_buyer ? esc_html__( 'Продавец', 'umi-marketplace' ) : esc_html__( 'Покупатель', 'umi-marketplace' ); ?>:</strong> <?php echo esc_html( $other_n ? $other_n : '#' . (int) $other_id ); ?></p>
				<?php endif; ?>
				<p><strong><?php esc_html_e( 'Сумма в объявлении', 'umi-marketplace' ); ?>:</strong> <?php echo esc_html( number_format_i18n( (int) round( (float) $rub ), 0 ) ); ?> ₽</p>
				<?php if ( $shares_ok && (int) $sh > 0 ) : ?>
					<p><strong><?php esc_html_e( 'Эквивалент в долях (по курсу пополнения)', 'umi-marketplace' ); ?>:</strong> <?php echo esc_html( number_format_i18n( (int) $sh, 0 ) ); ?></p>
					<?php
					$rate = (float) Umi_Settings::get_var( 'deposit_rub_per_share', 100 );
					if ( $rate > 0 ) {
						/* translators: %s: rate */
						echo '<p class="umi-deals__hint">' . esc_html( sprintf( __( 'Курс: %s ₽ за 1 долю (настройка администратора).', 'umi-marketplace' ), number_format_i18n( (int) round( $rate ), 0 ) ) ) . '</p>';
					}
					?>
				<?php endif; ?>
			</div>
		<?php
		// Действия.
		$btns = 0;
		if ( $is_buyer && Umi_Deals::STATUS_NEGOTIATED === $status && $shares_ok && (int) $sh > 0 ) {
			$btns += (int) self::echo_deal_action_form( $return_url, $deal_id, 'to_waiting', __( 'Перейти к ожиданию долей', 'umi-marketplace' ), 'umi-btn umi-btn--secondary' );
			$btns += (int) self::echo_deal_action_form( $return_url, $deal_id, 'pay_shares', __( 'Оплатить долями', 'umi-marketplace' ), 'umi-btn umi-btn--primary' );
		}
		if ( $is_buyer && Umi_Deals::STATUS_WAIT_SHARES === $status && $shares_ok && (int) $sh > 0 ) {
			$btns += (int) self::echo_deal_action_form( $return_url, $deal_id, 'pay_shares', __( 'Оплатить долями', 'umi-marketplace' ), 'umi-btn umi-btn--primary' );
		}
		if ( $is_seller && Umi_Deals::STATUS_NEGOTIATED === $status ) {
			$btns += (int) self::echo_deal_action_form( $return_url, $deal_id, 'mark_paid_rub', __( 'Отметить оплату рублями (вне сайта)', 'umi-marketplace' ), 'umi-btn umi-btn--secondary' );
		}
		if ( $is_seller && in_array( $status, array( Umi_Deals::STATUS_PAID_SHARES, Umi_Deals::STATUS_PAID_RUB ), true ) ) {
			$btns += (int) self::echo_deal_action_form( $return_url, $deal_id, 'complete', __( 'Завершить сделку', 'umi-marketplace' ), 'umi-btn umi-btn--primary' );
		}
		if ( in_array( $status, array( Umi_Deals::STATUS_NEGOTIATED, Umi_Deals::STATUS_PAID_SHARES, Umi_Deals::STATUS_PAID_RUB ), true ) && ( $is_buyer || $is_seller ) ) {
			$btns += (int) self::echo_deal_action_form( $return_url, $deal_id, 'open_dispute', __( 'Открыть спор', 'umi-marketplace' ), 'umi-link umi-deals__dispute' );
		}
		if ( Umi_Deals::STATUS_DISPUTE === $status && ( $is_buyer || $is_seller || $is_admin ) ) {
			$dthread = Umi_Chat::get_dispute_thread_id( $deal_id );
			if ( $dthread > 0 ) {
				echo '<div class="umi-deals__dispute-chat" role="region" aria-label="' . esc_attr__( 'Чат спора', 'umi-marketplace' ) . '">';
				echo '<h3 class="umi-cabinet-subh">' . esc_html__( 'Чат спора', 'umi-marketplace' ) . '</h3>';
				echo '<p class="umi-deals__dispute-hint umi-text-muted">' . esc_html__( 'Переписка с контрагентом и администратором. К сообщению можно приложить изображение до 2 МБ.', 'umi-marketplace' ) . '</p>';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::render_dispute_chat_box( $dthread );
				echo '</div>';
			}
			$opened_by = (int) get_post_meta( $deal_id, '_umi_dispute_opened_by', true );
			if ( $is_admin ) {
				$btns += (int) self::echo_deal_action_form( $return_url, $deal_id, 'close_dispute', __( 'Закрыть спор (завершить сделку)', 'umi-marketplace' ), 'umi-btn umi-btn--primary' );
			} elseif ( $opened_by > 0 && (int) $uid === $opened_by ) {
				$btns += (int) self::echo_deal_action_form( $return_url, $deal_id, 'close_dispute', __( 'Закрыть спор (вернуть прежний статус)', 'umi-marketplace' ), 'umi-btn umi-btn--secondary' );
			}
		}
		if ( 0 === $btns && Umi_Deals::STATUS_DISPUTE === $status && ! $is_admin && $uid !== (int) get_post_meta( $deal_id, '_umi_dispute_opened_by', true ) ) {
			echo '<p class="umi-notice">' . esc_html__( 'Статус «Спор» — пишите в чате спора; инициатор или администратор могут закрыть спор.', 'umi-marketplace' ) . '</p>';
		}
		// Отзыв после завершения.
		if ( Umi_Deals::STATUS_COMPLETED === $status && ( $is_buyer || $is_seller ) ) {
			$to = $is_buyer ? $seller_id : $buyer_id;
			if ( ! Umi_Reviews::exists( $deal_id, $uid, $to ) ) {
				?>
				<div class="umi-deals__review" role="form" aria-label="<?php esc_attr_e( 'Отзыв', 'umi-marketplace' ); ?>">
					<h3 class="umi-cabinet-subh"><?php esc_html_e( 'Оставить отзыв', 'umi-marketplace' ); ?></h3>
					<form method="post" class="umi-form" action="<?php echo esc_url( $return_url ); ?>#umi-deals">
						<?php wp_nonce_field( 'umi_deal_review', 'umi_deal_review_nonce' ); ?>
						<input type="hidden" name="umi_deal_form" value="1" />
						<input type="hidden" name="umi_deal_sub" value="review" />
						<input type="hidden" name="umi_review_deal_id" value="<?php echo (int) $deal_id; ?>" />
						<input type="hidden" name="umi_review_to" value="<?php echo (int) $to; ?>" />
						<?php wp_nonce_field( 'umi_deal_action', 'umi_deal_nonce' ); ?>
						<input type="hidden" name="umi_deal_return" value="<?php echo esc_url( $return_url ); ?>" />
						<p class="umi-form-row">
							<label class="umi-label" for="<?php echo esc_attr( 'umi-rating-' . (int) $deal_id ); ?>"><?php esc_html_e( 'Оценка', 'umi-marketplace' ); ?></label>
							<select class="umi-input" name="umi_review_rating" id="<?php echo esc_attr( 'umi-rating-' . (int) $deal_id ); ?>">
								<?php for ( $g = 5; $g >= 1; $g-- ) : ?>
									<option value="<?php echo (int) $g; ?>"><?php echo (int) $g; ?></option>
								<?php endfor; ?>
							</select>
						</p>
						<p class="umi-form-row">
							<label class="umi-label" for="<?php echo esc_attr( 'umi-rev-c-' . (int) $deal_id ); ?>"><?php esc_html_e( 'Текст', 'umi-marketplace' ); ?></label>
							<textarea class="umi-input" name="umi_review_content" id="<?php echo esc_attr( 'umi-rev-c-' . (int) $deal_id ); ?>" rows="4" required></textarea>
						</p>
						<button type="submit" class="umi-btn umi-btn--primary"><?php esc_html_e( 'Отправить отзыв', 'umi-marketplace' ); ?></button>
					</form>
				</div>
				<?php
			} else {
				echo '<p class="umi-success">' . esc_html__( 'Вы оставили отзыв по этой сделке.', 'umi-marketplace' ) . '</p>';
			}
		}
		?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param string     $return_url Return.
	 * @param int        $deal_id ID.
	 * @param string     $action_name Action slug for Umi_Deals::do_action.
	 * @param string     $label Button label.
	 * @param string     $class Button classes.
	 * @return int 1 (echoed form).
	 */
	private static function echo_deal_action_form( $return_url, $deal_id, $action_name, $label, $class = 'umi-btn' ) {
		?>
		<form method="post" class="umi-deals__action" action="<?php echo esc_url( $return_url ); ?>#umi-deals">
			<?php wp_nonce_field( 'umi_deal_action', 'umi_deal_nonce' ); ?>
			<input type="hidden" name="umi_deal_form" value="1" />
			<input type="hidden" name="umi_deal_sub" value="apply" />
			<input type="hidden" name="umi_deal_id" value="<?php echo (int) $deal_id; ?>" />
			<input type="hidden" name="umi_deal_action_name" value="<?php echo esc_attr( $action_name ); ?>" />
			<input type="hidden" name="umi_deal_return" value="<?php echo esc_url( $return_url ); ?>" />
			<p><button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button></p>
		</form>
		<?php
		return 1;
	}

	/**
	 * CTA на странице объявления: начать сделку.
	 *
	 * @param int $listing_id Listing.
	 * @return string
	 */
	private static function render_listing_deal_start( $listing_id ) {
		$listing_id = (int) $listing_id;
		$post       = get_post( $listing_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return '';
		}
		$cab  = (string) apply_filters( 'umi_header_url_cabinet', home_url( '/kabinet/' ) );
		$ret  = is_singular() && get_queried_object_id() ? get_permalink( (int) get_queried_object_id() ) : get_permalink( $listing_id );
		$ret  = $ret ? $ret : home_url( '/' );
		$uid  = get_current_user_id();
		$own  = $uid > 0 && (int) $post->post_author === $uid;
		if ( $own ) {
			return '';
		}
		ob_start();
		echo '<div class="umi-deal-cta" role="region" aria-label="' . esc_attr__( 'Сделка', 'umi-marketplace' ) . '">';
		if ( ! is_user_logged_in() ) {
			echo '<p class="umi-deal-cta__hint umi-notice">' . esc_html__( 'Войдите, чтобы оформить сделку по этому объявлению.', 'umi-marketplace' ) . ' <a class="umi-link" href="' . esc_url( wp_login_url( $ret ) ) . '">' . esc_html__( 'Войти', 'umi-marketplace' ) . '</a> · <a class="umi-link" href="' . esc_url( $cab ) . '#umi-deals">' . esc_html__( 'К сделкам', 'umi-marketplace' ) . '</a></p>';
			echo '</div>';
			return (string) ob_get_clean();
		}
		$active = Umi_Deals::find_active_deal( $listing_id, $uid, (int) $post->post_author );
		if ( $active > 0 ) {
			$href = add_query_arg( 'umi_deal', (int) $active, $cab ) . '#umi-deals';
			echo '<p class="umi-deal-cta__hint umi-notice"><a class="umi-link" href="' . esc_url( $href ) . '">' . esc_html__( 'Перейти к текущей сделке', 'umi-marketplace' ) . ' →</a></p>';
			echo '</div>';
			return (string) ob_get_clean();
		}
		?>
		<form method="post" class="umi-deal-cta__form" action="<?php echo esc_url( $ret ); ?>#umi-deals">
			<?php wp_nonce_field( 'umi_deal_action', 'umi_deal_nonce' ); ?>
			<input type="hidden" name="umi_deal_form" value="1" />
			<input type="hidden" name="umi_deal_sub" value="create" />
			<input type="hidden" name="umi_listing_id" value="<?php echo (int) $listing_id; ?>" />
			<input type="hidden" name="umi_deal_return" value="<?php echo esc_url( $cab ); ?>" />
			<button type="submit" class="umi-btn umi-btn--secondary umi-deal-cta__btn"><?php esc_html_e( 'Начать сделку', 'umi-marketplace' ); ?></button>
		</form>
		<div class="umi-listing-card__admin-alert">
			<button
				class="umi-btn umi-btn--outline umi-alert-admin-btn"
				data-listing-id="<?php echo (int) $listing_id; ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( Umi_Ajax::NONCE_ALERT ) ); ?>"
				data-ajax-url="<?php echo esc_attr( admin_url( 'admin-ajax.php' ) ); ?>"
			><?php esc_html_e( 'Привлечь администратора', 'umi-marketplace' ); ?></button>
			<span class="umi-alert-admin-msg" aria-live="polite"></span>
			<span class="umi-alert-admin-fee"><?php esc_html_e( 'Платная услуга — 5% от суммы сделки', 'umi-marketplace' ); ?></span>
		</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Button + link to chat page or inline anchor.
	 *
	 * @param array $atts Atts.
	 * @return string
	 */
	public static function contact_seller( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'href'  => '',
				'class' => 'umi-btn',
			),
			$atts,
			'umi_contact_seller'
		);
		$id = (int) $atts['id'];
		if ( ! $id ) {
			return '';
		}
		$btn_class = trim( (string) $atts['class'] );
		if ( '' === $btn_class ) {
			$btn_class = 'umi-btn';
		}
		if ( ! is_user_logged_in() ) {
			$ghost = trim( 'umi-btn umi-btn-ghost ' . str_replace( 'umi-btn--primary', '', $btn_class ) );
			$ghost = preg_replace( '/\s+/', ' ', $ghost );
			return '<a class="' . esc_attr( $ghost ) . '" href="' . esc_url( wp_login_url( get_permalink( $id ) ) ) . '">' . esc_html__( 'Войти и написать продавцу', 'umi-marketplace' ) . '</a>';
		}
		$href = $atts['href'] ? $atts['href'] : ( get_permalink( $id ) . '#umi-chat' );
		return '<a class="' . esc_attr( $btn_class ) . '" href="' . esc_url( $href ) . '">' . esc_html__( 'Написать продавцу', 'umi-marketplace' ) . '</a>';
	}

	/**
	 * Панель шапки: доли, сообщения, вход/регистрация или выход.
	 *
	 * @return string
	 */
	public static function header_toolbar() {
		$login = (string) apply_filters( 'umi_header_url_login', home_url( '/vhod/' ) );
		$cab   = (string) apply_filters( 'umi_header_url_cabinet', home_url( '/kabinet/' ) );

		$out  = '<div class="umi-header__toolbar" data-umi-header-toolbar>';
		$out .= '<div class="umi-header__toolbar-row">';
		$fav_url = (string) apply_filters( 'umi_header_url_favorites', home_url( '/izbrannoe/' ) );
		if ( is_user_logged_in() ) {
			$out .= '<div class="umi-header__tools">';
			$out .= '<div class="umi-header__balance-wrap">' . do_shortcode( '[umi_balance]' ) . '</div>';
			$out .= '<a class="umi-header__fav" href="' . esc_url( $fav_url ) . '" title="' . esc_attr__( 'Избранное', 'umi-marketplace' ) . '">';
			$out .= '<span class="screen-reader-text">' . esc_html__( 'Избранное', 'umi-marketplace' ) . '</span>';
			$out .= '<span class="umi-header__fav-icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="2" focusable="false"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></span></a>';
			$out .= '<button class="umi-header__share" type="button" data-umi-share data-umi-share-url="' . esc_url( home_url( '/' ) ) . '" title="' . esc_attr__( 'Поделиться', 'umi-marketplace' ) . '" aria-label="' . esc_attr__( 'Поделиться сайтом', 'umi-marketplace' ) . '">';
			$out .= '<span class="umi-header__share-icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg></span>';
			$out .= '</button>';
			$out .= '<a class="umi-header__msg" href="' . esc_url( $cab ) . '" title="' . esc_attr__( 'Личный кабинет', 'umi-marketplace' ) . '">';
			$out .= '<span class="screen-reader-text">' . esc_html__( 'Личный кабинет', 'umi-marketplace' ) . '</span>';
			$out .= '<span class="umi-header__msg-icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg></span>';
			$out .= do_shortcode( '[umi_unread_badge]' );
			$out .= '</a>';
			$out .= '</div>';
			$out .= '<a class="umi-header__link umi-header__link--ghost" href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Выход', 'umi-marketplace' ) . '</a>';
		} else {
			$out .= '<a class="umi-header__fav umi-header__fav--guest" href="' . esc_url( $fav_url ) . '" title="' . esc_attr__( 'Избранное', 'umi-marketplace' ) . '">';
			$out .= '<span class="screen-reader-text">' . esc_html__( 'Избранное', 'umi-marketplace' ) . '</span>';
			$out .= '<span class="umi-header__fav-icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="2" focusable="false"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></span></a> ';
			$out .= '<a class="umi-btn umi-btn--outline" href="' . esc_url( $login ) . '">' . esc_html__( 'Вход', 'umi-marketplace' ) . '</a>';
		}
		$out .= '</div></div>';
		return $out;
	}
}
