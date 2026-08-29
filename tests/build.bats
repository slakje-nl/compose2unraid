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
  grep -q ' min="7.2.3" ' "$PLG_OUT"
  grep -q '<!ENTITY launch "Compose">' "$PLG_OUT"
  grep -q '<FILE Name="/usr/local/emhttp/plugins/compose2unraid/Compose.page" Mode="0644">' "$PLG_OUT"
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

@test "a build without release notes points the changelog at the releases page" {
  run "$ROOT/tools/build-plg.sh" 2026.01.02

  [ "$status" -eq 0 ]
  grep -q '^- Release notes live at https://github.com/slakje-nl/compose2unraid/releases$' \
    "$PLG_OUT"
}

@test "release notes land in the changelog with the characters XML cannot carry escaped" {
  printf '## What is new\n* feat(ui): a <b>bold</b> badge & more in https://x/pull/9\n' \
    > "$OUT_DIR/notes.md"

  PLG_CHANGES="$OUT_DIR/notes.md" run "$ROOT/tools/build-plg.sh" 2026.01.02

  [ "$status" -eq 0 ]
  grep -q '^###&version;$' "$PLG_OUT"
  grep -q '^## What is new$' "$PLG_OUT"
  grep -q '^\* feat(ui): a &lt;b&gt;bold&lt;/b&gt; badge &amp; more in https://x/pull/9$' "$PLG_OUT"
  ! grep -q 'Release notes live at' "$PLG_OUT"
}
