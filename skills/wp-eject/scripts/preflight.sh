#!/bin/sh
# Checks the tools wp eject and the repository it generates use, and says what to do
# without each one. Exit status 1 only when something REQUIRED is missing.
required=0
have() { command -v "$1" >/dev/null 2>&1; }

echo "Required"
if have php; then
	if php -r 'exit(class_exists("ZipArchive") ? 0 : 1);'; then
		echo "  ok       php (with the zip extension)"
	else
		echo "  MISSING  php zip extension: install php-zip (or enable extension=zip)"; required=1
	fi
else
	echo "  MISSING  php: install PHP 7.4 or newer"; required=1
fi
if have wp; then
	echo "  ok       wp"
else
	echo "  missing  wp: run it through scripts/wp.sh, which downloads the phar to ~/.wp-cli/"
fi

echo "Optional"
if have git; then echo "  ok       git"; else echo "  missing  git: use --no-git (zips + wp-content/EJECT.md instead of branches and tags)"; fi
if have npx; then echo "  ok       npx"; else echo "  missing  npx/npm: build without Node: php scripts/build-from-blueprint.php blueprint.json <out-dir>"; fi
if have rsync; then echo "  ok       rsync"; else echo "  missing  rsync: deploy with deploy.strategy: tarball (config.yml)"; fi
if have tar; then echo "  ok       tar"; else echo "  missing  tar: needed by the tarball strategy"; fi
if have curl; then echo "  ok       curl"; else echo "  missing  curl: not needed, PHP does the downloads"; fi
exit $required
