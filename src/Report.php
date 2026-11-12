<?php

namespace Camaleaun\Eject;

use WP_CLI;
use WP_CLI\Utils;

/**
 * Prints what was found. Modified files are errors, added and missing files
 * are warnings; everything left in place is listed under "Not ejected".
 */
class Report {

	/** Files listed per package and kind before "… and N more". */
	const LIMIT = 10;

	/**
	 * @param Package[] $packages
	 */
	public static function table( array $packages ) {
		$rows = array();
		foreach ( $packages as $package ) {
			$rows[] = array(
				'type'     => $package->type,
				'slug'     => $package->slug,
				'version'  => $package->version,
				'source'   => $package->source,
				'active'   => null === $package->active ? '?' : ( $package->active ? 'yes' : 'no' ),
				'status'   => $package->status(),
				'verified' => count( $package->verified ),
				'modified' => count( $package->modified ),
				'added'    => count( $package->added ),
				'missing'  => count( $package->missing ),
			);
		}
		Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
	}

	/**
	 * Detailed lines for every package with notes or problems.
	 *
	 * @param Package[] $packages
	 */
	public static function details( array $packages ) {
		foreach ( $packages as $package ) {
			foreach ( $package->notes as $note ) {
				WP_CLI::log( "{$package->label()}: {$note}" );
			}
			if ( $package->unverifiable ) {
				WP_CLI::warning( "{$package->label()}: could not be verified; nothing will be ejected." );
			}
			foreach ( self::cap( $package->modified ) as $file ) {
				WP_CLI::log( WP_CLI::colorize( "%RError:%n {$package->label()}: modified {$file}" ) );
			}
			foreach ( self::cap( $package->added ) as $file ) {
				WP_CLI::warning( "{$package->label()}: added {$file} (kept)" );
			}
			foreach ( self::cap( $package->missing ) as $file ) {
				WP_CLI::warning( "{$package->label()}: missing {$file} (comes back on reinstall)" );
			}
		}
	}

	/**
	 * @param array<string,string>    $kept      Path => reason.
	 * @param array<string,bool>|null $in_bundle Paths that went into the bundle; null without a bundle.
	 */
	public static function kept( array $kept, $in_bundle = null ) {
		if ( ! $kept ) {
			return;
		}
		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%BNot ejected%n' ) );
		$rows = array();
		foreach ( $kept as $path => $reason ) {
			$row = array( 'path' => $path );
			if ( null !== $in_bundle ) {
				$row['bundle'] = isset( $in_bundle[ $path ] ) ? 'yes' : 'no';
			}
			$row['reason'] = $reason;
			$rows[]        = $row;
		}
		Utils\format_items( 'table', $rows, null === $in_bundle ? array( 'path', 'reason' ) : array( 'path', 'bundle', 'reason' ) );
	}

	/**
	 * Machine-readable version of everything.
	 *
	 * @param Package[]            $packages
	 * @param array<string,string> $kept
	 */
	public static function json( array $packages, array $kept, array $summary ) {
		$out = array(
			'summary'     => $summary,
			'packages'    => array(),
			'not_ejected' => $kept,
		);
		foreach ( $packages as $package ) {
			$out['packages'][] = array(
				'type'         => $package->type,
				'slug'         => $package->slug,
				'name'         => $package->name,
				'version'      => $package->version,
				'source'       => $package->source,
				'active'       => $package->active,
				'status'       => $package->status(),
				'download_url' => $package->download_url,
				'verified'     => count( $package->verified ),
				'modified'     => $package->modified,
				'added'        => $package->added,
				'missing'      => $package->missing,
				'notes'        => $package->notes,
			);
		}
		WP_CLI::line( json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private static function cap( array $files ) {
		if ( count( $files ) <= self::LIMIT ) {
			return $files;
		}
		$shown   = array_slice( $files, 0, self::LIMIT );
		$shown[] = '… and ' . ( count( $files ) - self::LIMIT ) . ' more';
		return $shown;
	}
}
