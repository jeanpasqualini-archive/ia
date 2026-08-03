<?php

declare(strict_types=1);

namespace GameRunner;

use InputController\InputControllerInterface;
use InputController\TerminalInputController;
use Logger\FileLogger;
use Logger\MultipleLogger;
use Map\Builder\MapBuilder;
use Map\Location\Point;
use Map\Player\Chat;
use Map\Provider\FileMapProvider;
use Map\Provider\MapProviderInterface;
use Map\Provider\TerrainMapProvider;
use Map\Render\TuiRender;
use Map\World\World;
use Map\World\WorldContainer;
use Memory\MemoryManager;
use PhpTui\Term\Terminal;
use Psr\Log\LogLevel;
use Runtime\MemoryUsage;
use Runtime\TimeControl;
use Snapshot\Instant;

class GameRunner
{
    /** How long the loop sleeps while it has nothing to compute. */
    private const IDLE_DELAY = 20_000;

    /** Ticks between two memory readings written to the log. */
    private const MEMORY_CHECK_EVERY = 50;

    /**
     * Frames between two snapshots while the simulation runs on its own.
     *
     * Counted in frames rather than ticks so the ring covers the same few
     * seconds of wall clock at any speed. Counted in ticks, x1000 would fill
     * the whole ring within a single frame.
     */
    private const SNAPSHOT_EVERY_FRAMES = 5;

    private bool $quit = false;

    private bool $stepRequested = false;

    private float $lastFrameAt = 0.0;

    private int $lastFrameTicks = 0;

    private int $frame = 0;

    private ?string $mapFile = null;

    private string $flashName = 'game';

    /** Set to replay the exact same terrain across runs. */
    private ?int $seed = null;

    private MultipleLogger $logger;

    private WorldContainer $worldContainer;

    private MemoryManager $memoryManager;

    private TimeControl $timeControl;

    private MemoryUsage $memoryUsage;

    private TuiRender $render;

    private InputControllerInterface $input;

    private World $world;

    public function __construct(
        private ?Terminal $terminal = null,
        private string $logFile = '/tmp/log/dev.log',
    ) {
        $this->terminal ??= Terminal::new();
        $this->timeControl = new TimeControl();
        $this->memoryUsage = new MemoryUsage();
    }

    /**
     * @param array<string, mixed> $options
     */
    public function configure(array $options = []): void
    {
        if (!empty($options['map'])) {
            $this->mapFile = (string) $options['map'];
        }

        if (!empty($options['flash-name'])) {
            $this->flashName = (string) $options['flash-name'];
        }

        if (isset($options['seed']) && '' !== $options['seed']) {
            $this->seed = (int) $options['seed'];
        }

        if (isset($options['speed']) && '' !== $options['speed']) {
            $this->timeControl->setMultiplier((float) $options['speed']);
        }

        if (!empty($options['play'])) {
            $this->timeControl->play();
        }
    }

    public function execute(): int
    {
        $this->logger = new MultipleLogger();
        $this->logger->addLogger(new FileLogger($this->logFile));

        $this->worldContainer = new WorldContainer();
        $this->memoryManager = new MemoryManager($this->flashName);
        $this->input = new TerminalInputController($this->terminal);

        $this->render = new TuiRender(
            $this->terminal,
            $this->logger,
            $this->worldContainer,
            $this->memoryManager,
            $this->timeControl
        );

        try {
            $this->render->init();
            $this->setWorld($this->createWorld());
            $this->draw();

            while (!$this->quit) {
                $this->pumpInput();

                if ($this->quit) {
                    break;
                }

                if (!$this->advance()) {
                    usleep(self::IDLE_DELAY);
                }
            }
        } finally {
            $this->render->close();
        }

        return 0;
    }

    /**
     * Run one iteration of the simulation if the current time state allows it.
     * Returns false when the loop should just idle.
     */
    private function advance(): bool
    {
        // Browsing the past: the world on screen is a restored snapshot, so
        // nothing must be computed until the user leaves the time machine.
        if ($this->timeControl->isTimeMachine()) {
            return false;
        }

        if ($this->timeControl->isPaused() && !$this->stepRequested) {
            return false;
        }

        $this->stepRequested = false;

        // One snapshot per frame, never per tick: at x1000 a snapshot per tick
        // would serialize the world thousands of times a second.
        $this->snapshot();

        // Measured frame to frame, sleep included: what matters is the speed
        // actually delivered, not the raw capacity of the machine.
        $now = microtime(true);

        if ($this->lastFrameAt > 0.0 && $now > $this->lastFrameAt) {
            $this->timeControl->observe($this->lastFrameTicks / ($now - $this->lastFrameAt));
        }

        $this->lastFrameAt = $now;
        $ticks = $this->timeControl->isPaused() ? 1 : $this->timeControl->ticksPerFrame();
        $this->lastFrameTicks = $ticks;

        for ($i = 0; $i < $ticks; $i++) {
            // Only the last tick of a batch is allowed to speak.
            $this->logger->mute($i < $ticks - 1);
            $this->world->update();
        }

        $this->logger->mute(false);

        $this->checkMemory();
        $this->draw();

        // Sleep only what is left of the frame budget. Sleeping the full
        // delay after an already late frame is how a loop that is merely
        // behind becomes hopelessly behind.
        if (!$this->timeControl->isPaused()) {
            $spent = (int) ((microtime(true) - $now) * 1_000_000);
            $remaining = $this->timeControl->frameDelay() - $spent;

            if ($remaining > 0) {
                usleep($remaining);
            }
        }

        return true;
    }

    private function pumpInput(): void
    {
        $this->input->update();

        $key = $this->input->getKey();

        if (null === $key) {
            return;
        }

        $redraw = match ($key) {
            'q' => $this->quit(),
            ' ' => $this->togglePause(),
            'n' => $this->requestStep(),
            '+', '=' => $this->changeSpeed(faster: true),
            '-' => $this->changeSpeed(faster: false),
            't' => $this->toggleTimeMachine(),
            'p' => $this->travel(-1),
            'a' => $this->travel(1),
            'x' => $this->persist(),
            'r' => $this->reload(),
            "\t" => $this->nextTab(),
            // Historic bindings, kept so the old muscle memory still works.
            'b' => $this->togglePause(true),
            's' => $this->togglePause(false),
            default => $this->selectTab($key),
        };

        if ($redraw) {
            $this->draw();
        }
    }

    private function quit(): bool
    {
        $this->quit = true;

        return false;
    }

    private function togglePause(?bool $paused = null): bool
    {
        match ($paused) {
            true => $this->timeControl->pause(),
            false => $this->timeControl->play(),
            null => $this->timeControl->togglePause(),
        };

        return true;
    }

    private function requestStep(): bool
    {
        $this->stepRequested = true;

        return false;
    }

    private function changeSpeed(bool $faster): bool
    {
        $faster ? $this->timeControl->faster() : $this->timeControl->slower();

        return true;
    }

    private function nextTab(): bool
    {
        $this->render->nextTab();

        return true;
    }

    private function selectTab(string $key): bool
    {
        if (1 !== preg_match('/^[1-9]$/', $key)) {
            return false;
        }

        $this->render->selectTab((int) $key - 1);

        return true;
    }

    private function persist(): bool
    {
        $path = $this->memoryManager->persist();
        $this->logger->log(LogLevel::INFO, 'memoire persistee dans ' . $path);

        return true;
    }

    private function reload(): bool
    {
        $this->logger->log(LogLevel::INFO, 'soft reload game');
        $this->setWorld($this->createWorld());

        return true;
    }

    private function toggleTimeMachine(): bool
    {
        $this->timeControl->toggleTimeMachine();
        $this->logger->log(
            LogLevel::INFO,
            'mode timemachine ' . ($this->timeControl->isTimeMachine() ? 'active' : 'desactive')
        );

        return true;
    }

    private function travel(int $offset): bool
    {
        if (!$this->timeControl->isTimeMachine()) {
            return false;
        }

        $memory = $this->memoryManager->getFlashMemory();
        $instant = $offset < 0 ? $memory->previous() : $memory->after();

        if (null === $instant) {
            $this->logger->log(LogLevel::ERROR, 'aucun instant dans cette direction');

            return true;
        }

        $world = $instant->getData();

        if (!$world instanceof World) {
            $this->logger->log(LogLevel::ERROR, 'instant corrompu');

            return true;
        }

        $this->setWorld($world);

        return true;
    }

    /**
     * Freeze the current world. Every step is worth keeping when the user
     * drives them one by one; while playing, one every few frames is enough.
     */
    private function snapshot(): void
    {
        $this->frame++;

        if (!$this->timeControl->isPaused() && 0 !== $this->frame % self::SNAPSHOT_EVERY_FRAMES) {
            return;
        }

        $this->memoryManager->getFlashMemory()->addInstant(new Instant($this->world));
    }

    /**
     * A cgroup kill (exit 137) gives no stack trace and no PHP error, so the
     * only way to know what happened is to have written it down beforehand.
     * The log file is opened in append mode and flushed per line, so the last
     * warning survives the SIGKILL.
     */
    private function checkMemory(): void
    {
        if (!$this->world->getTimer()->isTime(self::MEMORY_CHECK_EVERY)) {
            return;
        }

        $container = $this->memoryUsage->containerCurrent();
        $ratios = [
            'php' => MemoryUsage::ratio($this->memoryUsage->phpCurrent(), $this->memoryUsage->phpLimit()),
            'conteneur' => MemoryUsage::ratio($container, $this->memoryUsage->containerLimit()),
        ];

        $message = sprintf(
            '[MEMOIRE] php %s/%s, conteneur %s/%s, snapshots %s en %d instants',
            MemoryUsage::format($this->memoryUsage->phpCurrent()),
            MemoryUsage::format($this->memoryUsage->phpLimit()),
            MemoryUsage::format($container),
            MemoryUsage::format($this->memoryUsage->containerLimit()),
            MemoryUsage::format($this->memoryManager->getFlashMemory()->bytes()),
            $this->memoryManager->getFlashMemory()->count(),
        );

        foreach ($ratios as $what => $ratio) {
            if (null !== $ratio && $ratio >= MemoryUsage::DANGER_RATIO) {
                $this->logger->log(LogLevel::WARNING, sprintf(
                    '%s (%s a %d%% de sa limite)',
                    $message,
                    $what,
                    (int) round($ratio * 100)
                ));

                return;
            }
        }

        $this->logger->log(LogLevel::INFO, $message);
    }

    private function draw(): void
    {
        $map = $this->world->getMap();

        $map->clearLayer(MapBuilder::LAYER_PLAYER);

        // Each player is stamped with its own index so the renderer can tell
        // them apart; every cat used to be the same indistinguishable letter.
        foreach (array_values($this->world->getPlayerCollection()) as $index => $player) {
            $map->setItem($player->getPosition(), (string) ($index + 1), MapBuilder::LAYER_PLAYER);
        }

        $map->updateFinalLayer();

        $this->render->render($map->getFinalMap());
    }

    /**
     * A world coming back from a snapshot carries no services: it gets the
     * live logger and input controller re-attached here.
     */
    private function setWorld(World $world): void
    {
        $this->world = $world;
        $this->world->setLogger($this->logger);
        $this->world->setInputController($this->input);
        $this->worldContainer->setWorld($world);
    }

    private function createWorld(): World
    {
        $size = $this->render->getSize();
        $map = new MapBuilder($this->mapProvider($size['y'], $size['x'])->getMap(), $this->logger);

        return new World(
            $map,
            [
                $this->createChat($map, 5, 5),
                $this->createChat($map, (int) ($size['x'] / 2), (int) ($size['y'] / 2)),
            ],
            $this->logger,
            $this->input
        );
    }

    private function mapProvider(int $lines, int $columns): MapProviderInterface
    {
        if (null !== $this->mapFile) {
            return new FileMapProvider($this->mapFile);
        }

        return new TerrainMapProvider($lines, $columns, $this->seed);
    }

    private function createChat(MapBuilder $map, int $x, int $y): Chat
    {
        // Now that water blocks movement, a cat dropped on a lake would be
        // stuck there for good.
        $spawn = $map->nearestWalkable(new Point($x, $y));

        $chat = new Chat();
        $chat->getPosition()->setX($spawn->getX());
        $chat->getPosition()->setY($spawn->getY());

        return $chat;
    }
}
