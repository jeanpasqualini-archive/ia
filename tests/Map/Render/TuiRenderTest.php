<?php

declare(strict_types=1);

namespace Tests\Map\Render;

use Audio\SoundBoard;
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
use Runtime\Camera;
use Runtime\MemoryUsage;
use Runtime\TimeControl;
use Snapshot\Instant;
use Tests\Audio\FakeAudioOutput;
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
        // Terrain is painted as a background colour, so only what stands on
        // the ground still has a glyph of its own.
        self::assertStringContainsString('✿', $frame, 'la fleur est dessinee');
        // The forest is ground and is painted, not written. The club suit it
        // used to carry is drawn from the colour emoji font on macOS, two
        // columns wide, which no width measurement in PHP reports.
        self::assertStringNotContainsString('♣', $frame, 'le sous-bois est peint, pas ecrit');
    }

    public function testThePlayerGlyphIsDrawnOverTheGround(): void
    {
        $this->render()->render([['X', '1'], ['X', '2']]);

        $frame = $this->backend->toString();
        self::assertStringContainsString('●', $frame, 'le premier chat');
        self::assertStringContainsString('◆', $frame, 'le second, distinct du premier');
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
        self::assertStringContainsString('●', $frame, 'le marqueur du chat, le meme que sur la carte');
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
        // Half as many tiles across as there are columns: a tile spans two.
        self::assertSame(['x' => 32, 'y' => 17], $this->render()->getSize());
    }

    /**
     * Every rendered row must be exactly as wide as the screen.
     *
     * php-tui's paragraph rendering stores one grapheme per cell without
     * accounting for its display width, so a two column character — any emoji
     * — takes one cell and two columns. Everything after it on that row shifts
     * right and the block border lands one column off. This is the test that
     * was missing when emoji were tried in the side panel.
     */
    public function testNoRowIsWiderThanTheScreen(): void
    {
        $world = WorldFactory::fromRows(['XXFY', 'XEXX'], players: 2);
        $container = new WorldContainer();
        $container->setWorld($world);

        [$one, $two] = $world->getPlayerCollection();
        $one->getEstomac()->setNouriture(0);
        $one->update($world);

        $this->render(container: $container)->render([
            ['X', 'F', 'Y', '1'],
            ['E', 'X', '2', 'X'],
        ]);

        foreach (explode("\n", $this->backend->toString()) as $number => $row) {
            if ('' === $row) {
                continue;
            }

            self::assertSame(
                100,
                mb_strwidth($row, 'UTF-8'),
                sprintf('la ligne %d deborde ou se retracte', $number)
            );
        }
    }

    /**
     * The mute button only exists when a device was found, so it is absent
     * from every other frame the tests render — including the one above. It
     * adds spans to the control bar, which is exactly the kind of change that
     * pushes a row past the edge of the screen.
     */
    public function testTheMuteButtonDoesNotPushTheControlBarOffScreen(): void
    {
        $audio = $this->readyAudio();
        $render = $this->render(audio: $audio);

        foreach ([false, true] as $muted) {
            $render->render([['X', 'F'], ['E', 'X']]);

            foreach (explode("\n", $this->backend->toString()) as $number => $row) {
                if ('' === $row) {
                    continue;
                }

                self::assertSame(
                    100,
                    mb_strwidth($row, 'UTF-8'),
                    sprintf('la ligne %d deborde, son %s', $number, $muted ? 'coupe' : 'actif')
                );
            }

            $audio->toggleMute();
        }
    }

    /**
     * The map is larger than the screen, so only a window of it is drawn and
     * the title has to say which one — it is the only way to tell a cat that
     * is off to the left from one that is simply somewhere else.
     */
    public function testTheTitleSaysWhereTheCameraIsLooking(): void
    {
        $camera = new Camera();
        $render = $this->render(camera: $camera);
        $map = $this->emptyMap(64, 34);

        $render->render($map);

        self::assertStringContainsString('Carte 1:1', $this->backend->toString());
        self::assertStringContainsString('de 64x34', $this->backend->toString());

        $camera->zoomOut();
        $render->render($map);

        self::assertStringContainsString('Carte 1:2', $this->backend->toString());
    }

    /**
     * Zooming out samples one tile per block, so anything smaller than a
     * block is normally lost. Players are the exception, drawn from their own
     * positions afterwards: losing sight of a cat is precisely what one zooms
     * out to avoid.
     */
    public function testACatIsNeverSampledAwayWhenZoomingOut(): void
    {
        $world = WorldFactory::fromRows(['XX'], chatX: 40, chatY: 24);
        $container = new WorldContainer();
        $container->setWorld($world);

        $camera = new Camera();
        $render = $this->render(container: $container, camera: $camera);
        $map = $this->emptyMap(64, 34);

        $render->render($map);

        // Counted rather than looked for: the panel draws the same marker, on
        // purpose, so a cat reads as the same cat in both places.
        $offScreen = substr_count($this->backend->toString(), '●');

        $camera->zoomOut();
        $render->render($map);

        self::assertSame(
            $offScreen + 1,
            substr_count($this->backend->toString(), '●'),
            'le chat entre dans le champ et survit a l echantillonnage'
        );
    }

    /**
     * The mouse reports screen positions; the renderer is what knows the
     * layout, so it is what says whether a click landed on the map or on the
     * log pane underneath it.
     */
    public function testTheMapAreaStopsAtItsBorder(): void
    {
        $render = $this->render();

        self::assertFalse($render->isOverMap(0, 5), 'la bordure gauche');
        self::assertFalse($render->isOverMap(5, 0), 'la bordure haute');
        self::assertTrue($render->isOverMap(1, 1), 'le premier coin utile');

        // Thirty two tiles of two columns, seventeen rows.
        self::assertTrue($render->isOverMap(64, 17));
        self::assertFalse($render->isOverMap(65, 17), 'le panneau lateral');
        self::assertFalse($render->isOverMap(64, 18), 'le journal');
    }

    /**
     * Capture has to be given back. Left on, the terminal keeps swallowing
     * clicks after the game has exited and the shell becomes unusable — the
     * same class of damage as leaving the tty in raw mode.
     */
    public function testMouseCaptureIsTakenAndGivenBack(): void
    {
        $writer = StringWriter::new();
        $render = $this->renderWriting($writer, mouse: true);

        $render->init();

        // Button-event tracking: the mode that reports dragging.
        self::assertStringContainsString('?1002h', $writer->toString(), 'la capture est prise');

        $render->close();

        self::assertStringContainsString('?1002l', $writer->toString(), 'et rendue');
    }

    public function testTheMouseCanBeLeftAlone(): void
    {
        $writer = StringWriter::new();
        $render = $this->renderWriting($writer, mouse: false);

        $render->init();
        $render->close();

        // --no-mouse exists so the terminal keeps its own text selection.
        self::assertStringNotContainsString('?1002h', $writer->toString());
    }

    private function renderWriting(StringWriter $writer, bool $mouse): TuiRender
    {
        return new TuiRender(
            Terminal::new(
                AnsiPainter::new($writer),
                SizeFromEnvVarProvider::new(),
                rawMode: new TestRawMode(),
            ),
            new MultipleLogger(),
            new WorldContainer(),
            new MemoryManager('test'),
            new TimeControl(),
            $this->backend,
            new MemoryUsage(),
            mouse: $mouse,
        );
    }

    public function testScreenColumnsBecomeCellsTwoAtATime(): void
    {
        $render = $this->render();

        self::assertSame([0, 3], $render->toCells(1, 3), 'une colonne ne vaut pas encore une tuile');
        self::assertSame([1, 0], $render->toCells(2, 0));
        self::assertSame([-2, -1], $render->toCells(-4, -1));
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function emptyMap(int $width, int $height): array
    {
        return array_fill(0, $height, array_fill(0, $width, 'X'));
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
        ?SoundBoard $audio = null,
        ?Camera $camera = null,
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
            audio: $audio,
            camera: $camera ?? new Camera(),
        );
    }

    /**
     * A sound board that believes it has a device, so the control bar draws
     * the mute button it only shows when there is something to mute.
     */
    private function readyAudio(): SoundBoard
    {
        $board = new SoundBoard(new FakeAudioOutput());
        $board->start();

        return $board;
    }
}
