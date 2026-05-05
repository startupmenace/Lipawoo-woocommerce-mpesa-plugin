<?php
/**
 * LipaWoo Lite — Sandbox only API client
 * Production is locked to prompt upgrade.
 *
 * @package LipaWoo
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

class LipaWoo_API {

	private const AUTH_PATH      = '/oauth/v1/generate?grant_type=client_credentials';
	private const STK_PUSH_PATH  = '/mpesa/stkpush/v1/processrequest';
	private const STK_QUERY_PATH = '/mpesa/stkpushquery/v1/query';
	private const ACCOUNT_REF_MAX = 12;
	private const TX_DESC_MAX     = 13;
	private const HTTP_TIMEOUT    = 45;

	private $consumer_key;
	private $consumer_secret;
	private $shortcode;
	private $passkey;
	private $environment;
	private $base_url;
	private $access_token = null;
	private $token_expiry = 0;

	public function __construct( $consumer_key, $consumer_secret, $shortcode, $passkey, $environment = 'sandbox' ) {
		$this->consumer_key    = $consumer_key;
		$this->consumer_secret = $consumer_secret;
		$this->shortcode       = $shortcode;
		$this->passkey         = $passkey;
		$this->environment     = $environment;
		$this->base_url        = LipaWoo::get_api_base_url( $environment );
	}

	public function get_access_token() {
		if ( $this->access_token && time() < $this->token_expiry ) return $this->access_token;

		$cache_key = 'lipawoo_token_' . md5( $this->consumer_key );
		$cached    = get_transient( $cache_key );
		if ( $cached ) { $this->access_token = $cached; return $cached; }

		$response = wp_remote_get( $this->base_url . self::AUTH_PATH, [
			'headers'   => [ 'Authorization' => 'Basic ' . base64_encode( $this->consumer_key . ':' . $this->consumer_secret ), 'Accept' => '*/*' ],
			'timeout'   => self::HTTP_TIMEOUT,
			'sslverify' => true,
		] );

		if ( is_wp_error( $response ) ) return new WP_Error( 'token_error', 'Cannot reach Safaricom: ' . $response->get_error_message() );

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$token = $body['access_token'] ?? $body['accessToken'] ?? null;

		if ( 200 !== $code || empty( $token ) ) {
			$msg = $body['error_description'] ?? $body['errorMessage'] ?? $body['error'] ?? "HTTP {$code}";
			return new WP_Error( 'token_error', $msg );
		}

		$ttl = max( 60, (int) ( $body['expires_in'] ?? 3600 ) ) - 30;
		$this->access_token = $token;
		$this->token_expiry = time() + $ttl;
		set_transient( $cache_key, $token, $ttl );
		return $token;
	}

	public function stk_push( $phone, $amount, $account_reference, $callback_url, $description = 'Payment', $transaction_type = 'CustomerPayBillOnline', $party_b_override = null ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) return $token;

		$timestamp   = gmdate( 'YmdHis' );
		$password    = base64_encode( $this->shortcode . $this->passkey . $timestamp );
		$party_b     = $party_b_override ?? $this->shortcode;
		$account_ref = substr( $account_reference, 0, self::ACCOUNT_REF_MAX );
		$tx_desc     = substr( $description, 0, self::TX_DESC_MAX );

		$payload = [
			'BusinessShortCode' => $this->shortcode,
			'Password'          => $password,
			'Timestamp'         => $timestamp,
			'TransactionType'   => $transaction_type,
			'Amount'            => (int) ceil( $amount ),
			'PartyA'            => $phone,
			'PartyB'            => $party_b,
			'PhoneNumber'       => $phone,
			'CallBackURL'       => $callback_url,
			'AccountReference'  => $account_ref,
			'TransactionDesc'   => $tx_desc,
		];

		LipaWoo_Logger::info( "STK Push → ref={$account_ref} type={$transaction_type} partyB={$party_b}" );
		$result = $this->post( self::STK_PUSH_PATH, $payload, $token );
		if ( is_wp_error( $result ) ) return $result;
		if ( empty( $result['CheckoutRequestID'] ) ) {
			$error = $result['errorMessage'] ?? $result['ResponseDescription'] ?? 'STK Push failed';
			return new WP_Error( 'stk_push_error', $error );
		}
		return $result;
	}

	public function stk_query( $checkout_request_id ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) return $token;

		$timestamp = gmdate( 'YmdHis' );
		$password  = base64_encode( $this->shortcode . $this->passkey . $timestamp );

		$payload = [
			'BusinessShortCode' => $this->shortcode,
			'Password'          => $password,
			'Timestamp'         => $timestamp,
			'CheckoutRequestID' => $checkout_request_id,
		];

		return $this->post( self::STK_QUERY_PATH, $payload, $token );
	}

	public function clear_token_cache() {
		$this->access_token = null;
		$this->token_expiry = 0;
		delete_transient( 'lipawoo_token_' . md5( $this->consumer_key ) );
	}

	public static function format_phone( $phone ) {
		$phone = preg_replace( '/\D/', '', $phone );
		if ( substr( $phone, 0, 1 ) === '0' ) return '254' . substr( $phone, 1 );
		if ( substr( $phone, 0, 4 ) === '+254' ) return substr( $phone, 1 );
		if ( substr( $phone, 0, 3 ) !== '254' ) return '254' . $phone;
		return $phone;
	}

	public static function validate_phone( $phone ) {
		return (bool) preg_match( '/^254[0-9]{9}$/', self::format_phone( $phone ) );
	}

	private function post( $path, $payload, $token ) {
		$response = wp_remote_post( $this->base_url . $path, [
			'headers'   => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
			'body'      => wp_json_encode( $payload ),
			'timeout'   => self::HTTP_TIMEOUT,
			'sslverify' => true,
		] );

		if ( is_wp_error( $response ) ) return new WP_Error( 'http_error', 'Network error: ' . $response->get_error_message() );

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );

		LipaWoo_Logger::debug( "POST {$path} [{$code}]: {$raw}" );

		if ( ! is_array( $body ) ) return new WP_Error( 'invalid_response', "Safaricom returned an unexpected response (HTTP {$code})." );
		if ( $code >= 400 ) {
			$error_code = $body['errorCode'] ?? '';
			$actionable = [
				'500.001.1001' => 'Merchant does not exist — check your Business Shortcode and Passkey.',
				'500.001.1049' => 'Duplicate request — wait 30 seconds and try again.',
			];
			$msg = $actionable[ $error_code ] ?? ( $body['errorMessage'] ?? $body['ResponseDescription'] ?? "HTTP {$code}" );
			return new WP_Error( 'api_error', $msg );
		}
		return $body;
	}
}
