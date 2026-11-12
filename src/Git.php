<?php

namespace Camaleaun\Eject;

use WP_CLI;
use WP_CLI\Utils;

/**
 * --git (default): leave the output folder ready to push. git init when there is
 * no repository, each external package on its own orphan branch with a tag per
 * version (the generated deploy.php's `vendor publish`, without pushing), and the
 * first commit of the main branch when the repository is new.
 */
class Git {

	/** The work branch --git-work-branch makes when it is given without a name. */
	const DEFAULT_BRANCH = 'chore/project-setup';

	/**
	 * The default branch of the repository in $dir: origin's HEAD, else the branch checked
	 * out (also in a repository with no commits yet), else git's init.defaultBranch, else main.
	 * Works for a folder that is not a repository yet (init.defaultBranch, else main).
	 */
	public static function default_branch( $dir ) {
		if ( is_dir( $dir ) && self::is_repo( $dir ) ) {
			$origin = trim( self::capture( array( 'git', '-C', $dir, 'symbolic-ref', '--quiet', '--short', 'refs/remotes/origin/HEAD' ) )[1] );
			if ( '' !== $origin ) {
				return preg_replace( '#^origin/#', '', $origin );
			}
			$current = trim( self::capture( array( 'git', '-C', $dir, 'symbolic-ref', '--quiet', '--short', 'HEAD' ) )[1] );
			if ( '' !== $current ) {
				return $current;
			}
		}
		$configured = trim( self::capture( array( 'git', 'config', '--get', 'init.defaultBranch' ) )[1] );
		return '' !== $configured ? $configured : 'main';
	}

	/**
	 * Leaves the folder ready to push: git init when there is no repository, the
	 * external packages on their own branches and tags, and a commit.
	 *
	 * @param string      $dir        The repository folder (a directory bundle, or the site with --no-bundle).
	 * @param string      $branch     Main branch of a new repository.
	 * @param string      $vendor     --vendor-path.
	 * @param callable    $log
	 * @param string|null $git_branch Work branch for the commit (--git-work-branch).
	 * @param string[]    $externals  Folders of the external packages to publish.
	 * @return array<string,mixed> repository, created, branch, commit, published (set of "type/slug/version"), push (the command), remote.
	 */
	public static function prepare( $dir, $branch, $vendor, $log, $git_branch, array $externals ) {
		$dir     = rtrim( $dir, '/' );
		$summary = array(
			'repository' => $dir,
			'created'    => false,
			'branch'     => $branch,
			'commit'     => null,
			'published'  => array(),
			'push'       => null,
			'remote'     => null,
			'note'       => null,
		);
		if ( 0 !== self::capture( array( 'git', '--version' ) )[0] ) {
			WP_CLI::warning( 'git is not available here: the repository was not prepared (run with a regular wp, or --no-git).' );
			return $summary;
		}

		$log( '' );
		$log( WP_CLI::colorize( '%BGit%n' ) );
		if ( ! self::is_repo( $dir ) ) {
			self::run( array( 'git', '-C', $dir, 'init', '-q', '-b', $branch ) );
			$summary['created'] = true;
			$log( "Created the repository in {$dir} (branch {$branch})." );
		}
		$has_commits = 0 === self::capture( array( 'git', '-C', $dir, 'rev-parse', '--verify', '--quiet', 'HEAD' ) )[0];

		// A work branch on top of the current history; a new repository gets it after its first commit.
		if ( $git_branch && $has_commits ) {
			self::checkout_new( $dir, $git_branch, $log );
		}

		// The external packages: the generated deploy.php does the work (one implementation).
		$summary['published'] = self::publish_externals( $dir, $vendor, $externals );

		$commit_here = ! $has_commits || $git_branch;
		if ( $commit_here ) {
			$identity = 0 === self::capture( array( 'git', '-C', $dir, 'config', 'user.email' ) )[0] ? array() : array( '-c', 'user.name=Project setup', '-c', 'user.email=setup@localhost' );
			self::run( array( 'git', '-C', $dir, 'add', '--all' ) );
			if ( '' === trim( self::capture( array( 'git', '-C', $dir, 'status', '--porcelain', '--untracked-files=no' ) )[1] ) ) {
				$summary['note'] = 'Nothing to commit: the repository already has everything.';
			} else {
				$message = $has_commits ? 'Add the blueprint, the deploy files and the repository docs' : 'Initial project files';
				self::run( array_merge( array( 'git', '-C', $dir ), $identity, array( 'commit', '-q', '-m', $message ) ) );
				$summary['commit'] = trim( self::capture( array( 'git', '-C', $dir, 'rev-parse', '--short', 'HEAD' ) )[1] );
				$now               = trim( self::capture( array( 'git', '-C', $dir, 'rev-parse', '--abbrev-ref', 'HEAD' ) )[1] );
				$log( "Committed the site code on {$now} ({$summary['commit']}): only what .gitignore lets in." );
			}
			if ( $git_branch && ! $has_commits ) {
				self::checkout_new( $dir, $git_branch, $log );
			}
		} else {
			$summary['note'] = 'The repository already has history: nothing was committed. Review `git status`, commit what you want, then push (or use --git-work-branch=<name> to commit on a new branch).';
			if ( 0 === self::capture( array( 'git', '-C', $dir, 'ls-files', '--error-unmatch', 'wp-config.php' ) )[0] ) {
				WP_CLI::warning( 'wp-config.php is tracked by this repository (and stays in its history): untrack it with `git rm --cached wp-config.php`, or start a new history (e.g. `git checkout --orphan main-eject`) before pushing.' );
			}
		}

		$current = trim( self::capture( array( 'git', '-C', $dir, 'rev-parse', '--abbrev-ref', 'HEAD' ) )[1] );
		$refs    = "'refs/heads/vendor/*' 'refs/tags/vendor/*'";
		$remotes = array_values( array_filter( array_map( 'trim', explode( "\n", self::capture( array( 'git', '-C', $dir, 'remote' ) )[1] ) ) ) );
		if ( $remotes ) {
			$remote            = in_array( 'origin', $remotes, true ) ? 'origin' : $remotes[0];
			$summary['remote'] = $remote . ' (' . trim( self::capture( array( 'git', '-C', $dir, 'remote', 'get-url', $remote ) )[1] ) . ')';
			$summary['push']   = "git push -u {$remote} {$current} {$refs}";
		} else {
			$summary['push'] = "git push -u origin {$current} {$refs}";
		}
		$summary['branch'] = $current;
		return $summary;
	}

	/** The final lines of the eject: what to run to send everything to the remote. */
	public static function print_instructions( $summary, $log ) {
		if ( ! $summary || ! $summary['push'] ) {
			return;
		}
		$log( '' );
		$log( WP_CLI::colorize( '%BNext%n' ) );
		if ( $summary['note'] ) {
			$log( $summary['note'] );
		}
		if ( $summary['remote'] ) {
			$log( "Send the branch, the external packages' branches and their tags to {$summary['remote']}:" );
		} else {
			$log( 'This repository has no remote yet. Create an empty repository on your git host, then:' );
			$log( '  git remote add origin <repository-url>' );
		}
		if ( realpath( getcwd() ) !== realpath( $summary['repository'] ) ) {
			$log( '  cd ' . $summary['repository'] );
		}
		$log( '  ' . $summary['push'] );
	}

	/** @return string[] The files git tracks under a path. */
	public static function tracked( $dir, $path ) {
		list( $code, $stdout ) = self::capture( array( 'git', '-C', $dir, 'ls-files', '--', $path ) );
		return 0 === $code ? array_values( array_filter( array_map( 'trim', explode( "\n", $stdout ) ) ) ) : array();
	}

	public static function is_repo( $dir ) {
		return is_dir( rtrim( $dir, '/' ) . '/.git' ) && 0 === self::capture( array( 'git', '-C', $dir, 'rev-parse', '--git-dir' ) )[0];
	}

	/** Pending changes (tracked or untracked, not ignored), one per line; '' when the working tree is clean. */
	public static function pending( $dir ) {
		return trim( self::capture( array( 'git', '-C', $dir, 'status', '--porcelain' ) )[1] );
	}

	/** --git-origin-branch: check the branch out before anything is written; the tree must be clean. */
	public static function use_origin( $dir, $name, $log ) {
		if ( ! is_dir( $dir ) || ! self::is_repo( $dir ) ) {
			return; // A new repository starts on the default branch.
		}
		$current = trim( self::capture( array( 'git', '-C', $dir, 'rev-parse', '--abbrev-ref', 'HEAD' ) )[1] );
		if ( $current === $name ) {
			return;
		}
		if ( 0 !== self::capture( array( 'git', '-C', $dir, 'rev-parse', '--verify', '--quiet', 'refs/heads/' . $name ) )[0] ) {
			WP_CLI::error( "--git-origin-branch: there is no branch {$name} in {$dir}." );
		}
		if ( '' !== self::pending( $dir ) ) {
			WP_CLI::error( "--git-origin-branch: {$dir} has uncommitted changes; commit or stash them before switching to {$name}." );
		}
		self::run( array( 'git', '-C', $dir, 'checkout', '-q', $name ) );
		$log( "Checked out {$name} (was {$current})." );
	}

	private static function checkout_new( $dir, $name, $log ) {
		if ( 0 !== self::capture( array( 'git', '-C', $dir, 'check-ref-format', '--branch', $name ) )[0] ) {
			WP_CLI::error( "--git-work-branch is not a valid branch name: {$name}" );
		}
		$current = trim( self::capture( array( 'git', '-C', $dir, 'rev-parse', '--abbrev-ref', 'HEAD' ) )[1] );
		if ( $current === $name ) {
			return;
		}
		if ( 0 === self::capture( array( 'git', '-C', $dir, 'rev-parse', '--verify', '--quiet', 'refs/heads/' . $name ) )[0] ) {
			WP_CLI::error( "The branch {$name} already exists in {$dir}: choose another --git-work-branch, or check it out first." );
		}
		self::run( array( 'git', '-C', $dir, 'checkout', '-q', '-b', $name ) );
		$log( "Created the branch {$name} from {$current}." );
	}

	/** @var array<string,bool> Whether each repository had no pending changes when wp eject started. */
	private static $clean = array();

	/**
	 * Remembers, before anything is written, whether the working tree has no
	 * pending changes (tracked files only). wp eject's own writes must not count.
	 */
	public static function snapshot( $dir ) {
		$dir = rtrim( $dir, '/' );
		if ( is_dir( $dir . '/.git' ) && 0 === self::capture( array( 'git', '--version' ) )[0] ) {
			self::$clean[ $dir ] = '' === trim( self::capture( array( 'git', '-C', $dir, 'status', '--porcelain', '--untracked-files=no' ) )[1] );
		}
	}

	/**
	 * Why an existing file that wp eject did not write cannot be replaced; '' when
	 * it can: git tracks it and the working tree had no pending changes, so git
	 * keeps the previous version.
	 */
	public static function overwrite_blocker( $dir, $relative ) {
		$dir = rtrim( $dir, '/' );
		if ( ! isset( self::$clean[ $dir ] ) ) {
			return 'the folder is not a git repository';
		}
		if ( 0 !== self::capture( array( 'git', '-C', $dir, 'ls-files', '--error-unmatch', '--', $relative ) )[0] ) {
			return 'git does not track it';
		}
		return self::$clean[ $dir ] ? '' : 'git has uncommitted changes (commit or stash them)';
	}

	/**
	 * Runs `vendor publish --no-push` of the deploy.php that belongs to this repository.
	 *
	 * @param string[] $externals Folders of the external packages.
	 * @return array<string,bool> Set of "type/slug/version" that are on a branch and tag now.
	 */
	private static function publish_externals( $dir, $vendor, array $externals ) {
		if ( ! $externals ) {
			return array();
		}

		if ( ! class_exists( 'CI_Deploy_Command' ) ) {
			$file = $dir . '/deploy/deploy.php';
			if ( ! is_file( $file ) ) {
				// --no-ci: render it to a temporary file just for this.
				$file = tempnam( Utils\get_temp_dir(), 'eject-deploy-' ) . '.php';
				file_put_contents(
					$file,
					Utils\mustache_render(
						dirname( __DIR__ ) . '/templates/deploy-php.mustache',
						array(
							'playground_version' => Pipeline::PLAYGROUND_VERSION,
							'vendor_path'        => trim( $vendor, '/' ),
							'deploy_delete'      => 'mirror',
						)
					)
				);
			}
			require_once $file;
		}

		$cwd       = getcwd();
		$command   = new \CI_Deploy_Command( $dir );
		$published = $command->vendor_publish( $externals, array( 'push' => false ) );
		chdir( $cwd );
		return array_fill_keys( (array) $published, true );
	}

	private static function run( array $command ) {
		list( $code, , $stderr ) = self::capture( $command );
		if ( 0 !== $code ) {
			WP_CLI::error( 'Command failed (' . $code . '): ' . implode( ' ', array_map( 'escapeshellarg', $command ) ) . "\n" . trim( $stderr ) );
		}
	}

	/** @return array{0:int,1:string,2:string} */
	private static function capture( array $command ) {
		$process = proc_open(
			implode( ' ', array_map( 'escapeshellarg', $command ) ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		if ( ! is_resource( $process ) ) {
			return array( 1, '', 'could not run' );
		}
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array( proc_close( $process ), $stdout, $stderr );
	}
}
