# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A PHP toy AI simulation: a cat (`Chat`) wanders a tile map, gets hungry, walks to the nearest flower and eats it. It renders as a full-screen terminal dashboard — map, live stats, log pane, time-machine gauge, help. The domain vocabulary is French (`Chat`, `Estomac` = stomach, `Nouriture` = food, `Objectif` = goal); keep new code and comments in English unless extending an existing French-named concept.

## What it is actually for

Two things at once, and most decisions here only make sense against them.

**A sandbox where behaviour comes out of incomplete perception.** Until cats were given a limited field of view they knew where every flower on the map was, which makes an optimiser rather than an agent: there is nothing to watch, only a shortest path unrolling. Perception is now local, so a cat explores because it does not know, misses food another one will find, and walks round a lake whose far side it cannot see. Everything recently added serves that — a world eight screens wide so trajectories can diverge, cats spawned far apart so they live separate lives, and biomes next, which is what will give them reasons to be *different* rather than merely elsewhere.

**A proving ground for PHP.** The owner's position is that the ceiling is the developer and not the language, so the work deliberately goes at what PHP is said not to do: synthesizing audio and pushing it through SDL2 over FFI, twenty thousand ticks a second over forty thousand tiles, a windowed renderer with sampling. When something here looks disproportionate for a toy, that is usually why. Say plainly when a wall is a real runtime limit — there is no audio output, and FFI callbacks cannot be invoked from a foreign thread — rather than repeating the reputation.

The corollary is that **observability is a feature, not scaffolding**. The time machine, the per-AI panel, the memory gauges, the speed that reports the rate actually reached rather than the one requested, the field of view drawn on the map, the sound: they exist to watch agents and understand why they did what they did. Weigh a change against that before against convenience.

## Where the AI is going

The behaviour is deliberately hand-written and deterministic **first**. The current cat is the yardstick: without it, a learned policy is just a behaviour, with it the question becomes "does it beat the version written by hand", which is answerable. Do not replace the deterministic goals with a learner before there is something to grade.

Most of what a learning agent needs already exists, built for other reasons: `--seed` gives a reproducible environment, the snapshot ring lets a state be replayed and a different action tried from the same point, x1000 gives the throughput an evaluation needs, `Player::getVision()` and `PathFinder::costsWithin()` *are* the observation space, and `Estomac` is already a reward signal.

Two things to settle before writing any of it:

- **A policy should choose among `Objectif`s, not among steps.** The action space stays tiny, and — more importantly here — the AI panel keeps showing `describe()`, so one can still see *why* the cat did something. Picking tile-by-tile moves buys expressiveness and spends exactly what makes this project worth watching.
- **A policy has state, and `World` is serialized.** Weights and any generator must go into the snapshot or the time machine will lie: replaying a past state with a future brain. Three lines of `__sleep` now, a haunting later.

A cat is tractable because it has **one** drive, which is also why a learner would currently have nothing to learn — the `match` in `CatIA` is already optimal. The interesting boundary is the *second* drive (sleep, warmth, fear, curiosity): the moment two needs compete for the same tick, hand-written rules become a pile of `if`s nobody can tune, and arbitration is what a policy is actually good at.

## Fear: a price the cat adds itself

Fear here is not a behaviour and has no `Objectif`. It is the gap between what the world charges and what a cat believes it charges: `MapBuilder::COSTS` prices a bramble at one, like grass, and a cat that has been stung routes as though it cost twelve. **Nothing in the pathfinder changes — it is handed a different price list** (`Map\Path\CostBiasInterface`, implemented by `Peur`), so the avoidance appears *before* the next sting, which is what makes it anticipation rather than reaction. Brambles must stay objectively cheap: priced high in `COSTS`, every cat would route round them from birth and there would be nothing to learn.

**A cat does not choose what to blame.** Asked whether it remembers the bramble, the place or the individual, the answer is that it remembers whatever was present, and each cue takes a share. The update rule is Rescorla and Wagner's (1972) and is one line — every cue present moves by the part of the pain that was *not* predicted — which is what makes cues compete: a cue that already predicts the sting leaves little error for the others, so a familiar danger in a new place teaches almost nothing about the place (*blocking*, and there is a test named after it). Stung on brambles in many places, the terrain cue is reinforced every time while each place fades alone, so the memory generalises. Stung repeatedly in one place over varied ground, the place wins. **The level of abstraction is never chosen; it is selected by the statistics of what happened.**

Place cues are deliberately coarse (`Peur::REGION`, eight tiles). An exact tile on a map of forty thousand is a memory the cat will never be in a position to use again, and animals learn a context rather than a point.

Forgetting is half the behaviour, not housekeeping: a fear that never faded would keep a cat off a terrain for life over one scratch, with no way to find out it had changed.

**The range is what a cat can see; the bias is what it prefers, and the two must never be added together.** Conflated, a cat that fears a path stops *seeing* the food at the end of it — it was written that way first and the flower simply vanished. `PathFinder::flood()` therefore carries two costs: `$reach`, what the world charges, which is the only thing the budget is measured against, and `$best`, which adds the fear and is what the expansion is ordered by. `testABiasDoesNotShortenTheSightLine` guards it.

**Brambles grow as a collar around the flowers**, which is a correction worth remembering. They were first cut from the trough of the same noise field whose peaks grow flowers — prettier, and useless: they ended up exactly where flowers were not, so a cat walking to its food walked away from them. Measured over four thousand ticks on four maps, a single seed produced one sting and the whole mechanism was unreachable. Around the food, they are the first thing in this world a cat has to weigh.

There is deliberately **no death**. A cat that died would have to leave the world, the AI panel and the tab selection, and mortality is not what pain is for: `life` is the running account of how much of it was taken — which is what makes a wary cat measurably better off than a reckless one — and it heals slowly so the account is about recent experience.

## Running

```bash
make run      # play in the container (full screen, needs a real TTY, no sound)
make play     # play on the host PHP, with sound
make test     # phpunit
make logs     # tail app/log/dev.log from another terminal
make shell    # shell in the container
make help     # all targets
```

Keys: `space` play/pause, `n` one tick, `-`/`+` speed, **arrows to move the view, `z`/`Z` to zoom in and out, or drag the map with the mouse and zoom with the wheel**, `c` centre on the selected cat, `t` time machine then `p`/`a` to browse snapshots, `tab` or `1`..`9` to switch AI panel, `r` new map, `x` persist memory, `m` mute, `q` quit (`b`/`s` are kept as pause/play aliases). The game starts paused; `--play` starts it running.

In raw mode Ctrl+C is delivered as a key event, not a signal — quit with `q`. If the process is killed from outside, the tty is left raw: run `reset`.

Docker runs `php:8.4-cli`; nothing but `ext-intl` is compiled. Since the renderer is pure PHP, `php ./console` also works on any host PHP 8.1+ — Docker is convenience, not a requirement.

## Rendering: php-tui, not ncurses

The ncurses PECL extension was abandoned in 2012 and never ported past PHP 7, which used to pin this project to PHP 7.2 and a dead Debian image. It was replaced by [php-tui](https://php-tui.github.io/php-tui) (a Ratatui port) which emits ANSI escapes from pure PHP.

`TuiRender` is the only class that talks to the terminal. It builds a `Display` through `DisplayBuilder::default($backend)->fullscreen()`, then takes the alternate screen and raw mode in `init()` and gives them back in `close()`. **The alternate screen is what keeps the UI a fixed dashboard rather than output scrolling under the shell prompt** — if you touch `init()`/`close()`, keep the ordering (alternate screen before raw mode, reverse on the way out) and keep `close()` in a `finally`.

Nothing may write to stdout while the game runs: a stray notice lands inside the alternate screen and corrupts the frame. `console` sets `error_reporting(E_ALL & ~E_DEPRECATED)` because php-tui/term still declares implicitly nullable parameters, which PHP 8.4 reports when the class loads.

The frame is three rows: map + sidebar, log pane, control bar. The sidebar stacks an AI panel — a `TabsWidget` with one tab per player, the selected one detailing its stomach gauge and the descriptions returned by `ObjectifInterface::describe()` — over a live memory panel. The control bar owns everything time-related: play/pause, speed, and the snapshot gauge that only appears in time-machine mode. Tab selection lives in `TuiRender` (`nextTab`/`selectTab`); the runner just forwards keys.

## Sound: synthesized in PHP, pushed through SDL2 over FFI

PHP has no audio output — nothing in the core, and `ext-openal` died with PHP 7, the same story as ncurses. So the chip tune is **synthesized in pure PHP** (`Audio\Synth`, square/sweep/noise into unsigned 8 bit mono PCM) and handed to a device bound at runtime through FFI.

**The whole design follows from one limit: PHP callbacks cannot be invoked from a foreign thread.** Every audio API that *pulls* samples from its own thread — CoreAudio's AudioQueue, PortAudio in callback mode, SDL's own callback mode — would crash the process instead of raising anything. `SDL_QueueAudio` pushes instead, and `SDL_GetQueuedAudioSize` lets the game loop see how much lead is left, so no C code ever calls back into PHP. That is why it is SDL and not the obvious macOS API.

A C extension was considered and rejected on this repository's own terms: it spent its recent history escaping a native extension that had pinned it to PHP 7.2 and a dead image. FFI binds at runtime and is skipped when the library is absent.

**The container has no sound card**, and cannot be given one on macOS without a PulseAudio server on the host. `make run` is therefore silent by design and `make play` (host PHP) is where the music is. `AudioOutputInterface` makes that a swap, not a branch: `NullAudioOutput` answers false to `open()` and the sound board never even synthesizes the theme.

`SDL_AudioSpec` is transcribed by hand and must match the ABI — 32 bytes on arm64, asserted by `SdlAudioOutputTest`, because a wrong layout corrupts memory rather than raising an error.

Volumes are a **budget, not a preference**: the four voices total 82 of the 127 a byte allows, leaving exactly the 45 the loudest effect needs to land on top without the sum clipping (`testTheMusicLeavesRoomForASoundEffect`). `Mixer` saturates and never wraps — a sample allowed to overflow comes back as the opposite extreme, which is heard as a crack, not as distortion.

Note durations are counted **in samples, never in seconds**: rounding each note from its own duration lets the four voices drift apart, and a loop whose tracks end at different points clicks on every repeat (`testEveryVoiceIsExactlyTheSameLength`).

`SoundBoard::BUFFER_SECONDS` keeps a fifth of a second ahead of the speaker. Less and a slow frame — batching a thousand ticks at x1000 — drains the queue and the music gaps; more and a key press blip is heard too long after the key.

**Sound effects are triggered by the runner watching the world, never by the domain announcing them.** `World` is serialized into snapshots and an FFI handle is not serializable, so nothing audio-shaped may be reachable from it. Eating is detected by counting flowers between frames — they only ever disappear by being eaten — which is also what makes it right at x1000, where one frame may contain several meals. `GameRunner::setWorld()` rebases that count, otherwise a new map or a jump through the time machine would be heard as a meal.

## The world is larger than the screen

The map used to be built at exactly the size of the terminal, so the world *was* the screen and there was nothing to look at that was not already visible. It is now a fixed **256x160 tiles** (`GameRunner::WORLD_WIDTH`), some eight screens across on a hundred column terminal, and `TuiRender::getSize()` reports the size of the *view* rather than of the world.

That was affordable only once two things had been settled, both measured on a map that size: a snapshot cost 1.07 MB and now costs 8 KB, and an unbounded failed search cost 32ms and now costs nothing. Neither was visible while the map fitted the screen.

`Runtime\Camera` holds the window: origin in tiles, and a scale of 1, 2, 4 or 8 world tiles per rendered cell. Only zooming *out* is offered — at 1:1 a cell is already a tile, and a closer view would enlarge the blocks without adding anything to them. It lives in `Runtime` for the same reason `TimeControl` does: it is a way of looking at the simulation, not part of it, and anything reachable from `World` has to survive serialization.

Zooming **holds the middle of the view still**; anchored on the corner, whatever is being looked at slides off exactly when the user asks to see it closer. Panning moves a fixed number of *cells*, so a keypress covers eight times as much ground at 1:8 — which is the point of being zoomed out. `clamp()` runs on every frame rather than after every move, because zooming changes how much ground the view covers and a corner that was legal a moment ago may hang off the edge without anything having been panned.

**A cell is sampled, not averaged.** Reading every tile of every block would be sixty four lookups a cell at 1:8, some thirty thousand a frame, which costs more than the simulation it is showing; terrain is contiguous enough that one tile speaks for its neighbours. Sampling loses anything smaller than a block — a lone flower usually disappears at 1:4 — with one exception: **players are drawn from their own positions afterwards, so a cat is never sampled away.** Losing sight of a cat is precisely what one zooms out to avoid. Shades are hashed from world coordinates rather than screen ones, so the grain of the ground stays put while the view slides over it.

Cats spawn at quarter and three-quarter of the map rather than in the first screen: two cats a few tiles apart would compete for the same flowers and the rest of the world would never be walked on.

**The field of view is drawn from the real flood, not as a circle.** Sight is spent in cost, so it stops short in undergrowth and is cut off entirely by a lake; a circle would claim a cat sees across water. `PathFinder::costsWithin()` is the same flood as the routing with nothing to find, about a millisecond for one cat against a frame budget of sixty six, and only the selected one is drawn. The band marking the edge is **one cell thick at the current zoom** rather than one tile: a one tile ring would be sampled away at 1:2, which is exactly when the whole field of view starts fitting on screen.

**The AI panel carries a button that centres the view on the cat it describes** (`c` does the same). Its row is recorded while the panel is built rather than worked out a second time from the layout — computing it twice is how a button ends up one row away from itself, and `testTheFocusButtonIsWhereItSaysItIs` finds the drawn text in a real frame and checks the two agree.

**The mouse drags the map and the wheel zooms**, which is the gesture every map uses. php-tui parses the events; the work was deciding where they belong. The renderer owns the geometry — `isOverMap()` and `toCells()` — because it is what decided the layout, and the loop owns the gesture, because a drag is state across frames. `Camera::dragBy()` negates the movement: the ground follows the hand, so pulling right brings in what was on the left, and that sign is the easiest thing in the whole feature to get backwards.

A tile is two columns wide, so a one column drag is worth nothing yet; the anchor stays put until the movement adds up, otherwise a slow horizontal drag would round to zero for ever. Only the last mouse event of a frame is kept — capture is enabled with any-motion tracking, so a mouse merely crossing the screen emits an event per cell.

**Capture has to be given back on the way out.** Left on, the terminal keeps swallowing clicks after the game exits — the same class of damage as leaving the tty in raw mode, and `testMouseCaptureIsTakenAndGivenBack` is what stops it happening again. It also takes the terminal's own text selection with it, which is why `--no-mouse` exists: with it, copying a line out of the log needs no modifier.

## Memory and exit 137

Two ceilings can stop the game and they fail differently. PHP's `memory_limit` raises a catchable fatal error; the container cgroup limit makes the kernel SIGKILL the process, which is the **exit 137 with no stack trace and nothing in the log**. `Runtime\MemoryUsage` reads both (cgroup v2 then v1, `null` when uncapped) plus the peak, and `FlashMemory::bytes()` reports the snapshot ring — by far the biggest thing this program holds.

Three mitigations are in place: the sidebar panel shows both gauges live, `GameRunner::checkMemory()` writes a reading to `dev.log` every 50 ticks (a warning past 80% of a known limit) so there is a trace *before* the kill, and `docker-compose.yml` sets `mem_limit: 512m` on purpose — an uncapped container takes the whole Docker VM down instead of failing visibly.

`GameRunner::SNAPSHOT_EVERY` keeps the ring from being rewritten on every tick while playing: at x4 that would serialize the world a hundred times a second for a tenth of a second of history.

Players are stamped on their own layer as `1`..`9`, not a shared `P`, so each cat gets its own glyph and colour — two cats used to be the same indistinguishable letter.

**A tile spans two columns** (`TilePalette::TILE_WIDTH`). A terminal cell is about twice as tall as it is wide, so one cell per tile squashed the map vertically — round lakes came out as ovals and a diagonal step looked like 27 degrees rather than 45. `getSize()` therefore reports half as many tiles across as there are columns.

**Nothing in the UI may be two columns wide — emoji included.** php-tui's paragraph rendering stores one grapheme per cell without accounting for its display width (`Buffer::putString` handles it, the paragraph path does not), so an emoji takes one cell and two columns: everything after it on that row shifts right and the block border lands one column off. This bites in the side panels exactly as much as on the grid, which is not obvious — measuring the character in isolation says nothing. `testNoRowIsWiderThanTheScreen` renders a full frame and asserts every row is exactly the screen width; it is the test that was missing when emoji were first tried.

**Measuring a character's width in PHP is not enough to know how wide it will be drawn.** `mb_strwidth` reports the Unicode width; the terminal reports the *font's*. A codepoint with an emoji presentation gets substituted from the colour emoji font, which draws it on two columns whatever Unicode says — the club suit that used to mark the forest measured one column in PHP and took two on screen, so `testNoRowIsWiderThanTheScreen` stayed green while the border sat one column off. `testNoGlyphCanBeSubstitutedByTheEmojiFont` guards the family rather than that one character, using `\p{Emoji}` (PCRE2 supports it; it matches `♣` and not `✿`).

`TilePalette` decides how a tile is painted. Terrain is the cell **background**, not a coloured character, so ground reads as solid areas and the glyph stays free for what stands on it — grass, water **and forest** are plain spaces, and a flower is the only thing still written on the ground. The sixteen colour fallback has no shades to spare, so the meadow takes light green and the wood dark green: with no glyph left to tell them apart, the two colours carry it alone. Each terrain has four shades picked by hashing the tile coordinates: one flat colour looks like paint, and a shade redrawn at random every frame would make the map shimmer. The hash must avalanche — the first attempt used `crc32`, which is linear, so shades repeated every four tiles and wove visible diagonal stripes across the meadow (`testTheGrainRepeatsNoVisiblePattern` guards it).

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

**World is the simulated state.** Map, players, timer, and the per-player AI. Services are injected and explicitly excluded from `__sleep`. `World` no longer knows about the keyboard at all: it held an input controller only so a cat could be steered by hand, and the arrows now move the camera instead.

**AI is per-player and event-driven.** `ApplicationIA` walks every player, calls its AI, then its `update()`, then enforces where it may stand — clamped to the map and reverted if it landed on an impassable tile. That is the single authority on position, whatever moved the player. `Estomac` emits `HungryEvent`/`FullEvent` each tick; `CatIA` turns hungry into a `Manger` goal and drops all goals when full. **Goals own movement**, and now all of it: the free-roam `move()` driven by the arrow keys is gone, along with the `Direction` it wrote — nothing read it, and it was serialized into every snapshot. Add a behaviour by writing an `ObjectifInterface` under `src/IA/Objectif/` and subscribing it to an event in the AI class.

### Pathfinding

`PathFinder` works on integers over a flat cost grid (`MapBuilder::costGrid()`), never on `Point` objects: the first version allocated about two dozen of them per expanded tile, and a search that found nothing — having to visit the whole map — took 50ms, capping the simulation at x40 as soon as food ran out. **A cat only sees `Player::getVision()` tiles, and that is what lets the map be larger than the screen.** An unbounded search that finds nothing has to flood everything reachable, so its cost follows the size of the world: measured on a 256x160 map with a flower that exists but cannot be reached — the case that forces the flood to give up — it took **32ms**, which on its own caps the simulation near thirty ticks a second. Bounded, it does not register.

Bounding the flood is not enough on its own: `toNearest()` collected its goals through `positionsOf()`, which swept the whole map on every search, so that sweep takes the same box. The budget is spent in **cost, not distance**, which has a side effect worth keeping — undergrowth costs three times what grass does, so a cat sees three times less far through a wood.

`MapBuilder::costGrid()` is built once and kept until the terrain changes. `Manger` builds a `PathFinder` on every re-route and each one used to copy the whole grid; a cat moving writes to the *player* layer, which deliberately does not invalidate it.

**Limited sight has to come with wandering.** A cat that sees no flower walks to the edge of its sight and looks again from there; without that, a bounded search reads as a cat standing still. Headings are walked clockwise rather than drawn at random — near a shore most of them lead nowhere, and the wandering has to be reproducible for the same reason the terrain is seeded. `RETRY_EVERY` dropped from 300 ticks to 30 because its justification is gone: the answer *can* now turn positive on its own, since walking changes what is visible. It only guards a cat that can neither see food nor move.

A cat that has just eaten returns immediately instead of routing again: the stomach raises `full` on the player's own update, which runs *after* the AI's, so the goal would otherwise send it wandering off the flower it is standing on (`testTheCatWalksToTheFlowerEatsItAndTurnsItIntoGrass` caught exactly that).

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

**Time machine = serialization.** `Snapshot\Instant` serializes a `World`; `FlashMemory` keeps a ring of 10 with a read cursor. Restoring swaps the live world and re-attaches the logger (`GameRunner::setWorld`), which also rebases the flower count the eating sound is detected from.

Serialization is the sharp edge of this codebase. Anything added to `World` or a player must be serializable or excluded via `__sleep`. In particular, the `Estomac` event dispatcher holds closures bound to `CatIA`: it is dropped on sleep, `CatIA::__wakeup` re-subscribes, and `Estomac::getEventDispatcher()` builds it lazily because wake-up order between the two is not guaranteed. `tests/Snapshot/TimeMachineTest.php` covers exactly this.

## Tests

`make test` — 154 tests covering map queries and bounds, cat behaviour end-to-end (walks, eats, turns the flower to grass), snapshot round-trips, headless frame rendering, and the audio (oscillators, mixing, loop length, the feeding of the device against a fake output). The SDL test skips itself when the library is absent, which is the normal outcome in the container. `tests/WorldFactory.php` builds worlds from ASCII rows so nothing depends on the random provider.

`phpunit.xml.dist` fails on warnings, notices and deprecations, but `ignoreIndirectDeprecations` keeps vendor deprecations from failing the suite.

## Leftovers

`test*.php` and `testhorsia.php` at the repo root, `.tt.txt.swp`, and `10-powerline-symbols.conf` are pre-existing scratch files, unrelated to the app. `dev.sh` and `dev/` implement a watch-and-restart loop that assumes Linux (`inotifywait`) and a container name that no longer exists.
