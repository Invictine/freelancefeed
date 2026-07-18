#!/bin/sh
set -eu

stack_dir=/opt/rss-leads-stack
login_parent="$stack_dir/tmp"
container_id=102
container_name=rss-leads-mass-apply
auth_target=/tmp/rss-leads-mass-apply-auth.json

mkdir -p "$login_parent"
login_dir=$(mktemp -d "$login_parent/codex-mass-login.XXXXXX")
cleanup() {
	if [ -f "$login_dir/auth.json" ]; then
		chmod 600 "$login_dir/auth.json"
	fi
	rm -rf "$login_dir"
	pct exec "$container_id" -- rm -f "$auth_target" >/dev/null 2>&1 || true
}
trap cleanup EXIT HUP INT TERM

echo "Starting a new, dedicated Codex device login for Mass Apply."
echo "This does not read or modify the Proxmox host's existing Codex credentials."
CODEX_HOME="$login_dir" codex login --device-auth

if [ ! -s "$login_dir/auth.json" ]; then
	echo "Codex login completed without creating auth.json." >&2
	exit 1
fi

pct push "$container_id" "$login_dir/auth.json" "$auth_target"
pct exec "$container_id" -- docker run --rm \
	-v "$auth_target:/source/auth.json:ro" \
	-v rss-leads-stack_codex_auth:/target \
	--entrypoint sh rss-leads-mass-apply:latest \
	-lc 'cp /source/auth.json /target/auth.json && chmod 600 /target/auth.json'
pct exec "$container_id" -- docker restart "$container_name" >/dev/null
pct exec "$container_id" -- docker exec "$container_name" codex login status

echo "Mass Apply Codex login installed successfully."
