#!/usr/bin/env bash
# Run on the actual Linux daemon host, including the separate AxiomPress-Linux WSL distro.
set -euo pipefail
cd "$(dirname "$0")/../../.."
runtime=runsc
if [[ "${1:-}" == --development-runc ]]; then runtime=runc; shift; fi
if [[ $# != 0 ]]; then echo 'Use run-checks.sh [--development-runc]' >&2; exit 2; fi
docker info --format '{{json .Runtimes}}' | python3 -c \
    'import json,sys; assert sys.argv[1] in json.load(sys.stdin), "Required runtime absent; no fallback"' "$runtime"
for target in worker-sdk worker supervisor; do
    docker build --target "$target" -f tools/axiompress/supervisor/Dockerfile \
        -t "axiompress-$target:phase2" tools/axiompress
done
docker tag axiompress-worker-sdk:phase2 axiompress-worker-sdk:prototype
sdk=$(docker image inspect axiompress-worker-sdk:phase2 --format '{{.Id}}')
worker=$(docker image inspect axiompress-worker:phase2 --format '{{.Id}}')
image=$(docker image inspect axiompress-supervisor:phase2 --format '{{.Id}}')
mkdir -p docs .cache/axiompress-service
result=$(mktemp .cache/axiompress-service/result-XXXXXX)
trap 'rm -f -- "$result"' EXIT
docker run --rm --network=none --read-only \
    --tmpfs=/tmp:rw,size=64m --tmpfs=/policy:rw,size=16m --tmpfs=/state:rw,size=16m --tmpfs=/channel:rw,size=1m \
    --mount type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock \
    --mount "type=bind,source=$PWD/src/axiompress,target=/core,readonly" \
    --env "AXIOM_SDK_IMAGE=$sdk" --env "AXIOM_TEST_WORKER=$worker" --env "AXIOM_TEST_RUNTIME=$runtime" \
    --entrypoint python3 "$image" /app/service/test.py > "$result"
python3 -c 'import json,sys; r=json.load(open(sys.argv[1])); assert r["outcome"]=="passed"; print("PASS:",len(r["checks"]),"service checks; runtime="+r["runtime"],"p95_ms=",round(r["cold_p95_ms"],2))' "$result"
cp -- "$result" "docs/axiompress-phase2-service-$runtime-last.json"
