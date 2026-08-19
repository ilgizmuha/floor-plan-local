#!/usr/bin/env bash
# Deploy RG CRM to VPS (/var/www/crm/). Separate from arrj.ru WordPress.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOST="${VPS_HOST:-159.194.229.87}"
USER="${VPS_USER:-root}"
REMOTE="/var/www/crm"
BUILD_ID="$(date -u +%Y%m%d%H%M%S)"

KEY="${VPS_SSH_KEY:-}"
ssh_opts=(-o StrictHostKeyChecking=no -o IdentitiesOnly=yes)
if [[ -n "$KEY" ]]; then
  ssh_opts+=(-i "$KEY")
elif [[ -f "${HOME}/.ssh/vps_key" ]]; then
  ssh_opts+=(-i "${HOME}/.ssh/vps_key")
elif [[ -f /tmp/cursor_vps_key ]]; then
  ssh_opts+=(-i /tmp/cursor_vps_key)
fi

echo "Deploy RG CRM -> ${USER}@${HOST}:${REMOTE} (build ${BUILD_ID})"
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

ssh "${ssh_opts[@]}" "${USER}@${HOST}" bash -s <<EOF
set -euo pipefail
BUILD_ID="${BUILD_ID}"
REMOTE="${REMOTE}"
sed -i "s/css\\/styles.css?v=[0-9]*/css\\/styles.css?v=\${BUILD_ID}/" "\${REMOTE}/index.html"
sed -i "s/js\\/app.js?v=[0-9]*/js\\/app.js?v=\${BUILD_ID}/" "\${REMOTE}/index.html"
echo "\${BUILD_ID}" > "\${REMOTE}/BUILD_ID"
cat > /etc/nginx/snippets/crm-static.conf <<'NGINX'
location = /crm {
    return 301 /crm/;
}
location /crm/ {
    alias /var/www/crm/;
    index index.html;
    add_header Cache-Control "no-cache, no-store, must-revalidate" always;
    add_header Pragma "no-cache" always;
    add_header Expires "0" always;
}
NGINX
nginx -t && systemctl reload nginx
chown -R www-data:www-data "\${REMOTE}"
EOF

echo "Done: http://${HOST}/crm/ (build ${BUILD_ID})"
