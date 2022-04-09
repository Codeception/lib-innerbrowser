<?php

declare(strict_types=1);

use Codeception\Constraint\CrawlerNot as CrawlerNotConstraint;
use Codeception\PHPUnit\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

final class CrawlerNotConstraintTest extends TestCase
{
    protected ?CrawlerNotConstraint $constraint = null;

    public function _setUp()
    {
        $this->constraint = new CrawlerNotConstraint('warcraft', '/user');
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
            $this->assertStringContainsString("There was 'selector' element on page /user", $fail->getMessage());
            $this->assertStringNotContainsString('+ <p>Bye world</p>', $fail->getMessage());
            $this->assertStringContainsString('+ <p>Bye warcraft</p>', $fail->getMessage());
            return;
        }

        $this->fail("should have failed, but not");
    }

    public function testFailMessageResponseWhenMoreNodes()
    {
        $html = '';
        for ($i = 0; $i < 15; ++$i) {
            $html .= "<p>warcraft {$i}</p>";
        }

        $nodes = new DomCrawler($html);
        try {
            $this->constraint->evaluate($nodes->filter('p'), 'selector');
        } catch (AssertionFailedError $fail) {
            $this->assertStringContainsString("There was 'selector' element on page /user", $fail->getMessage());
            $this->assertStringContainsString('+ <p>warcraft 0</p>', $fail->getMessage());
            $this->assertStringContainsString('+ <p>warcraft 14</p>', $fail->getMessage());
            return;
        }

        $this->fail("should have failed, but not");
    }

    public function testFailMessageResponseWithoutUrl()
    {
        $this->constraint = new CrawlerNotConstraint('warcraft');
        $nodes = new DomCrawler('<p>Bye world</p><p>Bye warcraft</p>');
        try {
            $this->constraint->evaluate($nodes->filter('p'), 'selector');
        } catch (AssertionFailedError $fail) {
            $this->assertStringContainsString("There was 'selector' element", $fail->getMessage());
            $this->assertStringNotContainsString("There was 'selector' element on page /user", $fail->getMessage());
            return;
        }

        $this->fail("should have failed, but not");
    }
}
