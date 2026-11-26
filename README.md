# Sports Teams (m3u-editor plugin)

Gives team channels (for example `US: TORONTO MAPLE LEAFS` or `NBA: TORONTO RAPTORS ᴴᴰ`) their
official logo and a guide showing each team's upcoming games, using ESPN's public team and
schedule data. Supports NHL, NBA, WNBA, MLB, NFL and MLS.

## What it does

1. **Logos:** sets each matched team channel's logo to the team's official logo. It uses the
   version made for dark backgrounds by default, which suits TV apps. The logo is stored in the
   channel's own logo field, so provider syncs don't overwrite it.
2. **Guide:** writes an XMLTV guide (`plugin-data/sports-teams/team-schedules.xml`). Each team
   gets its games for the next 14 days (configurable), with broadcasters and venue, and
   `Next: @ Bruins – Fri Nov 27, 1:00 PM` filler in between. The guide is registered as an EPG
   source (default name **Team Schedules (ESPN)**), imported, and matched team channels are
   mapped to their team.
3. **Matching:** strips provider prefixes (`US:`, `NBA:`, `CA EN:`…), quality tags (`HD`, `ᴴᴰ`,
   `ᴿᴬᵂ`…) and bracketed text, then matches the full team name. Game and event channels (`A vs B`,
   `@`, dates, replays, RedZone, League Pass) are skipped. Renamed teams are handled, for
   example Arizona Coyotes → Utah Mammoth and Los Angeles Clippers → LA Clippers.

## When it runs

- After each sync of the playlists selected in the settings (`playlist.synced` hook).
- On its own schedule (default `30 4 * * *`), so the guide stays current between syncs.
- Manually: **Update Team Logos & Guide**, with a dry-run option. **Health Check** reports the
  team count for each league and the guide source.

## Settings

| Setting | Default | Notes |
|---|---|---|
| Playlists | – | Provider playlists to process. Custom playlists built from them inherit the result. |
| Leagues | NHL, NBA, WNBA, MLB | Add NFL or MLS if you have team channels for them. |
| Only channels whose group contains | `TEAM` | Empty = every enabled live channel. |
| Set official team logos / Logo variant | on / dark | |
| Build the team schedule guide | on | |
| Guide source name | Team Schedules (ESPN) | |
| Days of schedule | 14 | 1–30 |
| Time zone for "Next game" times | America/Toronto | Game times in the guide itself are stored in UTC. |
| Refresh on a schedule / cron | on / `30 4 * * *` | |

## Install

**From a release (recommended):** take the zip URL and SHA-256 from the
[latest release](https://github.com/SilentAxis/sports-teams-plugin/releases/latest). Then either
use **Extensions → Plugin Installs → Install from GitHub release** in m3u-editor, or run this in
the container:

```bash
php artisan plugins:stage-github-release \
  https://github.com/SilentAxis/sports-teams-plugin/releases/download/<tag>/sports-teams-<tag>.zip \
  --sha256=<sha256>
php artisan plugins:scan-install <review-id>
php artisan plugins:approve-install <review-id> --trust
```

**From source:** copy this folder somewhere the container can read, then run
`php artisan plugins:stage-directory /path/to/sports-teams-plugin` and the same scan and approve
steps.

After installing, enable the plugin, pick your playlist(s) in its settings, and run
**Update Team Logos & Guide** once. Use a dry run first if you want to preview it.

## Development

- `php scripts/validate-plugin.php`: checks the manifest and entrypoint, and that a release tag
  matches `version` in `plugin.json`.
- `bash scripts/package-plugin.sh [name.zip]`: builds the installable zip and its `.sha256` in
  `dist/`.
- Pushing a `v*` tag runs CI, which validates the files, packages the zip and publishes a GitHub
  release with the install command and checksum.

## Notes

- ESPN's site API is public and unauthenticated, but undocumented, so its format may change.
  Failures are logged per league and don't stop the other leagues.
- The plugin never changes channels outside the selected playlists.
- Team names and logos are trademarks of their respective leagues and teams. Logos are
  referenced from ESPN's CDN, not redistributed.

## License

[GNU Affero General Public License v3.0](LICENSE)
