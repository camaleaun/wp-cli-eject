<?php

namespace Camaleaun\Eject;

use WP_CLI\Utils;

/**
 * Small wrapper around WP-CLI's HTTP client (works before WordPress loads and
 * inside Studio's PHP WASM).
 */
class Http {

	/** @var string Message of the last failed request (status 0). */
	public static $last_error = '';

	/**
	 * GET a URL and decode its JSON body.
	 *
	 * @return array{0:int,1:mixed} [status code, decoded body or null].
	 */
	public static function json( $url ) {
		$response = self::get( $url );
		if ( 200 !== $response['status'] ) {
			return array( $response['status'], null );
		}
		return array( 200, json_decode( $response['body'], true ) );
	}

	/**
	 * GET a URL into a temporary file.
	 *
	 * @return array{0:int,1:string|null} [status code, file path or null].
	 */
	public static function download( $url ) {
		$file     = tempnam( Utils\get_temp_dir(), 'wp-eject-' );
		$response = self::get( $url, array( 'filename' => $file ) );
		if ( 200 !== $response['status'] ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return array( $response['status'], null );
		}
		return array( 200, $file );
	}

	/**
	 * @return array{status:int,body:string}
	 */
	private static function get( $url, $options = array() ) {
		$options = array_merge(
			array(
				'timeout'       => 120,
				'halt_on_error' => false,
			),
			$options
		);
		try {
			$response = Utils\http_request( 'GET', $url, null, array(), $options );
		} catch ( \Throwable $e ) {
			self::$last_error = $e->getMessage();
			return array(
				'status' => 0,
				'body'   => $e->getMessage(),
			);
		}
		return array(
			'status' => (int) $response->status_code,
			'body'   => (string) $response->body,
		);
	}
}
