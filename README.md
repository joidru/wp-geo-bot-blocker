# WP Geo Bot Blocker

WordPress plugin for country-based traffic filtering, bot detection and request rate limiting.

## Features

- country allowlist with monitoring and blocking modes;
- verified Google and Yandex crawler bypass using forward-confirmed reverse DNS;
- editable User-Agent keyword filter;
- IP and URL allowlists;
- per-IP request rate limiting;
- compact event log and statistics;
- automatic updates through GitHub Releases.

## Installation

1. Download `spamblock.zip` from the latest release.
2. In WordPress open **Plugins → Add New → Upload Plugin**.
3. Activate the plugin.
4. Open **Settings → Traffic Blocking** and begin with monitoring mode.

## Updates

Installed copies check the latest GitHub Release through the public GitHub API. When a newer release contains the `spamblock.zip` asset, WordPress displays its standard **Update now** action.

## Publishing a release

Create and push a tag such as `v1.4.1`. The included GitHub Actions workflow packages the plugin and publishes a release containing `spamblock.zip`.

## License

GPL-3.0-or-later.
