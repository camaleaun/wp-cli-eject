<?php

namespace Camaleaun\Eject\Checksums;

use Camaleaun\Eject\Package;

/**
 * Compares the files of a package with the hashes wordpress.org publishes.
 */
class Compare {

	/**
	 * @param Package                    $package  Filled with verified/modified/missing/added.
	 * @param array<string,string[]>     $expected Relative path => accepted hashes.
	 * @param string                     $algo     md5 or sha256.
	 * @param string[]                   $on_disk  Relative paths present (to find added files).
	 */
	public static function run( Package $package, array $expected, $algo, array $on_disk ) {
		foreach ( $expected as $file => $hashes ) {
			$path = $package->path . '/' . $file;
			if ( ! is_file( $path ) ) {
				$package->missing[] = $file;
				continue;
			}
			if ( in_array( hash_file( $algo, $path ), $hashes, true ) ) {
				$package->verified[] = $file;
			} else {
				$package->modified[] = $file;
			}
		}
		foreach ( $on_disk as $file ) {
			if ( ! isset( $expected[ $file ] ) ) {
				$package->added[] = $file;
			}
		}
	}

	/**
	 * Normalizes a checksum value (string or list of strings) to a list.
	 *
	 * @return string[]
	 */
	public static function hashes( $value ) {
		return array_values( array_filter( array_map( 'strtolower', (array) $value ), 'is_string' ) );
	}
}
