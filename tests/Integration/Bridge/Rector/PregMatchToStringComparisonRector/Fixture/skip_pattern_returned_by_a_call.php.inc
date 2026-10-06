<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector\Fixture;

final class SkipPatternReturnedByACall
{
    public function run(string $subject): bool
    {
        return (bool) preg_match(self::pattern(), $subject);
    }

    /**
     * @return '/foo/'
     */
    private static function pattern(): string
    {
        return '/foo/';
    }
}

?>
