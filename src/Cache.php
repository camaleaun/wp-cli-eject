<?php

namespace Camaleaun\Eject;

use WP_CLI;

/**
 * The downloads that never change for a version, kept in WP-CLI's own cache
 * (~/.wp-cli/cache, WP_CLI_CACHE_DIR). The zip keys are the ones
 * `wp plugin install` and `wp theme install` use, so both tools share them.
 */
class Cache {

	/** @var bool False with --no-cache: nothing is read or written. */
	public static $enabled = true;

	/** Keys only accept a-z 0-9 . _ - (and a folder). */
	public static function key( $type, $slug, $version, $extension ) {
		return $type . '/' . preg_replace( '/[^a-z0-9._-]/', '-', strtolower( $slug . '-' . $version ) ) . '.' . $extension;
	}

	/** @return string|null Path of a temporary copy of the cached file. */
	public static function get( $key ) {
		$cache = self::enabled() ? WP_CLI::get_cache() : null;
		if ( ! $cache || ! $cache->has( $key ) ) {
			return null;
		}
		$copy = tempnam( \WP_CLI\Utils\get_temp_dir(), 'wp-eject-' );
		return $cache->export( $key, $copy ) ? $copy : null;
	}

	public static function put( $key, $file ) {
		$cache = self::enabled() ? WP_CLI::get_cache() : null;
		if ( $cache ) {
			$cache->import( $key, $file );
		}
	}

	public static function forget( $key ) {
		$cache = self::enabled() ? WP_CLI::get_cache() : null;
		if ( $cache ) {
			$cache->remove( $key );
		}
	}

	private static function enabled() {
		return self::$enabled;
	}
}
