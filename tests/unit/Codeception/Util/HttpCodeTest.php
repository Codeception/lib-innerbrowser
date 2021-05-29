<?php

declare(strict_types=1);

namespace Codeception\Util;

final class HttpCodeTest extends \Codeception\Test\Unit
{
    public function testHttpCodeConstants()
    {
        $this->assertSame(200, HttpCode::OK);
        $this->assertSame(404, HttpCode::NOT_FOUND);
    }

    public function testHttpCodeWithDescription()
    {
        $this->assertSame('200 (OK)', HttpCode::getDescription(200));
        $this->assertSame('301 (Moved Permanently)', HttpCode::getDescription(301));
        $this->assertSame('401 (Unauthorized)', HttpCode::getDescription(401));
    }
}
