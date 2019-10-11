<?php
/**
 * Created by PhpStorm.
 * User: Freelance
 * Date: 24/12/2015
 * Time: 13:59
 */

namespace IA;


use IA\Objectif\Manger;
use Map\Player\Chat;
use Map\World\World;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcher;

class CatIA implements IAInterface
{
    protected $chat;

    protected $objectifs = array();

    public function __construct(Chat $chat)
    {
        $this->chat = $chat;

        $this->chat->getEstomac()->getEventDispatcher()->addListener("hungry", array($this, "onEstomacHungry"));
        $this->chat->getEstomac()->getEventDispatcher()->addListener("full", array($this, "onEstomacFull"));
    }

    public function onEstomacHungry(Event $event, $eventName, EventDispatcher $eventDispatcher)
    {
        if(!empty($this->objectifs)) return;

        $this->objectifs[] = new Manger($this->chat);
    }

    public function onEstomacFull(Event $event, $eventName, EventDispatcher $eventDispatcher)
    {
        $this->objectifs = array();
    }

    public function update(World $world)
    {
        $this->chat->move();

        foreach($this->objectifs as $objectif)
        {
            $objectif->update($world);
        }
    }
}