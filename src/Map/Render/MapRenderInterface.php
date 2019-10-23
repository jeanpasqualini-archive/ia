<?php
/**
 * Created by PhpStorm.
 * User: darkilliant
 * Date: 27/12/15
 * Time: 17:35
 */

namespace Map\Render;


use Psr\Log\LoggerInterface;

interface MapRenderInterface {
    public function init();
    public function getSize(): array;
    public function render($map);
    public function clear($map);
}