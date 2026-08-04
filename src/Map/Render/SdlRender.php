<?php

declare(strict_types=1);

namespace Map\Render;

use FFI;
use Logger\BufferLogger;
use Logger\MultipleLogger;
use Map\Builder\MapBuilder;
use Map\Path\PathFinder;
use Map\Player\PlayerInterface;
use Map\Relief;
use Map\World\World;
use Map\World\WorldContainer;
use Memory\MemoryManager;
use Psr\Log\LogLevel;
use Runtime\Camera;
use Runtime\MemoryUsage;
use Runtime\Sdl;
use Runtime\TimeControl;

/**
 * The whole dashboard in a window, drawn as pixels.
 *
 * **It shares the camera with the terminal, and that is the point.** Zooming,
 * panning, dragging and centring are not reimplemented here: `Runtime\Camera`
 * is still the only thing that knows where the view is, so `z`, `Z`, the
 * arrows, the wheel and `c` behave identically in both. A second copy of that
 * arithmetic is how two views of the same world end up disagreeing about
 * where a cat is.
 *
 * **Two resolutions, on purpose.** The map is a texture of one pixel per
 * *cell*, blown up by the GPU with nearest neighbour: painting it at window
 * resolution would be a million pixels a frame in PHP instead of ten thousand.
 * Text at that scale would come out in letters as tall as a lake, so the
 * panels are their own textures at true pixel size. World nearest-neighboured,
 * interface crisp — what every pixel art game does.
 *
 * **Nothing here decides what a panel says.** {@see Dashboard} does, and the
 * terminal reads the same thing, so the two cannot drift apart.
 *
 * A window is not always available — the container has no screen, exactly as
 * it has no sound card — and `Sdl::open()` answering null is a normal outcome
 * rather than an error. Composition still works without it, which is what lets
 * a frame be asserted on with no display anywhere near the test.
 */
final class SdlRender implements GameRenderInterface
{
    /** Pixels per rendered cell. A cell holds `Camera::scale()` tiles. */
    private const CELL = 8;

    private const SIDEBAR = 400;
    private const LOG_HEIGHT = 148;
    private const BAR_HEIGHT = 44;

    /**
     * Tiles per pixel of the overview. Two, so a 256x160 world comes to 128x80
     * — small enough to sit in the panel, big enough that a lake is a lake.
     */
    private const OVERVIEW_STEP = 2;

    /**
     * Frames between two rebuilds of the overview's ground — two seconds at
     * the render rate. It was rebuilt every frame and cost 14.3 ms of a 16 ms
     * frame, to redraw a world that changes when a cat eats a flower.
     */
    private const OVERVIEW_EVERY = 30;

    /**
     * How much the isometric buffer is blown up by. Two shows the landscape,
     * four puts one inside it — the same pixels, larger.
     */
    private const ISO_WIDE = 2;
    private const ISO_CLOSE = 4;

    private const TEXT = 2;
    private const LINE = 9 * self::TEXT;

    private const BACKGROUND = 0xFF16130F;
    private const BORDER = 0xFF3A342C;

    /** @var array<string, int> a tone from {@see Dashboard}, as a colour */
    private const TONES = [
        Dashboard::PLAIN => 0xFFC8C2B4,
        Dashboard::HEADING => 0xFFE8C05A,
        Dashboard::STRONG => 0xFFFFFFFF,
        Dashboard::ASIDE => 0xFF7A7468,
        Dashboard::WARNING => 0xFFE86A5A,
        Dashboard::GOOD => 0xFF7ACC6A,
    ];

    private ?FFI $sdl = null;

    private mixed $window = null;

    private mixed $renderer = null;

    private mixed $mapTexture = null;

    /** @var array<int, mixed> one streaming texture per isometric zoom */
    private array $isoTextures = [];

    private mixed $sidebarTexture = null;

    private mixed $bottomTexture = null;

    private mixed $mapArea = null;

    private mixed $sidebarArea = null;

    private mixed $bottomArea = null;

    private bool $started = false;

    private int $activeTab = 0;

    private BufferLogger $bufferLog;

    private CatSprite $cat;

    private IsoView $iso;


    /**
     * Which way the world is being looked at.
     *
     * Two ways of looking and not two worlds: the camera, the palette and the
     * dashboard are the same in both, so a cat is in the same place and the
     * meadow is the same green. `v` swaps them.
     */
    /** Two ways of looking, swapped by `v`. */
    private const VIEW_MAP = 0;
    private const VIEW_ISO = 1;

    private int $view = self::VIEW_MAP;

    /** Zoom levels given up on entering the isometric view, put back on exit. */
    private int $zoomAway = 0;

    private int $isoZoom = self::ISO_WIDE;

    private Dashboard $dashboard;

    private ?float $startedAt = null;

    private float $phase = 0.0;

    /** @var array<int, array<int, int>> floating cats, keyed [row][column] */
    private array $markers = [];

    /**
     * The follow button's box in cells, recorded while it is drawn.
     *
     * @var array{int, int, int, int}|null
     */
    private ?array $followButton = null;

    /**
     * The shape of the surface, or null where there is none. Held here and
     * never by the world: it is regenerated with the map from the same seed,
     * and anything `World` can reach is serialized into every snapshot.
     */
    private ?Relief $relief = null;

    /**
     * The overview's box in cells, recorded while it is drawn.
     *
     * @var array{int, int, int, int}|null
     */
    private ?array $overview = null;

    /** @var array<int, array<int, string>> */
    private array $lastMap = [];

    /**
     * The overview's terrain, kept between frames.
     *
     * **Measured, it was 14.3 ms of a 16 ms frame** — ten thousand tiles asked
     * of the palette, fifteen times a second, to show a world that does not
     * change fifteen times a second. The view box and the cats are drawn over
     * it on every frame because those *do* move; the ground underneath is
     * rebuilt on a timer and whenever a new map arrives.
     *
     * @var list<int>|null
     */
    private ?array $overviewGround = null;

    /** Frames until the overview's ground is worth looking at again. */
    private int $overviewAge = 0;

    /**
     * The panels, kept between frames along with what they were built from.
     *
     * **Measured, they were most of the frame in every view**: a sidebar of
     * 400x640 and a strip of 1424x192, filled and written and packed fifteen
     * times a second to say the same thing. Composition fell from 12.1 ms to
     * 2.4 in the map view once they stopped being rebuilt for nothing.
     *
     * This is what php-tui does for the terminal and the window was not doing:
     * a frame is not the whole picture, it is the part that changed.
     */
    private ?Pixels $sidebarCache = null;

    private string $sidebarKey = '';

    private ?Pixels $bottomCache = null;

    private string $bottomKey = '';

    /** Frames drawn, which is how the panels know when to look again. */
    private int $frames = 0;

    /** The panels last handed to the screen, so an unchanged one is not resent. */
    private ?Pixels $sentSidebar = null;

    private ?Pixels $sentBottom = null;

    /** The map last drawn, kept so a click on the overview knows its size. */
    private int $worldWidth = 0;

    private int $worldHeight = 0;

    public function __construct(
        private MultipleLogger $logger,
        private WorldContainer $worldContainer,
        private MemoryManager $memoryManager,
        private TimeControl $timeControl = new TimeControl(),
        private ?TilePalette $palette = null,
        private Camera $camera = new Camera(),
        private int $mapWidth = 1024,
        private int $mapHeight = 640,
        private Sdl $binding = new Sdl(),
        private MemoryUsage $memoryUsage = new MemoryUsage(),
        private ?\Closure $clock = null,
    ) {
        // A window always has every colour, so the sixteen colour fallback is
        // never *detected* here — it can still be asked for with --colours,
        // which is how the two renderers are compared on the same palette.
        // Twenty four steps rather than the terminal's six: quantising the
        // swell is a budget of escape sequences, and a window sends none. On a
        // lake several hundred pixels across, six reads as bands.
        $this->palette ??= new TilePalette(trueColor: true, swellSteps: 24);
        $this->clock ??= static fn (): float => microtime(true);
        $this->cat = new CatSprite();
        $this->iso = new IsoView($this->palette);
        $this->dashboard = new Dashboard($this->memoryManager, $this->memoryUsage);
        $this->bufferLog = new BufferLogger();
        $this->logger->addLogger($this->bufferLog);
    }

    public function init(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        $this->sdl = $this->binding->open();

        // **Said out loud, never swallowed.** Composition works without SDL —
        // that is what lets a frame be asserted on with no display — so a
        // failed binding would otherwise leave the game running perfectly and
        // showing nothing at all, which is an hour of looking in the wrong
        // place.
        if (null === $this->sdl) {
            $this->logger->log(LogLevel::ERROR, 'pas de fenetre : libsdl2 est introuvable');
            fwrite(STDERR, "pas de fenetre : libsdl2 est introuvable (brew install sdl2)\n");

            return;
        }

        if (0 !== $this->sdl->SDL_Init(Sdl::INIT_VIDEO)) {
            $error = FFI::string($this->sdl->SDL_GetError());
            $this->sdl = null;
            $this->logger->log(LogLevel::ERROR, 'SDL_Init a echoue : ' . $error);
            fwrite(STDERR, sprintf("pas de fenetre : %s\n", $error));

            return;
        }

        $this->window = $this->sdl->SDL_CreateWindow(
            'cat-ia',
            Sdl::WINDOWPOS_CENTERED,
            Sdl::WINDOWPOS_CENTERED,
            $this->mapWidth + self::SIDEBAR,
            $this->mapHeight + self::LOG_HEIGHT + self::BAR_HEIGHT,
            Sdl::WINDOW_SHOWN
        );

        $this->renderer = $this->sdl->SDL_CreateRenderer($this->window, -1, Sdl::RENDERER);

        $view = $this->getSize();
        $this->mapTexture = $this->texture($view['x'], $view['y']);
        // The isometric view needs real pixels — a lattice of diamonds cannot
        // be blown up from one pixel a cell — so it has a texture of its own,
        // at half the area it fills. Measured, a whole screen of ground costs
        // 15 ms a frame at full size against 4.8 at half.
        // One per zoom: a streaming texture has a fixed size, and swapping
        // zoom must not mean destroying and rebuilding one mid frame.
        foreach ([self::ISO_WIDE, self::ISO_CLOSE] as $zoom) {
            $this->isoTextures[$zoom] = $this->texture(
                intdiv($this->mapWidth, $zoom),
                intdiv($this->mapHeight, $zoom)
            );
        }
        $this->sidebarTexture = $this->texture(self::SIDEBAR, $this->mapHeight);
        $this->bottomTexture = $this->texture(
            $this->mapWidth + self::SIDEBAR,
            self::LOG_HEIGHT + self::BAR_HEIGHT
        );

        $this->mapArea = $this->area(0, 0, $this->mapWidth, $this->mapHeight);
        $this->sidebarArea = $this->area($this->mapWidth, 0, self::SIDEBAR, $this->mapHeight);
        $this->bottomArea = $this->area(
            0,
            $this->mapHeight,
            $this->mapWidth + self::SIDEBAR,
            self::LOG_HEIGHT + self::BAR_HEIGHT
        );

        $this->sdl->SDL_RaiseWindow($this->window);
    }

    public function close(): void
    {
        if (!$this->started || null === $this->sdl) {
            $this->started = false;

            return;
        }

        $this->started = false;

        foreach (array_merge([$this->mapTexture, $this->sidebarTexture, $this->bottomTexture], array_values($this->isoTextures)) as $texture) {
            if (null !== $texture) {
                $this->sdl->SDL_DestroyTexture($texture);
            }
        }

        if (null !== $this->renderer) {
            $this->sdl->SDL_DestroyRenderer($this->renderer);
        }

        if (null !== $this->window) {
            $this->sdl->SDL_DestroyWindow($this->window);
        }

        $this->sdl->SDL_Quit();
    }

    /**
     * The view, in tiles. A cell is square here — there is no half block
     * trick, so both axes are counted the same way, unlike the terminal where
     * a row of cells is two rows of tiles.
     *
     * @return array{x: int, y: int}
     */
    public function getSize(): array
    {
        if (self::VIEW_MAP !== $this->view) {
            // Both world axes run diagonally there, so a rectangle of screen
            // is a diamond of world and the coverage is not the area divided
            // by a cell.
            return IsoView::coverage($this->isoWidth(), $this->isoHeight());
        }

        return [
            'x' => intdiv($this->mapWidth, self::CELL),
            'y' => intdiv($this->mapHeight, self::CELL),
        ];
    }

    public function render($map): void
    {
        $this->init();

        $now = ($this->clock)();
        $this->startedAt ??= $now;
        $this->phase = $now - $this->startedAt;
        $this->palette->animate($this->phase);

        [$mapPixels, $sidebar, $bottom] = $this->compose($map);

        if (null === $this->sdl) {
            return;
        }

        $surface = self::VIEW_MAP === $this->view
            ? $this->mapTexture
            : $this->isoTextures[$this->isoZoom];
        $this->sdl->SDL_UpdateTexture($surface, null, $mapPixels->bytes(), $mapPixels->width() * 4);

        // Packing a panel costs a millisecond each, so an unchanged one is not
        // packed and not uploaded: the texture already holds it. Identity is
        // the test, because an unchanged panel is literally the same object —
        // that is what the cache hands back.
        if ($sidebar !== $this->sentSidebar) {
            $this->sdl->SDL_UpdateTexture($this->sidebarTexture, null, $sidebar->bytes(), $sidebar->width() * 4);
            $this->sentSidebar = $sidebar;
        }

        if ($bottom !== $this->sentBottom) {
            $this->sdl->SDL_UpdateTexture($this->bottomTexture, null, $bottom->bytes(), $bottom->width() * 4);
            $this->sentBottom = $bottom;
        }

        $this->sdl->SDL_RenderClear($this->renderer);
        $this->sdl->SDL_RenderCopy($this->renderer, $surface, null, FFI::addr($this->mapArea));
        $this->sdl->SDL_RenderCopy($this->renderer, $this->sidebarTexture, null, FFI::addr($this->sidebarArea));
        $this->sdl->SDL_RenderCopy($this->renderer, $this->bottomTexture, null, FFI::addr($this->bottomArea));
        $this->sdl->SDL_RenderPresent($this->renderer);
    }

    /**
     * The three surfaces of a frame, with no SDL involved.
     *
     * Public because it is the seam a test uses: the terminal renderer can be
     * asserted on through php-tui's recording backend, and this is the same
     * thing for the window — a whole frame, built and readable, with no
     * display anywhere.
     *
     * @param array<int, array<int, string>> $map
     *
     * @return array{0: Pixels, 1: Pixels, 2: Pixels}
     */
    public function compose(array $map): array
    {
        ++$this->frames;
        $this->worldHeight = count($map);
        $this->worldWidth = count($map[0] ?? []);
        $this->lastMap = $map;

        return [
            self::VIEW_MAP === $this->view ? $this->paintMap($map) : $this->paintIso($map),
            $this->paintSidebar(),
            $this->paintBottom(),
        ];
    }

    /**
     * Swap the two ways of looking. Bound to `v`.
     *
     * **The isometric view is always 1:1, and the zoom is put back on the way
     * out.** Its coverage is a diamond of some seventy cells a side, so at 1:4
     * it asks the world for nearly three hundred rows where there are a
     * hundred and sixty: everything past the edge is skipped and the picture
     * closes into a wedge with sky around it. Sampling would be meaningless
     * there anyway — a tree standing for eight tiles says nothing — and the
     * two views already divide the work between them: the map is the world at
     * a glance, this is the close look.
     */
    public function toggleView(): bool
    {
        $was = $this->view;
        $this->view = ($this->view + 1) % 2;

        // Captured on the way *out* of the map and nowhere else, so that
        // swapping back and forth cannot quietly lose it: by the second swap
        // the camera is already at its closest and a fresh count would be
        // zero.
        if (self::VIEW_MAP === $was) {
            $this->zoomAway = 0;

            while (!$this->camera->isClosest()) {
                $this->camera->zoomIn();
                ++$this->zoomAway;
            }
        }

        if (self::VIEW_MAP === $this->view) {
            for ($step = 0; $step < $this->zoomAway; $step++) {
                $this->camera->zoomOut();
            }

            $this->zoomAway = 0;

            return false;
        }

        return true;
    }

    /**
     * **In the isometric view, closer means a bigger tile and not a bigger
     * bite of the world.**
     *
     * Sampling is what a map does, and it is exactly what an isometric view
     * cannot do: a tree standing for eight tiles is not a smaller tree, it is
     * a wrong one, and the coverage diamond runs off the edge of the world
     * long before the screen fills. So the buffer is composed *smaller* and
     * blown up further — a tile drawn 16 pixels wide and scaled by four is 64
     * on screen, out of the same art. It costs less than the wide view rather
     * than more, there being a quarter as many tiles to paint.
     */
    public function zoom(bool $closer): bool
    {
        if (self::VIEW_MAP === $this->view) {
            $closer ? $this->camera->zoomIn() : $this->camera->zoomOut();

            return true;
        }

        $wanted = $closer ? self::ISO_CLOSE : self::ISO_WIDE;

        if ($wanted === $this->isoZoom) {
            return false;
        }

        $this->isoZoom = $wanted;

        return true;
    }

    public function isIsometric(): bool
    {
        return self::VIEW_MAP !== $this->view;
    }

    /**
     * The world as a lattice of diamonds, with what stands on it drawn as
     * shapes rather than as coloured squares.
     *
     * @param array<int, array<int, string>> $map
     */
    private function paintIso(array $map): Pixels
    {
        $cats = [];
        $level = $this->selectedPlayer()?->getNiveau();

        foreach (array_values($this->players()) as $index => $player) {
            if (null !== $level && $player->getNiveau() !== $level) {
                continue;
            }

            $colours = $this->palette->catColours($index);
            $cats[$player->getPosition()->getX() . ';' . $player->getPosition()->getY()] = [
                'coat' => Pixels::pack($colours['coat']),
                'patch' => Pixels::pack($colours['patch']),
                'eye' => Pixels::pack($colours['eye']),
                'outline' => Pixels::pack($colours['outline']),
            ];
        }

        return $this->iso->paint($map, $this->camera, $this->isoWidth(), $this->isoHeight(), $cats);
    }

    /**
     * The same colour, on ground that leans towards the light or away from it.
     *
     * The cheapest of the ways of showing a third dimension and the only one
     * that costs the layout nothing: a tile stays in its cell, so the camera,
     * the markers and the sight band cannot tell the difference.
     */
    private static function lit(int $colour, float $factor): int
    {
        $r = max(0, min(255, (int) round((($colour >> 16) & 0xFF) * $factor)));
        $g = max(0, min(255, (int) round((($colour >> 8) & 0xFF) * $factor)));
        $b = max(0, min(255, (int) round(($colour & 0xFF) * $factor)));

        return 0xFF000000 | ($r << 16) | ($g << 8) | $b;
    }

    private function isoWidth(): int
    {
        return intdiv($this->mapWidth, $this->isoZoom);
    }

    private function isoHeight(): int
    {
        return intdiv($this->mapHeight, $this->isoZoom);
    }

    public function clear($map): void
    {
    }

    /**
     * A window has no diff to throw away — every frame is whole — so there is
     * nothing to force. The method exists because the loop asks for it, and
     * doing nothing is the honest answer rather than a redraw for show.
     */
    public function repaint(): void
    {
    }

    public function setRelief(?Relief $relief): void
    {
        // A new map arrives with its own ground, so the overview cannot keep
        // the old one: `r` would otherwise show the previous world.
        $this->overviewGround = null;
        $this->relief = $relief;
        $this->iso->setRelief($relief);
    }

    public function nextTab(): void
    {
        $count = count($this->players());

        if ($count > 0) {
            $this->activeTab = ($this->activeTab + 1) % $count;
        }
    }

    public function selectTab(int $index): void
    {
        if ($index >= 0 && $index < count($this->players())) {
            $this->activeTab = $index;
        }
    }

    public function selectedPlayer(): ?PlayerInterface
    {
        return array_values($this->players())[$this->activeTab] ?? null;
    }

    public function focusOnSelectedPlayer(): bool
    {
        $player = $this->selectedPlayer();

        if (null === $player) {
            return false;
        }

        $this->camera->centreOn($player->getPosition()->getX(), $player->getPosition()->getY());

        return true;
    }

    /**
     * Whether a position falls on the map. The loop hands over cells, which is
     * what the input controller has already turned pixels into — the renderer
     * decided the cell size, so it is the renderer that converts.
     */
    public function isOverMap(int $column, int $row): bool
    {
        $view = $this->getSize();

        return $column >= 0 && $row >= 0 && $column < $view['x'] && $row < $view['y'];
    }

    /**
     * Cells to tiles. They are already the same thing here — a cell is square
     * and holds one row of tiles — where the terminal has to count a row as
     * two, its cells being half blocks.
     *
     * @return array{int, int}
     */
    public function toCells(int $columns, int $rows): array
    {
        return [$columns, $rows];
    }

    /**
     * Whether a click landed on the "follow this cat" button.
     *
     * Its box is recorded while the sidebar is drawn rather than worked out a
     * second time from the layout — computing it twice is how a button ends up
     * a few pixels away from itself, which the terminal learned once already.
     * The loop hands over cells, so the box is kept in cells.
     */
    public function isOverFocusButton(int $column, int $row): bool
    {
        if (null === $this->followButton) {
            return false;
        }

        [$left, $top, $right, $bottom] = $this->followButton;

        return $column >= $left && $column <= $right && $row >= $top && $row <= $bottom;
    }

    /**
     * The map, one pixel per cell, scaled up by the GPU afterwards.
     *
     * Sampled and not averaged, exactly as in the terminal: reading every tile
     * of every block would be sixty four lookups a cell at 1:8, and terrain is
     * contiguous enough that one tile speaks for its neighbours. Players are
     * drawn from their own positions afterwards, so a cat is never sampled
     * away.
     *
     * @param array<int, array<int, string>> $map
     */
    private function paintMap(array $map): Pixels
    {
        $view = $this->getSize();
        $height = count($map);
        $width = count($map[0] ?? []);

        $this->camera->clamp($width, $height, $view['x'], $view['y']);

        $scale = $this->camera->scale();
        $originX = $this->camera->x();
        $originY = $this->camera->y();

        $players = $this->visiblePlayers($originX, $originY, $scale, $view);
        $sightEdge = $this->sightEdge($scale);
        $this->markers = $this->floatingCats($originX, $originY, $scale, $view);
        $edgeColour = Pixels::pack($this->palette->sightEdgeColour());

        $pixels = new Pixels($view['x'], $view['y'], self::BACKGROUND);

        for ($row = 0; $row < $view['y']; $row++) {
            $worldY = $originY + $row * $scale;

            if ($worldY >= $height) {
                break;
            }

            for ($column = 0; $column < $view['x']; $column++) {
                $worldX = $originX + $column * $scale;

                if ($worldX >= $width) {
                    break;
                }

                if (isset($this->markers[$row][$column])) {
                    $pixels->set($column, $row, $this->markers[$row][$column]);

                    continue;
                }

                if (isset($sightEdge[$worldY * $width + $worldX])) {
                    $pixels->set($column, $row, $edgeColour);

                    continue;
                }

                $tile = $players[$row][$column] ?? $map[$worldY][$worldX] ?? MapBuilder::HERBE;
                $colour = $this->palette->packed($tile, $worldX, $worldY);

                // **A lake has a surface, not a slope.** The elevation carries
                // on below the water line, so lighting a lake draws the bottom
                // of it as though that were the top and a bay comes out with a
                // hillside in it. A cat is not ground either: it is the one
                // thing here that must never be dimmed by where it stands.
                if (null !== $this->relief
                    && MapBuilder::EAU !== $tile
                    && null === TilePalette::playerIndex($tile)) {
                    $colour = self::lit($colour, $this->relief->light($worldX, $worldY));
                }

                $pixels->set($column, $row, $colour);
            }
        }

        return $pixels;
    }

    private function paintSidebar(): Pixels
    {
        $key = $this->sidebarSays();

        if ($key === $this->sidebarKey && null !== $this->sidebarCache) {
            return $this->sidebarCache;
        }

        $this->sidebarKey = $key;

        $pixels = new Pixels(self::SIDEBAR, $this->mapHeight, self::BACKGROUND);
        $pixels->frame(0, 0, self::SIDEBAR, $this->mapHeight, self::BORDER);

        $players = array_values($this->players());
        $this->activeTab = [] === $players ? 0 : min($this->activeTab, count($players) - 1);
        $y = 12;

        // The tabs, one per cat, the selected one written in full.
        $pen = 12;

        foreach ($players as $index => $player) {
            $label = ' ' . TilePalette::playerMarker($index) . ' ' . $player->getIdentifiant() . ' ';
            $width = BitmapFont::widthOf($label, self::TEXT);

            if ($index === $this->activeTab) {
                $pixels->rect($pen - 2, $y - 3, $width + 4, self::LINE, 0xFF2C5F8F);
            }

            $pen = BitmapFont::write($pixels, $pen, $y, $label, 0xFFFFFFFF, self::TEXT);
        }

        $y += self::LINE + 8;

        foreach ($this->dashboard->forPlayer($this->selectedPlayer(), $this->activeTab) as $line) {
            BitmapFont::write($pixels, 12, $y, $line['text'], self::TONES[$line['tone']], self::TEXT);
            $y += self::LINE;
        }

        // **Stacked from the bottom in one chain, never measured separately.**
        // The memory panel, the button and the overview were each positioned
        // from the foot of the panel by their own expression, so none of them
        // knew the others were there and the overview came down on top of the
        // button. Three sums from the same edge is how things land on each
        // other; each one now starts where the one below it ended.
        $memory = $this->dashboard->memory();
        $memoryTop = $this->mapHeight - 12 - self::LINE * count($memory);

        $buttonHeight = self::LINE + 6;
        $buttonTop = $memoryTop - 14 - $buttonHeight;

        $following = $this->camera->isFollowing();
        $label = $following ? ' l : ne plus suivre ' : ' l : suivre le chat ';
        $buttonWidth = BitmapFont::widthOf($label, self::TEXT) + 8;

        $pixels->rect(12, $buttonTop, $buttonWidth, $buttonHeight, $following ? 0xFF2F6B35 : 0xFF2C5F8F);
        BitmapFont::write($pixels, 16, $buttonTop + 5, $label, 0xFFFFFFFF, self::TEXT);

        // In cells, because that is what the loop hands back from the mouse.
        $this->followButton = [
            intdiv($this->mapWidth + 12, self::CELL),
            intdiv($buttonTop, self::CELL),
            intdiv($this->mapWidth + 12 + $buttonWidth, self::CELL),
            intdiv($buttonTop + $buttonHeight, self::CELL),
        ];

        $this->drawOverview($pixels, $buttonTop - 16);

        $y = $memoryTop;

        foreach ($memory as $line) {
            BitmapFont::write($pixels, 12, $y, $line['text'], self::TONES[$line['tone']], self::TEXT);
            $y += self::LINE;
        }

        return $this->sidebarCache = $pixels;
    }

    /**
     * The whole world, small, with the view drawn on it.
     *
     * **It lives in the panel and not over the map**, because the panel is at
     * true pixel size while the isometric view is composed at half and blown
     * up: an overview drawn there would come out as soft as the ground it is
     * meant to help you leave. It is also the one thing the isometric view
     * cannot give at all — that one is always 1:1 and shows a few thousand
     * tiles out of forty thousand, so *where am I* stops being answerable
     * from the picture itself.
     *
     * Sampled every other tile: forty thousand writes a frame to say something
     * a quarter of that says just as well, and terrain is contiguous enough
     * that one tile speaks for its neighbour — the same trade the map view
     * makes when it is zoomed out.
     */
    private function drawOverview(Pixels $pixels, int $bottom): void
    {
        if ([] === $this->lastMap) {
            $this->overview = null;

            return;
        }

        $width = intdiv($this->worldWidth, self::OVERVIEW_STEP);
        $height = intdiv($this->worldHeight, self::OVERVIEW_STEP);
        $left = intdiv(self::SIDEBAR - $width, 2);
        $top = $bottom - $height;

        $pixels->frame($left - 2, $top - 2, $width + 4, $height + 4, self::BORDER);

        // Rebuilt on a timer rather than on a change: knowing whether the map
        // moved would mean hashing forty thousand tiles, which costs more than
        // the drawing it would save. A flower eaten somewhere shows up a
        // second or two late on a map this small, where it is one pixel.
        if (null === $this->overviewGround || --$this->overviewAge <= 0) {
            $ground = [];

            for ($y = 0; $y < $height; $y++) {
                $worldY = $y * self::OVERVIEW_STEP;

                for ($x = 0; $x < $width; $x++) {
                    $worldX = $x * self::OVERVIEW_STEP;
                    $tile = $this->lastMap[$worldY][$worldX] ?? MapBuilder::HERBE;
                    $ground[] = $this->palette->packed($tile, $worldX, $worldY);
                }
            }

            $this->overviewGround = $ground;
            $this->overviewAge = self::OVERVIEW_EVERY;
        }

        $at = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixels->set($left + $x, $top + $y, $this->overviewGround[$at++]);
            }
        }

        // Where the view is. Drawn as an outline rather than a tint, because a
        // tint over a map this small hides the very thing one is aiming at.
        $view = $this->getSize();
        $pixels->frame(
            $left + intdiv($this->camera->x(), self::OVERVIEW_STEP),
            $top + intdiv($this->camera->y(), self::OVERVIEW_STEP),
            max(3, intdiv($view['x'] * $this->camera->scale(), self::OVERVIEW_STEP)),
            max(3, intdiv($view['y'] * $this->camera->scale(), self::OVERVIEW_STEP)),
            0xFFF2E8C0
        );

        // The cats, drawn last and two pixels across: one pixel of a cat on a
        // map of forty thousand tiles is not something anyone can aim at.
        foreach (array_values($this->players()) as $index => $player) {
            $colours = $this->palette->catColours($index);
            $pixels->rect(
                $left + intdiv($player->getPosition()->getX(), self::OVERVIEW_STEP) - 1,
                $top + intdiv($player->getPosition()->getY(), self::OVERVIEW_STEP) - 1,
                3,
                3,
                Pixels::pack($colours['coat'])
            );
        }

        $this->overview = [
            intdiv($this->mapWidth + $left, self::CELL),
            intdiv($top, self::CELL),
            intdiv($this->mapWidth + $left + $width, self::CELL),
            intdiv($top + $height, self::CELL),
        ];
    }

    /**
     * A click on the overview, taken as "put me there".
     *
     * Answers false when the click was somewhere else, so the loop can carry
     * on offering it to the map — the overview is small and sits inside the
     * panel, and asking it first costs one comparison.
     */
    public function jumpTo(int $column, int $row): bool
    {
        if (null === $this->overview) {
            return false;
        }

        [$left, $top, $right, $bottom] = $this->overview;

        if ($column < $left || $column > $right || $row < $top || $row > $bottom) {
            return false;
        }

        $this->camera->centreOn(
            ($column - $left) * self::CELL * self::OVERVIEW_STEP,
            ($row - $top) * self::CELL * self::OVERVIEW_STEP
        );

        return true;
    }

    private function paintBottom(): Pixels
    {
        $key = $this->bottomSays();

        if ($key === $this->bottomKey && null !== $this->bottomCache) {
            return $this->bottomCache;
        }

        $this->bottomKey = $key;
        $width = $this->mapWidth + self::SIDEBAR;
        $pixels = new Pixels($width, self::LOG_HEIGHT + self::BAR_HEIGHT, self::BACKGROUND);
        $pixels->frame(0, 0, $width, self::LOG_HEIGHT, self::BORDER);

        $rows = intdiv(self::LOG_HEIGHT - 16, self::LINE);
        $y = 10;

        foreach (array_slice($this->bufferLog->getLogs(), -$rows) as $line) {
            BitmapFont::write($pixels, 12, $y, $line, self::TONES[Dashboard::ASIDE], self::TEXT);
            $y += self::LINE;
        }

        $observed = $this->timeControl->observedMultiplier();
        $state = sprintf(
            '%s espace    %s    %s    %s',
            $this->timeControl->isPaused() ? '▶' : '▮▮',
            $this->timeControl->speedLabel(),
            $this->camera->label(),
            self::VIEW_MAP === $this->view
                ? 'v 2.5d'
                : sprintf('v carte    z de pres (x%d)', $this->isoZoom)
        );

        BitmapFont::write(
            $pixels,
            12,
            self::LOG_HEIGHT + 14,
            $state,
            $this->timeControl->isLagging() ? self::TONES[Dashboard::WARNING] : self::TONES[Dashboard::PLAIN],
            self::TEXT
        );

        if (null !== $observed && $this->timeControl->isLagging()) {
            BitmapFont::write(
                $pixels,
                $width - 200,
                self::LOG_HEIGHT + 14,
                sprintf('reel x%s', number_format($observed, 1)),
                self::TONES[Dashboard::WARNING],
                self::TEXT
            );
        }

        return $this->bottomCache = $pixels;
    }

    /**
     * Everything the sidebar draws, as one string.
     *
     * Compared rather than hashed: these are already the strings that will be
     * written, so building the key is the cheap part of the work that is being
     * skipped. The frame count in it is what lets the overview's ground look
     * at the world again every so often — a flower eaten changes nothing else
     * here, and hashing forty thousand tiles to notice would cost more than
     * the drawing it saves.
     */
    private function sidebarSays(): string
    {
        $parts = [
            $this->activeTab,
            $this->camera->isFollowing() ? 'l' : '',
            $this->camera->x() . ';' . $this->camera->y() . ';' . $this->camera->scale(),
            intdiv($this->frames, self::OVERVIEW_EVERY),
        ];

        foreach ($this->players() as $player) {
            $parts[] = $player->getIdentifiant() . ':' . $player->getPosition();
        }

        foreach ($this->dashboard->forPlayer($this->selectedPlayer(), $this->activeTab) as $line) {
            $parts[] = $line['text'];
        }

        foreach ($this->dashboard->memory() as $line) {
            $parts[] = $line['text'];
        }

        return implode('|', $parts);
    }

    private function bottomSays(): string
    {
        return implode('|', [
            $this->timeControl->isPaused() ? 'p' : 'r',
            $this->timeControl->speedLabel(),
            $this->camera->label(),
            $this->view,
            $this->isoZoom,
            $this->timeControl->isLagging() ? number_format((float) $this->timeControl->observedMultiplier(), 1) : '',
            implode("\n", $this->bufferLog->getLogs()),
        ]);
    }

    /**
     * @return array<string, PlayerInterface>
     */
    private function players(): array
    {
        return $this->worldContainer->getWorld()?->getPlayerCollection() ?? [];
    }

    /**
     * @param array{x: int, y: int} $view
     *
     * @return array<int, array<int, string>>
     */
    private function visiblePlayers(int $originX, int $originY, int $scale, array $view): array
    {
        $placed = [];
        $level = $this->selectedPlayer()?->getNiveau();

        foreach (array_values($this->players()) as $index => $player) {
            if (null !== $level && $player->getNiveau() !== $level) {
                continue;
            }

            $x = $player->getPosition()->getX();
            $y = $player->getPosition()->getY();

            if ($x < $originX || $y < $originY) {
                continue;
            }

            $column = intdiv($x - $originX, $scale);
            $row = intdiv($y - $originY, $scale);

            if ($column < $view['x'] && $row < $view['y']) {
                $placed[$row][$column] = (string) ($index + 1);
            }
        }

        return $placed;
    }

    /**
     * @param array{x: int, y: int} $view
     *
     * @return array<int, array<int, int>>
     */
    private function floatingCats(int $originX, int $originY, int $scale, array $view): array
    {
        $placed = [];
        $level = $this->selectedPlayer()?->getNiveau();

        foreach (array_values($this->players()) as $index => $player) {
            if (null !== $level && $player->getNiveau() !== $level) {
                continue;
            }

            $x = $player->getPosition()->getX();
            $y = $player->getPosition()->getY();

            if ($x < $originX || $y < $originY) {
                continue;
            }

            $column = intdiv($x - $originX, $scale);
            $row = intdiv($y - $originY, $scale);

            if ($column >= $view['x'] || $row >= $view['y']) {
                continue;
            }

            foreach ($this->cat->over($this->palette, $index, $this->phase) as $dy => $line) {
                foreach ($line as $dx => $colour) {
                    $spriteRow = $row + $dy;
                    $spriteColumn = $column + $dx;

                    if ($spriteRow < 0 || $spriteColumn < 0
                        || $spriteRow >= $view['y'] || $spriteColumn >= $view['x']) {
                        continue;
                    }

                    $placed[$spriteRow][$spriteColumn] = Pixels::pack($colour);
                }
            }
        }

        return $placed;
    }

    /**
     * @return array<int, true>
     */
    private function sightEdge(int $scale): array
    {
        $world = $this->worldContainer->getWorld();
        $player = $this->selectedPlayer();

        if (!$world instanceof World || null === $player) {
            return [];
        }

        $range = $player->getVision();
        $budget = PathFinder::budgetFor($range);
        $band = $scale * PathFinder::budgetFor(1);
        $edge = [];

        foreach ((new PathFinder($world->mapFor($player)))->costsWithin($player->getPosition(), $range) as $index => $cost) {
            if ($cost > $budget - $band) {
                $edge[$index] = true;
            }
        }

        return $edge;
    }

    private function texture(int $width, int $height): mixed
    {
        return $this->sdl?->SDL_CreateTexture(
            $this->renderer,
            Sdl::PIXELFORMAT_ARGB8888,
            Sdl::TEXTUREACCESS_STREAMING,
            $width,
            $height
        );
    }

    private function area(int $x, int $y, int $width, int $height): mixed
    {
        $rect = $this->sdl?->new('SdlRect');

        if (null === $rect) {
            return null;
        }

        $rect->x = $x;
        $rect->y = $y;
        $rect->w = $width;
        $rect->h = $height;

        return $rect;
    }
}
