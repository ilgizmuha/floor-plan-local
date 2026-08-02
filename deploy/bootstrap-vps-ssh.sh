#!/usr/bin/env bash
# Idempotent SSH bootstrap for Cloud Agent -> VPS (159.194.229.87).
# Uses (in order): VPS_SSH_KEY env, ~/.ssh/vps_key, /tmp/cursor_vps_key,
# or VPS_SSH_PASSWORD + auto keygen.
set -euo pipefail

VPS_HOST="${VPS_HOST:-159.194.229.87}"
VPS_USER="${VPS_USER:-root}"
SSH_DIR="${HOME}/.ssh"
KEY_PATH="${SSH_DIR}/vps_key"
CONFIG_PATH="${SSH_DIR}/config"
LEGACY_KEY="/tmp/cursor_vps_key"

mkdir -p "$SSH_DIR"
chmod 700 "$SSH_DIR"

write_key_from_env() {
  if [[ -z "${VPS_SSH_KEY:-}" ]]; then
    return 1
  fi
  printf '%s\n' "$VPS_SSH_KEY" > "$KEY_PATH"
  chmod 600 "$KEY_PATH"
}

copy_legacy_key() {
  if [[ -f "$LEGACY_KEY" ]]; then
    install -m 600 "$LEGACY_KEY" "$KEY_PATH"
    return 0
  fi
  return 1
}

bootstrap_with_password() {
  if [[ -z "${VPS_SSH_PASSWORD:-}" ]]; then
    return 1
  fi
  if ! command -v sshpass >/dev/null 2>&1; then
    echo "sshpass required for VPS_SSH_PASSWORD bootstrap" >&2
    return 1
  fi
  if [[ ! -f "$KEY_PATH" ]]; then
    ssh-keygen -t ed25519 -f "$KEY_PATH" -N '' -C 'cursor-cloud-vps' >/dev/null
    chmod 600 "$KEY_PATH"
  fi
  local pubkey
  pubkey="$(tr -d '\n' < "${KEY_PATH}.pub")"
  sshpass -p "$VPS_SSH_PASSWORD" ssh \
    -o StrictHostKeyChecking=accept-new \
    -o PubkeyAuthentication=no \
    -o PreferredAuthentications=password \
    -o NumberOfPasswordPrompts=1 \
    "${VPS_USER}@${VPS_HOST}" \
    "mkdir -p ~/.ssh && chmod 700 ~/.ssh && grep -qxF '${pubkey}' ~/.ssh/authorized_keys 2>/dev/null || echo '${pubkey}' >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"
}

write_ssh_config() {
  local block
  block=$(cat <<EOF

# floor-plan-local VPS (managed by deploy/bootstrap-vps-ssh.sh)
Host vps ${VPS_HOST}
  HostName ${VPS_HOST}
  User ${VPS_USER}
  IdentityFile ${KEY_PATH}
  IdentitiesOnly yes
  StrictHostKeyChecking accept-new
EOF
)
  if [[ -f "$CONFIG_PATH" ]] && grep -q '# floor-plan-local VPS' "$CONFIG_PATH"; then
    return 0
  fi
  printf '%s\n' "$block" >> "$CONFIG_PATH"
  chmod 600 "$CONFIG_PATH"
}

if [[ ! -f "$KEY_PATH" ]]; then
  write_key_from_env || copy_legacy_key || bootstrap_with_password || true
fi

if [[ -f "$KEY_PATH" ]]; then
  chmod 600 "$KEY_PATH"
  write_ssh_config
  export VPS_SSH_KEY="$KEY_PATH"
  if ssh -i "$KEY_PATH" -o BatchMode=yes -o ConnectTimeout=12 "${VPS_USER}@${VPS_HOST}" 'echo vps_ok' >/dev/null 2>&1; then
    echo "VPS SSH ready: ${VPS_USER}@${VPS_HOST} (${KEY_PATH})"
    exit 0
  fi
  echo "VPS key present but connection failed — check authorized_keys on server" >&2
  exit 1
fi

echo "No VPS SSH key. Set VPS_SSH_KEY or VPS_SSH_PASSWORD in Cloud Agent secrets, or keep warm-fork snapshot with /tmp/cursor_vps_key" >&2
exit 0
