<?php
namespace Memory;


class MemoryManager {

    /** @var FlashMemory */
    protected $flashMemory;

    /**
     * @var string
     */
    private $id = 'game';

    public function __construct(string $id = 'game')
    {
        $this->flashMemory = new FlashMemory();
        $this->id = $id;
    }

    /**
     * @param string $id
     */
    public function setId(string $id)
    {
        $this->id = $id;
    }

    public function getFlashMemory()
    {
        return $this->flashMemory;
    }

    public function persist()
    {
        $ds = DIRECTORY_SEPARATOR;

        $cacheMemory = __DIR__."/../../app/cache/".c."/";

        if(!file_exists($cacheMemory)) mkdir($cacheMemory);

        file_put_contents($cacheMemory."memory.dump", serialize($this->getFlashMemory()->all()));

        return $this->id;
    }

    public function __sleep()
    {
        return ['id'];
    }

    public function __wakeup()
    {
        $this->flashMemory = new FlashMemory();
    }
}