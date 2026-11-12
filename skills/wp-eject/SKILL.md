---
name: wp-eject
description: Use when a WordPress site downloaded from a server (backup, All-in-One import, rsync, WordPress Studio site) must become a small git repository plus a Playground blueprint that rebuilds its code and a CI/CD pipeline (GitHub Actions, GitLab CI/CD or Bitbucket Pipelines) that deploys it over SSH — with `wp eject` (camaleaun/wp-cli-eject-command). Covers installing and running it (plain wp and `studio wp`), the default non-destructive flow (a self-contained blueprint bundle zip/directory that is also the new repository; --no-bundle/--apply only to trim the site in place), --keep-* decisions, reading ERROR/WARNING/Not ejected, external packages as orphan branches with a tag per version (vendor publish, --git ready to push), undoing the eject locally (restore), running the bundle in the Playground CLI to prove it, and setting up the generated pipeline and its DEPLOY_DELETE mode safely.
---

# wp eject

`wp eject` checks every file of core, plugins and themes against wordpress.org.
What is intact is left out, because the blueprint downloads it again. What is not
in the directory is zipped (*external*). The site's own code is kept. It writes
`blueprint.json`, pinned to the installed versions, and a pipeline that rebuilds
the site from the blueprint and deploys it.

**By default the site is only read.** Everything goes into a self-contained
Playground blueprint bundle (`<site>/<folder>-bundle.zip`, or a directory with
`--bundle=<dir>`). The bundle runs as is in Playground and, unzipped, it is the
project repository. `--no-bundle` works in the site itself, and `--no-bundle
--apply` deletes the verified files there.

```
downloaded site ─► wp eject (bundle) ─► read report ─► --keep-* decisions ─► wp eject again
     ─► run/compare the bundle ─► (--bundle=<dir>: a git repository, externals as branches + tags) ─► push = deploy
```

## Install / run

```bash
wp package install camaleaun/wp-cli-eject-command      # global (Packagist → GitHub fallback)
wp --require=/path/to/wp-cli-eject-command/eject-command.php eject …   # one-off
```

In a WordPress Studio site, run it through Studio's WP-CLI. The site's
`wp-cli.yml` can `require:` the command:

```bash
studio wp --require=/path/to/wp-cli-eject-command/eject-command.php eject --git-provider=github
```

It needs network access to `api.wordpress.org` and `downloads.wordpress.org`.
Verification takes about 2–3 minutes for a site with ~30 packages.

## The flow

1. **Bundle** (the site is not changed):
   ```bash
   wp eject --git-provider=<github|gitlab|bitbucket>
   ```
   `--git-provider` is required; use `--no-ci` only if no CI/CD is wanted.
   Ask the user where the project repository lives if you do not know.
   `--bundle=../acme-repo` writes a directory instead of the zip, which is handy
   to start the repository directly. Paths are relative to the site root. In
   Studio's `studio wp`, only paths inside the site folder can be written.
2. **Read the report** (see below) and decide what is the client's own code:
   `--keep-plugins=a,b --keep-themes=c`. These are kept as *custom*: not
   verified, not ejected, copied into the bundle as they are. Run again with them.
3. **Active plugins/theme:** by default no database is used and no site code
   runs, so the blueprint activates nothing. If the site has a working database
   (e.g. a running Studio site), add `--db` to mirror the active plugins, theme
   and language.
4. **Prove the bundle** before trusting it (see "Verify").
5. **Inside an existing repository:** `--bundle=<repo-dir> --git-work-branch`
   (clean working tree required; the branch is `chore/project-setup`, or the name you give)
   creates the branch from the repository's default branch (the deploy branch follows it: trunk, master, main), writes the files over the repo and
   commits; the end of the output gives the `git push`/`git remote add` to run.
   Ask before pushing. A regenerated file that git already tracks is replaced when the
   tree is clean; `--overwrite-ci` forces it.
6. **Later:** `wp eject --update-deploy --git-provider=<p>` in the repository
   regenerates the deploy files; `deploy/config.yml` and `deploy/deployignore` are never touched.

## When a tool is missing

Run `scripts/preflight.sh` first. Alternatives, in `scripts/`:

| Missing | Use |
|---|---|
| `wp` | `scripts/wp.sh <args>` (downloads the phar to `~/.wp-cli/`; `WP_EJECT_PATH` = this package) |
| `git` | `--no-git` (zips and `wp-content/EJECT.md` instead of branches and tags) |
| `npx` / `npm` | `php scripts/build-from-blueprint.php blueprint.json <out-dir>` (core + packages + files, no Node; run `vendor fetch` before for external zips) |
| `rsync` | `deploy.strategy: tarball` in `deploy/config.yml` |

Downloads are cached in `~/.wp-cli/cache` (`--no-cache` to skip).
5. **Make it the repository:** unzip it into an empty repo and follow its
   `EJECT.md`. Everything is committed on the main branch except `vendor/`
   (`.gitignore` leaves it out): each external package lives on its own orphan
   branch `vendor/plugin/<slug>` with a tag per version, so git stores only what
   changes. With `--bundle=<dir>`, `--git` (default) already did `git init`, the
   branches and tags and the first commit; only the remote and the push are
   left. Otherwise run `wp --require=deploy/deploy.php vendor publish`.
6. Only if the user wants the downloaded copy itself trimmed down:
   `wp eject --no-bundle --apply --yes …`. Use a copy or a clean git tree; it
   deletes thousands of files and the local site stops booting until it is rebuilt.

## Reading the report

| You see | Meaning | Do |
|---|---|---|
| `Error: … modified <file>` and exit 1 | a file differs from wordpress.org; **nothing was written or deleted** | show the user the file; restore the original (reinstall that version) or keep the package with `--keep-*`. Never "fix" it by editing the checksum logic |
| `could not be verified … (HTTP 0)` / `checksums request failed` | network failure (common in Studio's PHP WASM) | run again; it is not a modified file |
| `Warning: … added <file> (kept)` | a file wordpress.org does not ship (logs, `.htaccess` in `storage/`, leftovers) | fine; it stays in the repo. Tell the user if it looks like real code |
| `Warning: …: temporary file of an interrupted transfer (rsync)` | a `.name.php.AbC123` file left by a stopped rsync | it is ignored (not kept, not bundled, not committed); tell the user to delete it |
| `Warning: … missing <file>` | wordpress.org ships it, the site lacks it | fine; the rebuild brings it back |
| `files: N match the directory zip, M match WordPress X.Y's bundled copy` | a default theme whose files come from the directory zip and from the copy bundled with this or an older core (core updates never refresh an installed one); each file only needs one official copy | normal |
| `not in the wordpress.org directory` → `source: external` | premium/private/host plugin | it will be zipped; check with the user it is not their own code (`--keep-plugins` if it is) |
| **Not ejected** table | everything left in place and why (wp-config.php, mu-plugins, drop-ins, uploads, Studio files, pipeline files, custom packages) | read it to the user; review "not code WordPress loads" leftovers (backups, `upgrade/`) by hand |

`--format=json` gives the same as JSON (`summary.errors`, `packages[]`,
`not_ejected`) for scripts.

## Undo, publish, list

- `wp --require=deploy/deploy.php restore`: undoes the eject locally (builds and
  copies the site's code back; `wp-config.php` and the local database untouched).
- `wp --require=deploy/deploy.php vendor publish [--no-push]`: external zips →
  orphan branches + tags. Never rewrites a published version.
- `wp --require=deploy/deploy.php vendor list`: what is published and what is pending.

## Known differences

Before calling a modified or added file a problem, check whether it is noise:
- nested `.git/index` files: only their stat cache changes. They are known already.
- files a plugin writes at runtime (storage folders, caches), like All-in-One WP
  Migration's `storage/`, which is known already.

For a new case, run with `--save-known=known.yml`. Show the user the rules, fill
in each `reason` with them, then pass `--known=known.yml`. Keep the `sha256` for
modified code files, so any further change is still an error. Drop it only for
files whose content legitimately changes, such as git indexes. Never write a rule
to hide a real code change.

`--no-report` gives a short output: the verification progress, errors, warnings and the summary.

## Rules

- `--skip-plugins` / `--skip-themes` are **WP-CLI global parameters** (they skip
  loading plugins). They never reach `wp eject`; use `--keep-plugins` / `--keep-themes`.
- Never delete WordPress Studio's SQLite files (`wp-content/db.php`,
  `wp-content/database/`, `wp-content/mu-plugins/sqlite-database-integration/`).
  If all three are gone, Studio treats the site as MySQL and will not reinstall
  SQLite, and the site stops booting. `wp eject` never touches them.
- Running with the system PHP instead of the site's? Pass `--php=8.3` (major.minor)
  so the blueprint uses the site's PHP.
- `--db` only with a working database: it loads WordPress and runs the site's plugins.
- `--apply` only works with `--no-bundle`; a bundle never changes the site.
- An ejected copy (`--no-bundle --apply`) cannot be ejected again: core is gone and the command stops
  with "No WordPress install found". Rebuild it (`wp --require=deploy/deploy.php build`) to get a
  full tree back.

## Verify (rebuild and compare)

A zip bundle runs as is:

```bash
npx @wp-playground/cli@latest server --blueprint=./site-bundle.zip
```

To compare versions, unzip it, add a final `runPHP` step to `blueprint.json`
that writes `get_plugins()`, `wp_get_themes()` versions and `get_bloginfo('version')`
to a mounted folder, then run it:

```bash
npx @wp-playground/cli@latest run-blueprint --blueprint=./bundle/blueprint.json \
  --blueprint-may-read-adjacent-files --mount=./out:/out
```

The plugin and theme sets and their versions must match the original site.
Hello Dolly may appear only if the site has it. For the deploy build, use
`wp --require=deploy/deploy.php build` inside the unzipped bundle: `vendor/` is already there,
so it does not fetch the branch. Then compare `build/`.

## The pipeline

Generated from Mustache templates. Files carry a `Generated by wp eject` marker
and are regenerated on each run; other CI files are left alone unless
`--overwrite-ci`.

- `deploy/deploy.php`: WP-CLI commands, self-contained, that run without WordPress:
  `build` (blueprint → `build/`, then `build.commands`), `deploy` (maintenance on →
  optional backup → rsync or tarball → `deploy.after` → maintenance off, also on
  failure), `full` (both) and `maintenance on|off`. `--env=staging` reads
  `STAGING_*` variables first. `--dry-run` previews. Regenerated by `wp eject`.
- `deploy/config.yml`: the project's settings (delete mode, strategy, paths,
  maintenance, backup, after, build commands, environments, Slack). Written once,
  never overwritten: edit it there, not in `deploy.php`.
- `deploy/README.md`: the provider's variables, preview, modes, troubleshooting.
  Point the user to it.
- `deploy/deployignore`: never deployed **and never deleted on the server**.
  `wp-config.php`, `.htaccess`, uploads, caches and Studio files are already listed.
- CI: build on push and on PRs/MRs, deploy on pushes to `--git-deploy-branch` (default: the repository's default branch).

`DEPLOY_DELETE` (`deploy --delete` / CI variable; `wp eject --deploy-delete` sets the default):

| Mode | Deletes on the server | Use when |
|---|---|---|
| `mirror` (default) | everything not in the build, except deployignore | the repo is the whole truth; **check deployignore covers every server-only path first** (root files like verification HTML, server-managed mu-plugins, cache drop-ins) |
| `packages` | only inside each plugin/theme/core directory of the build | the server may have plugins the repo does not |
| `none` | nothing | first deploys, or when unsure |

Before the first real deploy:

1. Set the variables: `SSH_HOST`, `SSH_USER`, `DEPLOY_PATH`, `SSH_PRIVATE_KEY`
   (or Bitbucket's SSH keys), optionally `SSH_PORT` and `SSH_KNOWN_HOSTS`.
2. Preview against a copy of the server or with a dry run:
   ```bash
   wp --require=deploy/deploy.php build
   wp --require=deploy/deploy.php deploy --to=/tmp/server-copy                      # local folder
   SSH_HOST=… SSH_USER=… wp --require=deploy/deploy.php deploy --to=… --dry-run      # real server, no changes
   ```
   Read every `*deleting` line of the dry run with the user before switching
   `mirror` on.
3. A push to the deploy branch deploys. Tell the user that before they push.

## Example (dry run of a store)

```
type    slug                     version   source         active  status   …
core    wordpress                7.1.4     wordpress.org  yes     ok
plugin  woocommerce              11.2.2    wordpress.org  yes     ok
plugin  host-cache-helper        1.4.10    external       yes     ok
theme   twentytwentythree        1.6       wordpress.org  no      ok
plugin host-cache-helper 1.4.10: not in the wordpress.org directory
theme twentytwentythree 1.6: files: 20 match the directory zip, 2 match WordPress 7.0.8's bundled copy (…)
Warning: plugin all-in-one-wp-migration 7.113: added storage/.htaccess (kept)

Not ejected
path                                   bundle  reason
vendor/plugins/host-cache-helper-1.4.10.zip  yes  external package host-cache-helper 1.4.10 (…)
wp-content/plugins/all-in-one-wp-migration (4 added file(s))  yes  not shipped by wordpress.org: kept, review them
wp-config.php                          no      site configuration
wp-content/db.php                      no      Studio SQLite drop-in: required for the local site to boot
wp-content/uploads                     no      media: content, out of scope for the code blueprint

Success: Bundle written to site-bundle.zip (52 files). The site was not changed. 31 from wordpress.org, 3 external, 0 custom; 1 warning(s).
```

Here the next step is to ask the user about the 3 external plugins: are they
theirs or third-party? Then run it again with `--keep-plugins` for any that are
theirs.
