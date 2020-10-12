<?php

namespace Codeception\Lib\Model;

use Codeception\Lib\Interfaces\ConflictsWithModule;
use Codeception\Lib\Interfaces\Web;

/**
 * @see ConflictsWithModule
 */
trait ConflictsWithModuleTrait
{
    public function _conflicts()
    {
        return Web::class;
    }
}
