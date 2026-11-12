<?php

namespace Camaleaun\Eject;

/**
 * Builds the Playground blueprint that rebuilds the site's code.
 *
 * - core: the exact version zip;
 * - wordpress.org packages: the zip of the installed version (pinned);
 * - external: `bundled` zips in <vendor-path>/ (in a repository, `build` makes them from the tags vendor/<type>/<slug>/<version>);
 * - custom: already in the repo (mounted by whoever runs the blueprint), only activated.
 *
 * Content, database and uploads are not part of it.
 */
class Blueprint {

	/** @var Site */
	private $site;
	/** @var string */
	private $vendor_path;
	/** @var bool Activate each plugin with its hooks instead of setting active_plugins. */
	private $with_hooks;

	public function __construct( Site $site, $vendor_path, $with_hooks = false ) {
		$this->site        = $site;
		$this->vendor_path = trim( $vendor_path, '/' );
		$this->with_hooks  = (bool) $with_hooks;
	}

	/**
	 * @param Package   $core
	 * @param Package[] $plugins
	 * @param Package[] $themes
	 * @param string[]  $files_before Bundle mode: files (relative to the site root) written before
	 *                                the installs: custom packages, mu-plugins, drop-ins, translations.
	 * @param string[]  $files_after  Bundle mode: files inside installed packages (added files),
	 *                                written after the installs so these do not overwrite them.
	 */
	public function build( Package $core, array $plugins, array $themes, array $files_before = array(), array $files_after = array() ) {
		$steps = array();

		if ( 'en_US' !== $this->site->locale && '' !== $this->site->locale ) {
			$steps[] = array(
				'step'     => 'setSiteLanguage',
				'language' => $this->site->locale,
			);
		}

		// The core zip ships default themes and plugins the site may have deleted.
		foreach ( \Camaleaun\Eject\Checksums\Core::bundled_items() as $item ) {
			list( $kind, $name ) = explode( '/', $item, 2 );
			$dir                 = 'plugins' === $kind ? $this->site->plugins_dir : $this->site->themes_dir;
			if ( ! file_exists( $dir . '/' . $name ) ) {
				$steps[] = array(
					'step' => '.php' === substr( $name, -4 ) ? 'rm' : 'rmdir',
					'path' => '/wordpress/wp-content/' . $item,
				);
			}
		}

		$steps = array_merge( $steps, self::write_steps( $files_before ) );

		foreach ( $this->theme_order( $themes ) as $theme ) {
			$step = $this->install_step( $theme, $theme->slug === $this->site->stylesheet );
			if ( $step ) {
				$steps[] = $step;
			}
		}
		if ( '' !== $this->site->stylesheet && isset( $themes[ $this->site->stylesheet ] ) && Package::SOURCE_CUSTOM === $themes[ $this->site->stylesheet ]->source ) {
			$steps[] = array(
				'step'            => 'activateTheme',
				'themeFolderName' => $this->site->stylesheet,
			);
		}

		/*
		 * By default plugins are installed inactive and active_plugins is set at
		 * the end, exactly as in the site's database: running every activation
		 * hook in a row is not deterministic (one plugin's hook can make another's
		 * activation fail) and the deployed site keeps its own database anyway.
		 */
		$active = array();
		foreach ( $this->plugin_order( $plugins ) as $plugin ) {
			$is_active = true === $plugin->active;
			$step      = $this->install_step( $plugin, $this->with_hooks && $is_active );
			if ( $step ) {
				$steps[] = $step;
			} elseif ( $is_active && $this->with_hooks ) {
				$steps[] = array(
					'step'       => 'activatePlugin',
					'pluginPath' => $plugin->main,
				);
			}
			if ( $is_active ) {
				$active[] = $plugin->main;
			}
		}
		$steps = array_merge( $steps, self::write_steps( $files_after ) );

		if ( $active && ! $this->with_hooks ) {
			$steps[] = array(
				'step'    => 'setSiteOptions',
				'options' => array( 'active_plugins' => $active ),
			);
		}

		/*
		 * TODO: no `meta` on purpose. Playground's schema (BlueprintV1Declaration) makes `meta`
		 * optional, but requires `meta.author` whenever `meta` is there, and a generated file
		 * must not name anyone. A developer who wants a title, a description and an author back
		 * must give all three (`author` is a GitHub username) and must not use `meta` to
		 * recognize a bundle (see Bundle::is_previous_bundle()).
		 */
		return array(
			'$schema'           => 'https://playground.wordpress.net/blueprint-schema.json',
			'preferredVersions' => array(
				'php' => $this->site->php_version,
				'wp'  => $core->download_url ? $core->download_url : 'https://wordpress.org/wordpress-' . $core->version . '.zip',
			),
			'steps'             => $steps,
		);
	}

	public function write( array $blueprint, $file ) {
		file_put_contents( $file, self::json( $blueprint ) );
	}

	public static function json( array $blueprint ) {
		return self::encode( $blueprint );
	}

	/** Pretty JSON with tabs; takes arrays and objects (a decoded `{}` stays `{}`). */
	private static function encode( $data ) {
		$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return str_replace( '    ', "\t", $json ) . "\n";
	}

	/**
	 * Brings a blueprint.json written by an earlier version up to date: a `meta` without
	 * `author` is invalid for Playground (build-snapshot refuses it), so it is removed.
	 *
	 * @return bool True when the file was changed.
	 */
	public static function migrate( $file ) {
		$data = json_decode( (string) file_get_contents( $file ) );
		if ( ! is_object( $data ) || ! isset( $data->meta ) || ( is_object( $data->meta ) && isset( $data->meta->author ) ) ) {
			return false;
		}
		unset( $data->meta );
		file_put_contents( $file, self::encode( $data ) );
		return true;
	}

	/**
	 * writeFile steps that copy files of the bundle into the site.
	 *
	 * @param string[] $files Relative to the site root (and to the bundle root).
	 */
	private static function write_steps( array $files ) {
		$steps = array();
		$dirs  = array();
		foreach ( $files as $file ) {
			$dir = dirname( $file );
			if ( '.' !== $dir && ! isset( $dirs[ $dir ] ) ) {
				$dirs[ $dir ] = true;
				$steps[]      = array(
					'step' => 'mkdir',
					'path' => '/wordpress/' . $dir,
				);
			}
			$steps[] = array(
				'step' => 'writeFile',
				'path' => '/wordpress/' . $file,
				'data' => array(
					'resource' => 'bundled',
					'path'     => '/' . $file,
				),
			);
		}
		return $steps;
	}

	/** installPlugin/installTheme for wordpress.org and external; null for custom. */
	private function install_step( Package $package, $activate ) {
		$is_plugin = Package::TYPE_PLUGIN === $package->type;
		if ( Package::SOURCE_ORG === $package->source ) {
			$resource = array(
				'resource' => 'url',
				'url'      => $package->download_url,
			);
		} elseif ( Package::SOURCE_EXTERNAL === $package->source ) {
			$resource = array(
				'resource' => 'bundled',
				'path'     => '/' . $this->vendor_path . '/' . $package->type . 's/' . $package->zip_name(),
			);
		} else {
			return null;
		}

		return array(
			'step'                                      => $is_plugin ? 'installPlugin' : 'installTheme',
			( $is_plugin ? 'pluginData' : 'themeData' ) => $resource,
			'options'                                   => array( 'activate' => (bool) $activate ),
		);
	}

	/**
	 * Dependencies ("Requires Plugins") first, otherwise alphabetical.
	 *
	 * @param Package[] $plugins Keyed by slug.
	 * @return Package[]
	 */
	private function plugin_order( array $plugins ) {
		ksort( $plugins );
		$ordered = array();
		$visit   = function ( $slug, $trail ) use ( &$visit, &$ordered, $plugins ) {
			if ( isset( $ordered[ $slug ] ) || ! isset( $plugins[ $slug ] ) || isset( $trail[ $slug ] ) ) {
				return;
			}
			$trail[ $slug ] = true;
			foreach ( $plugins[ $slug ]->requires as $dependency ) {
				$visit( $dependency, $trail );
			}
			$ordered[ $slug ] = $plugins[ $slug ];
		};
		foreach ( array_keys( $plugins ) as $slug ) {
			$visit( $slug, array() );
		}
		return array_values( $ordered );
	}

	/**
	 * Parents before children.
	 *
	 * @param Package[] $themes Keyed by slug.
	 * @return Package[]
	 */
	private function theme_order( array $themes ) {
		ksort( $themes );
		$parents  = array();
		$children = array();
		foreach ( $themes as $theme ) {
			if ( '' !== $theme->template && $theme->template !== $theme->slug ) {
				$children[] = $theme;
			} else {
				$parents[] = $theme;
			}
		}
		return array_merge( $parents, $children );
	}
}
