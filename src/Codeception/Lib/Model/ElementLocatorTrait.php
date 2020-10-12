<?php

namespace Codeception\Lib\Model;

use Codeception\Lib\Interfaces\ElementLocator;

/**
 * @see ElementLocator
 */
trait ElementLocatorTrait
{
    public function _findElements($locator)
    {
        return $this->match($locator);
    }
}