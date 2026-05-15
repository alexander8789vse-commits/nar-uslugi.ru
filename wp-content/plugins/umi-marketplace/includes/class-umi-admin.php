<?php
/**
 * Admin UI: настройки, журнал, уведомления, поля пользователя.
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Admin
 */
class Umi_Admin {

	/**
	 * Hooks.
	 */
	public static function hooks() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_init', array( 'Umi_Roles', 'ensure_admin_marketplace_cap' ) );

		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'notify_pending' ), 10, 3 );
		add_action( 'show_user_profile', array( __CLASS__, 'user_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'user_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_user_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_fields' ) );
		add_action( 'admin_notices', array( __CLASS__, 'limit_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'profile_ledger_notice' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'users_columns' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'users_column_content' ), 10, 3 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_deal_meta' ) );
		add_action( 'save_post_umi_deal', array( __CLASS__, 'save_deal_meta' ), 10, 2 );
		add_filter( 'manage_' . Umi_Cpt::DEAL . '_posts_columns', array( __CLASS__, 'deal_list_columns' ) );
		add_action( 'manage_' . Umi_Cpt::DEAL . '_posts_custom_column', array( __CLASS__, 'deal_list_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'deal_list_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'deal_list_filter_query' ) );
	}

	/**
	 * Колонка «Статус» в списке сделок.
	 *
	 * @param string[] $cols Columns.
	 * @return string[]
	 */
	public static function deal_list_columns( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['umi_deal_status'] = __( 'Статус сделки', 'umi-marketplace' );
			}
		}
		if ( ! isset( $new['umi_deal_status'] ) ) {
			$new['umi_deal_status'] = __( 'Статус сделки', 'umi-marketplace' );
		}
		return $new;
	}

	/**
	 * @param string $col Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function deal_list_column( $col, $post_id ) {
		if ( 'umi_deal_status' !== $col ) {
			return;
		}
		$st  = Umi_Deals::get_status( (int) $post_id );
		$lab = Umi_Deals::status_labels();
		$out = isset( $lab[ $st ] ) ? $lab[ $st ] : $st;
		if ( ! $st ) {
			$out = '—';
		}
		echo '<span class="umi-deal-list-status" data-status="' . esc_attr( $st ) . '">' . esc_html( $out ) . '</span>';
	}

	/**
	 * Выпадающий фильтр по статусу.
	 */
	public static function deal_list_filter() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Umi_Cpt::DEAL !== $screen->post_type ) {
			return;
		}
		$cur = isset( $_GET['umi_deal_status'] ) ? sanitize_key( wp_unslash( $_GET['umi_deal_status'] ) ) : '';
		echo '<label class="screen-reader-text" for="umi_deal_status">' . esc_html__( 'Статус сделки', 'umi-marketplace' ) . '</label>';
		echo '<select name="umi_deal_status" id="umi_deal_status">';
		echo '<option value="">' . esc_html__( 'Все статусы', 'umi-marketplace' ) . '</option>';
		$labels = Umi_Deals::status_labels();
		foreach ( Umi_Deals::statuses() as $st ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $st ),
				selected( $cur, $st, false ),
				esc_html( isset( $labels[ $st ] ) ? $labels[ $st ] : $st )
			);
		}
		echo '</select>';
	}

	/**
	 * @param WP_Query $q Query.
	 */
	public static function deal_list_filter_query( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() ) {
			return;
		}
		if ( Umi_Cpt::DEAL !== $q->get( 'post_type' ) ) {
			return;
		}
		$st = isset( $_GET['umi_deal_status'] ) ? sanitize_key( wp_unslash( $_GET['umi_deal_status'] ) ) : '';
		if ( '' === $st || ! in_array( $st, Umi_Deals::statuses(), true ) ) {
			return;
		}
		$q->set(
			'meta_query',
			array(
				array(
					'key'   => '_umi_status',
					'value' => $st,
				),
			)
		);
	}

	/**
	 * Метабоксы сделки в админке.
	 */
	public static function add_deal_meta() {
		add_meta_box(
			'umi_deal_status',
			__( 'Статус сделки', 'umi-marketplace' ),
			array( __CLASS__, 'render_deal_meta' ),
			Umi_Cpt::DEAL,
			'side',
			'high'
		);
		add_meta_box(
			'umi_deal_parties',
			__( 'Участники и объявление', 'umi-marketplace' ),
			array( __CLASS__, 'render_deal_parties' ),
			Umi_Cpt::DEAL,
			'normal',
			'high'
		);
		add_meta_box(
			'umi_deal_dispute',
			__( 'Спор', 'umi-marketplace' ),
			array( __CLASS__, 'render_deal_dispute' ),
			Umi_Cpt::DEAL,
			'normal',
			'default'
		);
		add_meta_box(
			'umi_deal_chats',
			__( 'Переписка (покупатель — продавец и спор)', 'umi-marketplace' ),
			array( __CLASS__, 'render_deal_chats' ),
			Umi_Cpt::DEAL,
			'normal',
			'default'
		);
	}

	/**
	 * @param WP_Post $post Post.
	 */
	public static function render_deal_meta( $post ) {
		wp_nonce_field( 'umi_deal_status_save', 'umi_deal_status_nonce' );
		$st  = Umi_Deals::get_status( (int) $post->ID );
		$lab = Umi_Deals::status_labels();
		echo '<p><label for="umi_deal_status_sel">' . esc_html__( 'Статус', 'umi-marketplace' ) . '</label> ';
		echo '<select name="umi_deal_status" id="umi_deal_status_sel">';
		foreach ( Umi_Deals::statuses() as $k ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $k ),
				selected( $st, $k, false ),
				esc_html( isset( $lab[ $k ] ) ? $lab[ $k ] : $k )
			);
		}
		echo '</select></p>';
		$rub  = Umi_Deals::get_amount_rub( (int) $post->ID );
		$sh   = Umi_Deals::get_amount_shares( (int) $post->ID );
		$pt   = Umi_Deals::get_payment_type( (int) $post->ID );
		echo '<p class="description">' . esc_html__( 'Стоимость в сделке, ₽', 'umi-marketplace' ) . ': ' . esc_html( (string) $rub ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Сумма в долях (на момент сделки)', 'umi-marketplace' ) . ': ' . esc_html( $sh ) . '</p>';
		if ( $pt ) {
			echo '<p class="description">' . esc_html__( 'Тип оплаты', 'umi-marketplace' ) . ': ' . esc_html( $pt === Umi_Deals::PAY_SHARE ? __( 'доли', 'umi-marketplace' ) : __( 'рубли (вне сайта)', 'umi-marketplace' ) ) . '</p>';
		}
	}

	/**
	 * Покупатель, продавец, объявление.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_deal_parties( $post ) {
		$pid  = (int) $post->ID;
		$buy  = Umi_Deals::get_buyer_id( $pid );
		$sell = Umi_Deals::get_seller_id( $pid );
		$lid  = Umi_Deals::get_listing_id( $pid );

		echo '<div class="umi-deal-parties">';
		self::echo_user_party_block( $buy, __( 'Покупатель', 'umi-marketplace' ) );
		self::echo_user_party_block( $sell, __( 'Продавец', 'umi-marketplace' ) );
		echo '</div>';

		if ( $lid > 0 ) {
			$lp = get_post( $lid );
			if ( $lp && in_array( $lp->post_type, array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ), true ) ) {
				$is_svc = Umi_Cpt::SERVICE === $lp->post_type;
				$type_l = $is_svc ? __( 'Услуга', 'umi-marketplace' ) : __( 'Товар', 'umi-marketplace' );
				$price  = (float) get_post_meta( $lid, '_umi_price', true );
				echo '<h4>' . esc_html__( 'Объявление в сделке', 'umi-marketplace' ) . '</h4>';
				echo '<p><strong>' . esc_html( $type_l ) . ':</strong> ';
				echo '<a href="' . esc_url( get_permalink( $lid ) ) . '">' . esc_html( get_the_title( $lid ) ) . '</a> ';
				echo '(<a href="' . esc_url( get_edit_post_link( $lid ) ) . '">' . esc_html__( 'редактировать', 'umi-marketplace' ) . '</a>)</p>';
				if ( $price > 0 ) {
					echo '<p class="description">' . esc_html__( 'Цена в объявлении, ₽', 'umi-marketplace' ) . ': ' . esc_html( (string) $price ) . '</p>';
				}
			} else {
				echo '<p class="description">' . esc_html__( 'Объявление не найдено или снято.', 'umi-marketplace' ) . '</p>';
			}
		} else {
			echo '<p class="description">' . esc_html__( 'Объявление не привязано к сделке.', 'umi-marketplace' ) . '</p>';
		}
	}

	/**
	 * @param int    $user_id User.
	 * @param string $label Label.
	 */
	private static function echo_user_party_block( $user_id, $label ) {
		$uid = (int) $user_id;
		echo '<div class="umi-deal-party">';
		echo '<h4 class="umi-deal-party__title" style="margin:0 0 8px;">' . esc_html( $label ) . '</h4>';
		if ( $uid < 1 ) {
			echo '<p>—</p></div>';
			return;
		}
		$u = get_userdata( $uid );
		if ( ! $u ) {
			echo '<p>—</p></div>';
			return;
		}
		$phone  = (string) get_user_meta( $uid, 'umi_phone', true );
		$city   = (string) get_user_meta( $uid, 'umi_profile_city', true );
		$prof   = (string) get_user_meta( $uid, 'umi_profile_profession', true );
		$level  = (string) get_user_meta( $uid, 'umi_profile_level', true );
		$roles  = is_array( $u->roles ) ? implode( ', ', $u->roles ) : '';
		echo '<p><span class="description">ID</span> ' . (int) $uid . '</p>';
		echo '<p><strong>' . esc_html( $u->display_name ) . '</strong> <span class="description">(' . esc_html( $u->user_login ) . ')</span></p>';
		echo '<p><span class="description">' . esc_html__( 'Email', 'umi-marketplace' ) . '</span> ';
		echo '<a href="mailto:' . esc_attr( $u->user_email ) . '">' . esc_html( $u->user_email ) . '</a></p>';
		if ( $phone ) {
			echo '<p><span class="description">' . esc_html__( 'Телефон', 'umi-marketplace' ) . '</span> ' . esc_html( $phone ) . '</p>';
		}
		if ( $city ) {
			echo '<p><span class="description">' . esc_html__( 'Город', 'umi-marketplace' ) . '</span> ' . esc_html( $city ) . '</p>';
		}
		if ( $prof ) {
			echo '<p><span class="description">' . esc_html__( 'Профессия', 'umi-marketplace' ) . '</span> ' . esc_html( $prof ) . '</p>';
		}
		if ( $level ) {
			echo '<p><span class="description">' . esc_html__( 'Уровень', 'umi-marketplace' ) . '</span> ' . esc_html( $level ) . '</p>';
		}
		if ( $roles ) {
			echo '<p><span class="description">' . esc_html__( 'Роли', 'umi-marketplace' ) . '</span> ' . esc_html( $roles ) . '</p>';
		}
		$eulink = get_edit_user_link( $uid );
		if ( $eulink ) {
			echo '<p><a href="' . esc_url( $eulink ) . '">' . esc_html__( 'Профиль в админке', 'umi-marketplace' ) . '</a></p>';
		}
		echo '</div>';
	}

	/**
	 * Сводка по спору.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_deal_dispute( $post ) {
		$pid    = (int) $post->ID;
		$status = Umi_Deals::get_status( $pid );
		$labels = Umi_Deals::status_labels();
		$opener = (int) get_post_meta( $pid, '_umi_dispute_opened_by', true );
		$before = (string) get_post_meta( $pid, '_umi_status_before_dispute', true );
		$before_l = ( $before && isset( $labels[ $before ] ) ) ? $labels[ $before ] : $before;

		if ( Umi_Deals::STATUS_DISPUTE === $status ) {
			echo '<p><strong>' . esc_html__( 'Сейчас: открыт спор', 'umi-marketplace' ) . '</strong></p>';
		} else {
			echo '<p>' . esc_html__( 'Сейчас спор не открыт.', 'umi-marketplace' );
			if ( $opener > 0 && $before_l ) {
				echo ' ' . esc_html__( 'Ранее спор мог открываться; метаданные спора сброшены при закрытии.', 'umi-marketplace' );
			}
			echo '</p>';
		}
		if ( $opener > 0 ) {
			$ou = get_userdata( $opener );
			$on = $ou ? (string) $ou->display_name : '#' . (int) $opener;
			echo '<p>' . esc_html__( 'Инициатор спора', 'umi-marketplace' ) . ': ' . esc_html( $on ) . ' (ID ' . (int) $opener . ')</p>';
		} elseif ( Umi_Deals::STATUS_DISPUTE === $status ) {
			echo '<p class="description">' . esc_html__( 'Инициатор не зафиксирован (старая сделка).', 'umi-marketplace' ) . '</p>';
		}
		if ( $before_l ) {
			echo '<p>' . esc_html__( 'Статус до спора', 'umi-marketplace' ) . ': ' . esc_html( is_string( $before_l ) ? $before_l : (string) $before_l ) . '</p>';
		}
		$dtid = Umi_Chat::get_dispute_thread_id( $pid );
		if ( Umi_Deals::STATUS_DISPUTE === $status && $dtid < 1 ) {
			$dtid = Umi_Chat::get_or_create_dispute_thread( $pid );
		}
		if ( $dtid > 0 ) {
			if ( Umi_Deals::STATUS_DISPUTE === $status ) {
				echo '<p class="description">' . sprintf(
					/* translators: %d thread id */
					esc_html__( 'Тред чата спора: #%d (переписка в блоке ниже).', 'umi-marketplace' ),
					(int) $dtid
				) . '</p>';
			} else {
				echo '<p class="description">' . sprintf(
					/* translators: %d thread id */
					esc_html__( 'Архив чата спора: тред #%d (сообщения внизу на странице, если открывали спор).', 'umi-marketplace' ),
					(int) $dtid
				) . '</p>';
			}
		} elseif ( Umi_Deals::STATUS_DISPUTE === $status ) {
			echo '<p class="description">' . esc_html__( 'Тред чата спора ещё не создан.', 'umi-marketplace' ) . '</p>';
		}
	}

	/**
	 * Чат по объявлению + чат спора.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_deal_chats( $post ) {
		if ( 'auto-draft' === $post->post_status || (int) $post->ID < 1 ) {
			echo '<p class="description">' . esc_html__( 'Сохраните сделку, чтобы подгрузить чаты.', 'umi-marketplace' ) . '</p>';
			return;
		}
		$pid = (int) $post->ID;
		$ltid = Umi_Chat::get_listing_thread_id_for_deal( $pid );
		$st   = Umi_Deals::get_status( $pid );
		$dtid = Umi_Chat::get_dispute_thread_id( $pid );
		if ( Umi_Deals::STATUS_DISPUTE === $st && $dtid < 1 ) {
			$dtid = (int) Umi_Chat::get_or_create_dispute_thread( $pid );
		}

		if ( $ltid < 1 && $dtid < 1 ) {
			echo '<p class="description">' . esc_html__( 'Нет тредов чата: проверьте привязку к объявлению и участников.', 'umi-marketplace' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Сообщения участников и ваши ответы видны в кабинете и по почте, как и на сайте. Здесь отображаются оба треда: обычный по объявлению и, при споре, тред спора.', 'umi-marketplace' ) . '</p>';

		if ( $ltid > 0 ) {
			echo '<h4>' . esc_html__( 'Чат по объявлению (покупатель — продавец)', 'umi-marketplace' ) . '</h4>';
			echo '<div class="umi-deal-admin-chat umi-deal-admin-chat--listing"><div class="umi-chat umi-chat--listing" data-thread="' . (int) $ltid . '">';
			echo '<div class="umi-chat-log" data-last-id="0"></div>';
			echo '<form class="umi-chat-form"><input type="hidden" name="thread_id" value="' . (int) $ltid . '" />';
			echo '<textarea name="message" class="widefat" rows="3" required placeholder="' . esc_attr__( 'Сообщение покупателю и продавцу (видят оба)…', 'umi-marketplace' ) . '"></textarea>';
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Отправить', 'umi-marketplace' ) . '</button></p></form></div></div>';
		}

		if ( $dtid > 0 ) {
			echo '<h4 style="margin-top:1.5em">' . esc_html__( 'Чат спора', 'umi-marketplace' ) . '</h4>';
			echo '<div class="umi-deal-admin-chat umi-deal-admin-chat--dispute"><div class="umi-chat umi-chat--dispute" data-thread="' . (int) $dtid . '" data-umi-dispute="1">';
			echo '<div class="umi-chat-log" data-last-id="0"></div>';
			echo '<form class="umi-chat-form umi-chat-form--dispute" data-umi-dispute-form>';
			echo '<p><input type="file" class="umi-dispute-file" accept="image/jpeg,image/png,image/gif,image/webp" /> ';
			echo '<input type="hidden" class="umi-dispute-attachment-id" name="attachment_id" value="" /><button type="button" class="button umi-dispute-attach-clear" hidden="hidden">' . esc_html__( 'Сбросить фото', 'umi-marketplace' ) . '</button></p>';
			echo '<div class="umi-dispute-attach-preview" hidden="hidden"></div>';
			echo '<textarea name="message" class="widefat" rows="3" placeholder="' . esc_attr__( 'Текст или только фото…', 'umi-marketplace' ) . '"></textarea>';
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Отправить', 'umi-marketplace' ) . '</button></p></form></div></div>';
		} elseif ( Umi_Deals::STATUS_DISPUTE !== $st ) {
			echo '<p class="description" style="margin-top:1em">' . esc_html__( 'Тред чата спора появляется, когда сделка в статусе «Спор» (или ранее открывали спор).', 'umi-marketplace' ) . '</p>';
		}
	}

	/**
	 * @param int     $post_id ID.
	 * @param WP_Post $post Post.
	 */
	public static function save_deal_meta( $post_id, $post ) {
		if ( ! $post || Umi_Cpt::DEAL !== $post->post_type ) {
			return;
		}
		if ( ! isset( $_POST['umi_deal_status_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['umi_deal_status_nonce'] ) ), 'umi_deal_status_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}
		$st = isset( $_POST['umi_deal_status'] ) ? sanitize_key( wp_unslash( $_POST['umi_deal_status'] ) ) : '';
		if ( in_array( $st, Umi_Deals::statuses(), true ) ) {
			Umi_Deals::set_status( (int) $post_id, $st );
		}
	}

	/**
	 * Admin menu.
	 */
	public static function menu() {
		$cap = 'manage_umi_marketplace';

		add_menu_page(
			__( 'UMI Маркетплейс', 'umi-marketplace' ),
			__( 'UMI', 'umi-marketplace' ),
			$cap,
			'umi-mp-settings',
			array( __CLASS__, 'render_settings' ),
			'dashicons-store',
			56
		);

		add_submenu_page(
			'umi-mp-settings',
			__( 'Настройки', 'umi-marketplace' ),
			__( 'Настройки', 'umi-marketplace' ),
			$cap,
			'umi-mp-settings',
			array( __CLASS__, 'render_settings' )
		);

		add_submenu_page(
			'umi-mp-settings',
			__( 'Журнал долей', 'umi-marketplace' ),
			__( 'Журнал долей', 'umi-marketplace' ),
			$cap,
			'umi-mp-ledger',
			array( __CLASS__, 'render_ledger' )
		);

		add_submenu_page(
			'umi-mp-settings',
			__( 'Шорткоды', 'umi-marketplace' ),
			__( 'Шорткоды', 'umi-marketplace' ),
			$cap,
			'umi-mp-shortcodes',
			array( __CLASS__, 'render_shortcodes_help' )
		);
	}

	/**
	 * Register settings.
	 */
	public static function register_settings() {
		register_setting(
			'umi_mp_settings_group',
			Umi_Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Umi_Settings', 'sanitize' ),
			)
		);
	}

	/**
	 * Settings page.
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_umi_marketplace' ) ) {
			return;
		}

		$s = Umi_Settings::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'UMI — настройки', 'umi-marketplace' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'umi_mp_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="deposit_rub_per_share"><?php esc_html_e( 'Пополнение: ₽ за 1 долю', 'umi-marketplace' ); ?></label></th>
						<td><input name="<?php echo esc_attr( Umi_Settings::OPTION_KEY ); ?>[deposit_rub_per_share]" type="number" step="1" min="1" id="deposit_rub_per_share" value="<?php echo esc_attr( (int) round( (float) $s['deposit_rub_per_share'] ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="withdraw_rub_per_share"><?php esc_html_e( 'Вывод: ₽ за 1 долю (выше)', 'umi-marketplace' ); ?></label></th>
						<td><input name="<?php echo esc_attr( Umi_Settings::OPTION_KEY ); ?>[withdraw_rub_per_share]" type="number" step="1" min="1" id="withdraw_rub_per_share" value="<?php echo esc_attr( (int) round( (float) $s['withdraw_rub_per_share'] ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="limit_services_default"><?php esc_html_e( 'Лимит услуг (по умолчанию)', 'umi-marketplace' ); ?></label></th>
						<td><input name="<?php echo esc_attr( Umi_Settings::OPTION_KEY ); ?>[limit_services_default]" type="number" min="1" id="limit_services_default" value="<?php echo esc_attr( $s['limit_services_default'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="limit_products_default"><?php esc_html_e( 'Лимит товаров (по умолчанию)', 'umi-marketplace' ); ?></label></th>
						<td><input name="<?php echo esc_attr( Umi_Settings::OPTION_KEY ); ?>[limit_products_default]" type="number" min="1" id="limit_products_default" value="<?php echo esc_attr( $s['limit_products_default'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="chat_poll_interval_ms"><?php esc_html_e( 'Интервал опроса чата (мс)', 'umi-marketplace' ); ?></label></th>
						<td><input name="<?php echo esc_attr( Umi_Settings::OPTION_KEY ); ?>[chat_poll_interval_ms]" type="number" min="2000" id="chat_poll_interval_ms" value="<?php echo esc_attr( $s['chat_poll_interval_ms'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="admin_notify_email"><?php esc_html_e( 'Email для уведомлений о модерации', 'umi-marketplace' ); ?></label></th>
						<td><input name="<?php echo esc_attr( Umi_Settings::OPTION_KEY ); ?>[admin_notify_email]" type="email" id="admin_notify_email" value="<?php echo esc_attr( $s['admin_notify_email'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="support_user_id"><?php esc_html_e( 'ID пользователя — чат «Администратор»', 'umi-marketplace' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( Umi_Settings::OPTION_KEY ); ?>[support_user_id]" type="number" min="0" id="support_user_id" value="<?php echo esc_attr( (string) ( isset( $s['support_user_id'] ) ? (int) $s['support_user_id'] : 0 ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( '0 — по умолчанию первый администратор. Участнику чата с поддержкой в треде назначается этот пользователь.', 'umi-marketplace' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="seller_profile_page_id"><?php esc_html_e( 'Страница профиля продавца', 'umi-marketplace' ); ?></label></th>
						<td>
							<?php
							$sell_page = isset( $s['seller_profile_page_id'] ) ? (int) $s['seller_profile_page_id'] : 0;
							wp_dropdown_pages(
								array(
									'name'              => Umi_Settings::OPTION_KEY . '[seller_profile_page_id]',
									'id'                => 'seller_profile_page_id',
									'selected'          => $sell_page,
									'show_option_none'  => esc_html__( '— не задана (ссылка на главную с ?umi_seller=) —', 'umi-marketplace' ),
									'option_none_value' => '0',
									'post_status'       => 'publish',
								)
							);
							?>
							<p class="description">
								<?php esc_html_e( 'Создайте страницу и вставьте в контент [umi_seller_profile]. Ссылки с каталога ведут на эту страницу с параметром umi_seller=ID .', 'umi-marketplace' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Модерация', 'umi-marketplace' ); ?></h2>
			<ul class="umi-mp-quicklinks">
				<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_status=pending&post_type=' . Umi_Cpt::SERVICE ) ); ?>"><?php esc_html_e( 'Услуги на модерации', 'umi-marketplace' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_status=pending&post_type=' . Umi_Cpt::PRODUCT ) ); ?>"><?php esc_html_e( 'Товары на модерации', 'umi-marketplace' ); ?></a></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Ledger + operations.
	 */
	public static function render_ledger() {
		if ( ! current_user_can( 'manage_umi_marketplace' ) ) {
			return;
		}

		if ( isset( $_POST['umi_ledger_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['umi_ledger_nonce'] ) ), 'umi_ledger' ) ) {
			$action   = isset( $_POST['umi_ledger_action'] ) ? sanitize_key( wp_unslash( $_POST['umi_ledger_action'] ) ) : '';
			$email_in = isset( $_POST['umi_user_email'] ) ? sanitize_email( wp_unslash( $_POST['umi_user_email'] ) ) : '';
			$rub      = isset( $_POST['umi_rub_amount'] ) ? max( 0, (int) round( (float) $_POST['umi_rub_amount'] ) ) : 0;
			$shares   = isset( $_POST['umi_shares_amount'] ) ? max( 0, (int) round( (float) $_POST['umi_shares_amount'] ) ) : 0;
			$note     = isset( $_POST['umi_note'] ) ? sanitize_text_field( wp_unslash( $_POST['umi_note'] ) ) : '';

			$uid = 0;
			if ( $email_in && is_email( $email_in ) ) {
				$u   = get_user_by( 'email', $email_in );
				$uid = $u ? (int) $u->ID : 0;
			}

			if ( ! $uid && ( 'deposit' === $action || 'withdraw' === $action ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Пользователь с таким email не найден.', 'umi-marketplace' ) . '</p></div>';
			} elseif ( $uid && 'deposit' === $action && $rub >= 1 ) {
				Umi_Ledger::credit_from_rub_deposit( get_current_user_id(), $uid, $rub, $note );
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Доли начислены.', 'umi-marketplace' ) . '</p></div>';
			} elseif ( $uid && 'withdraw' === $action && $shares >= 1 ) {
				$r = Umi_Ledger::withdraw_shares_to_rub( get_current_user_id(), $uid, $shares, $note );
				if ( $r ) {
					echo '<div class="notice notice-success"><p>' . esc_html__( 'Доли списаны (вывод зафиксирован в журнале).', 'umi-marketplace' ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Не удалось списать: проверьте баланс.', 'umi-marketplace' ) . '</p></div>';
				}
			}
		}

		$rows = Umi_Ledger::recent( 200 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Журнал операций с долями', 'umi-marketplace' ); ?></h1>

			<h2><?php esc_html_e( 'Начислить доли за рубли', 'umi-marketplace' ); ?></h2>
			<form method="post" class="umi-mp-ledger-form">
				<?php wp_nonce_field( 'umi_ledger', 'umi_ledger_nonce' ); ?>
				<input type="hidden" name="umi_ledger_action" value="deposit" />
				<p>
					<label><?php esc_html_e( 'Email пользователя', 'umi-marketplace' ); ?> <input type="email" name="umi_user_email" required autocomplete="off" class="regular-text" /></label>
					<label><?php esc_html_e( 'Сумма ₽', 'umi-marketplace' ); ?> <input type="number" step="1" min="1" name="umi_rub_amount" required class="small-text" /></label>
					<label><?php esc_html_e( 'Комментарий', 'umi-marketplace' ); ?> <input type="text" name="umi_note" class="regular-text" /></label>
					<?php submit_button( __( 'Начислить', 'umi-marketplace' ), 'secondary', 'submit', false ); ?>
				</p>
			</form>

			<h2><?php esc_html_e( 'Вывод долей в рубли', 'umi-marketplace' ); ?></h2>
			<form method="post" class="umi-mp-ledger-form">
				<?php wp_nonce_field( 'umi_ledger', 'umi_ledger_nonce' ); ?>
				<input type="hidden" name="umi_ledger_action" value="withdraw" />
				<p>
					<label><?php esc_html_e( 'Email пользователя', 'umi-marketplace' ); ?> <input type="email" name="umi_user_email" required autocomplete="off" class="regular-text" /></label>
					<label><?php esc_html_e( 'Доли', 'umi-marketplace' ); ?> <input type="number" step="1" min="1" name="umi_shares_amount" required class="small-text" /></label>
					<label><?php esc_html_e( 'Комментарий', 'umi-marketplace' ); ?> <input type="text" name="umi_note" class="regular-text" /></label>
					<?php submit_button( __( 'Списать', 'umi-marketplace' ), 'secondary', 'submit', false ); ?>
				</p>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>ID</th>
						<th><?php esc_html_e( 'Дата', 'umi-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Пользователь', 'umi-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Тип', 'umi-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Δ долей', 'umi-marketplace' ); ?></th>
						<th><?php esc_html_e( '₽', 'umi-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Курс', 'umi-marketplace' ); ?></th>
						<th><?php esc_html_e( 'Комментарий', 'umi-marketplace' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo (int) $r['id']; ?></td>
							<td><?php echo esc_html( $r['created_at'] ); ?></td>
							<td><?php echo (int) $r['user_id']; ?> — <?php echo esc_html( get_userdata( (int) $r['user_id'] ) ? get_userdata( (int) $r['user_id'] )->user_login : '—' ); ?></td>
							<td><?php echo esc_html( $r['type'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) round( (float) $r['shares_delta'] ), 0 ) ); ?></td>
							<td><?php echo esc_html( isset( $r['rub_delta'] ) && null !== $r['rub_delta'] && (string) $r['rub_delta'] !== '' ? number_format_i18n( (int) round( (float) $r['rub_delta'] ), 0 ) : '—' ); ?></td>
							<td><?php echo esc_html( isset( $r['rub_rate_used'] ) && (float) $r['rub_rate_used'] > 0 ? number_format_i18n( (int) round( (float) $r['rub_rate_used'] ), 0 ) : '—' ); ?></td>
							<td><?php echo esc_html( $r['comment'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Shortcodes help.
	 */
	public static function render_shortcodes_help() {
		if ( ! current_user_can( 'manage_umi_marketplace' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Шорткоды UMI', 'umi-marketplace' ); ?></h1>
			<ul class="umi-mp-shortcode-list">
				<li><code>[umi_services]</code> — <?php esc_html_e( 'каталог услуг + фильтры', 'umi-marketplace' ); ?></li>
				<li><code>[umi_products]</code> — <?php esc_html_e( 'каталог товаров', 'umi-marketplace' ); ?></li>
				<li><code>[umi_listing_card]</code> <?php esc_html_e( 'или', 'umi-marketplace' ); ?> <code>[umi_listing_card id="123"]</code> — <?php esc_html_e( 'карточка объявления: фото, условия, автор, кнопка «Написать продавцу» и встроенный чат (на сингле id можно не указывать)', 'umi-marketplace' ); ?></li>
				<li><code>[umi_seller_profile]</code> — <?php esc_html_e( 'публичный профиль продавца (id в ссылке umi_seller из настроек плагина)', 'umi-marketplace' ); ?></li>
				<li><code>[umi_become_seller]</code> — <?php esc_html_e( 'форма «Стать продавцом»', 'umi-marketplace' ); ?></li>
				<li><code>[umi_user_cabinet]</code> — <?php esc_html_e( 'личный кабинет: баланс, сделки, диалоги, для продавца — объявления и фото (без wp-admin)', 'umi-marketplace' ); ?></li>
				<li><code>[umi_deals]</code> — <?php esc_html_e( 'список сделок (GET umi_deal=ID — карточка сделки); удобно вынести на отдельную страницу', 'umi-marketplace' ); ?></li>
				<li><code>[umi_favorites]</code> — <?php esc_html_e( 'избранные товары и услуги (страница /izbrannoe/)', 'umi-marketplace' ); ?></li>
				<li><code>[umi_seller_cabinet]</code> — <?php esc_html_e( 'то же, что [umi_user_cabinet] (устаревшее имя)', 'umi-marketplace' ); ?></li>
				<li><code>[umi_register]</code> — <?php esc_html_e( 'регистрация с подтверждением email', 'umi-marketplace' ); ?></li>
				<li><code>[umi_login]</code> — <?php esc_html_e( 'вход и повторная отправка ссылки подтверждения', 'umi-marketplace' ); ?></li>
				<li><code>[umi_balance]</code> — <?php esc_html_e( 'баланс долей', 'umi-marketplace' ); ?></li>
				<li><code>[umi_unread_badge]</code> — <?php esc_html_e( 'бейдж непрочитанных (шапка)', 'umi-marketplace' ); ?></li>
				<li><code>[umi_chat]</code> <?php esc_html_e( 'или', 'umi-marketplace' ); ?> <code>[umi_chat id="123"]</code> — <?php esc_html_e( 'только чат (если на странице уже есть [umi_listing_card], дублирование не выводится)', 'umi-marketplace' ); ?></li>
				<li><code>[umi_contact_seller id="123"]</code> — <?php esc_html_e( 'кнопка «Написать продавцу»', 'umi-marketplace' ); ?></li>
				<li><code>[umi_header_toolbar]</code> — <?php esc_html_e( 'панель шапки: доли, уведомления, вход или выход', 'umi-marketplace' ); ?></li>
			</ul>
			<p><?php esc_html_e( 'На странице объявления достаточно [umi_listing_card]: чат уже в карточке, якорь #umi-chat у блока. Отдельно [umi_chat] нужен только в другом контексте.', 'umi-marketplace' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Email on pending.
	 *
	 * @param string  $new New status.
	 * @param string  $old Old status.
	 * @param WP_Post $post Post.
	 */
	public static function notify_pending( $new, $old, $post ) {
		if ( ! $post || ! in_array( $post->post_type, array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ), true ) ) {
			return;
		}
		if ( 'pending' !== $new ) {
			return;
		}

		$s     = Umi_Settings::get();
		$email = $s['admin_notify_email'] ? $s['admin_notify_email'] : get_option( 'admin_email' );
		if ( ! is_email( $email ) ) {
			return;
		}

		$subject = sprintf(
			'[%s] %s',
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			__( 'Новое объявление на модерации', 'umi-marketplace' )
		);
		$body = __( 'Запись:', 'umi-marketplace' ) . ' ' . $post->post_title . "\n" . admin_url( 'post.php?post=' . (int) $post->ID . '&action=edit' );
		wp_mail( $email, $subject, $body );
	}

	/**
	 * Колонка «Доли» в списке пользователей.
	 *
	 * @param string[] $cols Columns.
	 * @return string[]
	 */
	public static function users_columns( $cols ) {
		if ( ! current_user_can( 'manage_umi_marketplace' ) ) {
			return $cols;
		}
		$new = array();
		$add = false;
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'email' === $k ) {
				$new['umi_phone']    = __( 'Телефон', 'umi-marketplace' );
				$new['umi_shares']   = __( 'Доли', 'umi-marketplace' );
				$new['umi_listings'] = __( 'Объявления', 'umi-marketplace' );
				$add = true;
			}
		}
		if ( ! $add ) {
			$new['umi_phone']    = __( 'Телефон', 'umi-marketplace' );
			$new['umi_shares']   = __( 'Доли', 'umi-marketplace' );
			$new['umi_listings'] = __( 'Объявления', 'umi-marketplace' );
		}
		return $new;
	}

	/**
	 * @param string $output Output.
	 * @param string $col Column.
	 * @param int    $user_id User ID.
	 * @return string
	 */
	public static function users_column_content( $output, $col, $user_id ) {
		if ( ! current_user_can( 'manage_umi_marketplace' ) ) {
			return $output;
		}
		if ( 'umi_shares' === $col ) {
			return esc_html( number_format_i18n( (int) round( (float) Umi_Balance::get( (int) $user_id ) ), 0 ) );
		}
		if ( 'umi_phone' === $col ) {
			$phone = (string) get_user_meta( (int) $user_id, 'umi_phone', true );
			return $phone ? esc_html( $phone ) : '—';
		}
		if ( 'umi_listings' === $col ) {
			$counts = self::count_listings_for_user( (int) $user_id );
			$total  = $counts['publish'] + $counts['pending'] + $counts['draft'];
			if ( 0 === $total ) {
				return '—';
			}
			$parts = array();
			if ( $counts['publish'] > 0 ) {
				$url     = admin_url( 'edit.php?post_type=' . Umi_Cpt::SERVICE . '&author=' . (int) $user_id . '&post_status=publish' );
				$parts[] = '<a href="' . esc_url( $url ) . '">' . (int) $counts['publish'] . ' ' . esc_html__( 'опубл.', 'umi-marketplace' ) . '</a>';
			}
			if ( $counts['pending'] > 0 ) {
				$url     = admin_url( 'edit.php?post_type=' . Umi_Cpt::SERVICE . '&author=' . (int) $user_id . '&post_status=pending' );
				$parts[] = '<a href="' . esc_url( $url ) . '">' . (int) $counts['pending'] . ' ' . esc_html__( 'модер.', 'umi-marketplace' ) . '</a>';
			}
			if ( $counts['draft'] > 0 ) {
				$parts[] = (int) $counts['draft'] . ' ' . esc_html__( 'черн.', 'umi-marketplace' );
			}
			return implode( ', ', $parts );
		}
		return $output;
	}

	/**
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	private static function count_listings_for_user( $user_id ) {
		$counts = array( 'publish' => 0, 'pending' => 0, 'draft' => 0 );
		foreach ( array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ) as $pt ) {
			$q = new WP_Query(
				array(
					'post_type'      => $pt,
					'author'         => (int) $user_id,
					'post_status'    => array( 'publish', 'pending', 'draft' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => false,
				)
			);
			foreach ( $q->posts as $pid ) {
				$st = get_post_status( (int) $pid );
				if ( isset( $counts[ $st ] ) ) {
					$counts[ $st ]++;
				}
			}
		}
		return $counts;
	}

	/**
	 * Сообщение после начисления/списания в профиле.
	 */
	public static function profile_ledger_notice() {
		$uid = get_current_user_id();
		if ( $uid < 1 || ! current_user_can( 'manage_umi_marketplace' ) ) {
			return;
		}
		$data = get_transient( 'umi_profile_ledger_' . $uid );
		if ( ! is_array( $data ) || empty( $data['message'] ) ) {
			return;
		}
		delete_transient( 'umi_profile_ledger_' . $uid );
		$ok = ( isset( $data['type'] ) && 'error' !== $data['type'] );
		$class = $ok ? 'notice-success' : 'notice-error';
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( (string) $data['message'] )
		);
	}

	/**
	 * @param string $message Message.
	 * @param string $type success|error.
	 */
	private static function set_profile_ledger_notice( $message, $type = 'success' ) {
		$cur = (int) get_current_user_id();
		if ( $cur < 1 ) {
			return;
		}
		set_transient( 'umi_profile_ledger_' . $cur, array( 'type' => (string) $type, 'message' => (string) $message ), 90 );
	}

	/**
	 * User profile fields (limits).
	 *
	 * @param WP_User $user User.
	 */
	public static function user_fields( $user ) {
		if ( ! current_user_can( 'manage_umi_marketplace' ) ) {
			return;
		}
		$uid   = (int) $user->ID;
		$ls    = get_user_meta( $user->ID, 'umi_limit_services_override', true );
		$lp    = get_user_meta( $user->ID, 'umi_limit_products_override', true );
		$phone = (string) get_user_meta( $user->ID, 'umi_phone', true );
		$city  = (string) get_user_meta( $user->ID, 'umi_profile_city', true );
		$prof  = (string) get_user_meta( $user->ID, 'umi_profile_profession', true );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="umi_phone"><?php esc_html_e( 'Телефон', 'umi-marketplace' ); ?></label></th>
				<td>
					<input type="text" name="umi_phone" id="umi_phone" value="<?php echo esc_attr( $phone ); ?>" class="regular-text" />
				</td>
			</tr>
			<tr>
				<th><label for="umi_profile_city"><?php esc_html_e( 'Город', 'umi-marketplace' ); ?></label></th>
				<td>
					<input type="text" name="umi_profile_city" id="umi_profile_city" value="<?php echo esc_attr( $city ); ?>" class="regular-text" />
				</td>
			</tr>
			<tr>
				<th><label for="umi_profile_profession"><?php esc_html_e( 'Профессия', 'umi-marketplace' ); ?></label></th>
				<td>
					<input type="text" name="umi_profile_profession" id="umi_profile_profession" value="<?php echo esc_attr( $prof ); ?>" class="regular-text" />
				</td>
			</tr>
		</table>

		<h2 id="umi-user-shares"><?php esc_html_e( 'UMI — доли, журнал, сделки', 'umi-marketplace' ); ?></h2>
		<p><strong><?php esc_html_e( 'Баланс долей', 'umi-marketplace' ); ?>:</strong> <?php echo esc_html( number_format_i18n( (int) round( (float) Umi_Balance::get( $uid ) ), 0 ) ); ?></p>
		<?php
		wp_nonce_field( 'umi_user_ledger_' . $uid, 'umi_user_ledger_nonce' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="umi_ledger_add"><?php esc_html_e( 'Начислить доли', 'umi-marketplace' ); ?></label></th>
				<td>
					<input name="umi_ledger_add" id="umi_ledger_add" type="text" inputmode="decimal" class="small-text" value="" placeholder="0" />
					<p class="description"><?php esc_html_e( 'Положительное число. Запись попадёт в журнал (тип: начисление админом).', 'umi-marketplace' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="umi_ledger_sub"><?php esc_html_e( 'Списать доли', 'umi-marketplace' ); ?></label></th>
				<td>
					<input name="umi_ledger_sub" id="umi_ledger_sub" type="text" inputmode="decimal" class="small-text" value="" placeholder="0" />
					<p class="description"><?php esc_html_e( 'Положительное число: столько долей спишется с баланса. Недостаточно — операция не выполнится.', 'umi-marketplace' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="umi_ledger_comment"><?php esc_html_e( 'Комментарий к операции', 'umi-marketplace' ); ?></label></th>
				<td>
					<textarea name="umi_ledger_comment" id="umi_ledger_comment" class="large-text" rows="2" placeholder=""></textarea>
				</td>
			</tr>
		</table>
		<p class="description"><?php esc_html_e( 'Сохраните профиль пользователя, чтобы применить начисление/списание (вместе с остальными полями).', 'umi-marketplace' ); ?></p>

		<h3 style="margin-top:1.5em"><?php esc_html_e( 'Журнал операций (доли)', 'umi-marketplace' ); ?></h3>
		<?php
		$rows = Umi_Ledger::for_user( $uid, 150 );
		if ( $rows ) {
			printf(
				'<p class="description"><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=umi-mp-ledger' ) ),
				esc_html__( 'Полный журнал плагина', 'umi-marketplace' )
			);
			echo '<table class="widefat striped" style="max-width:min(100%,920px)"><thead><tr>';
			echo '<th>' . esc_html__( 'Дата', 'umi-marketplace' ) . '</th>';
			echo '<th>' . esc_html__( 'Тип', 'umi-marketplace' ) . '</th>';
			echo '<th>' . esc_html__( 'Δ долей', 'umi-marketplace' ) . '</th>';
			echo '<th>' . esc_html__( 'Комментарий', 'umi-marketplace' ) . '</th>';
			echo '<th>' . esc_html__( 'Сделка', 'umi-marketplace' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$did = isset( $r['deal_id'] ) ? (int) $r['deal_id'] : 0;
				$dl  = $did > 0 ? get_edit_post_link( $did, 'raw' ) : '';
				echo '<tr>';
				echo '<td>' . esc_html( isset( $r['created_at'] ) ? (string) $r['created_at'] : '' ) . '</td>';
				echo '<td>' . esc_html( Umi_Ledger::type_label( isset( $r['type'] ) ? (string) $r['type'] : '' ) ) . '</td>';
				$ds = isset( $r['shares_delta'] ) ? (int) round( (float) $r['shares_delta'] ) : 0;
				echo '<td>' . esc_html( number_format_i18n( $ds, 0 ) ) . '</td>';
				echo '<td>' . esc_html( isset( $r['comment'] ) ? (string) $r['comment'] : '' ) . '</td>';
				echo '<td>';
				if ( $did > 0 && $dl ) {
					printf( '<a href="%s">#%d</a>', esc_url( $dl ), (int) $did );
				} else {
					echo '—';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">' . esc_html__( 'Записей пока нет.', 'umi-marketplace' ) . '</p>';
		}

		$deals = Umi_Deals::deals_for_user( $uid, 100 );
		echo '<h3 style="margin-top:1.5em">' . esc_html__( 'Сделки', 'umi-marketplace' ) . '</h3>';
		if ( $deals ) {
			$labels = Umi_Deals::status_labels();
			echo '<table class="widefat striped" style="max-width:min(100%,920px)"><thead><tr>';
			echo '<th>' . esc_html__( 'Дата', 'umi-marketplace' ) . '</th>';
			echo '<th>' . esc_html__( 'Сделка', 'umi-marketplace' ) . '</th>';
			echo '<th>' . esc_html__( 'Статус', 'umi-marketplace' ) . '</th>';
			echo '<th>' . esc_html__( 'Роль', 'umi-marketplace' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $deals as $p ) {
				$pid = (int) $p->ID;
				$st  = Umi_Deals::get_status( $pid );
				$sl  = isset( $labels[ $st ] ) ? $labels[ $st ] : $st;
				$el  = get_edit_post_link( $pid, 'raw' );
				$buy = Umi_Deals::get_buyer_id( $pid );
				$role = ( $uid === (int) $buy ) ? __( 'Покупатель', 'umi-marketplace' ) : __( 'Продавец', 'umi-marketplace' );
				echo '<tr><td>' . esc_html( get_post_time( 'Y-m-d H:i', false, $p ) ) . '</td><td>';
				if ( $el ) {
					printf( '<a href="%s">%s</a>', esc_url( $el ), esc_html( get_the_title( $p ) . ' (#' . $pid . ')' ) );
				} else {
					echo esc_html( $p->post_title . ' (#' . $pid . ')' );
				}
				echo '</td><td>' . esc_html( $sl ) . '</td><td>' . esc_html( $role ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">' . esc_html__( 'Сделок с участием этого пользователя нет.', 'umi-marketplace' ) . '</p>';
		}

		$disps = Umi_Deals::disputes_for_user( $uid, 50 );
		echo '<h3 style="margin-top:1.5em">' . esc_html__( 'Сделки в споре', 'umi-marketplace' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Активные сделки в статусе «Спор». Закрытые споры отображаются в общем списке сделок с итоговым статусом.', 'umi-marketplace' ) . '</p>';
		if ( $disps ) {
			$labels = Umi_Deals::status_labels();
			echo '<table class="widefat striped" style="max-width:min(100%,920px)"><thead><tr>';
			echo '<th>' . esc_html__( 'Дата', 'umi-marketplace' ) . '</th>';
			echo '<th>' . esc_html__( 'Сделка', 'umi-marketplace' ) . '</th>';
			echo '<th>' . esc_html__( 'Статус', 'umi-marketplace' ) . '</th>';
			echo '<th>' . esc_html__( 'Роль', 'umi-marketplace' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $disps as $p ) {
				$pid = (int) $p->ID;
				$st  = Umi_Deals::get_status( $pid );
				$sl  = isset( $labels[ $st ] ) ? $labels[ $st ] : $st;
				$el  = get_edit_post_link( $pid, 'raw' );
				$buy = Umi_Deals::get_buyer_id( $pid );
				$role = ( $uid === (int) $buy ) ? __( 'Покупатель', 'umi-marketplace' ) : __( 'Продавец', 'umi-marketplace' );
				echo '<tr><td>' . esc_html( get_post_time( 'Y-m-d H:i', false, $p ) ) . '</td><td>';
				if ( $el ) {
					printf( '<a href="%s">%s</a>', esc_url( $el ), esc_html( get_the_title( $p ) . ' (#' . $pid . ')' ) );
				} else {
					echo esc_html( $p->post_title . ' (#' . $pid . ')' );
				}
				echo '</td><td>' . esc_html( $sl ) . '</td><td>' . esc_html( $role ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">' . esc_html__( 'Нет сделок в статусе «Спор».', 'umi-marketplace' ) . '</p>';
		}

		$listings_q = new WP_Query(
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
		echo '<h3 style="margin-top:1.5em">' . esc_html__( 'Объявления пользователя', 'umi-marketplace' ) . '</h3>';
		if ( $listings_q->have_posts() ) {
			$st_labels = array(
				'publish' => __( 'Опубликовано', 'umi-marketplace' ),
				'pending' => __( 'На модерации', 'umi-marketplace' ),
				'draft'   => __( 'Черновик', 'umi-marketplace' ),
			);
			echo '<table class="widefat striped" style="max-width:min(100%,920px)"><thead><tr>';
			echo '<th>' . esc_html__( 'Тип', 'umi-marketplace' ) . '</th>';
			echo '<th>' . esc_html__( 'Название', 'umi-marketplace' ) . '</th>';
			echo '<th>' . esc_html__( 'Статус', 'umi-marketplace' ) . '</th>';
			echo '<th>' . esc_html__( 'Дата', 'umi-marketplace' ) . '</th>';
			echo '<th>' . esc_html__( 'На сайте', 'umi-marketplace' ) . '</th>';
			echo '</tr></thead><tbody>';
			while ( $listings_q->have_posts() ) {
				$listings_q->the_post();
				$lpid     = (int) get_the_ID();
				$ltype    = get_post_type( $lpid );
				$type_l   = ( Umi_Cpt::SERVICE === $ltype ) ? __( 'Услуга', 'umi-marketplace' ) : __( 'Товар', 'umi-marketplace' );
				$lst      = get_post_status( $lpid );
				$lst_text = isset( $st_labels[ $lst ] ) ? $st_labels[ $lst ] : $lst;
				$edit_url = get_edit_post_link( $lpid, 'raw' );
				$view_url = ( 'publish' === $lst ) ? get_permalink( $lpid ) : '';
				echo '<tr>';
				echo '<td>' . esc_html( $type_l ) . '</td>';
				echo '<td style="display:flex;align-items:center;gap:8px">';
				$thumb = get_the_post_thumbnail( $lpid, array( 40, 40 ), array( 'style' => 'width:40px;height:40px;object-fit:cover;border-radius:4px;flex-shrink:0' ) );
				if ( $thumb ) {
					echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				if ( $edit_url ) {
					printf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $edit_url ), esc_html( get_the_title() ) );
				} else {
					echo esc_html( get_the_title() );
				}
				if ( $view_url ) {
					echo ' <a href="' . esc_url( $view_url ) . '" target="_blank" rel="noopener">↗</a>';
				}
				echo '</td>';
				echo '<td>' . esc_html( $lst_text ) . '</td>';
				echo '<td>' . esc_html( get_post_time( 'Y-m-d', false ) ) . '</td>';
				echo '<td>';
				if ( $view_url ) {
					printf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $view_url ), esc_html__( 'Открыть', 'umi-marketplace' ) );
				} else {
					echo '—';
				}
				echo '</td>';
				echo '</tr>';
			}
			wp_reset_postdata();
			echo '</tbody></table>';
		} else {
			echo '<p class="description">' . esc_html__( 'Объявлений нет.', 'umi-marketplace' ) . '</p>';
		}
		?>
		<h2><?php esc_html_e( 'UMI — лимиты', 'umi-marketplace' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="umi_limit_services_override"><?php esc_html_e( 'Лимит услуг (переопределение)', 'umi-marketplace' ); ?></label></th>
				<td><input type="number" min="1" name="umi_limit_services_override" id="umi_limit_services_override" value="<?php echo esc_attr( (string) $ls ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'по умолчанию', 'umi-marketplace' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="umi_limit_products_override"><?php esc_html_e( 'Лимит товаров (переопределение)', 'umi-marketplace' ); ?></label></th>
				<td><input type="number" min="1" name="umi_limit_products_override" id="umi_limit_products_override" value="<?php echo esc_attr( (string) $lp ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'по умолчанию', 'umi-marketplace' ); ?>" /></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save user fields.
	 *
	 * @param int $user_id User ID.
	 */
	public static function save_user_fields( $user_id ) {
		if ( ! current_user_can( 'manage_umi_marketplace' ) ) {
			return;
		}
		$user_id = (int) $user_id;
		if ( $user_id > 0 && isset( $_POST['umi_user_ledger_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['umi_user_ledger_nonce'] ) ), 'umi_user_ledger_' . $user_id ) ) {
			$add     = isset( $_POST['umi_ledger_add'] ) ? (float) str_replace( ',', '.', (string) wp_unslash( $_POST['umi_ledger_add'] ) ) : 0;
			$sub     = isset( $_POST['umi_ledger_sub'] ) ? (float) str_replace( ',', '.', (string) wp_unslash( $_POST['umi_ledger_sub'] ) ) : 0;
			$comment = isset( $_POST['umi_ledger_comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['umi_ledger_comment'] ) ) : '';
			$admin_id = (int) get_current_user_id();
			$parts    = array();
			$errors   = array();

			if ( $add >= 1 ) {
				$add_s = Umi_Balance::normalize( (string) $add );
				if ( (int) $add_s > 0 ) {
					$r = Umi_Ledger::record(
						array(
							'admin_user_id' => $admin_id,
							'user_id'       => $user_id,
							'type'          => 'admin_credit',
							'shares_delta'  => $add_s,
							'comment'       => $comment,
						)
					);
					if ( $r ) {
						$parts[] = __( 'Доли начислены.', 'umi-marketplace' );
					} else {
						$errors[] = __( 'Не удалось начислить доли.', 'umi-marketplace' );
					}
				}
			}
			if ( $sub >= 1 ) {
				$sub_s = (int) Umi_Balance::normalize( (string) $sub );
				$bal   = (int) Umi_Balance::get( $user_id );
				if ( $sub_s < 1 ) {
					$errors[] = __( 'Сумма списания некорректна.', 'umi-marketplace' );
				} elseif ( $bal < $sub_s ) {
					$errors[] = __( 'Недостаточно долей для списания.', 'umi-marketplace' );
				} else {
					$r = Umi_Ledger::record(
						array(
							'admin_user_id' => $admin_id,
							'user_id'       => $user_id,
							'type'          => 'admin_debit',
							'shares_delta'  => (string) ( -1 * $sub_s ),
							'comment'       => $comment,
						)
					);
					if ( $r ) {
						$parts[] = __( 'Доли списаны.', 'umi-marketplace' );
					} else {
						$errors[] = __( 'Не удалось списать доли.', 'umi-marketplace' );
					}
				}
			}
			if ( $errors ) {
				self::set_profile_ledger_notice( implode( ' ', $errors ), 'error' );
			} elseif ( $parts ) {
				self::set_profile_ledger_notice( implode( ' ', $parts ), 'success' );
			}
		}
		if ( isset( $_POST['umi_phone'] ) ) {
			$new_phone = sanitize_text_field( wp_unslash( $_POST['umi_phone'] ) );
			if ( '' === $new_phone ) {
				delete_user_meta( $user_id, 'umi_phone' );
			} else {
				update_user_meta( $user_id, 'umi_phone', $new_phone );
			}
		}
		if ( isset( $_POST['umi_profile_city'] ) ) {
			$new_city = sanitize_text_field( wp_unslash( $_POST['umi_profile_city'] ) );
			if ( '' === $new_city ) {
				delete_user_meta( $user_id, 'umi_profile_city' );
			} else {
				update_user_meta( $user_id, 'umi_profile_city', $new_city );
			}
		}
		if ( isset( $_POST['umi_profile_profession'] ) ) {
			$new_prof = sanitize_text_field( wp_unslash( $_POST['umi_profile_profession'] ) );
			if ( '' === $new_prof ) {
				delete_user_meta( $user_id, 'umi_profile_profession' );
			} else {
				update_user_meta( $user_id, 'umi_profile_profession', $new_prof );
			}
		}
		if ( isset( $_POST['umi_limit_services_override'] ) && $_POST['umi_limit_services_override'] !== '' ) {
			update_user_meta( $user_id, 'umi_limit_services_override', max( 1, (int) $_POST['umi_limit_services_override'] ) );
		} else {
			delete_user_meta( $user_id, 'umi_limit_services_override' );
		}
		if ( isset( $_POST['umi_limit_products_override'] ) && $_POST['umi_limit_products_override'] !== '' ) {
			update_user_meta( $user_id, 'umi_limit_products_override', max( 1, (int) $_POST['umi_limit_products_override'] ) );
		} else {
			delete_user_meta( $user_id, 'umi_limit_products_override' );
		}
	}

	/**
	 * Limit notice after save.
	 */
	public static function limit_notice() {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return;
		}
		$key = 'umi_limit_notice_' . $uid;
		$msg = get_transient( $key );
		if ( ! $msg ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}
}
