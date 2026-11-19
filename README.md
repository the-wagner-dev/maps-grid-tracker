# Maps Grid Tracker

A small WordPress plugin that tracks how a business ranks in Google Maps from a grid of points around a location. Results are shown as a map with coloured pins, using your own [DataForSEO](https://dataforseo.com/) account.

It lives under **Tools → Maps Grid** and uses the standard WordPress admin look.

## Features

- **Grid**: from 3×3 to 15×15, with configurable spacing (km) and Google Maps zoom. The settings page has a live preview, and you click the map to move the centre.
- **Keywords**: up to 50, one per line, in any language code.
- **Business match**: by Google CID, with the website domain as a fallback. The "Find business" button runs one live lookup and fills the CID.
- **Report**:
  - pins coloured by position (1–3, 4–10, 11–depth, not found);
  - the top 3 competitors at every point;
  - average position, average total position (not found counts as depth + 1), and top-3 and found shares;
  - change since the previous scan;
  - a summary of all keywords.
- **History**:
  - scans with their status and the real cost reported by the API;
  - manual runs with a cost estimate and confirmation first.
- **Scheduling**: daily, weekly, every 2 weeks or every 30 days, at a chosen hour in the site timezone.
- **Queue**: uses DataForSEO's standard queue (`task_post` and then `task_get/advanced`), which is cheaper than live requests. Results are fetched in parallel batches.
- **Translations**: English, Polish (`pl_PL`) and Russian (`ru_RU`).
- **Sandbox mode** (`sandbox.dataforseo.com`) for free testing.

## Requirements

- WordPress 6.5+ and PHP 8.0+.
- A DataForSEO account with API access. Use the API login and password from *API Access* in the DataForSEO dashboard.
- WP-Cron, or a system cron (see below).

## Installation

1. Copy the plugin folder to `wp-content/plugins/maps-grid-tracker/`, or upload a zip of it.
2. Activate it. Two tables are created: `{prefix}mgt_scans` and `{prefix}mgt_points`.
3. Open **Tools → Maps Grid → Settings** and fill in:
   - the API login and password;
   - the grid centre;
   - the keywords;
   - the business (use "Find business" to get the CID).
4. Click **Test connection** (free). It shows the account balance.
5. Run the first scan from the **Scans** tab.

## Cron

The plugin schedules the `mgt_tick` event every 5 minutes. Each tick starts a scan when the schedule is due and then collects ready results.

If your site sets `DISABLE_WP_CRON`, run WordPress cron from the system crontab:

```cron
*/5 * * * * cd /path/to/wordpress && flock -n /tmp/wp-cron.lock wp cron event run --due-now --quiet
```

You can also run only this plugin:

```cron
*/5 * * * * cd /path/to/wordpress && wp maps-grid tick --quiet
```

## WP-CLI

```bash
wp maps-grid status
```

```bash
wp maps-grid scan --yes
```

```bash
wp maps-grid tick
```

- `status` lists recent scans, the next scheduled scan and the last tick.
- `scan` starts a scan now. It costs money unless sandbox mode is on.
- `tick` starts a due scheduled scan and collects results.

## Cost

One scan sends *points × keywords* requests. For example, a 9×9 grid with 20 keywords is 1,620 requests.

The settings page estimates the cost from the price per request you enter. Check the Google Maps SERP standard-queue price in your DataForSEO price list. The real cost is read from the API and shown in the history.

## Data and privacy

- **API credentials** are stored in the `mgt_settings` option, which is not autoloaded. The password is never printed back into the admin page.
- **Results** are stored in the plugin's tables. For each point they keep the position plus the title and rating of the top 3 places.
- **Map tiles** in the admin come from OpenStreetMap. Leaflet 1.9.4 is bundled (BSD-2-Clause, see `assets/leaflet/LICENSE`).
- **Uninstalling** the plugin (deleting it in WordPress) removes the tables and options.

## Development

Translations are built from the `.pot` file with `python3 tools-dev/build-po.py`. This needs `msgcat` and `msgfmt` from gettext.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
