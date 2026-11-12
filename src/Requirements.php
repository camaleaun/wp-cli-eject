<?php

namespace Camaleaun\Eject;

use WP_CLI;

/**
 * The tools wp eject and the files it generates rely on. A missing one is a note,
 * not a failure: wp eject itself only needs PHP with the zip extension.
 */
class Requirements {

	/** Whether a command is on the PATH. */
	public static function has( $tool ) {
		$process = proc_open(
			'command -v ' . escapeshellarg( $tool ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		if ( ! is_resource( $process ) ) {
			return false;
		}
		stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return 0 === proc_close( $process );
	}

	/** PHP's zip extension is the one hard requirement. */
	public static function require_zip() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			WP_CLI::error( 'PHP needs the zip extension (ZipArchive): install php-zip, or run with a PHP that has it.' );
		}
	}

	/**
	 * Notes about missing tools.
	 *
	 * @param array<string,string> $needs Tool => what needs it.
	 * @param bool                 $quiet True prints nothing (JSON output).
	 * @return string[] The missing tools.
	 */
	public static function note( array $needs, $quiet ) {
		$missing = array();
		foreach ( $needs as $tool => $why ) {
			if ( ! self::has( $tool ) ) {
				$missing[] = $tool;
				if ( ! $quiet ) {
					WP_CLI::log( WP_CLI::colorize( "%YNote:%n {$tool} was not found: {$why}" ) );
				}
			}
		}
		return $missing;
	}
}
