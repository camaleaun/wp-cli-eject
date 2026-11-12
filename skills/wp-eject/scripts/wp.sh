#!/bin/sh
# Runs WP-CLI: the installed `wp` when there is one, otherwise the phar, downloaded once
# to ~/.wp-cli/wp-cli.phar. Usage: wp.sh <wp arguments>   e.g. wp.sh eject --git-provider=github
# The package is loaded with --require when WP_EJECT_PATH points to a clone of wp-cli-eject-command.
set -e
if command -v wp >/dev/null 2>&1; then
	WP=wp
else
	PHAR="${HOME}/.wp-cli/wp-cli.phar"
	if [ ! -f "$PHAR" ]; then
		mkdir -p "${HOME}/.wp-cli"
		php -r 'copy("https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar", $argv[1]);' "$PHAR"
	fi
	WP="php $PHAR"
fi
if [ -n "${WP_EJECT_PATH:-}" ]; then
	exec $WP --require="${WP_EJECT_PATH}/eject-command.php" "$@"
fi
exec $WP "$@"
