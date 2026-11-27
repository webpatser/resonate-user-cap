#!/usr/bin/env bash
#
# Wait until a grokzen/redis-cluster container (3 masters, 1 replica each,
# ports 17000-17005) has settled: every node agrees on the final topology.
#
# cluster_state:ok alone is not enough. Right after start a replica that has
# not yet received its REPLICATE still reports as a master with no slots, and
# gossip reaches the nodes one by one, so one node can already see 3 replicas
# while another still counts 5 masters. Each node must therefore report, on its
# own: state ok, all 16384 slots assigned and ok, 6 known nodes, exactly 3
# masters and 3 replicas without fail/handshake/noaddr flags, and (on a
# replica) an up link to its master.
#
# Usage: wait-for-redis-cluster.sh <container> [timeout-seconds]

set -euo pipefail

container="${1:?usage: wait-for-redis-cluster.sh <container> [timeout-seconds]}"
timeout="${2:-120}"
ports=(17000 17001 17002 17003 17004 17005)

cli() {
    docker exec "$container" redis-cli -p "$@" 2>/dev/null | tr -d '\r'
}

node_settled() {
    local port="$1" info nodes replication

    info="$(cli "$port" cluster info)" || return 1
    grep -qx 'cluster_state:ok' <<<"$info" || return 1
    grep -qx 'cluster_slots_assigned:16384' <<<"$info" || return 1
    grep -qx 'cluster_slots_ok:16384' <<<"$info" || return 1
    grep -qx 'cluster_known_nodes:6' <<<"$info" || return 1

    nodes="$(cli "$port" cluster nodes)" || return 1
    [ "$(awk '$3 ~ /handshake|noaddr|fail/' <<<"$nodes" | wc -l)" -eq 0 ] || return 1
    [ "$(awk '$3 ~ /master/' <<<"$nodes" | wc -l)" -eq 3 ] || return 1
    [ "$(awk '$3 ~ /slave/' <<<"$nodes" | wc -l)" -eq 3 ] || return 1

    replication="$(cli "$port" info replication)" || return 1
    if grep -qx 'role:slave' <<<"$replication"; then
        grep -qx 'master_link_status:up' <<<"$replication" || return 1
    fi

    return 0
}

cluster_settled() {
    local port

    for port in "${ports[@]}"; do
        node_settled "$port" || return 1
    done

    return 0
}

deadline=$((SECONDS + timeout))

until cluster_settled; do
    if [ "$SECONDS" -ge "$deadline" ]; then
        echo "::error::Redis cluster in '$container' did not settle within ${timeout}s: every node must report state ok, 16384 slots, 6 known nodes, 3 masters and 3 attached replicas"

        for port in "${ports[@]}"; do
            echo "--- :$port cluster nodes"
            cli "$port" cluster nodes || true
        done

        exit 1
    fi

    sleep 1
done

echo "cluster settled: 3 masters, 3 attached replicas, 16384 slots, on all ${#ports[@]} nodes"
