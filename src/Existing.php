<?php

namespace Camaleaun\Eject;

/**
 * What an already ejected repository says about itself: its packages (from
 * blueprint.json) and the site's own code (from git).
 */
class Existing {

	/**
	 * Core, plugins and themes the blueprint installs, plus the repository's own
	 * custom packages.
	 *
	 * @return Package[]
	 */
	public static function packages( $root, array $code ) {
		$root      = rtrim( $root, '/' );
		$blueprint = json_decode( (string) @file_get_contents( $root . '/blueprint.json' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$site      = Site::from_root( $root );

		$core          = new Package( Package::TYPE_CORE, 'WordPress', $root );
		$core->version = $site->wp_version;
		$core->source  = Package::SOURCE_ORG;
		$packages      = array( $core );

		foreach ( (array) ( isset( $blueprint['steps'] ) ? $blueprint['steps'] : array() ) as $step ) {
			$type = isset( $step['step'] ) && 'installPlugin' === $step['step'] ? 'plugin' : ( isset( $step['step'] ) && 'installTheme' === $step['step'] ? 'theme' : '' );
			if ( '' === $type ) {
				continue;
			}
			$resource = isset( $step[ 'plugin' === $type ? 'pluginData' : 'themeData' ] ) ? $step[ 'plugin' === $type ? 'pluginData' : 'themeData' ] : array();
			if ( isset( $resource['url'] ) && preg_match( '#/' . $type . '/([^/]+?)\.(\d[^/]*)\.zip$#', $resource['url'], $match ) ) {
				$source = Package::SOURCE_ORG;
			} elseif ( isset( $resource['path'] ) && preg_match( '#/' . $type . 's/([^/]+)-(\d[^/]*)\.zip$#', $resource['path'], $match ) ) {
				$source = Package::SOURCE_EXTERNAL;
			} else {
				continue;
			}
			$package          = new Package( $type, $match[1], $root . '/wp-content/' . $type . 's/' . $match[1] );
			$package->version = $match[2];
			$package->source  = $source;
			$packages[]       = $package;
		}

		// The repository's own packages: plugin and theme folders it versions.
		foreach ( $code as $path ) {
			if ( ! preg_match( '#^wp-content/(plugin|theme)s/([^/]+)$#', $path, $match ) || ! is_dir( $root . '/' . $path ) ) {
				continue;
			}
			$package         = new Package( $match[1], $match[2], $root . '/' . $path );
			$headers         = 'plugin' === $match[1] ? array() : Inventory::headers( $root . '/' . $path . '/style.css', array( 'version' => 'Version' ) );
			$package->source = Package::SOURCE_CUSTOM;
			if ( 'plugin' === $match[1] ) {
				foreach ( (array) glob( $root . '/' . $path . '/*.php' ) as $file ) {
					$found = Inventory::headers(
						$file,
						array(
							'name'    => 'Plugin Name',
							'version' => 'Version',
						)
					);
					if ( '' !== $found['name'] ) {
						$headers = $found;
						break;
					}
				}
			}
			$package->version = isset( $headers['version'] ) ? $headers['version'] : '';
			$packages[]       = $package;
		}

		// The same order wp eject uses (see Command::__invoke): core, then plugins, then themes, each by name.
		// The blueprint's own order (themes first, dependencies first) is not the one to show.
		$rank = array(
			Package::TYPE_CORE   => 0,
			Package::TYPE_PLUGIN => 1,
			Package::TYPE_THEME  => 2,
		);
		usort(
			$packages,
			function ( Package $a, Package $b ) use ( $rank ) {
				return $rank[ $a->type ] === $rank[ $b->type ] ? strcmp( $a->slug, $b->slug ) : $rank[ $a->type ] - $rank[ $b->type ];
			}
		);
		return $packages;
	}

	/** @return array<string,bool> Set of "plugin/slug" and "theme/slug" the blueprint installs. */
	private static function installed( $root ) {
		$blueprint = json_decode( (string) @file_get_contents( $root . '/blueprint.json' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$set       = array();
		foreach ( (array) ( isset( $blueprint['steps'] ) ? $blueprint['steps'] : array() ) as $step ) {
			$type = isset( $step['step'] ) && 'installPlugin' === $step['step'] ? 'plugin' : ( isset( $step['step'] ) && 'installTheme' === $step['step'] ? 'theme' : '' );
			if ( '' === $type ) {
				continue;
			}
			$resource = isset( $step[ 'plugin' === $type ? 'pluginData' : 'themeData' ] ) ? $step[ 'plugin' === $type ? 'pluginData' : 'themeData' ] : array();
			$location = isset( $resource['url'] ) ? $resource['url'] : ( isset( $resource['path'] ) ? $resource['path'] : '' );
			if ( preg_match( '#/' . $type . 's?/([^/]+?)[.-]\d[^/]*\.zip$#', $location, $match ) ) {
				$set[ $type . '/' . $match[1] ] = true;
			}
		}
		return $set;
	}

	/**
	 * The site's own code, as the paths git tracks under wp-content: a package
	 * folder, or a single file.
	 *
	 * @return string[]
	 */
	public static function code_paths( $root ) {
		$root = rtrim( $root, '/' );
		if ( ! Git::is_repo( $root ) ) {
			return array();
		}
		$installed = self::installed( $root );
		$paths     = array();
		foreach ( Git::tracked( $root, 'wp-content' ) as $file ) {
			if ( Inventory::is_transfer_leftover( $root . '/' . $file ) ) {
				\WP_CLI::warning( "{$file}: tracked by git, but it is a temporary file of an interrupted transfer (rsync): ignored. Remove it from git with `git rm --cached`." );
				continue;
			}
			if ( preg_match( '#^wp-content/(plugins|themes)/([^/]+)/#', $file, $match ) && isset( $installed[ rtrim( $match[1], 's' ) . '/' . $match[2] ] ) ) {
				// A package the blueprint installs: only the files added to it are the repository's.
				$paths[ $file ] = true;
			} elseif ( preg_match( '#^(wp-content/(?:plugins|themes|mu-plugins)/[^/]+)/#', $file, $match ) ) {
				$paths[ $match[1] ] = true;
			} elseif ( preg_match( '#^(wp-content/languages)/#', $file, $match ) ) {
				$paths[ $match[1] ] = true;
			} else {
				$paths[ $file ] = true;
			}
		}
		$paths = array_keys( $paths );
		sort( $paths );
		return $paths;
	}
}
