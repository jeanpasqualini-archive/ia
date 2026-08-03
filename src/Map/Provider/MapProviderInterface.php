<?php

declare(strict_types=1);

namespace Map\Provider;

interface MapProviderInterface
{
    /**
     * One string per line, each character being a MapBuilder tile constant.
     *
     * @return list<string>
     */
    public function getMap(): array;
}
