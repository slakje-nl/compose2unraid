#!/bin/sh
if [ "${1:-}" = --without-stats ]; then
  stats='[]'
else
  stats='[{"ID":"aaaaaaaaaaaa","CPUPerc":"1.50%","MemUsage":"31.2MiB / 125.7GiB","MemPerc":"0.02%"}]'
fi
sed "s#STATS#$stats#" <<'JSON'
{"stacks":[
  {"name":"alpha","drift":"changed","services":["app"],"error":null,"defined":[{"service":"app","name":"alpha-app-1","icon":""},{"service":"db","name":"alpha-db-1","icon":""}]},
  {"name":"beta","drift":"changed","services":["old"],"error":null,"defined":[{"service":"app","name":"beta-app-1","icon":""}]},
  {"name":"gamma","drift":"new","services":[],"error":null,"defined":[{"service":"web","name":"gamma-web-1","icon":"https://example.com/web.png"},{"service":"cache","name":"my-cache","icon":""}]},
  {"name":"gone","drift":"gone","services":[],"error":null,"defined":[]},
  {"name":"torn","drift":"broken","services":[],"error":"yaml: line 3: did not find expected key","defined":[]},
  {"name":"noenv","drift":"broken","services":[],"error":"missing .env","defined":[]}
],
"containers":[
  {"id":"aaaaaaaaaaaa","name":"/alpha-app-1","stack":"alpha","service":"app","state":"running","health":"healthy","image":"example/alpha:1.2","image_id":"sha256:a1","started":"2026-01-01T00:00:00Z","created":"2026-01-01T00:00:00Z","labels":{},"digests":["sha256:old"]},
  {"id":"dddddddddddd","name":"/alpha-db-1","stack":"alpha","service":"db","state":"running","health":"","image":"example/alpha:1.2","image_id":"sha256:a2","started":"2026-01-01T00:00:00Z","created":"2026-01-01T00:00:00Z","labels":{},"digests":["sha256:new"]},
  {"id":"bbbbbbbbbbbb","name":"/beta-app-1","stack":"beta","service":"app","state":"running","health":"","image":"example/beta","image_id":"sha256:b1","started":"2026-01-01T00:00:00Z","created":"2026-01-01T00:00:00Z","labels":{},"digests":[]},
  {"id":"eeeeeeeeeeee","name":"/beta-old-1","stack":"beta","service":"old","state":"running","health":"","image":"example/old","image_id":"sha256:o1","started":"2026-01-01T00:00:00Z","created":"2026-01-01T00:00:00Z","labels":{},"digests":[]},
  {"id":"ffffffffffff","name":"/torn-app-1","stack":"torn","service":"app","state":"running","health":"","image":"example/torn","image_id":"sha256:t1","started":"2026-01-01T00:00:00Z","created":"2026-01-01T00:00:00Z","labels":{},"digests":[]},
  {"id":"cccccccccccc","name":"/gone-app-1","stack":"gone","service":"app","state":"exited","health":"","image":"example/gone@sha256:0123456789abcdef","image_id":"sha256:g1","started":"2026-01-01T00:00:00Z","created":"2026-01-01T00:00:00Z","labels":{},"digests":[]}
],
"stats":STATS,"cpus":8}
JSON
