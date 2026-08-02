# AGENTS.md — floor-plan-local + VPS + OCR

## Overview

This repo contains:

| Area | Path | VPS path |
|------|------|----------|
| Floor-plan vectorizer | `index.html`, `server.js` | — |
| Trading brain | `scripts/trading-brain.js` | `/opt/trading-brain/` |
| WP Trading panel | `wp-plugins/trading-brain-panel/` | `/var/www/wp/wp-content/plugins/trading-brain-panel/` |
| OCR plugin (Yandex Vision + DeepSeek) | `wp-plugins/wp-power-ocr-free/` | `/var/www/wp/wp-content/plugins/wp-power-ocr-free/` |

**VPS:** `root@159.194.229.87`  
**SSH key secret:** `VPS_SSH_KEY` (Cloud Agent) or `/tmp/cursor_vps_key` (ephemeral)

## Cloud Agent: manage VPS from chat

Always prefer `./scripts/vps.sh` instead of ad-hoc root SSH.

On first boot, `.cursor/environment.json` runs `deploy/bootstrap-vps-ssh.sh` to set up `~/.ssh/vps_key`.

```bash
chmod +x scripts/vps.sh deploy/bootstrap-vps-ssh.sh
bash deploy/bootstrap-vps-ssh.sh   # once per agent if install skipped
./scripts/vps.sh health
```

**Secrets in Cursor Cloud dashboard** (Environment → Secrets):
| Secret | Type | Purpose |
|--------|------|---------|
| `VPS_SSH_KEY` | Runtime Secret | private key (preferred) |
| `VPS_SSH_PASSWORD` | Runtime Secret | fallback: auto keygen + install pubkey on VPS |

Without secrets, warm-fork snapshots may still have `/tmp/cursor_vps_key`. deploy/vps-manage.sh

# Health + services
./scripts/vps.sh health
./scripts/vps.sh brain status
./scripts/vps.sh brain paper
./scripts/vps.sh ocr-status

# Deploy
./scripts/vps.sh deploy all          # brain + panel + helpers
./scripts/vps.sh deploy brain
./scripts/vps.sh deploy panel
./scripts/vps.sh deploy ocr

# OCR plugin sync (VPS ↔ repo)
./scripts/vps.sh ocr-pull             # download plugin from VPS
./scripts/vps.sh ocr-push             # upload local plugin to VPS

# Browse allowed paths on VPS
./scripts/vps.sh list /opt/trading-brain
./scripts/vps.sh list /var/www/wp/wp-content/plugins/wp-power-ocr-free
./scripts/vps.sh read /opt/trading-brain/data/paper-state.json
```

On VPS the unified helper is `/usr/local/bin/vps-manage` (read-only + safe path whitelist).

## VPS layout

```
/opt/trading-brain/          # trading brain (systemd: trading-brain.service)
/opt/bybit-trader/           # legacy bybit scripts
/var/www/wp/                 # WordPress root
  wp-content/plugins/
    wp-power-ocr-free/       # OCR: Яндекс Vision + DeepSeek AI
    trading-brain-panel/     # admin panel for trading brain
```

OCR temp dirs:
- `/var/www/wp/wp-content/uploads/yvo-tmp/`
- `/var/www/wp/wp-content/plugins/wp-power-ocr-free/tmp/`

## Trading brain

```bash
npm run brain:once
npm run brain:status
```

On VPS: `systemctl status trading-brain`, data in `/opt/trading-brain/data/`.

Secrets stay in `/opt/trading-brain/.env` — **never commit** `.env`.

## OCR plugin

Plugin name on disk: `wp-power-ocr-free` (Яндекс Vision OCR Pro с DeepSeek AI).

- Edit locally in `wp-plugins/wp-power-ocr-free/` after `ocr-pull`
- Deploy with `./scripts/vps.sh ocr-push`
- Check status: `./scripts/vps.sh ocr-status`
- Trading logic and API keys must **not** go into the OCR plugin

## Floor-plan local dev

```bash
npm install
cp config.json.example config.json   # Vectorizer.AI keys
npm start                            # http://localhost:8080
```

## Secrets (Cursor Cloud environment)

| Secret | Purpose |
|--------|---------|
| `VPS_SSH_KEY` | SSH private key for `159.194.229.87` |
| `VECTORIZER_USERNAME` / `VECTORIZER_PASSWORD` | Floor-plan vectorizer |
| `BYBIT_API_KEY` / `BYBIT_API_SECRET` | Bybit (VPS `.env`) |
| `CURSOR_API_KEY` | Cursor Analyst in trading brain |

## First-time VPS setup

```bash
./scripts/vps.sh deploy helpers
./scripts/vps.sh deploy brain
./scripts/vps.sh deploy panel
./scripts/vps.sh ocr-pull    # optional: sync OCR into repo
```

Sudoers:
- `/etc/sudoers.d/trading-brain-panel` — WP panel controls brain service
- `/etc/sudoers.d/vps-manage` — read-only VPS/OCR status for www-data
