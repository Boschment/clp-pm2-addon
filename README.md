# CloudPanel PM2 Addon

Adds a **PM2** tab to Node.js sites in CloudPanel. The tab only appears when the site type is Node.js.

## Features

- **Ecosystem config editor** — large textarea for `ecosystem.config.js`. The file is stored at `/opt/clp-pm2-addon/data/<domain>/ecosystem.config.js` (owned by the site user, mode `600`), **not** inside the site directory, so it never lands in git or under the web root.
- **Live status** — name, status badge, PID, CPU, memory, restart count, uptime, refreshed every 5s.
- **Lifecycle buttons** — `Start` shows when nothing is running; `Stop` shows when something is running; `Restart` is always available while running. All actions go through a single root helper (`clp-pm2-helper`) and run as the site user via `sudo -u`.
- **Auto-install of PM2** — a systemd `.path` unit watches `/etc/nginx/sites-enabled/`. When a new Node.js vhost appears (detected by `proxy_pass` + no PHP-FPM pool for the site user), `pm2` is installed in that user's `nvm` via `npm install -g pm2`.

## Install

```bash
sudo mkdir -p /opt/clp-pm2-addon
sudo cp -r ./* /opt/clp-pm2-addon/
sudo bash /opt/clp-pm2-addon/scripts/clp-pm2-addon install
```

## Commands

```bash
clp-pm2-addon install     # initial install
clp-pm2-addon repair      # re-apply patches (auto-runs after every apt operation)
clp-pm2-addon check       # verify status
clp-pm2-addon uninstall   # remove the addon (keeps stored configs and installed pm2s)
```

## Update survival

Same mechanism as the env addon: `/etc/apt/apt.conf.d/99-clp-pm2-addon` invokes `clp-pm2-addon repair --quiet` after every apt operation. `repair` is idempotent (<1ms when intact).

## Files patched into CloudPanel

- `src/Controller/Frontend/Pm2Controller.php` — added
- `templates/Frontend/Site/pm2.html.twig` — added
- `public/assets/css/frontend/pm2.css` — added
- `config/routes.yaml` — six routes appended in a marker block
- `templates/Frontend/Site/Partial/tab-container.html.twig` — `<li>` injected, wrapped in a Twig conditional that only renders for Node.js sites

## How "Node.js site" is detected

The tab visibility (Twig) and controller (PHP) both check site type via several plausible attribute names (`type`, `siteType`, `application`) for cross-version compatibility. PHP also has a filesystem fallback: no PHP-FPM pool for the site user **plus** an nginx vhost containing `proxy_pass`.

## Security

- `clp` user can only invoke `/usr/local/bin/clp-pm2-helper` via sudo (single-line sudoers entry). All pm2 invocations run as the site user via `sudo -u <user>`.
- Ecosystem config is stored outside the site/web root, mode `600`, owned by the site user. PM2 reads it from `/opt/clp-pm2-addon/data/<domain>/`.
- All routes are CSRF-protected.

## Uninstall

```bash
sudo clp-pm2-addon uninstall
# Purge stored configs and source:
sudo rm -rf /opt/clp-pm2-addon /usr/local/bin/clp-pm2-addon
```

Already-running PM2 processes are intentionally **not** stopped on uninstall.
