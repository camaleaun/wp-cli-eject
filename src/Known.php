<?php

namespace Camaleaun\Eject;

use WP_CLI;

/**
 * Known differences (known.yml, plus --known files): modified files that are not
 * code changes count as verified; files a package writes at runtime are ignored
 * and protected on deploy.
 */
class Known {

	/** @var array<int,array> */
	private $rules = array();

	/** @var string[] rsync patterns (anchored at the site root) of known added files. */
	private $protect = array();

	/**
	 * @param string[] $files YAML files, in order.
	 */
	public function __construct( array $files ) {
		foreach ( $files as $file ) {
			if ( ! is_readable( $file ) ) {
				WP_CLI::error( "Known differences file not found: {$file}" );
			}
			$parser = class_exists( 'Mustangostang\\Spyc' ) ? 'Mustangostang\\Spyc' : 'Spyc';
			$rules  = call_user_func( array( $parser, 'YAMLLoad' ), $file );
			foreach ( (array) $rules as $rule ) {
				if ( is_array( $rule ) ) {
					$this->rules[] = $rule;
				}
			}
		}
	}

	/** The bundled known.yml plus the given files. */
	public static function load( array $extra ) {
		return new self( array_merge( array( dirname( __DIR__ ) . '/known.yml' ), $extra ) );
	}

	/**
	 * Applies the rules to a verified package: known modified files move to
	 * verified, known added files are dropped from added.
	 *
	 * @param string $relative Package folder relative to the site root ('' for core).
	 */
	public function apply( Package $package, $relative ) {
		foreach ( $this->rules as $rule ) {
			if ( ! $this->matches( $rule, $package ) ) {
				continue;
			}
			$reason = isset( $rule['reason'] ) ? (string) $rule['reason'] : 'known difference';

			$modified = self::modified_entries( $rule );
			if ( $modified && $package->modified ) {
				$known = array();
				foreach ( $package->modified as $file ) {
					if ( self::modified_matches( $modified, $file, $package->path . '/' . $file ) ) {
						$known[] = $file;
					}
				}
				if ( $known ) {
					$package->modified = array_values( array_diff( $package->modified, $known ) );
					$package->verified = array_merge( $package->verified, $known );
					$package->notes[]  = 'known: ' . count( $known ) . ' modified file(s) count as verified (' . $reason . '): ' . implode( ', ', $known );
				}
			}

			$added = self::globs( $rule, 'added' );
			if ( $added ) {
				$known = array_values( array_filter( $package->added, array( $this->matcher( $added ), '__invoke' ) ) );
				if ( $known ) {
					$package->added   = array_values( array_diff( $package->added, $known ) );
					$package->notes[] = 'known: ' . count( $known ) . ' added file(s) ignored (' . $reason . ')';
				}
				foreach ( $added as $glob ) {
					$this->protect[] = '/' . ( '' === $relative ? '' : $relative . '/' ) . ltrim( $glob, '/' );
				}
			}
		}
	}

	/** @return string[] rsync patterns for deploy/deployignore. */
	public function protected_paths() {
		return array_values( array_unique( $this->protect ) );
	}

	private function matches( array $rule, Package $package ) {
		if ( Package::TYPE_CORE === $package->type ) {
			$applies = ! empty( $rule['core'] );
		} elseif ( isset( $rule['package'] ) ) {
			$applies = '*' === $rule['package'] || $package->slug === $rule['package'];
		} elseif ( isset( $rule[ $package->type ] ) ) {
			$applies = '*' === $rule[ $package->type ] || $package->slug === $rule[ $package->type ];
		} else {
			$applies = false;
		}
		$version = isset( $rule['version'] ) ? (string) $rule['version'] : '*';
		return $applies && self::satisfies( $package->version, $version );
	}

	/**
	 * Composer-like version constraints: '*', '>=2.5', '>=1.0 <2.0' (and),
	 * 'a || b' (or), '^1.2', '~1.2', '1.0 - 2.0', '=1.0' or '1.0'.
	 */
	public static function satisfies( $version, $constraint ) {
		$constraint = trim( $constraint );
		if ( '' === $constraint || '*' === $constraint ) {
			return true;
		}
		foreach ( preg_split( '/\s*\|\|\s*/', $constraint ) as $alternative ) {
			if ( preg_match( '/^(\S+)\s+-\s+(\S+)$/', trim( $alternative ), $range ) ) {
				$alternative = ">={$range[1]} <={$range[2]}";
			}
			$ok = true;
			foreach ( preg_split( '/[\s,]+/', trim( $alternative ) ) as $term ) {
				if ( '' === $term || '*' === $term ) {
					continue;
				}
				if ( ! self::term( $version, $term ) ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				return true;
			}
		}
		return false;
	}

	private static function term( $version, $term ) {
		if ( ! preg_match( '/^(>=|<=|>|<|==|=|!=|\^|~)?v?(\d[\w.+-]*)$/', $term, $match ) ) {
			WP_CLI::error( "Invalid version constraint: {$term}" );
		}
		list( , $operator, $bound ) = $match;
		$parts                      = explode( '.', $bound );
		if ( '^' === $operator ) {
			$upper = ( (int) $parts[0] + 1 ) . '.0.0';
			if ( '0' === $parts[0] && isset( $parts[1] ) ) {
				$upper = '0.' . ( (int) $parts[1] + 1 ) . '.0';
			}
			return version_compare( $version, $bound, '>=' ) && version_compare( $version, $upper, '<' );
		}
		if ( '~' === $operator ) {
			$upper = count( $parts ) > 2
				? $parts[0] . '.' . ( (int) $parts[1] + 1 ) . '.0'
				: ( (int) $parts[0] + 1 ) . '.0.0';
			return version_compare( $version, $bound, '>=' ) && version_compare( $version, $upper, '<' );
		}
		$operator = '' === $operator || '=' === $operator ? '==' : $operator;
		return version_compare( $version, $bound, $operator );
	}

	/**
	 * modified entries as [glob, sha256[]]: a plain string is a glob that accepts
	 * any content; {file, sha256} accepts only those exact contents.
	 *
	 * @return array<int,array{0:string,1:string[]}>
	 */
	private static function modified_entries( array $rule ) {
		$entries = array();
		foreach ( isset( $rule['modified'] ) ? (array) $rule['modified'] : array() as $entry ) {
			if ( is_array( $entry ) ) {
				if ( empty( $entry['file'] ) ) {
					continue;
				}
				$hashes    = isset( $entry['sha256'] ) ? array_map( 'strtolower', array_map( 'strval', (array) $entry['sha256'] ) ) : array();
				$entries[] = array( (string) $entry['file'], $hashes );
			} elseif ( '' !== (string) $entry ) {
				$entries[] = array( (string) $entry, array() );
			}
		}
		return $entries;
	}

	private static function modified_matches( array $entries, $file, $path ) {
		foreach ( $entries as $entry ) {
			list( $glob, $hashes ) = $entry;
			if ( ! fnmatch( ltrim( $glob, '/' ), $file ) ) {
				continue;
			}
			if ( ! $hashes || in_array( hash_file( 'sha256', $path ), $hashes, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * YAML with one rule per package that still has modified or added files:
	 * exact version, current sha256 of each modified file, each added file.
	 * Meant to be reviewed, then passed with --known.
	 *
	 * @param Package[] $packages
	 * @return int Rules written.
	 */
	public static function save( array $packages, $file ) {
		$yaml  = "# Generated by wp eject --save-known. Review each rule, write its reason, then\n";
		$yaml .= "# pass this file with --known. Format: see known.yml in wp-cli-eject-command.\n";
		$count = 0;
		foreach ( $packages as $package ) {
			if ( ! $package->modified && ! $package->added ) {
				continue;
			}
			++$count;
			$key   = Package::TYPE_CORE === $package->type ? 'core: true' : $package->type . ': ' . self::quote( $package->slug );
			$yaml .= "\n- {$key}\n  version: " . self::quote( '=' . $package->version ) . "\n";
			if ( $package->modified ) {
				$yaml .= "  modified:\n";
				foreach ( $package->modified as $modified ) {
					$yaml .= '    - file: ' . self::quote( $modified ) . "\n";
					$yaml .= "      sha256: [ '" . hash_file( 'sha256', $package->path . '/' . $modified ) . "' ]\n";
				}
			}
			if ( $package->added ) {
				$yaml .= "  added:\n";
				foreach ( $package->added as $added ) {
					$yaml .= '    - ' . self::quote( $added ) . "\n";
				}
			}
			$yaml .= "  reason: 'TODO: why this is not a change to the package code'\n";
		}
		file_put_contents( $file, $yaml );
		return $count;
	}

	private static function quote( $value ) {
		return "'" . str_replace( "'", "''", (string) $value ) . "'";
	}

	/** @return string[] */
	private static function globs( array $rule, $key ) {
		return isset( $rule[ $key ] ) ? array_values( array_filter( array_map( 'strval', (array) $rule[ $key ] ) ) ) : array();
	}

	/** A callable object matching a relative file against globs (* also matches /). */
	private function matcher( array $globs ) {
		return new class( $globs ) {
			/** @var string[] */
			private $globs;

			public function __construct( array $globs ) {
				$this->globs = $globs;
			}

			public function __invoke( $file ) {
				foreach ( $this->globs as $glob ) {
					if ( fnmatch( ltrim( $glob, '/' ), $file ) ) {
						return true;
					}
				}
				return false;
			}
		};
	}
}
