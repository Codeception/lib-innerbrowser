<?php

declare(strict_types=1);

namespace Codeception\Lib;

use Codeception\Constraint\Crawler as CrawlerConstraint;
use Codeception\Constraint\CrawlerNot as CrawlerNotConstraint;
use Codeception\Constraint\Page as PageConstraint;
use Codeception\Exception\ElementNotFound;
use Codeception\Exception\ExternalUrlException;
use Codeception\Exception\MalformedLocatorException;
use Codeception\Exception\ModuleException;
use Codeception\Exception\TestRuntimeException;
use Codeception\Lib\Interfaces\ConflictsWithModule;
use Codeception\Lib\Interfaces\ElementLocator;
use Codeception\Lib\Interfaces\PageSourceSaver;
use Codeception\Lib\Interfaces\Web;
use Codeception\Module;
use Codeception\Test\Descriptor;
use Codeception\TestInterface;
use Codeception\Util\HttpCode;
use Codeception\Util\Locator;
use Codeception\Util\ReflectionHelper;
use Codeception\Util\Uri;
use DOMDocument;
use DOMNode;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\BrowserKit\Exception\BadMethodCallException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Crawler as SymfonyCrawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Field\FormField;
use Symfony\Component\DomCrawler\Field\InputFormField;
use Symfony\Component\DomCrawler\Field\TextareaFormField;
use Symfony\Component\DomCrawler\Form as SymfonyForm;
use Symfony\Component\DomCrawler\Link;

class InnerBrowser extends Module implements Web, PageSourceSaver, ElementLocator, ConflictsWithModule
{
    protected ?SymfonyCrawler $crawler = null;

    /**
     * @api
     */
    public ?AbstractBrowser $client = null;

    /**
     * @var SymfonyForm[]
     */
    protected array $forms = [];

    /**
     * @var string[]
     */
    public array $headers = [];

    /**
     * @var array<string, string>|array<string, bool>|array<string, null>
     */
    protected array $defaultCookieParameters = ['expires' => null, 'path' => '/', 'domain' => '', 'secure' => false];

    /**
     * @var string[]|null
     */
    protected ?array $internalDomains = null;

    private ?string $baseUrl = null;

    public function _failed(TestInterface $test, $fail)
    {
        try {
            if (!$this->client || !$this->client->getInternalResponse()) {
                return;
            }
        } catch (BadMethodCallException) {
            //Symfony 5 throws exception if request() method threw an exception.
            //The "request()" method must be called before "Symfony\Component\BrowserKit\AbstractBrowser::getInternalResponse()"
            return;
        }
        $filename = preg_replace('#\W#', '.', Descriptor::getTestSignatureUnique($test));

        $extensions = [
            'application/json' => 'json',
            'text/xml' => 'xml',
            'application/xml' => 'xml',
            'text/plain' => 'txt'
        ];

        try {
            $internalResponse = $this->client->getInternalResponse();
        } catch (BadMethodCallException) {
            $internalResponse = false;
        }

        $responseContentType = $internalResponse ? (string) $internalResponse->getHeader('content-type') : '';
        [$responseMimeType] = explode(';', $responseContentType);

        $extension = $extensions[$responseMimeType] ?? 'html';

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
     * @return class-string
     */
    public function _conflicts(): string
    {
        return \Codeception\Lib\Interfaces\Web::class;
    }

    public function _findElements(mixed $locator): iterable
    {
        return $this->match($locator);
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
     * ```
     * Does not load the response into the module so you can't interact with response page (click, fill forms).
     * To load arbitrary page for interaction, use `_loadPage` method.
     *
     * @throws ExternalUrlException|ModuleException
     * @api
     * @see `_loadPage`
     */
    public function _request(
        string $method,
        string $uri,
        array $parameters = [],
        array $files = [],
        array $server = [],
        ?string $content = null
    ): ?string {
        $this->clientRequest($method, $uri, $parameters, $files, $server, $content);
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
     * ```
     *
     * @api
     * @throws ModuleException
     */
    public function _getResponseContent(): string
    {
        return $this->getRunningClient()->getInternalResponse()->getContent();
    }

    protected function clientRequest(
        string $method,
        string $uri,
        array $parameters = [],
        array $files = [],
        array $server = [],
        ?string $content = null,
        bool $changeHistory = true
    ): SymfonyCrawler {
        $this->debugSection("Request Headers", $this->headers);

        foreach ($this->headers as $header => $val) { // moved from REST module

            if ($val === null || $val === '') {
                continue;
            }

            $header = str_replace('-', '_', strtoupper($header));
            $server["HTTP_{$header}"] = $val;

            // Issue #827 - symfony foundation requires 'CONTENT_TYPE' without HTTP_
            if ($this instanceof Framework && $header === 'CONTENT_TYPE') {
                $server[$header] = $val;
            }
        }

        $server['REQUEST_TIME'] = time();
        $server['REQUEST_TIME_FLOAT'] = microtime(true);
        if ($this instanceof Framework) {
            if (preg_match('#^(//|https?://(?!localhost))#', $uri)) {
                $hostname = parse_url($uri, PHP_URL_HOST);
                if (!$this->isInternalDomain($hostname)) {
                    throw new ExternalUrlException($this::class . " can't open external URL: " . $uri);
                }
            }

            if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && $content === null && !empty($parameters)) {
                $content = http_build_query($parameters);
            }
        }

        if (method_exists($this->client, 'isFollowingRedirects')) {
            $isFollowingRedirects = $this->client->isFollowingRedirects();
            $maxRedirects = $this->client->getMaxRedirects();
        } else {
            //Symfony 2.7 support
            $isFollowingRedirects = ReflectionHelper::readPrivateProperty($this->client, 'followRedirects', 'Symfony\Component\BrowserKit\Client');
            $maxRedirects = ReflectionHelper::readPrivateProperty($this->client, 'maxRedirects', 'Symfony\Component\BrowserKit\Client');
        }

        if (!$isFollowingRedirects) {
            $result = $this->client->request($method, $uri, $parameters, $files, $server, $content, $changeHistory);
            $this->debugResponse($uri);
            return $result;
        }

        $this->client->followRedirects(false);
        $result = $this->client->request($method, $uri, $parameters, $files, $server, $content, $changeHistory);
        $this->debugResponse($uri);
        return $this->redirectIfNecessary($result, $maxRedirects, 0);
    }

    protected function isInternalDomain(string $domain): bool
    {
        if ($this->internalDomains === null) {
            $this->internalDomains = $this->getInternalDomains();
        }

        foreach ($this->internalDomains as $pattern) {
            if (preg_match($pattern, $domain)) {
                return true;
            }
        }

        return false;
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
     * ```
     *
     * @api
     */
    public function _loadPage(
        string $method,
        string $uri,
        array $parameters = [],
        array $files = [],
        array $server = [],
        ?string $content = null
    ): void {
        $this->crawler = $this->clientRequest($method, $uri, $parameters, $files, $server, $content);
        $this->baseUrl = $this->retrieveBaseUrl();
        $this->forms = [];
    }

    /**
     * @throws ModuleException
     */
    private function getCrawler(): SymfonyCrawler
    {
        if (!$this->crawler) {
            throw new ModuleException($this, 'Crawler is null. Perhaps you forgot to call "amOnPage"?');
        }

        return $this->crawler;
    }

    private function getRunningClient(): AbstractBrowser
    {
        try {
            if ($this->client->getInternalRequest() === null) {
                throw new ModuleException(
                    $this,
                    "Page not loaded. Use `\$I->amOnPage` (or hidden API methods `_request` and `_loadPage`) to open it"
                );
            }
        } catch (BadMethodCallException) {
            //Symfony 5
            throw new ModuleException(
                $this,
                "Page not loaded. Use `\$I->amOnPage` (or hidden API methods `_request` and `_loadPage`) to open it"
            );
        }

        return $this->client;
    }

    public function _savePageSource(string $filename): void
    {
        file_put_contents($filename, $this->_getResponseContent());
    }

    /**
     * Authenticates user for HTTP_AUTH
     */
    public function amHttpAuthenticated(string $username, string $password): void
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
     * ```
     *
     * @param string $name the name of the request header
     * @param string $value the value to set it to for subsequent
     *        requests
     */
    public function haveHttpHeader(string $name, string $value): void
    {
        $name = implode('-', array_map('ucfirst', explode('-', strtolower(str_replace('_', '-', $name)))));
        $this->headers[$name] = $value;
    }

    /**
     * Unsets a HTTP header (that was originally added by [haveHttpHeader()](#haveHttpHeader)),
     * so that subsequent requests will not send it anymore.
     *
     * Example:
     * ```php
     * <?php
     * $I->haveHttpHeader('X-Requested-With', 'Codeception');
     * $I->amOnPage('test-headers.php');
     * // ...
     * $I->unsetHeader('X-Requested-With');
     * $I->amOnPage('some-other-page.php');
     * ```
     *
     * @param string $name the name of the header to unset.
     */
    public function unsetHttpHeader(string $name): void
    {
        $name = implode('-', array_map('ucfirst', explode('-', strtolower(str_replace('_', '-', $name)))));
        unset($this->headers[$name]);
    }

    /**
     * @deprecated Use [unsetHttpHeader](#unsetHttpHeader) instead
     */
    public function deleteHeader(string $name): void
    {
        $this->unsetHttpHeader($name);
    }

    public function amOnPage(string $page): void
    {
        $this->_loadPage('GET', $page);
    }

    public function click($link, $context = null): void
    {
        if ($context) {
            $this->crawler = $this->match($context);
        }

        if (is_array($link)) {
            $this->clickByLocator($link);
            return;
        }

        $anchor = $this->strictMatch(['link' => $link]);
        if (count($anchor) === 0) {
            $anchor = $this->getCrawler()->selectLink($link);
        }

        if (count($anchor) > 0) {
            $this->openHrefFromDomNode($anchor->getNode(0));
            return;
        }

        $buttonText = str_replace('"', "'", $link);
        $button = $this->crawler->selectButton($buttonText);

        if (count($button) && $this->clickButton($button->getNode(0))) {
            return;
        }

        try {
            $this->clickByLocator($link);
        } catch (MalformedLocatorException) {
            throw new ElementNotFound("name={$link}", "'{$link}' is invalid CSS and XPath selector and Link or Button");
        }
    }

    /**
     * @param string|string[] $link
     */
    protected function clickByLocator(string|array $link): ?bool
    {
        $nodes = $this->match($link);
        if ($nodes->count() === 0) {
            throw new ElementNotFound($link, 'Link or Button by name or CSS or XPath');
        }

        foreach ($nodes as $node) {
            $tag = $node->tagName;
            $type = $node->getAttribute('type');

            if ($tag === 'a') {
                $this->openHrefFromDomNode($node);
                return true;
            }

            if (in_array($tag, ['input', 'button']) && in_array($type, ['submit', 'image'])) {
                return $this->clickButton($node);
            }
        }

        return null;
    }

    /**
     * Clicks the link or submits the form when the button is clicked
     *
     * @return bool clicked something
     */
    private function clickButton(DOMNode $node): bool
    {
        /**
         * First we check if the button is associated to a form.
         * It is associated to a form when it has a nonempty form
         */
        $formAttribute = $node->attributes->getNamedItem('form');
        if (isset($formAttribute)) {
            $form = empty($formAttribute->nodeValue) ? null : $this->filterByCSS('#' . $formAttribute->nodeValue)->getNode(0);
        } else {
            // Check parents
            $currentNode = $node;
            $form = null;
            while ($currentNode->parentNode !== null) {
                $currentNode = $currentNode->parentNode;
                if ($currentNode->nodeName === 'form') {
                    $form = $node;
                    break;
                }
            }
        }

        if (isset($form)) {
            $buttonName = $node->getAttribute('name');
            $formParams = $buttonName !== '' ? [$buttonName => $node->getAttribute('value')] : [];
            $this->proceedSubmitForm(
                new SymfonyCrawler($form, $this->getAbsoluteUrlFor($this->_getCurrentUri()), $this->getBaseUrl()),
                $formParams
            );
            return true;
        }

        // Check if the button is inside an anchor.
        $currentNode = $node;
        while ($currentNode->parentNode !== null) {
            $currentNode = $currentNode->parentNode;
            if ($currentNode->nodeName === 'a') {
                $this->openHrefFromDomNode($currentNode);
                return true;
            }
        }

        throw new TestRuntimeException('Button is not inside a link or a form');
    }

    private function openHrefFromDomNode(DOMNode $node): void
    {
        $link = new Link($node, $this->getBaseUrl());
        $this->amOnPage(preg_replace('/#.*/', '', $link->getUri()));
    }

    private function getBaseUrl(): ?string
    {
        return $this->baseUrl;
    }

    private function retrieveBaseUrl(): string
    {
        $baseUrl = '';

        $baseHref = $this->crawler->filter('base');
        if (count($baseHref) > 0) {
            $baseUrl = $baseHref->getNode(0)->getAttribute('href');
        }

        if ($baseUrl === '') {
            $baseUrl = $this->_getCurrentUri();
        }

        return $this->getAbsoluteUrlFor($baseUrl);
    }

    public function see(string $text, $selector = null): void
    {
        if (!$selector) {
            $this->assertPageContains($text);
            return;
        }

        $nodes = $this->match($selector);
        $this->assertDomContains($nodes, $this->stringifySelector($selector), $text);
    }

    public function dontSee(string $text, $selector = null): void
    {
        if (!$selector) {
            $this->assertPageNotContains($text);
            return;
        }

        $nodes = $this->match($selector);
        $this->assertDomNotContains($nodes, $this->stringifySelector($selector), $text);
    }

    public function seeInSource(string $raw): void
    {
        $this->assertPageSourceContains($raw);
    }

    public function dontSeeInSource(string $raw): void
    {
        $this->assertPageSourceNotContains($raw);
    }

    public function seeLink(string $text, ?string $url = null): void
    {
        $crawler = $this->getCrawler()->selectLink($text);
        if ($crawler->count() === 0) {
            $this->fail("No links containing text '{$text}' were found in page " . $this->_getCurrentUri());
        }

        if ($url) {
            $crawler = $crawler->filterXPath(sprintf('.//a[substring(@href, string-length(@href) - string-length(%1$s) + 1)=%1$s]', SymfonyCrawler::xpathLiteral($url)));
            if ($crawler->count() === 0) {
                $this->fail("No links containing text '{$text}' and URL '{$url}' were found in page " . $this->_getCurrentUri());
            }
        }

        $this->assertTrue(true);
    }

    public function dontSeeLink(string $text, string $url = ''): void
    {
        $crawler = $this->getCrawler()->selectLink($text);
        if (!$url && $crawler->count() > 0) {
            $this->fail("Link containing text '{$text}' was found in page " . $this->_getCurrentUri());
        }

        $crawler = $crawler->filterXPath(
            sprintf('.//a[substring(@href, string-length(@href) - string-length(%1$s) + 1)=%1$s]',
                SymfonyCrawler::xpathLiteral((string) $url))
        );
        if ($crawler->count() > 0) {
            $this->fail("Link containing text '{$text}' and URL '{$url}' was found in page " . $this->_getCurrentUri());
        }
    }

    /**
     * @throws ModuleException
     */
    public function _getCurrentUri(): string
    {
        return Uri::retrieveUri($this->getRunningClient()->getHistory()->current()->getUri());
    }

    public function seeInCurrentUrl(string $uri): void
    {
        $this->assertStringContainsString($uri, $this->_getCurrentUri());
    }

    public function dontSeeInCurrentUrl(string $uri): void
    {
        $this->assertStringNotContainsString($uri, $this->_getCurrentUri());
    }

    public function seeCurrentUrlEquals(string $uri): void
    {
        $this->assertSame(rtrim($uri, '/'), rtrim($this->_getCurrentUri(), '/'));
    }

    public function dontSeeCurrentUrlEquals(string $uri): void
    {
        $this->assertNotSame(rtrim($uri, '/'), rtrim($this->_getCurrentUri(), '/'));
    }

    public function seeCurrentUrlMatches(string $uri): void
    {
        $this->assertRegExp($uri, $this->_getCurrentUri());
    }

    public function dontSeeCurrentUrlMatches(string $uri): void
    {
        $this->assertNotRegExp($uri, $this->_getCurrentUri());
    }

    public function grabFromCurrentUrl(?string $uri = null): mixed
    {
        if (!$uri) {
            return $this->_getCurrentUri();
        }

        $matches = [];
        $res     = preg_match($uri, $this->_getCurrentUri(), $matches);
        if (!$res) {
            $this->fail("Couldn't match {$uri} in " . $this->_getCurrentUri());
        }

        if (!isset($matches[1])) {
            $this->fail("Nothing to grab. A regex parameter required. Ex: '/user/(\\d+)'");
        }

        return $matches[1];
    }

    public function seeCheckboxIsChecked($checkbox): void
    {
        $checkboxes = $this->getFieldsByLabelOrCss($checkbox);
        $this->assertGreaterThan(0, $checkboxes->filter('input[checked]')->count());
    }

    public function dontSeeCheckboxIsChecked($checkbox): void
    {
        $checkboxes = $this->getFieldsByLabelOrCss($checkbox);
        $this->assertSame(0, $checkboxes->filter('input[checked]')->count());
    }

    public function seeInField($field, $value): void
    {
        $nodes = $this->getFieldsByLabelOrCss($field);
        $this->assert($this->proceedSeeInField($nodes, $value));
    }

    public function dontSeeInField($field, $value): void
    {
        $nodes = $this->getFieldsByLabelOrCss($field);
        $this->assertNot($this->proceedSeeInField($nodes, $value));
    }

    public function seeInFormFields($formSelector, array $params): void
    {
        $this->proceedSeeInFormFields($formSelector, $params, false);
    }

    public function dontSeeInFormFields($formSelector, array $params): void
    {
        $this->proceedSeeInFormFields($formSelector, $params, true);
    }

    protected function proceedSeeInFormFields($formSelector, array $params, $assertNot)
    {
        $form = $this->match($formSelector)->first();
        if ($form->count() === 0) {
            throw new ElementNotFound($formSelector, 'Form');
        }

        $fields = [];
        foreach ($params as $name => $values) {
            $this->pushFormField($fields, $form, $name, $values);
        }

        foreach ($fields as [$field, $values]) {
            if (!is_array($values)) {
                $values = [$values];
            }

            foreach ($values as $value) {
                $ret = $this->proceedSeeInField($field, $value);
                if ($assertNot) {
                    $this->assertNot($ret);
                } else {
                    $this->assert($ret);
                }
            }
        }
    }

    /**
     * Map an array element passed to seeInFormFields to its corresponding field,
     * recursing through array values if the field is not found.
     *
     * @param array $fields The previously found fields.
     * @param SymfonyCrawler $form The form in which to search for fields.
     * @param string $name The field's name.
     * @param mixed $values
     */
    protected function pushFormField(array &$fields, SymfonyCrawler $form, string $name, $values): void
    {
        $field = $form->filterXPath(sprintf('.//*[@name=%s]', SymfonyCrawler::xpathLiteral($name)));

        if ($field->count() !== 0) {
            $fields[] = [$field, $values];
        } elseif (is_array($values)) {
            foreach ($values as $key => $value) {
                $this->pushFormField($fields, $form, sprintf('%s[%s]', $name, $key), $value);
            }
        } else {
            throw new ElementNotFound(
                sprintf('//*[@name=%s]', SymfonyCrawler::xpathLiteral($name)),
                'Form'
            );
        }
    }

    protected function proceedSeeInField(Crawler $fields, $value): array
    {
        $testValues = $this->getValueAndTextFromField($fields);
        if (!is_array($testValues)) {
            $testValues = [$testValues];
        }

        if (is_bool($value) && $value && !empty($testValues)) {
            $value = reset($testValues);
        } elseif (empty($testValues)) {
            $testValues = [''];
        }

        return [
            'Contains',
            (string)$value,
            $testValues,
            sprintf(
                "Failed asserting that `%s` is in %s's value: %s",
                $value,
                $fields->getNode(0)->nodeName,
                var_export($testValues, true)
            )
        ];
    }

    /**
     * Get the values of a set of fields and also the texts of selected options.
     */
    protected function getValueAndTextFromField(Crawler $nodes): array|string
    {
        if ($nodes->filter('textarea')->count() !== 0) {
            return (new TextareaFormField($nodes->filter('textarea')->getNode(0)))->getValue();
        }

        $input = $nodes->filter('input');
        if ($input->count() !== 0) {
            return $this->getInputValue($input);
        }

        if ($nodes->filter('select')->count() !== 0) {
            $options = $nodes->filter('option[selected]');
            $values = [];

            foreach ($options as $option) {
                $values[] = $option->getAttribute('value');
                $values[] = $option->textContent;
                $values[] = trim($option->textContent);
            }

            return $values;
        }

        $this->fail("Element {$nodes} is not a form field or does not contain a form field");
    }

    /**
     * Get the values of a set of input fields.
     */
    protected function getInputValue(SymfonyCrawler $input): array|string
    {
        $inputType = $input->attr('type');
        if ($inputType === 'checkbox' || $inputType === 'radio') {
            $values = [];

            foreach ($input->filter(':checked') as $checkbox) {
                $values[] = $checkbox->getAttribute('value');
            }

            return $values;
        }

        return (new InputFormField($input->getNode(0)))->getValue();
    }

    /**
     * Strips out one pair of trailing square brackets from a field's
     * name.
     *
     * @param string $name the field name
     * @return string the name after stripping trailing square brackets
     */
    protected function getSubmissionFormFieldName(string $name): string
    {
        if (str_ends_with($name, '[]')) {
            return substr($name, 0, -2);
        }

        return $name;
    }

    /**
     * Replaces boolean values in $params with the corresponding field's
     * value for checkbox form fields.
     *
     * The function loops over all input checkbox fields, checking if a
     * corresponding key is set in $params.  If it is, and the value is
     * boolean or an array containing booleans, the value(s) are
     * replaced in the array with the real value of the checkbox, and
     * the array is returned.
     *
     * @param SymfonyCrawler $form the form to find checkbox elements
     * @param array $params the parameters to be submitted
     * @return array the $params array after replacing bool values
     */
    protected function setCheckboxBoolValues(Crawler $form, array $params): array
    {
        $checkboxes = $form->filter('input[type=checkbox]');
        $chFoundByName = [];
        foreach ($checkboxes as $checkbox) {
            $fieldName = $this->getSubmissionFormFieldName($checkbox->getAttribute('name'));
            $pos = $chFoundByName[$fieldName] ?? 0;
            $skip = !isset($params[$fieldName])
                || (!is_array($params[$fieldName]) && !is_bool($params[$fieldName]))
                || (is_array($params[$fieldName]) &&
                    ($pos >= count($params[$fieldName]) || !is_bool($params[$fieldName][$pos]))
                );

            if ($skip) {
                continue;
            }

            $values = $params[$fieldName];
            if ($values === true) {
                $params[$fieldName] = $checkbox->hasAttribute('value') ? $checkbox->getAttribute('value') : 'on';
                $chFoundByName[$fieldName] = $pos + 1;
            } elseif (is_array($values)) {
                if ($values[$pos] === true) {
                    $params[$fieldName][$pos] = $checkbox->hasAttribute('value') ? $checkbox->getAttribute('value') : 'on';
                    $chFoundByName[$fieldName] = $pos + 1;
                } else {
                    array_splice($params[$fieldName], $pos, 1);
                }
            } else {
                unset($params[$fieldName]);
            }
        }

        return $params;
    }

    /**
     * Submits the form currently selected in the passed SymfonyCrawler, after
     * setting any values passed in $params and setting the value of the
     * passed button name.
     *
     * @param SymfonyCrawler $frmCrawl the form to submit
     * @param array $params additional parameter values to set on the
     *        form
     * @param string|null $button the name of a submit button in the form
     */
    protected function proceedSubmitForm(Crawler $frmCrawl, array $params, ?string $button = null): void
    {
        $url = null;
        $form = $this->getFormFor($frmCrawl);
        $defaults = $this->getFormValuesFor($form);
        $merged = array_merge($defaults, $params);
        $requestParams = $this->setCheckboxBoolValues($frmCrawl, $merged);

        if (!empty($button)) {
            $btnCrawl = $frmCrawl->filterXPath(sprintf(
                '//*[not(@disabled) and @type="submit" and @name=%s]',
                SymfonyCrawler::xpathLiteral($button)
            ));
            if (count($btnCrawl) > 0) {
                $requestParams[$button] = $btnCrawl->attr('value');
                $formaction = $btnCrawl->attr('formaction');
                if ($formaction) {
                    $url = $formaction;
                }
            }
        }

        if ($url === null) {
            $url = $this->getFormUrl($frmCrawl);
        }

        if (strcasecmp($form->getMethod(), 'GET') === 0) {
            $url = Uri::mergeUrls($url, '?' . http_build_query($requestParams));
        }

        $url = preg_replace('#\#.*#', '', $url);

        $this->debugSection('Uri', $url);
        $this->debugSection('Method', $form->getMethod());
        $this->debugSection('Parameters', $requestParams);

        $requestParams= $this->getFormPhpValues($requestParams);

        $this->crawler = $this->clientRequest(
            $form->getMethod(),
            $url,
            $requestParams,
            $form->getPhpFiles()
        );
        $this->forms = [];
    }

    public function submitForm($selector, array $params, ?string $button = null): void
    {
        $form = $this->match($selector)->first();
        if (count($form) === 0) {
            throw new ElementNotFound($this->stringifySelector($selector), 'Form');
        }

        $this->proceedSubmitForm($form, $params, $button);
    }

    /**
     * Returns an absolute URL for the passed URI with the current URL
     * as the base path.
     *
     * @param string $uri the absolute or relative URI
     * @return string the absolute URL
     * @throws TestRuntimeException if either the current
     *         URL or the passed URI can't be parsed
     */
    protected function getAbsoluteUrlFor(string $uri): string
    {
        $currentUrl = $this->getRunningClient()->getHistory()->current()->getUri();
        if (empty($uri) || str_starts_with($uri, '#')) {
            return $currentUrl;
        }

        return Uri::mergeUrls($currentUrl, $uri);
    }

    /**
     * Returns the form action's absolute URL.
     *
     * @throws TestRuntimeException if either the current
     *         URL or the URI of the form's action can't be parsed
     */
    protected function getFormUrl(Crawler $form): string
    {
        $action = $form->form()->getUri();
        return $this->getAbsoluteUrlFor($action);
    }

    /**
     * Returns a crawler Form object for the form pointed to by the
     * passed SymfonyCrawler.
     *
     * The returned form is an independent Crawler created to take care
     * of the following issues currently experienced by Crawler's form
     * object:
     *  - input fields disabled at a higher level (e.g. by a surrounding
     *    fieldset) still return values
     *  - Codeception expects an empty value to match an unselected
     *    select box.
     *
     * The function clones the crawler's node and creates a new crawler
     * because it destroys or adds to the DOM for the form to achieve
     * the desired functionality.  Other functions simply querying the
     * DOM wouldn't expect them.
     *
     * @param SymfonyCrawler $form the form
     */
    private function getFormFromCrawler(Crawler $form): SymfonyForm
    {
        $fakeDom = new DOMDocument();
        $fakeDom->appendChild($fakeDom->importNode($form->getNode(0), true));

        //add fields having form attribute with id of this form
        $formId = $form->attr('id');
        if ($formId !== null) {
            $fakeForm = $fakeDom->firstChild;
            $topParent = $this->getAncestorsFor($form)->last();
            $fieldsByFormAttribute = $topParent->filter(
                sprintf('input[form=%s],select[form=%s],textarea[form=%s]', $formId, $formId, $formId)
            );
            foreach ($fieldsByFormAttribute as $field) {
                $fakeForm->appendChild($fakeDom->importNode($field, true));
            }
        }

        $node = $fakeDom->documentElement;
        $action = $this->getFormUrl($form);
        $cloned = new SymfonyCrawler($node, $action, $this->getBaseUrl());
        $shouldDisable = $cloned->filter(
            'input:disabled:not([disabled]),select option:disabled,select optgroup:disabled option:not([disabled]),textarea:disabled:not([disabled]),select:disabled:not([disabled])'
        );
        foreach ($shouldDisable as $field) {
            $field->parentNode->removeChild($field);
        }

        return $cloned->form();
    }

    /**
     * Returns the DomCrawler\Form object for the form pointed to by
     * $node or its closes form parent.
     */
    protected function getFormFor(Crawler $node): SymfonyForm
    {
        if (strcasecmp($node->first()->getNode(0)->tagName, 'form') === 0) {
            $form = $node->first();
        } else {
            $form = $this->getAncestorsFor($node)->filter('form')->first();
        }

        if (!$form) {
            $this->fail('The selected node is not a form and does not have a form ancestor.');
        }

        $identifier = $form->attr('id') ?: $form->attr('action');
        if (!isset($this->forms[$identifier])) {
            $this->forms[$identifier] = $this->getFormFromCrawler($form);
        }

        return $this->forms[$identifier];
    }

    /**
     * Returns the ancestors of the passed SymfonyCrawler.
     *
     * symfony/dom-crawler deprecated parents() in favor of ancestors()
     * This provides backward compatibility with < 5.3.0-BETA-1
     *
     * @param SymfonyCrawler $crawler the crawler
     * @return SymfonyCrawler the ancestors
     */
    private function getAncestorsFor(SymfonyCrawler $crawler): SymfonyCrawler
    {
        if (method_exists($crawler, 'ancestors')) {
            return $crawler->ancestors();
        }

        return $crawler->parents();
    }

    /**
     * Returns an array of name => value pairs for the passed form.
     *
     * For form fields containing a name ending in [], an array is
     * created out of all field values with the given name.
     *
     * @param SymfonyForm $form the form
     * @return array an array of name => value pairs
     */
    protected function getFormValuesFor(SymfonyForm $form): array
    {
        $formNodeCrawler = new Crawler($form->getFormNode());
        $values = [];
        $fields = $form->all();
        foreach ($fields as $field) {
            if ($field instanceof FileFormField || $field->isDisabled()) {
                continue;
            }

            if (!$field->hasValue()) {
                // if unchecked a checkbox and if there is hidden input with same name to submit unchecked value
                $hiddenInput = $formNodeCrawler->filter('input[type=hidden][name="'.$field->getName().'"]:not([disabled])');
                if (count($hiddenInput) === 0) {
                    continue;
                } else {
                    // there might be multiple hidden input with same name, but we will only grab last one's value
                    $fieldValue = $hiddenInput->last()->attr('value');
                }
            } else {
                $fieldValue = $field->getValue();
            }


            $fieldName = $this->getSubmissionFormFieldName($field->getName());
            if (str_ends_with($field->getName(), '[]')) {
                if (!isset($values[$fieldName])) {
                    $values[$fieldName] = [];
                }

                $values[$fieldName][] = $fieldValue;
            } else {
                $values[$fieldName] = $fieldValue;
            }
        }

        return $values;
    }

    public function fillField($field, $value): void
    {
        $value = (string) $value;
        $input = $this->getFieldByLabelOrCss($field);
        $form = $this->getFormFor($input);
        $name = $input->attr('name');

        $dynamicField = $input->getNode(0)->tagName === 'textarea'
            ? new TextareaFormField($input->getNode(0))
            : new InputFormField($input->getNode(0));
        $formField = $this->matchFormField($name, $form, $dynamicField);
        $formField->setValue($value);
        $input->getNode(0)->setAttribute('value', htmlspecialchars($value));
        $inputGetNode = $input->getNode(0);
        if ($inputGetNode->tagName === 'textarea') {
            $input->getNode(0)->nodeValue = htmlspecialchars($value);
        }
    }

    protected function getFieldsByLabelOrCss($field): SymfonyCrawler
    {
        $input = null;
        if (is_array($field)) {
            $input = $this->strictMatch($field);
            if (count($input) === 0) {
                throw new ElementNotFound($field);
            }

            return $input;
        }

        // by label
        $label = $this->strictMatch(['xpath' => sprintf('.//label[descendant-or-self::node()[text()[normalize-space()=%s]]]', SymfonyCrawler::xpathLiteral($field))]);
        if (count($label) > 0) {
            $label = $label->first();
            if ($label->attr('for')) {
                $input = $this->strictMatch(['id' => $label->attr('for')]);
            } else {
                $input = $this->strictMatch(['xpath' => sprintf('.//label[descendant-or-self::node()[text()[normalize-space()=%s]]]//input', SymfonyCrawler::xpathLiteral($field))]);
            }
        }

        // by name
        if (!isset($input)) {
            $input = $this->strictMatch(['name' => $field]);
        }

        // by CSS and XPath
        if (count($input) === 0) {
            $input = $this->match($field);
        }

        if (count($input) === 0) {
            throw new ElementNotFound($field, 'Form field by Label or CSS');
        }

        return $input;
    }

    protected function getFieldByLabelOrCss($field): SymfonyCrawler
    {
        $input = $this->getFieldsByLabelOrCss($field);
        return $input->first();
    }

    public function selectOption($select, $option): void
    {
        $field = $this->getFieldByLabelOrCss($select);
        $form = $this->getFormFor($field);
        $fieldName = $this->getSubmissionFormFieldName($field->attr('name'));

        if (is_array($option)) {
            if (!isset($option[0])) { // strict option locator
                $form[$fieldName]->select($this->matchOption($field, $option));
                codecept_debug($option);
                return;
            }

            $options = [];
            foreach ($option as $opt) {
                $options[] = $this->matchOption($field, $opt);
            }

            $form[$fieldName]->select($options);
            return;
        }

        $dynamicField = new ChoiceFormField($field->getNode(0));
        $formField = $this->matchFormField($fieldName, $form, $dynamicField);
        $selValue = $this->matchOption($field, $option);

        if (is_array($formField)) {
            foreach ($formField as $field) {
                $values = $field->availableOptionValues();
                foreach ($values as $val) {
                    if ($val === $option) {
                        $field->select($selValue);
                        return;
                    }
                }
            }

            return;
        }

        $formField->select((string) $this->matchOption($field, $option));
    }

    /**
     * @return mixed
     */
    protected function matchOption(Crawler $field, string|array $option)
    {
        if (isset($option['value'])) {
            return $option['value'];
        }

        if (isset($option['text'])) {
            $option = $option['text'];
        }

        $options = $field->filterXPath(sprintf('//option[text()=normalize-space("%s")]|//input[@type="radio" and @value=normalize-space("%s")]', $option, $option));
        if ($options->count() !== 0) {
            $firstMatchingDomNode = $options->getNode(0);
            if ($firstMatchingDomNode->tagName === 'option') {
                $firstMatchingDomNode->setAttribute('selected', 'selected');
            } else {
                $firstMatchingDomNode->setAttribute('checked', 'checked');
            }

            $valueAttribute = $options->first()->attr('value');
            //attr() returns null when option has no value attribute
            if ($valueAttribute !== null) {
                return $valueAttribute;
            }

            return $options->first()->text();
        }

        return $option;
    }

    public function checkOption($option): void
    {
        $this->proceedCheckOption($option)->tick();
    }

    public function uncheckOption($option): void
    {
        $this->proceedCheckOption($option)->untick();
    }

    /**
     * @param string|string[] $option
     */
    protected function proceedCheckOption(string|array $option): ChoiceFormField
    {
        $form = $this->getFormFor($field = $this->getFieldByLabelOrCss($option));
        $name = $field->attr('name');

        if ($field->getNode(0) === null) {
            throw new TestRuntimeException("Form field {$name} is not located");
        }

        // If the name is an array than we compare objects to find right checkbox
        $formField = $this->matchFormField($name, $form, new ChoiceFormField($field->getNode(0)));
        $field->getNode(0)->setAttribute('checked', 'checked');
        if (!$formField instanceof ChoiceFormField) {
            throw new TestRuntimeException("Form field {$name} is not a checkable");
        }

        return $formField;
    }

    public function attachFile($field, string $filename): void
    {
        $form = $this->getFormFor($field = $this->getFieldByLabelOrCss($field));
        $filePath = codecept_data_dir() . $filename;
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("File does not exist: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new InvalidArgumentException("File is not readable: {$filePath}");
        }

        $name = $field->attr('name');
        $formField = $this->matchFormField($name, $form, new FileFormField($field->getNode(0)));
        if (is_array($formField)) {
            $this->fail("Field {$name} is ignored on upload, field {$name} is treated as array.");
        }

        $formField->upload($filePath);
    }

    /**
     * Sends an ajax GET request with the passed parameters.
     * See `sendAjaxPostRequest()`
     */
    public function sendAjaxGetRequest(string $uri, array $params = []): void
    {
        $this->sendAjaxRequest('GET', $uri, $params);
    }

    /**
     * Sends an ajax POST request with the passed parameters.
     * The appropriate HTTP header is added automatically:
     * `X-Requested-With: XMLHttpRequest`
     * Example:
     * ``` php
     * <?php
     * $I->sendAjaxPostRequest('/add-task', ['task' => 'lorem ipsum']);
     * ```
     * Some frameworks (e.g. Symfony) create field names in the form of an "array":
     * `<input type="text" name="form[task]">`
     * In this case you need to pass the fields like this:
     * ``` php
     * <?php
     * $I->sendAjaxPostRequest('/add-task', ['form' => [
     *     'task' => 'lorem ipsum',
     *     'category' => 'miscellaneous',
     * ]]);
     * ```
     */
    public function sendAjaxPostRequest(string $uri, array $params = []): void
    {
        $this->sendAjaxRequest('POST', $uri, $params);
    }

    /**
     * Sends an ajax request, using the passed HTTP method.
     * See `sendAjaxPostRequest()`
     * Example:
     * ``` php
     * <?php
     * $I->sendAjaxRequest('PUT', '/posts/7', ['title' => 'new title']);
     * ```
     */
    public function sendAjaxRequest(string $method, string $uri, array $params = []): void
    {
        $this->clientRequest($method, $uri, $params, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], null, false);
    }

    /**
     * @param mixed $url
     */
    protected function debugResponse($url): void
    {
        $this->debugSection('Page', $url);
        $this->debugSection('Response', $this->getResponseStatusCode());
        $this->debugSection('Request Cookies', $this->getRunningClient()->getInternalRequest()->getCookies());
        $this->debugSection('Response Headers', $this->getRunningClient()->getInternalResponse()->getHeaders());
    }

    public function makeHtmlSnapshot(?string $name = null): void
    {
        if (empty($name)) {
            $name = uniqid(date("Y-m-d_H-i-s_"), true);
        }

        $debugDir = codecept_output_dir() . 'debug';
        if (!is_dir($debugDir)) {
            mkdir($debugDir);
        }

        $fileName = $debugDir . DIRECTORY_SEPARATOR . $name . '.html';

        $this->_savePageSource($fileName);
        $this->debugSection('Snapshot Saved', "file://{$fileName}");
    }

    public function _getResponseStatusCode()
    {
        return $this->getResponseStatusCode();
    }

    protected function getResponseStatusCode()
    {
        // depending on Symfony version
        $response = $this->getRunningClient()->getInternalResponse();
        if (method_exists($response, 'getStatusCode')) {
            return $response->getStatusCode();
        }

        if (method_exists($response, 'getStatus')) {
            return $response->getStatus();
        }

        return "N/A";
    }

    /**
     * @param string|string[] $selector
     */
    protected function match(string|array $selector): SymfonyCrawler
    {
        if (is_array($selector)) {
            return $this->strictMatch($selector);
        }

        if (Locator::isCSS($selector)) {
            return $this->getCrawler()->filter($selector);
        }

        if (Locator::isXPath($selector)) {
            return $this->getCrawler()->filterXPath($selector);
        }

        throw new MalformedLocatorException($selector, 'XPath or CSS');
    }

    /**
     * @param string[] $by
     * @throws TestRuntimeException
     */
    protected function strictMatch(array $by): SymfonyCrawler
    {
        $type = key($by);
        $locator = $by[$type];
        return match ($type) {
            'id' => $this->filterByCSS(sprintf('#%s', $locator)),
            'name' => $this->filterByXPath(sprintf('.//*[@name=%s]', SymfonyCrawler::xpathLiteral($locator))),
            'css' => $this->filterByCSS($locator),
            'xpath' => $this->filterByXPath($locator),
            'link' => $this->filterByXPath(sprintf('.//a[.=%s or contains(./@title, %s)]', SymfonyCrawler::xpathLiteral($locator), SymfonyCrawler::xpathLiteral($locator))),
            'class' => $this->filterByCSS(".{$locator}"),
            default => throw new TestRuntimeException(
                "Locator type '{$by}' is not defined. Use either: xpath, css, id, link, class, name"
            ),
        };
    }

    protected function filterByAttributes(Crawler $nodes, array $attributes)
    {
        foreach ($attributes as $attr => $val) {
            $nodes = $nodes->reduce(
                static fn(Crawler $node): bool => $node->attr($attr) === $val
            );
        }

        return $nodes;
    }

    public function grabTextFrom($cssOrXPathOrRegex): mixed
    {
        if (is_string($cssOrXPathOrRegex) && @preg_match($cssOrXPathOrRegex, $this->client->getInternalResponse()->getContent(), $matches)) {
            return $matches[1];
        }

        $nodes = $this->match($cssOrXPathOrRegex);
        if ($nodes->count() !== 0) {
            return $nodes->first()->text();
        }

        throw new ElementNotFound($cssOrXPathOrRegex, 'Element that matches CSS or XPath or Regex');
    }

    public function grabAttributeFrom($cssOrXpath, string $attribute): mixed
    {
        $nodes = $this->match($cssOrXpath);
        if ($nodes->count() === 0) {
            throw new ElementNotFound($cssOrXpath, 'Element that matches CSS or XPath');
        }

        return $nodes->first()->attr($attribute);
    }

    public function grabMultiple($cssOrXpath, ?string $attribute = null): array
    {
        $result = [];
        $nodes = $this->match($cssOrXpath);

        foreach ($nodes as $node) {
            $result[] = $attribute !== null ? $node->getAttribute($attribute) : $node->textContent;
        }

        return $result;
    }

    public function grabValueFrom($field): mixed
    {
        $nodes = $this->match($field);
        if ($nodes->count() === 0) {
            throw new ElementNotFound($field, 'Field');
        }

        if ($nodes->filter('textarea')->count() !== 0) {
            return (new TextareaFormField($nodes->filter('textarea')->getNode(0)))->getValue();
        }

        $input = $nodes->filter('input');
        if ($input->count() !== 0) {
            return $this->getInputValue($input);
        }

        if ($nodes->filter('select')->count() !== 0) {
            $field = new ChoiceFormField($nodes->filter('select')->getNode(0));
            $options = $nodes->filter('option[selected]');
            $values = [];

            foreach ($options as $option) {
                $values[] = $option->getAttribute('value');
            }

            if (!$field->isMultiple()) {
                return reset($values);
            }

            return $values;
        }

        $this->fail("Element {$nodes} is not a form field or does not contain a form field");
    }

    public function setCookie($name, $val, $params = [])
    {
        $cookies = $this->client->getCookieJar();
        $params = array_merge($this->defaultCookieParameters, $params);

        $expires      = $params['expiry'] ?? null; // WebDriver compatibility
        $expires      = isset($params['expires']) && !$expires ? $params['expires'] : null;

        $path         = $params['path'] ?? null;
        $domain       = $params['domain'] ?? '';
        $secure       = $params['secure'] ?? false;
        $httpOnly     = $params['httpOnly'] ?? true;
        $encodedValue = $params['encodedValue'] ?? false;



        $cookies->set(new Cookie($name, $val, $expires, $path, $domain, $secure, $httpOnly, $encodedValue));
        $this->debugCookieJar();
    }

    public function grabCookie(string $cookie, array $params = []): mixed
    {
        $params = array_merge($this->defaultCookieParameters, $params);
        $this->debugCookieJar();
        $cookies = $this->getRunningClient()->getCookieJar()->get($cookie, $params['path'], $params['domain']);
        if ($cookies === null) {
            return null;
        }

        return $cookies->getValue();
    }

    /**
     * Grabs current page source code.
     *
     * @throws \Codeception\Exception\ModuleException if no page was opened.
     * @return string Current page source code.
     */
    public function grabPageSource(): string
    {
        return $this->_getResponseContent();
    }

    public function seeCookie($cookie, $params = [])
    {
        $params = array_merge($this->defaultCookieParameters, $params);
        $this->debugCookieJar();
        $this->assertNotNull($this->client->getCookieJar()->get($cookie, $params['path'], $params['domain']));
    }

    public function dontSeeCookie($cookie, $params = [])
    {
        $params = array_merge($this->defaultCookieParameters, $params);
        $this->debugCookieJar();
        $this->assertNull($this->client->getCookieJar()->get($cookie, $params['path'], $params['domain']));
    }

    public function resetCookie($cookie, $params = [])
    {
        $params = array_merge($this->defaultCookieParameters, $params);
        $this->client->getCookieJar()->expire($cookie, $params['path'], $params['domain']);
        $this->debugCookieJar();
    }

    private function stringifySelector($selector): string
    {
        if (is_array($selector)) {
            return trim(json_encode($selector, JSON_THROW_ON_ERROR), '{}');
        }

        return $selector;
    }

    public function seeElement($selector, array $attributes = []): void
    {
        $nodes = $this->match($selector);
        $selector = $this->stringifySelector($selector);
        if (!empty($attributes)) {
            $nodes = $this->filterByAttributes($nodes, $attributes);
            $selector .= "' with attribute(s) '" . trim(json_encode($attributes, JSON_THROW_ON_ERROR), '{}');
        }

        $this->assertDomContains($nodes, $selector);
    }

    public function dontSeeElement($selector, array $attributes = []): void
    {
        $nodes = $this->match($selector);
        $selector = $this->stringifySelector($selector);
        if (!empty($attributes)) {
            $nodes = $this->filterByAttributes($nodes, $attributes);
            $selector .= "' with attribute(s) '" . trim(json_encode($attributes, JSON_THROW_ON_ERROR), '{}');
        }

        $this->assertDomNotContains($nodes, $selector);
    }

    public function seeNumberOfElements($selector, $expected): void
    {
        $counted = count($this->match($selector));
        if (is_array($expected)) {
            [$floor, $ceil] = $expected;
            $this->assertTrue(
                $floor <= $counted && $ceil >= $counted,
                'Number of elements counted differs from expected range'
            );
        } else {
            $this->assertSame(
                $expected,
                $counted,
                'Number of elements counted differs from expected number'
            );
        }
    }

    public function seeOptionIsSelected($selector, $optionText)
    {
        $selected = $this->matchSelectedOption($selector);
        $this->assertDomContains($selected, 'selected option');
        //If element is radio then we need to check value
        $value = $selected->getNode(0)->tagName === 'option'
            ? $selected->text()
            : $selected->getNode(0)->getAttribute('value');
        $this->assertSame($optionText, $value);
    }

    public function dontSeeOptionIsSelected($selector, $optionText)
    {
        $selected = $this->matchSelectedOption($selector);
        if ($selected->count() === 0) {
            $this->assertSame(0, $selected->count());
            return;
        }

        //If element is radio then we need to check value
        $value = $selected->getNode(0)->tagName === 'option'
            ? $selected->text()
            : $selected->getNode(0)->getAttribute('value');
        $this->assertNotSame($optionText, $value);
    }

    protected function matchSelectedOption($select): SymfonyCrawler
    {
        $nodes = $this->getFieldsByLabelOrCss($select);
        $selectedOptions = $nodes->filter('option[selected],input:checked');
        if ($selectedOptions->count() === 0) {
            $selectedOptions = $nodes->filter('option,input')->first();
        }

        return $selectedOptions;
    }

    /**
     * Asserts that current page has 404 response status code.
     */
    public function seePageNotFound(): void
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
     */
    public function seeResponseCodeIs(int $code): void
    {
        $failureMessage = sprintf(
            'Expected HTTP Status Code: %s. Actual Status Code: %s',
            HttpCode::getDescription($code),
            HttpCode::getDescription($this->getResponseStatusCode())
        );
        $this->assertSame($code, $this->getResponseStatusCode(), $failureMessage);
    }

    /**
     * Checks that response code is between a certain range. Between actually means [from <= CODE <= to]
     */
    public function seeResponseCodeIsBetween(int $from, int $to): void
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
     */
    public function dontSeeResponseCodeIs(int $code): void
    {
        $failureMessage = sprintf(
            'Expected HTTP status code other than %s',
            HttpCode::getDescription($code)
        );
        $this->assertNotSame($code, $this->getResponseStatusCode(), $failureMessage);
    }

    /**
     * Checks that the response code 2xx
     */
    public function seeResponseCodeIsSuccessful(): void
    {
        $this->seeResponseCodeIsBetween(200, 299);
    }

    /**
     * Checks that the response code 3xx
     */
    public function seeResponseCodeIsRedirection(): void
    {
        $this->seeResponseCodeIsBetween(300, 399);
    }

    /**
     * Checks that the response code is 4xx
     */
    public function seeResponseCodeIsClientError(): void
    {
        $this->seeResponseCodeIsBetween(400, 499);
    }

    /**
     * Checks that the response code is 5xx
     */
    public function seeResponseCodeIsServerError(): void
    {
        $this->seeResponseCodeIsBetween(500, 599);
    }

    public function seeInTitle($title)
    {
        $nodes = $this->getCrawler()->filter('title');
        if ($nodes->count() === 0) {
            throw new ElementNotFound("<title>", "Tag");
        }

        $this->assertStringContainsString($title, $nodes->first()->text(), "page title contains {$title}");
    }

    public function dontSeeInTitle($title)
    {
        $nodes = $this->getCrawler()->filter('title');
        if ($nodes->count() === 0) {
            $this->assertTrue(true);
            return;
        }

        $this->assertStringNotContainsString($title, $nodes->first()->text(), "page title contains {$title}");
    }

    protected function assertDomContains($nodes, string $message, string $text = ''): void
    {
        $constraint = new CrawlerConstraint($text, $this->_getCurrentUri());
        $this->assertThat($nodes, $constraint, $message);
    }

    protected function assertDomNotContains($nodes, string $message, string $text = ''): void
    {
        $constraint = new CrawlerNotConstraint($text, $this->_getCurrentUri());
        $this->assertThat($nodes, $constraint, $message);
    }

    protected function assertPageContains(string $needle, string $message = ''): void
    {
        $constraint = new PageConstraint($needle, $this->_getCurrentUri());
        $this->assertThat(
            $this->getNormalizedResponseContent(),
            $constraint,
            $message
        );
    }

    protected function assertPageNotContains(string $needle, string $message = ''): void
    {
        $constraint = new PageConstraint($needle, $this->_getCurrentUri());
        $this->assertThatItsNot(
            $this->getNormalizedResponseContent(),
            $constraint,
            $message
        );
    }

    protected function assertPageSourceContains(string $needle, string $message = ''): void
    {
        $constraint = new PageConstraint($needle, $this->_getCurrentUri());
        $this->assertThat(
            $this->_getResponseContent(),
            $constraint,
            $message
        );
    }

    protected function assertPageSourceNotContains(string $needle, string $message = ''): void
    {
        $constraint = new PageConstraint($needle, $this->_getCurrentUri());
        $this->assertThatItsNot(
            $this->_getResponseContent(),
            $constraint,
            $message
        );
    }

    /**
     * @param array|object $form
     */
    protected function matchFormField(string $name, $form, FormField $dynamicField): FormField|array
    {
        if (!str_ends_with($name, '[]')) {
            return $form[$name];
        }

        $name = substr($name, 0, -2);
        /** @var FormField $item */
        foreach ($form[$name] as $item) {
            if ($item == $dynamicField) {
                return $item;
            }
        }

        throw new TestRuntimeException("None of form fields by {$name}[] were not matched");
    }

    protected function filterByCSS(string $locator): SymfonyCrawler
    {
        if (!Locator::isCSS($locator)) {
            throw new MalformedLocatorException($locator, 'css');
        }

        return $this->getCrawler()->filter($locator);
    }

    protected function filterByXPath(string $locator): SymfonyCrawler
    {
        if (!Locator::isXPath($locator)) {
            throw new MalformedLocatorException($locator, 'xpath');
        }

        return $this->getCrawler()->filterXPath($locator);
    }

    protected function getFormPhpValues(array $requestParams): array
    {
        foreach ($requestParams as $name => $value) {
            $qs = http_build_query([$name => $value]);
            if (!empty($qs)) {
                // If the field's name is of the form of "array[key]",
                // we'll remove it from the request parameters
                // and set the "array" key instead which will contain the actual array.
                if (strpos($name, '[') && strpos($name, ']') > strpos($name, '[')) {
                    unset($requestParams[$name]);
                }

                parse_str($qs, $expandedValue);
                $varName = substr($name, 0, strlen((string)key($expandedValue)));
                $requestParams = array_replace_recursive($requestParams, [$varName => current($expandedValue)]);
            }
        }

        return $requestParams;
    }

    protected function redirectIfNecessary(SymfonyCrawler $result, int $maxRedirects, int $redirectCount): SymfonyCrawler
    {
        $locationHeader = $this->client->getInternalResponse()->getHeader('Location');
        $statusCode = $this->getResponseStatusCode();
        if ($locationHeader && $statusCode >= 300 && $statusCode < 400) {
            if ($redirectCount === $maxRedirects) {
                throw new LogicException(sprintf(
                    'The maximum number (%d) of redirections was reached.',
                    $maxRedirects
                ));
            }

            $this->debugSection('Redirecting to', $locationHeader);

            $result = $this->client->followRedirect();
            $this->debugResponse($locationHeader);
            return $this->redirectIfNecessary($result, $maxRedirects, $redirectCount + 1);
        }

        $this->client->followRedirects(true);
        return $result;
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
     */
    public function switchToIframe(string $name): void
    {
        $iframe = $this->match("iframe[name={$name}]")->first();
        if (count($iframe) === 0) {
            $iframe = $this->match("frame[name={$name}]")->first();
        }

        if (count($iframe) === 0) {
            throw new ElementNotFound("name={$name}", 'Iframe');
        }

        $uri = $iframe->getNode(0)->getAttribute('src');
        $this->amOnPage($uri);
    }

    /**
     * Moves back in history.
     *
     * @param int $numberOfSteps (default value 1)
     */
    public function moveBack(int $numberOfSteps = 1): void
    {
        $request = null;
        if (!is_int($numberOfSteps) || $numberOfSteps < 1) {
            throw new InvalidArgumentException('numberOfSteps must be positive integer');
        }

        try {
            $history = $this->getRunningClient()->getHistory();
            for ($i = $numberOfSteps; $i > 0; --$i) {
                $request = $history->back();
            }
        } catch (LogicException $exception) {
            throw new InvalidArgumentException(
                sprintf(
                'numberOfSteps is set to %d, but there are only %d previous steps in the history',
                $numberOfSteps,
                $numberOfSteps - $i
            ), $exception->getCode(), $exception);
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

    protected function debugCookieJar(): void
    {
        $cookies = $this->client->getCookieJar()->all();
        $cookieStrings = array_map('strval', $cookies);
        $this->debugSection('Cookie Jar', $cookieStrings);
    }

    protected function setCookiesFromOptions()
    {
        if (isset($this->config['cookies']) && is_array($this->config['cookies']) && !empty($this->config['cookies'])) {
            $domain = parse_url($this->config['url'], PHP_URL_HOST);
            $cookieJar = $this->client->getCookieJar();
            foreach ($this->config['cookies'] as &$cookie) {
                if (!is_array($cookie) || !array_key_exists('Name', $cookie) || !array_key_exists('Value', $cookie)) {
                    throw new InvalidArgumentException('Cookies must have at least Name and Value attributes');
                }

                if (!isset($cookie['Domain'])) {
                    $cookie['Domain'] = $domain;
                }

                if (!isset($cookie['Expires'])) {
                    $cookie['Expires'] = null;
                }

                if (!isset($cookie['Path'])) {
                    $cookie['Path'] = '/';
                }

                if (!isset($cookie['Secure'])) {
                    $cookie['Secure'] = false;
                }

                if (!isset($cookie['HttpOnly'])) {
                    $cookie['HttpOnly'] = false;
                }

                $cookieJar->set(new Cookie(
                    $cookie['Name'],
                    $cookie['Value'],
                    $cookie['Expires'],
                    $cookie['Path'],
                    $cookie['Domain'],
                    $cookie['Secure'],
                    $cookie['HttpOnly']
                ));
            }
        }
    }

    protected function getNormalizedResponseContent(): string
    {
        $content = $this->_getResponseContent();
        // Since strip_tags has problems with JS code that contains
        // an <= operator the script tags have to be removed manually first.
        $content = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $content);

        $content = strip_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES);
        $content = str_replace("\n", ' ', $content);

        return preg_replace('#\s{2,}#', ' ', $content);
    }

    /**
     * Sets SERVER parameters valid for all next requests.
     * this will remove old ones.
     *
     * ```php
     * $I->setServerParameters([]);
     * ```
     */
    public function setServerParameters(array $params): void
    {
        $this->client->setServerParameters($params);
    }

    /**
     * Sets SERVER parameter valid for all next requests.
     *
     * ```php
     * $I->haveServerParameter('name', 'value');
     * ```
     */
    public function haveServerParameter(string $name, string $value): void
    {
        $this->client->setServerParameter($name, $value);
    }

    /**
     * Prevents automatic redirects to be followed by the client.
     *
     * ```php
     * <?php
     * $I->stopFollowingRedirects();
     * ```
     */
    public function stopFollowingRedirects(): void
    {
        $this->client->followRedirects(false);
    }

    /**
     * Enables automatic redirects to be followed by the client.
     *
     * ```php
     * <?php
     * $I->startFollowingRedirects();
     * ```
     */
    public function startFollowingRedirects(): void
    {
        $this->client->followRedirects(true);
    }

    /**
     * Follow pending redirect if there is one.
     *
     * ```php
     * <?php
     * $I->followRedirect();
     * ```
     */
    public function followRedirect(): void
    {
        $this->client->followRedirect();
    }

    /**
     * Sets the maximum number of redirects that the Client can follow.
     *
     * ```php
     * <?php
     * $I->setMaxRedirects(2);
     * ```
     */
    public function setMaxRedirects(int $maxRedirects): void
    {
        $this->client->setMaxRedirects($maxRedirects);
    }
}
