#!/usr/bin/env bash
set -euo pipefail

COMMAND="${1:-}"
LATEST_FILE="/opt/trading-brain/data/latest.json"
SERVICE_NAME="trading-brain.service"

case "$COMMAND" in
  latest)
    if [[ -r "$LATEST_FILE" ]]; then
      cat "$LATEST_FILE"
    else
      printf '{}\n'
    fi
    ;;
  status)
    systemctl is-active "$SERVICE_NAME" || true
    ;;
  start)
    systemctl start "$SERVICE_NAME"
    systemctl is-active "$SERVICE_NAME"
    ;;
  stop)
    systemctl stop "$SERVICE_NAME"
    systemctl is-active "$SERVICE_NAME" || true
    ;;
  restart)
    systemctl restart "$SERVICE_NAME"
    systemctl is-active "$SERVICE_NAME"
    ;;
  *)
    echo "Usage: trading-brain-panel {latest|status|start|stop|restart}" >&2
    exit 2
    ;;
esac
