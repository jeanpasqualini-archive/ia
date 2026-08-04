<?php

declare(strict_types=1);

namespace Map\Player;

use IA\CatIA;
use IA\IAInterface;
use Map\Location\Point;
use Map\Player\Chat\Estomac;
use Map\Player\Chat\Peur;
use Map\World\World;

class Chat extends Player implements PlayerHasEstomac, PlayerHasPeur
{
    private CatIA $ia;

    private Estomac $estomac;

    private Peur $peur;

    public function __construct()
    {
        $this->position = new Point(5, 5);
        $this->estomac = new Estomac($this);
        $this->peur = new Peur();
        $this->ia = new CatIA($this);

        parent::__construct();
    }

    public function getEstomac(): Estomac
    {
        return $this->estomac;
    }

    public function getPeur(): Peur
    {
        return $this->peur;
    }

    public function getIa(): IAInterface
    {
        return $this->ia;
    }

    public function update(World $world): void
    {
        parent::update($world);

        $this->estomac->update($world);

        if ($this->peur->shouldFade($world->getTimer()->getTick())) {
            $this->peur->fade();
        }
    }

    /**
     * @return list<string>
     */
    public function __sleep(): array
    {
        return ['position', 'ia', 'estomac', 'peur', 'identifiant', 'life', 'resistance', 'puissance'];
    }
}
