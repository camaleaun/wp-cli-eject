<?php

namespace Camaleaun\Eject;

use WP_CLI;

/**
 * What the command knows about the site: paths, versions and what is active.
 *
 * With the database (default) WordPress is loaded and the active plugins and
 * theme come from it. Without --db (the default) only the files are read: nothing is known to
 * be active, so the blueprint installs everything without activating it.
 */
class Site {

	/** @var string ABSPATH with trailing slash. */
	public $root;
	/** @var string */
	public $content_dir;
	/** @var string */
	public $plugins_dir;
	/** @var string */
	public $themes_dir;
	/** @var string */
	public $wp_version;
	/** @var string */
	public $locale = 'en_US';
	/** @var string major.minor */
	public $php_version;
	/** @var bool */
	public $has_db = false;
	/** @var string[] Plugin main files, e.g. "akismet/akismet.php". */
	public $active_plugins = array();
	/** @var string */
	public $stylesheet = '';
	/** @var string */
	public $template = '';
	/** @var bool */
	public $multisite = false;

	public static function detect( $use_db ) {
		$site       = new self();
		$site->root = rtrim( ABSPATH, '/' ) . '/';

		if ( ! is_file( $site->root . 'wp-includes/version.php' ) ) {
			WP_CLI::error( "No WordPress install found at {$site->root}." );
		}

		$site->php_version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

		if ( $use_db ) {
			WP_CLI::get_runner()->load_wordpress();
			$site->has_db      = true;
			$site->content_dir = rtrim( WP_CONTENT_DIR, '/' );
			$site->plugins_dir = rtrim( WP_PLUGIN_DIR, '/' );
			$site->themes_dir  = rtrim( get_theme_root(), '/' );
			$site->wp_version  = get_bloginfo( 'version' );
			$site->locale      = get_locale();
			$site->multisite   = is_multisite();
			$site->stylesheet  = get_stylesheet();
			$site->template    = get_template();
			$active            = (array) get_option( 'active_plugins', array() );
			if ( $site->multisite ) {
				$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
			}
			$site->active_plugins = array_values( array_unique( $active ) );
			return $site;
		}

		$versions          = self::read_version_file( $site->root . 'wp-includes/version.php' );
		$site->wp_version  = $versions['wp_version'];
		$site->locale      = $versions['locale'];
		$site->content_dir = $site->root . 'wp-content';
		$site->plugins_dir = $site->content_dir . '/plugins';
		$site->themes_dir  = $site->content_dir . '/themes';

		// Without loading WordPress, custom content paths cannot be resolved.
		foreach ( array( $site->root . 'wp-config.php', dirname( $site->root ) . '/wp-config.php' ) as $config ) {
			if ( is_file( $config ) && preg_match( '/define\s*\(\s*[\'"](WP_CONTENT_DIR|WP_PLUGIN_DIR)[\'"]/', (string) file_get_contents( $config ), $match ) ) {
				WP_CLI::warning( "wp-config.php defines {$match[1]}; without --db the command assumes wp-content/. Use --db to read the real paths." );
				break;
			}
		}
		return $site;
	}

	/**
	 * A repository that was ejected: no core, no database. Versions come from
	 * its blueprint.json.
	 */
	public static function from_root( $root ) {
		$site              = new self();
		$site->root        = rtrim( $root, '/' ) . '/';
		$site->content_dir = $site->root . 'wp-content';
		$site->plugins_dir = $site->content_dir . '/plugins';
		$site->themes_dir  = $site->content_dir . '/themes';
		$blueprint         = json_decode( (string) @file_get_contents( $site->root . 'blueprint.json' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$wp                = isset( $blueprint['preferredVersions']['wp'] ) ? (string) $blueprint['preferredVersions']['wp'] : '';
		$site->wp_version  = preg_match( '#wordpress-(\d[^/]*)\.zip$#', $wp, $match ) ? $match[1] : $wp;
		$site->php_version = isset( $blueprint['preferredVersions']['php'] ) ? (string) $blueprint['preferredVersions']['php'] : '';
		return $site;
	}

	/** Relative path from ABSPATH, for messages. */
	public function relative( $path ) {
		return 0 === strpos( $path, $this->root ) ? substr( $path, strlen( $this->root ) ) : $path;
	}

	private static function read_version_file( $file ) {
		$wp_version       = '';
		$wp_local_package = '';
		include $file; // Defines $wp_version and, in localized packages, $wp_local_package.
		return array(
			'wp_version' => $wp_version,
			'locale'     => $wp_local_package ? $wp_local_package : 'en_US',
		);
	}
}
