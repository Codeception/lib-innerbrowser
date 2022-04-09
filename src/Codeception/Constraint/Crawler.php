<?php

declare(strict_types=1);

namespace Codeception\Constraint;

use Codeception\Exception\ElementNotFound;
use DOMElement;
use PHPUnit\Framework\ExpectationFailedException;
use SebastianBergmann\Comparator\ComparisonFailure;
use Symfony\Component\DomCrawler\Crawler as SymfonyDomCrawler;

use function rtrim;
use function sprintf;
use function strpos;

class Crawler extends Page
{
    /**
     * @param SymfonyDomCrawler $nodes
     * @return bool
     */
    protected function matches($nodes): bool
    {
        if (!$nodes->count()) {
            return false;
        }
        if ($this->string === '') {
            return true;
        }

        foreach ($nodes as $node) {
            if (parent::matches($node->nodeValue)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param SymfonyDomCrawler $nodes
     * @param string $selector
     * @param ComparisonFailure|null $comparisonFailure
     */
    protected function fail($nodes, $selector, ComparisonFailure $comparisonFailure = null): void
    {
        if (!$nodes->count()) {
            throw new ElementNotFound($selector, 'Element located either by name, CSS or XPath');
        }

        $output = "Failed asserting that any element by '{$selector}' ";
        $output .= $this->uriMessage('on page');

        if ($nodes->count() < 10) {
            $output .= $this->nodesList($nodes);
        } else {
            $output = sprintf('%s [total %d elements]', rtrim($output, ' '), $nodes->count());
        }
        $output .= "\ncontains text '{$this->string}'";

        throw new ExpectationFailedException(
            $output,
            $comparisonFailure
        );
    }

    /**
     * @param DOMElement[] $other
     * @return string
     */
    protected function failureDescription($other): string
    {
        $description = '';
        foreach ($other as $o) {
            $description .= parent::failureDescription($o->textContent);
        }
        return $description;
    }

    protected function nodesList(SymfonyDomCrawler $domCrawler, string $contains = null): string
    {
        $output = '';
        foreach ($domCrawler as $node) {
            if ($contains && strpos($node->nodeValue, $contains) === false) {
                continue;
            }
            $output .= "\n+ " . $node->C14N();
        }
        return $output;
    }
}
