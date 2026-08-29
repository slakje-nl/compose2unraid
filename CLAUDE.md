# CLAUDE.md

Guidance for Claude Code when working in this repository.

compose2unraid is a small Unraid plugin for people who keep their Docker Compose stacks in a git
repository and deploy from a terminal. It brings the stacks on disk up when Docker starts, and it
shows them under the Docker menu: containers, health, versions, resource use, whether what runs
still matches the files on disk, and whether Unraid found a newer image. The icon menu on each
row does a little work: sync a stack whose files changed, recreate it, update the images Unraid
flagged, and start, stop or restart a service. Each is one `docker compose` command. Everything
else is read-only, and the plugin knows nothing about git.

Repository: `git@github.com:slakje-nl/compose2unraid.git`
Install URL: `https://github.com/slakje-nl/compose2unraid/releases/latest/download/compose2unraid.plg`

This file is the rulebook and the command reference.

---

## Toolchain

- **bash** for the logic, **jq** to build JSON, **PHP** (whatever Unraid ships) for rendering
  the page, and nothing else at runtime. No Node, no Composer, no framework, no database, no
  build step beyond assembling one XML file.
- **just** is the task runner. Every gate and dev command has a recipe; see `just --list`.
- Linters and tests run in **Docker**: `koalaman/shellcheck`, `php:cli`, `bats/bats` (with git and
  jq added in `tests/Dockerfile`), `zricethezav/gitleaks`. Nothing needs installing locally beyond
  `just` and Docker, and CI runs the identical containers.
- On the box the plugin depends on `docker compose`. The `.plg` downloads the static release once,
  with a checksum, and keeps it on flash.

## Commands

```
just               list the recipes
just check         every gate: lint, test, build, security, commit subjects (run before each commit)
just lint          shellcheck on every script, php -l on every PHP file and page
just test          bats tests against a fake docker, no Unraid needed
just build         assemble dist/compose2unraid.plg from src/ with a dev version
just security      gitleaks over the tree
just commits       every subject on the branch is 72 characters or fewer, as commitlint wants
just push host     copy the built .plg to the box you name and run plugin install there
```

Recipes are the single source of truth for how anything runs; prefer adding a recipe over
documenting a raw command here.

**`just check` MUST pass before any change is considered done.** It runs the exact same checks as
CI.

---

## Hard rules (do not violate)

- **No em dashes** in any code, doc, commit message or UI text. Use a comma, a colon, a full stop
  or parentheses.
- **Self-descriptive code, zero comments.** Names carry the meaning; extract a well-named function
  or variable instead of explaining one. Where a WHY genuinely needs recording (an upstream bug, a
  load-bearing ordering, an invariant) it goes in the commit message, which is dated, attributed
  and reachable from `git blame`. Shebangs, the XML structure of the `.plg` and the header block of
  a `.page` file are not comments. When you touch a file, delete any comment you find in the lines
  you changed.
- **No suppressions.** No `# shellcheck disable=`, no `@` error silencing in PHP, no
  `2>/dev/null` used to hide a failure rather than to discard expected noise, no `|| true` on a
  command whose failure matters. Fix the cause. If a rule genuinely cannot be satisfied, raise it
  and agree on the exception first.
- **British spelling** in docs, UI text and commit messages: initialise, favour, behaviour.
- **New dependencies need approval.** Ask before adding any tool, container image, GitHub Action
  or runtime binary that is not in the approved set below. Propose it, say what it buys and what
  it costs, and wait. Never add one as a side effect of solving something else.
- **Do not push to `main`.** Work on a feature branch and open a PR; `main` is protected.
- **Never claim something works on an Unraid box without output from that box.** Tests prove
  the logic; only a pasted result from a real install proves the plugin.
- **Six verbs, each one compose command.** The icon menu can apply a stack (`up -d
  --remove-orphans`, "Sync stack"), recreate it (`up -d --force-recreate --remove-orphans`),
  update services (`pull` then `up -d` of those, offered only for an image Unraid flagged), and
  start, stop or restart services; the header carries no links. "Show diff" is the same dry run
  the status uses and changes nothing. Nothing else changes a container from the page: no
  `down`, no volume removal, no image removal beyond the one an update replaced. Those stay
  terminal commands the popup writes out.

The approved set:

| | |
|---|---|
| Runtime | bash, `jq` and PHP as shipped by Unraid, `docker compose` (static release) |
| Dev containers | `koalaman/shellcheck`, `php:cli`, `bats/bats`, `zricethezav/gitleaks` |
| Toolchain | `just`, Docker |
| CI | `actions/checkout`, `actions/setup-node`, `jdx/mise-action`, `softprops/action-gh-release`, `@commitlint/cli`, `@commitlint/config-conventional` |

---

## Promises to the user

These are written in the README and every change must keep them true.

- **A container's image changes only when the user asks**: the update link on a service Unraid
  flagged, or a pull in a terminal. The boot hook uses `--no-recreate`, apply never pulls, and
  "update ready" comes from Unraid's own Check for Updates status file, never from a request the
  plugin made.
- **Boot never depends on the network.** The `docker_started` hook brings up whatever is on disk,
  detached from the event (`setsid ... &`), so emhttp's Docker start never waits for a stack.
- **Nothing changes without a click.** Refreshing the page runs `status.sh`, which only reads.
  The one endpoint that changes anything, `run.php`, accepts POST only, runs `apply.sh` with a
  validated stack name and service names, and streams its output into the dialog the click
  opened. `apply.sh` runs under `setsid` and the streaming loop watches for the client going
  away (a heartbeat comment every second while the child is silent); closing the dialog kills the
  whole process group, which also releases the lock. The container icon menu is Unraid's own
  `context` menu from `dynamix.js` (loaded on
  every page, CSS in `context.standalone.css`), attached the way the Docker tab does it: an
  inline `onclick` on the icon calls `context.attach('#id', opts)` and the same click, bubbling
  to `document`, opens it, above the icon when it would not fit below (`above: 'auto'`). Items post
  through the same form.
- **A broken stack is shown as such and the others are unaffected**, at boot and on the page.

## Architecture

- **bash owns the logic, PHP renders.** The boot hook must be bash, and `status.sh` and
  `apply.sh` share its helpers (`stacks`, `compose`, the config reader, drift detection).
  `status.sh` prints one JSON document, built with `jq`, including the services each compose
  file defines (`compose config --format json`: name, container name, icon label) so the page
  can list a stack before it runs; `include/stacks.php` turns it into the table.
  `apply.sh <stack>` brings a stack up according to its drift;
  `apply.sh <stack> --pull <service>...` pulls and recreates exactly those services and removes
  the images they replaced when nothing else uses them; `--recreate` forces every container;
  `--start`, `--stop` and `--restart` pass the services, or the whole stack when none is named,
  to that compose verb. All take the boot hook's `flock`. `--diff` prints the dry run's plan and
  takes no lock.
- **The run dialog is a form posting into an iframe.** `run.php` validates, runs `apply.sh`
  with `proc_open`, and echoes each output line as it arrives, so a pull's progress shows live.
  Closing either dialog, by its button or Escape, refreshes the table. No background runs, no
  run files, no history.
- **Drift is what Compose itself would do.** `stack_drift` runs
  `docker compose up -d --dry-run --no-build --pull never --remove-orphans` and reads the plan:
  any container, network or volume it would create, recreate or remove makes the whole stack
  `changed`; nothing is attributed to a service, "Show diff" prints the plan for that. The plan
  lines are `Container <name> Recreate` in the pinned compose and ` DRY-RUN MODE -  Container
  <name> Recreate` in the 2.x line, so `plan_changes_something` matches the words wherever they
  sit on the line, never a field position. Apply runs the same
  command without `--dry-run`, so the page and the action can never
  disagree. No containers is `new`; containers without a directory is `gone`; a plan Compose
  cannot make (a file it cannot read) is `broken` with the last line of its error; everything
  else is `insync`. Files a service bind-mounts are not part of the plan and not tracked. The
  read-only compose calls in `status.sh` run under `timeout` (60 seconds): `compose up` waits for
  `depends_on` health conditions, a dry run included, and a container that never turns healthy
  would otherwise leave a compose process behind on every refresh.
- **Secrets are `stacks/<name>/.env`**, optional, placed by the user, ignored by their sync. The
  commands popup shows the path it is expected at; nothing checks that it exists.
- **Image updates come from Unraid.** `/var/lib/docker/unraid-update-status.json`, written by the
  Docker tab's Check for Updates, covers every container on the box, template or not. The page's
  Check for updates button posts to the same `DockerUpdate.php` the Docker tab posts to. The page
  reads it and mirrors Unraid's own normalisation of image names (`docker.io/` stripped,
  `library/` for official images, `:latest` when there is no tag). A container whose image
  carries the `remote` digest from that file is up to date whatever the file's `status` says,
  which is how the badge clears right after an update.
- **Config is an Unraid `.cfg`** with one key, `BASE_PATH`, at
  `/boot/config/plugins/compose2unraid/compose2unraid.cfg`, read with `parse_plugin_cfg` in PHP and
  by one `sed` in bash. The default comes from `default.cfg`, the single place a
  default is written. There is no settings page.
- **Compose is always invoked the same way**: `docker compose --project-directory <stack dir>
  -p <stack name> ...`, through one `compose()` function in `common.sh`. The project name is the
  directory name, always.
- **The page refreshes in place.** The page renders at once with a placeholder div and fetches
  `include/stacks.php?stats=0` immediately (`status.sh --without-stats`, no `docker stats`
  sample, so the table is up in well under a second), then the full fragment right after, then
  every fifteen seconds (never while the tab is hidden), replacing that div each time. Nothing
  on the page itself runs `status.sh`. No timed reload.
- **Logging is one function**, `log`, which writes to `/var/log/compose2unraid/compose2unraid.log`
  and to syslog via `logger -t compose2unraid`.

Where things live on the box:

```
/boot/config/plugins/compose2unraid/     config and the downloaded compose binary (flash)
<base path>/stacks/<name>/               one directory per stack, synced by the user
/var/log/compose2unraid/                 the plugin log (tmpfs)
/usr/local/emhttp/plugins/compose2unraid/  installed page, PHP and scripts (tmpfs)
```

`<base path>` defaults to `/mnt/user/appdata/compose2unraid`, which exists on every Unraid box
whether or not it has a cache pool.

Repository layout:

```
src/
  Compose2Unraid.page            the page under the Docker menu, the run and commands dialogs
  include/common.php             helpers: run status.sh, read Unraid's update status, format
  include/stacks.php             the table, included by the page and fetched for refreshes
  include/run.php                POST only: runs apply.sh and streams its output
  scripts/common.sh              config, logging, stacks(), compose(), drift detection
  scripts/status.sh              the JSON the page renders
  scripts/apply.sh               apply a stack, or pull and recreate named services
  scripts/hook.sh                brings every stack up when Docker starts
  event/docker_started           starts scripts/hook.sh detached from the event
  default.cfg                    BASE_PATH
plg/compose2unraid.plg.in        the .plg template: header, install, remove; files are inlined
tools/build-plg.sh               assembles dist/compose2unraid.plg from src/ and the template
tests/                           bats files and the fake docker under tests/fakes/
dist/                            build output, gitignored
```

`src/` is installed verbatim to `/usr/local/emhttp/plugins/compose2unraid/`. Everything in the
`.plg` is inline CDATA, so an install needs no network apart from the one-time binary download.

---

## Code style

### bash

- `#!/bin/bash` and `set -euo pipefail` at the top of every script.
- 2-space indentation, lines under 100 characters.
- Small named functions; `main "$@"` at the bottom; `local` for every function variable.
- Commands with arguments are arrays: `cmd=(docker compose -p "$name" up -d); "${cmd[@]}"`.
- `printf` over `echo -e`; `[[ ]]` over `[ ]`; `$( )` over backticks.
- Every script sources `common.sh` and uses its helpers rather than repeating a path or a docker
  invocation.
- shellcheck clean with no directives. `common.sh` is linted through the scripts that source it
  (`-x -P SCRIPTDIR`), because on its own every helper it defines for its callers reads as unused.
- Add a blank line after each early-return guard and between logically unrelated blocks.

### PHP

- `declare(strict_types=1)` at the top of every file under `include/`.
- 4-space indentation, lines under 100 characters, PSR-12 shape.
- `.page` files contain the header block, `---`, then markup with the smallest possible amount of
  PHP. Anything with a branch worth testing belongs in bash.
- Every value printed into HTML goes through `compose2unraid_h`. Shell calls go through
  `escapeshellarg` for every argument and name only the two fixed scripts. A stack name is
  accepted only if it matches `^[a-z0-9][a-z0-9_-]{0,63}$` and its directory exists; service
  names match the same pattern and Compose refuses one the stack does not have.
- Follow the Docker tab's markup and classes (`table.tablesorter`, `span.outer/inner`, the
  `fa-play started green-text` line, `green-text`/`orange-text` words) so the page reads like the
  rest of the webGUI. Own CSS only for what Unraid has no class for.

### UI text

- Full sentences, plain words, addressed to someone running a home server.
- No emoji in the page. Font Awesome icons in the Docker tab's colours are the vocabulary.

---

## Testing

- **bats-core, in Docker**, over the scripts in `src/scripts/`. `just test` runs the suite; CI
  runs the same container.
- **A fake `docker` on `PATH`, never the real one.** `tests/fakes/docker` records every invocation
  to a log file and answers from a small fixture of containers (id, stack, service, image, image
  id, config hash, created). Its `compose up --dry-run` plans from a hash of the compose file
  and the `.env`, so drift tests are about content, not about magic strings.
- **Every test asserts something.** A test that runs a script without checking output or side
  effects is not a test; delete it.
- **Split the action from the validation.** Run the script in its own statement, then assert on
  `$status`, `$output` and the files it wrote.
- **Cover every state the page can show**: new, changed (a service added, removed, or edited),
  insync, gone, broken, and apply on a stack without an `.env`; the hook with a failing stack, an
  empty stacks directory, a slow daemon and
  a held lock; apply for each drift state, a refused stack, a compose failure; update pulling
  only the named services, an unknown service, a failed pull, a replaced image still in use.
- **The page is rendered in the test too.** `just test-render` runs the page and the fragment in
  the `php:cli` container against `tests/render/`: a `status.sh` stub in plain `sh` that prints a
  fixed status, a stub of Unraid's `parse_plugin_cfg`, and a stub update-status file. A fatal
  fails it, and it asserts every state and link the page can show. `php -l` alone missed a call
  before its include once; this is what catches that class.

---

## Git and commits

- **Conventional Commits**, checked by commitlint in CI. Scopes: `plg`, `scripts`, `ui`, `hook`,
  `build`, `ci`, `docs`, `deps`, `repo`. Types: `feat`, `fix`, `refactor`, `test`, `docs`,
  `chore`, `ci`. Subject under 72 characters, body wrapped at 100.
- **Every commit is an atomic, green, runnable slice.** `just check` passes at every commit so
  `git bisect` always lands on a working tree. Never commit a broken intermediate state and fix it
  in the next commit.
- **Verify every commit, but do not wait for approval.** Keep moving and let the user review the
  branch as a whole. Push the branch at the end of each phase so CI runs.
- Commits carry a `Co-Authored-By` trailer naming the model that wrote them.
- **PR titles are Conventional Commits too**: `type(scope): summary`, the scope left out when the
  PR spans several. `main` is protected, rebase-and-merge only.
- **No git hooks.** Run `just check` before every commit.

## Release

- Every push to `main` runs `release.yml`: it computes the next integer tag `vN`, builds the
  `.plg` with version `YYYY.MM.DD` (with a `.N` suffix when a release already carries that date),
  and attaches `compose2unraid.plg` to a GitHub release. The install URL
  `releases/latest/download/compose2unraid.plg` always serves the newest one, and the `.plg`'s
  own `pluginURL` points there so Unraid's update check works.
- Local builds get a version of `YYYY.MM.DD.dev<HHMM>`, which sorts above the day's release and
  forces a reinstall on the box. `plugin install` refuses a file at the installed plugin's own
  path on flash, so `just push` copies to `/tmp` and installs from there.
- Nothing else deploys from this repository. There is no environment, no secret and no box in CI.

---

## Security and privacy

**This repository is public.** Everything committed here is permanent and world-readable,
including anything later removed in a follow-up commit. Treat every file, log line, fixture and
screenshot as published the moment it is written.

### Never commit

- **Real hostnames or IPs.** No `192.168.*`, no `10.*`, no `*.lan` or `*.local` name that
  resolves somewhere, no VPN addresses. Where a literal is unavoidable in prose, use an RFC 5737
  documentation address (`192.0.2.10`) or `example.com`.
- **A real stacks repository or a real stack name.** Examples and fixtures use stacks called
  `example-app` and `example-db`, or `alpha` and `beta` in tests.
- **Filesystem paths that reveal a personal layout.** `/mnt/user/appdata/compose2unraid` and
  `/boot/config/plugins/compose2unraid` are the same for every Unraid user and are fine. A path
  naming someone's shares, drives or other plugins is not.
- **Credentials of any kind**, including ones that look fake, and `.env` files of any shape.

### Never leak at runtime or in CI

- **Never print an `.env`** or its values into a log or the page. The page shows whether the file
  exists, never what is in it.
- **Never echo an environment variable in a CI step**, and no `set -x` anywhere a secret could
  be in scope.
- **`gitleaks` runs in `just security` and in CI**, and its finding is a hard failure. Never add
  an allowlist entry to silence a real finding; fix the file.

### Before pushing a branch

```bash
git log --format='%ae' | sort -u                 # only the GitHub noreply address
just security                                     # gitleaks clean

# any email address; the repository SSH URL is the only expected hit
grep -rnE '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' . --exclude-dir=.git

# private network addresses; 192.0.2.x is RFC 5737 documentation space and is fine
grep -rnE '192\.168\.|10\.[0-9]{1,3}\.[0-9]{1,3}\.|172\.(1[6-9]|2[0-9]|3[01])\.' . --exclude-dir=.git
```

Both patterns match any address rather than a specific one, so this file never names the values
it is protecting. Anything they turn up that is not listed above as expected is a leak.

---

## Documentation

- **`CLAUDE.md`** is permanent: conventions, architecture, the promises, the privacy rules.
  Anything that must still be true in a year lives here.
- **`README.md`** is the only user-facing document: what the plugin does and does not do, the
  promises, install, the stacks directory layout, what the page shows, uninstall.

Any change that alters externally-visible behaviour (a config key, a path on the box, what the
page shows, what the hook does at boot) MUST update `README.md` in the same commit.

Do not create other doc files without being asked.
