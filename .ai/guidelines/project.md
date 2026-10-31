# Game Analytics Dashboard: notes for contributors and coding agents

A self-hosted game analytics backend: a Laravel 13 (PHP 8.4) ingestion API plus a Vue 3 / Vue Router / Tailwind 4 admin
SPA with hand-built SVG charts. `README.md` explains the design and its trade-offs. Read it before changing ingestion,
reports or demo mode.

## Commands

```sh
composer run dev                       # app server, queue listener and Vite
php artisan test                       # PHPUnit feature tests
vendor/bin/pint                        # format PHP (CI runs vendor/bin/pint --test)
npm run build                          # production assets
npm run test:e2e                       # browser smoke test; needs a seeded app already serving
DEMO_DAYS=14 php artisan migrate:fresh --seed   # quick seed; the default 60 days takes a minute or two
```

## Invariants to keep

- **Every event goes through `App\Ingestion\EventIngestor::ingest()`.** The HTTP endpoint, `analytics:simulate`,
  `DemoDataset::replay()` and the tests' `SendsEvents::ingest()` all use it. It writes `events` and the rollups
  (`players`, `player_activity_days`, `game_sessions`) in one transaction. Never write these tables directly, or the
  rollups the reports read drift from the raw events.
- **Idempotency depends on the lock order in `ingest()`.** It locks the players (`lockForUpdate`), then repeats the
  uuid check with a `sharedLock` before counting anything. Counters are `col = col + n`, never read-modify-write.
  Keep that order if you add a counter or a rollup, or a concurrent retry gets counted twice. A late event moves the
  player's `first_seen_on` earlier, and `recordActivityDays()` re-indexes their existing activity days.
- **Portable SQL only.** UTC dates are denormalised into `*_on` columns at write time (`occurred_on`, `activity_on`,
  `started_on`, `first_seen_on`). Reports group and filter on those columns. No `DATE()`, `date_trunc`, window
  functions or other driver-specific SQL. Logic that is hard to express portably runs in PHP over a `cursor()`
  (see `FunnelReport`).
- **Report maths lives in `App\Analytics\*`, not in controllers.** Date ranges are `DateRange` (inclusive UTC days,
  at most `DateRange::MAX_DAYS`), built by `ReportRequest::range()`. In retention, days that haven't happened yet are
  `null`, not `0`, and are left out of the averages.
- **Admin routes on a game sit inside the `games/{game}` `scopeBindings()` group** in `routes/web.php`, so one game's
  funnels and players can't be reached through another game's URL. Raw `DB::table()` queries in reports must filter
  on `game_id` (or on an already scoped player) themselves.
- **The ingest game comes from `AuthenticateIngestKey`.** `bootstrap/app.php` runs it before `ThrottleRequests`, so
  the `ingest` limiter counts per game, not per IP. Keys are stored only as SHA-256 hashes; the plaintext from
  `Game::rotateIngestKey()` is returned once.
- **Demo mode is read-only.** Every new admin write route needs the `demo.read-only` middleware and an entry in
  `DemoModeTest::writeEndpoints()`. The funnel preview is a GET on purpose, so it keeps working in the demo.
- **The simulator is deterministic.** `GameSimulator` draws every random value, uuids included, from its seeded
  `Randomizer`, and takes "now" as an argument. Never use `Str::uuid()`, `rand()` or `now()` inside it.
  `analytics:demo-refresh` replays the same history and ingests only events after the last stored timestamp, with uuid
  deduplication as the backstop. `RefreshDemoCommandTest` checks that incremental runs match a fresh build.
- **Demo rebuilds stay hidden until they are finished.** A rebuild is a separate `Game` with `building = true` and a
  `-next` slug. `DemoDataset::publish()` swaps it in within one transaction. Game listings must use
  `Game::visible()`. Replay progress is stored in the cache (`demo:replayed:{id}`), so a hosted demo needs a
  persistent `CACHE_STORE` (it uses `database`).

## Conventions

- Freeze time in tests with `travelTo()`. Build payloads with `Tests\Concerns\SendsEvents::event()`.
- Test races deterministically. `EventIngestionTest` uses `DB::listen` to commit a competing retry at the moment the
  request reads the players.
