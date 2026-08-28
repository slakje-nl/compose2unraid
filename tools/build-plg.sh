#!/bin/bash
set -euo pipefail

COMPOSE_VERSION=v5.5.0
COMPOSE_SHA256=c57ab918abd5b05ca7e7d0f275875dd1330a695074f309dc9eab1b49efafcd4b

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEMPLATE="$ROOT/plg/compose2unraid.plg.in"
OUT="${PLG_OUT:-$ROOT/dist/compose2unraid.plg}"
INSTALL_DIR=/usr/local/emhttp/plugins/compose2unraid

version() {
  if [[ -n "$1" ]]; then
    printf '%s' "$1"
  else
    date +%Y.%m.%d.dev%H%M
  fi
}

file_mode() {
  case "$1" in
    scripts/*|event/*) printf '0755' ;;
    *) printf '0644' ;;
  esac
}

file_entry() {
  local rel="$1" path="$ROOT/src/$1"
  if grep -q ']]>' "$path"; then
    printf '%s contains "]]>" and cannot be inlined into the plugin file\n' "$rel" >&2
    return 1
  fi

  printf '<FILE Name="%s/%s" Mode="%s">\n<INLINE><![CDATA[' "$INSTALL_DIR" "$rel" \
    "$(file_mode "$rel")"
  cat "$path"
  printf ']]></INLINE>\n</FILE>\n\n'
}

source_files() {
  (cd "$ROOT/src" && find . -type f | sed 's#^\./##' | LC_ALL=C sort)
}

file_entries() {
  local rel
  while IFS= read -r rel; do
    file_entry "$rel"
  done < <(source_files)
}

render() {
  local v="$1" line
  while IFS= read -r line; do
    if [[ "$line" == "@FILES@" ]]; then
      file_entries
      continue
    fi

    line="${line//@VERSION@/$v}"
    line="${line//@COMPOSE_VERSION@/$COMPOSE_VERSION}"
    line="${line//@COMPOSE_SHA256@/$COMPOSE_SHA256}"
    printf '%s\n' "$line"
  done < "$TEMPLATE"
}

main() {
  local v
  v="$(version "${1:-}")"
  mkdir -p "$(dirname "$OUT")"
  render "$v" > "$OUT"
  printf 'built %s (version %s, %s source files)\n' "$OUT" "$v" \
    "$(source_files | wc -l | tr -d ' ')"
}

main "$@"
