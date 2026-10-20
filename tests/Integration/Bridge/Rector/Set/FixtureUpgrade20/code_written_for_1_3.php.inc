<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\Set\FixtureUpgrade20;

use RegexParser\Lint\RegexAnalysisService;
use RegexParser\ReDoS\ReDoSSeverity;
use RegexParser\Regex;
use RegexParser\TokenType;

final class CodeWrittenFor13
{
    public function clear(): void
    {
        Regex::clearValidatorCaches();
    }

    public function isHigh(ReDoSSeverity $severity): bool
    {
        return ReDoSSeverity::HIGH === $severity;
    }

    public function isLiteral(TokenType $type): bool
    {
        return TokenType::T_LITERAL === $type;
    }

    public function parser(RegexAnalysisService $service): object
    {
        return $service->getRegex();
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\Set\FixtureUpgrade20;

use RegexParser\Lint\RegexAnalysisService;
use RegexParser\ReDoS\ReDoSSeverity;
use RegexParser\Regex;
use RegexParser\TokenType;

final class CodeWrittenFor13
{
    public function clear(): void
    {
        \PHPRegex\Toolkit\Regex::clearCaches();
    }

    public function isHigh(\PHPRegex\Redos\RedosSeverity $severity): bool
    {
        return \PHPRegex\Redos\RedosSeverity::High === $severity;
    }

    public function isLiteral(\PHPRegex\Parser\Token\TokenType $type): bool
    {
        return \PHPRegex\Parser\Token\TokenType::Literal === $type;
    }

    public function parser(\PHPRegex\Linter\AnalysisService $service): object
    {
        return $service->getParser();
    }
}

?>