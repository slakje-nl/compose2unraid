#!/bin/bash
set -euo pipefail
source "${BASH_SOURCE[0]%/*}/common.sh"

WORK=""
WITH_DRIFT=1
COMPOSE_TIMEOUT_SECONDS="${COMPOSE_TIMEOUT_SECONDS:-60}"

stack_entry() {
  local stack="$1" drift="$2" detail="${3:-}" defined="${4:-[]}"
  jq -cn --arg name "$stack" --arg drift "$drift" --arg detail "$detail" \
    --argjson defined "$defined" \
    '{name: $name, drift: $drift, error: null, defined: $defined}
      + (if $drift == "broken" then {error: $detail} else {} end)'
}

defined_services() {
  jq -c --arg stack "$1" '(.services // {}) | to_entries | map({service: .key,
    name: (.value.container_name // ($stack + "-" + .key + "-1")),
    icon: (.value.labels["net.unraid.docker.icon"] // "")})'
}

config_problem() {
  local last
  last="$(tail -1 "$1")"
  if [[ "$last" == *"env file"*"not found"* ]]; then
    printf 'missing .env'
  else
    printf '%s' "$last"
  fi
}

checked_entry() {
  local stack="$1" drift detail config errors="$WORK/config-errors-$1"
  if ! config="$(compose "$stack" config --format json 2> "$errors")"; then
    stack_entry "$stack" broken "$(config_problem "$errors")"
    return 0
  fi

  read -r drift detail < <(stack_drift "$stack")
  stack_entry "$stack" "$drift" "${detail:-}" "$(defined_services "$stack" <<< "$config")"
}

remember_entry() {
  local stack="$1" fresh
  checked_entry "$stack" > "$WORK/stack-$stack"
  fresh="$(mktemp "$CACHE_DIR/$stack.XXXXXX")"
  cp "$WORK/stack-$stack" "$fresh"
  mv "$fresh" "$CACHE_DIR/$stack.json"
}

remembered_entry() {
  local stack="$1"
  if [[ -f "$CACHE_DIR/$stack.json" ]]; then
    cat "$CACHE_DIR/$stack.json"
  else
    stack_entry "$stack" unknown
  fi
}

forget_stacks_not_on_disk() {
  local file name
  for file in "$CACHE_DIR"/*.json; do
    [[ -f "$file" ]] || continue
    name="${file##*/}"
    name="${name%.json}"
    [[ " $* " == *" $name "* ]] || rm -f "$file"
  done
}

gone_entries() {
  local stack
  while IFS= read -r stack; do
    [[ -n "$stack" ]] || continue
    [[ " $* " == *" $stack "* ]] && continue
    valid_stack_name "$stack" || continue
    stack_entry "$stack" gone
  done < <(projects_started_from_stacks)
}

tolerating_vanished() {
  local errors="$WORK/errors-$1"
  shift
  "$@" 2> "$errors" || ! grep -qvE 'No such (object|container)' "$errors"
}

images_of() {
  local -a images
  mapfile -t images < <(jq -r '.[].Image' "$1" | sort -u)
  if (( ${#images[@]} == 0 )); then
    printf '[]'
    return 0
  fi

  tolerating_vanished image docker image inspect "${images[@]}"
}

containers() {
  local -a ids
  mapfile -t ids < <(docker ps -aq --filter "label=$COMPOSE_PROJECT_LABEL")
  if (( ${#ids[@]} == 0 )); then
    printf '[]'
    return 0
  fi

  tolerating_vanished inspect docker inspect "${ids[@]}" > "$WORK/inspect"
  images_of "$WORK/inspect" > "$WORK/images"
  jq --slurpfile images "$WORK/images" '
    ($images[0] | map({key: .Id, value: [.RepoDigests[] | split("@")[1]]}) | from_entries)
      as $digests
    | map({id: .Id, name: .Name,
        stack: .Config.Labels["com.docker.compose.project"],
        service: .Config.Labels["com.docker.compose.service"],
        state: .State.Status, health: (.State.Health.Status // ""),
        image: .Config.Image, image_id: .Image,
        started: .State.StartedAt, created: .Created, labels: .Config.Labels,
        digests: ($digests[.Image] // [])})' "$WORK/inspect"
}

stats() {
  local -a ids
  mapfile -t ids < <(docker ps -q --filter "label=$COMPOSE_PROJECT_LABEL")
  (( ${#ids[@]} > 0 )) || return 0

  tolerating_vanished stats docker stats --no-stream --format '{{json .}}' "${ids[@]}"
}

sample_stats() {
  if ! stats > "$WORK/stats"; then
    : > "$WORK/stats"
  fi
}

collected_entries() {
  local stack
  for stack in "$@"; do
    cat "$WORK/stack-$stack"
  done
  cat "$WORK/gone"
}

collect_in_parallel() {
  local -a pids
  local stack pid
  containers > "$WORK/containers" & pids+=($!)
  sample_stats & pids+=($!)
  gone_entries "$@" > "$WORK/gone" & pids+=($!)
  for stack in "$@"; do
    if (( WITH_DRIFT )); then
      remember_entry "$stack" & pids+=($!)
    else
      remembered_entry "$stack" > "$WORK/stack-$stack" & pids+=($!)
    fi
  done
  for pid in "${pids[@]}"; do
    wait "$pid"
  done
}

main() {
  load_config
  if [[ "${1:-}" == --without-drift ]]; then
    WITH_DRIFT=0
  fi
  local -a names
  mapfile -t names < <(stacks)
  WORK="$(mktemp -d)"
  trap 'rm -rf "$WORK"' EXIT
  if (( WITH_DRIFT )); then
    forget_stacks_not_on_disk "${names[@]}"
  fi
  collect_in_parallel "${names[@]}"
  jq -cn --slurpfile stacks <(collected_entries "${names[@]}") \
    --slurpfile containers "$WORK/containers" --slurpfile stats "$WORK/stats" \
    --argjson cpus "$(nproc)" \
    '{stacks: ($stacks | sort_by(.name)), containers: $containers[0], stats: $stats,
      cpus: $cpus}'
}

main "$@"
