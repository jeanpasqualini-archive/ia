<?php

use Map\Render\WebMapRender;
use Map\Player\Chat;
use Map\Location\Direction;
use Map\Provider\RandomMapProvider;
use Map\Builder\MapBuilder;

require __DIR__."/../vendor/autoload.php";

session_start();

$line = 30; //exec('tput lines');
$colonne = 40; //exec('tput cols');

//$line = exec('tput lines');
//$colonne = exec('tput cols');

$mapRender = new WebMapRender();
$mapRender->updateTime = 10000;

if(!isset($_SESSION["world"]))
{
    $chat = new Chat();

    $chat->getPosition()->setDirection(new Direction(1, 0));

    $map = new MapBuilder((new RandomMapProvider($line, $colonne))->getMap());

    $world = new \Map\World\World($map, array(
        $chat
    ));
}
else
{
    $world = unserialize($_SESSION["world"]);
}


try {
    $world->update();

    $players = $world->getPlayerCollection();

    foreach ($players as $player) {
        $world->getMap()->setItem($player->getPosition(), "P");
    }

    $mapRender->render($world->getMap()->getFinalMap());

}
catch(Exception $e)
{
    echo "<h1>".$e->getMessage()."</h1>";
}

//$_SESSION["world"] = serialize($world);