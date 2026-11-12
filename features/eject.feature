Feature: Eject a WordPress site into a blueprint

  Scenario: A dry run verifies everything, writes the blueprint and deletes nothing
    Given an empty directory
    And WP files

    When I try `wp eject --no-bundle --no-ci`
    And the return code should be 0
    Then STDOUT should contain:
      """
      Blueprint written to blueprint.json.
      """
    And STDOUT should contain:
      """
      Dry run, nothing deleted.
      """
    And STDOUT should contain:
      """
      Without --db: only files are read
      """
    And the blueprint.json file should contain:
      """
      "wp": "https://wordpress.org/wordpress-
      """
    And the blueprint.json file should contain:
      """
      https://downloads.wordpress.org/plugin/akismet.
      """
    And the wp-includes/version.php file should exist
    And the wp-content/plugins/akismet/akismet.php file should exist

    When I run `php -r 'echo implode(",", array_keys(json_decode(file_get_contents("blueprint.json"), true)));'`
    Then STDOUT should be:
      """
      $schema,preferredVersions,steps
      """

  Scenario: The pipeline needs a git provider
    Given an empty directory
    And WP files

    When I try `wp eject`
    Then STDERR should contain:
      """
      Error: --git-provider is required
      """
    And the return code should be 1
    And the blueprint.json file should not exist

  Scenario: A modified file is an error and nothing is written
    Given an empty directory
    And WP files
    And "<?php" replaced with "<?php // changed" in the wp-load.php file

    When I try `wp eject --no-bundle --no-ci --apply --yes`
    Then STDOUT should contain:
      """
      core wordpress
      """
    And STDOUT should contain:
      """
      modified wp-load.php
      """
    And STDERR should contain:
      """
      package(s) failed verification
      """
    And the return code should be 1
    And the blueprint.json file should not exist
    And the wp-includes/version.php file should exist

  Scenario: Added files are warnings and are kept by --apply
    Given an empty directory
    And WP files
    And a wp-content/plugins/akismet/notes.txt file:
      """
      Not shipped by wordpress.org.
      """

    When I try `wp eject --no-bundle --no-ci --apply --yes`
    Then STDERR should contain:
      """
      Warning: plugin akismet
      """
    And STDERR should contain:
      """
      added notes.txt (kept)
      """
    And the return code should be 0
    And STDOUT should contain:
      """
      Ejected.
      """
    And the wp-content/plugins/akismet/notes.txt file should exist
    And the wp-content/plugins/akismet/akismet.php file should not exist
    And the wp-includes/version.php file should not exist
    And the wp-content/plugins/hello.php file should not exist

  Scenario: A plugin that is not in the directory is packed as external
    Given an empty directory
    And WP files
    And a wp-content/plugins/wp-eject-behat-external/wp-eject-behat-external.php file:
      """
      <?php
      /**
       * Plugin Name: wp eject Behat external plugin
       * Version: 1.2.3
       */
      """

    When I try `wp eject --no-bundle --no-ci --apply --yes --no-git`
    And the return code should be 0
    Then STDOUT should contain:
      """
      not in the wordpress.org directory
      """
    And STDOUT should contain:
      """
      Packed plugin wp-eject-behat-external 1.2.3 into wp-content/plugins/wp-eject-behat-external-1.2.3.zip.
      """
    And the wp-content/plugins/wp-eject-behat-external-1.2.3.zip file should exist
    And the wp-content/plugins/wp-eject-behat-external directory should not exist
    And the wp-content/EJECT.md file should contain:
      """
      - `wp-content/plugins/wp-eject-behat-external-1.2.3.zip`
      """
    And the wp-content/plugins/EJECT.md file should not exist
    And the blueprint.json file should contain:
      """
      "path": "/vendor/plugins/wp-eject-behat-external-1.2.3.zip"
      """

  Scenario: --keep-plugins keeps a plugin in the repository as custom
    Given an empty directory
    And WP files
    And a wp-content/plugins/wp-eject-behat-custom/wp-eject-behat-custom.php file:
      """
      <?php
      /**
       * Plugin Name: wp eject Behat custom plugin
       * Version: 1.0.0
       */
      """

    When I try `wp eject --no-bundle --no-ci --apply --yes --keep-plugins=wp-eject-behat-custom`
    And the return code should be 0
    Then STDOUT should contain:
      """
      custom (--keep-plugins)
      """
    And the wp-content/plugins/wp-eject-behat-custom/wp-eject-behat-custom.php file should exist
    And the wp-content/plugins/wp-eject-behat-custom-1.0.0.zip file should not exist

  Scenario: An unknown slug in --keep-plugins is a warning
    Given an empty directory
    And WP files

    When I try `wp eject --no-bundle --no-ci --keep-plugins=does-not-exist`
    Then STDERR should contain:
      """
      Warning: --keep-plugins=does-not-exist: no such plugin.
      """
    And the return code should be 0

  Scenario: Without --db nothing is activated; with --db the active state is kept
    Given a WP install
    And I run `wp plugin activate akismet`

    When I try `wp eject --no-bundle --no-ci`
    And the return code should be 0
    Then the blueprint.json file should not contain:
      """
      setSiteOptions
      """
    And the blueprint.json file should not contain:
      """
      "activate": true
      """

    When I try `wp eject --no-bundle --no-ci --db`
    And the return code should be 0
    Then the blueprint.json file should contain:
      """
      "active_plugins": [
      """
    And the blueprint.json file should contain:
      """
      "akismet/akismet.php"
      """
    And the blueprint.json file should contain:
      """
      "activate": true
      """

  Scenario: The report can be JSON
    Given an empty directory
    And WP files

    When I try `wp eject --no-ci --format=json`
    And the return code should be 0
    Then STDOUT should be JSON containing:
      """
      {"summary":{"errors":0,"applied":false,"database":false}}
      """

  Scenario: Studio files are never ejected and the report says why
    Given an empty directory
    And WP files
    And a wp-content/mu-plugins/99-studio-loader.php file:
      """
      <?php
      """
    And a STUDIO.md file:
      """
      Studio instructions.
      """

    When I try `wp eject --no-bundle --git-provider=github --no-git --apply --yes`
    And the return code should be 0
    Then STDOUT should contain:
      """
      Not ejected
      """
    And STDOUT should contain:
      """
      Studio mu-plugin loader
      """
    And the wp-content/mu-plugins/99-studio-loader.php file should exist
    And the STUDIO.md file should exist
    And the deploy/deployignore file should not contain:
      """
      Studio
      """

  Scenario Outline: The pipeline is written for each git provider
    Given an empty directory
    And WP files

    When I try `wp eject --no-bundle --git-provider=<provider> --deploy-delete=packages --deploy-branch=production`
    And the return code should be 0
    Then STDOUT should contain:
      """
      Pipeline (<provider>) written
      """
    And the <file> file should not contain:
      """
      Generated file: may be rewritten
      """
    And the <file> file should contain:
      """
      production
      """
    And the deploy/deploy.php file should contain:
      """
      build-snapshot
      """
    And the deploy/config.yml file should contain:
      """
        delete: packages
      """
    And the deploy/README.md file should contain:
      """
      <name>
      """
    And the deploy/deployignore file should contain:
      """
      /wp-config.php
      """

    Examples:
      | provider  | file                         | name                |
      | github    | .github/workflows/deploy.yml | GitHub Actions      |
      | gitlab    | .gitlab-ci.yml               | GitLab CI/CD        |
      | bitbucket | bitbucket-pipelines.yml      | Bitbucket Pipelines |

  Scenario: An existing CI file that was not generated is left alone
    Given an empty directory
    And WP files
    And a bitbucket-pipelines.yml file:
      """
      image: custom
      """

    When I try `wp eject --no-bundle --git-provider=bitbucket`
    Then STDERR should contain:
      """
      bitbucket-pipelines.yml exists and was not generated by wp eject
      """
    And the return code should be 0
    And the bitbucket-pipelines.yml file should contain:
      """
      image: custom
      """

    When I try `wp eject --no-bundle --git-provider=bitbucket --overwrite-ci`
    And the return code should be 0
    Then the bitbucket-pipelines.yml file should not contain:
      """
      Generated file: may be rewritten
      """

  Scenario: --no-ci writes no CI/CD
    Given an empty directory
    And WP files

    When I try `wp eject --no-bundle --no-ci`
    And the return code should be 0
    Then the deploy directory should not exist

  @require-bin-unzip
  Scenario: By default a self-contained bundle is written and the site is not changed
    Given an empty directory
    And WP files
    And a wp-content/plugins/wp-eject-behat-external/wp-eject-behat-external.php file:
      """
      <?php
      /**
       * Plugin Name: wp eject Behat external plugin
       * Version: 1.2.3
       */
      """
    And a wp-content/plugins/wp-eject-behat-custom/wp-eject-behat-custom.php file:
      """
      <?php
      /**
       * Plugin Name: wp eject Behat custom plugin
       * Version: 1.0.0
       */
      """
    And a wp-content/mu-plugins/site-tweaks.php file:
      """
      <?php
      """
    And a wp-config.php file:
      """
      <?php // secrets
      """

    When I try `wp eject --no-ci --keep-plugins=wp-eject-behat-custom`
    And the return code should be 0
    Then STDOUT should contain:
      """
      -bundle.zip (
      """
    And STDOUT should contain:
      """
      The site was not changed.
      """
    And the wp-includes/version.php file should exist
    And the wp-content/plugins/akismet/akismet.php file should exist
    And the wp-content/plugins/wp-eject-behat-external/wp-eject-behat-external.php file should exist
    And the blueprint.json file should not exist

    When I try `wp eject --no-ci --keep-plugins=wp-eject-behat-custom --bundle=site.zip`
    And the return code should be 0
    And I run `unzip -Z1 site.zip`
    Then STDOUT should contain:
      """
      blueprint.json
      """
    And STDOUT should contain:
      """
      vendor/plugins/wp-eject-behat-external-1.2.3.zip
      """
    And STDOUT should contain:
      """
      wp-content/plugins/wp-eject-behat-custom/wp-eject-behat-custom.php
      """
    And STDOUT should contain:
      """
      wp-content/mu-plugins/site-tweaks.php
      """
    And STDOUT should contain:
      """
      README.md
      """
    And STDOUT should not contain:
      """
      wp-config.php
      """
    And STDOUT should not contain:
      """
      wp-includes/
      """

  Scenario: The bundle can be a directory
    Given an empty directory
    And WP files

    When I try `wp eject --git-provider=gitlab --bundle=out`
    And the return code should be 0
    Then the out/blueprint.json file should not contain:
      """
      "meta"
      """
    And the out/.gitlab-ci.yml file should not contain:
      """
      Generated file: may be rewritten
      """
    And the out/.gitignore file should contain:
      """
      !/blueprint.json
      """
    And the .gitlab-ci.yml file should not exist

  Scenario: --apply only works with --no-bundle
    Given an empty directory
    And WP files

    When I try `wp eject --no-ci --apply`
    Then STDERR should contain:
      """
      --apply deletes files in the site; it only works with --no-bundle
      """
    And the return code should be 1
    And the wp-includes/version.php file should exist

  @require-bin-rsync
  Scenario Outline: deploy copies the build and deletes only what the mode allows
    Given an empty directory
    And WP files
    And I try `wp eject --no-bundle --git-provider=github --deploy-delete=none`
    And the return code should be 0
    And a build/index.php file:
      """
      <?php
      """
    And a build/wp-content/plugins/shop/shop.php file:
      """
      <?php // new
      """
    And a server/wp-config.php file:
      """
      <?php // secrets
      """
    And a server/wp-content/uploads/photo.jpg file:
      """
      jpg
      """
    And a server/wp-content/plugins/shop/old.php file:
      """
      <?php // removed in the new version
      """
    And a server/wp-content/plugins/server-only/server-only.php file:
      """
      <?php
      """

    When I run `wp --require=deploy/deploy.php deploy --build-dir=build --to=server --delete=<mode>`
    Then STDOUT should contain:
      """
      Deployed to production.
      """
    And the server/index.php file should exist
    And the server/wp-content/plugins/shop/shop.php file should exist
    And the server/wp-config.php file should exist
    And the server/wp-content/uploads/photo.jpg file should exist
    And the server/wp-content/plugins/shop/old.php file should <old>
    And the server/wp-content/plugins/server-only/server-only.php file should <server_only>

    Examples:
      | mode     | old       | server_only |
      | mirror   | not exist | not exist   |
      | packages | not exist | exist       |
      | none     | exist     | exist       |

  @require-bin-rsync
  Scenario: deploy --dry-run lists the changes and changes nothing
    Given an empty directory
    And WP files
    And I try `wp eject --no-bundle --git-provider=gitlab`
    And the return code should be 0
    And a build/index.php file:
      """
      <?php
      """
    And a server/old.php file:
      """
      <?php
      """

    When I run `wp --require=deploy/deploy.php deploy --build-dir=build --to=server --dry-run`
    Then STDOUT should match /\*deleting\s+old\.php/
    And STDOUT should contain:
      """
      Dry run: nothing was changed.
      """
    And the server/old.php file should exist
    And the server/index.php file should not exist

  Scenario: deploy needs a target, a valid mode and a build
    Given an empty directory
    And WP files
    And I try `wp eject --no-bundle --git-provider=bitbucket`
    And the return code should be 0

    When I try `wp --require=deploy/deploy.php deploy --build-dir=build --to=server`
    Then STDERR should contain:
      """
      no build directory
      """
    And the return code should be 1

    When I try `wp --require=deploy/deploy.php deploy --build-dir=build`
    Then STDERR should contain:
      """
      DEPLOY_PATH (or --to
      """
    And the return code should be 1

    When I try `wp --require=deploy/deploy.php deploy --build-dir=build --to=server --delete=all`
    Then STDERR should contain:
      """
      must be mirror, packages or none
      """
    And the return code should be 1

  Scenario: maintenance on and off on the target
    Given an empty directory
    And WP files
    And I try `wp eject --no-bundle --git-provider=github`
    And the return code should be 0

    When I run `wp --require=deploy/deploy.php maintenance on --to=server`
    Then the server/.maintenance file should exist

    When I run `wp --require=deploy/deploy.php maintenance off --to=server`
    Then the server/.maintenance file should not exist

  @require-bin-rsync
  Scenario: deploy turns maintenance mode off even when a step fails
    Given an empty directory
    And WP files
    And a deploy/config.yml file:
      """
      deploy:
        delete: none
        after:
          - 'false'
      """
    And I try `wp eject --no-bundle --git-provider=github`
    And the return code should be 0
    And a build/index.php file:
      """
      <?php
      """

    When I try `wp --require=deploy/deploy.php deploy --build-dir=build --to=server`
    Then STDOUT should contain:
      """
      Maintenance mode on
      """
    And STDERR should contain:
      """
      The deploy failed: turning maintenance mode off.
      """
    And the return code should be 1
    And the server/.maintenance file should not exist
    And the server/index.php file should exist

  @require-bin-rsync
  Scenario: deploy runs deploy.after, keeps deploy.backup.keep backups and honors deploy.paths
    Given an empty directory
    And WP files
    And a deploy/config.yml file:
      """
      deploy:
        delete: none
        paths:
          - wp-content/themes
        backup:
          enabled: true
          keep: 1
          path: backups
        after:
          - touch after-ran
      """
    And I try `wp eject --no-bundle --git-provider=gitlab`
    And the return code should be 0
    And a build/index.php file:
      """
      <?php
      """
    And a build/wp-content/themes/acme/style.css file:
      """
      /* Theme Name: Acme */
      """
    And a server/wp-content/plugins/old/old.php file:
      """
      <?php
      """

    When I run `wp --require=deploy/deploy.php deploy --build-dir=build --to=server`
    And I run `wp --require=deploy/deploy.php deploy --build-dir=build --to=server`
    Then the server/after-ran file should exist
    And the server/wp-content/themes/acme/style.css file should exist
    And the server/index.php file should not exist

    When I run `ls server/backups | wc -l | tr -d ' '`
    Then STDOUT should be:
      """
      1
      """

  @require-bin-tar
  Scenario: the tarball strategy replaces the packages it ships and cannot mirror
    Given an empty directory
    And WP files
    And I try `wp eject --no-bundle --git-provider=bitbucket`
    And the return code should be 0
    And a build/wp-content/plugins/shop/shop.php file:
      """
      <?php // new
      """
    And a server/wp-content/plugins/shop/old.php file:
      """
      <?php // removed in the new version
      """
    And a server/wp-content/plugins/other/other.php file:
      """
      <?php
      """

    When I run `wp --require=deploy/deploy.php deploy --build-dir=build --to=server --strategy=tarball --delete=packages`
    Then STDOUT should contain:
      """
      Extracted.
      """
    And the server/wp-content/plugins/shop/shop.php file should exist
    And the server/wp-content/plugins/shop/old.php file should not exist
    And the server/wp-content/plugins/other/other.php file should exist

    When I try `wp --require=deploy/deploy.php deploy --build-dir=build --to=server --strategy=tarball --delete=mirror`
    Then STDERR should contain:
      """
      the tarball strategy cannot mirror
      """
    And the return code should be 1

  Scenario: every problem is listed at once
    Given an empty directory
    And WP files
    And I try `wp eject --no-bundle --git-provider=github`
    And the return code should be 0

    When I try `wp --require=deploy/deploy.php deploy --build-dir=build --strategy=ftp --delete=all`
    Then STDERR should contain:
      """
      Cannot deploy:
      """
    And STDERR should contain:
      """
      DEPLOY_PATH (or --to
      """
    And STDERR should contain:
      """
      must be rsync or tarball
      """
    And STDERR should contain:
      """
      must be mirror, packages or none
      """
    And STDERR should contain:
      """
      no build directory
      """
    And the return code should be 1

  @require-bin-rsync
  Scenario: settings come from the option, then the variable, then deploy/config.yml
    Given an empty directory
    And WP files
    And a deploy/config.yml file:
      """
      deploy:
        delete: none
        maintenance: false
      """
    And I try `wp eject --no-bundle --git-provider=github`
    And the return code should be 0
    And a build/index.php file:
      """
      <?php
      """

    When I run `wp --require=deploy/deploy.php deploy --build-dir=build --to=server`
    Then STDOUT should contain:
      """
      delete: none
      """
    And STDOUT should not contain:
      """
      Maintenance mode on
      """

    When I run `DEPLOY_DELETE=packages wp --require=deploy/deploy.php deploy --build-dir=build --to=server`
    Then STDOUT should contain:
      """
      delete: packages
      """

    When I run `DEPLOY_DELETE=packages wp --require=deploy/deploy.php deploy --build-dir=build --to=server --delete=mirror`
    Then STDOUT should contain:
      """
      delete: mirror
      """

    When I run `STAGING_DEPLOY_PATH=staging-site DEPLOY_PATH=prod-site wp --require=deploy/deploy.php deploy --build-dir=build --env=staging`
    Then STDOUT should contain:
      """
      Deployed to staging.
      """
    And the staging-site/index.php file should exist
    And the prod-site directory should not exist

  Scenario: an existing deploy/config.yml is never overwritten
    Given an empty directory
    And WP files
    And a deploy/config.yml file:
      """
      deploy:
        delete: none
      """

    When I try `wp eject --no-bundle --git-provider=github --overwrite-ci`
    And the return code should be 0
    Then the deploy/config.yml file should strictly be:
      """
      deploy:
        delete: none
      """
    And STDOUT should contain:
      """
      kept (yours)
      """

  Scenario: --save-known writes rules with hashes and --known accepts exactly those contents
    Given an empty directory
    And WP files
    And "<?php" replaced with "<?php // local change" in the wp-content/plugins/akismet/akismet.php file

    When I try `wp eject --no-ci --save-known=known.yml`
    Then the return code should be 1
    And STDERR should contain:
      """
      failed verification (plugin akismet
      """
    And the known.yml file should contain:
      """
      - plugin: 'akismet'
      """
    And the known.yml file should contain:
      """
          - file: 'akismet.php'
            sha256: [ '
      """

    When I try `wp eject --no-ci --known=known.yml --bundle=out.zip`
    And the return code should be 0
    Then STDOUT should contain:
      """
      known: 1 modified file(s) count as verified
      """
    And STDOUT should contain:
      """
      The site was not changed.
      """

    Given "local change" replaced with "another change" in the wp-content/plugins/akismet/akismet.php file
    When I try `wp eject --no-ci --known=known.yml --bundle=out.zip`
    Then the return code should be 1
    And STDOUT should contain:
      """
      modified akismet.php
      """

  Scenario: known added files are ignored, left out of the bundle and protected on deploy
    Given an empty directory
    And WP files
    And a wp-content/plugins/akismet/cache/state.json file:
      """
      {}
      """
    And a known.yml file:
      """
      - plugin: akismet
        version: '>=5.0 <99'
        added:
          - 'cache/*'
        reason: runtime cache of the test
      """

    When I try `wp eject --git-provider=github --known=known.yml --bundle=out`
    And the return code should be 0
    Then STDOUT should contain:
      """
      known: 1 added file(s) ignored (runtime cache of the test)
      """
    And STDERR should not contain:
      """
      added cache/state.json
      """
    And the out/wp-content/plugins/akismet/cache/state.json file should not exist
    And the out/deploy/deployignore file should contain:
      """
      /wp-content/plugins/akismet/cache/*
      """

  Scenario: a known rule outside its version range does not apply
    Given an empty directory
    And WP files
    And a wp-content/plugins/akismet/cache/state.json file:
      """
      {}
      """
    And a known.yml file:
      """
      - plugin: akismet
        version: '<1.0 || ^99'
        added:
          - 'cache/*'
        reason: old versions only
      """

    When I try `wp eject --no-ci --known=known.yml --bundle=out.zip`
    Then STDERR should contain:
      """
      Warning: plugin akismet
      """
    And STDERR should contain:
      """
      added cache/state.json (kept)
      """
    And the return code should be 0

  Scenario: --no-report prints only the verification progress, errors, warnings and the summary
    Given an empty directory
    And WP files
    And I run `echo notes > wp-content/plugins/akismet/notes.txt`

    When I try `wp eject --no-ci --no-report --bundle=out.zip`
    And the return code should be 0
    Then STDERR should contain:
      """
      added notes.txt (kept)
      """
    And STDOUT should contain:
      """
      Verifying WordPress
      """
    And STDOUT should contain:
      """
      Verifying plugin akismet
      """
    And STDOUT should not contain:
      """
      Not ejected
      """
    And STDOUT should not contain:
      """
      verified	modified
      """
    And STDOUT should contain:
      """
      Bundle written to out.zip
      """

  @require-bin-git
  Scenario: the repository gets a whitelist .gitignore and an .editorconfig
    Given an empty directory
    And WP files
    And a wp-content/plugins/wp-eject-behat-custom/wp-eject-behat-custom.php file:
      """
      <?php
      /**
       * Plugin Name: wp eject Behat custom plugin
       * Version: 1.0.0
       */
      """
    And a wp-content/mu-plugins/site-tweaks.php file:
      """
      <?php
      """

    When I try `wp eject --git-provider=bitbucket --keep-plugins=wp-eject-behat-custom --bundle=repo`
    And the return code should be 0
    Then the repo/.editorconfig file should contain:
      """
      indent_style = tab
      """
    And the repo/.gitignore file should contain:
      """
      !/bitbucket-pipelines.yml
      """

    When I run `git init -q repo && git -C repo add -A && git -C repo ls-files`
    Then STDOUT should contain:
      """
      .editorconfig
      .gitignore
      README.md
      bitbucket-pipelines.yml
      blueprint.json
      deploy/README.md
      deploy/config.yml
      deploy/deploy.php
      deploy/deployignore
      """
    And STDOUT should contain:
      """
      wp-content/mu-plugins/site-tweaks.php
      """
    And STDOUT should contain:
      """
      wp-content/plugins/wp-eject-behat-custom/wp-eject-behat-custom.php
      """
    And STDOUT should not contain:
      """
      wp-content/plugins/akismet
      """
    And STDOUT should not contain:
      """
      EJECT.md
      """

  Scenario: an existing .gitignore is kept unless --overwrite-ci
    Given an empty directory
    And WP files
    And a .gitignore file:
      """
      my rules
      """

    When I try `wp eject --no-bundle --git-provider=github`
    Then STDERR should contain:
      """
      .gitignore exists and was not generated by wp eject
      """
    And the return code should be 0
    And the .gitignore file should strictly be:
      """
      my rules
      """
    And the .editorconfig file should exist

    When I try `wp eject --no-bundle --git-provider=github --overwrite-ci`
    And the return code should be 0
    Then the .gitignore file should contain:
      """
      !/blueprint.json
      """
    And the .gitignore file should not contain:
      """
      Generated file
      """

  @require-bin-git
  Scenario: vendor publish keeps each external package on its own branch, one tag per version
    Given an empty directory
    And WP files
    And a wp-content/plugins/wp-eject-behat-external/wp-eject-behat-external.php file:
      """
      <?php
      /**
       * Plugin Name: wp eject Behat external plugin
       * Version: 1.2.3
       */
      """
    And I try `wp eject --no-bundle --git-provider=github --apply --yes --no-git`
    And the return code should be 0
    And I run `git init -q -b main && git add -A && git -c user.name=t -c user.email=t@t commit -qm site`

    When I run `wp --require=deploy/deploy.php vendor publish --no-push`
    Then STDOUT should contain:
      """
      Branch vendor/plugin/wp-eject-behat-external, tag vendor/plugin/wp-eject-behat-external/1.2.3.
      """

    When I run `git ls-tree -r --name-only vendor/plugin/wp-eject-behat-external/1.2.3`
    Then STDOUT should be:
      """
      wp-eject-behat-external.php
      """

    When I run `git status --porcelain`
    Then STDOUT should be empty

    When I run `wp --require=deploy/deploy.php vendor publish --no-push`
    Then STDOUT should contain:
      """
      Already published as vendor/plugin/wp-eject-behat-external/1.2.3.
      """

    When I run `rm wp-content/plugins/wp-eject-behat-external-1.2.3.zip && wp --require=deploy/deploy.php vendor fetch`
    Then STDOUT should contain:
      """
      External wp-eject-behat-external 1.2.3: tag vendor/plugin/wp-eject-behat-external/1.2.3
      """
    And the vendor/plugins/wp-eject-behat-external-1.2.3.zip file should exist

  @require-bin-git @require-bin-unzip @require-bin-zip
  Scenario: a published version must not change
    Given an empty directory
    And WP files
    And a wp-content/plugins/wp-eject-behat-external/wp-eject-behat-external.php file:
      """
      <?php
      /**
       * Plugin Name: wp eject Behat external plugin
       * Version: 1.2.3
       */
      """
    And I try `wp eject --no-bundle --git-provider=github --apply --yes --no-git`
    And the return code should be 0
    And I run `git init -q -b main && wp --require=deploy/deploy.php vendor publish --no-push`
    And I run `mkdir -p tmp && cd tmp && unzip -q ../wp-content/plugins/wp-eject-behat-external-1.2.3.zip && echo '// changed' >> wp-eject-behat-external/wp-eject-behat-external.php && rm ../wp-content/plugins/wp-eject-behat-external-1.2.3.zip && zip -qr ../wp-content/plugins/wp-eject-behat-external-1.2.3.zip wp-eject-behat-external`

    When I try `wp --require=deploy/deploy.php vendor publish --no-push`
    Then STDERR should contain:
      """
      already exists with different files
      """
    And the return code should be 1

  @require-bin-rsync
  Scenario: restore puts the site's code back without touching wp-config.php
    Given an empty directory
    And WP files
    And a wp-config.php file:
      """
      <?php // local
      """
    And I try `wp eject --no-bundle --git-provider=gitlab --apply --yes`
    And the return code should be 0
    And a build/index.php file:
      """
      <?php // built
      """
    And a build/wp-config.php file:
      """
      <?php // from the build: must not win
      """
    And a build/wp-content/plugins/shop/shop.php file:
      """
      <?php
      """

    When I run `wp --require=deploy/deploy.php restore --build-dir=build --no-build`
    Then STDOUT should contain:
      """
      back in
      """
    And the index.php file should contain:
      """
      built
      """
    And the wp-content/plugins/shop/shop.php file should exist
    And the wp-config.php file should contain:
      """
      local
      """
    And the build directory should not exist

  Scenario: --apply also removes core's index.php files, regenerable server data and empty folders
    Given an empty directory
    And WP files
    And a wp-content/wflogs/rules.php file:
      """
      <?php
      """
    And a wp-content/upgrade/index.php file:
      """
      <?php
      // Silence is golden.
      """
    And an empty wp-content/upgrade-temp-backup/plugins directory

    When I try `wp eject --no-bundle --no-ci --apply --yes`
    And the return code should be 0
    Then the wp-content/index.php file should not exist
    And the wp-content/plugins/index.php file should not exist
    And the wp-content/themes/index.php file should not exist
    And the wp-content/wflogs directory should not exist
    And the wp-content/upgrade directory should not exist
    And the wp-content/upgrade-temp-backup directory should not exist
    And STDOUT should contain:
      """
      regenerable server data: deleted
      """

  @require-bin-git
  Scenario: by default a directory bundle is a repository ready to push
    Given an empty directory
    And WP files
    And a wp-content/plugins/wp-eject-behat-external/wp-eject-behat-external.php file:
      """
      <?php
      /**
       * Plugin Name: wp eject Behat external plugin
       * Version: 1.2.3
       */
      """

    When I try `wp eject --git-provider=github --bundle=repo`
    And the return code should be 0
    Then STDOUT should contain:
      """
      Committed the site code on main
      """
    And STDOUT should contain:
      """
      This repository has no remote yet.
      """
    And STDOUT should contain:
      """
      git remote add origin <repository-url>
      """
    And STDOUT should contain:
      """
      git push -u origin main 'refs/heads/vendor/*' 'refs/tags/vendor/*'
      """

    When I run `git -C repo log --format=%s --all`
    Then STDOUT should contain:
      """
      Initial project files
      """
    And STDOUT should contain:
      """
      wp-eject-behat-external 1.2.3
      """

    When I run `git -C repo tag --list`
    Then STDOUT should contain:
      """
      vendor/plugin/wp-eject-behat-external/1.2.3
      """

    When I run `git -C repo status --porcelain`
    Then STDOUT should be empty

  Scenario: --no-git leaves git alone, and a zip bundle skips it
    Given an empty directory
    And WP files

    When I try `wp eject --no-ci --no-git --bundle=repo`
    And the return code should be 0
    Then the repo/.git directory should not exist

    When I try `wp eject --no-ci --bundle=site.zip`
    And the return code should be 0
    Then STDOUT should contain:
      """
      Git: skipped for a zip bundle
      """

  @require-bin-git
  Scenario: an existing repository gets the branches but no commit, and a tracked wp-config.php is reported
    Given an empty directory
    And WP files
    And a wp-config.php file:
      """
      <?php // secrets
      """
    And I run `git init -q -b main && git add -A && git -c user.name=t -c user.email=t@t commit -qm "From production"`

    When I try `wp eject --no-bundle --no-ci`
    Then STDOUT should contain:
      """
      already has history: nothing was committed
      """
    And STDERR should contain:
      """
      wp-config.php is tracked by this repository
      """
    And the return code should be 0

  @require-bin-rsync
  Scenario: build runs the Playground from a folder with only the blueprint and the files it references
    Given an empty directory
    And WP files
    And a wp-content/plugins/wp-eject-behat-external/wp-eject-behat-external.php file:
      """
      <?php
      /**
       * Plugin Name: wp eject Behat external plugin
       * Version: 1.2.3
       */
      """
    And I try `wp eject --no-bundle --git-provider=github --apply --yes --no-git`
    And the return code should be 0
    And a junk/big.bin file:
      """
      not for Playground
      """
    And a bin/npx file:
      """
      #!/bin/sh
      # Stands in for npx: records what the Playground would see and writes an empty snapshot.
      echo "npm warn EBADENGINE noise"
      echo "npm warn EBADENGINE noise" >&2
      find . -type f | sort > "$SAW"
      for arg in "$@"; do
        case "$arg" in --outfile=*) out="${arg#--outfile=}" ;; esac
      done
      mkdir -p snapshot/wordpress && echo '<?php' > snapshot/wordpress/index.php
      (cd snapshot && zip -qr "$out" wordpress)
      """
    And I run `chmod +x bin/npx`

    When I run `SAW=$PWD/saw.txt PATH=$PWD/bin:$PATH wp --require=deploy/deploy.php build --build-dir=build`
    Then the saw.txt file should contain:
      """
      ./blueprint.json
      """
    And the saw.txt file should contain:
      """
      ./vendor/plugins/wp-eject-behat-external-1.2.3.zip
      """
    And the saw.txt file should not contain:
      """
      junk
      """
    And the saw.txt file should not contain:
      """
      .git
      """
    And STDOUT should not contain:
      """
      EBADENGINE
      """
    And the build/index.php file should exist

  @require-bin-tar
  @require-bin-git
  Scenario: auto uses one tarball for an empty target and rsync once it has files, and .git-ftp.log records the commit
    Given an empty directory
    And WP files
    And I try `wp eject --no-bundle --git-provider=github --no-git`
    And the return code should be 0
    And a build/index.php file:
      """
      <?php
      """
    And I run `git init -q -b main && git add deploy blueprint.json && git -c user.name=t -c user.email=t@t commit -qm first`
    And I run `git rev-parse HEAD`
    And save STDOUT as {SHA}

    When I run `wp --require=deploy/deploy.php deploy --build-dir=build --to=server --delete=mirror`
    Then STDOUT should contain:
      """
      Strategy auto: the target is empty, so one tarball.
      """
    And STDOUT should contain:
      """
      Extracted.
      """
    And the server/index.php file should exist
    And the server/.git-ftp.log file should contain:
      """
      {SHA}
      """

    When I run `wp --require=deploy/deploy.php deploy status --to=server`
    Then STDOUT should contain:
      """
      Deployed to production: {SHA}
      """
    And STDOUT should contain:
      """
      HEAD is deployed.
      """

    When I run `git -c user.name=t -c user.email=t@t commit -q --allow-empty -m second`
    And I run `wp --require=deploy/deploy.php deploy status --to=server`
    Then STDOUT should contain:
      """
      1 commit(s) behind HEAD.
      """

  @require-bin-rsync
  @require-bin-git
  Scenario: auto switches to incremental rsync when the target already has files
    Given an empty directory
    And WP files
    And I try `wp eject --no-bundle --git-provider=github --no-git`
    And the return code should be 0
    And a build/index.php file:
      """
      <?php // new
      """
    And a server/old.php file:
      """
      <?php
      """
    And I run `git init -q -b main && git add deploy blueprint.json && git -c user.name=t -c user.email=t@t commit -qm first`

    When I run `wp --require=deploy/deploy.php deploy --build-dir=build --to=server --delete=mirror`
    Then STDOUT should contain:
      """
      Strategy auto: the target has files, so incremental rsync.
      """
    And the server/index.php file should exist
    And the server/old.php file should not exist
    And the server/.git-ftp.log file should exist

  Scenario: the commit recorded comes from the CI variable, and deploy status without a log is a warning
    Given an empty directory
    And WP files
    And I try `wp eject --no-bundle --git-provider=github --no-git`
    And the return code should be 0
    And a build/index.php file:
      """
      <?php
      """

    When I run `CI_COMMIT_SHA=abc1234def wp --require=deploy/deploy.php deploy --build-dir=build --to=server`
    Then the server/.git-ftp.log file should contain:
      """
      abc1234def
      """

    When I try `wp --require=deploy/deploy.php deploy status --to=nowhere`
    Then STDERR should contain:
      """
      No .git-ftp.log on the production target
      """
    And the return code should be 0

    When I try `wp --require=deploy/deploy.php deploy nonsense`
    Then STDERR should contain:
      """
      Unknown action: nonsense
      """
    And the return code should be 1

  Scenario: the generated files have no visible credit and no generated text, and are still regenerated by a new run
    Given an empty directory
    And WP files

    When I try `wp eject --no-bundle --git-provider=github --no-git`
    And the return code should be 0
    Then the deploy/README.md file should not contain:
      """
      <!-- generated
      """
    And the deploy/README.md file should not contain:
      """
      wp eject
      """
    And the deploy/deployignore file should contain:
      """
      /.git-ftp.log
      """

    When I try `wp eject --no-bundle --git-provider=github --no-git`
    And the return code should be 0

    And I try `wp eject --no-ci --bundle=repo --no-git`
    And the return code should be 0
    Then the repo/README.md file should not contain:
      """
      wp eject
      """
    And the repo/EJECT.md file should not exist

  @require-bin-rsync
  Scenario: build is made outside the repository by default, and --build-dir=. merges it into the root
    Given an empty directory
    And WP files
    And a wp-config.php file:
      """
      <?php // local
      """
    And I try `wp eject --no-bundle --git-provider=github --apply --yes --no-git`
    And the return code should be 0
    And a bin/npx file:
      """
      #!/bin/sh
      # Stands in for npx: writes a snapshot with only an index.php.
      for arg in "$@"; do
        case "$arg" in --outfile=*) out="${arg#--outfile=}" ;; esac
      done
      mkdir -p snapshot/wordpress && echo '<?php // restored' > snapshot/wordpress/index.php
      (cd snapshot && zip -qr "$out" wordpress)
      """
    And I run `chmod +x bin/npx`

    When I run `PATH=$PWD/bin:$PATH wp --require=deploy/deploy.php build`
    Then STDOUT should contain:
      """
      -build/
      """
    And the build directory should not exist
    And the index.php file should not exist

    When I run `PATH=$PWD/bin:$PATH wp --require=deploy/deploy.php build --build-dir=.`
    Then STDOUT should contain:
      """
      back in
      """
    And the index.php file should contain:
      """
      restored
      """
    And the wp-config.php file should contain:
      """
      local
      """
    And I run `rm -rf "$PWD-build" "$PWD-build.zip"`

  @require-bin-rsync
  Scenario: build refuses to wipe a folder that is not a previous build, and deploy needs a real build directory
    Given an empty directory
    And WP files
    And I try `wp eject --no-bundle --git-provider=github --no-git`
    And the return code should be 0
    And a junk/precious.txt file:
      """
      keep me
      """
    And a bin/npx file:
      """
      #!/bin/sh
      exit 0
      """
    And I run `chmod +x bin/npx`

    When I try `PATH=$PWD/bin:$PATH wp --require=deploy/deploy.php build --build-dir=junk`
    Then STDERR should contain:
      """
      Refusing to wipe
      """
    And the return code should be 1
    And the junk/precious.txt file should exist

    When I try `wp --require=deploy/deploy.php deploy --build-dir=. --to=server`
    Then STDERR should contain:
      """
      only works with `build` and `restore`
      """
    And the return code should be 1

  @require-bin-git
  Scenario: with a remote the eject ends with the push to it
    Given an empty directory
    And WP files
    And I run `git init -q -b main && git remote add origin https://example.test/site.git`

    When I try `wp eject --no-bundle --no-ci`
    And the return code should be 0
    Then STDOUT should contain:
      """
      to origin (https://example.test/site.git):
      """
    And STDOUT should contain:
      """
      git push -u origin main 'refs/heads/vendor/*' 'refs/tags/vendor/*'
      """
    And STDOUT should not contain:
      """
      git remote add
      """

  @require-bin-git
  Scenario: a committed .gitignore and .editorconfig are replaced when the working tree is clean
    Given an empty directory
    And WP files
    And a .gitignore file:
      """
      my rules
      """
    And an .editorconfig file:
      """
      root = true
      """
    And I run `git init -q -b main && git add .gitignore .editorconfig && git -c user.name=t -c user.email=t@t commit -qm base`

    When I try `wp eject --no-bundle --no-ci`
    And the return code should be 0
    Then the .gitignore file should contain:
      """
      !/blueprint.json
      """
    And the .gitignore file should not contain:
      """
      Generated file
      """
    And the .editorconfig file should contain:
      """
      indent_style = tab
      """
    And STDOUT should contain:
      """
      git has the previous version
      """

    When I run `git diff --stat HEAD`
    Then STDOUT should contain:
      """
      .gitignore
      """

  @require-bin-git
  Scenario: with pending changes in the working tree, a committed .gitignore is kept
    Given an empty directory
    And WP files
    And a .gitignore file:
      """
      my rules
      """
    And a notes.txt file:
      """
      first
      """
    And I run `git init -q -b main && git add .gitignore notes.txt && git -c user.name=t -c user.email=t@t commit -qm base`
    And a notes.txt file:
      """
      changed, not committed
      """

    When I try `wp eject --no-bundle --no-ci`
    Then STDERR should contain:
      """
      .gitignore exists and was not generated by wp eject, and git has uncommitted changes
      """
    And the return code should be 0
    And the .gitignore file should strictly be:
      """
      my rules
      """

  Scenario: missing tools are noted, and build says what to install
    Given an empty directory
    And WP files
    And I try `wp eject --no-bundle --git-provider=github --no-git`
    And the return code should be 0
    And I run `mkdir only && for t in php sh realpath dirname basename uname wp; do ln -s "$(command -v $t)" only/$t; done`

    When I try `PATH=$PWD/only wp eject --no-ci --bundle=out.zip`
    And the return code should be 0
    Then STDOUT should contain:
      """
      Note: git was not found
      """
    And STDOUT should contain:
      """
      Note: npx was not found
      """
    And STDOUT should contain:
      """
      Note: rsync was not found
      """

    When I try `PATH=$PWD/only wp --require=deploy/deploy.php build --build-dir=b`
    Then STDERR should contain:
      """
      npx was not found: the build runs the WordPress Playground CLI
      """
    And the return code should be 1

  Scenario: the repository README says where each part comes from and how to use it
    Given an empty directory
    And WP files
    And a wp-content/plugins/wp-eject-behat-external/wp-eject-behat-external.php file:
      """
      <?php
      /**
       * Plugin Name: wp eject Behat external plugin
       * Version: 1.2.3
       */
      """
    And a wp-content/plugins/wp-eject-behat-custom/wp-eject-behat-custom.php file:
      """
      <?php
      /**
       * Plugin Name: wp eject Behat custom plugin
       * Version: 1.0.0
       */
      """

    When I try `wp eject --git-provider=bitbucket --deploy-branch=trunk --keep-plugins=wp-eject-behat-custom --bundle=repo --no-git`
    And the return code should be 0
    Then the repo/README.md file should not contain:
      """
      <!-- generated
      """
    And the repo/README.md file should not contain:
      """
      wp eject
      """
    And the repo/README.md file should contain:
      """
      | plugin | `wp-eject-behat-external` | 1.2.3 | external: branch `vendor/plugin/wp-eject-behat-external` |
      """
    And the repo/README.md file should contain:
      """
      - `wp-eject-behat-external` 1.2.3: branch `vendor/plugin/wp-eject-behat-external`, tag `vendor/plugin/wp-eject-behat-external/1.2.3`
      """
    And the repo/README.md file should contain:
      """
      - `wp-content/plugins/wp-eject-behat-custom`
      """
    And the repo/README.md file should contain:
      """
      Pushes to `trunk` build the site
      """
    And the repo/README.md file should contain:
      """
      deploy/README.md
      """
    And the repo/.gitignore file should contain:
      """
      !/README.md
      """
    And the repo/EJECT.md file should not exist

  Scenario: a README without external packages or custom code says so
    Given an empty directory
    And WP files
    And I run `rm -rf wp-content/themes/ipsum`

    When I try `wp eject --no-ci --bundle=repo --no-git`
    And the return code should be 0
    Then the repo/README.md file should contain:
      """
      There are none: everything comes from wordpress.org or from this repository.
      """
    And the repo/README.md file should not contain:
      """
      ## Deploy
      """

  @require-bin-git
  Scenario: the bundle goes into an existing repository on a new branch and publishes the external packages
    Given an empty directory
    And WP files
    And a wp-content/plugins/wp-eject-behat-external/wp-eject-behat-external.php file:
      """
      <?php
      /**
       * Plugin Name: wp eject Behat external plugin
       * Version: 1.2.3
       */
      """
    And I run `mkdir repo && cd repo && git init -q -b trunk && echo "# site" > NOTES.md && git add -A && git -c user.name=t -c user.email=t@t commit -qm base`

    When I try `wp eject --git-provider=github --deploy-branch=trunk --bundle=repo --git-branch=chore/eject`
    And the return code should be 0
    Then STDOUT should contain:
      """
      Created the branch chore/eject from trunk
      """
    And STDOUT should contain:
      """
      This repository has no remote yet.
      """
    And STDOUT should contain:
      """
      git push -u origin chore/eject 'refs/heads/vendor/*' 'refs/tags/vendor/*'
      """
    And the repo/vendor directory should not exist

    When I run `git -C repo rev-parse --abbrev-ref HEAD`
    Then STDOUT should be:
      """
      chore/eject
      """

    When I run `git -C repo ls-tree -r --name-only trunk`
    Then STDOUT should be:
      """
      NOTES.md
      """

    When I run `git -C repo ls-tree -r --name-only chore/eject`
    Then STDOUT should contain:
      """
      blueprint.json
      """
    And STDOUT should contain:
      """
      NOTES.md
      """

    When I run `git -C repo tag --list`
    Then STDOUT should contain:
      """
      vendor/plugin/wp-eject-behat-external/1.2.3
      """

  @require-bin-git
  Scenario: an existing repository with pending changes, or a branch that exists, stops the eject
    Given an empty directory
    And WP files
    And I run `mkdir repo && cd repo && git init -q -b trunk && echo "# site" > NOTES.md && git add -A && git -c user.name=t -c user.email=t@t commit -qm base && echo changed >> NOTES.md`

    When I try `wp eject --no-ci --bundle=repo`
    Then STDERR should contain:
      """
      has uncommitted changes
      """
    And the return code should be 1
    And the repo/blueprint.json file should not exist

    When I run `git -C repo checkout -q -- NOTES.md && git -C repo branch chore/eject`
    And I try `wp eject --no-ci --bundle=repo --git-branch=chore/eject`
    Then STDERR should contain:
      """
      The branch chore/eject already exists
      """
    And the return code should be 1

  @require-bin-git
  Scenario: without --git-branch an existing repository is written but not committed
    Given an empty directory
    And WP files
    And I run `mkdir repo && cd repo && git init -q -b trunk && echo "# site" > NOTES.md && git add -A && git -c user.name=t -c user.email=t@t commit -qm base`

    When I try `wp eject --no-ci --bundle=repo`
    And the return code should be 0
    Then STDOUT should contain:
      """
      nothing was committed
      """
    And the repo/blueprint.json file should exist

    When I run `git -C repo log --oneline`
    Then STDOUT should not contain:
      """
      Add the blueprint
      """

  @require-bin-git
  Scenario: with git, --apply publishes an external package from its folder: no zip and no EJECT.md
    Given an empty directory
    And WP files
    And a wp-content/plugins/wp-eject-behat-external/wp-eject-behat-external.php file:
      """
      <?php
      /**
       * Plugin Name: wp eject Behat external plugin
       * Version: 1.2.3
       */
      """

    When I try `wp eject --no-bundle --no-ci --apply --yes`
    And the return code should be 0
    Then STDOUT should contain:
      """
      as branch vendor/plugin/wp-eject-behat-external (tag 1.2.3); folder removed.
      """
    And the wp-content/plugins/wp-eject-behat-external directory should not exist
    And the wp-content/plugins/wp-eject-behat-external-1.2.3.zip file should not exist
    And the wp-content/EJECT.md file should not exist

    When I run `git tag --list`
    Then STDOUT should contain:
      """
      vendor/plugin/wp-eject-behat-external/1.2.3
      """

    When I run `git ls-tree -r --name-only vendor/plugin/wp-eject-behat-external/1.2.3`
    Then STDOUT should be:
      """
      wp-eject-behat-external.php
      """

  @require-bin-git
  Scenario: --update-deploy regenerates the deploy files of an ejected repository and keeps config.yml and deployignore
    Given an empty directory
    And WP files
    And a wp-content/plugins/wp-eject-behat-external/wp-eject-behat-external.php file:
      """
      <?php
      /**
       * Plugin Name: wp eject Behat external plugin
       * Version: 1.2.3
       */
      """
    And I try `wp eject --git-provider=github --bundle=repo`
    And the return code should be 0
    And I run `printf '%s\n' '// tampered' >> repo/deploy/deploy.php && printf '%s\n' '# mine' >> repo/deploy/config.yml && printf '%s\n' '/mine' >> repo/deploy/deployignore`
    And I run `php -r '$f = "repo/blueprint.json"; $d = json_decode(file_get_contents($f)); $d->meta = (object) array("title" => "Site code"); file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT));'`

    When I try `wp eject --update-deploy --git-provider=github --path=repo`
    And the return code should be 0
    Then STDOUT should contain:
      """
      deploy/deploy.php
      """
    And STDOUT should contain:
      """
      Deploy files updated.
      """
    And STDOUT should contain:
      """
      removed meta
      """

    When I run `php -r 'echo implode(",", array_keys(json_decode(file_get_contents("repo/blueprint.json"), true)));'`
    Then STDOUT should be:
      """
      $schema,preferredVersions,steps
      """

    When I run `cat repo/deploy/deploy.php`
    Then STDOUT should not contain:
      """
      Generated file: may be rewritten
      """
    And STDOUT should not contain:
      """
      tampered
      """

    When I run `cat repo/deploy/config.yml`
    Then STDOUT should contain:
      """
      # mine
      """

    When I run `cat repo/deploy/deployignore`
    Then STDOUT should contain:
      """
      /mine
      """

  Scenario: --update-deploy needs the blueprint of an ejected repository
    Given an empty directory
    And a blueprint-less/index.php file:
      """
      <?php
      """

    When I try `wp eject --update-deploy --git-provider=github --path=blueprint-less`
    Then STDERR should contain:
      """
      No blueprint.json
      """
    And the return code should be 1

  Scenario: the downloads are cached, --no-cache skips the cache, and a cached zip never causes a false modified
    Given an empty directory
    And WP files

    When I try `WP_CLI_CACHE_DIR=$PWD/cache wp eject --no-ci`
    And the return code should be 0
    Then the cache/theme directory should exist
    When I run `ls cache/theme`
    Then STDOUT should contain:
      """
      .zip
      """
    When I run `ls cache/core-checksums`
    Then STDOUT should contain:
      """
      .json
      """

    When I try `rm -r cache && WP_CLI_CACHE_DIR=$PWD/cache wp eject --no-ci --no-cache`
    And the return code should be 0
    Then the cache/theme directory should not exist

    When I try `WP_CLI_CACHE_DIR=$PWD/cache wp eject --no-ci`
    And the return code should be 0
    And I run `php -r '$f = glob("cache/theme/*.zip")[0]; $z = new ZipArchive(); $z->open($f); $n = $z->getNameIndex(0); $z->addFromString(rtrim($n, "/") . "/style.css", "tampered"); $z->close();'`
    And I try `WP_CLI_CACHE_DIR=$PWD/cache wp eject --no-ci`
    And the return code should be 0
    Then STDOUT should not contain:
      """
      failed verification
      """

  Scenario: a default theme file is accepted per file, and one with unknown content is still a modification
    Given an empty directory
    And WP files
    And I run `php -r '$f = glob("wp-content/themes/twenty*/style.css")[0]; file_put_contents($f, "/* local change */\n", FILE_APPEND); file_put_contents("changed.txt", $f);'`

    When I try `wp eject --no-ci`
    Then STDERR should contain:
      """
      failed verification
      """
    And STDOUT should contain:
      """
      1 modified file(s): ERROR
      """
    And the return code should be 1

  @require-bin-git
  Scenario Outline: the deploy branch is the one the existing repository already uses
    Given an empty directory
    And WP files
    And I run `mkdir repo && cd repo && git init -q -b <branch> && echo "# site" > NOTES.md && git add -A && git -c user.name=t -c user.email=t@t commit -qm base`

    When I try `wp eject --git-provider=github --bundle=repo`
    And the return code should be 0
    And I run `cat repo/.github/workflows/deploy.yml`
    Then STDOUT should contain:
      """
      - <branch>
      """

    Examples:
      | branch |
      | trunk  |
      | master |

  @require-bin-git
  Scenario: --git-branch without a name makes the default work branch, from the default branch
    Given an empty directory
    And WP files
    And I run `mkdir repo && cd repo && git init -q -b trunk && echo "# site" > NOTES.md && git add -A && git -c user.name=t -c user.email=t@t commit -qm base`

    When I try `wp eject --git-provider=github --bundle=repo --git-branch`
    And the return code should be 0
    Then STDOUT should contain:
      """
      Created the branch chore/project-setup from trunk
      """

    When I run `git -C repo rev-parse --abbrev-ref HEAD`
    Then STDOUT should be:
      """
      chore/project-setup
      """

    When I run `git -C repo ls-tree -r --name-only trunk`
    Then STDOUT should be:
      """
      NOTES.md
      """

  @require-bin-git
  Scenario: --git-origin-branch is checked out first, and the work branch is made from it
    Given an empty directory
    And WP files
    And I run `mkdir repo && cd repo && git init -q -b trunk && echo "# site" > NOTES.md && git add -A && git -c user.name=t -c user.email=t@t commit -qm base && git checkout -q -b other && echo more > MORE.md && git add -A && git -c user.name=t -c user.email=t@t commit -qm more`

    When I try `wp eject --git-provider=github --bundle=repo --git-origin-branch=trunk --git-work-branch=chore/x --git-staging-branch=qa --git-deploy-branch=trunk`
    And the return code should be 0
    Then STDOUT should contain:
      """
      Created the branch chore/x from trunk
      """

    When I run `git -C repo ls-tree -r --name-only chore/x`
    Then STDOUT should not contain:
      """
      MORE.md
      """

    When I run `cat repo/.github/workflows/deploy.yml`
    Then STDOUT should contain:
      """
      - qa
      """

  @require-bin-git
  Scenario: the old names of the branch flags still work, and an unknown origin branch is an error
    Given an empty directory
    And WP files
    And I run `mkdir repo && cd repo && git init -q -b trunk && echo "# site" > NOTES.md && git add -A && git -c user.name=t -c user.email=t@t commit -qm base`

    When I try `wp eject --git-provider=github --bundle=repo --git-origin-branch=nope`
    Then STDERR should contain:
      """
      there is no branch nope
      """
    And the return code should be 1

    When I try `wp eject --git-provider=github --bundle=repo --git-branch=chore/old --staging-branch=qa --deploy-branch=trunk`
    And the return code should be 0
    Then STDOUT should contain:
      """
      Created the branch chore/old from trunk
      """

  Scenario: a bundle directory is recognized by its generated files, and any other directory is never wiped
    Given an empty directory
    And WP files
    And I run `mkdir other && echo '{}' > other/blueprint.json`

    When I try `wp eject --no-ci --no-git --bundle=out`
    And the return code should be 0
    And I try `wp eject --no-ci --no-git --bundle=out`
    And the return code should be 0
    Then the out/blueprint.json file should exist

    When I try `wp eject --no-ci --no-git --bundle=other`
    Then STDERR should contain:
      """
      is not a bundle written by wp eject
      """
    And the return code should be 1
    And the other/blueprint.json file should exist

  Scenario: temporary files of an interrupted rsync are ignored, real dotfiles are kept
    Given an empty directory
    And WP files
    And I run `touch wp-content/plugins/akismet/.akismet.php.JBqZ7SStjT wp-content/plugins/akismet/.class.php.a1B2c3 wp-content/plugins/akismet/.htaccess.backup`

    When I try `wp eject --no-ci --no-git --bundle=out`
    Then STDERR should contain:
      """
      .akismet.php.JBqZ7SStjT: temporary file of an interrupted transfer
      """
    And STDERR should not contain:
      """
      added .akismet.php.JBqZ7SStjT
      """
    And STDERR should contain:
      """
      added .htaccess.backup (kept)
      """
    And STDOUT should contain:
      """
      2 temporary file(s) ignored
      """
    And the out/.gitignore file should not contain:
      """
      JBqZ7SStjT
      """
    And the out/README.md file should not contain:
      """
      JBqZ7SStjT
      """
    And the out/wp-content/plugins/akismet/.akismet.php.JBqZ7SStjT file should not exist
    And the return code should be 0

  Scenario: --apply leaves a temporary file of an interrupted rsync on disk
    Given an empty directory
    And WP files
    And I run `touch wp-content/plugins/akismet/.akismet.php.JBqZ7SStjT`

    When I try `wp eject --no-bundle --no-ci --no-git --apply --yes`
    And the return code should be 0
    Then the wp-content/plugins/akismet/.akismet.php.JBqZ7SStjT file should exist
    And the wp-content/plugins/akismet/akismet.php file should not exist

  Scenario: a temporary file inside the site's own code is never committed
    Given an empty directory
    And WP files
    And a wp-content/plugins/wp-eject-behat-custom/wp-eject-behat-custom.php file:
      """
      <?php
      /**
       * Plugin Name: wp eject Behat custom plugin
       * Version: 1.0.0
       */
      """
    And I run `touch wp-content/plugins/wp-eject-behat-custom/.helper.php.JBqZ7SStjT`

    When I try `wp eject --no-bundle --no-ci --no-git --keep-plugins=wp-eject-behat-custom`
    And the return code should be 0
    Then the .gitignore file should contain:
      """
      !/wp-content/plugins/wp-eject-behat-custom/
      """
    And the .gitignore file should contain:
      """
      /wp-content/plugins/wp-eject-behat-custom/.helper.php.JBqZ7SStjT
      """

  @require-bin-rsync
  Scenario: build keeps the tool's output out of the way, shows it when the tool fails or with --debug
    Given an empty directory
    And WP files
    And I try `wp eject --no-bundle --git-provider=github --no-git`
    And the return code should be 0
    And a bin/npx file:
      """
      #!/bin/sh
      echo "boom detail" >&2
      exit 3
      """
    And I run `chmod +x bin/npx`

    When I try `PATH=$PWD/bin:$PATH wp --require=deploy/deploy.php build --build-dir=build`
    Then STDERR should contain:
      """
      boom detail
      """
    And STDERR should contain:
      """
      Command failed (3)
      """
    And the return code should be 1

    When I try `PATH=$PWD/bin:$PATH wp --require=deploy/deploy.php build --build-dir=build --debug`
    Then STDERR should contain:
      """
      boom detail
      """
    And the return code should be 1

  Scenario: .gitignore and .editorconfig have no marker, and are still recognized and regenerated
    Given an empty directory
    And WP files

    When I try `wp eject --no-bundle --no-ci --no-git`
    And the return code should be 0
    Then the .editorconfig file should not contain:
      """
      Generated file
      """
    And the .gitignore file should not contain:
      """
      Generated file
      """
    And the .gitignore file should not contain:
      """
      A whitelist
      """

    When I run `echo '# tampered' >> .gitignore && echo '# tampered' >> .editorconfig`
    And I try `wp eject --no-bundle --no-ci --no-git`
    And the return code should be 0
    Then the .gitignore file should not contain:
      """
      tampered
      """
    And the .editorconfig file should not contain:
      """
      tampered
      """

  Scenario: files written by an earlier version, with the generated header, are regenerated and lose it
    Given an empty directory
    And WP files
    And I try `wp eject --git-provider=github --no-git --bundle=repo`
    And the return code should be 0
    And I run `for f in repo/deploy/deployignore repo/.gitignore repo/deploy/deploy.php; do (printf '# Generated file: may be rewritten when the project is regenerated.\n#\n'; cat $f) > $f.new && mv $f.new $f; done`
    And I run `echo '# mine' >> repo/deploy/deployignore`

    When I try `wp eject --update-deploy --git-provider=github --path=repo`
    And the return code should be 0
    Then STDOUT should contain:
      """
      removed the "generated" header
      """
    And the repo/deploy/deployignore file should not contain:
      """
      Generated file
      """
    And the repo/deploy/deployignore file should contain:
      """
      # mine
      """
    And the repo/.gitignore file should not contain:
      """
      Generated file
      """
    And the repo/deploy/deploy.php file should not contain:
      """
      Generated file
      """

  @require-bin-git
  Scenario: the README that --update-deploy writes is the one wp eject wrote (but the title), packages in the same order
    Given an empty directory
    And WP files
    And a wp-content/plugins/wp-eject-behat-external/wp-eject-behat-external.php file:
      """
      <?php
      /**
       * Plugin Name: wp eject Behat external plugin
       * Version: 1.2.3
       */
      """
    And a wp-content/plugins/wp-eject-behat-custom/wp-eject-behat-custom.php file:
      """
      <?php
      /**
       * Plugin Name: wp eject Behat custom plugin
       * Version: 1.0.0
       */
      """
    And I try `wp eject --git-provider=github --keep-plugins=wp-eject-behat-custom --bundle=repo`
    And the return code should be 0
    And I run `cp repo/README.md first-readme.md`

    When I try `wp eject --update-deploy --git-provider=github --path=repo`
    And the return code should be 0
    And I run `tail -n +2 first-readme.md > a.md && tail -n +2 repo/README.md > b.md && diff a.md b.md`
    Then the return code should be 0
