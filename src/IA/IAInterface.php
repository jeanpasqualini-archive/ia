<?php

declare(strict_types=1);

namespace IA;

use Map\World\World;

interface IAInterface
{
    public function update(World $world): void;
}
