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

		wp_register_style( 'umi-cabinet-v2', false, array(), UMI_MP_VERSION );
		wp_enqueue_style( 'umi-cabinet-v2' );
		wp_add_inline_style( 'umi-cabinet-v2', self::cabinet_v2_css() );
	}

	/**
	 * CSS для двухколоночного кабинета продавца.
	 */
	private static function cabinet_v2_css() {
		return '
/* ── Layout ─────────────────────────────────────────── */
.umi-cabinet--v2 { font-size: 15px; }
.umi-cabinet-layout {
  display: grid;
  grid-template-columns: 272px 1fr;
  gap: 24px;
  align-items: start;
}
@media (max-width: 767px) {
  .umi-cabinet-layout { grid-template-columns: 1fr; }
  .umi-cabinet--v2 .umi-cabinet-sidebar { order: 0; }
  .umi-cabinet--v2 .umi-cabinet-main { order: 1; }
}
.umi-cabinet-flash { margin-bottom: 16px; }

/* ── Main header ──────────────────────────────────────── */
.umi-cabinet-main-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
  gap: 12px;
}
.umi-cabinet-main-header .umi-cabinet-heading { margin: 0; }
.umi-btn--sm { padding: 6px 14px; font-size: 13px; }

/* ── Listings table ───────────────────────────────────── */
.umi-cabinet-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;
  background: #fff;
  border-radius: 10px;
  overflow: hidden;
  box-shadow: 0 1px 4px rgba(0,0,0,.07);
}
.umi-cabinet-table th,
.umi-cabinet-table td {
  padding: 10px 14px;
  text-align: left;
  border-bottom: 1px solid #f0f0f0;
}
.umi-cabinet-table thead th {
  background: #f7f8fa;
  font-weight: 600;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: #777;
}
.umi-cabinet-table tbody tr:last-child td { border-bottom: none; }
.umi-cabinet-table tbody tr:hover td { background: #fafbff; }
.umi-cabinet-table-title-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}
.umi-cabinet-table-type {
  font-size: 11px;
  background: #f0f0f0;
  border-radius: 4px;
  padding: 2px 7px;
  white-space: nowrap;
}
.umi-cabinet-table-status { font-size: 13px; font-weight: 500; }
.umi-cabinet-table-status--publish { color: #1a7f3c; }
.umi-cabinet-table-status--pending { color: #b06c00; }
.umi-cabinet-table-status--draft   { color: #999; }
.umi-cabinet-table-actions { white-space: nowrap; }
.umi-cabinet-table-actions-inner { display: flex; justify-content: space-around; align-items: center; }
.umi-cabinet-table-actions .umi-cabinet-list-delete { display: inline; }
.umi-cabinet-icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px; height: 30px;
  border-radius: 6px;
  border: none;
  background: none;
  cursor: pointer;
  color: #9ca3af;
  padding: 0;
  text-decoration: none;
  transition: color .15s, background .15s;
  vertical-align: middle;
}
.umi-cabinet-icon-btn:hover { background: #f3f4f6; }
.umi-cabinet-icon-btn--edit:hover { color: #4361ee; }
.umi-cabinet-icon-btn--delete:hover { color: #dc2626; }

/* ── Sidebar ──────────────────────────────────────────── */
.umi-cabinet-sidebar {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 1px 4px rgba(0,0,0,.06);
}

/* Profile card */
.umi-cabinet-profile-card {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 20px 18px 16px;
  border-bottom: 1px solid #f0f0f2;
}
.umi-cabinet-avatar-btn {
  position: relative;
  display: inline-flex;
  background: none;
  border: none;
  cursor: pointer;
  padding: 0;
  border-radius: 50%;
  flex-shrink: 0;
  transition: opacity .15s;
}
.umi-cabinet-avatar-btn:hover { opacity: .85; }
.umi-cabinet-avatar-btn .umi-cabinet-avatar {
  width: 56px; height: 56px;
  border-radius: 50%;
  display: block;
  object-fit: cover;
}
.umi-cabinet-avatar-badge {
  position: absolute;
  bottom: 1px; right: 1px;
  background: #4361ee;
  color: #fff;
  border-radius: 50%;
  width: 18px; height: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 2px solid #fff;
}
.umi-cabinet-profile-info { min-width: 0; }
.umi-cabinet-profile-name {
  font-weight: 600; font-size: 15px;
  margin: 0 0 3px;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.umi-cabinet-profile-role {
  font-size: 12px; color: #888; margin: 0;
}

/* Balance */
.umi-cabinet-sidebar-balance {
  padding: 11px 18px;
  border-bottom: 1px solid #f0f0f2;
  font-size: 14px;
}

/* Sidebar nav */
.umi-cabinet-sidebar-nav { display: flex; flex-direction: column; }
.umi-cabinet-nav-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 13px 18px;
  background: none;
  border: none;
  border-bottom: 1px solid #f5f5f7;
  cursor: pointer;
  text-align: left;
  font-size: 14px;
  font-family: inherit;
  color: #222;
  width: 100%;
  transition: background .12s, color .12s;
}
.umi-cabinet-nav-item:last-child { border-bottom: none; }
.umi-cabinet-nav-item:hover { background: #f0f3ff; color: #4361ee; }
.umi-cabinet-nav-item:focus-visible { outline: 2px solid #4361ee; outline-offset: -2px; }
.umi-cabinet-nav-item__label { flex: 1; }
.umi-cabinet-nav-item__count {
  background: #dde0e7;
  color: #555;
  border-radius: 10px;
  padding: 1px 8px;
  font-size: 11px;
  font-weight: 600;
}
.umi-cabinet-nav-item__badge {
  border-radius: 10px;
  padding: 1px 8px;
  font-size: 11px;
  font-weight: 600;
  background: #e53935;
  color: #fff;
}
.umi-chat-badge--empty { display: none !important; }

/* ── Modals ───────────────────────────────────────────── */
.umi-modal {
  position: fixed;
  inset: 0;
  z-index: 9990;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.umi-modal[hidden] { display: none; }
.umi-modal__backdrop {
  position: absolute;
  inset: 0;
  background: rgba(0,0,0,.48);
}
.umi-modal__box {
  position: relative;
  background: #fff;
  border-radius: 14px;
  box-shadow: 0 10px 48px rgba(0,0,0,.18);
  width: 100%;
  max-width: 580px;
  max-height: 88vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.umi-modal__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 18px 24px 14px;
  border-bottom: 1px solid #f0f0f2;
  flex-shrink: 0;
}
.umi-modal__title { font-size: 17px; font-weight: 700; margin: 0; }
.umi-modal__close {
  background: none;
  border: none;
  font-size: 20px;
  cursor: pointer;
  color: #aaa;
  padding: 4px 8px;
  border-radius: 6px;
  line-height: 1;
  transition: color .1s, background .1s;
  flex-shrink: 0;
}
.umi-modal__close:hover { color: #333; background: #f0f0f2; }
.umi-modal__body {
  padding: 20px 24px;
  overflow-y: auto;
  flex: 1;
}
@media (max-width: 480px) {
  .umi-modal__box { max-height: 94vh; border-radius: 12px 12px 0 0; }
  .umi-modal { align-items: flex-end; padding: 0; }
}
';
	}
}
