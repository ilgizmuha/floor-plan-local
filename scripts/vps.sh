#!/usr/bin/env bash
# Remote VPS management for Cloud Agent / local dev.
# Requires: VPS_HOST, VPS_USER (default root), VPS_SSH_KEY or ~/.ssh/id_rsa
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOST="${VPS_HOST:-159.194.229.87}"
USER="${VPS_USER:-root}"
KEY="${VPS_SSH_KEY:-}"
if [[ -z "$KEY" && -f "${HOME}/.ssh/vps_key" ]]; then
  KEY="${HOME}/.ssh/vps_key"
fi
REMOTE_HELPER="/usr/local/bin/vps-manage"
REMOTE_BRAIN="/opt/trading-brain"
REMOTE_WP_PLUGINS="/var/www/wp/wp-content/plugins"

ssh_opts=(-o StrictHostKeyChecking=no -o IdentitiesOnly=yes)
if [[ -n "$KEY" ]]; then
  ssh_opts+=(-i "$KEY")
elif [[ -f "${HOME}/.ssh/vps_key" ]]; then
  ssh_opts+=(-i "${HOME}/.ssh/vps_key")
elif [[ -f /tmp/cursor_vps_key ]]; then
  ssh_opts+=(-i /tmp/cursor_vps_key)
fi

remote() {
  ssh "${ssh_opts[@]}" "${USER}@${HOST}" "$@"
}

scp_to() {
  local src="$1" dest="$2"
  scp "${ssh_opts[@]}" "$src" "${USER}@${HOST}:${dest}"
}

have_rsync() {
  command -v rsync >/dev/null 2>&1
}

sync_dir_to_remote() {
  local local_dir="$1" remote_dir="$2"
  shift 2
  local excludes=("$@")
  if have_rsync; then
    local args=(-az --delete -e "ssh ${ssh_opts[*]}")
    for ex in "${excludes[@]}"; do args+=(--exclude "$ex"); done
    rsync "${args[@]}" "${local_dir}/" "${USER}@${HOST}:${remote_dir}/"
    return
  fi
  local tar_args=()
  for ex in "${excludes[@]}"; do tar_args+=(--exclude="$ex"); done
  tar czf - -C "$(dirname "$local_dir")" "${tar_args[@]}" "$(basename "$local_dir")" \
    | remote "mkdir -p '$remote_dir' && tar xzf - -C '$(dirname "$remote_dir")'"
}

sync_dir_from_remote() {
  local remote_dir="$1" local_dir="$2"
  shift 2
  local excludes=("$@")
  mkdir -p "$local_dir"
  if have_rsync; then
    local args=(-az -e "ssh ${ssh_opts[*]}")
    for ex in "${excludes[@]}"; do args+=(--exclude "$ex"); done
    rsync "${args[@]}" "${USER}@${HOST}:${remote_dir}/" "${local_dir}/"
    return
  fi
  local tar_args=()
  for ex in "${excludes[@]}"; do tar_args+=(--exclude="$ex"); done
  remote "tar czf - -C '$(dirname "$remote_dir")' ${tar_args[*]} '$(basename "$remote_dir")'" \
    | tar xzf - -C "$(dirname "$local_dir")"
}

usage() {
  cat <<EOF
Usage: ./scripts/vps.sh <command> [args]

Commands:
  ssh                         Open SSH shell
  run <vps-manage cmd...>     Run /usr/local/bin/vps-manage on VPS
  health                      VPS health JSON
  brain <sub>                 brain status|latest|paper|quality|restart
  ocr-status                  OCR plugin status
  ocr-tmp                     OCR temp files list
  list <path>                 List allowed VPS path
  read <file>                 Read allowed VPS file
  deploy [all|brain|panel|helpers|ocr]
  ocr-pull                    Download OCR plugin from VPS -> wp-plugins/wp-power-ocr-free
  ocr-push                    Upload OCR plugin from repo -> VPS

Env:
  VPS_HOST (default $HOST)
  VPS_USER (default $USER)
  VPS_SSH_KEY path to private key
EOF
}

cmd_deploy() {
  local what="${1:-all}"
  echo "Deploy to ${USER}@${HOST} ($what)"

  if [[ "$what" == "all" || "$what" == "helpers" ]]; then
    scp_to "$ROOT/deploy/vps-manage.sh" /usr/local/bin/vps-manage
    scp_to "$ROOT/deploy/trading-brain-panel-helper.sh" /usr/local/bin/trading-brain-panel
    remote "chmod +x /usr/local/bin/vps-manage /usr/local/bin/trading-brain-panel"
    if [[ -f "$ROOT/deploy/sudoers-vps-manage" ]]; then
      scp_to "$ROOT/deploy/sudoers-vps-manage" /etc/sudoers.d/vps-manage
      remote "chmod 440 /etc/sudoers.d/vps-manage"
    fi
  fi

  if [[ "$what" == "all" || "$what" == "brain" ]]; then
    remote "mkdir -p ${REMOTE_BRAIN}/scripts/knowledge ${REMOTE_BRAIN}/data"
    scp_to "$ROOT/scripts/trading-brain.js" "${REMOTE_BRAIN}/trading-brain.js"
    scp_to "$ROOT/scripts/strategy-engine.js" "${REMOTE_BRAIN}/strategy-engine.js"
    scp_to "$ROOT/scripts/finam-client.js" "${REMOTE_BRAIN}/finam-client.js"
    for f in "$ROOT/scripts/knowledge/"*.json; do
      [[ -f "$f" ]] && scp_to "$f" "${REMOTE_BRAIN}/scripts/knowledge/$(basename "$f")"
    done
    if [[ -f "$ROOT/deploy/trading-brain.service" ]]; then
      scp_to "$ROOT/deploy/trading-brain.service" /etc/systemd/system/trading-brain.service
      remote "systemctl daemon-reload"
    fi
    remote "systemctl restart trading-brain.service || true"
  fi

  if [[ "$what" == "all" || "$what" == "panel" ]]; then
    remote "mkdir -p ${REMOTE_WP_PLUGINS}/trading-brain-panel"
    scp_to "$ROOT/wp-plugins/trading-brain-panel/trading-brain-panel.php" \
      "${REMOTE_WP_PLUGINS}/trading-brain-panel/trading-brain-panel.php"
  fi

  if [[ "$what" == "all" || "$what" == "ocr" ]]; then
    if [[ ! -f "$ROOT/wp-plugins/wp-power-ocr-free/wp-power-ocr-free.php" ]]; then
      echo "Skip ocr: wp-plugins/wp-power-ocr-free not in repo. Run: ./scripts/vps.sh ocr-pull" >&2
    else
      remote "mkdir -p ${REMOTE_WP_PLUGINS}/wp-power-ocr-free/tmp"
      sync_dir_to_remote "$ROOT/wp-plugins/wp-power-ocr-free" \
        "${REMOTE_WP_PLUGINS}/wp-power-ocr-free" \
        'tmp/' 'vendor/' '.git/'
      remote "chown -R www-data:www-data ${REMOTE_WP_PLUGINS}/wp-power-ocr-free; chmod 777 ${REMOTE_WP_PLUGINS}/wp-power-ocr-free/tmp"
    fi
  fi

  echo "Done."
}

cmd_ocr_pull() {
  mkdir -p "$ROOT/wp-plugins/wp-power-ocr-free"
  sync_dir_from_remote "${REMOTE_WP_PLUGINS}/wp-power-ocr-free" \
    "$ROOT/wp-plugins/wp-power-ocr-free" \
    'tmp/' 'vendor/'
  echo "Pulled OCR plugin to wp-plugins/wp-power-ocr-free/"
}

cmd_ocr_push() {
  if [[ ! -d "$ROOT/wp-plugins/wp-power-ocr-free" ]]; then
    echo "Missing wp-plugins/wp-power-ocr-free — run ocr-pull first" >&2
    exit 1
  fi
  cmd_deploy ocr
}

main() {
  local cmd="${1:-help}"
  shift || true
  case "$cmd" in
    help|-h|--help) usage ;;
    ssh) remote "${@:-bash -l}" ;;
    run) remote "$REMOTE_HELPER $*" ;;
    health) remote "$REMOTE_HELPER health" ;;
    brain) remote "$REMOTE_HELPER brain ${1:-status}" ;;
    ocr-status) remote "$REMOTE_HELPER ocr-status" ;;
    ocr-tmp) remote "$REMOTE_HELPER ocr-tmp" ;;
    list) remote "$REMOTE_HELPER list ${1:-/opt}" ;;
    read) remote "$REMOTE_HELPER read $1" ;;
    deploy) cmd_deploy "${1:-all}" ;;
    ocr-pull) cmd_ocr_pull ;;
    ocr-push) cmd_ocr_push ;;
    *) echo "Unknown command: $cmd" >&2; usage; exit 2 ;;
  esac
}

main "$@"
