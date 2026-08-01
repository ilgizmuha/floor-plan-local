# WP Power OCR (Yandex Vision + DeepSeek)

OCR plugin for WordPress: document/image text recognition, contract forms, deal cabinet.

## VPS location

```
/var/www/wp/wp-content/plugins/wp-power-ocr-free/
```

## Sync with repo

```bash
# Download from VPS (first time)
./scripts/vps.sh ocr-pull

# Edit files in this folder, then deploy
./scripts/vps.sh ocr-push
```

`vendor/` and `tmp/` are excluded from rsync — run `composer install` on VPS if needed.

## Status from chat / agent

```bash
./scripts/vps.sh ocr-status
./scripts/vps.sh ocr-tmp
```

## Notes

- Writable temp: `uploads/yvo-tmp/` and plugin `tmp/` (must be www-data writable)
- Do not put trading-brain or Bybit keys in this plugin
- Main entry: `wp-power-ocr-free.php`
