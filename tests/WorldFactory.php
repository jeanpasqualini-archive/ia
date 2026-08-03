<?php

declare(strict_types=1);

namespace Tests;

use Logger\MultipleLogger;
use Map\Builder\MapBuilder;
use Map\Player\Chat;
use Map\World\World;

/**
 * Builds deterministic worlds from an ASCII drawing, so tests never depend on
 * the random map provider.
 */
final class WorldFactory
{
    /**
     * @param list<string> $rows tiles as MapBuilder constants
     */
    public static function fromRows(array $rows, int $chatX = 0, int $chatY = 0, int $players = 1): World
    {
        $chats = [];

        for ($i = 0; $i < $players; $i++) {
            $chat = new Chat();
            $chat->getPosition()->setX($chatX);
            $chat->getPosition()->setY($chatY);
            $chats[] = $chat;
        }

        return new World(new MapBuilder($rows, new MultipleLogger()), $chats);
    }

    public static function chat(World $world): Chat
    {
        $chat = $world->getPlayerCollection()[0];
        assert($chat instanceof Chat);

        return $chat;
    }
}
