<?php
/**
 * Created by PhpStorm.
 * User: darkilliant
 * Date: 28/12/15
 * Time: 12:17
 */

namespace Memory;


use Map\World\World;
use Snapshot\Instant;
use Snapshot\InstantCollection;
use Snapshot\InstantCollectionLimited;

class FlashMemory {

    protected $limit = 10;

    protected $instantCollection;

    private $positionRead = -1;

    public function __construct()
    {
        $this->instantCollection = new InstantCollectionLimited($this->limit);
    }

    public function addInstant(Instant $instant)
    {
        $this->instantCollection->add($instant);

        $this->positionRead++;

        if ($this->positionRead > $this->limit) {
            $this->positionRead = $this->limit;
        }
    }

    public function getPositionRead()
    {
        return $this->positionRead;
    }

    public function getPlaces()
    {
        return $this->limit;
    }

    public function all()
    {
        return $this->instantCollection->all();
    }

    public function previous()
    {
        $this->positionRead--;
        return $this->instantCollection->get($this->positionRead);
    }

    public function after()
    {
        $this->positionRead++;
        return $this->instantCollection->get($this->positionRead);
    }

    public function count()
    {
        return $this->instantCollection->count();
    }
}