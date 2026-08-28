#!/bin/bash

export SCRIPTS="$BATS_TEST_DIRNAME/../src/scripts"

setup_sandbox() {
  SANDBOX="$(mktemp -d)"
  export COMPOSE2UNRAID_FLASH_DIR="$SANDBOX/flash"
  export COMPOSE2UNRAID_LOG_DIR="$SANDBOX/log"
  export COMPOSE2UNRAID_LOCK_FILE="$SANDBOX/lock"
  export FAKE_DOCKER_LOG="$SANDBOX/docker.log"
  export FAKE_DOCKER_CONTAINERS="$SANDBOX/containers"
  export PATH="$BATS_TEST_DIRNAME/fakes:$PATH"
  BASE="$SANDBOX/base"
  STACKS="$BASE/stacks"
  mkdir -p "$COMPOSE2UNRAID_FLASH_DIR" "$STACKS"
  : > "$FAKE_DOCKER_LOG"
  : > "$FAKE_DOCKER_CONTAINERS"
  write_cfg BASE_PATH="$BASE"
}

teardown_sandbox() {
  rm -rf "$SANDBOX"
}

write_cfg() {
  local pair
  : > "$COMPOSE2UNRAID_FLASH_DIR/compose2unraid.cfg"
  for pair in "$@"; do
    printf '%s="%s"\n' "${pair%%=*}" "${pair#*=}" >> "$COMPOSE2UNRAID_FLASH_DIR/compose2unraid.cfg"
  done
}

make_stack() {
  local name="$1" version="${2:-1}" file="${3:-compose.yaml}"
  mkdir -p "$STACKS/$name"
  printf 'services:\n  app:\n    image: example/%s:%s\n' "$name" "$version" \
    > "$STACKS/$name/$file"
  : > "$STACKS/$name/.env"
}

stack_hash() {
  local name="$1" file
  file="$(find "$STACKS/$name" -maxdepth 1 -name '*compose.y*ml' | sort | head -1)"
  (cat "$file"; [[ -f "$STACKS/$name/.env" ]] && cat "$STACKS/$name/.env") | md5sum | cut -c1-12
}

add_running() {
  local name="$1" service="${2:-app}" hash="${3:-$(stack_hash "$1")}"
  local created="${4:-2021-01-01T00:00:00Z}" image="${5:-example/$1:1}" image_id="${6:-sha256:$1-1}"
  printf '%s %s %s %s %s %s %s\n' "c-$name-$service" "$name" "$service" "$image" "$image_id" \
    "$hash" "$created" >> "$FAKE_DOCKER_CONTAINERS"
}

docker_calls() {
  cat "$FAKE_DOCKER_LOG"
}

plugin_log() {
  cat "$COMPOSE2UNRAID_LOG_DIR/compose2unraid.log"
}

hold_lock() {
  exec 8>> "$COMPOSE2UNRAID_LOCK_FILE"
  flock 8
}

status_json() {
  "$SCRIPTS/status.sh"
}
