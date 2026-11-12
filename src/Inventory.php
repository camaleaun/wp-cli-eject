<?php

namespace Camaleaun\Eject;

/**
 * Lists core, plugins and themes from the files (the same headers WordPress reads).
 */
class Inventory {

	/** @var Site */
	private $site;

	public function __construct( Site $site ) {
		$this->site = $site;
	}

	public function core() {
		$core          = new Package( Package::TYPE_CORE, 'wordpress', $this->site->root ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- slug, not the name.
		$core->name    = 'WordPress';
		$core->version = $this->site->wp_version;
		$core->active  = true;
		return $core;
	}

	/**
	 * Plugins in their own directory. Single-file plugins in the plugins root are
	 * returned separately (they are kept, see loose_plugins()).
	 *
	 * @return Package[] Keyed by slug.
	 */
	public function plugins() {
		$plugins = array();
		foreach ( self::dirs( $this->site->plugins_dir ) as $slug ) {
			$dir = $this->site->plugins_dir . '/' . $slug;
			foreach ( glob( $dir . '/*.php' ) as $file ) {
				$headers = self::headers(
					$file,
					array(
						'name'       => 'Plugin Name',
						'version'    => 'Version',
						'requires'   => 'Requires Plugins',
						'update_uri' => 'Update URI',
					)
				);
				if ( '' === $headers['name'] ) {
					continue;
				}
				$plugin             = new Package( Package::TYPE_PLUGIN, $slug, $dir );
				$plugin->name       = $headers['name'];
				$plugin->version    = $headers['version'];
				$plugin->main       = $slug . '/' . basename( $file );
				$plugin->update_uri = $headers['update_uri'];
				$plugin->requires   = array_values( array_filter( array_map( 'trim', explode( ',', $headers['requires'] ) ) ) );
				$plugin->active     = $this->site->has_db ? in_array( $plugin->main, $this->site->active_plugins, true ) : null;
				$plugins[ $slug ]   = $plugin;
				break;
			}
		}
		return $plugins;
	}

	/** @return string[] Single-file plugins in the plugins root, e.g. "hello.php". */
	public function loose_plugins() {
		$loose = array();
		foreach ( (array) glob( $this->site->plugins_dir . '/*.php' ) as $file ) {
			if ( '' !== self::headers( $file, array( 'name' => 'Plugin Name' ) )['name'] ) {
				$loose[] = basename( $file );
			}
		}
		return $loose;
	}

	/** @return Package[] Keyed by slug. */
	public function themes() {
		$themes = array();
		foreach ( self::dirs( $this->site->themes_dir ) as $slug ) {
			$dir = $this->site->themes_dir . '/' . $slug;
			if ( ! is_file( $dir . '/style.css' ) ) {
				continue;
			}
			$headers = self::headers(
				$dir . '/style.css',
				array(
					'name'       => 'Theme Name',
					'version'    => 'Version',
					'template'   => 'Template',
					'update_uri' => 'Update URI',
				)
			);
			if ( '' === $headers['name'] ) {
				continue;
			}
			$theme             = new Package( Package::TYPE_THEME, $slug, $dir );
			$theme->name       = $headers['name'];
			$theme->version    = $headers['version'];
			$theme->template   = $headers['template'];
			$theme->update_uri = $headers['update_uri'];
			$theme->active     = $this->site->has_db ? in_array( $slug, array( $this->site->stylesheet, $this->site->template ), true ) : null;
			$themes[ $slug ]   = $theme;
		}
		return $themes;
	}

	/** @return string[] Sorted names of the subdirectories. */
	public static function dirs( $path ) {
		$dirs = array();
		foreach ( (array) @scandir( $path ) as $entry ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( '' !== $entry && '.' !== $entry[0] && is_dir( $path . '/' . $entry ) ) {
				$dirs[] = $entry;
			}
		}
		sort( $dirs );
		return $dirs;
	}

	/**
	 * Reads file headers the way get_file_data() does (first 8 KB).
	 *
	 * @param array<string,string> $keys Map of key => header name.
	 * @return array<string,string>
	 */
	public static function headers( $file, $keys ) {
		$data = (string) file_get_contents( $file, false, null, 0, 8192 );
		$data = str_replace( "\r", "\n", $data );
		$out  = array();
		foreach ( $keys as $key => $header ) {
			$out[ $key ] = '';
			if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $data, $match ) ) {
				$out[ $key ] = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
			}
		}
		return $out;
	}

	/** @var array<string,bool> Temporary files of an interrupted transfer that files() skipped (absolute paths). */
	private static $leftovers = array();

	/**
	 * A file rsync left behind: while it receives a file it writes `.<name>.<random>` next to
	 * it (6 characters, 10 with macOS's rsync). Real dotfiles such as `.htaccess.backup` or
	 * `.env.sample` are not matched: the random part has a capital or a digit, or the file it
	 * stands for sits next to it.
	 */
	public static function is_transfer_leftover( $path ) {
		if ( ! preg_match( '/^\.(.+)\.([A-Za-z0-9]{6}|[A-Za-z0-9]{10})$/', basename( $path ), $match ) ) {
			return false;
		}
		return (bool) preg_match( '/[A-Z0-9]/', $match[2] ) || file_exists( dirname( $path ) . '/' . $match[1] );
	}

	/** @return string[] Absolute paths of the temporary files skipped so far. */
	public static function leftovers() {
		return array_keys( self::$leftovers );
	}

	/**
	 * Every file under a directory, relative to it, sorted. Temporary files of an
	 * interrupted transfer are left out (see is_transfer_leftover()).
	 *
	 * @return string[]
	 */
	public static function files( $dir ) {
		$files = array();
		if ( ! is_dir( $dir ) ) {
			return $files;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);
		$prefix   = strlen( rtrim( $dir, '/' ) ) + 1;
		foreach ( $iterator as $file ) {
			if ( ( $file->isFile() || $file->isLink() ) && self::is_transfer_leftover( $file->getPathname() ) ) {
				self::$leftovers[ str_replace( '\\', '/', $file->getPathname() ) ] = true;
			} elseif ( $file->isFile() || $file->isLink() ) {
				$files[] = str_replace( '\\', '/', substr( $file->getPathname(), $prefix ) );
			}
		}
		sort( $files );
		return $files;
	}
}
