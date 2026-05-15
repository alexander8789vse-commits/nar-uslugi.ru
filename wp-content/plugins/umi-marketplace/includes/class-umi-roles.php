<?php
/**
 * Custom roles and caps.
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Roles
 */
class Umi_Roles {

	const ROLE_BUYER  = 'umi_buyer';
	const ROLE_SELLER = 'umi_seller';

	/**
	 * Create roles and caps.
	 */
	public static function install_roles() {
		self::register_caps();

		if ( ! get_role( self::ROLE_BUYER ) ) {
			add_role(
				self::ROLE_BUYER,
				__( 'Покупатель UMI', 'umi-marketplace' ),
				array(
					'read'                   => true,
					'upload_files'           => true,
					'edit_umi_reviews'       => true,
					'edit_umi_review'        => true,
					'delete_umi_reviews'     => true,
					'delete_umi_review'      => true,
					'read_umi_review'        => true,
					'delete_published_umi_reviews' => true,
					'edit_published_umi_reviews'   => true,
					'publish_umi_reviews'    => true,
				)
			);
		}

		if ( ! get_role( self::ROLE_SELLER ) ) {
			add_role(
				self::ROLE_SELLER,
				__( 'Продавец UMI', 'umi-marketplace' ),
				array(
					'read'                   => true,
					'upload_files'           => true,
					'edit_umi_services'      => true,
					'edit_umi_service'       => true,
					'delete_umi_services'    => true,
					'delete_umi_service'     => true,
					'read_umi_service'       => true,
					'delete_published_umi_services' => true,
					'edit_published_umi_services'   => true,
					'edit_umi_products'      => true,
					'edit_umi_product'       => true,
					'delete_umi_products'    => true,
					'delete_umi_product'     => true,
					'read_umi_product'       => true,
					'delete_published_umi_products' => true,
					'edit_published_umi_products'   => true,
					'publish_umi_services'   => true,
					'publish_umi_products'   => true,
					'edit_umi_reviews'       => true,
					'edit_umi_review'        => true,
					'delete_umi_reviews'     => true,
					'delete_umi_review'      => true,
					'read_umi_review'        => true,
					'delete_published_umi_reviews' => true,
					'edit_published_umi_reviews'   => true,
					'publish_umi_reviews'    => true,
				)
			);
		}

		self::grant_admin_caps();
	}

	/**
	 * Register post type capabilities with WP.
	 */
	public static function register_caps() {
		$roles = array( 'administrator' );
		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			$caps = self::all_caps();
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Ensure administrator has all marketplace caps.
	 */
	public static function grant_admin_caps() {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}
		foreach ( self::all_caps() as $cap ) {
			$role->add_cap( $cap );
		}
	}

	/**
	 * Публикация/отправка на модерацию с фронта (установленные сайты без реактивации).
	 */
	public static function ensure_seller_publish_caps() {
		$role = get_role( self::ROLE_SELLER );
		if ( ! $role ) {
			return;
		}
		foreach ( array( 'publish_umi_services', 'publish_umi_products' ) as $cap ) {
			$role->add_cap( $cap );
		}
	}

	/**
	 * Загрузка изображений с фронта (кабинет) без медиатеки wp-admin.
	 */
	public static function ensure_upload_caps() {
		foreach ( array( self::ROLE_BUYER, self::ROLE_SELLER ) as $rname ) {
			$role = get_role( $rname );
			if ( $role && ! $role->has_cap( 'upload_files' ) ) {
				$role->add_cap( 'upload_files' );
			}
		}
	}

	/**
	 * All plugin capabilities.
	 *
	 * @return string[]
	 */
	public static function all_caps() {
		return array(
			'edit_umi_service',
			'read_umi_service',
			'delete_umi_service',
			'edit_umi_services',
			'edit_others_umi_services',
			'publish_umi_services',
			'read_private_umi_services',
			'delete_umi_services',
			'delete_private_umi_services',
			'delete_published_umi_services',
			'delete_others_umi_services',
			'edit_private_umi_services',
			'edit_published_umi_services',
			'edit_umi_product',
			'read_umi_product',
			'delete_umi_product',
			'edit_umi_products',
			'edit_others_umi_products',
			'publish_umi_products',
			'read_private_umi_products',
			'delete_umi_products',
			'delete_private_umi_products',
			'delete_published_umi_products',
			'delete_others_umi_products',
			'edit_private_umi_products',
			'edit_published_umi_products',
			'edit_umi_deal',
			'read_umi_deal',
			'delete_umi_deal',
			'edit_umi_deals',
			'edit_others_umi_deals',
			'publish_umi_deals',
			'read_private_umi_deals',
			'delete_umi_deals',
			'delete_published_umi_deals',
			'delete_others_umi_deals',
			'edit_published_umi_deals',
			'edit_umi_review',
			'read_umi_review',
			'delete_umi_review',
			'edit_umi_reviews',
			'edit_others_umi_reviews',
			'publish_umi_reviews',
			'read_private_umi_reviews',
			'delete_umi_reviews',
			'delete_private_umi_reviews',
			'delete_published_umi_reviews',
			'delete_others_umi_reviews',
			'edit_private_umi_reviews',
			'edit_published_umi_reviews',
			'manage_umi_marketplace',
		);
	}

	/**
	 * Default role for new users.
	 *
	 * @param int $user_id User ID.
	 */
	public static function set_default_role( $user_id ) {
		$user = new WP_User( $user_id );
		if ( $user->exists() && ! in_array( self::ROLE_SELLER, (array) $user->roles, true ) ) {
			$user->set_role( self::ROLE_BUYER );
		}
	}

	/**
	 * На случай обновления плагина без повторной активации.
	 */
	public static function ensure_admin_marketplace_cap() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( 'manage_umi_marketplace' ) ) {
			$role->add_cap( 'manage_umi_marketplace' );
		}
	}
}
