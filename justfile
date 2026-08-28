set shell := ["bash", "-uc"]

shellcheck := "docker run --rm -v \"$PWD:/mnt\" -w /mnt koalaman/shellcheck:stable"
php := "docker run --rm -v \"$PWD:/mnt\" -w /mnt php:cli-alpine php"
gitleaks := "docker run --rm -v \"$PWD:/mnt\" -w /mnt zricethezav/gitleaks"
tests := "compose2unraid-tests"

default:
    @just --list --unsorted

check: lint test build security commits

lint:
    {{ shellcheck }} -x -P SCRIPTDIR tools/*.sh $(find src/scripts -name "*.sh" ! -name common.sh) src/event/* tests/*.bash tests/fakes/*
    for f in $(find src -name '*.php' -o -name '*.page'); do {{ php }} -l "$f" | grep -v '^No syntax errors'; test "${PIPESTATUS[0]}" -eq 0 || exit 1; done

test *args: test-render
    docker build -q -t {{ tests }} tests >/dev/null
    docker run --rm -v "$PWD:/code" -w /code {{ tests }} {{ args }} tests

test-render:
    docker run --rm \
      -v "$PWD/src:/usr/local/emhttp/plugins/compose2unraid:ro" \
      -v "$PWD/tests/render/status.sh:/usr/local/emhttp/plugins/compose2unraid/scripts/status.sh:ro" \
      -v "$PWD/tests/render/Wrappers.php:/usr/local/emhttp/plugins/dynamix/include/Wrappers.php:ro" \
      -v "$PWD/tests/render/update-status.json:/var/lib/docker/unraid-update-status.json:ro" \
      -v "$PWD/tests/render/render.php:/render.php:ro" \
      php:cli-alpine php /render.php

build version="":
    tools/build-plg.sh {{ version }}
    docker run --rm -i php:cli-alpine php -r 'exit(simplexml_load_string(stream_get_contents(STDIN), "SimpleXMLElement", LIBXML_NOENT)["name"] == "compose2unraid" ? 0 : 1);' < dist/compose2unraid.plg

commits:
    git log --format='%s' main..HEAD | awk 'length > 72 { print "subject over 72 characters: " $0; bad = 1 } END { exit bad }'

security:
    {{ gitleaks }} dir . --no-banner

push host:
    scp dist/compose2unraid.plg {{ host }}:/tmp/compose2unraid.plg
    ssh {{ host }} plugin install /tmp/compose2unraid.plg
