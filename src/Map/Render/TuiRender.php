<?php

declare(strict_types=1);

namespace Map\Render;

use IA\CatIA;
use Logger\BufferLogger;
use Logger\MultipleLogger;
use Map\Player\PlayerHasEstomac;
use Map\Player\PlayerInterface;
use Map\World\WorldContainer;
use Memory\MemoryManager;
use PhpTui\Term\Actions;
use PhpTui\Term\ClearType;
use PhpTui\Term\Terminal;
use PhpTui\Term\TerminalInformation\Size;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\Color\AnsiColor;
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

    public function __construct(
        private Terminal $terminal,
        private MultipleLogger $logger,
        private WorldContainer $worldContainer,
        private MemoryManager $memoryManager,
        private TimeControl $timeControl = new TimeControl(),
        private ?Backend $backend = null,
        private MemoryUsage $memoryUsage = new MemoryUsage(),
        private ?TilePalette $palette = null,
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

        $this->display->clear();
        $this->started = true;
    }

    public function close(): void
    {
        if (!$this->started) {
            return;
        }

        $this->started = false;
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

    public function selectTab(int $index): void
    {
        if ($index >= 0 && $index < count($this->players())) {
            $this->activeTab = $index;
        }
    }

    /**
     * Size of the playable area, in tiles, once the dashboard chrome is
     * subtracted from the terminal.
     *
     * @return array{x: int, y: int}
     */
    public function getSize(): array
    {
        $size = $this->terminal->info(Size::class);

        $cols = $size instanceof Size ? $size->cols : 80;
        $lines = $size instanceof Size ? $size->lines : 24;

        // A tile spans two columns, so the map holds half as many of them.
        return [
            'x' => max(10, intdiv($cols - self::SIDEBAR_WIDTH - 2, TilePalette::TILE_WIDTH)),
            'y' => max(10, $lines - self::LOG_HEIGHT - self::CONTROL_HEIGHT - 2),
        ];
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
                        $this->block('Carte', $this->mapWidget($map)),
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

        return ParagraphWidget::fromText(Text::fromLines(...$lines));
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
     * @param array<int, array<int, string>> $map
     */
    private function mapWidget(array $map): Widget
    {
        $lines = [];

        foreach ($map as $y => $row) {
            $spans = [];

            foreach ($row as $x => $tile) {
                $spans[] = $this->palette->cell($tile, $x, $y);
            }

            $lines[] = Line::fromSpans(...$spans);
        }

        return ParagraphWidget::fromText(Text::fromLines(...$lines));
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
