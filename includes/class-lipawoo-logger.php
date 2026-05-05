<?php
/**
 * LipaWoo Logger
 *
 * @package LipaWoo
 * @license GPL-2.0-or-later
 * @link    https://mpesa-woocommerce.wasmer.app
 */

defined( 'ABSPATH' ) || exit;

class LipaWoo_Logger {
	private static $logger = null;
	private const SOURCE = 'lipawoo';

	public static function log( $message, $level = 'info', $context = [] ) {
		$settings = get_option( 'woocommerce_lipawoo_settings', [] );
		if ( ( $settings['debug'] ?? 'no' ) !== 'yes' ) return;
		if ( null === self::$logger ) self::$logger = wc_get_logger();
		$context['source'] = self::SOURCE;
		if ( ! empty( $context['data'] ) ) {
			$message .= ' | ' . wp_json_encode( $context['data'] );
			unset( $context['data'] );
		}
		switch ( $level ) {
			case 'error':   self::$logger->error( $message, $context ); break;
			case 'warning': self::$logger->warning( $message, $context ); break;
			case 'debug':   self::$logger->debug( $message, $context ); break;
			default:        self::$logger->info( $message, $context );
		}
	}

	public static function info( $msg, $ctx = [] )    { self::log( $msg, 'info', $ctx ); }
	public static function error( $msg, $ctx = [] )   { self::log( $msg, 'error', $ctx ); }
	public static function debug( $msg, $ctx = [] )   { self::log( $msg, 'debug', $ctx ); }
	public static function warning( $msg, $ctx = [] ) { self::log( $msg, 'warning', $ctx ); }
}
