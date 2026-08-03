<?php

declare(strict_types=1);

namespace Tests\Map\Render;

use Logger\MultipleLogger;
use Map\Render\TuiRender;
use Map\World\WorldContainer;
use Memory\MemoryManager;
use PhpTui\Term\InformationProvider\SizeFromEnvVarProvider;
use PhpTui\Term\Painter\AnsiPainter;
use PhpTui\Term\RawMode\TestRawMode;
use PhpTui\Term\Terminal;
use PhpTui\Term\Writer\StringWriter;
use PhpTui\Tui\Display\Backend\DummyBackend;
use PHPUnit\Framework\TestCase;
use Runtime\MemoryUsage;
use Runtime\TimeControl;
use Snapshot\Instant;
use Tests\WorldFactory;

/**
 * Renders real frames without a tty, using php-tui's own test doubles.
 */
final class TuiRenderTest extends TestCase
{
    private DummyBackend $backend;

    protected function setUp(): void
    {
        putenv('COLUMNS=100');
        putenv('LINES=30');
        $this->backend = DummyBackend::fromDimensions(100, 30);
    }

    public function testAFrameContainsThePanelsTheMapAndThePlayer(): void
    {
        $render = $this->render();

        $render->render([
            ['X', 'F', 'Y'],
            ['E', 'X', 'X'],
        ]);

        $frame = $this->backend->toString();

        self::assertStringContainsString('Carte', $frame);
        self::assertStringContainsString('Journal', $frame);
        self::assertStringContainsString('Temps', $frame);
        self::assertStringContainsString('✿', $frame, 'la fleur est dessinee');
        self::assertStringContainsString('≈', $frame, "l'eau est dessinee");
    }

    public function testThePlayerGlyphIsDrawnOverTheGround(): void
    {
        $this->render()->render([['X', 'P']]);

        self::assertStringContainsString('■', $this->backend->toString());
    }

    public function testTheControlBarShowsTheTimeState(): void
    {
        $time = new TimeControl();
        $render = $this->render(timeControl: $time);

        $render->render([['X']]);
        $paused = $this->backend->toString();

        self::assertStringContainsString('▶ espace', $paused, 'en pause on propose de lire');
        self::assertStringContainsString('x1', $paused, 'la vitesse courante est affichee');
        self::assertStringContainsString('t machine', $paused);

        $time->play();
        $time->faster();
        $render->render([['X']]);
        $playing = $this->backend->toString();

        self::assertStringContainsString('▮▮ espace', $playing, 'en lecture on propose de mettre en pause');
        self::assertStringContainsString('x2', $playing, 'la vitesse a change');
    }

    public function testTheTimeMachineControlsOnlyAppearInTimeMachineMode(): void
    {
        $time = new TimeControl();
        $memory = new MemoryManager('test');
        $memory->getFlashMemory()->addInstant(new Instant(WorldFactory::fromRows(['XX'])));
        $render = $this->render(timeControl: $time, memoryManager: $memory);

        $render->render([['X']]);
        self::assertStringNotContainsString('1/10', $this->backend->toString());

        $time->toggleTimeMachine();
        $render->render([['X']]);

        $frame = $this->backend->toString();
        self::assertStringContainsString('1/10', $frame, 'la jauge de snapshots apparait');
        self::assertStringContainsString('p <', $frame);
        self::assertStringContainsString('> a', $frame);
    }

    public function testEnteringTheTimeMachineForcesPause(): void
    {
        $time = new TimeControl();
        $time->play();
        $time->toggleTimeMachine();

        self::assertTrue($time->isPaused());
    }

    public function testEachAiGetsATabWithItsStomachAndGoals(): void
    {
        $world = WorldFactory::fromRows(['XXF', 'XXX', 'XXX']);
        $container = new WorldContainer();
        $container->setWorld($world);

        WorldFactory::chat($world)->getEstomac()->setNouriture(0);
        WorldFactory::chat($world)->update($world);

        $this->render(container: $container)->render([['X']]);
        $frame = $this->backend->toString();

        self::assertStringContainsString('Estomac', $frame);
        self::assertStringContainsString('Objectifs', $frame);
        self::assertStringContainsString('Manger', $frame, 'l objectif en cours est decrit');
        self::assertStringContainsString('0/10', $frame, "l'estomac est vide");
    }

    public function testAnIdleAiSaysSoInsteadOfShowingAnEmptyList(): void
    {
        $container = new WorldContainer();
        $container->setWorld(WorldFactory::fromRows(['XXX']));

        $this->render(container: $container)->render([['X']]);

        self::assertStringContainsString('il flane', $this->backend->toString());
    }

    public function testTabsAreOnePerPlayerAndSwitchable(): void
    {
        $world = WorldFactory::fromRows(['XXX'], players: 2);
        $container = new WorldContainer();
        $container->setWorld($world);
        $render = $this->render(container: $container);

        $render->render([['X']]);
        $first = $this->backend->toString();

        [$one, $two] = $world->getPlayerCollection();
        self::assertStringContainsString($one->getIdentifiant(), $first);
        self::assertStringContainsString($two->getIdentifiant(), $first);
        self::assertStringContainsString('IA (1/2)', $first);

        $render->nextTab();
        $render->render([['X']]);
        self::assertStringContainsString('IA (2/2)', $this->backend->toString());

        $render->nextTab();
        $render->render([['X']]);
        self::assertStringContainsString('IA (1/2)', $this->backend->toString(), 'les tabs bouclent');
    }

    public function testSelectingAnOutOfRangeTabIsIgnored(): void
    {
        $container = new WorldContainer();
        $container->setWorld(WorldFactory::fromRows(['XXX']));
        $render = $this->render(container: $container);

        $render->selectTab(7);
        $render->render([['X']]);

        self::assertStringContainsString('IA (1/1)', $this->backend->toString());
    }

    public function testThePlayableAreaLeavesRoomForTheDashboard(): void
    {
        self::assertSame(['x' => 64, 'y' => 17], $this->render()->getSize());
    }

    public function testTheMemoryPanelReportsBothCeilingsAndTheSnapshotRing(): void
    {
        $memory = new MemoryManager('test');
        $memory->getFlashMemory()->addInstant(new Instant(WorldFactory::fromRows(['XX'])));

        $this->render(memoryManager: $memory)->render([['X']]);
        $frame = $this->backend->toString();

        self::assertStringContainsString('Memoire', $frame);
        self::assertStringContainsString('PHP', $frame);
        self::assertStringContainsString('pic', $frame);
        self::assertStringContainsString('snap', $frame);
        self::assertStringContainsString('en 1 instants', $frame);
    }

    public function testAnUncappedCeilingIsDrawnAsInfinite(): void
    {
        $unlimited = new class extends MemoryUsage {
            public function phpLimit(): ?int
            {
                return null;
            }
        };

        $this->render(memoryUsage: $unlimited)->render([['X']]);

        self::assertStringContainsString('∞', $this->backend->toString());
    }

    private function render(
        ?WorldContainer $container = null,
        ?MemoryManager $memoryManager = null,
        ?TimeControl $timeControl = null,
        ?MemoryUsage $memoryUsage = null,
    ): TuiRender {
        $terminal = Terminal::new(
            AnsiPainter::new(StringWriter::new()),
            SizeFromEnvVarProvider::new(),
            rawMode: new TestRawMode(),
        );

        return new TuiRender(
            $terminal,
            new MultipleLogger(),
            $container ?? new WorldContainer(),
            $memoryManager ?? new MemoryManager('test'),
            $timeControl ?? new TimeControl(),
            $this->backend,
            $memoryUsage ?? new MemoryUsage(),
        );
    }
}
