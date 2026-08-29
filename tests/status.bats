setup() {
  load helpers
  setup_sandbox
}

teardown() {
  teardown_sandbox
}

@test "status lists the stacks on disk with their drift, and no containers on a fresh box" {
  make_stack alpha
  make_stack beta

  run status_json

  [ "$status" -eq 0 ]
  [ "$(printf '%s' "$output" | jq -c '[.stacks[] | [.name, .drift]]')" = '[["alpha","new"],["beta","new"]]' ]
  [ "$(printf '%s' "$output" | jq -c '.stacks[0].defined')" = '[{"service":"app","name":"alpha-app-1","icon":""}]' ]
  [ "$(printf '%s' "$output" | jq -c '.containers')" = '[]' ]
  [ "$(printf '%s' "$output" | jq -r '.cpus > 0')" = 'true' ]
}

@test "status reports drift per stack and lists stacks that run but left the disk, sorted by name" {
  make_stack same
  add_running same
  make_stack edited
  add_running edited app oldhash
  add_running gone app x
  make_stack broken
  add_running broken
  export FAKE_DOCKER_FAIL_STACKS=broken

  run status_json

  [ "$status" -eq 0 ]
  [ "$(printf '%s' "$output" | jq -c '[.stacks[] | [.name, .drift, .error]]')" = '[["broken","broken","compose config failed for broken"],["edited","changed",null],["gone","gone",null],["same","insync",null]]' ]
  [ "$(printf '%s' "$output" | jq -r '.containers | length')" = "4" ]
  [ "$(printf '%s' "$output" | jq -r '.containers[0].stack')" = "same" ]
  [ "$(printf '%s' "$output" | jq -c '.containers[0].digests')" = '["sha256:same-1"]' ]
}

@test "a compose project started from outside the stacks directory is not the plugin's business" {
  make_stack alpha
  add_running alpha
  add_foreign other
  add_running gone app x

  run status_json

  [ "$status" -eq 0 ]
  [ "$(printf '%s' "$output" | jq -c '[.stacks[] | [.name, .drift]]')" = '[["alpha","insync"],["gone","gone"]]' ]
}

@test "status fails when docker fails, rather than showing a partial table" {
  make_stack alpha
  add_running alpha
  export FAKE_DOCKER_FAIL_STATS=1

  run status_json

  [ "$status" -ne 0 ]
  [[ "$output" == *"stats failed"* ]]
}

@test "status can skip the stats sample for a quick first page" {
  make_stack alpha
  add_running alpha

  run "$SCRIPTS/status.sh" --without-stats

  [ "$status" -eq 0 ]
  [ "$(printf '%s' "$output" | jq -c '.stats')" = '[]' ]
  [ "$(printf '%s' "$output" | jq -r '.containers | length')" = "1" ]
  ! docker_calls | grep -q '^stats'

  run status_json

  [ "$status" -eq 0 ]
  docker_calls | grep -q '^stats --no-stream'
}

@test "status lists the services on disk with their container name and icon" {
  make_stack alpha
  printf '    container_name: my-app\n    labels:\n      - net.unraid.docker.icon=https://example.com/a.png\n' \
    >> "$STACKS/alpha/compose.yaml"
  printf '  db:\n    image: example/db:1\n' >> "$STACKS/alpha/compose.yaml"

  run "$SCRIPTS/status.sh" --without-stats

  [ "$status" -eq 0 ]
  [ "$(printf '%s' "$output" | jq -c '.stacks[0].defined')" = '[{"service":"app","name":"my-app","icon":"https://example.com/a.png"},{"service":"db","name":"alpha-db-1","icon":""}]' ]
}

@test "a stack compose cannot read is broken even before it has containers" {
  make_stack broken
  add_running gone app x
  export FAKE_DOCKER_FAIL_STACKS=broken

  run status_json

  [ "$status" -eq 0 ]
  [ "$(printf '%s' "$output" | jq -c '[.stacks[] | [.drift, .error, .defined]]')" = '[["broken","compose config failed for broken",[]],["gone",null,[]]]' ]
}

@test "a stack whose compose file needs an .env that is not there says missing .env" {
  make_stack alpha
  printf '    env_file: .env\n' >> "$STACKS/alpha/compose.yaml"
  rm "$STACKS/alpha/.env"

  run status_json

  [ "$status" -eq 0 ]
  [ "$(printf '%s' "$output" | jq -c '.stacks[0] | [.drift, .error]')" = '["broken","missing .env"]' ]

  : > "$STACKS/alpha/.env"
  run status_json

  [ "$(printf '%s' "$output" | jq -r '.stacks[0].drift')" = "new" ]
}

@test "a compose that does not answer is reported instead of hanging the status" {
  make_stack slow
  add_running slow
  export FAKE_DOCKER_HANG_STACKS=slow COMPOSE_TIMEOUT_SECONDS=1

  run "$SCRIPTS/status.sh" --without-stats

  [ "$status" -eq 0 ]
  [ "$(printf '%s' "$output" | jq -c '.stacks[0] | [.drift, .error]')" = '["broken","compose did not answer within 1s"]' ]
}
