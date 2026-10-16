<?php

namespace App\Parity;

use Assert\Assertion;
use Symfony\Component\Validator\Constraints as Assert;
use Webmozart\Assert\Assert as Check;

final class PresetsTest extends \PHPUnit\Framework\TestCase
{
    #[Assert\Regex('/symfony-attribute/')]
    public string $name = '';

    public function test_presets(string $subject, \Iterator $iterator): void
    {
        $this->assertMatchesRegularExpression('/phpunit-this/', $subject);
        self::assertDoesNotMatchRegularExpression(pattern: '/phpunit-named/', string: $subject);
        $this->expectExceptionMessageMatches('/phpunit-expectation/');
        Check::regex($subject, '/webmozart/');
        Assertion::notRegex($subject, '/beberlei/');
        new Assert\Regex(pattern: '/symfony-new/', htmlPattern: '[a-z]+');
        new \RegexIterator($iterator, '/regex-iterator/');
        filter_var($subject, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/filter-var/']]);
        preg_match(...['subject' => $subject], pattern: '/after-spread/');
    }
}
