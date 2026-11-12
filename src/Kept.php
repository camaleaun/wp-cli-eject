<?php

namespace Camaleaun\Eject;

/**
 * Everything the command leaves in place on purpose, with the reason. Printed
 * as "Not ejected" at the end of every run.
 */
class Kept {

	/** Studio files: removing them breaks the local site or its AI setup. */
	const STUDIO = array(
		'wp-content/db.php'                          => 'Studio SQLite drop-in: required for the local site to boot',
		'wp-content/database'                        => 'Studio SQLite database',
		'wp-content/mu-plugins/sqlite-database-integration' => 'Studio SQLite integration: if db.php, database/ and this are all gone, Studio treats the site as MySQL and will not reinstall SQLite',
		'wp-content/mu-plugins/99-studio-loader.php' => 'Studio mu-plugin loader (Studio rewrites it on every start)',
		'.agents'                                    => 'Studio AI skills',
		'.claude'                                    => 'Studio AI skills (links to .agents)',
		'AGENTS.md'                                  => 'Studio AI instructions',
		'CLAUDE.md'                                  => 'Studio AI instructions',
		'STUDIO.md'                                  => 'Studio AI instructions',
	);

	/** wp-content folders of server data that regenerates by itself (Wordfence logs, upgrade leftovers, caches). */
	const REGENERABLE = array( 'wflogs', 'upgrade', 'upgrade-temp-backup', 'cache' );

	/** Drop-ins WordPress loads from wp-content/. */
	const DROPINS = array( 'advanced-cache.php', 'db.php', 'db-error.php', 'install.php', 'maintenance.php', 'object-cache.php', 'php-error.php', 'fatal-error-handler.php', 'sunrise.php', 'blog-deleted.php', 'blog-inactive.php', 'blog-suspended.php' );

	/** @var array<string,string> Relative path => reason. */
	private $items = array();

	/** @var array<string,bool> Relative paths that are the site's own code (they go into the bundle). */
	private $code = array();

	/** @var Site */
	private $site;

	public function __construct( Site $site ) {
		$this->site = $site;
	}

	/**
	 * @param bool $code True when the path is the site's own code: it goes into the bundle.
	 */
	public function add( $path, $reason, $code = false ) {
		$this->items[ $path ] = $reason;
		if ( $code ) {
			$this->code[ $path ] = true;
		} else {
			unset( $this->code[ $path ] );
		}
	}

	/** @return string[] Relative paths (files or directories) of the site's own code. */
	public function code() {
		$paths = array_keys( $this->code );
		sort( $paths );
		return $paths;
	}

	public function is_code( $path ) {
		return isset( $this->code[ $path ] );
	}

	/** @return array<string,string> */
	public function all() {
		ksort( $this->items );
		return $this->items;
	}

	/** Studio files, wp-content leftovers, mu-plugins, drop-ins and loose plugins. */
	public function scan( Inventory $inventory ) {
		$root    = $this->site->root;
		$content = $this->site->relative( $this->site->content_dir );

		foreach ( self::STUDIO as $path => $reason ) {
			$path = preg_replace( '#^wp-content#', $content, $path );
			if ( file_exists( $root . $path ) ) {
				if ( $content . '/db.php' === $path && ! $this->is_sqlite_dropin( $root . $path ) ) {
					continue; // A non-Studio db.php is reported as a drop-in below.
				}
				$this->add( $path, $reason );
			}
		}

		if ( is_file( $root . 'wp-config.php' ) ) {
			$this->add( 'wp-config.php', 'site configuration' );
		}

		foreach ( (array) @scandir( $this->site->content_dir ) as $entry ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( '' === $entry || '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $content . '/' . $entry;
			if ( isset( $this->items[ $path ] ) || in_array( $entry, array( 'plugins', 'themes', 'index.php' ), true ) ) {
				continue;
			}
			if ( in_array( $entry, self::DROPINS, true ) ) {
				$this->add( $path, 'drop-in: not in any directory, kept in the repo', true );
			} elseif ( 'mu-plugins' === $entry ) {
				foreach ( (array) scandir( $this->site->content_dir . '/mu-plugins' ) as $mu ) {
					$mu_path = $path . '/' . $mu;
					if ( '.' !== $mu[0] && 'index.php' !== $mu && ! isset( $this->items[ $mu_path ] ) ) {
						$this->add( $mu_path, 'mu-plugin: not in any directory, kept in the repo', true );
					}
				}
			} elseif ( 'uploads' === $entry ) {
				$this->add( $path, 'media: content, out of scope for the code blueprint' );
			} elseif ( in_array( $entry, self::REGENERABLE, true ) ) {
				$this->add( $path, 'regenerable server data: never versioned or deployed (--no-bundle --apply deletes the local copy)' );
			} elseif ( 'languages' === $entry ) {
				$this->add( $path, 'translations: kept (language packs are not verified)', true );
			} else {
				$this->add( $path, 'not code WordPress loads (backups, caches, upgrade leftovers…): review and delete by hand' );
			}
		}

		foreach ( $inventory->loose_plugins() as $file ) {
			if ( Checksums\Core::bundled_file_matches( $this->site->plugins_dir . '/' . $file, 'plugins/' . $file ) ) {
				continue; // Shipped with core (Hello Dolly): ejected with it.
			}
			$this->add( $this->site->relative( $this->site->plugins_dir ) . '/' . $file, 'single-file plugin: kept in the repo', true );
		}
	}

	private function is_sqlite_dropin( $file ) {
		$head = (string) file_get_contents( $file, false, null, 0, 2048 );
		return false !== stripos( $head, 'SQLite integration' ) || false !== strpos( $head, 'SQLITE_DB_DROPIN_VERSION' );
	}
}
