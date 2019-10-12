<?php
/**
 * Created by PhpStorm.
 * User: darkilliant
 * Date: 28/12/15
 * Time: 13:32
 */

namespace Snapshot;


class InstantCollectionLimited extends InstantCollection {

    protected $limit = 0;

    public function __construct($limit)
    {
        $this->instantCollection = [];

        $this->limit = $limit;
    }

    public function add(Instant $instant)
    {
        if(count($this->instantCollection) >= $this->limit) {
            $this->instantCollection = array_slice($this->instantCollection, 1, 10);
        }

        parent::add($instant);
    }
}