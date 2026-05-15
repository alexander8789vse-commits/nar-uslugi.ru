<?php
/**
 * Custom post types.
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Cpt
 */
class Umi_Cpt {

	const SERVICE = 'umi_service';
	const PRODUCT = 'umi_product';
	const DEAL    = 'umi_deal';
	const REVIEW  = 'umi_review';

	/**
	 * Register CPTs.
	 */
	public static function register() {
		$labels_service = array(
			'name'          => __( 'Услуги', 'umi-marketplace' ),
			'singular_name' => __( 'Услуга', 'umi-marketplace' ),
			'menu_name'     => __( 'Услуги', 'umi-marketplace' ),
			'add_new'       => __( 'Добавить услугу', 'umi-marketplace' ),
			'add_new_item'  => __( 'Новая услуга', 'umi-marketplace' ),
			'edit_item'     => __( 'Редактировать услугу', 'umi-marketplace' ),
		);

		register_post_type(
			self::SERVICE,
			array(
				'labels'              => $labels_service,
				'public'              => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_icon'           => 'dashicons-hammer',
				'has_archive'         => false,
				'rewrite'             => array( 'slug' => 'usluga' ),
				'supports'            => array( 'title', 'editor', 'thumbnail', 'author' ),
				'capability_type'     => array( 'umi_service', 'umi_services' ),
				'map_meta_cap'        => true,
				'exclude_from_search' => false,
			)
		);

		$labels_product = array(
			'name'          => __( 'Товары', 'umi-marketplace' ),
			'singular_name' => __( 'Товар', 'umi-marketplace' ),
			'menu_name'     => __( 'Товары', 'umi-marketplace' ),
			'add_new'       => __( 'Добавить товар', 'umi-marketplace' ),
		);

		register_post_type(
			self::PRODUCT,
			array(
				'labels'              => $labels_product,
				'public'              => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_icon'           => 'dashicons-cart',
				'has_archive'         => false,
				'rewrite'             => array( 'slug' => 'tovar' ),
				'supports'            => array( 'title', 'editor', 'thumbnail', 'author' ),
				'capability_type'     => array( 'umi_product', 'umi_products' ),
				'map_meta_cap'        => true,
				'exclude_from_search' => false,
			)
		);

		$labels_deal = array(
			'name'          => __( 'Сделки', 'umi-marketplace' ),
			'singular_name' => __( 'Сделка', 'umi-marketplace' ),
			'menu_name'     => __( 'Сделки', 'umi-marketplace' ),
		);

		register_post_type(
			self::DEAL,
			array(
				'labels'          => $labels_deal,
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . self::SERVICE,
				'menu_icon'       => 'dashicons-clipboard',
				'capability_type' => array( 'umi_deal', 'umi_deals' ),
				'map_meta_cap'    => true,
				'supports'        => array( 'title' ),
			)
		);

		$labels_review = array(
			'name'          => __( 'Отзывы', 'umi-marketplace' ),
			'singular_name' => __( 'Отзыв', 'umi-marketplace' ),
		);

		register_post_type(
			self::REVIEW,
			array(
				'labels'          => $labels_review,
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . self::SERVICE,
				'capability_type' => array( 'umi_review', 'umi_reviews' ),
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor' ),
			)
		);
	}
}
