<?php

declare(strict_types=1);

namespace Map\Render;

use IA\CatIA;
use Map\Builder\MapBuilder;
use Map\Player\Chat\Peur;
use Map\Player\PlayerHasEstomac;
use Map\Player\PlayerHasPeur;
use Map\Player\PlayerInterface;
use Memory\MemoryManager;
use Runtime\MemoryUsage;

/**
 * What the panels *say*, with nothing about how they are drawn.
 *
 * **This exists because there are two renderers now.** `TuiRender` used to
 * hold both halves: the same method read the stomach, called `describe()` and
 * asked `MemoryUsage`, *and* built `BlockWidget`s and `TabsWidget`s. That was
 * fine while there was one way to draw. With a window beside the terminal the
 * content has to be said once and drawn twice, or the two drift apart at the
 * first thing added to either — the same failure the cat's two coat colours
 * were saved from by living in one table.
 *
 * A line is a string and a tone. The tone is a *meaning*, never a colour:
 * "this is a heading", "this is a warning", "this is an aside". php-tui turns
 * it into an `AnsiColor`, the window into a packed integer, and neither has to
 * know what the other chose.
 */
final class Dashboard
{
    public const PLAIN = 'plain';
    public const HEADING = 'heading';
    public const STRONG = 'strong';
    public const ASIDE = 'aside';
    public const WARNING = 'warning';
    public const GOOD = 'good';

    public function __construct(
        private MemoryManager $memoryManager,
        private MemoryUsage $memoryUsage = new MemoryUsage(),
    ) {
    }

    /**
     * Everything the selected cat's panel shows, in order.
     *
     * @return list<array{text: string, tone: string}>
     */
    public function forPlayer(?PlayerInterface $player, int $index): array
    {
        if (null === $player) {
            return [['text' => 'aucune IA', 'tone' => self::ASIDE]];
        }

        $lines = [
            ['text' => TilePalette::playerMarker($index) . '  ' . $player->getIdentifiant(), 'tone' => self::STRONG],
            ['text' => '', 'tone' => self::PLAIN],
        ];

        if ($player instanceof PlayerHasEstomac) {
            $food = $player->getEstomac()->getNouriture();
            $lines[] = [
                'text' => sprintf('Estomac %s %d/10', self::gauge($food, 10), $food),
                'tone' => match (true) {
                    $food <= 3 => self::WARNING,
                    $food >= 8 => self::GOOD,
                    default => self::PLAIN,
                },
            ];
        }

        $lines[] = ['text' => 'Position ' . (string) $player->getPosition(), 'tone' => self::PLAIN];

        if ($player instanceof PlayerHasPeur) {
            foreach ($this->fears($player->getPeur()) as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = ['text' => '', 'tone' => self::PLAIN];
        $lines[] = ['text' => 'Objectifs', 'tone' => self::HEADING];

        $goals = $this->goals($player);

        foreach ($goals as $goal) {
            $lines[] = ['text' => '  - ' . $goal, 'tone' => self::PLAIN];
        }

        if ([] === $goals) {
            $lines[] = ['text' => '  (aucun, il flane)', 'tone' => self::ASIDE];
        }

        return $lines;
    }

    /**
     * The two ceilings that can stop the game, and the ring of snapshots which
     * is by far the biggest thing it holds.
     *
     * @return list<array{text: string, tone: string}>
     */
    public function memory(): array
    {
        $memory = $this->memoryManager->getFlashMemory();

        $lines = [$this->usage('PHP ', $this->memoryUsage->phpCurrent(), $this->memoryUsage->phpLimit())];
        $lines[] = [
            'text' => 'pic  ' . MemoryUsage::format($this->memoryUsage->phpPeak()),
            'tone' => self::ASIDE,
        ];

        $container = $this->memoryUsage->containerCurrent();

        if (null !== $container) {
            $lines[] = $this->usage('cgrp', $container, $this->memoryUsage->containerLimit());
        }

        $lines[] = [
            'text' => sprintf('snap %s en %d instants', MemoryUsage::format($memory->bytes()), $memory->count()),
            'tone' => self::PLAIN,
        ];

        return $lines;
    }

    /**
     * @return array{text: string, tone: string}
     */
    private function usage(string $label, int $used, ?int $limit): array
    {
        if (null === $limit || $limit <= 0) {
            return ['text' => $label . ' ' . MemoryUsage::format($used), 'tone' => self::PLAIN];
        }

        $share = $used / $limit;

        return [
            'text' => sprintf(
                '%s %s %s / %s',
                $label,
                self::gauge((int) round($share * 10), 10),
                MemoryUsage::format($used),
                MemoryUsage::format($limit)
            ),
            // Past four fifths of a known limit the container is close to
            // being killed outright, which leaves no stack trace at all.
            'tone' => $share >= 0.8 ? self::WARNING : self::PLAIN,
        ];
    }

    /**
     * @return list<array{text: string, tone: string}>
     */
    private function fears(Peur $peur): array
    {
        if ($peur->isEmpty()) {
            return [];
        }

        $lines = [['text' => 'Peur', 'tone' => self::WARNING]];

        foreach ($peur->strongest() as [$cue, $weight]) {
            $lines[] = [
                'text' => sprintf('  %-14s %.2f', self::readable($cue), $weight),
                'tone' => self::PLAIN,
            ];
        }

        return $lines;
    }

    /**
     * A cue as the panel says it. The rule reinforces whatever was present, so
     * a cue is a terrain or a region, and either has to be readable — the
     * whole point of the panel is being able to see *why* a cat went round
     * something.
     */
    public static function readable(string $cue): string
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
    private function goals(PlayerInterface $player): array
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
     * A bar of blocks, which both renderers can draw: the terminal writes the
     * characters, the window writes them through the bitmap font. One column
     * wide each, emoji excluded — the rule that governs everything with text
     * in it.
     */
    public static function gauge(int $value, int $total): string
    {
        $value = max(0, min($total, $value));

        return '[' . str_repeat('#', $value) . str_repeat('.', $total - $value) . ']';
    }
}
