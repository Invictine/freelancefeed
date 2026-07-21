#!/bin/sh
set -eu

stack_dir=${MASS_APPLY_STACK_DIR:-/opt/rss-leads-stack}
login_parent="$stack_dir/tmp"
container_id=${MASS_APPLY_CONTAINER_ID:-102}
container_name=${MASS_APPLY_CONTAINER_NAME:-rss-leads-mass-apply}
image_name=${MASS_APPLY_IMAGE:-rss-leads-mass-apply:latest}
volume_name=${MASS_APPLY_AUTH_VOLUME:-rss-leads-stack_codex_auth}
auth_target=/tmp/rss-leads-mass-apply-auth.json

for command in codex pct; do
	if ! command -v "$command" >/dev/null 2>&1; then
		echo "Required command not found: $command" >&2
		exit 1
	fi
done

if ! pct status "$container_id" 2>/dev/null | grep -q 'status: running'; then
	echo "Proxmox container $container_id is not running." >&2
	exit 1
fi

if ! pct exec "$container_id" -- docker inspect "$container_name" >/dev/null 2>&1; then
	echo "Docker container $container_name was not found in Proxmox container $container_id." >&2
	echo "Start Mass Apply first from $stack_dir inside that container." >&2
	exit 1
fi

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
	--user 0 \
	-v "$auth_target:/source/auth.json:ro" \
	-v "$volume_name:/target" \
	--entrypoint sh "$image_name" \
	-lc 'cp /source/auth.json /target/auth.json && chown 10001:10001 /target/auth.json && chmod 600 /target/auth.json'
pct exec "$container_id" -- docker restart "$container_name" >/dev/null
pct exec "$container_id" -- docker exec "$container_name" codex login status

echo "Mass Apply Codex login installed successfully."
echo
echo "Next steps:"
echo "1. Open FreshRSS and select Mass apply."
echo "2. Copy the helper pairing token printed below into the panel."
echo "3. Select Save token & check, then queue leads and prepare the drafts."
echo
echo "Helper pairing token (treat this like a password):"
pct exec "$container_id" -- docker exec "$container_name" sh -c 'cat "$CODEX_HOME/mass-apply-token"'
