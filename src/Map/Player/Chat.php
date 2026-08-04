<?php

declare(strict_types=1);

namespace Map\Player;

use IA\CatIA;
use IA\IAInterface;
use Map\Location\Point;
use Map\Player\Chat\Estomac;
use Map\World\World;

class Chat extends Player implements PlayerHasEstomac
{
    private CatIA $ia;

    private Estomac $estomac;

    public function __construct()
    {
        $this->position = new Point(5, 5);
        $this->estomac = new Estomac($this);
        $this->ia = new CatIA($this);

        parent::__construct();
    }

    public function getEstomac(): Estomac
    {
        return $this->estomac;
    }

    public function getIa(): IAInterface
    {
        return $this->ia;
    }

    public function update(World $world): void
    {
        parent::update($world);

        $this->estomac->update($world);
    }

    /**
     * @return list<string>
     */
    public function __sleep(): array
    {
        return ['position', 'ia', 'estomac', 'identifiant', 'life', 'resistance', 'puissance'];
    }
}
