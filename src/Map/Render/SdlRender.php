<?php

declare(strict_types=1);

namespace Map\Render;

use FFI;
use Logger\BufferLogger;
use Logger\MultipleLogger;
use Map\Builder\MapBuilder;
use Map\Path\PathFinder;
use Map\Player\PlayerInterface;
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

    private mixed $sidebarTexture = null;

    private mixed $bottomTexture = null;

    private mixed $mapArea = null;

    private mixed $sidebarArea = null;

    private mixed $bottomArea = null;

    private bool $started = false;

    private int $activeTab = 0;

    private BufferLogger $bufferLog;

    private CatSprite $cat;

    private Dashboard $dashboard;

    private ?float $startedAt = null;

    private float $phase = 0.0;

    /** @var array<int, array<int, int>> floating cats, keyed [row][column] */
    private array $markers = [];

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
        $this->palette ??= new TilePalette(trueColor: true);
        $this->clock ??= static fn (): float => microtime(true);
        $this->cat = new CatSprite();
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

        foreach ([$this->mapTexture, $this->sidebarTexture, $this->bottomTexture] as $texture) {
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

        $this->sdl->SDL_UpdateTexture($this->mapTexture, null, $mapPixels->bytes(), $mapPixels->width() * 4);
        $this->sdl->SDL_UpdateTexture($this->sidebarTexture, null, $sidebar->bytes(), $sidebar->width() * 4);
        $this->sdl->SDL_UpdateTexture($this->bottomTexture, null, $bottom->bytes(), $bottom->width() * 4);

        $this->sdl->SDL_RenderClear($this->renderer);
        $this->sdl->SDL_RenderCopy($this->renderer, $this->mapTexture, null, FFI::addr($this->mapArea));
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
        return [$this->paintMap($map), $this->paintSidebar(), $this->paintBottom()];
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

    /** No button to press: the window has room to write the hint instead. */
    public function isOverFocusButton(int $column, int $row): bool
    {
        return false;
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
                $pixels->set($column, $row, Pixels::pack($this->palette->pixel($tile, $worldX, $worldY)));
            }
        }

        return $pixels;
    }

    private function paintSidebar(): Pixels
    {
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

        // The memory panel sits at the bottom, where it does not push the AI
        // about as the cat's goals come and go.
        $y = $this->mapHeight - 12 - self::LINE * count($this->dashboard->memory());

        foreach ($this->dashboard->memory() as $line) {
            BitmapFont::write($pixels, 12, $y, $line['text'], self::TONES[$line['tone']], self::TEXT);
            $y += self::LINE;
        }

        return $pixels;
    }

    private function paintBottom(): Pixels
    {
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
            $this->timeControl->isTimeMachine() ? 't machine' : 'z zoom  fleches vue  c centrer  q quitter'
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

        return $pixels;
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
