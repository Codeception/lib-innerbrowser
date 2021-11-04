<?php

declare(strict_types=1);

use Codeception\Exception\ExternalUrlException;
use Codeception\Lib\Framework;
use Codeception\Lib\ModuleContainer;
use Codeception\Module\UniversalFramework;
use Codeception\Stub;

require_once __DIR__ . '/TestsForWeb.php';

/**
 * @group appveyor
 */
final class FrameworksTest extends TestsForWeb
{
    protected Framework $module;

    protected function _setUp()
    {
        $container = Stub::make(ModuleContainer::class);
        $this->module = new UniversalFramework($container);
        $this->module->_initialize();
    }

    public function testHttpAuth()
    {
        $this->module->amOnPage('/auth');
        $this->module->see('Unauthorized');
        $this->module->amHttpAuthenticated('davert', 'password');
        $this->module->amOnPage('/auth');
        $this->module->dontSee('Unauthorized');
        $this->module->see("Welcome, davert");
        $this->module->amHttpAuthenticated('davert', '123456');
        $this->module->amOnPage('/auth');
        $this->module->see('Forbidden');
    }

    public function testExceptionIsThrownOnRedirectToExternalUrl()
    {
        $this->expectException(ExternalUrlException::class);
        $this->module->amOnPage('/external_url');
        $this->module->click('Next');
    }

    public function testMoveBackOneStep()
    {
        $this->module->amOnPage('/iframe');
        $this->module->switchToIframe('content');
        $this->module->seeCurrentUrlEquals('/info');
        $this->module->click('Ссылочка');
        $this->module->seeCurrentUrlEquals('/');
        $this->module->moveBack();
        $this->module->seeCurrentUrlEquals('/info');
        $this->module->click('Sign in!');
        $this->module->seeCurrentUrlEquals('/login');
    }

    public function testMoveBackTwoSteps()
    {
        $this->module->amOnPage('/iframe');
        $this->module->switchToIframe('content');
        $this->module->seeCurrentUrlEquals('/info');
        $this->module->click('Ссылочка');
        $this->module->seeCurrentUrlEquals('/');
        $this->module->moveBack(2);
        $this->module->seeCurrentUrlEquals('/iframe');
    }

    public function testMoveBackThrowsExceptionIfNumberOfStepsIsInvalid()
    {
        $this->module->amOnPage('/iframe');
        $this->module->switchToIframe('content');
        $this->module->seeCurrentUrlEquals('/info');
        $this->module->click('Ссылочка');
        $this->module->seeCurrentUrlEquals('/');

        $invalidValues = [0, -5, 1.5, 'a', 3];
        foreach ($invalidValues as $invalidValue) {
            try {
                $this->module->moveBack($invalidValue);
                $this->fail('Expected to get exception here');
            } catch (InvalidArgumentException $exception) {
                codecept_debug('Exception: ' . $exception->getMessage());
            } catch (TypeError $error) {
                codecept_debug('Error: ' . $error->getMessage());
            }
        }
    }

    public function testCreateSnapshotOnFail()
    {
        $container = Stub::make(ModuleContainer::class);
        $module = Stub::construct(get_class($this->module), [$container], [
            '_savePageSource' => Stub\Expected::once(function ($filename) {
                $this->assertSame(codecept_log_dir('Codeception.Module.UniversalFramework.looks.like..test.fail.html'), $filename);
            }),
        ]);
        $module->_initialize();
        $module->amOnPage('/');

        $cest = new \Codeception\Test\Cest($this->module, 'looks:like::test', 'demo1Cest.php');
        $module->_failed($cest, new \PHPUnit\Framework\AssertionFailedError());
    }
}
