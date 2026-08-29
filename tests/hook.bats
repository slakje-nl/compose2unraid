setup() {
  load helpers
  setup_sandbox
}

teardown() {
  teardown_sandbox
}

@test "the hook brings every stack up in order without recreating anything" {
  make_stack beta
  make_stack alpha

  run "$SCRIPTS/hook.sh"

  [ "$status" -eq 0 ]
  [ "$(docker_calls)" = $'info\n'"compose --progress plain --project-directory $STACKS/alpha -p alpha up -d --no-recreate"$'\n'"compose --progress plain --project-directory $STACKS/beta -p beta up -d --no-recreate" ]
  plugin_log | grep -q 'every stack is up'
}

@test "a stack that fails does not stop the others and the hook reports the failure" {
  make_stack alpha
  make_stack beta
  export FAKE_DOCKER_FAIL_STACKS="alpha"

  run "$SCRIPTS/hook.sh"

  [ "$status" -eq 1 ]
  docker_calls | grep -q -- '-p beta up'
  plugin_log | grep -q 'alpha could not be brought up'
  plugin_log | grep -q 'compose up failed for alpha'
  plugin_log | grep -q 'beta is up'
  ! plugin_log | grep -q 'compose up ok for beta'
  plugin_log | grep -q '1 stack(s) did not come up'
}

@test "the hook does nothing when there are no stacks on disk" {
  run "$SCRIPTS/hook.sh"

  [ "$status" -eq 0 ]
  [ ! -s "$FAKE_DOCKER_LOG" ]
  plugin_log | grep -q 'nothing to bring up'
}

@test "the hook waits for docker to answer before touching a stack" {
  make_stack alpha
  export FAKE_DOCKER_INFO_FAILURES=2

  run "$SCRIPTS/hook.sh"

  [ "$status" -eq 0 ]
  [ "$(docker_calls)" = $'info\ninfo\ninfo\n'"compose --progress plain --project-directory $STACKS/alpha -p alpha up -d --no-recreate" ]
}

@test "the hook refuses to run while another action holds the lock" {
  make_stack alpha
  hold_lock

  run "$SCRIPTS/hook.sh"

  [ "$status" -eq 75 ]
  [ "$(docker_calls)" = "info" ]
  [[ "$output" == *"still in progress"* ]]
}
