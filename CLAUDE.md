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

**The second drive now exists**, and it is where a learner would first have something to learn. Eating and sheltering compete for the same tick, and `CatIA::arbitrate()` ranks them with two thresholds and a guard — hurt below five, out again at nine, and only if a way down is in sight. Those numbers were tuned by measuring, which is exactly the work a policy does better than a person. A third drive would make it worse still: three hand-written rankings is where this stops being tunable by hand.

What has *not* been built, and is the honest next step before any of it: a cat cannot be told apart from another cat by what it has learnt. Two of them run the same code with different memories. Giving them different visions, or different learning rates, would make a run produce a spread rather than a single trajectory — and a spread is what one needs before "does it beat the hand-written version" means anything.

## Fear: a price the cat adds itself

Fear here is not a behaviour and has no `Objectif`. It is the gap between what the world charges and what a cat believes it charges: `MapBuilder::COSTS` prices a bramble at one, like grass, and a cat that has been stung routes as though it cost twelve. **Nothing in the pathfinder changes — it is handed a different price list** (`Map\Path\CostBiasInterface`, implemented by `Peur`), so the avoidance appears *before* the next sting, which is what makes it anticipation rather than reaction. Brambles must stay objectively cheap: priced high in `COSTS`, every cat would route round them from birth and there would be nothing to learn.

**A cat does not choose what to blame.** Asked whether it remembers the bramble, the place or the individual, the answer is that it remembers whatever was present, and each cue takes a share. The update rule is Rescorla and Wagner's (1972) and is one line — every cue present moves by the part of the pain that was *not* predicted — which is what makes cues compete: a cue that already predicts the sting leaves little error for the others, so a familiar danger in a new place teaches almost nothing about the place (*blocking*, and there is a test named after it). Stung on brambles in many places, the terrain cue is reinforced every time while each place fades alone, so the memory generalises. Stung repeatedly in one place over varied ground, the place wins. **The level of abstraction is never chosen; it is selected by the statistics of what happened.**

Place cues are deliberately coarse (`Peur::REGION`, eight tiles). An exact tile on a map of forty thousand is a memory the cat will never be in a position to use again, and animals learn a context rather than a point.

Forgetting is half the behaviour, not housekeeping: a fear that never faded would keep a cat off a terrain for life over one scratch, with no way to find out it had changed.

**The range is what a cat can see; the bias is what it prefers, and the two must never be added together.** Conflated, a cat that fears a path stops *seeing* the food at the end of it — it was written that way first and the flower simply vanished. `PathFinder::flood()` therefore carries two costs: `$reach`, what the world charges, which is the only thing the budget is measured against, and `$best`, which adds the fear and is what the expansion is ordered by. `testABiasDoesNotShortenTheSightLine` guards it.

**Brambles grow as a collar around the flowers**, which is a correction worth remembering. They were first cut from the trough of the same noise field whose peaks grow flowers — prettier, and useless: they ended up exactly where flowers were not, so a cat walking to its food walked away from them. Measured over four thousand ticks on four maps, a single seed produced one sting and the whole mechanism was unreachable. Around the food, they are the first thing in this world a cat has to weigh.

**The foxglove is the second thing to learn, and it needed no new machinery.** `MapBuilder::NOURRITURE` lists what a hungry cat will walk to; only `FLEUR` feeds it, `DIGITALE` poisons. The tiles *are* distinguishable, so the plant can be learnt — the cat simply does not know yet, and has to taste one. Foxgloves are drawn per tile inside the flower patches rather than cut from a field of their own: scattered among the blooms, the only thing that predicts a meal is which flower it is; grown in patches, the *place* would predict it just as well and a cat would learn the map instead of the plant.

Two asymmetries make it behave like an animal rather than like a table, and both are borrowed rather than invented:

- **A poisoned meal is learnt in one trial** (`Peur::TASTE_RATE`, near one, against 0.4 for a scratch). An animal that needed poisoning twice usually did not get the chance.
- **Illness binds to what was tasted, hardly at all to where it happened** (`Peur::salience()`, Garcia and Koelling 1966), while an injury binds to the place as readily as to the thing. This is not a refinement: without it the region of a poisoned meal became as dear as the plant, so the good flowers growing beside it were avoided too. Measured over six thousand ticks, a cat that already knew still ate six more foxgloves with a real flower within reach; with it, two.

What remains is the right behaviour rather than a failure: a cat still eats a foxglove out of ignorance the first time, and still eats one when it is the only food in range. That second case was the first genuine dilemma in this world, and it is what the second drive — see the shelter, below — was added to answer.

**Holes make the danger graded, and cost the same to walk on as grass.** That last part is the rule, not a detail: a hazard priced into the map is routed round from birth and there is nothing left to learn. Holes were given a cost of three at first — climbing out ought to cost something — and over six thousand ticks on three maps no cat ever fell in one. With the cost back to one, the gradient appears on its own: the rule moves by the pain that was not predicted, so a pit that hurts four times what a thorn does is feared about four times as much, and the detour it is worth follows. Nothing anywhere grades it.

There is deliberately **no death**. A cat that died would have to leave the world, the AI panel and the tab selection, and mortality is not what pain is for: `life` is the running account of how much of it was taken — which is what makes a wary cat measurably better off than a reckless one — and it heals slowly so the account is about recent experience.

## Running

```bash
make run      # play in the container (full screen, needs a real TTY, no sound)
make play     # play on the host PHP, with sound
make window   # play in an SDL window instead of the terminal, on the host
make demo     # a throwaway 2.5D sketch of the map, printed once
make test     # phpunit
make logs     # tail app/log/dev.log from another terminal
make shell    # shell in the container
make help     # all targets
```

Keys: `space` play/pause, `n` one tick, `-`/`+` speed, **arrows to move the view, `z`/`Z` to zoom in and out, or drag the map with the mouse and zoom with the wheel**, `c` centre on the selected cat once, **`l` (or the panel's button) ride along with it**, `t` time machine then `p`/`a` to browse snapshots, `tab` or `1`..`9` to switch AI panel, `r` new map, `x` persist memory, `m` mute, `f` (or ctrl-L) repaint the whole screen, **`v` swap between the map and the isometric view, in the window**, `q` quit (`b`/`s` are kept as pause/play aliases). The game starts paused; `--play` starts it running.

`--window` draws in an SDL window rather than in the terminal. `--colours=16` or `--colours=24` forces the colour depth instead of trusting `COLORTERM`, which is inherited and therefore wrong in both directions.

In raw mode Ctrl+C is delivered as a key event, not a signal — quit with `q`. If the process is killed from outside, the tty is left raw: run `reset`.

Docker runs `php:8.4-cli`; nothing but `ext-intl` is compiled. Since the renderer is pure PHP, `php ./console` also works on any host PHP 8.1+ — Docker is convenience, not a requirement.

## Rendering: php-tui, not ncurses

There are two renderers now — the terminal below, and a window further down — but they share the camera, the palette and the content of the panels, so most of what follows governs both.

The ncurses PECL extension was abandoned in 2012 and never ported past PHP 7, which used to pin this project to PHP 7.2 and a dead Debian image. It was replaced by [php-tui](https://php-tui.github.io/php-tui) (a Ratatui port) which emits ANSI escapes from pure PHP.

`TuiRender` is the only class that talks to the terminal — it is no longer the only renderer, but nothing else emits an escape sequence. It builds a `Display` through `DisplayBuilder::default($backend)->fullscreen()`, then takes the alternate screen and raw mode in `init()` and gives them back in `close()`. **The alternate screen is what keeps the UI a fixed dashboard rather than output scrolling under the shell prompt** — if you touch `init()`/`close()`, keep the ordering (alternate screen before raw mode, reverse on the way out) and keep `close()` in a `finally`.

Nothing may write to stdout while the game runs: a stray notice lands inside the alternate screen and corrupts the frame. `console` sets `error_reporting(E_ALL & ~E_DEPRECATED)` because php-tui/term still declares implicitly nullable parameters, which PHP 8.4 reports when the class loads.

The frame is three rows: map + sidebar, log pane, control bar. The sidebar stacks an AI panel — a `TabsWidget` with one tab per player, the selected one detailing its stomach gauge and the descriptions returned by `ObjectifInterface::describe()` — over a live memory panel. The control bar owns everything time-related: play/pause, speed, and the snapshot gauge that only appears in time-machine mode. Tab selection lives in `TuiRender` (`nextTab`/`selectTab`); the runner just forwards keys.

**A frame carries only what changed, and that is the whole economy of the thing.** It is also what makes any byte the terminal loses or misreads a permanent mark: the renderer believes that cell is already right and will never paint it again. `f`, or ctrl-L, throws the record away so the next frame is painted whole — the key every full screen program has had for forty years, and the only way out of damage the renderer cannot see.

### What a terminal costs, measured

**A write to the tty blocks.** So a terminal that cannot swallow a frame does not merely show it late — it stalls the simulation behind it, and `TimeControl::observe()` reports the shortfall in red. That makes escape volume a budget rather than an optimisation, and it was measured on a hundred and sixty column screen:

- a full repaint: **135 KB**
- a frame with the water animated continuously: **35 KB**, which is half a megabyte a second at 15 fps
- the same with the swell quantised to six steps: **5 KB**, 73 KB/s
- a frame where nothing moved at all: **14 bytes**

The middle line is the one that mattered. php-tui sends only the cells that changed, so a *continuous* colour guarantees every cell of every lake changed: 93% of water tiles a frame, against 8% quantised. Electron terminals do not survive the first figure.

### The water is the only thing that moves

`TilePalette::swell()` draws the lake from two travelling waves summed — one alone is a ruler sliding across the water and its period is plain within a second — and quantised to six steps for the budget above. The phase is in **seconds of wall clock, never in ticks**: read from the tick it would boil at x1000 and freeze while paused, and an animation is a way of looking rather than something the world does, exactly like the camera. It is read once per frame, or the bottom of the lake would be older than its top.

It *is* frozen while the world is, though. A lake rippling on a paused game holds the terminal busy for as long as the game is left open, which is most of the time it is open. Below x1 the frame lasts longer than the animation wants, so `GameRunner::waitUntil()` breaks the wait into slices and lets `animate()` decide whether a redraw is due — `draw()` timestamps itself, so a frame drawn for a real reason is never drawn twice.

### A cat is one tile out of forty thousand

`CatSprite` floats a small drawn cat over each player: thirteen by twelve tiles, in tiles rather than cells because a tile is half a cell and half a cell is square, so the art comes out undistorted. Its size is in tiles of the *view*, so zooming out does not shrink it away — losing the marker is precisely what one zooms out to avoid.

Three things it needed, and each was a bug first:

- **An outline.** On a meadow carrying some hundred and ninety flowers, brambles and holes per screen, a shape with no dark line round it has no silhouette at all: it dissolves into the speckle and reads as a rendering fault rather than as a cat. `testTheCatIsOutlinedAllTheWayRound` checks the ring is closed, and it is what found first the tail and then the belly left bare.
- **A second coat colour that is darker fur, never a cream.** The first pair was a red coat and a near white patch over a near white cloud: the right half of the animal melted into the thing it was sitting on.
- **A gap measured at the bottom of the bob and not at rest.** Counted at rest, the cloud comes down onto the cat once a cycle and hides the one tile the whole marker exists to point at.

Each cat is given its own place in the bob's cycle, for the same reason the water is drawn from two waves: in step, several of them read as one animation copied a few times.

## Two renderers, and two ways of looking

`--window` draws the game in an SDL window instead of the terminal. It is **a renderer of the game and not a demo**: every feature follows because none of them was rewritten.

- **`Runtime\Camera` is shared**, so `z`, `Z`, the arrows, the wheel and `c` land in the same place in both. A second copy of that arithmetic is how two views of one world end up disagreeing about where a cat is.
- **`InputController\SdlInput` answers in the vocabulary the loop already speaks** — a character, an arrow sentinel, or a `MouseInput` in cells — so `pumpInput()` gained no branch at all. A second key table is how two front ends end up with two sets of shortcuts.
- **`TilePalette` is shared**, so the meadow keeps its hashed grain and the lake its swell in both.

`Map\Render\Dashboard` is what makes two renderers possible without them drifting. `TuiRender` used to hold both halves: the same method read the stomach, called `describe()` and asked `MemoryUsage`, *and* built `BlockWidget`s. A line is now a string and a **tone** — a meaning, never a colour — and php-tui turns it into an `AnsiColor` while the window turns it into a packed integer, neither knowing what the other chose. `GameRenderInterface` is the rest of the contract: the loop asks for the tab, the selected cat and where a click landed, and the renderer answers in half blocks or in square cells without the loop caring.

**SDL cannot write a single character, and does not have to.** The map is already composed as a buffer of pixels in PHP, so text is more pixels in the same buffer: `BitmapFont` is 665 bytes of 5x7 glyphs. That was the whole of the obstacle — no SDL_ttf, no second library to find at runtime, no second header to transcribe against an ABI that corrupts memory when it is wrong.

**`Map\Render\Pixels` knows nothing of SDL**, which is what makes the window testable: `SdlRender::compose()` builds all three surfaces of a frame with no display anywhere near it, the same seam php-tui's recording backend gives the terminal. It also hid a good bug — `rect()` used `array_splice`, which reindexes the whole array, so on a panel of a quarter of a million pixels a one pixel border cost more than the simulation and hung the suite.

**Two resolutions, on purpose.** The map is a texture of one pixel per cell blown up by the GPU with nearest neighbour: painting it at window resolution would be a million pixels a frame in PHP instead of ten thousand. Text at that scale would come out in letters as tall as a lake, so the panels are their own textures at true pixel size.

### The isometric view

`v` swaps the map for a lattice of diamonds where what stands on the ground is drawn as a shape — `IsoSprites` holds a tree that is a trunk and a canopy, a foxglove standing taller than the blooms it hides among, a bramble low to the ground, a cat seen from the side. The ground itself is not drawn but *generated*, and its colour comes from `TilePalette::pixel()`, so the isometric view inherits the grain and the swell for nothing. A sprite's own colour is read there too, which is why a foxglove is still the cold one among the warm: the cat has to be able to be wrong about it and the player has to be able to see that it was.

Composed at half the area it fills and blown up. Measured on a real 256x160 terrain: **13 ms a frame at 512x320**, against 15 ms for the bare ground at full size — the chunky pixel is what buys the objects, and it is the same pixel the map view already shows.

**Drawn back to front by depth.** A diamond lattice has no row order that is also a depth order — screen height comes from the *sum* of the two world axes — so the loop walks that sum. Iterating rows instead puts trees in front of the cats standing before them.

Two geometry mistakes the tests found and reading would not have. The lattice fans out from a point, so drawn from the top of the view it left both upper corners bare, and it has to start *above* the view and be clipped. Correcting only that left the two lower corners bare for the mirror reason: a lattice sized by depth alone narrows towards the bottom exactly as it does towards the top. `IsoView::coverage()` therefore solves for the far corner — a screen position comes from the sum *and* the difference of the axes — rather than counting rows.

## Sound: synthesized in PHP, pushed through SDL2 over FFI

PHP has no audio output — nothing in the core, and `ext-openal` died with PHP 7, the same story as ncurses. So the chip tune is **synthesized in pure PHP** (`Audio\Synth`, square/sweep/noise into unsigned 8 bit mono PCM) and handed to a device bound at runtime through FFI.

**The whole design follows from one limit: PHP callbacks cannot be invoked from a foreign thread.** Every audio API that *pulls* samples from its own thread — CoreAudio's AudioQueue, PortAudio in callback mode, SDL's own callback mode — would crash the process instead of raising anything. `SDL_QueueAudio` pushes instead, and `SDL_GetQueuedAudioSize` lets the game loop see how much lead is left, so no C code ever calls back into PHP. That is why it is SDL and not the obvious macOS API.

**The video side reuses the binding and escapes that limit entirely.** `Runtime\Sdl` binds `SDL_CreateWindow`, `SDL_UpdateTexture` and `SDL_PollEvent` the same way, and there is no callback anywhere: the renderer polls, the renderer draws, the renderer presents. No C code ever calls back into PHP. What was nearly impossible for the speaker is ordinary for the screen — worth remembering before assuming the audio design generalises.

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

**The AI panel carries a button that makes the view ride along with the cat it describes** (`l` does the same, and `c` centres once without following).

Centring and following are different needs and both are kept: putting the cat back in the middle and leaving the view alone is what one wants while reading the map, and riding along is what one wants while watching an animal decide something. **Moving the view by hand ends the follow** — without that the drag fights it and loses, the view snaps back on the very next frame, and the map reads as broken rather than as a mode being on. Zooming does not end it: a zoom is a question about the same place. Which cat is followed is never stored, it is whichever one the panel describes, so switching tab moves the view and there is no second selection to keep in step. Its row is recorded while the panel is built rather than worked out a second time from the layout — computing it twice is how a button ends up one row away from itself, and `testTheFocusButtonIsWhereItSaysItIs` finds the drawn text in a real frame and checks the two agree.

**The mouse drags the map and the wheel zooms**, which is the gesture every map uses. php-tui parses the events; the work was deciding where they belong. The renderer owns the geometry — `isOverMap()` and `toCells()` — because it is what decided the layout, and the loop owns the gesture, because a drag is state across frames. `Camera::dragBy()` negates the movement: the ground follows the hand, so pulling right brings in what was on the left, and that sign is the easiest thing in the whole feature to get backwards.

A tile is two columns wide, so a one column drag is worth nothing yet; the anchor stays put until the movement adds up, otherwise a slow horizontal drag would round to zero for ever. Only the last mouse event of a frame is kept — capture is enabled with any-motion tracking, so a mouse merely crossing the screen emits an event per cell.

**Capture has to be given back on the way out.** Left on, the terminal keeps swallowing clicks after the game exits — the same class of damage as leaving the tty in raw mode, and `testMouseCaptureIsTakenAndGivenBack` is what stops it happening again. It also takes the terminal's own text selection with it, which is why `--no-mouse` exists: with it, copying a line out of the log needs no modifier.

## Under the meadow

`World` holds a map per level (`World::SURFACE`, `World::SOUTERRAIN`) and a player carries the name of the one it is on. A cavern is **one hole seen from two sides**: the same tile at the same coordinates on both maps, so going through it changes which map the cat is read against and nothing else. `World::mapFor($player)` is what everything that moves, searches or hurts a player goes through; `getMap()` still means the surface, for the things that do not care.

`ApplicationIA` flips the level **only on arrival** — checked every tick instead, a cat standing on a cavern flips back and forth for as long as it stays there. The view follows the selected cat down and back up, and only players on the level being looked at are drawn.

**The reason to descend was the hard part, and the first two attempts could not fire at all.** Descending "when there is nothing left to eat up here" never happened: a cat sees twenty five tiles in every direction — some two thousand of them — and the meadow carries food on four percent of its surface. Measured over four thousand ticks, a cat was hungry for a hundred and seventy six of them and *never once* had nothing in sight. The same arithmetic sank the mirror rule for coming back up: cats went down once and stayed for three quarters of the run, even with the mushrooms thinned to a fifth. **"Nothing in sight" is not a usable trigger in this world**, and anything built on it is scenery.

Being hurt does happen. `SeMettreAlAbri` is the second drive and the reason the caverns exist: below there are no brambles, no pits and no foxgloves, so shelter is not an errand but the one safe place there is. It outranks hunger — the first arbitration in this world — and it is the *goal* that walks the cat back out once mended, rather than hunger being left to find the way. Two details it needs: the goal is only taken if a way down is actually in sight, or a cat that cannot reach one keeps it for ever and stops eating; and the cat rests **beside** the entrance rather than on it, because `toNearest` excludes the tile one is standing on and the next cavern is thirty tiles away, out of sight.

Caverns are punched on a coarse grid of wanted positions with a local search around each. Scanning the map and stopping at a quota put all forty in the first two rows, which is the same as having none.

Measured after all that: eight descents and eight returns over twelve thousand ticks, a cat spending about fifteen percent of its life underground.

## Memory and exit 137

Two ceilings can stop the game and they fail differently. PHP's `memory_limit` raises a catchable fatal error; the container cgroup limit makes the kernel SIGKILL the process, which is the **exit 137 with no stack trace and nothing in the log**. `Runtime\MemoryUsage` reads both (cgroup v2 then v1, `null` when uncapped) plus the peak, and `FlashMemory::bytes()` reports the snapshot ring — by far the biggest thing this program holds.

Three mitigations are in place: the sidebar panel shows both gauges live, `GameRunner::checkMemory()` writes a reading to `dev.log` every 50 ticks (a warning past 80% of a known limit) so there is a trace *before* the kill, and `docker-compose.yml` sets `mem_limit: 512m` on purpose — an uncapped container takes the whole Docker VM down instead of failing visibly.

`GameRunner::SNAPSHOT_EVERY` keeps the ring from being rewritten on every tick while playing: at x4 that would serialize the world a hundred times a second for a tenth of a second of history.

Players are stamped on their own layer as `1`..`9`, not a shared `P`, so each cat gets its own glyph and colour — two cats used to be the same indistinguishable letter.

What it costs is the glyphs: a character fills a whole cell, so at half a cell per tile a flower has only its colour left to speak with. `TilePalette::pixel()` is that question — what colour is this tile — and everything already had an answer, since the blooms, the thorns and the cats were coloured before they were shaped. The mode is refused without true colour, where two tiles in one cell cannot both be said.

**A tile is half a cell.** Two of them share one character — an upper half block whose foreground is the tile above and whose background the tile below — which puts 64x34 tiles on a hundred column terminal where the previous two-column tile fitted 32x17. Half a cell is square, so lakes stay round; the two-column tile was square for the same reason, a terminal cell being about twice as tall as it is wide, and it showed a quarter as much.

**The map is drawn in colour alone**, because a character cannot be cut in half. That is the trade the resolution is bought with, and it cost nothing to make: the blooms, the thorns and the cats were all coloured before they were ever shaped. `TilePalette::pixel()` is the whole question — what colour is this tile — and it answers in the terminal's own currency, an `RgbColor` where there is true colour and an `AnsiColor` where there are sixteen. Half blocks work either way: a foreground and a background is all they need.

**Nothing in the UI may be two columns wide — emoji included.** php-tui's paragraph rendering stores one grapheme per cell without accounting for its display width (`Buffer::putString` handles it, the paragraph path does not), so an emoji takes one cell and two columns: everything after it on that row shifts right and the block border lands one column off. This bites in the side panels exactly as much as on the grid, which is not obvious — measuring the character in isolation says nothing. `testNoRowIsWiderThanTheScreen` renders a full frame and asserts every row is exactly the screen width; it is the test that was missing when emoji were first tried.

Shapes survive in the side panel, where text flows, and the rule below still governs them there — it simply no longer applies to the map, which has no writing on it at all.

**Measuring a character's width in PHP is not enough to know how wide it will be drawn.** `mb_strwidth` reports the Unicode width; the terminal reports the *font's*. A codepoint with an emoji presentation gets substituted from the colour emoji font, which draws it on two columns whatever Unicode says — the club suit that used to mark the forest measured one column in PHP and took two on screen, so `testNoRowIsWiderThanTheScreen` stayed green while the border sat one column off. `testNoGlyphCanBeSubstitutedByTheEmojiFont` guards the family rather than that one character, using `\p{Emoji}` (PCRE2 supports it; it matches `♣` and not `✿`).

`TilePalette` decides how a tile is painted. Terrain is the cell **background**, not a coloured character, so ground reads as solid areas and the glyph stays free for what stands on it — grass, water **and forest** are plain spaces, and a flower is the only thing still written on the ground. The sixteen colour fallback has no shades to spare, so the meadow takes light green and the wood dark green: with no glyph left to tell them apart, the two colours carry it alone. Each terrain has four shades picked by hashing the tile coordinates: one flat colour looks like paint, and a shade redrawn at random every frame would make the map shimmer. The hash must avalanche — the first attempt used `crc32`, which is linear, so shades repeated every four tiles and wove visible diagonal stripes across the meadow (`testTheGrainRepeatsNoVisiblePattern` guards it).

**`COLORTERM` is inherited, so it lies in both directions.** It describes the terminal that started the shell and not the one drawing the frame: exported from a profile, carried across an ssh or nested in a tmux, it speaks for something else entirely. A table of terminals to disbelieve was tried and was worse than the problem — it held Terminal.app on the grounds that it has no 24 bit colour, and measured on a real one, it has. `--colours` settles it by hand instead, and nothing is judged on its name. `docker-compose.yml` forwards `TERM` and `COLORTERM` from the host: without that the container advertises nothing.

**There is deliberately no 256 colour rung, and it was tried.** The xterm cube steps each channel through 0, 95, 135, 175, 215, 255, and this palette sits in the first gap: three of the four meadow greens land on `#5f5f5f`, which is a *grey*, all four greens of the wood collapse onto one entry, and the thicket and the bramble both come out pure black. That is not a coarser picture — the meadow stops meaning meadow, which is worse than the flat sixteen, where at least green stays green.

The sixteen colour path had a bug worth remembering: `pixel()` read the *background* of the cell, and a flower is a magenta character on a green background. Every flower, foxglove and bramble therefore came back the exact green of the meadow — four thousand a map, gone into the grass. A tile is half a cell and has one colour, so what is *there* has to win over what it stands on.

Renderers are swappable through `MapRenderInterface`. Tests use php-tui's own doubles — `DummyBackend`, `StringWriter`, `TestRawMode`, `SizeFromEnvVarProvider` — so frames render headlessly and can be asserted as strings (see `tests/Map/Render/TuiRenderTest.php`).

## Architecture

Entry: `console` → `ApplicationConsole` (single-command Symfony app) → `Command\ApplicationCommand` → `GameRunner`.

`GameRunner` owns the loop and all screen-facing services (logger, renderer, input, memory). It holds a `GameRenderInterface` and an `InputControllerInterface` rather than either implementation, so `--window` is a choice made once in `execute()` and the loop below it is unchanged. Each iteration: drain input → handle keys → `advance()`. Key bindings live in one `match` in `pumpInput()`; each handler returns whether a redraw is needed. `World::update()` self-throttles to 15 updates/second and returns `false` when it skipped, so the frame is only redrawn on a real tick.

`Runtime\TimeControl` is the single source of truth for paused/speed/time-machine, shared by the loop and the control bar so neither has to reach into the other. `advance()` computes nothing while the time machine is on — the world on screen is a restored snapshot.

### Speed

The ladder runs x0.25 to x1000. Below the render rate, speed is the sleep between frames; above it there is no sleep left to shave, so **speed becomes ticks per frame** (`ticksPerFrame()`), rendering stays at 15fps and the simulation batches. `World::update()` no longer paces itself — a domain object calling `usleep()` capped everything above x1.

Three things scale with the batch rather than the tick: the logger is muted for all but the last tick of a batch (thousands of lines per frame would cost more than the simulation), snapshots are taken per *frame* (`SNAPSHOT_EVERY_FRAMES`), and `advance()` sleeps only the remainder of the frame budget — sleeping a full delay after a late frame is how a loop that is merely behind becomes hopelessly behind.

Asking for x1000 does not make the machine deliver it, so `observe()` records the rate actually reached and the control bar turns red with the real multiplier. Measured here: ~20k ticks/s in isolation (x1300), ~x360 through the full render loop. `--speed N` starts at a given rung, which is also how that gets measured.

**World is the simulated state.** A map *per level* (`World::SURFACE`, `World::SOUTERRAIN`), players, timer, and the per-player AI. A player carries the name of the level it stands on and `World::mapFor($player)` is what everything that moves, searches or hurts it goes through; `getMap()` still means the surface. Services are injected and explicitly excluded from `__sleep`. `World` no longer knows about the keyboard at all: it held an input controller only so a cat could be steered by hand, and the arrows now move the camera instead.

**AI is per-player and event-driven.** `ApplicationIA` walks every player, calls its AI, then its `update()`, then enforces where it may stand — clamped to the map and reverted if it landed on an impassable tile. That is the single authority on position, whatever moved the player. `Estomac` emits `HungryEvent`/`FullEvent` each tick; `CatIA` turns hungry into a `Manger` goal and drops all goals when full. **Goals own movement**, and now all of it: the free-roam `move()` driven by the arrow keys is gone, along with the `Direction` it wrote — nothing read it, and it was serialized into every snapshot. Add a behaviour by writing an `ObjectifInterface` under `src/IA/Objectif/`. Hunger arrives as an event; being hurt does not, and `CatIA::arbitrate()` is where drives are ranked against each other instead — a badly hurt cat drops everything for `SeMettreAlAbri`, and picks eating back up once it is out and mended. That method is where a third drive would go.

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

**Map is layered.** `MapBuilder` holds named layers (`map`, `player`) flattened into a final layer; renderers only see `getFinalMap()`. Tiles are single chars. Surface: `X` grass, `Y` open forest, `U` thicket, `E` water, `F` flower, `D` foxglove (poisonous), `R` bramble (stings), `T` pit (stings harder), `C` cavern. Underground: `K` rock, `G` gallery, `M` mushroom, and `C` again — the same cavern seen from below. `MapBuilder::NOURRITURE` is what a hungry cat will walk to, `HURTS` what standing somewhere costs, `EATING` what a mouthful gives or takes. Providers implement `MapProviderInterface`: `TerrainMapProvider` and `FileMapProvider` (`app/map/terre.txt`, mapping `░✿↟∼` back to `XFYE`).

**A thicket blocks the view, and nothing about the view says so.** `FOURRE` costs six to push through against three for open forest, and since sight is spent in cost rather than in distance, that one number does both jobs: a cat walks round a thicket, and sees barely into one. Measured with a range of twenty five: twenty tiles across a meadow, nine under trees, five in a thicket. Writing an opacity anywhere would have been a second mechanism saying the same thing.

### Terrain generation

`TerrainMapProvider` builds two fractal value-noise fields — elevation and bloom — and cuts the terrain out of them: low ground is water, high ground is forest, the rest grass, and grass blooms where the second field peaks. Continuous fields are what make regions contiguous, which the previous per-cell random draw could never produce.

The cuts are **quantiles, not fixed thresholds**. Asking for "the lowest 18%" yields the same coverage on every seed; a fixed cut on a normalized field swung between 19% and 37% water depending on how the noise fell. Terrain shares (`WATER_SHARE`, `FOREST_SHARE`, `FLOWER_SHARE`) are therefore a contract, asserted in `TerrainMapProviderTest`. `FOREST_SHARE` is the share of the map that is *wooded*: the thicket is taken out of the top of that band rather than added beside it, so a wood closes in towards its middle. Flowers are ranked among grass cells only, so their share does not shrink on a lake-heavy map — the cat always has food.

Generation is seeded through `Random\Randomizer` (no global `mt_srand`), so `--seed N` replays a map exactly and the tests can assert on shapes. Coherence itself is tested by measuring clustering — the fraction of water tiles touching another water tile — against the same tiles shuffled.

Terrain drives movement: see Pathfinding below.

**Time machine = serialization.** `Snapshot\Instant` serializes a `World`; `FlashMemory` keeps a ring of 10 with a read cursor. Restoring swaps the live world and re-attaches the logger (`GameRunner::setWorld`), which also rebases the flower count the eating sound is detected from.

Serialization is the sharp edge of this codebase. Anything added to `World` or a player must be serializable or excluded via `__sleep`. In particular, the `Estomac` event dispatcher holds closures bound to `CatIA`: it is dropped on sleep, `CatIA::__wakeup` re-subscribes, and `Estomac::getEventDispatcher()` builds it lazily because wake-up order between the two is not guaranteed. `tests/Snapshot/TimeMachineTest.php` covers exactly this.

## Tests

`make test` — 201 tests covering map queries and bounds, cat behaviour end-to-end (walks, eats, turns the flower to grass), snapshot round-trips, headless frame rendering, and the audio (oscillators, mixing, loop length, the feeding of the device against a fake output). `tests/Map/Render/RecordingBackend.php` keeps whole cells rather than just their characters: php-tui's own DummyBackend throws the styles away, which said enough while the map spoke through glyphs and says nothing now that a tile is a colour. The SDL test skips itself when the library is absent, which is the normal outcome in the container. `tests/WorldFactory.php` builds worlds from ASCII rows so nothing depends on the random provider.

The window has the same kind of seam: `SdlRender::compose()` builds the three surfaces of a frame into `Pixels` with no display involved, so the layout, the camera arithmetic and the isometric lattice are all asserted headlessly. `SdlInput::keyFor()` is public for the same reason — the class went unloaded by any test until its private constants collided with the interface's public ones and the game died on the first `--window`. A class nothing touches is a class nothing checks.

`phpunit.xml.dist` fails on warnings, notices and deprecations, but `ignoreIndirectDeprecations` keeps vendor deprecations from failing the suite.

## Leftovers

`demo/relief.php` is a throwaway sketch of the map in 2.5D, printed once to the normal screen, loaded by nothing and meant to be argued with or deleted. Its heights are *invented* from the terrain type, which is the argument for one day keeping the elevation field `TerrainMapProvider` already cuts the terrain out of and then throws away: read from it, no lake would sit on a hilltop, because being low is what made it a lake.

`test*.php` and `testhorsia.php` at the repo root, `.tt.txt.swp`, and `10-powerline-symbols.conf` are pre-existing scratch files, unrelated to the app. `dev.sh` and `dev/` implement a watch-and-restart loop that assumes Linux (`inotifywait`) and a container name that no longer exists.
