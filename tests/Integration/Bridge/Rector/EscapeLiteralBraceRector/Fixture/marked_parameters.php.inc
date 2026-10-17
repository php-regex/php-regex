<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\Fixture;

use PHPRegex\Parser\Attribute\RegexPattern;

function matches_marked(#[RegexPattern] string $pattern, #[\SensitiveParameter] string $subject): bool
{
    return 1 === preg_match($pattern, $subject);
}

final class MarkedPatterns
{
    public static function matches(#[RegexPattern] string $pattern, string $subject): bool
    {
        return 1 === preg_match($pattern, $subject);
    }

    public function run(string $subject): bool
    {
        return matches_marked('/a{,3}/', $subject)
            && self::matches('/d{,3}/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\Fixture;

use PHPRegex\Parser\Attribute\RegexPattern;

function matches_marked(#[RegexPattern] string $pattern, #[\SensitiveParameter] string $subject): bool
{
    return 1 === preg_match($pattern, $subject);
}

final class MarkedPatterns
{
    public static function matches(#[RegexPattern] string $pattern, string $subject): bool
    {
        return 1 === preg_match($pattern, $subject);
    }

    public function run(string $subject): bool
    {
        return matches_marked('/a\{,3}/', $subject)
            && self::matches('/d\{,3}/', $subject);
    }
}

?>
