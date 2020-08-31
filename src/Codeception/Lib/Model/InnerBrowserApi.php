<?php

namespace Codeception\Lib\Model;

use Codeception\Exception\ElementNotFound;
use Codeception\Test\Descriptor;
use Codeception\TestInterface;
use Codeception\Util\HttpCode;
use Codeception\Util\Uri;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\BrowserKit\Exception\BadMethodCallException;
use Symfony\Component\DomCrawler\Crawler;

trait InnerBrowserApi
{
    public function _failed(TestInterface $test, $fail)
    {
        try {
            if (!$this->client || !$this->client->getInternalResponse()) {
                return;
            }
        } catch (BadMethodCallException $e) {
            //Symfony 5 throws exception if request() method threw an exception.
            //The "request()" method must be called before "Symfony\Component\BrowserKit\AbstractBrowser::getInternalResponse()"
            return;
        }
        $filename = preg_replace('~\W~', '.', Descriptor::getTestSignatureUnique($test));

        $extensions = [
            'application/json' => 'json',
            'text/xml' => 'xml',
            'application/xml' => 'xml',
            'text/plain' => 'txt'
        ];

        try {
            $internalResponse = $this->client->getInternalResponse();
        } catch (BadMethodCallException $e) {
            $internalResponse = false;
        }

        $responseContentType = $internalResponse ? $internalResponse->getHeader('content-type') : '';
        list($responseMimeType) = explode(';', $responseContentType);

        $extension = isset($extensions[$responseMimeType]) ? $extensions[$responseMimeType] : 'html';

        $filename = mb_strcut($filename, 0, 244, 'utf-8') . '.fail.' . $extension;
        $this->_savePageSource($report = codecept_output_dir() . $filename);
        $test->getMetadata()->addReport('html', $report);
        $test->getMetadata()->addReport('response', $report);
    }

    public function _after(TestInterface $test)
    {
        $this->client = null;
        $this->crawler = null;
        $this->forms = [];
        $this->headers = [];
    }

    /**
     * Send custom request to a backend using method, uri, parameters, etc.
     * Use it in Helpers to create special request actions, like accessing API
     * Returns a string with response body.
     *
     * ```php
     * <?php
     * // in Helper class
     * public function createUserByApi($name) {
     *     $userData = $this->getModule('{{MODULE_NAME}}')->_request('POST', '/api/v1/users', ['name' => $name]);
     *     $user = json_decode($userData);
     *     return $user->id;
     * }
     * ?>
     * ```
     * Does not load the response into the module so you can't interact with response page (click, fill forms).
     * To load arbitrary page for interaction, use `_loadPage` method.
     *
     * @param $method
     * @param $uri
     * @param array $parameters
     * @param array $files
     * @param array $server
     * @param null $content
     * @return mixed|Crawler
     * @api
     * @see `_loadPage`
     */
    public function _request(
        $method,
        $uri,
        array $parameters = [],
        array $files = [],
        array $server = [],
        $content = null
    ) {
        $this->clientRequest($method, $uri, $parameters, $files, $server, $content, true);
        return $this->_getResponseContent();
    }

    /**
     * Returns content of the last response
     * Use it in Helpers when you want to retrieve response of request performed by another module.
     *
     * ```php
     * <?php
     * // in Helper class
     * public function seeResponseContains($text)
     * {
     *    $this->assertStringContainsString($text, $this->getModule('{{MODULE_NAME}}')->_getResponseContent(), "response contains");
     * }
     * ?>
     * ```
     *
     * @api
     * @return string
     */
    public function _getResponseContent()
    {
        return (string)$this->getRunningClient()->getInternalResponse()->getContent();
    }

    /**
     * Opens a page with arbitrary request parameters.
     * Useful for testing multi-step forms on a specific step.
     *
     * ```php
     * <?php
     * // in Helper class
     * public function openCheckoutFormStep2($orderId) {
     *     $this->getModule('{{MODULE_NAME}}')->_loadPage('POST', '/checkout/step2', ['order' => $orderId]);
     * }
     * ?>
     * ```
     *
     * @param $method
     * @param $uri
     * @param array $parameters
     * @param array $files
     * @param array $server
     * @param null $content
     * @api
     */
    public function _loadPage(
        $method,
        $uri,
        array $parameters = [],
        array $files = [],
        array $server = [],
        $content = null
    ) {
        $this->crawler = $this->clientRequest($method, $uri, $parameters, $files, $server, $content);
        $this->baseUrl = $this->retrieveBaseUrl();
        $this->forms = [];
    }

    /**
     * Authenticates user for HTTP_AUTH
     *
     * @param $username
     * @param $password
     */
    public function amHttpAuthenticated($username, $password)
    {
        $this->client->setServerParameter('PHP_AUTH_USER', $username);
        $this->client->setServerParameter('PHP_AUTH_PW', $password);
    }

    /**
     * Sets the HTTP header to the passed value - which is used on
     * subsequent HTTP requests through PhpBrowser.
     *
     * Example:
     * ```php
     * <?php
     * $I->haveHttpHeader('X-Requested-With', 'Codeception');
     * $I->amOnPage('test-headers.php');
     * ?>
     * ```
     *
     * To use special chars in Header Key use HTML Character Entities:
     * Example:
     * Header with underscore - 'Client_Id'
     * should be represented as - 'Client&#x0005F;Id' or 'Client&#95;Id'
     *
     * ```php
     * <?php
     * $I->haveHttpHeader('Client&#95;Id', 'Codeception');
     * ?>
     * ```
     *
     * @param string $name the name of the request header
     * @param string $value the value to set it to for subsequent
     *        requests
     */
    public function haveHttpHeader($name, $value)
    {
        $name = implode('-', array_map('ucfirst', explode('-', strtolower(str_replace('_', '-', $name)))));
        $this->headers[$name] = $value;
    }

    /**
     * Deletes the header with the passed name.  Subsequent requests
     * will not have the deleted header in its request.
     *
     * Example:
     * ```php
     * <?php
     * $I->haveHttpHeader('X-Requested-With', 'Codeception');
     * $I->amOnPage('test-headers.php');
     * // ...
     * $I->deleteHeader('X-Requested-With');
     * $I->amOnPage('some-other-page.php');
     * ?>
     * ```
     *
     * @param string $name the name of the header to delete.
     */
    public function deleteHeader($name)
    {
        $name = implode('-', array_map('ucfirst', explode('-', strtolower(str_replace('_', '-', $name)))));
        unset($this->headers[$name]);
    }

    /**
     * @return string
     */
    public function _getCurrentUri()
    {
        return Uri::retrieveUri($this->getRunningClient()->getHistory()->current()->getUri());
    }

    /**
     * If your page triggers an ajax request, you can perform it manually.
     * This action sends a GET ajax request with specified params.
     *
     * See ->sendAjaxPostRequest for examples.
     *
     * @param $uri
     * @param $params
     */
    public function sendAjaxGetRequest($uri, $params = [])
    {
        $this->sendAjaxRequest('GET', $uri, $params);
    }

    /**
     * If your page triggers an ajax request, you can perform it manually.
     * This action sends a POST ajax request with specified params.
     * Additional params can be passed as array.
     *
     * Example:
     *
     * Imagine that by clicking checkbox you trigger ajax request which updates user settings.
     * We emulate that click by running this ajax request manually.
     *
     * ``` php
     * <?php
     * $I->sendAjaxPostRequest('/updateSettings', array('notifications' => true)); // POST
     * $I->sendAjaxGetRequest('/updateSettings', array('notifications' => true)); // GET
     *
     * ```
     *
     * @param $uri
     * @param $params
     */
    public function sendAjaxPostRequest($uri, $params = [])
    {
        $this->sendAjaxRequest('POST', $uri, $params);
    }

    /**
     * If your page triggers an ajax request, you can perform it manually.
     * This action sends an ajax request with specified method and params.
     *
     * Example:
     *
     * You need to perform an ajax request specifying the HTTP method.
     *
     * ``` php
     * <?php
     * $I->sendAjaxRequest('PUT', '/posts/7', array('title' => 'new title'));
     *
     * ```
     *
     * @param $method
     * @param $uri
     * @param $params
     */
    public function sendAjaxRequest($method, $uri, $params = [])
    {
        $this->clientRequest($method, $uri, $params, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], null, false);
    }

    public function _getResponseStatusCode()
    {
        return $this->getResponseStatusCode();
    }

    /**
     * Asserts that current page has 404 response status code.
     */
    public function seePageNotFound()
    {
        $this->seeResponseCodeIs(404);
    }

    /**
     * Checks that response code is equal to value provided.
     *
     * ```php
     * <?php
     * $I->seeResponseCodeIs(200);
     *
     * // recommended \Codeception\Util\HttpCode
     * $I->seeResponseCodeIs(\Codeception\Util\HttpCode::OK);
     * ```
     *
     * @param $code
     */
    public function seeResponseCodeIs($code)
    {
        $failureMessage = sprintf(
            'Expected HTTP Status Code: %s. Actual Status Code: %s',
            HttpCode::getDescription($code),
            HttpCode::getDescription($this->getResponseStatusCode())
        );
        $this->assertEquals($code, $this->getResponseStatusCode(), $failureMessage);
    }

    /**
     * Checks that response code is between a certain range. Between actually means [from <= CODE <= to]
     *
     * @param $from
     * @param $to
     */
    public function seeResponseCodeIsBetween($from, $to)
    {
        $failureMessage = sprintf(
            'Expected HTTP Status Code between %s and %s. Actual Status Code: %s',
            HttpCode::getDescription($from),
            HttpCode::getDescription($to),
            HttpCode::getDescription($this->getResponseStatusCode())
        );
        $this->assertGreaterThanOrEqual($from, $this->getResponseStatusCode(), $failureMessage);
        $this->assertLessThanOrEqual($to, $this->getResponseStatusCode(), $failureMessage);
    }

    /**
     * Checks that response code is equal to value provided.
     *
     * ```php
     * <?php
     * $I->dontSeeResponseCodeIs(200);
     *
     * // recommended \Codeception\Util\HttpCode
     * $I->dontSeeResponseCodeIs(\Codeception\Util\HttpCode::OK);
     * ```
     * @param $code
     */
    public function dontSeeResponseCodeIs($code)
    {
        $failureMessage = sprintf(
            'Expected HTTP status code other than %s',
            HttpCode::getDescription($code)
        );
        $this->assertNotEquals($code, $this->getResponseStatusCode(), $failureMessage);
    }

    /**
     * Checks that the response code 2xx
     */
    public function seeResponseCodeIsSuccessful()
    {
        $this->seeResponseCodeIsBetween(200, 299);
    }

    /**
     * Checks that the response code 3xx
     */
    public function seeResponseCodeIsRedirection()
    {
        $this->seeResponseCodeIsBetween(300, 399);
    }

    /**
     * Checks that the response code is 4xx
     */
    public function seeResponseCodeIsClientError()
    {
        $this->seeResponseCodeIsBetween(400, 499);
    }

    /**
     * Checks that the response code is 5xx
     */
    public function seeResponseCodeIsServerError()
    {
        $this->seeResponseCodeIsBetween(500, 599);
    }

    /**
     * Switch to iframe or frame on the page.
     *
     * Example:
     * ``` html
     * <iframe name="another_frame" src="http://example.com">
     * ```
     *
     * ``` php
     * <?php
     * # switch to iframe
     * $I->switchToIframe("another_frame");
     * ```
     *
     * @param string $name
     */
    public function switchToIframe($name)
    {
        $iframe = $this->match("iframe[name=$name]")->first();
        if (!count($iframe)) {
            $iframe = $this->match("frame[name=$name]")->first();
        }
        if (!count($iframe)) {
            throw new ElementNotFound("name=$name", 'Iframe');
        }

        $uri = $iframe->getNode(0)->getAttribute('src');
        $this->amOnPage($uri);
    }

    /**
     * Moves back in history.
     *
     * @param int $numberOfSteps (default value 1)
     */
    public function moveBack($numberOfSteps = 1)
    {
        if (!is_int($numberOfSteps) || $numberOfSteps < 1) {
            throw new InvalidArgumentException('numberOfSteps must be positive integer');
        }
        try {
            $history = $this->getRunningClient()->getHistory();
            for ($i = $numberOfSteps; $i > 0; $i--) {
                $request = $history->back();
            }
        } catch (LogicException $e) {
            throw new InvalidArgumentException(
                sprintf(
                    'numberOfSteps is set to %d, but there are only %d previous steps in the history',
                    $numberOfSteps,
                    $numberOfSteps - $i
                )
            );
        }
        $this->_loadPage(
            $request->getMethod(),
            $request->getUri(),
            $request->getParameters(),
            $request->getFiles(),
            $request->getServer(),
            $request->getContent()
        );
    }

    /**
     * Sets SERVER parameters valid for all next requests.
     * this will remove old ones.
     *
     * ```php
     * $I->setServerParameters([]);
     * ```
     * @param array $params
     */
    public function setServerParameters(array $params)
    {
        $this->client->setServerParameters($params);
    }

    /**
     * Sets SERVER parameter valid for all next requests.
     *
     * ```php
     * $I->haveServerParameter('name', 'value');
     * ```
     * @param $name
     * @param $value
     */
    public function haveServerParameter($name, $value)
    {
        $this->client->setServerParameter($name, $value);
    }
}