#!/bin/bash
set -euo pipefail
source "${BASH_SOURCE[0]%/*}/common.sh"

usage() {
  printf 'usage: apply.sh <stack>\n' >&2
  printf '       apply.sh <stack> --pull <service> [service...]\n' >&2
  printf '       apply.sh <stack> --start|--stop|--restart [service...]\n' >&2
  printf '       apply.sh <stack> --diff | --recreate\n' >&2
  exit 2
}

service_image_ids() {
  local stack="$1" service ids
  shift
  for service in "$@"; do
    mapfile -t ids < <(docker ps -aq --filter "label=$COMPOSE_PROJECT_LABEL=$stack" \
      --filter "label=$COMPOSE_SERVICE_LABEL=$service")
    if (( ${#ids[@]} > 0 )); then
      docker inspect --format '{{.Image}}' "${ids[@]}"
    fi
  done | sort -u
}

tidy_images() {
  local id
  for id in "$@"; do
    if [[ -n "$(docker ps -aq --filter "ancestor=$id")" ]]; then
      continue
    fi
    if docker image rm "$id" > /dev/null 2>&1; then
      printf 'Removed the old image %s\n' "${id#sha256:}"
    fi
  done
}

show_diff() {
  local stack="$1" drift detail plan
  read -r drift detail < <(stack_drift "$stack")
  case "$drift" in
    new) printf 'Nothing of %s is running yet. Sync stack would create:\n' "$stack" ;;
    changed) printf 'Sync stack would change %s in %s. The plan:\n' "$detail" "$stack" ;;
    broken) printf 'Compose cannot read %s: %s\n' "$stack" "$detail"; return 1 ;;
    *) printf '%s is in sync, sync stack would change nothing\n' "$stack"; return 0 ;;
  esac
  plan="$(planned_changes "$stack")"
  awk '$1 == "Container" || $1 == "Network" || $1 == "Volume"' <<< "$plan"
}

recreate_stack() {
  local stack="$1"
  printf 'Recreating every container of %s\n' "$stack"
  compose "$stack" up -d --force-recreate --remove-orphans
  log info "recreated $stack"
}

apply_stack() {
  local stack="$1" drift detail
  read -r drift detail < <(stack_drift "$stack")
  case "$drift" in
    new|changed)
      printf 'Applying %s%s\n' "$stack" "${detail:+ ($detail)}"
      compose "$stack" up -d --remove-orphans
      ;;
    broken)
      printf 'Compose cannot read %s: %s\n' "$stack" "$detail"
      return 1
      ;;
    *)
      printf '%s is in sync, nothing to do\n' "$stack"
      return 0
      ;;
  esac
  log info "applied $stack"
}

update_services() {
  local stack="$1"
  shift
  local -a old_images
  mapfile -t old_images < <(service_image_ids "$stack" "$@")
  printf 'Pulling %s\n' "$*"
  compose "$stack" pull "$@"
  printf 'Recreating %s\n' "$*"
  compose "$stack" up -d "$@"
  if (( ${#old_images[@]} > 0 )); then
    tidy_images "${old_images[@]}"
  fi
  log info "updated $stack: $*"
}

gerund() {
  case "$1" in
    stop) printf 'Stopping' ;;
    *) printf '%sing' "${1^}" ;;
  esac
}

run_on_services() {
  local verb="$1" stack="$2"
  shift 2
  printf '%s %s\n' "$(gerund "$verb")" "${*:-the whole stack}"
  compose "$stack" "$verb" "$@"
  log info "$verb $stack: ${*:-all}"
}

main() {
  load_config
  local stack="${1:-}" option="${2:-}"
  [[ -n "$stack" ]] || usage
  if ! valid_stack_name "$stack" || ! is_stack_dir "$STACKS_DIR/$stack"; then
    printf 'There is no stack called %s under %s\n' "$stack" "$STACKS_DIR"
    return 1
  fi
  if [[ "$option" == --pull ]] && (( $# < 3 )); then
    usage
  fi
  if [[ "$option" == --diff ]]; then
    show_diff "$stack"
    return
  fi

  take_lock
  case "$option" in
    "") apply_stack "$stack" ;;
    --pull) update_services "$stack" "${@:3}" ;;
    --start|--stop|--restart) run_on_services "${option#--}" "$stack" "${@:3}" ;;
    --recreate) recreate_stack "$stack" ;;
    *) usage ;;
  esac
  printf 'Done.\n'
}

main "$@"
