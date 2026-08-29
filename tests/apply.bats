setup() {
  load helpers
  setup_sandbox
}

teardown() {
  teardown_sandbox
}

@test "apply brings up a new stack" {
  make_stack alpha

  run "$SCRIPTS/apply.sh" alpha

  [ "$status" -eq 0 ]
  docker_calls | grep -q "^compose --progress plain --project-directory $STACKS/alpha -p alpha up -d --remove-orphans$"
  [[ "$output" == *"Applying alpha"* ]]
  [[ "$output" == *"Done."* ]]
  plugin_log | grep -q 'applied alpha'
}

@test "apply runs compose up as it is and lets Compose decide what to change" {
  make_stack same
  add_running same
  make_stack edited
  add_running edited app oldhash

  run "$SCRIPTS/apply.sh" same
  [ "$status" -eq 0 ]
  [[ "$output" == *"Applying same"* ]]
  [ "$(docker_calls | grep -c -- '-p same up -d --remove-orphans$')" = "1" ]
  ! docker_calls | grep -q -- '--dry-run'

  run "$SCRIPTS/apply.sh" edited
  [ "$status" -eq 0 ]
  [[ "$output" == *"Applying edited"* ]]
  docker_calls | grep -q -- '-p edited up -d --remove-orphans$'
}

@test "apply refuses a stack that is not on disk" {
  make_stack alpha

  run "$SCRIPTS/apply.sh" nope
  [ "$status" -eq 1 ]
  [[ "$output" == *"There is no stack called nope"* ]]
  [ ! -s "$FAKE_DOCKER_LOG" ]
}

@test "apply starts a stack that has no .env" {
  make_stack alpha
  rm "$STACKS/alpha/.env"

  run "$SCRIPTS/apply.sh" alpha
  [ "$status" -eq 0 ]
  docker_calls | grep -q -- '-p alpha up -d --remove-orphans'
}

@test "apply reports a compose failure" {
  make_stack alpha
  export FAKE_DOCKER_FAIL_STACKS=alpha

  run "$SCRIPTS/apply.sh" alpha

  [ "$status" -eq 1 ]
  [[ "$output" == *"compose up failed for alpha"* ]]
  [[ "$output" != *"Done."* ]]
}

@test "update pulls and recreates only the named services, not what they depend on, and removes the images it replaced" {
  make_stack alpha
  printf '  db:\n    image: example/db:1\n' >> "$STACKS/alpha/compose.yaml"
  add_running alpha app "$(stack_hash alpha)" 2021-01-01T00:00:00Z example/alpha:1 sha256:a1
  add_running alpha db "$(stack_hash alpha)" 2021-01-01T00:00:00Z example/db:1 sha256:d1
  cp "$FAKE_DOCKER_CONTAINERS" "$SANDBOX/after"
  sed -i 's/sha256:a1/sha256:a2/' "$SANDBOX/after"
  export FAKE_DOCKER_CONTAINERS_AFTER_UP="$SANDBOX/after"

  run "$SCRIPTS/apply.sh" alpha --pull app

  [ "$status" -eq 0 ]
  docker_calls | grep -q -- '-p alpha pull app$'
  docker_calls | grep -q -- '-p alpha up -d --no-deps app$'
  ! docker_calls | grep -q 'pull db\|up -d db'
  [ "$(docker_calls | grep -c '^image rm')" = "1" ]
  docker_calls | grep -q '^image rm sha256:a1$'
  [[ "$output" == *"Removed the old image a1"* ]]
  plugin_log | grep -q 'updated alpha: app'
}

@test "update refuses a service the stack does not have" {
  make_stack alpha

  run "$SCRIPTS/apply.sh" alpha --pull web

  [ "$status" -eq 1 ]
  [[ "$output" == *"no such service: web"* ]]
  ! docker_calls | grep -q -- 'up -d'
  ! docker_calls | grep -q 'image rm'
}

@test "a failed pull recreates nothing and keeps every image" {
  make_stack alpha
  add_running alpha
  export FAKE_DOCKER_FAIL_PULL_STACKS=alpha

  run "$SCRIPTS/apply.sh" alpha --pull app

  [ "$status" -ne 0 ]
  [[ "$output" == *"pull access denied for alpha"* ]]
  ! docker_calls | grep -q -- 'up -d'
  ! docker_calls | grep -q 'image rm'
}

@test "a replaced image still used by another stack is kept" {
  make_stack alpha
  make_stack beta
  add_running alpha app "$(stack_hash alpha)" 2021-01-01T00:00:00Z example/shared:1 sha256:s1
  add_running beta app "$(stack_hash beta)" 2021-01-01T00:00:00Z example/shared:1 sha256:s1
  cp "$FAKE_DOCKER_CONTAINERS" "$SANDBOX/after"
  sed -i '1s/sha256:s1/sha256:s2/' "$SANDBOX/after"
  export FAKE_DOCKER_CONTAINERS_AFTER_UP="$SANDBOX/after"

  run "$SCRIPTS/apply.sh" alpha --pull app

  [ "$status" -eq 0 ]
  ! docker_calls | grep -q 'image rm'
}

@test "apply refuses to run while the hook holds the lock" {
  make_stack alpha
  hold_lock

  run "$SCRIPTS/apply.sh" alpha

  [ "$status" -eq 75 ]
  [ ! -s "$FAKE_DOCKER_LOG" ]
}

@test "start, stop and restart pass only the named services to compose" {
  make_stack alpha
  printf '  db:\n    image: example/db:1\n' >> "$STACKS/alpha/compose.yaml"
  add_running alpha

  run "$SCRIPTS/apply.sh" alpha --restart app
  [ "$status" -eq 0 ]
  [[ "$output" == *"Restarting app"* ]]
  [[ "$output" == *"Done."* ]]
  docker_calls | grep -q -- '-p alpha restart --no-deps app$'

  run "$SCRIPTS/apply.sh" alpha --stop app db
  [ "$status" -eq 0 ]
  [[ "$output" == *"Stopping app db"* ]]
  docker_calls | grep -q -- '-p alpha stop app db$'

  run "$SCRIPTS/apply.sh" alpha --start db
  [ "$status" -eq 0 ]
  [[ "$output" == *"Starting db"* ]]
  docker_calls | grep -q -- '-p alpha start db$'
  ! docker_calls | grep -q -- 'pull\|up -d'
  plugin_log | grep -q 'restart alpha: app'
}

@test "start, stop and restart without services act on the whole stack" {
  make_stack alpha
  add_running alpha

  run "$SCRIPTS/apply.sh" alpha --restart
  [ "$status" -eq 0 ]
  [[ "$output" == *"Restarting the whole stack"* ]]
  docker_calls | grep -q -- '-p alpha restart$'
  plugin_log | grep -q 'restart alpha: all'
}

@test "an update needs at least one service and the option must be known" {
  make_stack alpha

  run "$SCRIPTS/apply.sh" alpha --pull
  [ "$status" -eq 2 ]

  run "$SCRIPTS/apply.sh" alpha --down app
  [ "$status" -eq 2 ]
  [ ! -s "$FAKE_DOCKER_LOG" ]
}

@test "diff shows what sync stack would do and changes nothing" {
  make_stack same
  add_running same
  make_stack edited
  add_running edited app oldhash

  run "$SCRIPTS/apply.sh" same --diff
  [ "$status" -eq 0 ]
  [[ "$output" == *"same is in sync"* ]]

  run "$SCRIPTS/apply.sh" edited --diff
  [ "$status" -eq 0 ]
  [[ "$output" == *"Sync stack would change edited. The plan:"* ]]
  [[ "$output" == *"Container edited-app-1 Recreate"* ]]
  ! docker_calls | grep -v -- '--dry-run' | grep -q -- 'up -d'
  [ "$(docker_calls | grep -c -- '--dry-run')" = "2" ]

  make_stack fresh
  run "$SCRIPTS/apply.sh" fresh --diff
  [ "$status" -eq 0 ]
  [[ "$output" == *"Nothing of fresh is running yet. Sync stack would create:"* ]]
  [[ "$output" == *"Container fresh-app-1 Creating"* ]]
}

@test "recreate brings every container of the stack up again from its image" {
  make_stack alpha
  add_running alpha

  run "$SCRIPTS/apply.sh" alpha --recreate

  [ "$status" -eq 0 ]
  [[ "$output" == *"Recreating every container of alpha"* ]]
  docker_calls | grep -q -- '-p alpha up -d --force-recreate --remove-orphans$'
  ! docker_calls | grep -q pull
  plugin_log | grep -q 'recreated alpha'
}
