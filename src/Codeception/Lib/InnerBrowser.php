<?php

namespace Codeception\Lib;

use Codeception\Exception\ElementNotFound;
use Codeception\Exception\ExternalUrlException;
use Codeception\Exception\MalformedLocatorException;
use Codeception\Exception\ModuleException;
use Codeception\Exception\TestRuntimeException;
use Codeception\Lib\Interfaces\ConflictsWithModule;
use Codeception\Lib\Interfaces\ElementLocator;
use Codeception\Lib\Interfaces\PageSourceSaver;
use Codeception\Lib\Interfaces\Web;
use Codeception\Lib\Model\BrowserInterface;
use Codeception\Lib\Model\ConflictsWithModuleTrait;
use Codeception\Lib\Model\ElementLocatorTrait;
use Codeception\Lib\Model\PageSourceSaverTrait;
use Codeception\Lib\Model\WebTrait;
use Codeception\Module;
use Codeception\PHPUnit\Constraint\Crawler as CrawlerConstraint;
use Codeception\PHPUnit\Constraint\CrawlerNot as CrawlerNotConstraint;
use Codeception\PHPUnit\Constraint\Page as PageConstraint;
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
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Field\FormField;
use Symfony\Component\DomCrawler\Field\InputFormField;
use Symfony\Component\DomCrawler\Field\TextareaFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\DomCrawler\Link;

//Alias for Symfony < 4.3
if (!class_exists(AbstractBrowser::class) && class_exists('Symfony\Component\BrowserKit\Client')) {
    class_alias('Symfony\Component\BrowserKit\Client', AbstractBrowser::class);
}

class InnerBrowser extends Module implements BrowserInterface, ConflictsWithModule, ElementLocator, PageSourceSaver, Web
{
    use
        ConflictsWithModuleTrait,
        ElementLocatorTrait,
        PageSourceSaverTrait,
        WebTrait
    ;

    private $baseUrl;

    /**
     * @var Crawler
     */
    protected $crawler;

    protected $defaultCookieParameters = ['expires' => null, 'path' => '/', 'domain' => '', 'secure' => false];

    /**
     * @var array|Form[]
     */
    protected $forms = [];

    protected $internalDomains;

    /**
     * @api
     * @var AbstractBrowser
     */
    public $client;

    public $headers = [];

    /**
     * Clicks the link or submits the form when the button is clicked
     * @param DOMNode $node
     * @return boolean clicked something
     * @throws ModuleException|ExternalUrlException
     */
    private function clickButton(DOMNode $node)
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
            if ($buttonName !== '') {
                $formParams = [$buttonName => $node->getAttribute('value')];
            } else {
                $formParams = [];
            }
            $this->proceedSubmitForm(
                new Crawler($form, $this->getAbsoluteUrlFor($this->_getCurrentUri()), $this->getBaseUrl()),
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

    private function getBaseUrl()
    {
        return $this->baseUrl;
    }

    /**
     * @return Crawler
     * @throws ModuleException
     */
    private function getCrawler()
    {
        if (!$this->crawler) {
            throw new ModuleException($this, 'Crawler is null. Perhaps you forgot to call "amOnPage"?');
        }
        return $this->crawler;
    }

    /**
     * Returns a crawler Form object for the form pointed to by the
     * passed Crawler.
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
     * @param Crawler $form the form
     * @return Form
     * @throws ModuleException
     */
    private function getFormFromCrawler(Crawler $form)
    {
        $fakeDom = new DOMDocument();
        $fakeDom->appendChild($fakeDom->importNode($form->getNode(0), true));
        $node = $fakeDom->documentElement;
        $action = (string)$this->getFormUrl($form);
        $cloned = new Crawler($node, $action, $this->getBaseUrl());
        $shouldDisable = $cloned->filter(
            'input:disabled:not([disabled]),select option:disabled,select optgroup:disabled option:not([disabled]),textarea:disabled:not([disabled]),select:disabled:not([disabled])'
        );
        foreach ($shouldDisable as $field) {
            $field->parentNode->removeChild($field);
        }
        return $cloned->form();
    }

    /**
     * @return AbstractBrowser
     * @throws ModuleException
     */
    private function getRunningClient()
    {
        try {
            if ($this->client->getInternalRequest() === null) {
                throw new ModuleException(
                    $this,
                    "Page not loaded. Use `\$I->amOnPage` (or hidden API methods `_request` and `_loadPage`) to open it"
                );
            }
        } catch (BadMethodCallException $e) {
            //Symfony 5
            throw new ModuleException(
                $this,
                "Page not loaded. Use `\$I->amOnPage` (or hidden API methods `_request` and `_loadPage`) to open it"
            );
        }
        return $this->client;
    }

    private function openHrefFromDomNode(DOMNode $node)
    {
        $link = new Link($node, $this->getBaseUrl());
        $this->amOnPage(preg_replace('/#.*/', '', $link->getUri()));
    }

    /**
     * @return string
     * @throws ModuleException
     */
    private function retrieveBaseUrl()
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

    private function stringifySelector($selector)
    {
        if (is_array($selector)) {
            return trim(json_encode($selector), '{}');
        }
        return $selector;
    }

    /**
     * @param $method
     * @param $uri
     * @param array $parameters
     * @param array $files
     * @param array $server
     * @param null $content
     * @param bool $changeHistory
     * @return mixed|Crawler|null
     * @throws ExternalUrlException|ModuleException
     */
    protected function clientRequest(
        $method,
        $uri,
        array $parameters = [],
        array $files = [],
        array $server = [],
        $content = null,
        $changeHistory = true
    ) {
        $this->debugSection('Request Headers', $this->headers);

        foreach ($this->headers as $header => $val) { // moved from REST module

            if ($val === null || $val === '') {
                continue;
            }

            $header = str_replace('-', '_', strtoupper($header));
            $server["HTTP_$header"] = $val;

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
                    throw new ExternalUrlException(get_class($this) . " can't open external URL: " . $uri);
                }
            }

            if ($method !== 'GET' && $content === null && !empty($parameters)) {
                $content = http_build_query($parameters);
            }
        }

        if (method_exists($this->client, 'isFollowingRedirects')) {
            $isFollowingRedirects = $this->client->isFollowingRedirects();
            $maxRedirects = $this->client->getMaxRedirects();
        } else {
            //Symfony 2.7 support
            $isFollowingRedirects = ReflectionHelper::readPrivateProperty(
                $this->client, 'followRedirects', 'Symfony\Component\BrowserKit\Client'
            );
            $maxRedirects = ReflectionHelper::readPrivateProperty(
                $this->client, 'maxRedirects', 'Symfony\Component\BrowserKit\Client'
            );
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

    protected function assertDomContains($nodes, $message, $text = '')
    {
        $constraint = new CrawlerConstraint($text, $this->_getCurrentUri());
        $this->assertThat($nodes, $constraint, $message);
    }

    protected function assertDomNotContains($nodes, $message, $text = '')
    {
        $constraint = new CrawlerNotConstraint($text, $this->_getCurrentUri());
        $this->assertThat($nodes, $constraint, $message);
    }

    /**
     * @param $needle
     * @param string $message
     */
    protected function assertPageContains($needle, $message = '')
    {
        $constraint = new PageConstraint($needle, $this->_getCurrentUri());
        $this->assertThat(
            $this->getNormalizedResponseContent(),
            $constraint,
            $message
        );
    }

    /**
     * @param $needle
     * @param string $message
     */
    protected function assertPageNotContains($needle, $message = '')
    {
        $constraint = new PageConstraint($needle, $this->_getCurrentUri());
        $this->assertThatItsNot(
            $this->getNormalizedResponseContent(),
            $constraint,
            $message
        );
    }

    protected function assertPageSourceContains($needle, $message = '')
    {
        $constraint = new PageConstraint($needle, $this->_getCurrentUri());
        $this->assertThat(
            $this->_getResponseContent(),
            $constraint,
            $message
        );
    }

    protected function assertPageSourceNotContains($needle, $message = '')
    {
        $constraint = new PageConstraint($needle, $this->_getCurrentUri());
        $this->assertThatItsNot(
            $this->_getResponseContent(),
            $constraint,
            $message
        );
    }

    /**
     * @param $link
     * @return bool
     * @throws ModuleException|ExternalUrlException
     */
    protected function clickByLocator($link)
    {
        $nodes = $this->match($link);
        if (!$nodes->count()) {
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
    }

    protected function debugCookieJar()
    {
        $cookies = $this->client->getCookieJar()->all();
        $cookieStrings = array_map('strval', $cookies);
        $this->debugSection('Cookie Jar', $cookieStrings);
    }

    /**
     * @param $url
     * @throws ModuleException
     */
    protected function debugResponse($url)
    {
        $this->debugSection('Page', $url);
        $this->debugSection('Response', $this->getResponseStatusCode());
        $this->debugSection('Request Cookies', $this->getRunningClient()->getInternalRequest()->getCookies());
        $this->debugSection('Response Headers', $this->getRunningClient()->getInternalResponse()->getHeaders());
    }

    protected function filterByAttributes(Crawler $nodes, array $attributes)
    {
        foreach ($attributes as $attr => $val) {
            $nodes = $nodes->reduce(
                static function (Crawler $node) use ($attr, $val) {
                    return $node->attr($attr) === $val;
                }
            );
        }
        return $nodes;
    }

    /**
     * @param $locator
     * @return Crawler
     * @throws ModuleException
     */
    protected function filterByCSS($locator)
    {
        if (!Locator::isCSS($locator)) {
            throw new MalformedLocatorException($locator, 'css');
        }
        return $this->getCrawler()->filter($locator);
    }

    /**
     * @param $locator
     * @return Crawler
     * @throws ModuleException
     */
    protected function filterByXPath($locator)
    {
        if (!Locator::isXPath($locator)) {
            throw new MalformedLocatorException($locator, 'xpath');
        }
        return $this->getCrawler()->filterXPath($locator);
    }

    /**
     * Returns an absolute URL for the passed URI with the current URL
     * as the base path.
     *
     * @param string $uri the absolute or relative URI
     * @return string the absolute URL
     * @throws TestRuntimeException|ModuleException if either the current
     *         URL or the passed URI can't be parsed
     */
    protected function getAbsoluteUrlFor($uri)
    {
        $currentUrl = $this->getRunningClient()->getHistory()->current()->getUri();
        if (empty($uri) || strpos($uri, '#') === 0) {
            return $currentUrl;
        }
        return Uri::mergeUrls($currentUrl, $uri);
    }

    /**
     * @param $field
     * @return object|Crawler
     * @throws ModuleException
     */
    protected function getFieldByLabelOrCss($field)
    {
        $input = $this->getFieldsByLabelOrCss($field);
        return $input->first();
    }

    /**
     * @param $field
     *
     * @return Crawler
     * @throws ModuleException
     */
    protected function getFieldsByLabelOrCss($field)
    {
        if (is_array($field)) {
            $input = $this->strictMatch($field);
            if (!count($input)) {
                throw new ElementNotFound($field);
            }
            return $input;
        }

        // by label
        $label = $this->strictMatch(['xpath' => sprintf('.//label[descendant-or-self::node()[text()[normalize-space()=%s]]]', Crawler::xpathLiteral($field))]);
        if (count($label)) {
            $label = $label->first();
            if ($label->attr('for')) {
                $input = $this->strictMatch(['id' => $label->attr('for')]);
            } else {
                $input = $this->strictMatch(['xpath' => sprintf('.//label[descendant-or-self::node()[text()[normalize-space()=%s]]]//input', Crawler::xpathLiteral($field))]);
            }
        }

        // by name
        if (!isset($input)) {
            $input = $this->strictMatch(['name' => $field]);
        }

        // by CSS and XPath
        if (!count($input)) {
            $input = $this->match($field);
        }

        if (!count($input)) {
            throw new ElementNotFound($field, 'Form field by Label or CSS');
        }

        return $input;
    }

    /**
     * Returns the DomCrawler\Form object for the form pointed to by
     * $node or its closes form parent.
     *
     * @param Crawler $node
     * @return Form
     * @throws ModuleException
     */
    protected function getFormFor(Crawler $node)
    {
        if (strcasecmp($node->first()->getNode(0)->tagName, 'form') === 0) {
            $form = $node->first();
        } else {
            $form = $node->parents()->filter('form')->first();
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
     * Returns the form action's absolute URL.
     *
     * @param Crawler $form
     * @return string
     * @throws TestRuntimeException|ModuleException if either the current
     *         URL or the URI of the form's action can't be parsed
     */
    protected function getFormUrl(Crawler $form)
    {
        $action = $form->form()->getUri();
        return $this->getAbsoluteUrlFor($action);
    }

    /**
     * Returns an array of name => value pairs for the passed form.
     *
     * For form fields containing a name ending in [], an array is
     * created out of all field values with the given name.
     *
     * @param Form $form
     * @return array an array of name => value pairs
     */
    protected function getFormValuesFor(Form $form)
    {
        $values = [];
        $fields = $form->all();
        foreach ($fields as $field) {
            if ($field instanceof FileFormField || $field->isDisabled() || !$field->hasValue()) {
                continue;
            }
            $fieldName = $this->getSubmissionFormFieldName($field->getName());
            if (substr($field->getName(), -2) === '[]') {
                if (!isset($values[$fieldName])) {
                    $values[$fieldName] = [];
                }
                $values[$fieldName][] = $field->getValue();
            } else {
                $values[$fieldName] = $field->getValue();
            }
        }
        return $values;
    }

    /**
     * @param $requestParams
     * @return array
     */
    protected function getFormPhpValues($requestParams)
    {
        foreach ($requestParams as $name => $value) {
            $qs = http_build_query([$name => $value], '', '&');
            if (!empty($qs)) {
                // If the field's name is of the form of "array[key]",
                // we'll remove it from the request parameters
                // and set the "array" key instead which will contain the actual array.
                if (strpos($name, '[') && strpos($name, ']') > strpos($name, '[')) {
                    unset($requestParams[$name]);
                }

                parse_str($qs, $expandedValue);
                $varName = substr($name, 0, strlen(key($expandedValue)));
                $requestParams = array_replace_recursive($requestParams, [$varName => current($expandedValue)]);
            }
        }
        return $requestParams;
    }

    /**
     * Get the values of a set of input fields.
     *
     * @param Crawler $input
     * @return array|string
     */
    protected function getInputValue($input)
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
     * @return string
     */
    protected function getNormalizedResponseContent()
    {
        $content = $this->_getResponseContent();
        // Since strip_tags has problems with JS code that contains
        // an <= operator the script tags have to be removed manually first.
        $content = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $content);

        $content = strip_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES);
        $content = str_replace("\n", ' ', $content);
        $content = preg_replace('/\s{2,}/', ' ', $content);

        return $content;
    }

    /**
     * @return int|string
     * @throws ModuleException
     */
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
     * Get the values of a set of fields and also the texts of selected options.
     *
     * @param Crawler $nodes
     * @return array|mixed|string
     */
    protected function getValueAndTextFromField(Crawler $nodes)
    {
        if ($nodes->filter('textarea')->count()) {
            return (new TextareaFormField($nodes->filter('textarea')->getNode(0)))->getValue();
        }

        $input = $nodes->filter('input');
        if ($input->count()) {
            return $this->getInputValue($input);
        }

        if ($nodes->filter('select')->count()) {
            $options = $nodes->filter('option[selected]');
            $values = [];

            foreach ($options as $option) {
                $values[] = $option->getAttribute('value');
                $values[] = $option->textContent;
                $values[] = trim($option->textContent);
            }

            return $values;
        }

        $this->fail("Element $nodes is not a form field or does not contain a form field");
    }

    /**
     * Strips out one pair of trailing square brackets from a field's
     * name.
     *
     * @param string $name the field name
     * @return string the name after stripping trailing square brackets
     */
    protected function getSubmissionFormFieldName($name)
    {
        if (substr($name, -2) === '[]') {
            return substr($name, 0, -2);
        }
        return $name;
    }

    protected function isInternalDomain($domain)
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
     * @param $selector
     *
     * @return Crawler
     * @throws ModuleException
     */
    protected function match($selector)
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
     * @param $name
     * @param $form
     * @param $dynamicField
     * @return FormField
     */
    protected function matchFormField($name, $form, $dynamicField)
    {
        if (substr($name, -2) !== '[]') {
            return $form[$name];
        }
        $name = substr($name, 0, -2);
        /** @var $item FormField */
        foreach ($form[$name] as $item) {
            if ($item == $dynamicField) {
                return $item;
            }
        }
        throw new TestRuntimeException("None of form fields by {$name}[] were not matched");
    }

    protected function matchOption(Crawler $field, $option)
    {
        if (isset($option['value'])) {
            return $option['value'];
        }
        if (isset($option['text'])) {
            $option = $option['text'];
        }
        $options = $field->filterXPath(sprintf('//option[text()=normalize-space("%s")]|//input[@type="radio" and @value=normalize-space("%s")]', $option, $option));
        if ($options->count()) {
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

    /**
     * @param $select
     * @return object|Crawler
     * @throws ModuleException
     */
    protected function matchSelectedOption($select)
    {
        $nodes = $this->getFieldsByLabelOrCss($select);
        $selectedOptions = $nodes->filter('option[selected],input:checked');
        if ($selectedOptions->count() === 0) {
            $selectedOptions = $nodes->filter('option,input')->first();
        }
        return $selectedOptions;
    }

    /**
     * @param $option
     * @return ChoiceFormField
     * @throws ModuleException
     */
    protected function proceedCheckOption($option)
    {
        $form = $this->getFormFor($field = $this->getFieldByLabelOrCss($option));
        $name = $field->attr('name');

        if ($field->getNode(0) === null) {
            throw new TestRuntimeException("Form field $name is not located");
        }
        // If the name is an array than we compare objects to find right checkbox
        $formField = $this->matchFormField($name, $form, new ChoiceFormField($field->getNode(0)));
        $field->getNode(0)->setAttribute('checked', 'checked');
        if (!$formField instanceof ChoiceFormField) {
            throw new TestRuntimeException("Form field $name is not a checkable");
        }
        return $formField;
    }

    protected function proceedSeeInField(Crawler $fields, $value)
    {
        $testValues = $this->getValueAndTextFromField($fields);
        if (!is_array($testValues)) {
            $testValues = [$testValues];
        }
        if (is_bool($value) && $value === true && !empty($testValues)) {
            $value = reset($testValues);
        } elseif (empty($testValues)) {
            $testValues = [''];
        }
        return [
            'Contains',
            (string)$value,
            $testValues,
            sprintf(
                'Failed asserting that `%s` is in %s\'s value: %s',
                $value,
                $fields->getNode(0)->nodeName,
                var_export($testValues, true)
            )
        ];
    }

    /**
     * @param $formSelector
     * @param array $params
     * @param $assertNot
     * @throws ModuleException
     */
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

        foreach ($fields as list($field, $values)) {
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
     * Submits the form currently selected in the passed Crawler, after
     * setting any values passed in $params and setting the value of the
     * passed button name.
     *
     * @param Crawler $frmCrawl the form to submit
     * @param array $params additional parameter values to set on the
     *        form
     * @param string $button the name of a submit button in the form
     * @throws ExternalUrlException|ModuleException
     */
    protected function proceedSubmitForm(Crawler $frmCrawl, array $params, $button = null)
    {
        $url = null;
        $form = $this->getFormFor($frmCrawl);
        $defaults = $this->getFormValuesFor($form);
        $merged = array_merge($defaults, $params);
        $requestParams = $this->setCheckboxBoolValues($frmCrawl, $merged);

        if (!empty($button)) {
            $btnCrawl = $frmCrawl->filterXPath(sprintf(
                '//*[not(@disabled) and @type="submit" and @name=%s]',
                Crawler::xpathLiteral($button)
            ));
            if (count($btnCrawl)) {
                $requestParams[$button] = $btnCrawl->attr('value');
                $formaction = $btnCrawl->attr('formaction');
                if ($formaction) {
                    $url = $formaction;
                }
            }
        }

        if (!$url) {
            $url = $this->getFormUrl($frmCrawl);
        }

        if (strcasecmp($form->getMethod(), 'GET') === 0) {
            $url = Uri::mergeUrls($url, '?' . http_build_query($requestParams));
        }

        $url = preg_replace('/#.*/', '', $url);

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

    /**
     * Map an array element passed to seeInFormFields to its corresponding field,
     * recursing through array values if the field is not found.
     *
     * @param array $fields The previously found fields.
     * @param Crawler $form The form in which to search for fields.
     * @param string $name The field's name.
     * @param mixed $values
     * @return void
     */
    protected function pushFormField(&$fields, $form, $name, $values)
    {
        $field = $form->filterXPath(sprintf('.//*[@name=%s]', Crawler::xpathLiteral($name)));

        if ($field->count()) {
            $fields[] = [$field, $values];
        } elseif (is_array($values)) {
            foreach ($values as $key => $value) {
                $this->pushFormField($fields, $form, "{$name}[$key]", $value);
            }
        } else {
            throw new ElementNotFound(
                sprintf('//*[@name=%s]', Crawler::xpathLiteral($name)),
                'Form'
            );
        }
    }

    /**
     * @param $result
     * @param $maxRedirects
     * @param $redirectCount
     * @return mixed
     * @throws ModuleException
     */
    protected function redirectIfNecessary($result, $maxRedirects, $redirectCount)
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
     * Replaces boolean values in $params with the corresponding field's
     * value for checkbox form fields.
     *
     * The function loops over all input checkbox fields, checking if a
     * corresponding key is set in $params.  If it is, and the value is
     * boolean or an array containing booleans, the value(s) are
     * replaced in the array with the real value of the checkbox, and
     * the array is returned.
     *
     * @param Crawler $form the form to find checkbox elements
     * @param array $params the parameters to be submitted
     * @return array the $params array after replacing bool values
     */
    protected function setCheckboxBoolValues(Crawler $form, array $params)
    {
        $checkboxes = $form->filter('input[type=checkbox]');
        $chFoundByName = [];
        foreach ($checkboxes as $box) {
            $fieldName = $this->getSubmissionFormFieldName($box->getAttribute('name'));
            $pos = (!isset($chFoundByName[$fieldName])) ? 0 : $chFoundByName[$fieldName];
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
                $params[$fieldName] = $box->hasAttribute('value') ? $box->getAttribute('value') : 'on';
                $chFoundByName[$fieldName] = $pos + 1;
            } elseif (is_array($values)) {
                if ($values[$pos] === true) {
                    $params[$fieldName][$pos] = $box->hasAttribute('value') ? $box->getAttribute('value') : 'on';
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
     * @param array $by
     * @return Crawler
     *@throws TestRuntimeException|ModuleException
     */
    protected function strictMatch(array $by)
    {
        $type = key($by);
        $locator = $by[$type];
        switch ($type) {
            case 'id':
                return $this->filterByCSS("#$locator");
            case 'name':
                return $this->filterByXPath(sprintf('.//*[@name=%s]', Crawler::xpathLiteral($locator)));
            case 'css':
                return $this->filterByCSS($locator);
            case 'xpath':
                return $this->filterByXPath($locator);
            case 'link':
                return $this->filterByXPath(sprintf('.//a[.=%s or contains(./@title, %s)]', Crawler::xpathLiteral($locator), Crawler::xpathLiteral($locator)));
            case 'class':
                return $this->filterByCSS(".$locator");
            default:
                throw new TestRuntimeException(
                    "Locator type '$by' is not defined. Use either: xpath, css, id, link, class, name"
                );
        }
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

    public function _after(TestInterface $test)
    {
        $this->client = null;
        $this->crawler = null;
        $this->forms = [];
        $this->headers = [];
    }

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

    /**
     * @return string
     */
    public function _getCurrentUri()
    {
        return Uri::retrieveUri($this->getRunningClient()->getHistory()->current()->getUri());
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
     * @return int|string
     */
    public function _getResponseStatusCode()
    {
        return $this->getResponseStatusCode();
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
     * @api
     * @param $method
     * @param $uri
     * @param array $parameters
     * @param array $files
     * @param array $server
     * @param null $content
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
     * @api
     * @param $method
     * @param $uri
     * @param array $parameters
     * @param array $files
     * @param array $server
     * @param null $content
     * @return mixed|Crawler
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
     * Checks that response code is equal to value provided.
     *
     * ```php
     * <?php
     * $I->dontSeeResponseCodeIs(200);
     *
     * // recommended \Codeception\Util\HttpCode
     * $I->dontSeeResponseCodeIs(\Codeception\Util\HttpCode::OK);
     * ```
     *
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
     * Checks that the response code is 4xx
     */
    public function seeResponseCodeIsClientError()
    {
        $this->seeResponseCodeIsBetween(400, 499);
    }

    /**
     * Checks that the response code 3xx
     */
    public function seeResponseCodeIsRedirection()
    {
        $this->seeResponseCodeIsBetween(300, 399);
    }

    /**
     * Checks that the response code is 5xx
     */
    public function seeResponseCodeIsServerError()
    {
        $this->seeResponseCodeIsBetween(500, 599);
    }

    /**
     * Checks that the response code 2xx
     */
    public function seeResponseCodeIsSuccessful()
    {
        $this->seeResponseCodeIsBetween(200, 299);
    }

    /**
     * If your page triggers an ajax request, you can perform it manually.
     * This action sends a GET ajax request with specified params.
     *
     * See ->sendAjaxPostRequest for examples.
     *
     * @param $uri
     * @param array $params
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
     * @param array $params
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
     * @param array $params
     */
    public function sendAjaxRequest($method, $uri, $params = [])
    {
        $this->clientRequest($method, $uri, $params, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], null, false);
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
     * @param $name
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
}
