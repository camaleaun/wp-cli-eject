<?php

namespace Camaleaun\Eject\Checksums;

use Camaleaun\Eject\Cache;
use Camaleaun\Eject\Http;
use Camaleaun\Eject\Inventory;
use Camaleaun\Eject\Package;
use Camaleaun\Eject\Site;

/**
 * Same source and scope as `wp core verify-checksums`: wp-content/ and
 * wp-config.php are never part of core.
 */
class Core {

	/** @var array<string,string[]> Checksums core publishes for wp-content/ (bundled themes and plugins). */
	private static $bundled = array();

	const API = 'https://api.wordpress.org/core/checksums/1.0/?version=%s&locale=%s';

	public static function verify( Package $core, Site $site ) {
		$checksums = self::fetch( $core->version, $site->locale );
		if ( null === $checksums && 'en_US' !== $site->locale ) {
			$core->notes[] = "no checksums for locale {$site->locale}, compared with en_US";
			$checksums     = self::fetch( $core->version, 'en_US' );
		}
		if ( null === $checksums ) {
			$core->unverifiable = true;
			$core->notes[]      = "could not get the checksums of WordPress {$core->version}" . ( '' !== Http::$last_error ? ': ' . Http::$last_error : '' );
			return;
		}

		$expected = array();
		foreach ( $checksums as $file => $md5 ) {
			if ( 0 !== strpos( $file, 'wp-content/' ) ) {
				$expected[ $file ] = Compare::hashes( $md5 );
			} else {
				self::$bundled[ substr( $file, strlen( 'wp-content/' ) ) ] = Compare::hashes( $md5 );
			}
		}
		$core->download_url = 'https://wordpress.org/wordpress-' . $core->version . '.zip';
		$core->source       = Package::SOURCE_ORG;

		Compare::run( $core, $expected, 'md5', self::on_disk( $site ) );
	}

	/** @return array<string,string>|null */
	private static function fetch( $version, $locale ) {
		// A release's checksums never change: cache them (WP-CLI's cache, shared with wp core verify-checksums users).
		$key    = 'core-checksums/' . preg_replace( '/[^a-z0-9._-]/', '-', strtolower( $version . '-' . $locale ) ) . '.json';
		$cached = Cache::get( $key );
		if ( null !== $cached ) {
			$body = json_decode( (string) file_get_contents( $cached ), true );
			@unlink( $cached ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! empty( $body['checksums'] ) && is_array( $body['checksums'] ) ) {
				return $body['checksums'];
			}
		}
		list( $status, $body ) = Http::json( sprintf( self::API, rawurlencode( $version ), rawurlencode( $locale ) ) );
		if ( 200 !== $status || empty( $body['checksums'] ) || ! is_array( $body['checksums'] ) ) {
			return null;
		}
		$file = tempnam( \WP_CLI\Utils\get_temp_dir(), 'wp-eject-' );
		file_put_contents( $file, json_encode( $body ) );
		Cache::put( $key, $file );
		@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $body['checksums'];
	}

	/**
	 * Files that look like core: wp-admin/, wp-includes/ and the root files core
	 * ships. Anything else in the root (wp-config.php, .htaccess, AGENTS.md…) is
	 * not core and is left alone.
	 *
	 * @return string[]
	 */
	private static function on_disk( Site $site ) {
		$files = array();
		foreach ( array( 'wp-admin', 'wp-includes' ) as $dir ) {
			foreach ( Inventory::files( $site->root . $dir ) as $file ) {
				$files[] = $dir . '/' . $file;
			}
		}
		foreach ( (array) scandir( $site->root ) as $entry ) {
			if ( ! is_file( $site->root . $entry ) || in_array( $entry, array( 'wp-config.php' ), true ) ) {
				continue;
			}
			if ( preg_match( '/^wp-[^\/]+\.php$/', $entry ) || in_array( $entry, array( 'index.php', 'xmlrpc.php', 'license.txt', 'readme.html' ), true ) ) {
				$files[] = $entry;
			}
		}
		return $files;
	}

	/**
	 * Whether a single file under wp-content matches the copy core ships (e.g. Hello Dolly).
	 *
	 * @param string $relative Path under wp-content, e.g. "plugins/hello.php".
	 */
	public static function bundled_file_matches( $file, $relative ) {
		return isset( self::$bundled[ $relative ] ) && is_file( $file ) && in_array( md5_file( $file ), self::$bundled[ $relative ], true );
	}

	/**
	 * Top-level items the core zip puts in wp-content (e.g. "plugins/akismet",
	 * "plugins/hello.php", "themes/twentytwentyfive").
	 *
	 * @return string[]
	 */
	public static function bundled_items() {
		$items = array();
		foreach ( array_keys( self::$bundled ) as $file ) {
			$parts = explode( '/', $file );
			if ( count( $parts ) >= 2 && in_array( $parts[0], array( 'plugins', 'themes' ), true ) && 'index.php' !== $parts[1] ) {
				$items[ $parts[0] . '/' . $parts[1] ] = true;
			}
		}
		return array_keys( $items );
	}

	/** @var array<string,array<string,string[]>> Bundled checksums of older releases, by version. */
	private static $older = array();

	/** Major branches to look back when a bundled copy predates the current core. */
	const LOOK_BACK = 8;

	/**
	 * Default themes and plugins ship inside the core zip too. Core releases
	 * update them (e.g. "Tested up to") without bumping their version, and a core
	 * update does not overwrite a default theme that is already installed. So a
	 * file the directory zip does not match is also compared with the same file
	 * in the copy bundled with this core and with the latest release of each
	 * earlier major branch (looked up only while some file is still unmatched).
	 *
	 * Per file, like wp plugin verify-checksums, which accepts any hash wordpress.org
	 * ever published for a file: each file only needs to match one official copy,
	 * and a file that matches none stays modified.
	 *
	 * @return bool True when at least one file matched a bundled copy (results updated).
	 */
	public static function verify_bundled( Package $package, $core_version ) {
		$prefix = $package->type . 's/' . $package->slug . '/';
		$mine   = array();
		foreach ( self::$bundled as $file => $hashes ) {
			if ( 0 === strpos( $file, $prefix ) ) {
				$mine[ $file ] = true;
				break;
			}
		}
		if ( ! $mine ) {
			return false; // Not a bundled package.
		}

		$remaining = $package->modified;
		$matched   = array();
		$sources   = array();
		$versions  = array( $core_version => self::$bundled );
		foreach ( self::older_versions( $core_version ) as $version ) {
			$versions[ $version ] = null; // Fetched only if needed.
		}
		foreach ( $versions as $version => $bundled ) {
			if ( ! $remaining ) {
				break;
			}
			if ( null === $bundled ) {
				$bundled = self::bundled_of( $version );
			}
			foreach ( $remaining as $index => $file ) {
				$accepted = isset( $bundled[ $prefix . $file ] ) ? $bundled[ $prefix . $file ] : array();
				if ( $accepted && in_array( md5_file( $package->path . '/' . $file ), $accepted, true ) ) {
					$matched[]           = $file;
					$sources[ $version ] = isset( $sources[ $version ] ) ? $sources[ $version ] + 1 : 1;
					unset( $remaining[ $index ] );
				}
			}
		}
		if ( ! $matched ) {
			return false;
		}

		$from_directory    = count( $package->verified );
		$package->verified = array_merge( $package->verified, $matched );
		$package->modified = array_values( $remaining );
		$parts             = array();
		if ( $from_directory ) {
			$parts[] = "{$from_directory} match the directory zip";
		}
		foreach ( $sources as $version => $count ) {
			$parts[] = "{$count} match WordPress {$version}'s bundled copy" . ( $version === $core_version ? '' : ' (core updates do not refresh an installed default ' . $package->type . '; the blueprint installs the directory zip of the same version)' );
		}
		$package->notes[] = 'files: ' . implode( ', ', $parts );
		return true;
	}

	/** @return array<string,string[]> */
	private static function bundled_of( $version ) {
		if ( ! isset( self::$older[ $version ] ) ) {
			self::$older[ $version ] = array();
			foreach ( (array) self::fetch( $version, 'en_US' ) as $file => $md5 ) {
				if ( 0 === strpos( $file, 'wp-content/' ) ) {
					self::$older[ $version ][ substr( $file, strlen( 'wp-content/' ) ) ] = Compare::hashes( $md5 );
				}
			}
		}
		return self::$older[ $version ];
	}

	/**
	 * Latest release of each major branch before the current one, newest first.
	 *
	 * @return string[]
	 */
	private static function older_versions( $core_version ) {
		list( $status, $versions ) = Http::json( 'https://api.wordpress.org/core/stable-check/1.0/' );
		if ( 200 !== $status || ! is_array( $versions ) ) {
			return array();
		}
		$current = implode( '.', array_slice( explode( '.', $core_version ), 0, 2 ) );
		$latest  = array();
		foreach ( array_keys( $versions ) as $version ) {
			$major = implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );
			if ( version_compare( $major, $current, '<' ) && ( ! isset( $latest[ $major ] ) || version_compare( $version, $latest[ $major ], '>' ) ) ) {
				$latest[ $major ] = $version;
			}
		}
		uksort(
			$latest,
			function ( $a, $b ) {
				return version_compare( $b, $a );
			}
		);
		return array_slice( array_values( $latest ), 0, self::LOOK_BACK );
	}
}
