<?php

namespace Codeception\Lib\Model;

use Codeception\Exception\ElementNotFound;
use Codeception\Exception\MalformedLocatorException;
use Codeception\Lib\Interfaces\Web;
use InvalidArgumentException;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Field\InputFormField;
use Symfony\Component\DomCrawler\Field\TextareaFormField;

/**
 * @see Web
 */
trait WebTrait
{
    public function amOnPage($page)
    {
        $this->_loadPage('GET', $page);
    }

    public function see($text, $selector = null)
    {
        if (!$selector) {
            $this->assertPageContains($text);
            return;
        }

        $nodes = $this->match($selector);
        $this->assertDomContains($nodes, $this->stringifySelector($selector), $text);
    }

    public function dontSee($text, $selector = null)
    {
        if (!$selector) {
            $this->assertPageNotContains($text);
            return;
        }

        $nodes = $this->match($selector);
        $this->assertDomNotContains($nodes, $this->stringifySelector($selector), $text);
    }

    public function seeInSource($raw)
    {
        $this->assertPageSourceContains($raw);
    }

    public function dontSeeInSource($raw)
    {
        $this->assertPageSourceNotContains($raw);
    }

    public function submitForm($selector, array $params, $button = null)
    {
        $form = $this->match($selector)->first();
        if (!count($form)) {
            throw new ElementNotFound($this->stringifySelector($selector), 'Form');
        }
        $this->proceedSubmitForm($form, $params, $button);
    }

    public function click($link, $context = null)
    {
        if ($context) {
            $this->crawler = $this->match($context);
        }

        if (is_array($link)) {
            $this->clickByLocator($link);
            return;
        }

        $anchor = $this->strictMatch(['link' => $link]);
        if (!count($anchor)) {
            $anchor = $this->getCrawler()->selectLink($link);
        }
        if (count($anchor)) {
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
        } catch (MalformedLocatorException $e) {
            throw new ElementNotFound("name=$link", "'$link' is invalid CSS and XPath selector and Link or Button");
        }
    }

    public function seeLink($text, $url = null)
    {
        $crawler = $this->getCrawler()->selectLink($text);
        if ($crawler->count() === 0) {
            $this->fail("No links containing text '$text' were found in page " . $this->_getCurrentUri());
        }
        if ($url) {
            $crawler = $crawler->filterXPath(sprintf('.//a[substring(@href, string-length(@href) - string-length(%1$s) + 1)=%1$s]', Crawler::xpathLiteral($url)));
            if ($crawler->count() === 0) {
                $this->fail("No links containing text '$text' and URL '$url' were found in page " . $this->_getCurrentUri());
            }
        }
        $this->assertTrue(true);
    }

    public function dontSeeLink($text, $url = '')
    {
        $crawler = $this->getCrawler()->selectLink($text);
        if (!$url && $crawler->count() > 0) {
            $this->fail("Link containing text '$text' was found in page " . $this->_getCurrentUri());
        }
        $crawler = $crawler->filterXPath(
            sprintf('.//a[substring(@href, string-length(@href) - string-length(%1$s) + 1)=%1$s]',
                Crawler::xpathLiteral($url))
        );
        if ($crawler->count() > 0) {
            $this->fail("Link containing text '$text' and URL '$url' was found in page " . $this->_getCurrentUri());
        }
    }

    public function seeInCurrentUrl($uri)
    {
        $this->assertStringContainsString($uri, $this->_getCurrentUri());
    }

    public function seeCurrentUrlEquals($uri)
    {
        $this->assertEquals(rtrim($uri, '/'), rtrim($this->_getCurrentUri(), '/'));
    }

    public function seeCurrentUrlMatches($uri)
    {
        $this->assertRegExp($uri, $this->_getCurrentUri());
    }

    public function dontSeeInCurrentUrl($uri)
    {
        $this->assertStringNotContainsString($uri, $this->_getCurrentUri());
    }

    public function dontSeeCurrentUrlEquals($uri)
    {
        $this->assertNotEquals(rtrim($uri, '/'), rtrim($this->_getCurrentUri(), '/'));
    }

    public function dontSeeCurrentUrlMatches($uri)
    {
        $this->assertNotRegExp($uri, $this->_getCurrentUri());
    }

    public function grabFromCurrentUrl($uri = null)
    {
        if (!$uri) {
            return $this->_getCurrentUri();
        }
        $matches = [];
        $res     = preg_match($uri, $this->_getCurrentUri(), $matches);
        if (!$res) {
            $this->fail("Couldn't match $uri in " . $this->_getCurrentUri());
        }
        if (!isset($matches[1])) {
            $this->fail("Nothing to grab. A regex parameter required. Ex: '/user/(\\d+)'");
        }
        return $matches[1];
    }

    public function seeCheckboxIsChecked($checkbox)
    {
        $checkboxes = $this->getFieldsByLabelOrCss($checkbox);
        $this->assertDomContains($checkboxes->filter('input[checked=checked]'), 'checkbox');
    }

    public function dontSeeCheckboxIsChecked($checkbox)
    {
        $checkboxes = $this->getFieldsByLabelOrCss($checkbox);
        $this->assertEquals(0, $checkboxes->filter('input[checked=checked]')->count());
    }

    public function seeInField($field, $value)
    {
        $nodes = $this->getFieldsByLabelOrCss($field);
        $this->assert($this->proceedSeeInField($nodes, $value));
    }

    public function dontSeeInField($field, $value)
    {
        $nodes = $this->getFieldsByLabelOrCss($field);
        $this->assertNot($this->proceedSeeInField($nodes, $value));
    }

    public function seeInFormFields($formSelector, array $params)
    {
        $this->proceedSeeInFormFields($formSelector, $params, false);
    }

    public function dontSeeInFormFields($formSelector, array $params)
    {
        $this->proceedSeeInFormFields($formSelector, $params, true);
    }

    public function selectOption($select, $option)
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

        $formField->select($this->matchOption($field, $option));
    }

    public function checkOption($option)
    {
        $this->proceedCheckOption($option)->tick();
    }

    public function uncheckOption($option)
    {
        $this->proceedCheckOption($option)->untick();
    }

    public function fillField($field, $value)
    {
        $input = $this->getFieldByLabelOrCss($field);
        $form = $this->getFormFor($input);
        $name = $input->attr('name');

        $dynamicField = $input->getNode(0)->tagName === 'textarea'
            ? new TextareaFormField($input->getNode(0))
            : new InputFormField($input->getNode(0));
        $formField = $this->matchFormField($name, $form, $dynamicField);
        $formField->setValue($value);
        $input->getNode(0)->setAttribute('value', htmlspecialchars($value));
        if ($input->getNode(0)->tagName === 'textarea') {
            $input->getNode(0)->nodeValue = htmlspecialchars($value);
        }
    }

    public function attachFile($field, $filename)
    {
        $form = $this->getFormFor($field = $this->getFieldByLabelOrCss($field));
        $filePath = codecept_data_dir() . $filename;
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("File does not exist: $filePath");
        }
        if (!is_readable($filePath)) {
            throw new InvalidArgumentException("File is not readable: $filePath");
        }

        $name = $field->attr('name');
        $formField = $this->matchFormField($name, $form, new FileFormField($field->getNode(0)));
        if (is_array($formField)) {
            $this->fail("Field $name is ignored on upload, field $name is treated as array.");
        }

        $formField->upload($filePath);
    }

    public function grabTextFrom($cssOrXPathOrRegex)
    {
        if (@preg_match($cssOrXPathOrRegex, $this->client->getInternalResponse()->getContent(), $matches)) {
            return $matches[1];
        }
        $nodes = $this->match($cssOrXPathOrRegex);
        if ($nodes->count()) {
            return $nodes->first()->text();
        }
        throw new ElementNotFound($cssOrXPathOrRegex, 'Element that matches CSS or XPath or Regex');
    }

    /**
     * @param $field
     *
     * @return array|mixed|null|string
     */
    public function grabValueFrom($field)
    {
        $nodes = $this->match($field);
        if (!$nodes->count()) {
            throw new ElementNotFound($field, 'Field');
        }

        if ($nodes->filter('textarea')->count()) {
            return (new TextareaFormField($nodes->filter('textarea')->getNode(0)))->getValue();
        }

        $input = $nodes->filter('input');
        if ($input->count()) {
            return $this->getInputValue($input);
        }

        if ($nodes->filter('select')->count()) {
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

        $this->fail("Element $nodes is not a form field or does not contain a form field");
    }

    public function grabAttributeFrom($cssOrXpath, $attribute)
    {
        $nodes = $this->match($cssOrXpath);
        if (!$nodes->count()) {
            throw new ElementNotFound($cssOrXpath, 'Element that matches CSS or XPath');
        }
        return $nodes->first()->attr($attribute);
    }

    public function grabMultiple($cssOrXpath, $attribute = null)
    {
        $result = [];
        $nodes = $this->match($cssOrXpath);

        foreach ($nodes as $node) {
            if ($attribute !== null) {
                $result[] = $node->getAttribute($attribute);
            } else {
                $result[] = $node->textContent;
            }
        }
        return $result;
    }

    public function seeElement($selector, $attributes = [])
    {
        $nodes = $this->match($selector);
        $selector = $this->stringifySelector($selector);
        if (!empty($attributes)) {
            $nodes = $this->filterByAttributes($nodes, $attributes);
            $selector .= "' with attribute(s) '" . trim(json_encode($attributes), '{}');
        }
        $this->assertDomContains($nodes, $selector);
    }

    public function dontSeeElement($selector, $attributes = [])
    {
        $nodes = $this->match($selector);
        $selector = $this->stringifySelector($selector);
        if (!empty($attributes)) {
            $nodes = $this->filterByAttributes($nodes, $attributes);
            $selector .= "' with attribute(s) '" . trim(json_encode($attributes), '{}');
        }
        $this->assertDomNotContains($nodes, $selector);
    }

    public function seeNumberOfElements($selector, $expected)
    {
        $counted = count($this->match($selector));
        if (is_array($expected)) {
            list($floor, $ceil) = $expected;
            $this->assertTrue(
                $floor <= $counted && $ceil >= $counted,
                'Number of elements counted differs from expected range'
            );
        } else {
            $this->assertEquals(
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
        $this->assertEquals($optionText, $value);
    }

    public function dontSeeOptionIsSelected($selector, $optionText)
    {
        $selected = $this->matchSelectedOption($selector);
        if (!$selected->count()) {
            $this->assertEquals(0, $selected->count());
            return;
        }
        //If element is radio then we need to check value
        $value = $selected->getNode(0)->tagName === 'option'
            ? $selected->text()
            : $selected->getNode(0)->getAttribute('value');
        $this->assertNotEquals($optionText, $value);
    }

    public function seeInTitle($title)
    {
        $nodes = $this->getCrawler()->filter('title');
        if (!$nodes->count()) {
            throw new ElementNotFound("<title>", "Tag");
        }
        $this->assertStringContainsString($title, $nodes->first()->text(), "page title contains $title");
    }

    public function dontSeeInTitle($title)
    {
        $nodes = $this->getCrawler()->filter('title');
        if (!$nodes->count()) {
            $this->assertTrue(true);
            return;
        }
        $this->assertStringNotContainsString($title, $nodes->first()->text(), "page title contains $title");
    }

    public function seeCookie($cookie, array $params = [])
    {
        $params = array_merge($this->defaultCookieParameters, $params);
        $this->debugCookieJar();
        $this->assertNotNull($this->client->getCookieJar()->get($cookie, $params['path'], $params['domain']));
    }

    public function dontSeeCookie($cookie, array $params = [])
    {
        $params = array_merge($this->defaultCookieParameters, $params);
        $this->debugCookieJar();
        $this->assertNull($this->client->getCookieJar()->get($cookie, $params['path'], $params['domain']));
    }

    public function setCookie($name, $val, array $params = [])
    {
        $cookies = $this->client->getCookieJar();
        $params = array_merge($this->defaultCookieParameters, $params);

        $expires      = isset($params['expiry']) ? $params['expiry'] : null; // WebDriver compatibility
        $expires      = isset($params['expires']) && !$expires ? $params['expires'] : null;
        $path         = isset($params['path'])    ? $params['path'] : null;
        $domain       = isset($params['domain'])  ? $params['domain'] : '';
        $secure       = isset($params['secure'])  ? $params['secure'] : false;
        $httpOnly     = isset($params['httpOnly'])  ? $params['httpOnly'] : true;
        $encodedValue = isset($params['encodedValue'])  ? $params['encodedValue'] : false;

        $cookies->set(new Cookie($name, $val, $expires, $path, $domain, $secure, $httpOnly, $encodedValue));
        $this->debugCookieJar();
    }

    public function resetCookie($name, array $params = [])
    {
        $params = array_merge($this->defaultCookieParameters, $params);
        $this->client->getCookieJar()->expire($name, $params['path'], $params['domain']);
        $this->debugCookieJar();
    }

    public function grabCookie($cookie, array $params = [])
    {
        $params = array_merge($this->defaultCookieParameters, $params);
        $this->debugCookieJar();
        $cookies = $this->getRunningClient()->getCookieJar()->get($cookie, $params['path'], $params['domain']);
        if (!$cookies) {
            return null;
        }
        return $cookies->getValue();
    }

    /**
     * Grabs current page source code.
     *
     * @return string Current page source code.
     */
    public function grabPageSource()
    {
        return $this->_getResponseContent();
    }
}