<?php
/**
 * User share balance (user meta).
 *
 * @package UmiMarketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Umi_Balance
 */
class Umi_Balance {

	const META_KEY = 'umi_share_balance';

	/**
	 * Get balance.
	 *
	 * @param int $user_id User ID.
	 * @return string Decimal string.
	 */
	public static function get( $user_id ) {
		$v = get_user_meta( $user_id, self::META_KEY, true );
		if ( '' === $v || null === $v ) {
			return '0';
		}
		return (string) $v;
	}

	/**
	 * Set balance exactly.
	 *
	 * @param int    $user_id User ID.
	 * @param string $amount Decimal string.
	 */
	public static function set( $user_id, $amount ) {
		update_user_meta( $user_id, self::META_KEY, (string) max( 0, (int) self::normalize( $amount ) ) );
	}

	/**
	 * Add shares (can be negative).
	 *
	 * @param int    $user_id User ID.
	 * @param string $delta Decimal string.
	 * @return string New balance.
	 */
	public static function add( $user_id, $delta ) {
		$cur = (int) self::normalize( self::get( $user_id ) );
		$d   = (int) self::normalize( $delta );
		$new = max( 0, $cur + $d );
		$s   = (string) $new;
		self::set( $user_id, $s );
		return $s;
	}

	/**
	 * Целые доли (внутри и в выводе без дробной части). Допускает отрицательные значения (дельты).
	 *
	 * @param string|float $n Value.
	 * @return string
	 */
	public static function normalize( $n ) {
		if ( ! is_string( $n ) ) {
			$n = (string) $n;
		}
		$n = str_replace( ',', '.', $n );
		if ( ! is_numeric( $n ) ) {
			return '0';
		}
		return (string) (int) round( (float) $n );
	}
}
