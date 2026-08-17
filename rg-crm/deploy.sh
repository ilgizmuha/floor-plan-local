#!/usr/bin/env bash
# Deploy RG CRM to VPS (/var/www/crm/). Separate from arrj.ru WordPress.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOST="${VPS_HOST:-159.194.229.87}"
USER="${VPS_USER:-root}"
REMOTE="/var/www/crm"

KEY="${VPS_SSH_KEY:-}"
ssh_opts=(-o StrictHostKeyChecking=no -o IdentitiesOnly=yes)
if [[ -n "$KEY" ]]; then
  ssh_opts+=(-i "$KEY")
elif [[ -f "${HOME}/.ssh/vps_key" ]]; then
  ssh_opts+=(-i "${HOME}/.ssh/vps_key")
elif [[ -f /tmp/cursor_vps_key ]]; then
  ssh_opts+=(-i /tmp/cursor_vps_key)
fi

echo "Deploy RG CRM -> ${USER}@${HOST}:${REMOTE}"
ssh "${ssh_opts[@]}" "${USER}@${HOST}" "mkdir -p ${REMOTE}/{css,js,data,source}"

if command -v rsync >/dev/null 2>&1; then
  rsync -az --delete \
    --exclude 'source/' \
    -e "ssh ${ssh_opts[*]}" \
    "${ROOT}/" "${USER}@${HOST}:${REMOTE}/"
else
  tar czf - -C "${ROOT}" --exclude=source . \
    | ssh "${ssh_opts[@]}" "${USER}@${HOST}" "tar xzf - -C ${REMOTE}"
fi

ssh "${ssh_opts[@]}" "${USER}@${HOST}" "chown -R www-data:www-data ${REMOTE}"
echo "Done: http://${HOST}/crm/"
