<?php

namespace Codeception\Lib\Model;

use Codeception\Lib\Interfaces\PageSourceSaver;

/**
 * @see PageSourceSaver
 */
trait PageSourceSaverTrait
{
    public function _savePageSource($filename)
    {
        file_put_contents($filename, $this->_getResponseContent());
    }

    public function makeHtmlSnapshot($name = null)
    {
        if (empty($name)) {
            $name = uniqid(date("Y-m-d_H-i-s_"), true);
        }
        $debugDir = codecept_output_dir() . 'debug';
        if (!is_dir($debugDir)) {
            mkdir($debugDir, 0777);
        }
        $fileName = $debugDir . DIRECTORY_SEPARATOR . $name . '.html';

        $this->_savePageSource($fileName);
        $this->debugSection('Snapshot Saved', "file://$fileName");
    }
}