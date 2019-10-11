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

    protected $limit = 1;

    protected $instantCollection;

    public function __construct()
    {
        $this->instantCollection = new InstantCollectionLimited($this->limit);
    }

    public function addInstant(Instant $instant)
    {
        $this->instantCollection->add($instant);
    }

    public function all()
    {
        return $this->instantCollection->all();
    }

    public function count()
    {
        return $this->instantCollection->count();
    }
}