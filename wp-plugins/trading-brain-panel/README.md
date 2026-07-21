# Trading Brain Panel

Admin-only WordPress panel for `/opt/trading-brain`.

The plugin does not contain exchange keys or trading logic. It calls the limited helper `/usr/local/bin/trading-brain-panel` through sudo to:

- read `/opt/trading-brain/data/latest.json`
- read `trading-brain.service` status
- start, stop, or restart `trading-brain.service`

Install the helper and sudoers rule on the VPS before activating the plugin.
