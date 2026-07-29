#!/bin/bash
set -eu

INSTALL_DIR="/root/planos"
LOCK_DIR="/var/run/patch-planos-huawei.lock"

if ! mkdir "$LOCK_DIR" 2>/dev/null; then
  exit 0
fi
trap 'rmdir "$LOCK_DIR"' EXIT INT TERM

"${INSTALL_DIR}/planos.sh" |
while IFS="$(printf '\t')" read -r plano up down; do
  [ -n "$plano" ] || continue
  "${INSTALL_DIR}/att-planos.sh" "$plano" "$up" "$down"
done
