<?php
/**
 * Created by PhpStorm.
 * User: darkilliant
 * Date: 28/12/15
 * Time: 12:09
 */

namespace Snapshot;


class Instant {

    protected $data;

    public function __construct($instance)
    {
        $this->data = serialize($instance);
    }

    public function getData()
    {
        return unserialize($this->data);
    }

    public function __sleep()
    {
        return ['data'];
    }
}