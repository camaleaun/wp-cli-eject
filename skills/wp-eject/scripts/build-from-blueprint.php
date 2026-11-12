<?php
/**
 * Builds the site's code from a blueprint.json written by wp eject, in plain PHP:
 * no Node, no Playground. Usage:
 *
 *   php build-from-blueprint.php <blueprint.json> <out-dir>
 *
 * It downloads the WordPress zip the blueprint pins, installs the plugins and themes
 * (a URL, or a zip bundled with the repository: run `vendor fetch` first), and applies
 * writeFile, mkdir, rm and rmdir. Steps that need a database (activation, options,
 * language) are listed and skipped: the deployed site keeps its own database.
 */

if ( PHP_SAPI !== 'cli' || $argc < 3 ) {
	fwrite( STDERR, "Usage: php build-from-blueprint.php <blueprint.json> <out-dir>\n" );
	exit( 1 );
}
if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The PHP zip extension is required.\n" );
	exit( 1 );
}

$blueprint_file = realpath( $argv[1] );
$out            = rtrim( $argv[2], '/' );
$base           = dirname( $blueprint_file );
$blueprint      = json_decode( (string) file_get_contents( $blueprint_file ), true );
if ( ! is_array( $blueprint ) ) {
	fwrite( STDERR, "Not a valid blueprint: {$argv[1]}\n" );
	exit( 1 );
}

function fetch_zip( $source, $base ) {
	if ( is_array( $source ) && 'bundled' === ( $source['resource'] ?? '' ) ) {
		$path = $base . $source['path'];
		if ( ! is_file( $path ) ) {
			fwrite( STDERR, "Missing {$source['path']}: run `wp --require=deploy/deploy.php vendor fetch` first.\n" );
			exit( 1 );
		}
		return array( $path, false );
	}
	$url  = is_array( $source ) ? $source['url'] : $source;
	$file = tempnam( sys_get_temp_dir(), 'wp-eject-' );
	if ( ! copy( $url, $file ) ) {
		fwrite( STDERR, "Could not download {$url}\n" );
		exit( 1 );
	}
	return array( $file, true );
}

function extract_to( $zip_file, $dest, $strip ) {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_file ) ) {
		fwrite( STDERR, "Cannot open {$zip_file}\n" );
		exit( 1 );
	}
	$names = array();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$names[] = $zip->getNameIndex( $i );
	}
	// A zip with one top folder: the folder is the package (plugin) or "wordpress" (core).
	$top = strstr( $names[0], '/', true );
	foreach ( $names as $i => $name ) {
		$relative = $strip ? substr( $name, strlen( $top ) + 1 ) : $name;
		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			continue;
		}
		$target = $dest . '/' . $relative;
		if ( '/' === substr( $name, -1 ) ) {
			@mkdir( $target, 0755, true );
			continue;
		}
		@mkdir( dirname( $target ), 0755, true );
		file_put_contents( $target, $zip->getFromIndex( $i ) );
	}
	$zip->close();
}

function remove_tree( $path ) {
	if ( is_file( $path ) || is_link( $path ) ) {
		unlink( $path );
		return;
	}
	foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $child ) {
		remove_tree( $path . '/' . $child );
	}
	@rmdir( $path );
}

@mkdir( $out, 0755, true );
$wp = $blueprint['preferredVersions']['wp'] ?? 'latest';
if ( 'latest' === $wp ) {
	$wp = 'https://wordpress.org/latest.zip';
}
echo 'WordPress: ' . ( is_array( $wp ) ? $wp['path'] : $wp ) . "\n";
list( $file, $temp ) = fetch_zip( $wp, $base );
extract_to( $file, $out, true );
$temp && unlink( $file );

foreach ( $blueprint['steps'] ?? array() as $step ) {
	$name = $step['step'] ?? '';
	$path = isset( $step['path'] ) ? $out . preg_replace( '#^/wordpress#', '', $step['path'] ) : '';
	switch ( $name ) {
		case 'installPlugin':
		case 'installTheme':
			$kind                = 'installPlugin' === $name ? 'plugins' : 'themes';
			$source              = $step['installPlugin' === $name ? 'pluginData' : 'themeData'];
			list( $file, $temp ) = fetch_zip( $source, $base );
			echo "install {$kind}: " . basename( $source['url'] ?? $source['path'] ) . "\n";
			extract_to( $file, $out . '/wp-content/' . $kind, false );
			$temp && unlink( $file );
			break;
		case 'writeFile':
			@mkdir( dirname( $path ), 0755, true );
			$data = $step['data'];
			file_put_contents( $path, is_array( $data ) ? file_get_contents( $base . $data['path'] ) : $data );
			break;
		case 'mkdir':
			@mkdir( $path, 0755, true );
			break;
		case 'rm':
		case 'rmdir':
			remove_tree( $path );
			break;
		default:
			echo "skipped (needs a database): {$name}\n";
	}
}
echo "Built in {$out}\n";
