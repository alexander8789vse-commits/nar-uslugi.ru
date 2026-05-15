<?php
/**
 * Scripts and styles.
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Assets
 */
class Umi_Assets {

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'public_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_deal_screen_assets' ) );
	}

	/**
	 * Скрипт чата на экране редактирования сделки (тот же poll/send, что на сайте).
	 *
	 * @param string $hook Суффикс экрана.
	 */
	public static function admin_deal_screen_assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Umi_Cpt::DEAL !== $screen->post_type ) {
			return;
		}
		$settings = Umi_Settings::get();
		$poll     = (int) $settings['chat_poll_interval_ms'];
		wp_register_script(
			'umi-mp-chat',
			UMI_MP_URL . 'assets/umi-public.js',
			array(),
			UMI_MP_VERSION,
			true
		);
		wp_enqueue_script( 'umi-mp-chat' );
		wp_localize_script(
			'umi-mp-chat',
			'umiMp',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( Umi_Ajax::NONCE_ACTION ),
				'uploadNonce' => wp_create_nonce( Umi_Ajax::NONCE_UPLOAD ),
				'pollMs'      => $poll,
				'i18n'        => array(
					'deleteThread'          => __( 'Удалить этот диалог? Переписка станет недоступна вам и собеседнику.', 'umi-marketplace' ),
					'noThreads'             => __( 'Пока нет переписок. Откройте объявление и нажмите «Написать продавцу».', 'umi-marketplace' ),
					'cabinetPriceNonAuthor' => __( 'Можно купить только за доли', 'umi-marketplace' ),
					'cabinetPriceBoth'      => __( 'Можно купить за доли и рубли', 'umi-marketplace' ),
					'cabinetPriceRub'       => __( 'Стоимость, ₽', 'umi-marketplace' ),
				),
			)
		);
		wp_register_style( 'umi-deal-admin-chat', false, array(), UMI_MP_VERSION );
		wp_enqueue_style( 'umi-deal-admin-chat' );
		wp_add_inline_style(
			'umi-deal-admin-chat',
			'.umi-deal-admin-chat .umi-chat { max-width: 640px; margin-top: 8px; }
			.umi-deal-admin-chat .umi-chat-log { max-height: 220px; overflow: auto; border: 1px solid #c3c4c7; background: #fff; padding: 8px; }
			.umi-deal-admin-chat .umi-chat-msg { margin-bottom: 8px; font-size: 13px; }
			.umi-deal-admin-chat .umi-chat-msg--mine { margin-left: 12%; }
			.umi-deal-admin-chat .umi-chat-msg-body { background: #f0f0f1; padding: 6px 8px; border-radius: 4px; }
			.umi-deal-admin-chat .umi-chat-msg--mine .umi-chat-msg-body { background: #e7f0f7; }
			.umi-deal-admin-chat .umi-chat-form textarea { width: 100%; max-width: 640px; }
			.umi-deal-parties { display: grid; gap: 12px; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
			.umi-deal-party { margin: 0; padding: 8px 12px; background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px; }
			.umi-deal-party p { margin: 0 0 6px; font-size: 13px; }'
		);
	}

	/**
	 * JS для кнопки «Привлечь администратора» — работает и без авторизации.
	 */
	private static function alert_admin_script() {
		return <<<'JS'
(function () {
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.umi-alert-admin-btn');
		if (!btn || btn.disabled) return;
		btn.disabled = true;
		var msg = btn.parentNode.querySelector('.umi-alert-admin-msg');
		btn.textContent = 'Отправляем…';
		var fd = new FormData();
		fd.append('action', 'umi_mp_alert_admin');
		fd.append('nonce', btn.dataset.nonce);
		fd.append('listing_id', btn.dataset.listingId);
		fetch(btn.dataset.ajaxUrl, { method: 'POST', body: fd })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (d.success) {
					btn.textContent = 'Администратор уведомлён';
					if (msg) msg.textContent = '';
				} else {
					btn.textContent = 'Привлечь администратора';
					btn.disabled = false;
					if (msg) msg.textContent = 'Не удалось отправить, попробуйте позже.';
				}
			})
			.catch(function () {
				btn.textContent = 'Привлечь администратора';
				btn.disabled = false;
				if (msg) msg.textContent = 'Ошибка сети, попробуйте позже.';
			});
	});
})();
JS;
	}

	/**
	 * Front assets.
	 */
	public static function public_assets() {
		$settings = Umi_Settings::get();
		$poll     = (int) $settings['chat_poll_interval_ms'];

		wp_register_script(
			'umi-mp-chat',
			UMI_MP_URL . 'assets/umi-public.js',
			array(),
			UMI_MP_VERSION,
			true
		);

		if ( is_user_logged_in() ) {
			wp_enqueue_script( 'umi-mp-chat' );
			wp_localize_script(
				'umi-mp-chat',
				'umiMp',
				array(
					'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( Umi_Ajax::NONCE_ACTION ),
					'uploadNonce' => wp_create_nonce( Umi_Ajax::NONCE_UPLOAD ),
					'pollMs'      => $poll,
					'i18n'        => array(
						'deleteThread'          => __( 'Удалить этот диалог? Переписка станет недоступна вам и собеседнику.', 'umi-marketplace' ),
						'noThreads'             => __( 'Пока нет переписок. Откройте объявление и нажмите «Написать продавцу».', 'umi-marketplace' ),
						'cabinetPriceNonAuthor' => __( 'Можно купить только за доли', 'umi-marketplace' ),
						'cabinetPriceBoth'      => __( 'Купить можно за доли и рубли', 'umi-marketplace' ),
						'cabinetPriceRub'       => __( 'Стоимость, ₽', 'umi-marketplace' ),
						'favAdd'                => __( 'В избранное', 'umi-marketplace' ),
						'favRemove'             => __( 'Убрать из избранного', 'umi-marketplace' ),
					),
				)
			);
		}

		wp_register_script( 'umi-alert-admin', false, array(), UMI_MP_VERSION, true );
		wp_enqueue_script( 'umi-alert-admin' );
		wp_add_inline_script( 'umi-alert-admin', self::alert_admin_script() );
	}
}
