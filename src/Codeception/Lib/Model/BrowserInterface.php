<?php

namespace Codeception\Lib\Model;

use Codeception\Lib\Interfaces\ConflictsWithModule;
use Codeception\Lib\Interfaces\ElementLocator;
use Codeception\Lib\Interfaces\PageSourceSaver;
use Codeception\Lib\Interfaces\Web;
use Codeception\TestInterface;

interface BrowserInterface extends ConflictsWithModule, ElementLocator, PageSourceSaver, Web
{
    public function _after(TestInterface $test);

    public function _failed(TestInterface $test, $fail);

    public function _getCurrentUri();

    public function _getResponseContent();

    public function _getResponseStatusCode();

    public function _loadPage(
        $method,
        $uri,
        array $parameters = [],
        array $files = [],
        array $server = [],
        $content = null
    );

    public function _request(
        $method,
        $uri,
        array $parameters = [],
        array $files = [],
        array $server = [],
        $content = null
    );

    public function amHttpAuthenticated($username, $password);

    public function deleteHeader($name);

    public function dontSeeResponseCodeIs($code);

    public function haveHttpHeader($name, $value);

    public function haveServerParameter($name, $value);

    public function moveBack($numberOfSteps = 1);

    public function seePageNotFound();

    public function seeResponseCodeIs($code);

    public function seeResponseCodeIsBetween($from, $to);

    public function seeResponseCodeIsClientError();

    public function seeResponseCodeIsRedirection();

    public function seeResponseCodeIsServerError();

    public function seeResponseCodeIsSuccessful();

    public function sendAjaxGetRequest($uri, $params = []);

    public function sendAjaxPostRequest($uri, $params = []);

    public function sendAjaxRequest($method, $uri, $params = []);

    public function setServerParameters(array $params);

    public function switchToIframe($name);
}