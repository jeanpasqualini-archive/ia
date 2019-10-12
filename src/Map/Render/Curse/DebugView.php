<?php
/**
 * Created by PhpStorm.
 * User: darkilliant
 * Date: 28/12/15
 * Time: 13:12
 */

namespace Map\Render\Curse;


use CurseFramework\Listbox;
use IA\ApplicationIA;
use IA\CatIA;
use IA\Objectif\Manger;
use Map\Player\PlayerHasEstomac;
use Map\Player\PlayerInterface;
use Map\World\World;
use Map\World\WorldContainer;

class DebugView extends Listbox {

    /** @var WorldContainer */
    private $worldContainer;

    public function setWorldContainer(WorldContainer $worldContainer)
    {
        $this->worldContainer = $worldContainer;
    }

    public function update()
    {
        $players = $this->worldContainer->getWorld()->getPlayerCollection();
        $items = [];
        $items[] = [
            'title' => sprintf(
                '%d joueur(s)',
                count($players)
            ), 'bold' => false
        ];
        $items[] = [
            'title' => '------------', 'bold' => false
        ];

        /**
         * @var $players PlayerHasEstomac[]
         */
        foreach ($players as $id => $player) {
            $items[] = [
                'title' => sprintf(
                    '%s (%d)',
                    $this->getShortName(get_class($player)),
                    $id
                ),
                'bold' => false
            ];
            $items[] = [
                'title' => sprintf(
                    "   ".'nouriture : %s',
                    $player instanceof PlayerHasEstomac
                        ? $player->getEstomac()->getNouriture()
                        : 'no estomac'
                ),
                'bold' => false
            ];

            if ($player instanceof PlayerInterface)
            {
                    $items[] = [
                        'title' => sprintf(
                            "   ".'position : X: %s Y: %s',
                            $player->getPosition()->getX(),
                            $player->getPosition()->getY()
                        ),
                        'bold' => false
                    ];
                /** @var Manger $objectif */
                foreach ($player->getIa()->getObjectifs() as $objectif) {
                    $items[] = [
                        'title' => sprintf(
                            "   ".'objectif : %s',
                            $this->getShortName(get_class($objectif))
                        ),
                        'bold' => false
                    ];
                }
            }
        }

        $this->setItems($items);

        $this->setChanged(true);
    }

    protected function getShortName($class) {
        return substr($class, strrpos($class, '\\'));
    }
}