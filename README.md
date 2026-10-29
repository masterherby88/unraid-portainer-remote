# Portainer Remote for Unraid

A small Unraid plugin that adds a Dashboard tile for one Docker container on a remote Docker host managed by Portainer. The initial target is an ioBroker container on a Raspberry Pi.

## What it does

- Settings page for the Portainer URL, API key, Environment ID, exact container name, TLS verification, and refresh interval.
- Connection test lists available Portainer environments.
- Dashboard tile displays state, health, image, and Portainer connectivity.
- Start, stop, restart (with confirmation), refresh, and last-100-lines log actions.
- API key stays server-side in `/boot/config/plugins/portainer.remote/portainer.remote.cfg` (mode 0600). It is never rendered into page JavaScript.
- Uses Portainer's documented API; it does not inspect or modify Portainer's database.

## Requirements

- Unraid with its PHP/cURL webGUI support.
- A reachable Portainer Server API.
- A Portainer API key and the Environment ID for the Raspberry Pi's Docker endpoint.
- The configured container name must match exactly (usually `iobroker`).

## Configuration

1. In Portainer, create an API key for a user that can access only the Raspberry Pi environment if possible.
2. Set the URL to the Portainer Server (for example `https://portainer.lan:9443`), click **Verbindung testen**, and choose the Environment ID from the list.
3. In Unraid, open **Settings → User Utilities → Portainer Remote**, enter the exact container name, click **Speichern**, then open the Dashboard.
4. If the widget is not visible, unlock the Dashboard and enable **Remote-Portainer-Containerstatus** in the tile manager.
5. A self-signed certificate should preferably be added to the host trust store. Only disable TLS verification for a trusted local connection where the certificate cannot be validated.

Keep Portainer and the agent reachable only from trusted LANs/VPNs. The API key grants the same access as its Portainer account; avoid an administrator key when a narrower account will do. HTTP sends the API key unencrypted and should not be used across untrusted networks.

## Install / build

This repository is public so Unraid can fetch its plugin and release URLs without credentials. `build-package.sh` builds a Slackware-compatible `.txz` package from `src/`. Pushing a `v*` tag builds and attaches the package to a GitHub release. The installer URL for the current release is:

```text
https://raw.githubusercontent.com/masterherby88/unraid-portainer-remote/main/portainer.remote.plg
```

Install it in Unraid via **Plugins → Install Plugin**. The plugin installer fetches the matching `.txz` package from GitHub Releases. To build locally, run:

```bash
./build-package.sh
python3 tests/test_static.py
```

## API scope

Only these actions are implemented: list environments (test), list/inspect the configured container, start, stop, restart, and bounded logs. The plugin does not create/delete containers, edit their configuration, execute arbitrary commands, or expose a generic Docker API proxy.

## Limitations

- One configured Portainer environment and one exact container name per installation.
- No direct Portainer SQLite database support. The database format is internal and credentials are not recoverable from it; the API is the supported integration surface.
- Container logs are limited to the most recent 200 lines and 20 KB.
- This is an early version; validate on a test system before relying on it for home automation uptime.

## License

MIT. See `LICENSE`.
