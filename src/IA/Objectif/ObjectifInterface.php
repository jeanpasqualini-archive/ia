<?php

declare(strict_types=1);

namespace IA\Objectif;

use Map\World\World;

/**
 * A goal the AI pursues across several ticks.
 */
interface ObjectifInterface
{
    public function update(World $world): void;

    /**
     * One line summary, shown in the AI panel.
     */
    public function describe(): string;
}
