<?php
/**
 * Registers `wp eject`.
 *
 * Load with `wp package install`, Composer, or `--require=<path>/eject-command.php`
 * (e.g. in the site's wp-cli.yml, which `studio wp` honors).
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

if ( ! class_exists( 'Camaleaun\\Eject\\Command' ) ) {
	spl_autoload_register(
		static function ( $class_name ) {
			$prefix = 'Camaleaun\\Eject\\';
			if ( 0 !== strpos( $class_name, $prefix ) ) {
				return;
			}
			$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
			if ( is_file( $file ) ) {
				require $file;
			}
		}
	);
}

WP_CLI::add_command( 'eject', 'Camaleaun\\Eject\\Command', array( 'when' => 'before_wp_load' ) );
