# Developer guide

## Build

```sh
composer install
npm ci
npm run build        # production bundle into js/
npm run watch        # rebuild on change
```

`js/` and `vendor/` are build output and are not in the repository. Run
`npm run build` before you enable the app, or the sidebar field never
loads. Run it through npm, not webpack directly: the config reads the
package name from npm.

## Checks

Run all of them before a pull request. CI runs the same commands.

```sh
composer lint        # php -l over every file
composer cs:check    # nextcloud/coding-standard; composer cs:fix repairs
composer test        # PHPUnit
npm run lint         # ESLint
npm test             # node --test over tests/js/
npm run build
```

Every function in `lib/` and `src/` must have a cognitive complexity of 15
or less. `tests/Unit/CognitiveComplexityTest.php` scores `lib/` with
`nikic/php-parser`, and `sonarjs/cognitive-complexity` in `.eslintrc.json`
scores `src/`. If a function goes over, split it. Do not raise the limit.

The workflows in `.github/workflows/` are the templates from
[`nextcloud/.github`](https://github.com/nextcloud/.github), with two
changes: the runner label is `ubuntu-latest`, and each workflow also runs
on a push to `main`. The PHP matrix comes from `<php>` in
`appinfo/info.xml`.

## Layout

```
appinfo/          info.xml, routes.php
lib/AppInfo/      registers the three listeners and the notifier
lib/Controller/   the OCS endpoint for the pending target, the admin endpoint
lib/Listener/     BeforeShareCreated, ShareCreated, ShareDeleted
lib/Notification/ Notifier renders a notification, TargetNotifier sends one
lib/Service/      ShareTargetService: pending store, settings, permission check
lib/Validator/    PathValidator: the one authority on what a path may hold
src/              share-target.js (the field), admin.js, path-check.js, target-note.js
tests/Unit/       PHPUnit
tests/js/         node --test
```

## How it works

**The field.** `src/share-target.js` defines the custom element
`oca_files_set_target-share-target` and registers it with
`registerSidebarAction()` from `@nextcloud/sharing/ui`. Nextcloud mounts it
in `SharingDetailsTab.vue` under "Advanced settings" and sets `node` and
`share` on it as properties. `enabled()` shows the field only when the
initial state says `can_use`, the share does not exist yet, the share type
is account or group, and the node carries the share permission. The
`onSave` callback of the action runs after the share exists, so the app
does not use it.

**The pending target.** The share does not exist while the user types, so
the field cannot put the path on it. It sends the path to the app's own
endpoint, and the server holds it until the share is created.

| Route | Body | Effect |
|---|---|---|
| `POST /ocs/v2.php/apps/files_set_target/api/v1/target` | `fileId`, `path`, `shareWith` | validate, store, return `sanitized` |
| `DELETE .../api/v1/target/{fileId}` | `shareWith` in the query | remove the pending target |

Both answer 403 when the caller may not set a target, and 400 without
`shareWith`. Storage is `OCP\Config\IUserConfig`: user is the caller, key
is `target.<fileId>.<first 16 hex of sha1(shareWith)>`, value is
`{path, ts}`.
No table, no migration, no cron. A value older than one hour is ignored.

**Applying it.** `BeforeShareCreatedListener`, for account and group
shares only:

1. Take the pending target for (sharer, file, recipient). The read deletes
   the row. No target, no work.
2. Check the permission of the sharer again, and validate the path again.
3. Account share with a parent segment: make the parent folder in the home
   of the recipient when `enable_auto_create_dir` holds, and keep the
   highest folder made in the share attribute
   `files_set_target/created_dir`. Without the setting, the parent folder
   must exist. If it does not, abort.
4. `$share->setTarget('/' . $path)`. `Manager::createShare` computes the
   default target before it dispatches the event, so the provider persists
   this value as `file_target`.
5. Set the share attribute `files_set_target/target_path` for the
   listeners that run after creation.

A failure calls `$event->setError($message)` **and**
`$event->stopPropagation()`. `Manager::createShare` throws only when both
hold. The share is not created, and the sharer gets the message.

**Group shares.** The target reaches the group row, but Nextcloud
auto-accepts the share for every member on `ShareCreatedEvent` and makes a
per-member row with the default target, and that row wins.
`ShareCreatedListener` therefore calls `moveShare($share, $uid)` for every
member, which writes the target into that row. A member that fails is
logged and skipped: the share exists, and the app cannot abort it any
more. The app makes no folder in the home of a member.

**Cleanup.** `ShareDeletedListener` removes the folders under
`created_dir` after an account share goes, while `enable_auto_remove_dir`
holds, and only while each folder is empty. `ShareDeletedEvent` fires after
`provider->delete()`, so the share is no longer in the listing.

**Notification.** `ShareCreatedListener` reads the path from the
`target_path` attribute and hands it to `TargetNotifier`, which sends for
account shares only.

**Two copies of the rules.** `lib/Validator/PathValidator.php` and
`src/path-check.js` hold the same rules. The server is the authority. A
rule that changes in one must change in the other, and in both test files.

Known limits:

- The create response reports the default `file_target`. Nextcloud resets
  the in-memory target after `provider->create()`. The row holds the
  custom one.
- If `provider->create()` fails after step 3, the new parent folder stays.
- Two shares of the same file to the same recipient inside one hour both
  get the last written target.
- The location for a member who joins the group later is not verified on
  a live server.

## Release

The app declares one Nextcloud major: `min-version` equals `max-version`
in `appinfo/info.xml`, and `composer.json` pins `nextcloud/ocp` to the
same major. A new major is a new release: raise both, run the checks on
that major, release.

`make dist` builds `js/` and a production `vendor/`, then packages
`build/artifacts/files_set_target.tar.gz`. The file list comes from
`git ls-files`, so nothing untracked reaches the tarball. `make appstore`
packages only what is built and refuses a `vendor/` with dev packages.

Publishing is `appstore-build-publish.yml`. Keep `<version>` in `info.xml`
and `package.json` the same. Tag `vX.Y.Z` to match, publish a GitHub
release, and the workflow builds, signs and uploads. It needs:

- a signing certificate for the app id, from a pull request on
  [`nextcloud/app-certificate-requests`](https://github.com/nextcloud/app-certificate-requests)
- the repository secret `APP_PRIVATE_KEY`, the key of that certificate
- the repository secret `APPSTORE_TOKEN`, from the apps.nextcloud.com account

None of the three exists yet.
