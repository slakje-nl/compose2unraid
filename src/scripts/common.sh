#!/bin/bash
set -euo pipefail

PLUGIN_NAME=compose2unraid
SCRIPTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FLASH_DIR="${COMPOSE2UNRAID_FLASH_DIR:-/boot/config/plugins/$PLUGIN_NAME}"
LOG_DIR="${COMPOSE2UNRAID_LOG_DIR:-/var/log/$PLUGIN_NAME}"
LOCK_FILE="${COMPOSE2UNRAID_LOCK_FILE:-/var/lock/$PLUGIN_NAME.lock}"
DEFAULT_CFG="$SCRIPTS_DIR/../default.cfg"
CFG_FILE="$FLASH_DIR/$PLUGIN_NAME.cfg"
LOG_FILE="$LOG_DIR/$PLUGIN_NAME.log"
COMPOSE_FILE_NAMES=(compose.yaml compose.yml docker-compose.yaml docker-compose.yml)
COMPOSE_PROJECT_LABEL=com.docker.compose.project
COMPOSE_SERVICE_LABEL=com.docker.compose.service
COMPOSE_WORKING_DIR_LABEL=com.docker.compose.project.working_dir
DOCKER_WAIT_SECONDS=120

STACKS_DIR=""

configured_base_path() {
  local -a files=("$DEFAULT_CFG")
  if [[ -f "$CFG_FILE" ]]; then
    files+=("$CFG_FILE")
  fi
  sed -n 's/^BASE_PATH="\(.*\)"$/\1/p' "${files[@]}" | tail -1
}

validate_base_path() {
  if [[ "$1" != /* || "$1" == */../* || "$1" == */.. ]]; then
    printf 'The base path must be an absolute path without "..": %s\n' "$1"
    exit 1
  fi
}

load_config() {
  local base
  base="$(configured_base_path)"
  validate_base_path "$base"
  STACKS_DIR="${base%/}/stacks"
  mkdir -p "$LOG_DIR" "$STACKS_DIR"
}

syslog_priority() {
  case "$1" in
    error) printf 'err' ;;
    warn) printf 'warning' ;;
    *) printf 'info' ;;
  esac
}

log() {
  local level="$1"
  shift
  printf '%s [%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$level" "$*" >> "$LOG_FILE"
  if command -v logger > /dev/null; then
    logger -t "$PLUGIN_NAME" -p "user.$(syslog_priority "$level")" -- "$*"
  fi
}

take_lock() {
  mkdir -p "$(dirname "$LOCK_FILE")"
  exec 9>> "$LOCK_FILE"
  if ! flock -n 9; then
    printf 'Another %s run is still in progress.\n' "$PLUGIN_NAME"
    exit 75
  fi
}

wait_for_docker() {
  local waited=0
  until docker info > /dev/null 2>&1; do
    if (( waited >= DOCKER_WAIT_SECONDS )); then
      return 1
    fi

    sleep 1
    waited=$((waited + 1))
  done
}

valid_stack_name() {
  [[ "$1" =~ ^[a-z0-9][a-z0-9_-]{0,63}$ ]]
}

is_stack_dir() {
  local dir="$1" name
  for name in "${COMPOSE_FILE_NAMES[@]}"; do
    if [[ -f "$dir/$name" ]]; then
      return 0
    fi
  done

  return 1
}

stacks() {
  [[ -d "$STACKS_DIR" ]] || return 0

  local dir name
  for dir in "$STACKS_DIR"/*/; do
    dir="${dir%/}"
    name="${dir##*/}"
    is_stack_dir "$dir" || continue
    if ! valid_stack_name "$name"; then
      log warn "Ignoring $name: a stack directory name may only use lowercase letters, digits," \
        "dashes and underscores"
      continue
    fi

    printf '%s\n' "$name"
  done
}

projects_started_from_stacks() {
  local format="{{.Label \"$COMPOSE_PROJECT_LABEL\"}}{{\"\\t\"}}"
  format+="{{.Label \"$COMPOSE_WORKING_DIR_LABEL\"}}"
  docker ps -a --filter "label=$COMPOSE_PROJECT_LABEL" --format "$format" |
    awk -F '\t' -v dir="$STACKS_DIR/" 'index($2, dir) == 1 { print $1 }' | sort -u
}

compose() {
  local stack="$1"
  shift
  local -a cmd=(docker compose --progress plain --project-directory "$STACKS_DIR/$stack"
    -p "$stack" "$@")
  if [[ -n "${COMPOSE_TIMEOUT_SECONDS:-}" ]]; then
    cmd=(timeout "$COMPOSE_TIMEOUT_SECONDS" "${cmd[@]}")
  fi
  "${cmd[@]}"
}

has_containers() {
  [[ -n "$(docker ps -aq --filter "label=$COMPOSE_PROJECT_LABEL=$1")" ]]
}

planned_changes() {
  compose "$1" up -d --dry-run --no-build --remove-orphans 2>&1
}

plan_changes_something() {
  grep -Eq '(^| )(Container|Network|Volume) [^ ]+ +(Recreate|Creating|Removing)( |$)' <<< "$1"
}

stack_drift() {
  local stack="$1" plan
  if ! has_containers "$stack"; then
    printf 'new\n'
    return 0
  fi

  local status=0
  plan="$(planned_changes "$stack")" || status=$?
  if (( status == 124 || status == 143 )); then
    printf 'broken compose did not answer within %ss\n' "$COMPOSE_TIMEOUT_SECONDS"
    return 0
  fi
  if (( status != 0 )); then
    printf 'broken %s\n' "$(tail -1 <<< "$plan")"
    return 0
  fi

  if plan_changes_something "$plan"; then
    printf 'changed\n'
    return 0
  fi

  printf 'insync\n'
}
