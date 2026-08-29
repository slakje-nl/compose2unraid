#!/bin/bash
set -euo pipefail
source "${BASH_SOURCE[0]%/*}/common.sh"

bring_up() {
  local stack="$1" status=0 output
  output="$(compose "$stack" up -d --no-recreate 2>&1)" || status=$?
  if (( status == 0 )); then
    log info "$stack is up"
    return 0
  fi

  log error "$stack could not be brought up: $(tail -1 <<< "$output")"
  printf '%s\n' "$output" >> "$LOG_FILE"
  return 1
}

main() {
  load_config
  local -a names
  mapfile -t names < <(stacks)
  if (( ${#names[@]} == 0 )); then
    log warn "There are no stacks under $STACKS_DIR, so there is nothing to bring up"
    return 0
  fi

  if ! wait_for_docker; then
    log error "Docker did not become ready within $DOCKER_WAIT_SECONDS seconds," \
      "no stack was brought up"
    return 1
  fi

  take_lock
  local failed=0 stack
  for stack in "${names[@]}"; do
    bring_up "$stack" || failed=$((failed + 1))
  done

  if (( failed > 0 )); then
    log error "$failed stack(s) did not come up"
    return 1
  fi

  log info "every stack is up"
}

main "$@"
