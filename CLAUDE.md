# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A PHP toy AI simulation: a cat (`Chat`) wanders a tile map, gets hungry, walks to the nearest flower and eats it. It renders as a full-screen terminal dashboard — map, live stats, log pane, time-machine gauge, help. The domain vocabulary is French (`Chat`, `Estomac` = stomach, `Nouriture` = food, `Objectif` = goal); keep new code and comments in English unless extending an existing French-named concept.

## Running

```bash
make run      # play (full screen, needs a real TTY)
make test     # phpunit
make logs     # tail app/log/dev.log from another terminal
make shell    # shell in the container
make help     # all targets
```

Keys: `space` play/pause, `n` one tick, `-`/`+` speed, `t` time machine then `p`/`a` to browse snapshots, `tab` or `1`..`9` to switch AI panel, `r` new map, `x` persist memory, `q` quit (`b`/`s` are kept as pause/play aliases). The game starts paused; `--play` starts it running.

In raw mode Ctrl+C is delivered as a key event, not a signal — quit with `q`. If the process is killed from outside, the tty is left raw: run `reset`.

Docker runs `php:8.4-cli`; nothing but `ext-intl` is compiled. Since the renderer is pure PHP, `php ./console` also works on any host PHP 8.1+ — Docker is convenience, not a requirement.

## Rendering: php-tui, not ncurses

The ncurses PECL extension was abandoned in 2012 and never ported past PHP 7, which used to pin this project to PHP 7.2 and a dead Debian image. It was replaced by [php-tui](https://php-tui.github.io/php-tui) (a Ratatui port) which emits ANSI escapes from pure PHP.

`TuiRender` is the only class that talks to the terminal. It builds a `Display` through `DisplayBuilder::default($backend)->fullscreen()`, then takes the alternate screen and raw mode in `init()` and gives them back in `close()`. **The alternate screen is what keeps the UI a fixed dashboard rather than output scrolling under the shell prompt** — if you touch `init()`/`close()`, keep the ordering (alternate screen before raw mode, reverse on the way out) and keep `close()` in a `finally`.

Nothing may write to stdout while the game runs: a stray notice lands inside the alternate screen and corrupts the frame. `console` sets `error_reporting(E_ALL & ~E_DEPRECATED)` because php-tui/term still declares implicitly nullable parameters, which PHP 8.4 reports when the class loads.

The frame is three rows: map + sidebar, log pane, control bar. The sidebar stacks an AI panel — a `TabsWidget` with one tab per player, the selected one detailing its stomach gauge and the descriptions returned by `ObjectifInterface::describe()` — over a live memory panel. The control bar owns everything time-related: play/pause, speed, and the snapshot gauge that only appears in time-machine mode. Tab selection lives in `TuiRender` (`nextTab`/`selectTab`); the runner just forwards keys.

## Memory and exit 137

Two ceilings can stop the game and they fail differently. PHP's `memory_limit` raises a catchable fatal error; the container cgroup limit makes the kernel SIGKILL the process, which is the **exit 137 with no stack trace and nothing in the log**. `Runtime\MemoryUsage` reads both (cgroup v2 then v1, `null` when uncapped) plus the peak, and `FlashMemory::bytes()` reports the snapshot ring — by far the biggest thing this program holds.

Three mitigations are in place: the sidebar panel shows both gauges live, `GameRunner::checkMemory()` writes a reading to `dev.log` every 50 ticks (a warning past 80% of a known limit) so there is a trace *before* the kill, and `docker-compose.yml` sets `mem_limit: 512m` on purpose — an uncapped container takes the whole Docker VM down instead of failing visibly.

`GameRunner::SNAPSHOT_EVERY` keeps the ring from being rewritten on every tick while playing: at x4 that would serialize the world a hundred times a second for a tenth of a second of history.

Players are stamped on their own layer as `1`..`9`, not a shared `P`, so each cat gets its own glyph and colour — two cats used to be the same indistinguishable letter.

**A tile spans two columns** (`TilePalette::TILE_WIDTH`). A terminal cell is about twice as tall as it is wide, so one cell per tile squashed the map vertically — round lakes came out as ovals and a diagonal step looked like 27 degrees rather than 45. `getSize()` therefore reports half as many tiles across as there are columns.

**Nothing in the UI may be two columns wide — emoji included.** php-tui's paragraph rendering stores one grapheme per cell without accounting for its display width (`Buffer::putString` handles it, the paragraph path does not), so an emoji takes one cell and two columns: everything after it on that row shifts right and the block border lands one column off. This bites in the side panels exactly as much as on the grid, which is not obvious — measuring the character in isolation says nothing. `testNoRowIsWiderThanTheScreen` renders a full frame and asserts every row is exactly the screen width; it is the test that was missing when emoji were first tried.

`TilePalette` decides how a tile is painted. Terrain is the cell **background**, not a coloured character, so ground reads as solid areas and the glyph stays free for what stands on it — grass and water are plain spaces. Each terrain has four shades picked by hashing the tile coordinates: one flat colour looks like paint, and a shade redrawn at random every frame would make the map shimmer. The hash must avalanche — the first attempt used `crc32`, which is linear, so shades repeated every four tiles and wove visible diagonal stripes across the meadow (`testTheGrainRepeatsNoVisiblePattern` guards it).

True colour is not universal, so `TilePalette::detect()` reads `COLORTERM` and falls back to sixteen ANSI colours without pretending to have shades. `docker-compose.yml` forwards `TERM` and `COLORTERM` from the host: without that the container advertises nothing and the fallback is all you ever get.

Renderers are swappable through `MapRenderInterface`. Tests use php-tui's own doubles — `DummyBackend`, `StringWriter`, `TestRawMode`, `SizeFromEnvVarProvider` — so frames render headlessly and can be asserted as strings (see `tests/Map/Render/TuiRenderTest.php`).

## Architecture

Entry: `console` → `ApplicationConsole` (single-command Symfony app) → `Command\ApplicationCommand` → `GameRunner`.

`GameRunner` owns the loop and all terminal-facing services (logger, renderer, input, memory). Each iteration: drain input → handle keys → `advance()`. Key bindings live in one `match` in `pumpInput()`; each handler returns whether a redraw is needed. `World::update()` self-throttles to 15 updates/second and returns `false` when it skipped, so the frame is only redrawn on a real tick.

`Runtime\TimeControl` is the single source of truth for paused/speed/time-machine, shared by the loop and the control bar so neither has to reach into the other. `advance()` computes nothing while the time machine is on — the world on screen is a restored snapshot.

### Speed

The ladder runs x0.25 to x1000. Below the render rate, speed is the sleep between frames; above it there is no sleep left to shave, so **speed becomes ticks per frame** (`ticksPerFrame()`), rendering stays at 15fps and the simulation batches. `World::update()` no longer paces itself — a domain object calling `usleep()` capped everything above x1.

Three things scale with the batch rather than the tick: the logger is muted for all but the last tick of a batch (thousands of lines per frame would cost more than the simulation), snapshots are taken per *frame* (`SNAPSHOT_EVERY_FRAMES`), and `advance()` sleeps only the remainder of the frame budget — sleeping a full delay after a late frame is how a loop that is merely behind becomes hopelessly behind.

Asking for x1000 does not make the machine deliver it, so `observe()` records the rate actually reached and the control bar turns red with the real multiplier. Measured here: ~20k ticks/s in isolation (x1300), ~x360 through the full render loop. `--speed N` starts at a given rung, which is also how that gets measured.

**World is the simulated state.** Map, players, timer, and the per-player AI. Services are injected and explicitly excluded from `__sleep`. Input is drained by the loop, never by `World` — both draining the same event stream would make each miss half the key presses.

**AI is per-player and event-driven.** `ApplicationIA` walks every player, calls its AI, then its `update()`, then enforces where it may stand — clamped to the map and reverted if it landed on an impassable tile. That is the single authority on position, whatever moved the player. `Estomac` emits `HungryEvent`/`FullEvent` each tick; `CatIA` turns hungry into a `Manger` goal and drops all goals when full. **Goals own movement while active**; the free-roam `move()` (keyboard direction) only runs when there is no goal. Add a behaviour by writing an `ObjectifInterface` under `src/IA/Objectif/` and subscribing it to an event in the AI class.

### Pathfinding

`PathFinder` works on integers over a flat cost grid (`MapBuilder::costGrid()`), never on `Point` objects: the first version allocated about two dozen of them per expanded tile, and a search that found nothing — having to visit the whole map — took 50ms, capping the simulation at x40 as soon as food ran out. `Manger` also waits `RETRY_EVERY` ticks before searching again after a failure, since eating only ever removes flowers and the answer can hardly turn positive on its own.

`MapBuilder::cost()` gives each tile a walking cost — grass and flowers 1, undergrowth 3, water impassable (`null`). Read it with `array_key_exists`, never `??`: an impassable tile has a *null* cost, which `??` would silently replace with the default.

`PathFinder` is a uniform cost search. Finding the closest flower and routing to it is one problem, so it is one flood: expand by cost until a tile matches. That beats running A* once per candidate flower, and it answers "the nearest one I can reach" rather than "the nearest as the crow flies" — rarely the same tile now that lakes block movement.

Diagonal steps cost 14 against 10 for straight ones. Charged equally, a sideways detour ties with the straight line and the cat wanders for no reason. A diagonal also may not slip between two touching lakes.

`Manger` holds a `Route` — a precomputed list of tiles walked one per tick — and re-routes when it ends. A route re-checks each tile before stepping on it, because the map changes underneath: another cat may have eaten the target flower. Players are spawned through `MapBuilder::nearestWalkable()`, otherwise a cat dropped on a lake is stuck for good.

**Map is layered.** `MapBuilder` holds named layers (`map`, `player`) flattened into a final layer; renderers only see `getFinalMap()`. Tiles are single chars: `X` grass, `Y` tree, `E` water, `F` flower. Providers implement `MapProviderInterface`: `TerrainMapProvider` and `FileMapProvider` (`app/map/terre.txt`, mapping `░✿↟∼` back to `XFYE`).

### Terrain generation

`TerrainMapProvider` builds two fractal value-noise fields — elevation and bloom — and cuts the terrain out of them: low ground is water, high ground is forest, the rest grass, and grass blooms where the second field peaks. Continuous fields are what make regions contiguous, which the previous per-cell random draw could never produce.

The cuts are **quantiles, not fixed thresholds**. Asking for "the lowest 18%" yields the same coverage on every seed; a fixed cut on a normalized field swung between 19% and 37% water depending on how the noise fell. Terrain shares (`WATER_SHARE`, `FOREST_SHARE`, `FLOWER_SHARE`) are therefore a contract, asserted in `TerrainMapProviderTest`. Flowers are ranked among grass cells only, so their share does not shrink on a lake-heavy map — the cat always has food.

Generation is seeded through `Random\Randomizer` (no global `mt_srand`), so `--seed N` replays a map exactly and the tests can assert on shapes. Coherence itself is tested by measuring clustering — the fraction of water tiles touching another water tile — against the same tiles shuffled.

Terrain drives movement: see Pathfinding below.

**Time machine = serialization.** `Snapshot\Instant` serializes a `World`; `FlashMemory` keeps a ring of 10 with a read cursor. Restoring swaps the live world and re-injects logger and input controller (`GameRunner::setWorld`).

Serialization is the sharp edge of this codebase. Anything added to `World` or a player must be serializable or excluded via `__sleep`. In particular, the `Estomac` event dispatcher holds closures bound to `CatIA`: it is dropped on sleep, `CatIA::__wakeup` re-subscribes, and `Estomac::getEventDispatcher()` builds it lazily because wake-up order between the two is not guaranteed. `tests/Snapshot/TimeMachineTest.php` covers exactly this.

## Tests

`make test` — 19 tests covering map queries and bounds, cat behaviour end-to-end (walks, eats, turns the flower to grass), snapshot round-trips, and headless frame rendering. `tests/WorldFactory.php` builds worlds from ASCII rows so nothing depends on the random provider.

`phpunit.xml.dist` fails on warnings, notices and deprecations, but `ignoreIndirectDeprecations` keeps vendor deprecations from failing the suite.

## Leftovers

`test*.php` and `testhorsia.php` at the repo root, `.tt.txt.swp`, and `10-powerline-symbols.conf` are pre-existing scratch files, unrelated to the app. `dev.sh` and `dev/` implement a watch-and-restart loop that assumes Linux (`inotifywait`) and a container name that no longer exists.
