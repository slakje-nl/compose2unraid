setup() {
  load helpers
  setup_sandbox
}

teardown() {
  teardown_sandbox
}

@test "config takes the default and lets a quoted BASE_PATH on flash override it" {
  write_cfg OTHER="x" BASE_PATH="$BASE/"

  run bash -c "source '$SCRIPTS/common.sh'; load_config; printf '%s\n' \"\$STACKS_DIR\""

  [ "$status" -eq 0 ]
  [ "$output" = "$BASE/stacks" ]
}

@test "config ignores an unquoted BASE_PATH and keeps the default" {
  printf 'BASE_PATH=%s\n' "$BASE" > "$COMPOSE2UNRAID_FLASH_DIR/compose2unraid.cfg"

  run bash -c "source '$SCRIPTS/common.sh'; load_config; printf '%s\n' \"\$STACKS_DIR\""

  [ "$status" -eq 0 ]
  [ "$output" = "/mnt/user/appdata/compose2unraid/stacks" ]
}

@test "config creates the stacks directory so the first sync has somewhere to land" {
  rm -r "$STACKS"

  run bash -c "source '$SCRIPTS/common.sh'; load_config; stacks"

  [ "$status" -eq 0 ]
  [ -d "$STACKS" ]
  [ "$output" = "" ]
}

@test "config works without a flash file at all" {
  rm "$COMPOSE2UNRAID_FLASH_DIR/compose2unraid.cfg"

  run bash -c "source '$SCRIPTS/common.sh'; load_config; printf '%s\n' \"\$STACKS_DIR\""

  [ "$status" -eq 0 ]
  [ "$output" = "/mnt/user/appdata/compose2unraid/stacks" ]
}

@test "config refuses a relative base path" {
  for bad in 'BASE_PATH=relative/path' 'BASE_PATH=/mnt/../etc'; do
    write_cfg BASE_PATH="$BASE" "$bad"
    run bash -c "source '$SCRIPTS/common.sh'; load_config"
    [ "$status" -eq 1 ]
    [[ "$output" == *"${bad#*=}"* ]]
  done
}

@test "stack names are lowercase, digits, dashes and underscores, up to 64 characters" {
  run bash -c "source '$SCRIPTS/common.sh'; for n in ok a1 my-stack my_stack $(printf 'a%.0s' {1..64}); do valid_stack_name \"\$n\" || exit 1; done; for n in Upper -lead 'sp ace' 'a;b' ../x $(printf 'a%.0s' {1..65}); do valid_stack_name \"\$n\" && exit 1; done; echo fine"

  [ "$status" -eq 0 ]
  [ "$output" = "fine" ]
}

@test "stacks lists directories with a compose file, sorted, skipping the rest" {
  make_stack beta
  make_stack alpha 1 docker-compose.yml
  make_stack gamma 1 compose.yml
  mkdir -p "$STACKS/no-compose-here" "$STACKS/Bad Name"
  printf 'services: {}\n' > "$STACKS/Bad Name/compose.yaml"

  run bash -c "source '$SCRIPTS/common.sh'; load_config; stacks"

  [ "$status" -eq 0 ]
  [ "$output" = $'alpha\nbeta\ngamma' ]
  plugin_log | grep -q 'Ignoring Bad Name'
}

@test "compose always passes the project directory and the project name" {
  make_stack alpha

  run bash -c "source '$SCRIPTS/common.sh'; load_config; compose alpha config -q"

  [ "$status" -eq 0 ]
  [ "$(docker_calls)" = "compose --progress plain --project-directory $STACKS/alpha -p alpha config -q" ]
}

@test "drift is what a compose dry run would create, recreate or remove" {
  make_stack fresh
  make_stack same
  add_running same
  make_stack edited
  add_running edited app oldhash
  make_stack grown
  add_running grown app
  printf '  extra:\n    image: example/extra:1\n' >> "$STACKS/grown/compose.yaml"
  make_stack shrunk
  add_running shrunk app
  add_running shrunk old "$(stack_hash shrunk)"
  make_stack named
  printf '    container_name: mydb\n' >> "$STACKS/named/compose.yaml"
  add_running named app oldhash
  make_stack broken
  add_running broken
  export FAKE_DOCKER_FAIL_STACKS=broken

  run bash -c "source '$SCRIPTS/common.sh'; load_config; for s in fresh same edited grown shrunk named broken; do printf '%s: ' \$s; stack_drift \$s; done"

  [ "$status" -eq 0 ]
  [ "$output" = $'fresh: new\nsame: insync\nedited: changed\ngrown: changed\nshrunk: changed\nnamed: changed\nbroken: broken compose up failed for broken' ]
  docker_calls | grep -q -- '-p same up -d --dry-run --no-build --pull never --remove-orphans$'
  ! docker_calls | grep -q -- '-p fresh up'
}

@test "an edited secrets file counts as a change, a touched one does not" {
  make_stack alpha
  add_running alpha
  printf 'KEY=new\n' > "$STACKS/alpha/.env"
  make_stack beta
  add_running beta
  touch "$STACKS/beta/.env"

  run bash -c "source '$SCRIPTS/common.sh'; load_config; stack_drift alpha; stack_drift beta"

  [ "$output" = $'changed\ninsync' ]
}

@test "the plan is read by its words, with or without the dry-run prefix compose 2.x prints" {
  pinned=$' Container alpha-app-1 Running \n Container alpha-db-1 Recreate \n Container alpha-db-1 Recreated '
  older=$'  DRY-RUN MODE -  Network alpha_default Creating \n  DRY-RUN MODE -  Container alpha-app-1 Creating '
  quiet=$' Container alpha-app-1 Running \n Container alpha-app-1 Started '

  run bash -c "source '$SCRIPTS/common.sh'; plan_changes_something \"\$1\" && echo pinned; plan_changes_something \"\$2\" && echo older; plan_changes_something \"\$3\" || echo quiet" _ "$pinned" "$older" "$quiet"

  [ "$status" -eq 0 ]
  [ "$output" = $'pinned\nolder\nquiet' ]
}
