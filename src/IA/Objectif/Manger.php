<?php

declare(strict_types=1);

namespace IA\Objectif;

use Map\Builder\MapBuilder;
use Map\Location\Point;
use Map\Path\CostBiasInterface;
use Map\Path\PathFinder;
use Map\Path\Route;
use Map\Player\Chat\Peur;
use Map\Player\PlayerHasEstomac;
use Map\Player\PlayerHasPeur;
use Map\World\World;
use Psr\Log\LogLevel;

/**
 * Walk to the closest reachable flower, eat it, repeat.
 */
class Manger implements ObjectifInterface
{
    /**
     * Ticks to wait before looking again when the cat can neither see food
     * nor walk anywhere.
     *
     * This used to be three hundred, on the grounds that a failed search
     * floods the whole map and that eating only removes flowers, so the
     * answer could hardly turn positive on its own. Neither half holds now:
     * the search is bounded by what the cat can see, and walking changes what
     * that is. The wait is only there for a cat with nowhere left to go.
     */
    private const RETRY_EVERY = 30;

    /**
     * Clockwise, starting east. Walked in order rather than picked at random:
     * near a shore or a map edge most headings lead nowhere, and the wandering
     * has to be reproducible for the same reason the terrain is seeded.
     *
     * @var list<array{int, int}>
     */
    private const HEADINGS = [
        [1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [-1, -1], [0, -1], [1, -1],
    ];

    private ?Route $route = null;

    private int $nextSearch = 0;

    private int $heading = 0;

    /** Whether the current route leads to a flower or merely somewhere else. */
    private bool $exploring = false;

    public function __construct(private PlayerHasEstomac $player)
    {
    }

    public function describe(): string
    {
        $destination = $this->route?->getDestination();

        if (null === $destination) {
            return 'Manger : cherche une fleur';
        }

        // Kept short: the panel is barely thirty columns and a wrapped goal
        // is harder to read than a terse one.
        return sprintf(
            '%s -> %s (%d)',
            $this->exploring ? 'Explore' : 'Manger',
            (string) $destination,
            $this->route?->remaining() ?? 0
        );
    }

    public function update(World $world): void
    {
        if (null !== $this->route && $this->route->isEnd()) {
            $ate = $this->eat($world);
            $this->route = null;

            // A cat that has just eaten does not set off again in the same
            // tick. The stomach only raises "full" on the player's own update,
            // which runs after this one, so without this the goal would send
            // it wandering off the flower it is standing on.
            if ($ate) {
                return;
            }
        }

        // Nothing in sight is not a reason to sit down. A cat that cannot see
        // a flower walks to the edge of what it can see and looks again from
        // there — without which a limited field of view reads as a broken cat
        // rather than as a hungry one.
        $this->route ??= $this->findFood($world) ?? $this->explore($world);

        if (null === $this->route) {
            $this->nextSearch = $world->getTimer()->getTick() + self::RETRY_EVERY;
            $world->getLogger()->log(LogLevel::INFO, "le chat ne voit rien et ne peut aller nulle part");

            return;
        }

        $this->route->update($world);
    }

    private function eat(World $world): bool
    {
        $map = $world->getMap();
        $destination = $this->route?->getDestination();

        if (null === $destination || !in_array($map->getItem($destination), MapBuilder::NOURRITURE, true)) {
            return false;
        }

        // Only eat what we are standing on: another cat may have got there
        // first, and the route may have been cut short by a terrain change.
        if (!$this->player->getPosition()->equals($destination)) {
            return false;
        }

        $food = $map->nourishment($destination);
        $poison = $map->poison($destination);

        // Read before the flower is taken away: the cues are what was there
        // at the moment of the meal, and one of them is the flower itself.
        $cues = Peur::cues($map, $destination->getX(), $destination->getY());

        $map->setItem($destination, MapBuilder::HERBE);

        if ($poison > 0) {
            $this->poisoned($world, $cues, $poison);

            return true;
        }

        $world->getLogger()->log(LogLevel::INFO, 'le chat mange');

        $this->player->getEstomac()->setNouriture(
            $this->player->getEstomac()->getNouriture() + $food
        );

        return true;
    }

    /**
     * A flower that turned out not to be one.
     *
     * Learnt as a single trial rather than gradually: an animal poisoned by
     * something it ate does not usually get a second chance to average the
     * experience out, and it is the one association that reliably forms in
     * one go.
     *
     * @param list<string> $cues
     */
    private function poisoned(World $world, array $cues, int $poison): void
    {
        $taken = $this->player->hurt($poison);

        $world->getLogger()->log(LogLevel::INFO, sprintf(
            '[poison] %s recrache une digitale, vie %d',
            $this->player->getIdentifiant(),
            $this->player->getLife(),
        ), ['pid' => $this->player->getIdentifiant()]);

        if ($this->player instanceof PlayerHasPeur) {
            $this->player->getPeur()->remember($cues, (float) $taken, swallowed: true);
        }
    }

    private function findFood(World $world): ?Route
    {
        $tick = $world->getTimer()->getTick();

        if ($tick < $this->nextSearch) {
            return null;
        }

        // Routed with the cat's own price list, not the world's: a bramble
        // it has been stung by is dear to it and cheap to everyone else.
        $steps = (new PathFinder($world->getMap(), $this->bias()))->toNearest(
            $this->player->getPosition(),
            MapBuilder::NOURRITURE,
            $this->player->getVision()
        );

        if (null === $steps || [] === $steps) {
            return null;
        }

        $this->nextSearch = 0;
        $this->exploring = false;

        $route = new Route($this->player, $steps);

        $world->getLogger()->log(
            LogLevel::INFO,
            sprintf(
                '[flower] le chat part vers %s en %d cases',
                (string) $route->getDestination(),
                count($steps)
            ),
            ['pid' => $this->player->getIdentifiant()]
        );

        return $route;
    }

    private function bias(): ?CostBiasInterface
    {
        return $this->player instanceof PlayerHasPeur ? $this->player->getPeur() : null;
    }

    /**
     * Walk to the edge of what can be seen, so the next look happens from
     * somewhere else.
     *
     * Every heading is tried in turn, not just one: against a shore or a map
     * edge, most of them lead nowhere. A wander that happens to end on a
     * flower is eaten like any other route — update() does not care how the
     * cat got there.
     */
    private function explore(World $world): ?Route
    {
        $map = $world->getMap();
        $from = $this->player->getPosition();
        $vision = $this->player->getVision();

        for ($tried = 0; $tried < count(self::HEADINGS); $tried++) {
            [$dx, $dy] = self::HEADINGS[$this->heading];
            $this->heading = ($this->heading + 1) % count(self::HEADINGS);

            $target = new Point($from->getX() + $dx * $vision, $from->getY() + $dy * $vision);
            $map->clamp($target);
            $target = $map->nearestWalkable($target);

            if ($target->equals($from)) {
                continue;
            }

            // Twice the sight line as a budget: the target sits at the edge of
            // it, and walking round a lake to reach it costs more than the
            // straight line.
            $steps = (new PathFinder($map, $this->bias()))->to($from, $target, $vision * 2);

            if (null !== $steps && [] !== $steps) {
                $this->exploring = true;

                $world->getLogger()->log(
                    LogLevel::INFO,
                    sprintf('[explore] le chat va voir vers %s', (string) $target),
                    ['pid' => $this->player->getIdentifiant()]
                );

                return new Route($this->player, $steps);
            }
        }

        return null;
    }
}
