# Project Summary: Game Analytics Dashboard

_Last updated: 2026-10-05_

## What it is

A self-hosted game telemetry backend with an admin dashboard. Game clients send batches of events to a Laravel
ingestion API. A Vue dashboard reports on them:

- KPIs compared with the previous period;
- event trends and a live event stream;
- cohort retention;
- an ordered-funnel builder;
- per-player timelines.

The project is aimed at reviewers judging backend and data skills in a game context, without needing any gameplay
code. A seeded simulator generates 60 days of believable telemetry for a fictional game.

**Stack:** Laravel 13 · PHP 8.4 · Vue 3 + Vue Router · Tailwind 4 · SQLite (portable SQL only) · hand-built SVG charts

## What was built

### Backend
- **Ingestion API** (`POST /api/v1/events`):
  - batches of up to 500 events, authenticated by a hashed per-game key;
  - strict validation, including an ingestion window and a clock-skew check;
  - idempotent on a client `uuid`;
  - rate limited per game (the auth middleware is given priority so it runs before throttling).
- **`EventIngestor`:** writes the raw events and maintains three rollups (`players`, `player_activity_days`,
  `game_sessions`) in one transaction. Handles late events that move a player into an earlier cohort, and sessions
  split across batches.
- **Reports:** `OverviewReport`, `EventsReport`, `RetentionReport` and `FunnelReport`. Unobserved retention days are
  null and excluded from weighted averages. Funnels are ordered and windowed, count the best attempt, and give median
  time between steps plus a platform breakdown.
- **Admin JSON API:**
  - session auth with CSRF;
  - API resources and form requests;
  - game-scoped route bindings, so one game's funnels and players can't be read through another game's URL;
  - player search with escaped LIKE wildcards;
  - cursor-paginated timelines and event stream.
- **`GameSimulator`:** deterministic (seeded). Builds in power-curve retention, a paid-acquisition burst and a
  tutorial fix in client 1.4.0. Used by the seeder and by `php artisan analytics:simulate`, which sends live traffic
  either directly or through the real HTTP endpoint.

### Frontend
- **Seven pages:** Overview, Events (with a live tail), Retention, Funnels (builder with live preview), Players,
  Player profile, and Games & keys (key rotation and a curl example).
- **SVG charts:** line, column, bar list, funnel, cohort heatmap and stat tiles with sparklines. They have crosshair
  and per-mark tooltips, keyboard focus, and a table view for every chart.
- **Colour:**
  - the palette was validated for colour-blind separation;
  - each entity keeps the same colour (platforms, selected events);
  - dark mode uses its own chosen colours.
- One filter row scopes the whole page. Refetches dim the previous data rather than flashing. The layout is
  responsive down to phone width.

## Verification status

| Area | Status |
|---|---|
| PHPUnit suite | ✅ 55/55 passing (225 assertions) |
| Seeding: 2 games, ~268k events | ✅ ~2.3 min on SQLite |
| Report latency on seeded data (30-day range) | ✅ overview 0.11s · retention <0.01s · funnels 0.2–1.0s · events 0.2s |
| Live simulator through HTTP ingestion; wrong key → 401 | ✅ Verified |
| All pages in light, dark and 390px mobile: no console errors, no horizontal overflow | ✅ Checked with headless Edge screenshots |
| Large-scale volume (millions of events/day) | ⚠️ Not targeted. See next steps |

## Notes
- Times and dates are UTC calendar days throughout. The last day of a range is partial, and the DAU chart says so.
- Ingestion is synchronous. A batch of 500 commits in one transaction, so a retry after a timeout is safe.
- `php artisan serve` was run on port 8020 for verification, because another local project uses 8000.

## To-Dos
Loose ends in the current build, roughly in priority order:

- [ ] **Version control:** the project isn't a git repository yet. Run `git init` and make the first commit.
  `.gitignore` already excludes `vendor/`, `node_modules/`, `.env` and `database/database.sqlite`.
- [ ] **Commit the browser check:** the light/dark/mobile screenshot run was a one-off script outside the repo. Add it
  as `tests/e2e/smoke.mjs` with an `npm run test:e2e` script, the same pattern realtime-kanban uses
  (`playwright-core` + installed Edge).
- [ ] **Command test:** `analytics:simulate` was verified by hand (direct mode, HTTP mode, wrong key → 401) but has no
  automated test. Add one that uses `Http::fake()` for the `--http` path.
- [ ] **Faster seeding:** the seed takes about 2 minutes. Either let `GameSimulator::backfill` emit larger batches for
  the seeder only, or add a `--days` option so a quick demo can seed fewer days.
- [ ] **Live-simulator realism:** `liveBatch()` picks returning players from a small in-memory pool, so a long
  `analytics:simulate` run gives a few `live_*` players unrealistically many sessions per day. Cap sessions per
  player per day.
- [ ] **Concurrent-retry counts:** if the same batch arrives twice at the same moment, the second response can
  under-report `duplicates`. The stored data is still correct because of the unique index. Either document this in
  the API rules or work the count out from `insertOrIgnore`'s affected-row count.
- [ ] **Admin accounts:** the only way in is the seeded user. Add an `artisan make:admin` command, plus password
  reset or Fortify, before anyone else uses the dashboard.
- [ ] **Per-game permissions:** every signed-in admin can see every game. If the dashboard is ever multi-tenant, add a
  game–user pivot and a policy behind the existing `scopeBindings` routes.

## Next steps
New features once the to-dos are done:

1. Queue ingestion (write raw events, roll up in a job) and partition or archive `events` by month.
2. Property breakdowns (e.g. `level_fail` by `properties.level`) using generated columns or a property table.
3. Saved segments (platform, country, app version, payer) applied to every report.
4. CSV export from each table view, and scheduled email digests.
5. Funnel comparison: two date ranges side by side, e.g. before and after the 1.4.0 tutorial fix.
6. Revenue reports: LTV curves by cohort and a breakdown of purchases by item.
