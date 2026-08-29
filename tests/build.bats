setup() {
  load helpers
  ROOT="$BATS_TEST_DIRNAME/.."
  OUT_DIR="$(mktemp -d)"
  export PLG_OUT="$OUT_DIR/compose2unraid.plg"
}

teardown() {
  rm -rf "$OUT_DIR"
}

@test "the build inlines every source file with its install path and stamps the version" {
  run "$ROOT/tools/build-plg.sh" 2026.01.02

  [ "$status" -eq 0 ]
  grep -q '<!ENTITY version "2026.01.02">' "$PLG_OUT"
  grep -q '<FILE Name="/usr/local/emhttp/plugins/compose2unraid/scripts/common.sh" Mode="0755">' "$PLG_OUT"
  grep -q '<FILE Name="/usr/local/emhttp/plugins/compose2unraid/default.cfg" Mode="0644">' "$PLG_OUT"
  grep -q '<INLINE><!\[CDATA\[#!/bin/bash' "$PLG_OUT"
  [ "$(grep -c '<FILE Name="/usr/local/emhttp' "$PLG_OUT")" -eq "$(find "$ROOT/src" -type f | wc -l)" ]
  grep -q 'source "&plugdir;/scripts/common.sh"' "$PLG_OUT"
  grep -q 'mkdir -p "$base/stacks"' "$PLG_OUT"
  grep -q '> /etc/logrotate.d/&name;$' "$PLG_OUT"
  grep -q '^rm -f &composeTarget; /etc/logrotate.d/&name;$' "$PLG_OUT"
}

@test "a build without a version gets a dated dev version" {
  run "$ROOT/tools/build-plg.sh"

  [ "$status" -eq 0 ]
  grep -Eq '<!ENTITY version "[0-9]{4}\.[0-9]{2}\.[0-9]{2}\.dev[0-9]{4}">' "$PLG_OUT"
}
