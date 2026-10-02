#!/usr/bin/env bash
# cirrus.sh on|build|off|status — the standby's search backend switch.
#
# WHY THERE IS A SWITCH AT ALL. Netcup answers search with CirrusSearch; without
# it MediaWiki falls back to MySQL full-text, which on this corpus finds 1-3% of
# what Cirrus finds and ranks the wrong page first (measured 2026-10-02: "Ostrom"
# 15 hits here against 637 there; "platform cooperative" 11 against 1034, and the
# top hit is not "Platform Cooperativism"). So parity genuinely needs Cirrus.
#
# But this standby serves no traffic -- 24 hours of its access log contains
# nothing except local probes -- and an idle Elasticsearch would hold ~1GB on a
# host already running 244 containers. So Elasticsearch is NOT part of the
# default stack and is not allowed to restart on its own; the extensions sit on
# disk unloaded, and the index volume survives `off`. A promotion is then a
# start, not a 45k-page rebuild.
#
#   ./cirrus.sh status   what is on, what the wiki says is answering, a sample count
#   ./cirrus.sh on       start Elasticsearch and switch the wiki to CirrusSearch
#   ./cirrus.sh build    build the index (slow: tens of minutes; run under nohup)
#   ./cirrus.sh off      back to MySQL full-text, Elasticsearch container removed
#
# `on` is safe to re-run. `off` keeps the index volume on purpose.
set -uo pipefail
cd "$(dirname "$0")"

WIKI=p2pwiki-standby
ES=p2pwiki-standby-elasticsearch
MARKER=wiki-state/cirrus-enabled
DC=(docker compose -f docker-compose.yml -f docker-compose.cirrus.yml)
MAINT=/var/www/html/extensions/CirrusSearch/maintenance

api() {
	docker exec "$WIKI" curl -s -G --max-time 25 \
		--data-urlencode "format=json" --data-urlencode "formatversion=2" "$@" \
		http://localhost/api.php 2>/dev/null
}

# What the wiki itself says is loaded -- not what this script hopes is loaded.
backend() {
	api --data-urlencode "action=query" --data-urlencode "meta=siteinfo" \
	    --data-urlencode "siprop=extensions" \
	| python3 -c 'import sys,json
try:
    names = {e["name"] for e in json.load(sys.stdin)["query"]["extensions"]}
except Exception:
    print("unknown"); raise SystemExit
print("cirrus" if "CirrusSearch" in names else "mysql")' 2>/dev/null
}

hits() {
	api --data-urlencode "action=query" --data-urlencode "list=search" \
	    --data-urlencode "srsearch=${1:-Ostrom}" --data-urlencode "srlimit=1" \
	| python3 -c 'import sys,json
try:
    d = json.load(sys.stdin)["query"]
    print("%s hits, top=%s" % (d["searchinfo"]["totalhits"],
                               (d["search"] or [{"title":"-"}])[0]["title"][:40]))
except Exception as exc:
    print("query failed (%s)" % exc.__class__.__name__)' 2>/dev/null
}

es_running() { [ -n "$(docker ps -q -f "name=^${ES}$")" ]; }
es_healthy() { docker exec "$ES" curl -sf --max-time 5 http://localhost:9200/_cluster/health >/dev/null 2>&1; }
index_exists() {
	docker exec "$ES" curl -sf --max-time 10 "http://localhost:9200/_cat/indices/p2pwiki*?h=index" 2>/dev/null \
	| grep -q "p2pwiki"
}

wait_for() {    # wait_for <seconds> <command...>
	local limit=$1; shift
	local waited=0
	until "$@"; do
		waited=$((waited + 5))
		[ "$waited" -ge "$limit" ] && return 1
		sleep 5
	done
	return 0
}

# ForceSearchIndex does NOT write to Elasticsearch. It enqueues
# cirrusSearchElasticaWrite jobs and reports "Indexed N pages at X/second" --
# which is pages PROCESSED, not documents WRITTEN. With $wgJobRunRate at 0 and
# nothing running the queue, that message is a report of success for work that
# never happened: measured 2026-10-02, 378 pages "indexed", 5860 jobs queued,
# and Elasticsearch's own index_total counter still exactly 0.
#
# So the queue has to be drained explicitly, and the result has to be checked
# against Elasticsearch rather than against the indexer's own opinion.
jobs_left() {
	docker exec "$WIKI" php /var/www/html/maintenance/showJobs.php 2>/dev/null | tr -cd '0-9'
}

drain_jobs() {
	local left iter=0
	while :; do
		left=$(jobs_left)
		if [ -z "$left" ]; then
			echo "   FAILED: could not read the job queue" >&2
			return 1
		fi
		if [ "$left" = "0" ]; then
			echo "   queue empty"
			return 0
		fi
		iter=$((iter + 1))
		if [ "$iter" -gt 400 ]; then
			echo "   FAILED: gave up with $left jobs still queued" >&2
			return 1
		fi
		echo "   $left jobs left (pass $iter)"
		# -d memory_limit=640M is the difference between a drain that takes ten
		# minutes and one that takes an hour. runJobs.php stops itself at 95% of
		# PHP's limit ("Detected excessive memory usage"), and the image's 150M
		# default makes it quit roughly every 500 jobs. The container's ceiling is
		# 1G, so 640M leaves room and still cannot OOM the container.
		docker exec "$WIKI" php -d memory_limit=640M \
			/var/www/html/maintenance/runJobs.php \
			--type cirrusSearchElasticaWrite --maxjobs 30000 >/dev/null 2>&1 || true
	done
}

# A built index means documents in Elasticsearch, a drained queue, and a query
# that answers like production. Anything less is reported as a failure.
verify_index() {
	local docs jobs sample rc=0
	docs=$(docker exec "$ES" curl -s --max-time 15 \
		"http://localhost:9200/p2pwiki_content/_count" 2>/dev/null \
		| python3 -c 'import sys,json
try: print(json.load(sys.stdin)["count"])
except Exception: print(0)' 2>/dev/null)
	jobs=$(jobs_left)
	sample=$(hits Ostrom)
	echo "   documents in p2pwiki_content : ${docs:-?}"
	echo "   jobs still queued            : ${jobs:-?}"
	echo "   sample 'Ostrom'              : $sample"
	[ "${docs:-0}" -lt 30000 ] 2>/dev/null && { echo "   FAILED: fewer documents than this corpus has pages" >&2; rc=1; }
	[ "${jobs:-1}" != "0" ] && { echo "   FAILED: the write queue is not empty" >&2; rc=1; }
	case "$sample" in *"query failed"*|"0 hits"*) echo "   FAILED: search returned nothing" >&2; rc=1 ;; esac
	return $rc
}

case "${1:-status}" in

status)
	echo "marker          : $([ -f "$MARKER" ] && echo present || echo absent)"
	echo "elasticsearch   : $(es_running && echo running || echo "not running")$(es_running && { es_healthy && echo " (healthy)" || echo " (not answering yet)"; })"
	if es_running; then
		echo "index           : $(index_exists && echo present || echo missing)"
		echo "jobs queued     : $(jobs_left)"
	fi
	echo "wiki says       : $(backend)"
	echo "sample 'Ostrom' : $(hits Ostrom)"
	;;

on)
	echo "== starting elasticsearch =="
	"${DC[@]}" up -d "$ES" 2>&1 | tail -2
	if ! wait_for 180 es_healthy; then
		echo "FAILED: elasticsearch did not answer within 180s; leaving the wiki on MySQL" >&2
		exit 1
	fi
	echo "   healthy"
	echo "== switching the wiki to CirrusSearch =="
	: > "$MARKER"
	if ! wait_for 90 test cirrus = "$(backend)"; then
		# PHP's stat cache can hold a missing file briefly; a graceful reload clears it.
		docker exec "$WIKI" apache2ctl graceful >/dev/null 2>&1
		if ! wait_for 60 test cirrus = "$(backend)"; then
			echo "FAILED: the wiki still reports $(backend) after the marker was created" >&2
			exit 1
		fi
	fi
	echo "   wiki reports: $(backend)"
	if index_exists; then
		echo "   index present; search: $(hits Ostrom)"
	else
		echo "   index MISSING -- run './cirrus.sh build' (tens of minutes; use nohup)"
	fi
	;;

build)
	es_running || { echo "elasticsearch is not running; run './cirrus.sh on' first" >&2; exit 1; }
	es_healthy || { echo "elasticsearch is not answering" >&2; exit 1; }
	log=cirrus-build.$(date +%Y%m%dT%H%M%S).log
	echo "full output -> $log"
	echo "== 1/5 index configuration =="
	docker exec "$WIKI" php "$MAINT/UpdateSearchIndexConfig.php" >> "$log" 2>&1 \
		&& echo "   done" || { echo "   FAILED (see $log)" >&2; exit 1; }
	echo "== 2/5 pages, without link counts (~150/second) =="
	docker exec "$WIKI" php "$MAINT/ForceSearchIndex.php" --skipLinks --indexOnSkip >> "$log" 2>&1
	echo "   $(grep -c "^\[" "$log" 2>/dev/null) progress lines; $(tail -1 "$log" | cut -c1-70)"
	echo "== 3/5 draining the write queue =="
	drain_jobs || exit 1
	echo "== 4/5 link counts =="
	docker exec "$WIKI" php "$MAINT/ForceSearchIndex.php" --skipParse >> "$log" 2>&1
	echo "== 5/5 draining again =="
	drain_jobs || exit 1
	docker exec "$ES" curl -s -XPOST --max-time 30 "http://localhost:9200/p2pwiki*/_refresh" >/dev/null 2>&1
	echo "== verify =="
	verify_index
	;;

off)
	echo "== switching the wiki back to MySQL full-text =="
	rm -f "$MARKER"
	if ! wait_for 90 test mysql = "$(backend)"; then
		docker exec "$WIKI" apache2ctl graceful >/dev/null 2>&1
		wait_for 60 test mysql = "$(backend)" || echo "WARNING: the wiki still reports $(backend)" >&2
	fi
	echo "   wiki reports: $(backend)"
	echo "== removing the elasticsearch container (the index volume stays) =="
	"${DC[@]}" stop "$ES" 2>&1 | tail -1
	"${DC[@]}" rm -f "$ES" 2>&1 | tail -1
	es_running && echo "WARNING: $ES is still running" >&2
	echo "   elasticsearch: $(es_running && echo "STILL RUNNING" || echo "gone")"
	echo "   search still works: $(hits Ostrom)"
	;;

*)
	echo "usage: $0 {status|on|build|off}" >&2
	exit 2
	;;
esac
