<?php

declare(strict_types=1);

use Codeception\Constraint\Crawler as CrawlerConstraint;
use Codeception\PHPUnit\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

final class CrawlerConstraintTest extends TestCase
{
    protected ?CrawlerConstraint $constraint = null;

    public function _setUp()
    {
        $this->constraint = new CrawlerConstraint('hello', '/user');
    }

    public function testEvaluation()
    {
        $nodes = new DomCrawler("<p>Bye world</p><p>Hello world</p>");
        $this->constraint->evaluate($nodes);
    }

    public function testFailMessageResponse()
    {
        $nodes = new DomCrawler('<p>Bye world</p><p>Bye warcraft</p>');
        try {
            $this->constraint->evaluate($nodes->filter('p'), 'selector');
        } catch (AssertionFailedError $fail) {
            $this->assertStringContainsString(
                "Failed asserting that any element by 'selector' on page /user",
                $fail->getMessage()
            );
            $this->assertStringContainsString('+ <p>Bye world</p>', $fail->getMessage());
            $this->assertStringContainsString('+ <p>Bye warcraft</p>', $fail->getMessage());
            return;
        }

        $this->fail("should have failed, but not");
    }

    public function testFailMessageResponseWhenMoreNodes()
    {
        $html = '';
        for ($i = 0; $i < 15; ++$i) {
            $html .= "<p>item {$i}</p>";
        }

        $nodes = new DomCrawler($html);
        try {
            $this->constraint->evaluate($nodes->filter('p'), 'selector');
        } catch (AssertionFailedError $fail) {
            $this->assertStringContainsString(
                "Failed asserting that any element by 'selector' on page /user",
                $fail->getMessage()
            );
            $this->assertStringNotContainsString('+ <p>item 0</p>', $fail->getMessage());
            $this->assertStringNotContainsString('+ <p>item 14</p>', $fail->getMessage());
            $this->assertStringContainsString('[total 15 elements]', $fail->getMessage());
            return;
        }

        $this->fail("should have failed, but not");
    }

    public function testFailMessageResponseWithoutUrl()
    {
        $this->constraint = new CrawlerConstraint('hello');
        $nodes = new DomCrawler('<p>Bye world</p><p>Bye warcraft</p>');
        try {
            $this->constraint->evaluate($nodes->filter('p'), 'selector');
        } catch (AssertionFailedError $fail) {
            $this->assertStringContainsString("Failed asserting that any element by 'selector'", $fail->getMessage());
            $this->assertStringNotContainsString("Failed asserting that any element by 'selector' on page", $fail->getMessage());
            return;
        }

        $this->fail("should have failed, but not");
    }
}
