#!/usr/bin/env bash
set -euo pipefail

COMMAND="${1:-}"
LATEST_FILE="/opt/trading-brain/data/latest.json"
PAPER_FILE="/opt/trading-brain/data/paper-state.json"
QUALITY_FILE="/opt/trading-brain/data/quality.json"
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
  paper)
    if [[ -r "$PAPER_FILE" ]]; then
      cat "$PAPER_FILE"
    else
      printf '{}\n'
    fi
    ;;
  quality)
    if [[ -r "$QUALITY_FILE" ]]; then
      cat "$QUALITY_FILE"
    else
      printf '{}\n'
    fi
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
    echo "Usage: trading-brain-panel {latest|status|paper|quality|start|stop|restart}" >&2
    exit 2
    ;;
esac
