<?php

namespace Camaleaun\Eject;

use WP_CLI;

/**
 * The --apply side: packs external packages, deletes verified files, writes EJECT.md.
 */
class Packer {

	/** @var Site */
	private $site;
	/** @var string */
	private $vendor_path;

	public function __construct( Site $site, $vendor_path ) {
		$this->site        = $site;
		$this->vendor_path = trim( $vendor_path, '/' );
	}

	/**
	 * Zips an external package next to its directory (<slug>/ at the zip root), checks
	 * the zip holds every file, then removes the directory.
	 *
	 * @return string Path of the zip.
	 */
	public function pack( Package $package ) {
		$zip = $this->zip_to( $package, dirname( $package->path ) . '/' . $package->zip_name() );
		self::remove_tree( $package->path );
		return $zip;
	}

	/**
	 * Zips a package (<slug>/ at the zip root) to a given path and checks the zip
	 * holds every file. The package directory is left untouched.
	 *
	 * @return string Path of the zip.
	 */
	public function zip_to( Package $package, $zip ) {
		$files = Inventory::files( $package->path );
		if ( ! is_dir( dirname( $zip ) ) ) {
			mkdir( dirname( $zip ), 0755, true );
		}

		$archive = new \ZipArchive();
		if ( true !== $archive->open( $zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			WP_CLI::error( "Could not create {$zip}." );
		}
		foreach ( $files as $file ) {
			$archive->addFile( $package->path . '/' . $file, $package->slug . '/' . $file );
		}
		$archive->close();

		$check = new \ZipArchive();
		if ( true !== $check->open( $zip ) || count( $files ) !== $check->numFiles ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
			WP_CLI::error( "{$zip} does not hold every file of {$package->label()}; the directory was kept." );
		}
		$check->close();
		return $zip;
	}

	/**
	 * Deletes only the files whose hash matched, then the directories left empty.
	 * Added and modified files stay where they are.
	 *
	 * @param string[] $prune_dirs Directories (relative to the package) to prune; the package dir itself when empty.
	 * @return int Files deleted.
	 */
	public function eject( Package $package, array $prune_dirs = array( '' ) ) {
		$deleted = 0;
		foreach ( $package->verified as $file ) {
			if ( @unlink( $package->path . '/' . $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				++$deleted;
			}
		}
		foreach ( $prune_dirs as $dir ) {
			self::prune( rtrim( $package->path . '/' . $dir, '/' ), '' === $dir ? Package::TYPE_CORE !== $package->type : true );
		}
		return $deleted;
	}

	/**
	 * The external packages part of EJECT.md: one orphan branch per package,
	 * one tag per version, published by `vendor publish`.
	 *
	 * @param string[] $zips Zip paths, relative to the repository root.
	 */
	private static function external_section( array $zips ) {
		$list = $zips ? '- `' . implode( "`\n- `", $zips ) . '`' : '- (none)';
		return <<<MD
## External packages

These are not in the wordpress.org directory (premium, private or host-specific),
so they cannot be downloaded again:

{$list}

Each one lives on its **own orphan branch**, with its files at the branch root and
**one tag per version**:

| | Name |
|---|---|
| branch | `vendor/plugin/<slug>` (or `vendor/theme/<slug>`) |
| tag | `vendor/plugin/<slug>/<version>` |

A new version is a new commit on that branch, so git stores only what changed and
the history stays. `build` turns the tag back into the zip the blueprint expects
(`git archive`), so no zip is ever committed.

Publish them (it never switches branch nor touches the working tree):

```bash
wp --require=deploy/deploy.php vendor publish              # commit, tag and push
wp --require=deploy/deploy.php vendor publish --no-push    # only locally
wp --require=deploy/deploy.php vendor list                 # what is published / pending
```

The same by hand, for one zip:

```bash
unzip acme-pro-2.1.0.zip -d /tmp/acme && cd /tmp/acme/acme-pro
export GIT_INDEX_FILE=/tmp/acme.index GIT_DIR=<repository>/.git
git --work-tree=. add --all --force . && tree=\$(git write-tree)
parent=\$(git rev-parse -q --verify refs/heads/vendor/plugin/acme-pro) && parent="-p \$parent"
commit=\$(git commit-tree \$tree \$parent -m "acme-pro 2.1.0")
git update-ref refs/heads/vendor/plugin/acme-pro \$commit
git tag vendor/plugin/acme-pro/2.1.0 \$commit
unset GIT_INDEX_FILE GIT_DIR
git push origin vendor/plugin/acme-pro vendor/plugin/acme-pro/2.1.0
```

To update one: put the new version's zip back (or run the setup again), then run
`vendor publish`. A published version never changes: publishing the same version
with other files is an error.
MD;
	}

	/**
	 * wp-content/EJECT.md after `--no-bundle --apply`: what happened and what to
	 * do with the external zips.
	 *
	 * @param string[] $zips Zip paths, relative to the site root.
	 * @return string Path written.
	 */
	public function write_instructions( $content_dir, array $zips ) {
		$external = self::external_section( $zips );
		$md       = <<<MD
# About this folder

Every file that wordpress.org can give back (core, plugins and themes, checked file
by file) was deleted from this folder. `blueprint.json` rebuilds them at the exact
versions installed.

{$external}

## Commit

The `.gitignore` lets in only the blueprint, the deploy files
and the site's own code; the zips and this file stay out.

## Undo it locally

```bash
wp --require=deploy/deploy.php restore
```

It builds the site from the blueprint (the external zips come from here or from
their tags) and copies it back into this folder, without touching `wp-config.php`,
the local database or `deploy/`.
MD;
		file_put_contents( $content_dir . '/EJECT.md', $md . "\n" );
		return $content_dir . '/EJECT.md';
	}

	/** Deletes a package's folder (after it was published, or zipped). */
	public function remove_package( Package $package ) {
		self::remove_tree( $package->path );
	}

	/** Removes a directory and everything in it. */
	public static function remove_dir( $dir ) {
		if ( is_dir( $dir ) && ! is_link( $dir ) ) {
			self::remove_tree( $dir );
		}
	}

	/** Removes the empty directories under $dir (not $dir itself). */
	public static function prune_empty( $dir ) {
		self::prune( $dir, false );
	}

	private static function prune( $dir, $remove_self ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry && is_dir( $dir . '/' . $entry ) && ! is_link( $dir . '/' . $entry ) ) {
				self::prune( $dir . '/' . $entry, true );
			}
		}
		if ( $remove_self && 2 === count( (array) scandir( $dir ) ) ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	private static function remove_tree( $dir ) {
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
