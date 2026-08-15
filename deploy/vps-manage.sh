#!/usr/bin/env bash
# Unified read-only VPS helper for Cloud Agent / WordPress panels.
# Install: /usr/local/bin/vps-manage
set -euo pipefail

COMMAND="${1:-}"
ARG="${2:-}"

BRAIN_DIR="/opt/trading-brain"
BRAIN_DATA="${BRAIN_DIR}/data"
WP_ROOT="/var/www/wp"
OCR_PLUGIN="${WP_ROOT}/wp-content/plugins/wp-power-ocr-free"
PANEL_PLUGIN="${WP_ROOT}/wp-content/plugins/trading-brain-panel"
UPLOADS="${WP_ROOT}/wp-content/uploads"
SERVICE_NAME="trading-brain.service"

allowed_path() {
  local p
  p="$(readlink -f "$1" 2>/dev/null || true)"
  case "$p" in
    "${BRAIN_DIR}"/*|"${BRAIN_DIR}"|\
    "${WP_ROOT}"/*|"${WP_ROOT}"|\
    /opt/bybit-trader/*|/opt/bybit-trader|\
    /usr/local/bin/trading-brain-panel|\
    /usr/local/bin/vps-manage)
      printf '%s' "$p"
      ;;
    *)
      echo "path not allowed: $1" >&2
      exit 2
      ;;
  esac
}

json_escape() {
  python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))' <<<"$1"
}

cmd_health() {
  python3 <<'PY'
import json, subprocess, os
from pathlib import Path

def run(cmd):
    try:
        return subprocess.check_output(cmd, shell=True, text=True, stderr=subprocess.STDOUT).strip()
    except subprocess.CalledProcessError as e:
        return (e.output or str(e)).strip()

out = {
    "host": run("hostname"),
    "time": run("date -Is"),
    "disk": run("df -h / /opt /var/www/wp 2>/dev/null | tail -n +2"),
    "brainService": run("systemctl is-active trading-brain.service || true"),
    "nginx": run("systemctl is-active nginx 2>/dev/null || echo n/a"),
    "phpFpm": run("systemctl is-active php8.3-fpm 2>/dev/null || systemctl is-active php-fpm 2>/dev/null || echo n/a"),
    "paths": {
        "brain": "/opt/trading-brain",
        "ocrPlugin": "/var/www/wp/wp-content/plugins/wp-power-ocr-free",
        "tradingPanel": "/var/www/wp/wp-content/plugins/trading-brain-panel",
        "wpRoot": "/var/www/wp",
    },
}
for label, p in [
    ("brainDir", "/opt/trading-brain"),
    ("ocrPlugin", "/var/www/wp/wp-content/plugins/wp-power-ocr-free"),
    ("panelPlugin", "/var/www/wp/wp-content/plugins/trading-brain-panel"),
]:
    path = Path(p)
    out.setdefault("exists", {})[label] = path.exists()
    if path.exists():
        out.setdefault("mtime", {})[label] = path.stat().st_mtime
print(json.dumps(out, ensure_ascii=False, indent=2))
PY
}

cmd_brain() {
  local sub="${ARG:-status}"
  case "$sub" in
    status) systemctl is-active "$SERVICE_NAME" || true ;;
    latest) [[ -r "${BRAIN_DATA}/latest.json" ]] && cat "${BRAIN_DATA}/latest.json" || printf '{}\n' ;;
    paper) [[ -r "${BRAIN_DATA}/paper-state.json" ]] && cat "${BRAIN_DATA}/paper-state.json" || printf '{}\n' ;;
    quality) [[ -r "${BRAIN_DATA}/quality.json" ]] && cat "${BRAIN_DATA}/quality.json" || printf '{}\n' ;;
    restart) systemctl restart "$SERVICE_NAME"; systemctl is-active "$SERVICE_NAME" ;;
    start) systemctl start "$SERVICE_NAME"; systemctl is-active "$SERVICE_NAME" ;;
    stop) systemctl stop "$SERVICE_NAME"; systemctl is-active "$SERVICE_NAME" || true ;;
    *) echo "brain subcommands: status|latest|paper|quality|start|stop|restart" >&2; exit 2 ;;
  esac
}

cmd_ocr_status() {
  python3 <<'PY'
import json, os, re
from pathlib import Path

plugin = Path("/var/www/wp/wp-content/plugins/wp-power-ocr-free/wp-power-ocr-free.php")
info = {"pluginDir": str(plugin.parent), "exists": plugin.exists()}
if plugin.exists():
    text = plugin.read_text(encoding="utf-8", errors="ignore")
    for key, pat in [
        ("name", r"Plugin Name:\s*(.+)"),
        ("version", r"Version:\s*([0-9.]+)"),
        ("yvoVersion", r"define\('YVO_VERSION',\s*'([^']+)'\)"),
    ]:
        m = re.search(pat, text)
        if m:
            info[key] = m.group(1).strip()
for rel in ["tmp", "../../uploads/yvo-tmp", "../../uploads/yvo-templates"]:
    p = (plugin.parent / rel).resolve()
    info.setdefault("dirs", []).append({
        "path": str(p),
        "exists": p.exists(),
        "writable": os.access(p, os.W_OK) if p.exists() else False,
        "files": len(list(p.glob("*"))) if p.is_dir() else 0,
    })
print(json.dumps(info, ensure_ascii=False, indent=2))
PY
}

cmd_ocr_tmp() {
  python3 <<'PY'
import json
from pathlib import Path
roots = [
    Path("/var/www/wp/wp-content/uploads/yvo-tmp"),
    Path("/var/www/wp/wp-content/plugins/wp-power-ocr-free/tmp"),
]
items = []
for root in roots:
    if not root.is_dir():
        continue
    for p in sorted(root.iterdir(), key=lambda x: x.stat().st_mtime, reverse=True)[:30]:
        st = p.stat()
        items.append({
            "root": str(root),
            "name": p.name,
            "size": st.st_size,
            "mtime": st.st_mtime,
            "isDir": p.is_dir(),
        })
print(json.dumps({"items": items}, ensure_ascii=False, indent=2))
PY
}

cmd_list() {
  local target
  target="$(allowed_path "${ARG:-/opt}")"
  ls -la --time-style=long-iso "$target"
}

cmd_read() {
  local target size
  target="$(allowed_path "$ARG")"
  if [[ ! -f "$target" ]]; then
    echo "not a file: $target" >&2
    exit 2
  fi
  size="$(stat -c%s "$target" 2>/dev/null || stat -f%z "$target")"
  if [[ "$size" -gt 1048576 ]]; then
    echo "file too large (${size} bytes, max 1MB): $target" >&2
    exit 2
  fi
  cat "$target"
}

cmd_wp_plugins() {
  if command -v wp >/dev/null 2>&1; then
    wp --path="$WP_ROOT" plugin list --fields=name,status,version,update 2>/dev/null || true
  else
    ls -1 "$WP_ROOT/wp-content/plugins"
  fi
}

case "$COMMAND" in
  health) cmd_health ;;
  brain) cmd_brain ;;
  ocr-status) cmd_ocr_status ;;
  ocr-tmp) cmd_ocr_tmp ;;
  list) cmd_list ;;
  read) cmd_read ;;
  wp-plugins) cmd_wp_plugins ;;
  *)
    cat >&2 <<'EOF'
Usage: vps-manage <command> [arg]

Commands:
  health                 JSON summary of VPS + key paths
  brain <sub>            status|latest|paper|quality|start|stop|restart
  ocr-status             OCR plugin version + temp dirs
  ocr-tmp                Recent OCR temp/upload files
  list <path>            List allowed directory
  read <file>            Read allowed file (max 1MB)
  wp-plugins             WordPress plugin list
EOF
    exit 2
    ;;
esac
