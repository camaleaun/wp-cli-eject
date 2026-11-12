<?php

namespace Camaleaun\Eject;

/**
 * Core, a plugin or a theme found in the site, and what the verification found.
 */
class Package {

	const TYPE_CORE   = 'core';
	const TYPE_PLUGIN = 'plugin';
	const TYPE_THEME  = 'theme';

	/** Downloadable again from wordpress.org: ejected. */
	const SOURCE_ORG = 'wordpress.org';
	/** Not in the wordpress.org directory (premium, private, host-specific): packed as a zip, then kept on its own orphan branch with a tag per version. */
	const SOURCE_EXTERNAL = 'external';
	/** Listed in --keep-plugins/--keep-themes: kept in the project repo. */
	const SOURCE_CUSTOM = 'custom';

	const STATUS_OK      = 'ok';
	const STATUS_WARNING = 'warning';
	const STATUS_ERROR   = 'error';

	/** @var string */
	public $type;
	/** @var string Directory name (plugins/themes) or "WordPress". */
	public $slug;
	/** @var string */
	public $name = '';
	/** @var string */
	public $version = '';
	/** @var string Absolute directory of the package (ABSPATH for core). */
	public $path;
	/** @var string Plugin main file relative to the plugins dir, e.g. "akismet/akismet.php". */
	public $main = '';
	/** @var string Parent theme slug for child themes. */
	public $template = '';
	/** @var string[] Slugs from the "Requires Plugins" header. */
	public $requires = array();
	/** @var string "Update URI" header. */
	public $update_uri = '';
	/** @var bool|null Null when unknown (run without --db). */
	public $active = null;
	/** @var string One of the SOURCE_* constants. */
	public $source = '';
	/** @var string Zip URL of this exact version on wordpress.org. */
	public $download_url = '';

	/** @var string[] Files whose hash matched (relative to $path): the only ones --apply deletes. */
	public $verified = array();
	/** @var string[] Files that differ from wordpress.org. */
	public $modified = array();
	/** @var string[] Files wordpress.org does not ship. */
	public $added = array();
	/** @var string[] Files wordpress.org ships that are not here. */
	public $missing = array();
	/** @var string[] Human-readable notes for the report. */
	public $notes = array();
	/** @var bool True when the checksums could not be fetched for an expected reason (network etc.). */
	public $unverifiable = false;

	public function __construct( $type, $slug, $path ) {
		$this->type = $type;
		$this->slug = $slug;
		$this->path = rtrim( $path, '/' );
	}

	public function status() {
		if ( $this->modified || $this->unverifiable ) {
			return self::STATUS_ERROR;
		}
		if ( $this->added || $this->missing ) {
			return self::STATUS_WARNING;
		}
		return self::STATUS_OK;
	}

	/** Label used in messages, e.g. "plugin woocommerce 9.1.2". */
	public function label() {
		return trim( $this->type . ' ' . $this->slug . ' ' . $this->version );
	}

	/** File name of the zip an external package is packed into. */
	public function zip_name() {
		$version = preg_replace( '/[^A-Za-z0-9._-]/', '-', $this->version );
		return $this->slug . ( '' !== $version ? '-' . $version : '' ) . '.zip';
	}
}
