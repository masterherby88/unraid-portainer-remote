#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VERSION="$(tr -d '[:space:]' < "$ROOT/VERSION")"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/install"
cp -a "$ROOT/src/usr" "$STAGE/"
cat > "$STAGE/install/doinst.sh" <<'EOF'
#!/bin/sh
mkdir -p /boot/config/plugins/portainer.remote
CFG=/boot/config/plugins/portainer.remote/portainer.remote.cfg
if [ ! -f "$CFG" ]; then
  install -m 0600 /usr/local/emhttp/plugins/portainer.remote/default.cfg "$CFG"
fi
chmod 0600 "$CFG" 2>/dev/null || true
EOF
cat > "$STAGE/install/slack-desc" <<'EOF'
portainer.remote: Portainer Remote Dashboard tile for Unraid
portainer.remote:
portainer.remote: Show and manage a configured remote Docker container using
portainer.remote: the Portainer API. Includes an Unraid settings page and tile.
portainer.remote:
portainer.remote: This community project is not affiliated with Portainer or Unraid.
EOF
chmod 0755 "$STAGE/install/doinst.sh"
OUT="$ROOT/dist/portainer.remote-${VERSION}.txz"
mkdir -p "$ROOT/dist"
tar --sort=name --mtime='@0' --owner=0 --group=0 --numeric-owner -cJf "$OUT" -C "$STAGE" .
echo "Built $OUT"
