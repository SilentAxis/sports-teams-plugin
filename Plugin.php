<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace AppLocalPlugins\SportsTeams;

use App\Jobs\ProcessEpgImport;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\Playlist;
use App\Plugins\Contracts\ChannelProcessorPluginInterface;
use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Contracts\ScheduledPluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use App\Services\EpgCacheService;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use DateTime;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sports Teams: official team logos and a per-team game-schedule guide for team
 * channels, built from ESPN's public site API (team list + team schedule).
 */
class Plugin implements ChannelProcessorPluginInterface, HookablePluginInterface, PluginInterface, ScheduledPluginInterface
{
    private const API = 'https://site.api.espn.com/apis/site/v2/sports';

    private const GUIDE_FILE = 'plugin-data/sports-teams/team-schedules.xml';

    /**
     * League key => [ESPN sport/league path, typical game length in hours].
     *
     * @var array<string, array{0: string, 1: float}>
     */
    private const LEAGUES = [
        'nhl' => ['hockey/nhl', 3.0],
        'nba' => ['basketball/nba', 2.5],
        'wnba' => ['basketball/wnba', 2.5],
        'mlb' => ['baseball/mlb', 3.0],
        'nfl' => ['football/nfl', 3.5],
        'mls' => ['soccer/usa.1', 2.0],
    ];

    /**
     * Former or alternate team names providers still use => [league, ESPN abbreviation].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ALIASES = [
        'arizona coyotes' => ['nhl', 'utah'],
        'utah hockey club' => ['nhl', 'utah'],
        'los angeles clippers' => ['nba', 'lac'],
        'oakland athletics' => ['mlb', 'ath'],
        'washington football team' => ['nfl', 'wsh'],
    ];

    /**
     * Teams no longer in ESPN's current team list whose logo is still published.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const EXTRA_TEAMS = [
        'connecticut sun' => ['wnba', 'Connecticut Sun', 'https://a.espncdn.com/i/teamlogos/wnba/500-dark/conn.png'],
    ];

    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return match ($action) {
            'health_check' => $this->healthCheck($context),
            'apply' => $this->apply($context, isset($payload['playlist_id']) && $payload['playlist_id'] ? [(int) $payload['playlist_id']] : null),
            default => PluginActionResult::failure("Unsupported action [{$action}]."),
        };
    }

    public function runHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        if ($hook !== 'playlist.synced') {
            return PluginActionResult::success("Hook [{$hook}] not handled by Sports Teams.");
        }

        $playlistId = (int) ($payload['playlist_id'] ?? 0);
        if (! in_array($playlistId, $this->configuredPlaylistIds($context->settings), true)) {
            return PluginActionResult::success("Playlist #{$playlistId} is not configured for Sports Teams - skipping.");
        }

        return $this->apply($context, [$playlistId]);
    }

    public function scheduledActions(CarbonInterface $now, array $settings): array
    {
        if (! ($settings['schedule_enabled'] ?? true) || $this->configuredPlaylistIds($settings) === []) {
            return [];
        }

        $cron = trim((string) ($settings['schedule_cron'] ?? '30 4 * * *'));
        if ($cron === '' || ! CronExpression::isValidExpression($cron) || ! (new CronExpression($cron))->isDue($now)) {
            return [];
        }

        return [['type' => 'action', 'name' => 'apply', 'payload' => ['source' => 'schedule'], 'dry_run' => false]];
    }

    private function healthCheck(PluginExecutionContext $context): PluginActionResult
    {
        $counts = [];
        foreach ($this->enabledLeagues($context->settings) as $league) {
            try {
                $counts[strtoupper($league)] = count($this->fetchTeams($league));
            } catch (Throwable $e) {
                $counts[strtoupper($league)] = 'error: '.$e->getMessage();
            }
        }

        $epg = Epg::where('name', $this->epgName($context->settings))->first();

        return PluginActionResult::success('Health check complete.', [
            'teams_per_league' => $counts,
            'configured_playlists' => $this->configuredPlaylistIds($context->settings),
            'guide_source' => $epg ? ['id' => $epg->id, 'channels' => DB::table('epg_channels')->where('epg_id', $epg->id)->count()] : null,
        ]);
    }

    /**
     * Fetch teams/schedules, then set logos, rebuild the guide and map channels.
     *
     * @param  array<int, int>|null  $playlistIds  null = configured playlists
     */
    private function apply(PluginExecutionContext $context, ?array $playlistIds): PluginActionResult
    {
        $settings = $context->settings;
        $dryRun = $context->dryRun;
        $playlistIds ??= $this->configuredPlaylistIds($settings);

        if ($playlistIds === []) {
            return PluginActionResult::failure('No playlist selected. Choose one or more playlists in the plugin settings.');
        }

        $playlists = Playlist::whereIn('id', $playlistIds)->get(['id', 'name', 'user_id']);
        if ($context->user) {
            $playlists = $playlists->where('user_id', $context->user->id);
        }
        if ($playlists->isEmpty()) {
            return PluginActionResult::failure('None of the selected playlists were found.');
        }

        // 1. Teams (+ schedules when building the guide)
        $buildGuide = (bool) ($settings['build_guide'] ?? true);
        $teams = [];
        foreach ($this->enabledLeagues($settings) as $league) {
            try {
                foreach ($this->fetchTeams($league) as $team) {
                    if ($buildGuide) {
                        $team['games'] = $this->fetchGames($league, $team['id']);
                        usleep(100000);
                    }
                    $teams[$team['channel_id']] = $team;
                }
                $context->info(sprintf('%s: %d teams loaded.', strtoupper($league), count(array_filter($teams, fn ($t) => $t['league'] === $league))));
            } catch (Throwable $e) {
                $context->warning(sprintf('%s: could not load teams (%s).', strtoupper($league), $e->getMessage()));
            }
        }
        if ($teams === []) {
            return PluginActionResult::failure('Could not load any teams from ESPN.');
        }
        $context->heartbeat('Teams loaded.', 30);

        // 2. Match team channels
        $matches = $this->matchChannels($playlists->pluck('id')->all(), $teams, (string) ($settings['group_filter'] ?? 'TEAM'));
        $context->info(sprintf('%d team channel(s) matched; %d team-like channel(s) not matched.', count($matches['matched']), count($matches['unmatched'])));
        foreach (array_slice(array_unique($matches['unmatched']), 0, 15) as $title) {
            $context->info("  not matched: {$title}");
        }

        // 3. Logos
        $logosSet = 0;
        if ($settings['set_logos'] ?? true) {
            $variant = ($settings['logo_variant'] ?? 'dark') === 'default' ? 'logo' : 'logo_dark';
            foreach ($matches['matched'] as $channelId => $teamKey) {
                $url = $teams[$teamKey][$variant] ?: $teams[$teamKey]['logo'];
                if (! $url || ($matches['logos'][$channelId] ?? null) === $url) {
                    continue;
                }
                if (! $dryRun) {
                    DB::table('channels')->where('id', $channelId)->update(['logo' => $url, 'updated_at' => now()]);
                }
                $logosSet++;
            }
            $context->info(($dryRun ? '[dry run] would set' : 'Set')." {$logosSet} team logo(s).");
        }
        $context->heartbeat('Logos done.', 50);

        // 4. Guide
        $mapped = 0;
        $guide = null;
        if ($buildGuide) {
            $guide = $this->writeGuide($teams, $settings, $dryRun);
            $context->info(sprintf('%s guide with %d team channel(s) and %d programme(s).', $dryRun ? '[dry run] would write' : 'Wrote', $guide['channels'], $guide['programmes']));

            if (! $dryRun) {
                $epg = $this->importGuide($playlists->first()->user_id, $settings, $context);
                if ($epg) {
                    $epgChannelIds = DB::table('epg_channels')->where('epg_id', $epg->id)->pluck('id', 'channel_id');
                    foreach ($matches['matched'] as $channelId => $teamKey) {
                        $epgChannelId = $epgChannelIds[$teamKey] ?? null;
                        if ($epgChannelId && (int) ($matches['epg'][$channelId] ?? 0) !== (int) $epgChannelId) {
                            DB::table('channels')->where('id', $channelId)->update(['epg_channel_id' => $epgChannelId, 'epg_map_enabled' => true]);
                            $mapped++;
                        }
                    }
                    $context->info("Mapped {$mapped} team channel(s) to the guide.");
                }
            }
        }

        if (! $dryRun && ($logosSet || $mapped || $buildGuide)) {
            foreach ([Playlist::all(), CustomPlaylist::all()] as $set) {
                foreach ($set as $playlist) {
                    EpgCacheService::clearPlaylistEpgCacheFile($playlist);
                }
            }
        }

        return PluginActionResult::success(sprintf(
            '%s%d team channel(s): %d logo(s) %s, %d guide mapping(s) %s.',
            $dryRun ? '[dry run] ' : '',
            count($matches['matched']),
            $logosSet,
            $dryRun ? 'to set' : 'set',
            $mapped,
            $dryRun ? 'skipped' : 'updated'
        ), [
            'matched' => count($matches['matched']),
            'unmatched' => array_values(array_unique($matches['unmatched'])),
            'logos_set' => $logosSet,
            'guide' => $guide,
            'mapped' => $mapped,
            'dry_run' => $dryRun,
        ]);
    }

    /**
     * @return array<int, array{channel_id: string, league: string, id: string, abbr: string, name: string, short: string, logo: ?string, logo_dark: ?string}>
     */
    private function fetchTeams(string $league): array
    {
        [$path] = self::LEAGUES[$league];
        $response = Http::timeout(30)->retry(2, 2000)->get(self::API."/{$path}/teams");
        if (! $response->ok()) {
            throw new \RuntimeException("HTTP {$response->status()}");
        }

        $teams = [];
        foreach ($response->json('sports.0.leagues.0.teams') ?? [] as $row) {
            $team = $row['team'] ?? [];
            if (empty($team['abbreviation'])) {
                continue;
            }
            $logos = collect($team['logos'] ?? []);
            $abbr = strtolower($team['abbreviation']);
            $teams[] = [
                'channel_id' => "espn.{$league}.{$abbr}",
                'league' => $league,
                'id' => (string) $team['id'],
                'abbr' => $abbr,
                'name' => (string) $team['displayName'],
                'short' => (string) ($team['shortDisplayName'] ?? $team['displayName']),
                'logo' => $logos->first(fn ($l) => in_array('default', $l['rel'] ?? [], true))['href'] ?? $logos->first()['href'] ?? null,
                'logo_dark' => $logos->first(fn ($l) => in_array('dark', $l['rel'] ?? [], true))['href'] ?? null,
            ];
        }

        foreach (self::EXTRA_TEAMS as $key => [$extraLeague, $name, $logo]) {
            if ($extraLeague === $league && ! collect($teams)->contains('name', $name)) {
                $slug = preg_replace('/[^a-z0-9]+/', '', $key);
                $teams[] = ['channel_id' => "espn.{$league}.{$slug}", 'league' => $league, 'id' => '', 'abbr' => $slug, 'name' => $name, 'short' => $name, 'logo' => $logo, 'logo_dark' => $logo];
            }
        }

        return $teams;
    }

    /**
     * @return array<int, array{start: int, stop: int, title: string, opponent: string, desc: string}>
     */
    private function fetchGames(string $league, string $teamId): array
    {
        if ($teamId === '') {
            return [];
        }
        [$path, $hours] = self::LEAGUES[$league];
        $events = Http::timeout(30)->retry(2, 2000)->get(self::API."/{$path}/teams/{$teamId}/schedule")->json('events') ?? [];

        $games = [];
        foreach ($events as $event) {
            $start = strtotime((string) ($event['date'] ?? ''));
            $competition = $event['competitions'][0] ?? [];
            $state = $competition['status']['type']['name'] ?? '';
            if (! $start || in_array($state, ['STATUS_POSTPONED', 'STATUS_CANCELED'], true)) {
                continue;
            }
            $competitors = collect($competition['competitors'] ?? []);
            $self = $competitors->first(fn ($c) => (string) ($c['team']['id'] ?? '') === $teamId);
            $other = $competitors->first(fn ($c) => (string) ($c['team']['id'] ?? '') !== $teamId)['team'] ?? [];
            $broadcasts = collect($competition['broadcasts'] ?? [])->map(fn ($b) => $b['media']['shortName'] ?? null)->filter()->unique()->implode(', ');
            $games[] = [
                'start' => $start,
                'stop' => $start + (int) ($hours * 3600),
                'title' => (string) ($event['name'] ?? ''),
                'opponent' => (($self['homeAway'] ?? '') === 'home' ? 'vs ' : '@ ').($other['shortDisplayName'] ?? $other['displayName'] ?? 'TBD'),
                'desc' => trim(implode('. ', array_filter([$event['seasonType']['name'] ?? null, $competition['venue']['fullName'] ?? null, $broadcasts ? "Broadcast: {$broadcasts}" : null]))),
            ];
        }
        usort($games, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $games;
    }

    /**
     * @param  array<int, int>  $playlistIds
     * @param  array<string, array<string, mixed>>  $teams
     * @return array{matched: array<int, string>, unmatched: array<int, string>, logos: array<int, ?string>, epg: array<int, ?int>}
     */
    private function matchChannels(array $playlistIds, array $teams, string $groupFilter): array
    {
        $index = [];
        foreach ($teams as $key => $team) {
            $index[$this->normalize($team['name'])] = $key;
        }
        foreach (self::ALIASES as $alias => [$league, $abbr]) {
            if (isset($teams["espn.{$league}.{$abbr}"])) {
                $index[$alias] = "espn.{$league}.{$abbr}";
            }
        }
        uksort($index, fn ($a, $b) => strlen($b) <=> strlen($a));

        $query = DB::table('channels')->whereIn('playlist_id', $playlistIds)->where('is_vod', false)->where('enabled', true)->where('title', 'not like', '#%');
        if (trim($groupFilter) !== '') {
            $query->where('group', 'ilike', '%'.trim($groupFilter).'%');
        }

        $result = ['matched' => [], 'unmatched' => [], 'logos' => [], 'epg' => []];
        foreach ($query->get(['id', 'title', 'logo', 'epg_channel_id']) as $channel) {
            $name = $this->normalize((string) $channel->title);
            if ($name === '' || preg_match('/\b(vs|v|x|at)\b|@|\d{4} \d{2} \d{2}|replay|info|network|league pass|tubi|prime|no event|redzone/u', $name)) {
                continue;
            }
            $hit = null;
            foreach ($index as $teamName => $key) {
                if ($name === $teamName || preg_match('/\b'.preg_quote($teamName, '/').'\b/', $name)) {
                    $hit = $key;
                    break;
                }
            }
            if ($hit) {
                $result['matched'][$channel->id] = $hit;
                $result['logos'][$channel->id] = $channel->logo;
                $result['epg'][$channel->id] = $channel->epg_channel_id;
            } else {
                $result['unmatched'][] = trim((string) $channel->title);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array<string, mixed>>  $teams
     * @return array{channels: int, programmes: int, path: string}
     */
    private function writeGuide(array $teams, array $settings, bool $dryRun): array
    {
        $tz = new DateTimeZone($this->validTimezone((string) ($settings['timezone'] ?? 'America/Toronto')));
        $from = intdiv(time() - 12 * 3600, 3600) * 3600;
        $to = time() + max(1, min(30, (int) ($settings['guide_days'] ?? 14))) * 86400;
        $esc = fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $fmt = fn (int $t) => gmdate('YmdHis', $t).' +0000';

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<tv generator-info-name=\"m3u-editor sports-teams plugin\">\n";
        $programmes = '';
        $count = 0;
        foreach ($teams as $key => $team) {
            $icon = $team['logo_dark'] ?: $team['logo'];
            $xml .= "  <channel id=\"{$esc($key)}\"><display-name>{$esc($team['name'])}</display-name>".($icon ? "<icon src=\"{$esc($icon)}\"/>" : '')."</channel>\n";

            $games = $team['games'] ?? [];
            $inWindow = array_values(array_filter($games, fn ($g) => $g['stop'] > $from && $g['start'] < $to));
            $cursor = $from;
            foreach (array_merge($inWindow, [null]) as $game) {
                $gapEnd = $game ? max($cursor, $game['start']) : $to;
                while ($cursor < $gapEnd) {
                    $blockEnd = min($gapEnd, (intdiv($cursor, 6 * 3600) + 1) * 6 * 3600);
                    $next = collect($games)->first(fn ($g) => $g['start'] >= $cursor);
                    $title = $next ? 'Next: '.$next['opponent'].' – '.(new DateTime('@'.$next['start']))->setTimezone($tz)->format('D M j, g:i A') : 'No games scheduled';
                    $desc = $next ? $next['title'].'. '.$next['desc'] : $team['name'].' — no upcoming games found.';
                    $programmes .= "  <programme start=\"{$fmt($cursor)}\" stop=\"{$fmt($blockEnd)}\" channel=\"{$esc($key)}\"><title>{$esc($title)}</title><desc>{$esc($desc)}</desc><category>Sports</category></programme>\n";
                    $count++;
                    $cursor = $blockEnd;
                }
                if ($game) {
                    $start = max($game['start'], $cursor);
                    if ($game['stop'] > $start) {
                        $programmes .= "  <programme start=\"{$fmt($start)}\" stop=\"{$fmt($game['stop'])}\" channel=\"{$esc($key)}\"><title>{$esc($game['title'])}</title><desc>{$esc($game['desc'])}</desc><category>Sports</category></programme>\n";
                        $count++;
                    }
                    $cursor = max($cursor, $game['stop']);
                }
            }
        }
        $xml .= $programmes."</tv>\n";

        if (! $dryRun) {
            Storage::disk('local')->put(self::GUIDE_FILE, $xml);
        }

        return ['channels' => count($teams), 'programmes' => $count, 'path' => self::GUIDE_FILE];
    }

    private function importGuide(int $userId, array $settings, PluginExecutionContext $context): ?Epg
    {
        $name = $this->epgName($settings);
        $epg = Epg::where('name', $name)->where('user_id', $userId)->first();
        if (! $epg) {
            $epg = new Epg(['name' => $name]);
            $epg->user_id = $userId;
            $epg->auto_sync = false;
            $context->info("Creating guide source '{$name}'.");
        }
        $epg->uploads = self::GUIDE_FILE;
        $epg->save();

        try {
            dispatch_sync(new ProcessEpgImport($epg->fresh(), true));
            $deadline = time() + 600;
            do {
                sleep(3);
                $epg->refresh();
                $status = is_object($epg->status) ? $epg->status->value : (string) $epg->status;
            } while (! in_array($status, ['completed', 'failed'], true) && time() < $deadline);
        } catch (Throwable $e) {
            $context->error('Guide import failed: '.$e->getMessage());

            return null;
        }

        if ($status !== 'completed') {
            $context->error("Guide import did not complete (status: {$status}).");

            return null;
        }

        return $epg;
    }

    private function normalize(string $value): string
    {
        $value = (string) preg_replace('/[\x{02B0}-\x{02FF}\x{1D2C}-\x{1DBF}\x{2070}-\x{209F}\x{2C7C}\x{2C7D}]+/u', ' ', $value);
        $value = mb_strtolower($value, 'UTF-8');
        $value = (string) preg_replace('/^\s*(?:[a-z]{2}(?:\s+(?:en|fr))?|nhl|nba|wnba|mlb|nfl|mls)\s*[:|]\s*/u', '', $value);
        $value = str_replace(['st.', 'saint '], ['st', 'st '], $value);
        $value = (string) preg_replace('/\b(hd|fhd|uhd|4k|8k|raw|60fps|sd)\b|\(.*?\)/u', ' ', $value);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    /**
     * @return array<int, int>
     */
    private function configuredPlaylistIds(array $settings): array
    {
        return array_values(array_map('intval', array_filter((array) ($settings['playlist_ids'] ?? []))));
    }

    /**
     * @return array<int, string>
     */
    private function enabledLeagues(array $settings): array
    {
        $leagues = array_map('strtolower', (array) ($settings['leagues'] ?? ['nhl', 'nba', 'wnba', 'mlb']));

        return array_values(array_intersect($leagues, array_keys(self::LEAGUES)));
    }

    private function epgName(array $settings): string
    {
        return trim((string) ($settings['epg_name'] ?? '')) ?: 'Team Schedules (ESPN)';
    }

    private function validTimezone(string $timezone): string
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }
}
