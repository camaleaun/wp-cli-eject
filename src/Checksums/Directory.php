<?php

namespace Camaleaun\Eject\Checksums;

use Camaleaun\Eject\Cache;
use Camaleaun\Eject\Http;
use Camaleaun\Eject\Inventory;
use Camaleaun\Eject\Package;

/**
 * Plugins and themes from the wordpress.org directory.
 *
 * Plugins: the checksum files `wp plugin verify-checksums` uses. When a version
 * has none, or for themes (WP-CLI has no `theme verify-checksums`), the zip of
 * that exact version is downloaded and hashed file by file.
 *
 * A package that is not in the directory, or whose installed version can no
 * longer be downloaded, is external: it is packed, never deleted unverified.
 */
class Directory {

	const PLUGIN_CHECKSUMS = 'https://downloads.wordpress.org/plugin-checksums/%s/%s.json';
	const PLUGIN_INFO      = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=%s&request[fields][versions]=1';
	const PLUGIN_ZIP       = 'https://downloads.wordpress.org/plugin/%s.%s.zip';
	const THEME_INFO       = 'https://api.wordpress.org/themes/info/1.2/?action=theme_information&request[slug]=%s&request[fields][versions]=1';
	const THEME_ZIP        = 'https://downloads.wordpress.org/theme/%s.%s.zip';

	public static function verify( Package $package ) {
		if ( '' === $package->version ) {
			self::external( $package, 'no Version header' );
			return;
		}
		if ( self::updates_elsewhere( $package ) ) {
			self::external( $package, "Update URI points outside wordpress.org ({$package->update_uri})" );
			return;
		}

		$on_disk = Inventory::files( $package->path );

		if ( Package::TYPE_PLUGIN === $package->type ) {
			list( $status, $body ) = Http::json( sprintf( self::PLUGIN_CHECKSUMS, rawurlencode( $package->slug ), rawurlencode( $package->version ) ) );
			if ( 200 === $status && ! empty( $body['files'] ) ) {
				$expected = array();
				foreach ( $body['files'] as $file => $hashes ) {
					$expected[ $file ] = Compare::hashes( isset( $hashes['sha256'] ) ? $hashes['sha256'] : array() );
				}
				self::org( $package, sprintf( self::PLUGIN_ZIP, rawurlencode( $package->slug ), rawurlencode( $package->version ) ) );
				Compare::run( $package, $expected, 'sha256', $on_disk );
				return;
			}
			if ( 0 === $status || $status >= 500 ) {
				self::unverifiable( $package, "checksums request failed (HTTP {$status})" );
				return;
			}
		}

		$zip_url = self::zip_url( $package );
		if ( null === $zip_url ) {
			return; // Already classified by zip_url().
		}

		$key    = Cache::key( $package->type, $package->slug, $package->version, 'zip' );
		$cached = Cache::get( $key );
		$result = self::zip_result( $package, $zip_url, $cached, $key );
		if ( null === $result ) {
			return;
		}
		self::org( $package, $zip_url );
		Compare::run( $package, $result, 'sha256', $on_disk );

		if ( null !== $cached && $package->modified ) {
			// A tag can be edited after its release: do not trust a cached zip to call code modified.
			Cache::forget( $key );
			$fresh = self::zip_result( $package, $zip_url, null, $key );
			if ( null !== $fresh ) {
				$package->verified = array();
				$package->modified = array();
				$package->missing  = array();
				$package->added    = array();
				Compare::run( $package, $fresh, 'sha256', $on_disk );
			}
		}
	}

	/**
	 * The hashes of a version's zip, from the cache or downloaded (and then cached).
	 * Classifies the package and returns null when that is not possible.
	 *
	 * @return array<string,string[]>|null
	 */
	private static function zip_result( Package $package, $zip_url, $cached, $key ) {
		$file = $cached;
		if ( null === $file ) {
			list( $status, $file ) = Http::download( $zip_url );
			if ( 200 !== $status ) {
				if ( 0 === $status || $status >= 500 ) {
					self::unverifiable( $package, "download failed (HTTP {$status}): {$zip_url}" );
				} else {
					self::external( $package, "in the directory, but version {$package->version} is not downloadable" );
				}
				return null;
			}
		}

		$expected = self::zip_hashes( $file );
		if ( null !== $expected && null === $cached ) {
			Cache::put( $key, $file );
		}
		@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( null === $expected ) {
			self::unverifiable( $package, "could not read {$zip_url}" );
		}
		return $expected;
	}

	/**
	 * URL of the zip of the installed version, from the directory API.
	 * Classifies the package as external (and returns null) when it is not listed.
	 */
	private static function zip_url( Package $package ) {
		$api                   = Package::TYPE_PLUGIN === $package->type ? self::PLUGIN_INFO : self::THEME_INFO;
		list( $status, $info ) = Http::json( sprintf( $api, rawurlencode( $package->slug ) ) );

		if ( 0 === $status || $status >= 500 ) {
			self::unverifiable( $package, "directory API request failed (HTTP {$status})" );
			return null;
		}
		if ( 200 !== $status || ! is_array( $info ) || isset( $info['error'] ) || empty( $info['slug'] ) ) {
			self::external( $package, 'not in the wordpress.org directory' );
			return null;
		}
		if ( ! empty( $info['versions'][ $package->version ] ) ) {
			return $info['versions'][ $package->version ];
		}
		$pattern = Package::TYPE_PLUGIN === $package->type ? self::PLUGIN_ZIP : self::THEME_ZIP;
		return sprintf( $pattern, rawurlencode( $package->slug ), rawurlencode( $package->version ) );
	}

	/**
	 * sha256 of every file in a wordpress.org zip, without its top folder.
	 *
	 * @return array<string,string[]>|null
	 */
	private static function zip_hashes( $file ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file ) ) {
			return null;
		}
		$hashes = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
			$name = $zip->getNameIndex( $i );
			if ( '/' === substr( $name, -1 ) ) {
				continue;
			}
			$relative = substr( $name, strpos( $name, '/' ) + 1 );
			if ( '' === $relative || false === strpos( $name, '/' ) ) {
				continue;
			}
			$hashes[ $relative ] = array( hash( 'sha256', (string) $zip->getFromIndex( $i ) ) );
		}
		$zip->close();
		return $hashes;
	}

	private static function updates_elsewhere( Package $package ) {
		$uri = strtolower( trim( $package->update_uri ) );
		if ( '' === $uri ) {
			return false;
		}
		return ! preg_match( '#^(https?://)?(w\.org|WordPress\.org)/(plugins|themes)/#', $uri );
	}

	private static function org( Package $package, $url ) {
		$package->source       = Package::SOURCE_ORG;
		$package->download_url = $url;
	}

	private static function external( Package $package, $why ) {
		$package->source  = Package::SOURCE_EXTERNAL;
		$package->notes[] = $why;
	}

	private static function unverifiable( Package $package, $why ) {
		if ( false !== strpos( $why, 'HTTP 0' ) && '' !== Http::$last_error ) {
			$why .= ': ' . Http::$last_error;
		}
		$package->unverifiable = true;
		$package->notes[]      = $why;
	}
}
