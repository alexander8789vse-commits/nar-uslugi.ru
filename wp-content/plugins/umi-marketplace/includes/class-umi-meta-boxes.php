<?php
/**
 * Meta boxes for services and products.
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Meta_Boxes
 */
class Umi_Meta_Boxes {

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_boxes' ) );
		add_action( 'save_post_' . Umi_Cpt::SERVICE, array( __CLASS__, 'save_listing' ), 10, 2 );
		add_action( 'save_post_' . Umi_Cpt::PRODUCT, array( __CLASS__, 'save_listing' ), 10, 2 );
	}

	/**
	 * Register meta boxes.
	 */
	public static function add_boxes() {
		add_meta_box(
			'umi_listing_data',
			__( 'Данные объявления', 'umi-marketplace' ),
			array( __CLASS__, 'render_box' ),
			array( Umi_Cpt::SERVICE, Umi_Cpt::PRODUCT ),
			'normal',
			'high'
		);
	}

	/**
	 * Render meta box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_box( $post ) {
		wp_nonce_field( 'umi_save_listing', 'umi_listing_nonce' );

		$price        = get_post_meta( $post->ID, '_umi_price', true );
		$city         = get_post_meta( $post->ID, '_umi_city', true );
		$gallery      = get_post_meta( $post->ID, '_umi_gallery', true );
		$is_product = ( Umi_Cpt::PRODUCT === $post->post_type );
		$is_service = ( Umi_Cpt::SERVICE === $post->post_type );
		$pay_m      = (string) get_post_meta( $post->ID, '_umi_pay_shares', true );
		$intent_m   = self::get_service_intent( (int) $post->ID );
		$author_m   = (string) get_post_meta( $post->ID, '_umi_author_product', true );
		// Товар: только явная «1». Услуга: по умолчанию «можно за доли» (пустое мета = да, как в старых записях).
		$pay_shares = $is_product ? ( '1' === $pay_m ) : ( $is_service && ( '' === $pay_m || '1' === $pay_m ) );
		// Авторский товар: явно «0» — не авторский (только доли); иначе (в т.ч. пустое мета) — как авторский для формы.
		$author_product = ! $is_product || ( '0' !== $author_m );
		if ( ! is_string( $gallery ) ) {
			$gallery = '';
		}
		$ids = array_filter( array_map( 'intval', explode( ',', $gallery ) ) );
		while ( count( $ids ) < 3 ) {
			$ids[] = '';
		}
		$ids = array_slice( $ids, 0, 3 );

		if ( '' === (string) $city && $post->post_author ) {
			$city = (string) get_user_meta( $post->post_author, 'umi_profile_city', true );
		}
		?>
		<p>
			<label for="umi_price"><strong><?php esc_html_e( 'Стоимость (₽)', 'umi-marketplace' ); ?></strong></label><br />
			<input type="number" step="1" min="0" id="umi_price" name="umi_price" value="<?php echo esc_attr( $price ); ?>" class="widefat" />
		</p>
		<p>
			<label for="umi_city"><strong><?php esc_html_e( 'Город', 'umi-marketplace' ); ?></strong></label><br />
			<input type="text" id="umi_city" name="umi_city" value="<?php echo esc_attr( $city ); ?>" class="widefat" />
		</p>
		<?php if ( $is_product ) : ?>
		<p>
			<label for="umi_author_product">
				<input type="checkbox" id="umi_author_product" name="umi_author_product" value="1" <?php checked( $author_product ); ?> />
				<?php esc_html_e( 'Авторский товар', 'umi-marketplace' ); ?>
			</label>
		</p>
		<p class="description"><?php esc_html_e( 'Авторский товар можно продать и за доли на сайте, и за рубли (согласование вне оплаты долями). Любой другой товар в каталоге доступен только к оплате долями на сайте.', 'umi-marketplace' ); ?></p>
		<p>
			<label for="umi_pay_shares">
				<input type="checkbox" id="umi_pay_shares" name="umi_pay_shares" value="1" <?php checked( $pay_shares ); ?> />
				<?php esc_html_e( 'Можно купить за доли (сделка с оплатой долями на сайте)', 'umi-marketplace' ); ?>
			</label>
		</p>
		<script>
		(function(){
			var a=document.getElementById('umi_author_product');
			var p=document.getElementById('umi_pay_shares');
			if(!a||!p)return;
			function sync(){
				var author=a.checked;
				p.disabled=!author;
				if(!author){p.checked=true;}
			}
			a.addEventListener('change',sync);
			sync();
		})();
		</script>
		<?php elseif ( $is_service ) : ?>
		<p>
			<strong><?php esc_html_e( 'Объявление', 'umi-marketplace' ); ?></strong>
		</p>
		<p>
			<label>
				<input type="radio" name="umi_service_intent" value="offer" <?php checked( $intent_m, 'offer' ); ?> />
				<?php esc_html_e( 'Предлагаю услугу', 'umi-marketplace' ); ?>
			</label><br />
			<label>
				<input type="radio" name="umi_service_intent" value="seek" <?php checked( $intent_m, 'seek' ); ?> />
				<?php esc_html_e( 'Ищу услугу', 'umi-marketplace' ); ?>
			</label>
		</p>
		<p>
			<label for="umi_pay_shares">
				<input type="checkbox" id="umi_pay_shares" name="umi_pay_shares" value="1" <?php checked( $pay_shares ); ?> />
				<?php esc_html_e( 'Можно купить за доли (сделка с оплатой долями на сайте)', 'umi-marketplace' ); ?>
			</label>
		</p>
		<?php endif; ?>
		<p><strong><?php esc_html_e( 'Галерея (до 3 ID вложений)', 'umi-marketplace' ); ?></strong></p>
		<?php foreach ( $ids as $i => $aid ) : ?>
			<p>
				<label><?php echo esc_html( sprintf( /* translators: %d image index */ __( 'Фото %d (ID файла)', 'umi-marketplace' ), $i + 1 ) ); ?></label><br />
				<input type="number" min="0" name="umi_gallery[]" value="<?php echo $aid ? (int) $aid : ''; ?>" class="widefat" />
			</p>
		<?php endforeach; ?>
		<p class="description"><?php esc_html_e( 'Загрузите файлы в Медиатеке и укажите их числовой ID.', 'umi-marketplace' ); ?></p>
		<?php
	}

	/**
	 * Save listing meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post.
	 */
	public static function save_listing( $post_id, $post ) {
		if ( ! isset( $_POST['umi_listing_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['umi_listing_nonce'] ) ), 'umi_save_listing' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$price = isset( $_POST['umi_price'] ) ? (float) $_POST['umi_price'] : 0;
		$city  = isset( $_POST['umi_city'] ) ? sanitize_text_field( wp_unslash( $_POST['umi_city'] ) ) : '';

		$gallery_raw = isset( $_POST['umi_gallery'] ) && is_array( $_POST['umi_gallery'] ) ? wp_unslash( $_POST['umi_gallery'] ) : array();
		$gallery_ids = array();
		foreach ( $gallery_raw as $g ) {
			$g = (int) $g;
			if ( $g > 0 ) {
				$gallery_ids[] = $g;
			}
			if ( count( $gallery_ids ) >= 3 ) {
				break;
			}
		}
		$pay = null;
		if ( Umi_Cpt::PRODUCT === $post->post_type ) {
			$author = ! empty( $_POST['umi_author_product'] );
			update_post_meta( $post_id, '_umi_author_product', $author ? '1' : '0' );
			if ( ! $author ) {
				$pay = true;
			} else {
				$pay = ! empty( $_POST['umi_pay_shares'] );
			}
		} elseif ( Umi_Cpt::SERVICE === $post->post_type ) {
			$pay = ! empty( $_POST['umi_pay_shares'] );
			$intent_raw = isset( $_POST['umi_service_intent'] ) ? wp_unslash( $_POST['umi_service_intent'] ) : 'offer';
			self::set_service_intent( $post_id, $intent_raw );
		}
		self::set_listing_meta( $post_id, (float) $price, $city, $gallery_ids, 0, 0, $pay );
	}

	/**
	 * Сохранение полей объявления (админка и фронт).
	 *
	 * @param int        $post_id        Post ID.
	 * @param float      $price         Price.
	 * @param string     $city          City.
	 * @param int[]      $gallery_ids   Up to 3 attachment IDs.
	 * @param int        $thumbnail_id  Featured image attachment (0 to skip).
	 * @param int        $for_user_id   Проверка владельца вложений (0 = current user).
	 * @param bool|null  $pay_shares     Услуга/товар: разрешить оплату долями; null — не менять поле.
	 */
	public static function set_listing_meta( $post_id, $price, $city, $gallery_ids, $thumbnail_id = 0, $for_user_id = 0, $pay_shares = null ) {
		$price = max( 0, (int) round( (float) $price ) );
		update_post_meta( $post_id, '_umi_price', $price );
		$city = is_string( $city ) ? sanitize_text_field( $city ) : '';
		$city = self::normalize_listing_city( $city );
		update_post_meta( $post_id, '_umi_city', $city );

		$post = get_post( $post_id );
		if ( $post && null !== $pay_shares && ( Umi_Cpt::PRODUCT === $post->post_type || Umi_Cpt::SERVICE === $post->post_type ) ) {
			update_post_meta( $post_id, '_umi_pay_shares', $pay_shares ? '1' : '0' );
		}

		$out = array();
		foreach ( (array) $gallery_ids as $g ) {
			$g = (int) $g;
			if ( $g < 1 ) {
				continue;
			}
			$post = get_post( $g );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				continue;
			}
			$uid = (int) $for_user_id ? (int) $for_user_id : (int) get_current_user_id();
			if ( $uid && (int) $post->post_author !== $uid && ! current_user_can( 'edit_post', $g ) ) {
				continue;
			}
			$out[] = $g;
			if ( count( $out ) >= 3 ) {
				break;
			}
		}
		update_post_meta( $post_id, '_umi_gallery', implode( ',', $out ) );

		$tid = (int) $thumbnail_id;
		if ( $tid > 0 ) {
			$att = get_post( $tid );
			if ( $att && 'attachment' === $att->post_type ) {
				$uid = (int) $for_user_id ? (int) $for_user_id : (int) get_current_user_id();
				$mime = get_post_mime_type( $tid );
				if ( $mime && 0 === strpos( $mime, 'image/' ) && ( (int) $att->post_author === $uid || current_user_can( 'edit_post', $tid ) ) ) {
					set_post_thumbnail( $post_id, $tid );
				}
			}
		}
	}

	/**
	 * ID изображений для карточки: обложка, затем галерея, не более 3.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	public static function get_listing_image_ids( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id < 1 ) {
			return array();
		}
		$thumb = (int) get_post_thumbnail_id( $post_id );
		$g     = get_post_meta( $post_id, '_umi_gallery', true );
		$gids  = array_filter( array_map( 'intval', explode( ',', (string) $g ) ) );
		$out   = array();
		if ( $thumb > 0 ) {
			$out[] = $thumb;
		}
		foreach ( $gids as $gid ) {
			if ( $gid < 1 || in_array( $gid, $out, true ) ) {
				continue;
			}
			$out[] = $gid;
			if ( count( $out ) >= 3 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Настроенная оплата сделки долями: для услуг и товаров — мета _umi_pay_shares;
	 * у услуг без мета (старые записи) — по-прежнему «можно за доли».
	 *
	 * @param int $post_id Listing post ID.
	 * @return bool
	 */
	public static function listing_allows_shares_payment( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return false;
		}
		$m = (string) get_post_meta( $post->ID, '_umi_pay_shares', true );
		if ( Umi_Cpt::SERVICE === $post->post_type ) {
			if ( '' === $m ) {
				return true;
			}
			return '1' === $m;
		}
		if ( Umi_Cpt::PRODUCT === $post->post_type ) {
			return '1' === $m;
		}
		return false;
	}

	/**
	 * Нормализация строки города (пробелы) для meta и фильтра.
	 *
	 * @param string $city City.
	 * @return string
	 */
	public static function normalize_listing_city( $city ) {
		$city = is_string( $city ) ? trim( $city ) : '';
		if ( '' === $city ) {
			return '';
		}
		return (string) preg_replace( '/\s+/u', ' ', $city );
	}

	/**
	 * Фрагмент meta_query для каталога услуг: способ расчёта.
	 *
	 * @param string $mode Пусто / all | rub | shares | both.
	 * @return array|null
	 */
	public static function services_payment_filter_meta_query( $mode ) {
		$mode = is_string( $mode ) ? sanitize_key( $mode ) : '';
		if ( ! in_array( $mode, array( 'rub', 'shares', 'both' ), true ) ) {
			return null;
		}
		if ( 'rub' === $mode ) {
			return array(
				'key'   => '_umi_pay_shares',
				'value' => '0',
			);
		}
		if ( 'shares' === $mode ) {
			return array(
				'relation' => 'OR',
				array(
					'key'   => '_umi_pay_shares',
					'value' => '1',
				),
				array(
					'key'     => '_umi_pay_shares',
					'compare' => 'NOT EXISTS',
				),
			);
		}
		// both: есть цена в ₽ и оплата долями на сайте (в т.ч. старые услуги без мета).
		return array(
			'relation' => 'AND',
			array(
				'key'     => '_umi_price',
				'value'   => 0,
				'type'    => 'NUMERIC',
				'compare' => '>',
			),
			array(
				'relation' => 'OR',
				array(
					'key'   => '_umi_pay_shares',
					'value' => '1',
				),
				array(
					'key'     => '_umi_pay_shares',
					'compare' => 'NOT EXISTS',
				),
			),
		);
	}

	/**
	 * Фрагмент meta_query для каталога товаров: способ оплаты (для товара «за доли» только явная «1»).
	 *
	 * @param string $mode rub | shares | both.
	 * @return array|null
	 */
	public static function products_payment_filter_meta_query( $mode ) {
		$mode = is_string( $mode ) ? sanitize_key( $mode ) : '';
		if ( ! in_array( $mode, array( 'rub', 'shares', 'both' ), true ) ) {
			return null;
		}
		if ( 'rub' === $mode ) {
			return array(
				'relation' => 'OR',
				array(
					'key'   => '_umi_pay_shares',
					'value' => '0',
				),
				array(
					'key'     => '_umi_pay_shares',
					'compare' => 'NOT EXISTS',
				),
			);
		}
		if ( 'shares' === $mode ) {
			return array(
				'key'   => '_umi_pay_shares',
				'value' => '1',
			);
		}
		return array(
			'relation' => 'AND',
			array(
				'key'     => '_umi_price',
				'value'   => 0,
				'type'    => 'NUMERIC',
				'compare' => '>',
			),
			array(
				'key'   => '_umi_pay_shares',
				'value' => '1',
			),
		);
	}

	/**
	 * Фильтр каталога товаров: только «авторские» (не явно неавторские).
	 * Явно неавторский — meta _umi_author_product = '0'; пустое мета — старые записи, считаем авторскими.
	 *
	 * @param bool $only_author Only author-eligible products.
	 * @return array|null
	 */
	public static function products_author_filter_meta_query( $only_author ) {
		if ( ! $only_author ) {
			return null;
		}
		return array(
			'relation' => 'OR',
			array(
				'key'     => '_umi_author_product',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'   => '_umi_author_product',
				'value' => '1',
			),
		);
	}

	/**
	 * Намерение по услуге: предложение или поиск.
	 *
	 * @param mixed $raw Значение из формы (offer|seek).
	 * @return string offer|seek
	 */
	public static function sanitize_service_intent( $raw ) {
		$key = is_string( $raw ) ? sanitize_key( $raw ) : '';
		return ( 'seek' === $key ) ? 'seek' : 'offer';
	}

	/**
	 * Сохранить намерение (только CPT услуги).
	 *
	 * @param int    $post_id Post ID.
	 * @param mixed  $raw     Raw POST value.
	 */
	public static function set_service_intent( $post_id, $raw ) {
		$intent = self::sanitize_service_intent( $raw );
		update_post_meta( (int) $post_id, '_umi_service_intent', $intent );
	}

	/**
	 * Получить намерение услуги (старые записи без мета считаются «предложение»).
	 *
	 * @param int $post_id Post ID.
	 * @return string offer|seek
	 */
	public static function get_service_intent( $post_id ) {
		$v = (string) get_post_meta( (int) $post_id, '_umi_service_intent', true );
		return ( 'seek' === $v ) ? 'seek' : 'offer';
	}

	/**
	 * Фильтр каталога услуг: только предложения или только поиск.
	 *
	 * @param string $mode '', 'offer', 'seek'. Пустое — без фильтра.
	 * @return array|null
	 */
	public static function services_intent_filter_meta_query( $mode ) {
		$mode = is_string( $mode ) ? sanitize_key( $mode ) : '';
		if ( ! in_array( $mode, array( 'offer', 'seek' ), true ) ) {
			return null;
		}
		if ( 'seek' === $mode ) {
			return array(
				'key'   => '_umi_service_intent',
				'value' => 'seek',
			);
		}
		return array(
			'relation' => 'OR',
			array(
				'key'   => '_umi_service_intent',
				'value' => 'offer',
			),
			array(
				'key'     => '_umi_service_intent',
				'compare' => 'NOT EXISTS',
			),
		);
	}
}
