<?php

declare(strict_types=1);

namespace Map\Render;

use Audio\SoundBoard;
use IA\CatIA;
use Logger\BufferLogger;
use Logger\MultipleLogger;
use Map\Builder\MapBuilder;
use Map\Path\PathFinder;
use Map\Player\Chat\Peur;
use Map\Player\PlayerHasEstomac;
use Map\Player\PlayerHasPeur;
use Map\Player\PlayerInterface;
use Map\World\World;
use Map\World\WorldContainer;
use Memory\MemoryManager;
use PhpTui\Term\Actions;
use PhpTui\Term\ClearType;
use PhpTui\Term\Terminal;
use PhpTui\Term\TerminalInformation\Size;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Color\Color;
use PhpTui\Tui\Display\Backend;
use PhpTui\Tui\Display\Display;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\TabsWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Line;
use PhpTui\Tui\Text\Span;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Tui\Widget\Widget;
use Runtime\Camera;
use Runtime\MemoryUsage;
use Runtime\TimeControl;

/**
 * Full screen dashboard built on php-tui.
 *
 * Everything is emitted as ANSI escape sequences, so no PHP extension is
 * involved. The game owns the alternate screen buffer for its whole lifetime,
 * which is what keeps the UI a fixed dashboard instead of output scrolling
 * after the shell prompt.
 */
class TuiRender implements MapRenderInterface
{
    private const SIDEBAR_WIDTH = 34;
    private const LOG_HEIGHT = 8;
    private const CONTROL_HEIGHT = 3;
    private const MEMORY_HEIGHT = 6;

    private ?Display $display = null;

    private bool $started = false;

    private BufferLogger $bufferLog;

    /** Index of the AI tab currently shown in the sidebar. */
    private int $activeTab = 0;

    /**
     * Half block rendering: a tile becomes half a cell instead of two columns,
     * which puts four times as many of them on screen.
     */
    private bool $fine = false;

    /**
     * Screen row of the "centre the view" button, recorded while the panel is
     * built rather than worked out afterwards. Computing it from the layout a
     * second time is how a button ends up one row away from where it is drawn.
     */
    private ?int $focusRow = null;

    public function __construct(
        private Terminal $terminal,
        private MultipleLogger $logger,
        private WorldContainer $worldContainer,
        private MemoryManager $memoryManager,
        private TimeControl $timeControl = new TimeControl(),
        private ?Backend $backend = null,
        private MemoryUsage $memoryUsage = new MemoryUsage(),
        private ?TilePalette $palette = null,
        private ?SoundBoard $audio = null,
        private Camera $camera = new Camera(),
        private bool $mouse = true,
    ) {
        $this->palette ??= TilePalette::detect();
        $this->bufferLog = new BufferLogger();
        $this->logger->addLogger($this->bufferLog);
    }

    public function init(): void
    {
        if ($this->started) {
            return;
        }

        $this->display = DisplayBuilder::default(
            $this->backend ?? PhpTermBackend::new($this->terminal)
        )->fullscreen()->build();

        // Order matters: grab the alternate screen before switching the tty to
        // raw mode, so a failure in between still leaves a usable terminal.
        $this->terminal->execute(Actions::cursorHide());
        $this->terminal->execute(Actions::alternateScreenEnable());
        $this->terminal->enableRawMode();

        // Capture is what lets the map be dragged, and it takes the terminal's
        // own selection with it: copying a line of the log then needs shift or
        // alt. That is why it can be turned off — see --no-mouse.
        if ($this->mouse) {
            $this->terminal->execute(Actions::enableMouseCapture());
        }

        $this->display->clear();
        $this->started = true;
    }

    public function close(): void
    {
        if (!$this->started) {
            return;
        }

        $this->started = false;

        if ($this->mouse) {
            $this->terminal->execute(Actions::disableMouseCapture());
        }

        $this->terminal->disableRawMode();
        $this->terminal->execute(Actions::alternateScreenDisable());
        $this->terminal->execute(Actions::cursorShow());
        $this->terminal->execute(Actions::clear(ClearType::All));
    }

    public function nextTab(): void
    {
        $count = count($this->players());

        if ($count > 0) {
            $this->activeTab = ($this->activeTab + 1) % $count;
        }
    }

    /**
     * Twice the tiles across and twice down, drawn as upper half blocks: the
     * top tile is the foreground colour and the bottom one the background.
     *
     * It is the same ground the 1:2 zoom covers, except that zoom *samples* —
     * it throws away three tiles in four — and this draws all of them. What
     * it costs is the glyphs: a character fills a whole cell, so at half a
     * cell per tile a flower has only its colour left to speak with.
     *
     * Refused without true colour, where the shades it needs do not exist.
     */
    public function toggleFine(): bool
    {
        if (!$this->palette->hasTrueColor()) {
            return false;
        }

        $this->fine = !$this->fine;

        return true;
    }

    public function isFine(): bool
    {
        return $this->fine;
    }

    public function selectTab(int $index): void
    {
        if ($index >= 0 && $index < count($this->players())) {
            $this->activeTab = $index;
        }
    }

    /**
     * Size of the *view* in cells, once the dashboard chrome is subtracted
     * from the terminal.
     *
     * This used to be the size of the world as well, because the map was
     * built to fit the screen exactly. It is now only how much of the world
     * fits at a time: at 1:1 a cell is a tile, and the camera decides which
     * ones.
     *
     * @return array{x: int, y: int}
     */
    public function getSize(): array
    {
        $size = $this->terminal->info(Size::class);

        $cols = $size instanceof Size ? $size->cols : 80;
        $lines = $size instanceof Size ? $size->lines : 24;

        $width = $cols - self::SIDEBAR_WIDTH - 2;
        $height = $lines - self::LOG_HEIGHT - self::CONTROL_HEIGHT - 2;

        // A tile spans two columns, so a row holds half as many of them —
        // unless it is half a cell, in which case a row holds twice as many
        // and there are two rows of tiles per row of cells.
        return $this->fine
            ? ['x' => max(10, $width), 'y' => max(10, $height * 2)]
            : [
                'x' => max(10, intdiv($width, TilePalette::TILE_WIDTH)),
                'y' => max(10, $height),
            ];
    }

    /**
     * Whether a screen position falls inside the map, border excluded.
     *
     * The geometry lives here because the renderer is what decided it. The
     * loop only needs the answer, not the layout.
     */
    public function isOverMap(int $column, int $row): bool
    {
        $view = $this->getSize();

        return $column >= 1
            && $row >= 1
            && $column <= ($this->fine ? $view['x'] : $view['x'] * TilePalette::TILE_WIDTH)
            && $row <= ($this->fine ? intdiv($view['y'], 2) : $view['y']);
    }

    /**
     * Screen columns and rows turned into cells. A tile spans two columns, so
     * a one column drag is worth nothing — the caller keeps its anchor until
     * the movement adds up, otherwise a slow horizontal drag would round to
     * zero for ever and feel stuck.
     *
     * @return array{int, int}
     */
    public function toCells(int $columns, int $rows): array
    {
        return $this->fine
            ? [$columns, $rows * 2]
            : [intdiv($columns, TilePalette::TILE_WIDTH), $rows];
    }

    /**
     * Whether a screen position falls on the "centre the view" button of the
     * AI panel.
     *
     * Null until a frame has been drawn: the row is recorded while the panel
     * is built, because the panel is what decides where the button goes.
     */
    public function isOverFocusButton(int $column, int $row): bool
    {
        $size = $this->terminal->info(Size::class);
        $cols = $size instanceof Size ? $size->cols : 80;
        $left = $cols - self::SIDEBAR_WIDTH;

        return null !== $this->focusRow
            && $row === $this->focusRow
            && $column > $left
            && $column < $cols - 1;
    }

    /**
     * Bring the cat shown in the panel into the middle of the view.
     *
     * On the renderer because it is what knows which tab is selected, and it
     * already holds the camera.
     */
    /** The cat the panel is describing, which is also the one the view follows. */
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

    public function render($map): void
    {
        $this->init();

        $this->display->draw($this->layout($map));
    }

    public function clear($map): void
    {
    }

    /**
     * @param array<int, array<int, string>> $map
     */
    private function layout(array $map): Widget
    {
        // Drawn before the title is built: rendering is what clamps the
        // camera, and a title read beforehand would be a frame behind.
        $window = $this->mapWidget($map);

        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(
                Constraint::min(1),
                Constraint::length(self::LOG_HEIGHT),
                Constraint::length(self::CONTROL_HEIGHT),
            )
            ->widgets(
                GridWidget::default()
                    ->direction(Direction::Horizontal)
                    ->constraints(
                        Constraint::min(1),
                        Constraint::length(self::SIDEBAR_WIDTH),
                    )
                    ->widgets(
                        $this->block($this->mapTitle($map), $window),
                        $this->sidebar(),
                    ),
                $this->block('Journal', $this->logWidget()),
                $this->controlBar(),
            );
    }

    private function sidebar(): Widget
    {
        return GridWidget::default()
            ->direction(Direction::Vertical)
            ->constraints(Constraint::min(1), Constraint::length(self::MEMORY_HEIGHT))
            ->widgets($this->aiPanel(), $this->block('Memoire', $this->memoryWidget()));
    }

    /**
     * Live memory readings. Exit code 137 is the kernel SIGKILL-ing the
     * container for going over its cgroup limit, and it leaves no trace in the
     * log, so the two ceilings and the snapshot ring are shown here.
     */
    private function memoryWidget(): Widget
    {
        $php = $this->memoryUsage->phpCurrent();
        $phpLimit = $this->memoryUsage->phpLimit();
        $container = $this->memoryUsage->containerCurrent();
        $containerLimit = $this->memoryUsage->containerLimit();
        $memory = $this->memoryManager->getFlashMemory();

        $lines = [
            $this->usageLine('PHP ', $php, $phpLimit),
            Line::fromSpans(
                Span::styled('pic  ', Style::default()->fg(AnsiColor::DarkGray)),
                Span::styled(
                    MemoryUsage::format($this->memoryUsage->phpPeak()),
                    Style::default()->fg(AnsiColor::DarkGray)
                ),
            ),
        ];

        if (null !== $container) {
            $lines[] = $this->usageLine('cgrp', $container, $containerLimit);
        }

        $lines[] = Line::fromSpans(
            Span::fromString('snap '),
            Span::styled(
                sprintf('%s en %d instants', MemoryUsage::format($memory->bytes()), $memory->count()),
                Style::default()->fg(AnsiColor::Magenta)
            ),
        );

        return ParagraphWidget::fromText(Text::fromLines(...$lines));
    }

    private function usageLine(string $label, int $used, ?int $limit): Line
    {
        $ratio = MemoryUsage::ratio($used, $limit);
        $style = Style::default()->fg(match (true) {
            null === $ratio => AnsiColor::Cyan,
            $ratio >= MemoryUsage::DANGER_RATIO => AnsiColor::LightRed,
            $ratio >= 0.6 => AnsiColor::Yellow,
            default => AnsiColor::LightGreen,
        });

        return Line::fromSpans(
            Span::fromString($label . ' '),
            Span::styled($this->ratioGauge($ratio), $style),
            Span::fromString(sprintf(
                ' %s/%s',
                MemoryUsage::format($used),
                null === $limit ? '∞' : MemoryUsage::format($limit)
            )),
        );
    }

    private function ratioGauge(?float $ratio, int $width = 8): string
    {
        if (null === $ratio) {
            return str_repeat('─', $width);
        }

        $filled = (int) round(min(1.0, max(0.0, $ratio)) * $width);

        return str_repeat('▓', $filled) . str_repeat('░', $width - $filled);
    }

    /**
     * One tab per AI, the selected one detailing its stomach and its goals.
     */
    private function aiPanel(): Widget
    {
        $players = $this->players();

        if ([] === $players) {
            return $this->block('IA', ParagraphWidget::fromString('aucune IA'));
        }

        $this->activeTab = min($this->activeTab, count($players) - 1);

        $tabs = TabsWidget::fromTitles(...array_map(
            static fn (int $index, PlayerInterface $player): Line => Line::fromString(
                ' ' . TilePalette::playerMarker($index) . ' ' . $player->getIdentifiant() . ' '
            ),
            array_keys($players),
            $players
        ))
            ->select($this->activeTab)
            ->highlightStyle(Style::default()->fg(AnsiColor::Black)->bg(AnsiColor::Cyan));

        return $this->block(
            sprintf('IA (%d/%d)', $this->activeTab + 1, count($players)),
            GridWidget::default()
                ->direction(Direction::Vertical)
                ->constraints(Constraint::length(1), Constraint::min(1))
                ->widgets($tabs, $this->playerDetail($players[$this->activeTab]))
        );
    }

    private function playerDetail(PlayerInterface $player): Widget
    {
        $index = array_search($player, $this->players(), true);
        $lines = [
            Line::fromSpans(
                Span::fromString(TilePalette::playerMarker(is_int($index) ? $index : 0) . '  '),
                Span::styled(
                    $player->getIdentifiant(),
                    Style::default()->fg(AnsiColor::White)
                ),
            ),
            Line::fromString(''),
        ];

        if ($player instanceof PlayerHasEstomac) {
            $food = $player->getEstomac()->getNouriture();
            $lines[] = Line::fromSpans(
                Span::fromString('Estomac '),
                Span::styled($this->gauge($food, 10), $this->foodStyle($food)),
                Span::fromString(sprintf(' %d/10', $food)),
            );
        }

        $lines[] = Line::fromString(sprintf('Position %s', (string) $player->getPosition()));

        if ($player instanceof PlayerHasPeur) {
            foreach ($this->fearLines($player->getPeur()) as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = Line::fromString('');
        $lines[] = Line::fromSpans(
            Span::styled('Objectifs', Style::default()->fg(AnsiColor::Yellow))
        );

        foreach ($this->objectifs($player) as $description) {
            $lines[] = Line::fromString('  - ' . $description);
        }

        if ([] === $this->objectifs($player)) {
            $lines[] = Line::fromSpans(
                Span::styled('  (aucun, il flane)', Style::default()->fg(AnsiColor::DarkGray))
            );
        }

        $lines[] = Line::fromString('');

        // Two rows down for the block border and the row of tabs above this
        // paragraph. Recorded here, where the line is actually placed.
        $this->focusRow = 2 + count($lines);
        $lines[] = Line::fromSpans(
            Span::styled(
                ' [ c : centrer la vue ] ',
                Style::default()->fg(AnsiColor::Black)->bg(AnsiColor::Cyan)
            )
        );

        return ParagraphWidget::fromText(Text::fromLines(...$lines));
    }

    /**
     * What the cat has learnt to expect, worst first.
     *
     * Fear that cannot be read cannot be told from a bug: the panel is where
     * one sees that a cat is walking the long way round because it remembers
     * something, and not because the pathfinder is broken.
     *
     * @return list<Line>
     */
    private function fearLines(Peur $peur): array
    {
        if ($peur->isEmpty()) {
            return [];
        }

        $lines = [Line::fromSpans(Span::styled('Peur', Style::default()->fg(AnsiColor::LightRed)))];

        foreach ($peur->strongest() as [$cue, $weight]) {
            $lines[] = Line::fromString(sprintf('  %-14s %.2f', $this->readable($cue), $weight));
        }

        return $lines;
    }

    /**
     * Cues are keyed for lookup, not for reading. `sol:R` is a fine key and a
     * poor label.
     */
    private function readable(string $cue): string
    {
        [$kind, $what] = array_pad(explode(':', $cue, 2), 2, '');

        return match ($kind) {
            'sol' => match ($what) {
                MapBuilder::RONCE => 'les ronces',
                MapBuilder::ARBRE => 'les bois',
                MapBuilder::EAU => "l'eau",
                default => 'le sol ' . $what,
            },
            'lieu' => 'la zone ' . $what,
            default => $cue,
        };
    }

    /**
     * @return list<string>
     */
    private function objectifs(PlayerInterface $player): array
    {
        $ia = $player->getIa();

        if (!$ia instanceof CatIA) {
            return [];
        }

        return array_map(
            static fn (object $objectif): string => $objectif->describe(),
            $ia->getObjectifs()
        );
    }

    /**
     * The time controls, with the active mode highlighted.
     */
    private function controlBar(): Widget
    {
        $memory = $this->memoryManager->getFlashMemory();
        $tick = $this->worldContainer->getWorld()?->getTimer()->getTick() ?? 0;

        $spans = [
            $this->button(
                $this->timeControl->isPaused() ? ' ▶ espace ' : ' ▮▮ espace ',
                !$this->timeControl->isPaused()
            ),
            Span::fromString(' '),
            $this->button(' n pas ', $this->timeControl->isPaused()),
            Span::fromString('  '),
            $this->button(' - ', !$this->timeControl->isSlowest()),
            Span::styled(
                ' ' . $this->timeControl->speedLabel() . ' ',
                Style::default()->fg(
                    $this->timeControl->isLagging() ? AnsiColor::LightRed : AnsiColor::LightGreen
                )
            ),
            $this->button(' + ', !$this->timeControl->isFastest()),
            Span::fromString('  '),
            $this->button(' t machine ', $this->timeControl->isTimeMachine()),
        ];

        // Only shown when there is a device to be silent about: inside the
        // container there is no sound to mute, and a dead button would just
        // be a promise the build cannot keep.
        if (true === $this->audio?->isReady()) {
            $spans[] = Span::fromString('  ');
            $spans[] = $this->button(
                $this->audio->isMuted() ? ' m muet ' : ' m son ',
                !$this->audio->isMuted()
            );
        }

        if ($this->timeControl->isTimeMachine()) {
            $spans[] = Span::fromString(' ');
            $spans[] = $this->button(' p < ', true);
            $spans[] = Span::styled(
                sprintf(' %s %d/%d ', $this->timeMachineGauge(), $memory->count(), $memory->getPlaces()),
                Style::default()->fg(AnsiColor::Magenta)
            );
            $spans[] = $this->button(' > a ', true);
        }

        // Asking for x1000 does not make the machine deliver it, so the rate
        // actually reached is shown as soon as it falls behind.
        if ($this->timeControl->isLagging()) {
            $spans[] = Span::styled(
                sprintf(' reel x%s ', $this->format($this->timeControl->observedMultiplier() ?? 0.0)),
                Style::default()->fg(AnsiColor::LightRed)
            );
        }

        $spans[] = Span::fromString('  ');
        $spans[] = Span::styled(
            sprintf('tick %d', $tick),
            Style::default()->fg(AnsiColor::DarkGray)
        );

        return $this->block(
            'Temps  (tab: IA suivante, r: nouvelle map, x: sauver, q: quitter)',
            ParagraphWidget::fromLines(Line::fromSpans(...$spans))
        );
    }

    private function format(float $multiplier): string
    {
        return $multiplier >= 10
            ? (string) (int) round($multiplier)
            : rtrim(rtrim(number_format($multiplier, 1), '0'), '.');
    }

    private function button(string $label, bool $active): Span
    {
        return Span::styled(
            $label,
            $active
                ? Style::default()->fg(AnsiColor::Black)->bg(AnsiColor::Cyan)
                : Style::default()->fg(AnsiColor::Gray)
        );
    }

    private function timeMachineGauge(): string
    {
        $memory = $this->memoryManager->getFlashMemory();
        $places = $memory->getPlaces();
        $used = min($memory->count(), $places);
        $cursor = max(0, min($memory->getPositionRead(), $places - 1));

        $gauge = '';

        for ($i = 0; $i < $places; $i++) {
            $gauge .= match (true) {
                $i === $cursor => '▓',
                $i < $used => '▒',
                default => '░',
            };
        }

        return $gauge;
    }

    private function gauge(int $value, int $max, int $width = 10): string
    {
        $filled = (int) round(max(0, min($value, $max)) / $max * $width);

        return str_repeat('▓', $filled) . str_repeat('░', $width - $filled);
    }

    private function foodStyle(int $food): Style
    {
        return Style::default()->fg(match (true) {
            $food <= 2 => AnsiColor::LightRed,
            $food <= 5 => AnsiColor::Yellow,
            default => AnsiColor::LightGreen,
        });
    }

    /**
     * @return list<PlayerInterface>
     */
    private function players(): array
    {
        return $this->worldContainer->getWorld()?->getPlayerCollection() ?? [];
    }

    /**
     * The window the camera is looking through.
     *
     * The map is larger than the screen, so only part of it is drawn, and at
     * anything but the closest zoom a cell stands for a block of tiles. That
     * block is **sampled**, not averaged: reading every tile of every block
     * would be sixty four lookups a cell at 1:8, some thirty thousand a frame,
     * which costs more than the simulation it is showing. Terrain is
     * contiguous enough that one tile speaks for its neighbours.
     *
     * Sampling does lose things smaller than a block — a lone flower usually
     * disappears at 1:4 — and that is a deliberate trade, with one exception:
     * players are drawn from their own positions afterwards, so a cat is
     * never sampled away. Losing sight of a cat is precisely what one zooms
     * out to avoid.
     *
     * Shades are hashed from world coordinates rather than screen ones, so
     * the grain of the ground stays put while the view slides over it.
     *
     * @param array<int, array<int, string>> $map
     */
    private function mapWidget(array $map): Widget
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

        $lines = [];

        // Two rows of tiles per row of cells in the fine view, one otherwise.
        $step = $this->fine ? 2 : 1;

        for ($row = 0; $row < $view['y']; $row += $step) {
            $worldY = $originY + $row * $scale;

            if ($worldY >= $height) {
                break;
            }

            $spans = [];

            for ($column = 0; $column < $view['x']; $column++) {
                $worldX = $originX + $column * $scale;

                if ($worldX >= $width) {
                    break;
                }

                $spans[] = $this->fine
                    ? $this->halfBlock($map, $players, $sightEdge, $row, $column, $originX, $originY, $scale, $width, $height)
                    : $this->palette->cell(
                        $players[$row][$column] ?? $map[$worldY][$worldX] ?? MapBuilder::HERBE,
                        $worldX,
                        $worldY,
                        isset($sightEdge[$worldY * $width + $worldX])
                    );
            }

            $lines[] = Line::fromSpans(...$spans);
        }

        return ParagraphWidget::fromText(Text::fromLines(...$lines));
    }

    /**
     * One cell carrying two tiles: an upper half block whose foreground is
     * the tile above and whose background is the tile below.
     *
     * The lower one may fall off the bottom of the world, in which case the
     * cell is drawn as the upper tile alone rather than against whatever
     * happened to be in memory.
     *
     * @param array<int, array<int, string>> $map
     * @param array<int, array<int, string>> $players
     * @param array<int, true> $sightEdge
     */
    private function halfBlock(
        array $map,
        array $players,
        array $sightEdge,
        int $row,
        int $column,
        int $originX,
        int $originY,
        int $scale,
        int $width,
        int $height,
    ): Span {
        $worldX = $originX + $column * $scale;
        $top = $originY + $row * $scale;
        $bottom = $originY + ($row + 1) * $scale;

        $upper = $this->pixelAt($map, $players, $sightEdge, $worldX, $top, $row, $column, $width, $height);
        $lower = $bottom >= $height
            ? $upper
            : $this->pixelAt($map, $players, $sightEdge, $worldX, $bottom, $row + 1, $column, $width, $height);

        return Span::styled('▀', Style::default()->fg($upper)->bg($lower));
    }

    /**
     * @param array<int, array<int, string>> $map
     * @param array<int, array<int, string>> $players
     * @param array<int, true> $sightEdge
     */
    private function pixelAt(
        array $map,
        array $players,
        array $sightEdge,
        int $worldX,
        int $worldY,
        int $row,
        int $column,
        int $width,
        int $height,
    ): Color {
        if (isset($sightEdge[$worldY * $width + $worldX])) {
            return $this->palette->sightEdgeColour();
        }

        $tile = $players[$row][$column] ?? $map[$worldY][$worldX] ?? MapBuilder::HERBE;

        return $this->palette->pixel($tile, $worldX, $worldY);
    }

    /**
     * Where the camera is, and how much of the world it holds. The map no
     * longer fits on the screen, so this is the only way to know whether the
     * cat one is looking for is off to the left or simply somewhere else.
     *
     * @param array<int, array<int, string>> $map
     */
    private function mapTitle(array $map): string
    {
        return sprintf(
            '%s %s%s  %d;%d de %dx%d  (fleches, z/Z, f)',
            World::SOUTERRAIN === $this->selectedPlayer()?->getNiveau() ? 'Souterrain' : 'Carte',
            $this->camera->label(),
            $this->fine ? ' fin' : '',
            $this->camera->y(),
            $this->camera->x(),
            count($map[0] ?? []),
            count($map),
        );
    }

    /**
     * The far edge of what the selected cat can see, as world tile indices.
     *
     * Drawn from the real flood rather than as a circle, because sight is
     * spent in cost: it stops short in undergrowth, is cut off by a lake, and
     * a circle would claim the cat sees across water. Roughly a millisecond
     * for one cat, against a frame budget of sixty six.
     *
     * The band is one cell thick *at the current zoom* rather than one tile.
     * A one tile ring would be sampled away at 1:2 and never seen again,
     * which is exactly when the whole field of view starts fitting on screen.
     *
     * @return array<int, true>
     */
    private function sightEdge(int $scale): array
    {
        $world = $this->worldContainer->getWorld();
        $player = $this->selectedPlayer();

        if (null === $world || null === $player) {
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

    /**
     * Players placed on the grid of cells, as the glyph the palette expects.
     *
     * @param array{x: int, y: int} $view
     *
     * @return array<int, array<int, string>> indexed [row][column]
     */
    private function visiblePlayers(int $originX, int $originY, int $scale, array $view): array
    {
        $placed = [];
        $level = $this->selectedPlayer()?->getNiveau();

        foreach (array_values($this->players()) as $index => $player) {
            // Only what is on the level being looked at. A cat underground is
            // not standing on the meadow above it.
            if (null !== $level && $player->getNiveau() !== $level) {
                continue;
            }

            $x = $player->getPosition()->getX();
            $y = $player->getPosition()->getY();

            // Guarded before the division: intdiv truncates towards zero, so a
            // player just off the left edge would otherwise land in column 0
            // and appear inside the view.
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

    private function logWidget(): Widget
    {
        $visible = array_slice($this->bufferLog->getLogs(), -(self::LOG_HEIGHT - 2));

        return ParagraphWidget::fromString(implode("\n", $visible));
    }

    private function block(string $title, Widget $inner): Widget
    {
        return BlockWidget::default()
            ->borders(Borders::ALL)
            ->titles(Title::fromString($title))
            ->widget($inner);
    }


}
