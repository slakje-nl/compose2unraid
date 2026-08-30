# compose2unraid

> [!WARNING]
> **Always verify code you use from the internet.** That applies to this repository as much as
> any other. Read the source, understand what it can reach, and decide for yourself before you
> run it on infrastructure you care about.

A small Unraid plugin for people who keep their Docker Compose stacks in a git repository and
deploy from a terminal. It brings the stacks up when Docker starts and shows them on a Compose
tab next to Docker: containers, health, versions, resource use, whether what runs still matches
the files on disk, and whether a newer image is out, with an icon menu whose every entry is one
`docker compose` command.

## Install

In **Plugins, Install Plugin**, paste:

```
https://github.com/slakje-nl/compose2unraid/releases/latest/download/compose2unraid.plg
```

The plugin downloads a static `docker compose` release once (pinned by version and SHA-256),
keeps it on the flash drive, and installs it to `/usr/local/lib/docker/cli-plugins/` at every
boot. That is the `docker compose` the box uses; uninstalling removes it again. The page is the
**Compose** tab in the top bar, between Docker and VMs, shown while Docker runs.

The only setting is the base path, `/mnt/user/appdata/compose2unraid` by default. To change it,
put `BASE_PATH="/mnt/cache/compose2unraid"` in
`/boot/config/plugins/compose2unraid/compose2unraid.cfg` (a pool path skips the fuse layer, which
compose bind mounts appreciate). `stacks/` lives under it.

Uninstalling leaves your containers running and the base path untouched.

## How it works

The plugin reads one directory:

```
/mnt/user/appdata/compose2unraid/stacks/
  example-app/
    compose.yaml            required: this is what makes the directory a stack
    compose.override.yaml   optional, for Unraid labels such as net.unraid.docker.icon
    .env                    optional secrets, kept on the box, never in git
    nginx/default.conf      anything the stack bind-mounts, referenced relatively
  example-db/
    compose.yaml
```

How the files get there is up to you: `rsync`, `scp`, a `git pull` you run over ssh, a CI job.
A typical `just deploy` in a stacks repository is
`rsync --delete --exclude .env stacks/ box:/mnt/user/appdata/compose2unraid/stacks/`, then
**Sync stack** on the page, or `docker compose up -d` over ssh, for what changed. The plugin does
not know about git and never fetches anything.

The plugin creates `stacks/` when it is installed (or, if the array was not up yet, when Docker
starts). Every directory directly under it with a compose file is a stack. The project name is
the directory name, always, so container names stay stable and `docker compose ls` shows what
the page shows. Compose finds `compose.yaml` and the override file by itself.

## Requirements

Unraid 7.2.3 or newer on x86_64. `git` is not needed. The flash drive is unencrypted, so anything in
`.env` is readable by whoever holds the drive; that is true of every Unraid plugin's secrets.

## Development

Everything runs through `just`; the linters and the tests run in Docker, so nothing needs
installing beyond `just` and Docker.

```bash
just               # list the recipes
just check         # lint, test, build, security: what CI runs
just build         # dist/compose2unraid.plg with a dev version
just push root@box # copy the built file to the box and install it there
```

The bash scripts are tested with bats against a fake `docker`; see `tests/`.

## Licence

MIT, see [`LICENSE`](LICENSE).

## AI

Claude was used responsibly in the development of this repository. It was not left on its own:
it was monitored and guided by a human (who is also a software engineer!).
