camaleaun/wp-cli-eject-command
======================

Turn a WordPress site downloaded from a server into a small repository, a
[Playground blueprint](https://wordpress.github.io/wordpress-playground/blueprints)
that rebuilds its code, and a CI/CD pipeline that deploys it.

[![Testing](https://github.com/camaleaun/wp-cli-eject-command/actions/workflows/testing.yml/badge.svg)](https://github.com/camaleaun/wp-cli-eject-command/actions/workflows/testing.yml)

Quick links: [Using](#using) | [Installing](#installing) | [How it decides](#how-it-decides) | [The pipeline](#the-pipeline) | [For AI agents](#for-ai-agents)

`wp eject`:

1. **verifies** core, plugins and themes against wordpress.org, file by file;
2. **ejects** what is intact and can be downloaded again: it is left out, and the blueprint downloads it;
3. **packs** what is not in the directory (*external*: premium, private,
   host-specific) as zips;
4. **writes `blueprint.json`**, pinned to the exact versions installed;
5. **writes the pipeline** (GitHub Actions, GitLab CI/CD or Bitbucket
   Pipelines) that rebuilds the site from the blueprint and deploys it over SSH.

**By default the site is not changed.** Everything goes into a self-contained
[blueprint bundle](https://wordpress.github.io/wordpress-playground/blueprints/bundles)
(`<site>/<folder>-bundle.zip`) that runs as is in WordPress Playground and,
unzipped, is the project repository:

```
blueprint.json            core, plugins and themes pinned; the site's own code written from the bundle
vendor/plugins/*.zip      external packages (published as their own orphan branches, one tag per version)
wp-content/...            the site's own code: custom packages, mu-plugins, drop-ins, translations, added files
deploy/  + CI file        the pipeline
EJECT.md  .gitignore      what is inside and how to make it the repository
```

`wp-config.php`, uploads, the database, backups and caches, and WordPress Studio's
files are never in it. With `--no-bundle`, the work happens in the site itself
instead (and `--apply` deletes the verified files there).

## Using

~~~
wp eject [--bundle[=<path>]] [--apply] [--keep-plugins=<slugs>] [--keep-themes=<slugs>] [--skip-core]
  [--db] [--php=<version>] [--output=<file>] [--vendor-path=<path>]
  [--activate-with-hooks] [--git-provider=<provider>] [--[no-]ci]
  [--git-deploy-branch=<branch>] [--git-staging-branch=<branch>] [--deploy-delete=<mode>] [--overwrite-ci]
  [--known=<files>] [--save-known=<file>] [--[no-]git] [--git-origin-branch=<name>] [--git-work-branch[=<name>]] [--[no-]cache]
  [--[no-]report] [--format=<format>] [--yes]

wp eject --update-deploy --git-provider=<provider> [--git-deploy-branch=<branch>] [--git-staging-branch=<branch>]
~~~

A modified file stops everything before anything is written. The site is only
read, unless `--no-bundle --apply`.

**OPTIONS**

| Option | |
|---|---|
| `--bundle[=<path>]` | Write the bundle and leave the site untouched (default). A path ending in `.zip` is a zip, anything else a directory; relative to the site root. Default `<site>/<folder>-bundle.zip`. |
| `--no-bundle` | Work in the site itself: blueprint.json, pipeline and (with `--apply`) deletions there. |
| `--apply` | With `--no-bundle`: delete the verified files and pack the external packages next to their folder (asks first; `--yes` to skip). |
| `--keep-plugins=<slugs>` | Comma-separated plugins to keep as custom code (not verified, not ejected; copied into the bundle). |
| `--keep-themes=<slugs>` | Same for themes. |
| `--skip-core` | With `--no-bundle`: verify core but never delete it. |
| `--db` | Load WordPress to read the active plugins, theme and site language, so the blueprint activates them. Off by default: only files are read and no site code runs. |
| `--php=<x.y>` | PHP version for the blueprint. Defaults to the PHP running the command. |
| `--output=<file>` | With `--no-bundle`: blueprint path (default `blueprint.json` in the site root). |
| `--vendor-path=<path>` | Where the external zips are, relative to the blueprint (default `vendor`). |
| `--activate-with-hooks` | Activate plugins one by one with their activation hooks instead of setting `active_plugins`. |
| `--git-provider=<provider>` | `github`, `gitlab` or `bitbucket`. **Required** unless `--no-ci`. |
| `--no-ci` | Write no CI/CD. |
| `--git-deploy-branch=<branch>` | (`--deploy-branch`) Pushes to this branch build and deploy to production. Default: the branch the repository already uses (`origin`'s HEAD, else the checked out branch), so `trunk` stays `trunk` and `master` stays `master`; `main` for a new repository (or git's `init.defaultBranch`). |
| `--git-staging-branch=<branch>` | (`--staging-branch`) Pushes to this branch build and deploy to staging (default `staging`). |
| `--deploy-delete=<mode>` | What the deploy may delete on the server: `mirror` (default), `packages` or `none`. |
| `--overwrite-ci` | Replace generated files (CI, `.gitignore`, `.editorconfig`, `README.md`, `deploy/*`) that exist but were not generated by `wp eject`, even with uncommitted changes. Without it, a file that git tracks is replaced when the working tree is clean (git keeps the previous version). |
| `--known=<files>` | Comma-separated YAML files of [known differences](#known-differences), added to the package's `known.yml`. |
| `--save-known=<file>` | Write a rule for every package that still has modified or added files (exact version, sha256 of each modified file), to review and pass with `--known`. |
| `--no-report` | Print only the verification progress, errors, warnings and the summary. |
| `--no-git` | Do not prepare the repository (see [External packages and git](#external-packages-and-git)). |
| `--git-origin-branch=<name>` | The branch the work branch is made from, checked out first (clean working tree). Default: the branch checked out. |
| `--git-work-branch[=<name>]` | (`--git-branch`) With a directory bundle (or `--no-bundle`) inside a git repository: create a work branch from the current one and commit the eject there, apart from the default branch. Without a name it is `chore/project-setup`. |
| `--no-cache` | Do not read or write WP-CLI's download cache (`~/.wp-cli/cache`), where the zips of released versions and WordPress's checksums are kept and reused. |
| `--update-deploy` | In an ejected repository (it needs `blueprint.json`): regenerate `deploy/deploy.php`, `deploy/README.md`, the CI file, `.gitignore`, `.editorconfig` and `README.md`. `deploy/config.yml` and `deploy/deployignore` are yours and never touched. |
| `--format=<format>` | `table` (default) or `json`. |

Do not use `--skip-plugins`/`--skip-themes`: they are WP-CLI's global
parameters (they skip loading plugins/themes) and never reach the command.

**EXAMPLES**

    # Bundle with GitHub Actions; the site is not changed
    $ wp eject --git-provider=github

    # The client's own plugin and child theme as custom code; the bundle as a directory (the new repo)
    $ wp eject --git-provider=bitbucket --keep-plugins=acme-core --keep-themes=acme-child --bundle=../acme-repo

    # Read the active plugins/theme from the database
    $ wp eject --git-provider=gitlab --db

    # Work in the site itself and delete the verified files there
    $ wp eject --git-provider=gitlab --no-bundle --apply

    # Run a bundle
    $ npx @wp-playground/cli@latest server --blueprint=site-bundle.zip

## Installing

    wp package install camaleaun/wp-cli-eject-command

(WP-CLI resolves the short name on Packagist, then on GitHub.) Or without installing, per run or in the site's `wp-cli.yml` (honored by
[WordPress Studio](https://developer.wordpress.com/studio/)'s `studio wp`):

    wp --require=/path/to/wp-cli-eject-command/eject-command.php eject

Requires PHP 7.4+ with `zip` and network access to `api.wordpress.org` and
`downloads.wordpress.org`. Works with a regular `wp` and with `studio wp` (PHP WASM).

## How it decides

| Found | Checked against | Result |
|---|---|---|
| Core | `api.wordpress.org/core/checksums` (md5) | ejected (left out / deleted); the blueprint pins `wordpress-<version>.zip` |
| Plugin in the directory | `downloads.wordpress.org/plugin-checksums` (sha256); the version zip when there are no checksums | ejected; installed from `<slug>.<version>.zip` |
| Theme in the directory | the zip of the installed version (WP-CLI has no `theme verify-checksums`) | ejected; installed from `<slug>.<version>.zip` |
| Default theme/plugin shipped with core (incl. Hello Dolly) | also the copy bundled in this core and in the latest release of the 8 previous major branches (core updates never refresh an installed default theme) | ejected |
| Not in the directory, or `Update URI` elsewhere | — | **external**: zipped into the bundle's `vendor/` (with `--no-bundle --apply`: next to its folder with an `EJECT.md`, folder removed) |
| `--keep-plugins` / `--keep-themes` | not checked | **custom**: copied into the bundle / kept in the site |

- A **modified** file is an **error**. Nothing is deleted or written; the command
  exits with 1 after printing the whole report.
- An **added** file is a **warning**. It is never deleted; it goes into the bundle.
- A **missing** file is a **warning**. Reinstalling brings it back.
- `--no-bundle --apply` only deletes files whose hash matched. Directories left empty are removed.

Every run ends with a **Not ejected** table: each item kept and why (with a
`bundle` column telling whether it went into the bundle)
(`wp-config.php`, mu-plugins, drop-ins, uploads, leftovers such as backups and
`upgrade/`, custom packages, the pipeline files and the WordPress Studio files):

| Studio file | Why it stays |
|---|---|
| `wp-content/db.php` | SQLite drop-in: the local site does not boot without it |
| `wp-content/database/` | the SQLite database |
| `wp-content/mu-plugins/sqlite-database-integration/` | if `db.php`, `database/` and this are all gone, Studio treats the site as MySQL and never reinstalls SQLite |
| `wp-content/mu-plugins/99-studio-loader.php` | Studio rewrites it on every start |
| `AGENTS.md`, `CLAUDE.md`, `STUDIO.md`, `.agents/`, `.claude/` | Studio AI instructions and skills |

### Known differences

Some differences are not changes to a package's code. [`known.yml`](known.yml)
lists them, and `--known=<file>` adds a project's own:

```yaml
- plugin: woocommerce-shipping   # or theme: <slug>, core: true, package: '*' (any plugin or theme)
  version: '>=2.5 <3'            # Composer-like: '*', '>=2.5', '^2.5', '~2.5', '1.0 - 2.0', 'a || b'
  modified:                      # count as verified
    - 'vendor/*/.git/index'      # glob relative to the package folder (* also matches /)
    - file: includes/class-foo.php
      sha256: [ 'ab12…' ]        # only these exact contents are accepted
  added:                         # files the package writes at runtime
    - 'storage/*'
  reason: why this is not a change to the package code
```

- A known **modified** file counts as verified. With `sha256`, any other content
  is still an error.
- A known **added** file is not a warning and not bundled, and it goes into
  `deploy/deployignore`, so a deploy never deletes it on the server.
- The report says `known: … (reason)` for each one.

The package already knows git indexes of repositories nested in a package
(`*/.git/index`: tools that scan nested repositories rewrite their stat cache)
and All-in-One WP Migration's `storage/` files. To start a project list from a
site, run with `--save-known=known.yml`, write each `reason`, then pass
`--known=known.yml`.

### The blueprint

- `preferredVersions`: the site's PHP and the exact core zip.
- Core-bundled themes/plugins the site deleted are removed.
- Themes (parents first) and plugins (`Requires Plugins` dependencies first) are
  installed from their pinned zips; external ones from
  `{"resource": "bundled", "path": "/vendor/plugins/<slug>-<version>.zip"}`.
- With `--db`, the active theme is activated and `active_plugins` is set at the end
  exactly as in the site's database (running many activation hooks in a row is
  not deterministic; a deployed site keeps its own database anyway).
- The site's own code: in a bundle, `writeFile` steps copy it from the bundle
  (before the installs; files added inside a package, after its install). With
  `--no-bundle` it is not installed (it comes from the repository), only activated.
- Content, database and uploads are not part of it.

### External packages and git

Each external package lives on its **own orphan branch**, `vendor/plugin/<slug>`
(or `vendor/theme/<slug>`), with its files at the branch root and **one tag per
version**, `vendor/plugin/<slug>/<version>`. A new version is a new commit, so git
stores only what changed and the history stays; zips never enter git. `build`
fetches only the tags the blueprint needs and turns them into the zips it expects
(`git archive`).

With `--git` (the default), `wp eject` leaves a directory bundle, or the site with
`--no-bundle`, **ready to push**:

1. `git init` (branch `--git-deploy-branch`) when there is no repository;
2. each external package as its branch and tag (`vendor publish`, without pushing);
3. the first commit of a new repository, with only what `.gitignore` lets in;
4. the command to push: `git remote add origin <url> && git push -u origin main 'refs/heads/vendor/*' 'refs/tags/vendor/*'`.

A directory bundle can go **into an existing repository** whose working tree is
clean: files are written over it and nothing is deleted. With `--git-work-branch=<name>`
the branch is created from the current one and the eject is committed there;
without it nothing is committed. `wp-config.php` tracked by the repository is
warned about. A zip bundle skips git; `--no-git` turns it off.

The eject ends with how to publish: the `git push` for the current branch and the
vendor branches and tags, or `git remote add origin <url>` first when there is no
remote. With git, `--no-bundle --apply` publishes an external package's folder
straight to its branch and tag (no zip, no `wp-content/EJECT.md`); the root
`README.md` says where each package comes from. Without git, the folder becomes a
zip next to it and `wp-content/EJECT.md` says how to restore it.

`wp eject` checks the tools it relies on: `git`, `npx`, `rsync` (a note, not an
error) and PHP's `zip` extension (an error). Without `npx`, build from the
blueprint with the pure PHP script in the skill (`scripts/build-from-blueprint.php`).

Downloads of released versions are cached in WP-CLI's cache (`~/.wp-cli/cache`,
`WP_CLI_CACHE_DIR`), under the same keys `wp plugin install` uses. A cached zip is
never trusted to call code modified: it is downloaded again once first.

Later, `wp --require=deploy/deploy.php vendor publish` publishes new versions, and
`vendor list` shows what is published and what is pending.

### Undo the eject locally

    wp --require=deploy/deploy.php restore

It builds the site from the blueprint (external zips from the folder or from
their tags) and copies it back, without touching `wp-config.php`, the local
database, `deploy/` or `.git`. git still ignores the restored files.

## The pipeline

Generated from Mustache templates (like `wp scaffold`), in the repository root:

| File | |
|---|---|
| `deploy/deploy.php` | WP-CLI commands that run without WordPress, self-contained (they do not need this package): **`build`** (blueprint → `build/`, plus the repository's own code and `build.commands`), **`deploy`** (maintenance mode on → optional backup → copy with rsync or tarball → `deploy.after` → maintenance mode off, also on failure → Slack), **`full`** (both) and **`maintenance on\|off`**. Regenerated by `wp eject` |
| `deploy/config.yml` | **the project's settings**, written once and never overwritten: `deploy.delete`, `strategy`, `paths`, `maintenance`, `backup`, `after`, `build.commands`, `environments.<env>.path`, `notify.slack_webhook` |
| `deploy/README.md` | provider setup, commands, every setting, maintenance page, backups, troubleshooting |
| `deploy/deployignore` | never copied into the build, never deployed, never deleted on the server (`wp-config.php`, `.htaccess`, `.maintenance`, uploads, caches, `.env`, dumps, logs…). Edit freely. |
| `.github/workflows/deploy.yml` / `.gitlab-ci.yml` / `bitbucket-pipelines.yml` | build on pull requests; production on `--git-deploy-branch`, staging on `--git-staging-branch`; manual runs for deploy, build only and maintenance on/off per environment |

`DEPLOY_DELETE` (`deploy --delete`, default from `--deploy-delete`, overridable as a CI variable):

- `mirror`: the server ends up equal to the build, except `deployignore`;
- `packages`: deletes only inside each plugin, theme and core directory of the build;
- `none`: only adds and updates.

Variables: `SSH_HOST`, `SSH_USER`, `SSH_PORT`, `DEPLOY_PATH`, `SSH_PRIVATE_KEY`,
`SSH_KNOWN_HOSTS`. Try the deploy locally first:

    wp --require=deploy/deploy.php build
    wp --require=deploy/deploy.php deploy --to=/tmp/server-copy --dry-run
    wp --require=deploy/deploy.php full --env=staging
    wp --require=deploy/deploy.php maintenance on --env=production

The CI installs PHP, WP-CLI and Node.js (for the Playground CLI) and runs the same
two commands.

Running `wp eject` again regenerates the files it wrote (they carry a
`Generated by wp eject` marker); other CI files are never overwritten without
`--overwrite-ci`.

## Troubleshooting

- **`Deprecated: Implicitly marking parameter ... as nullable` from `phar:///.../composer/...`** (PHP 8.4 with `wp package install`): the
  old Composer bundled in the WP-CLI phar. It is harmless and not from this package. Hide it with
  `php -d error_reporting='E_ALL & ~E_DEPRECATED' $(which wp) package install camaleaun/wp-cli-eject-command`
  or `error_reporting = E_ALL & ~E_DEPRECATED` in `php.ini`.
- **`wp eject` warns "temporary file of an interrupted transfer (rsync)"**: a file like `.name.php.AbC123` left by a `rsync` that
  stopped. It is ignored (not kept, not bundled, not committed): delete it.
- **`restore` and `build` print little**: the tools' own output (npm, Playground, rsync) is shown only when they fail, or live with
  WP-CLI's global `--debug`.

## For AI agents

[`skills/wp-eject/SKILL.md`](skills/wp-eject/SKILL.md) teaches an agent (Claude
Code, Codex, Studio's agents…) when and how to use the command, read the report
and set up the pipeline. Copy or link it into the agent's skills directory
(e.g. `.claude/skills/wp-eject` or `.agents/skills/wp-eject`).

## Contributing

    composer install
    WP_CLI_TEST_DBTYPE=sqlite composer prepare-tests
    WP_CLI_TEST_DBTYPE=sqlite composer behat

The Behat features talk to the live wordpress.org APIs.

## License

MIT, like WP-CLI and its commands.
