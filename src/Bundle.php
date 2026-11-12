<?php

namespace Camaleaun\Eject;

use WP_CLI;
use WP_CLI\Utils;

/**
 * A Playground blueprint bundle written away from the site: a self-contained
 * zip (default) or a directory, with blueprint.json at its root.
 *
 * Files are staged in a temporary directory and written at the end, so a
 * failure never leaves half a bundle behind.
 */
class Bundle {

	/** @var string */
	private $target;
	/** @var bool */
	private $is_zip;
	/** @var string */
	private $stage;
	/** @var int */
	private $count = 0;

	public function __construct( $target ) {
		$this->target = rtrim( $target, '/' );
		$this->is_zip = '.zip' === strtolower( substr( $this->target, -4 ) );
		$this->stage  = rtrim( Utils\get_temp_dir(), '/' ) . '/wp-eject-bundle-' . uniqid();
		mkdir( $this->stage, 0755, true );
	}

	/**
	 * A directory target that is already a git repository must have a clean
	 * working tree, so that the files wp eject writes are the only change.
	 */
	public static function check_target( $target ) {
		$target = rtrim( $target, '/' );
		if ( '.zip' === strtolower( substr( $target, -4 ) ) || ! Git::is_repo( $target ) ) {
			return;
		}
		$pending = Git::pending( $target );
		if ( '' !== $pending ) {
			WP_CLI::error( "The repository at {$target} has uncommitted changes:\n" . $pending . "\nCommit or stash them first, so the files wp eject writes are the only change." );
		}
	}

	/** Default target: <site root>/<site folder>-bundle.zip */
	public static function default_target( Site $site ) {
		return $site->root . basename( rtrim( $site->root, '/' ) ) . '-bundle.zip';
	}

	public function target() {
		return $this->target;
	}

	public function is_zip() {
		return $this->is_zip;
	}

	public function count() {
		return $this->count;
	}

	/** Path of a file inside the staging directory (for writers that need one). */
	public function path( $relative ) {
		$file = $this->stage . '/' . ltrim( $relative, '/' );
		if ( ! is_dir( dirname( $file ) ) ) {
			mkdir( dirname( $file ), 0755, true );
		}
		return $file;
	}

	/** Counts a file written directly at path(). */
	public function counted() {
		++$this->count;
	}

	public function add_string( $relative, $content, $executable = false ) {
		$file = $this->path( $relative );
		file_put_contents( $file, $content );
		if ( $executable ) {
			chmod( $file, 0755 );
		}
		++$this->count;
	}

	public function add_file( $source, $relative ) {
		$file = $this->path( $relative );
		copy( $source, $file );
		chmod( $file, fileperms( $source ) & 0777 );
		++$this->count;
	}

	/**
	 * Adds a file or a whole directory.
	 *
	 * @return string[] Relative paths added (files only).
	 */
	public function add_tree( $source, $relative ) {
		if ( is_file( $source ) ) {
			$this->add_file( $source, $relative );
			return array( $relative );
		}
		$added = array();
		foreach ( Inventory::files( $source ) as $file ) {
			if ( is_link( $source . '/' . $file ) ) {
				continue;
			}
			$this->add_file( $source . '/' . $file, $relative . '/' . $file );
			$added[] = $relative . '/' . $file;
		}
		return $added;
	}

	/** Writes the zip or the directory and removes the staging directory. */
	public function close() {
		if ( $this->is_zip ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $this->target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				WP_CLI::error( "Could not create {$this->target}." );
			}
			foreach ( Inventory::files( $this->stage ) as $file ) {
				$zip->addFile( $this->stage . '/' . $file, $file );
				// Keep each file's permissions (e.g. executable bits).
				$zip->setExternalAttributesName( $file, \ZipArchive::OPSYS_UNIX, ( 0100000 | ( fileperms( $this->stage . '/' . $file ) & 0777 ) ) << 16 );
			}
			$zip->close();
		} elseif ( Git::is_repo( $this->target ) ) {
			// Into an existing repository: write over, delete nothing. deploy/config.yml is the project's.
			self::check_target( $this->target );
			foreach ( Inventory::files( $this->stage ) as $file ) {
				$dest = $this->target . '/' . $file;
				if ( 'deploy/config.yml' === $file && is_file( $dest ) ) {
					continue;
				}
				if ( ! is_dir( dirname( $dest ) ) ) {
					mkdir( dirname( $dest ), 0755, true );
				}
				copy( $this->stage . '/' . $file, $dest );
				chmod( $dest, fileperms( $this->stage . '/' . $file ) & 0777 );
			}
		} else {
			if ( file_exists( $this->target ) && ! self::is_previous_bundle( $this->target ) ) {
				WP_CLI::error( "{$this->target} exists and is not a bundle written by wp eject; choose another --bundle path." );
			}
			self::remove_tree( $this->target );
			if ( ! is_dir( dirname( $this->target ) ) ) {
				mkdir( dirname( $this->target ), 0755, true );
			}
			// Copy instead of rename: the temp dir may be on another volume.
			foreach ( Inventory::files( $this->stage ) as $file ) {
				$dest = $this->target . '/' . $file;
				if ( ! is_dir( dirname( $dest ) ) ) {
					mkdir( dirname( $dest ), 0755, true );
				}
				copy( $this->stage . '/' . $file, $dest );
				chmod( $dest, fileperms( $this->stage . '/' . $file ) & 0777 );
			}
		}
		self::remove_tree( $this->stage );
	}

	private static function is_previous_bundle( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		if ( 2 === count( (array) scandir( $dir ) ) ) {
			return true; // Empty directory.
		}
		$blueprint = $dir . '/blueprint.json';
		if ( ! is_file( $blueprint ) ) {
			return false;
		}
		// A bundle always has the generated .gitignore. The old marker (the blueprint's meta title) is
		// kept for bundles written by an earlier version.
		return ( is_file( $dir . '/.gitignore' ) && Pipeline::is_generated( $dir . '/.gitignore' ) )
			|| false !== strpos( (string) file_get_contents( $blueprint ), '"title": "Site code"' );
	}

	private static function remove_tree( $dir ) {
		if ( ! file_exists( $dir ) ) {
			return;
		}
		if ( is_file( $dir ) || is_link( $dir ) ) {
			unlink( $dir );
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			if ( $file->isDir() && ! $file->isLink() ) {
				rmdir( $file->getPathname() );
			} else {
				unlink( $file->getPathname() );
			}
		}
		rmdir( $dir );
	}
}
