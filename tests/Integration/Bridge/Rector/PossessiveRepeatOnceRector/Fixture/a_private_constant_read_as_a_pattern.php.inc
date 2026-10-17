<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PossessiveRepeatOnceRector\Fixture;

final class APrivateConstantReadAsAPattern
{
    private const PATTERN = '/(a|ab){1}+c/';
    private const OTHER = '/(?:a|ab){1,1}+c/', UNUSED = 'x';

    public function run(string $subject): bool
    {
        $matched = array_filter([$subject], static fn (string $text): bool => 1 === preg_match(self::PATTERN, $text));

        return [] !== $matched || 1 === preg_match_all(self::OTHER, $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PossessiveRepeatOnceRector\Fixture;

final class APrivateConstantReadAsAPattern
{
    private const PATTERN = '/(a|ab){1}c/';
    private const OTHER = '/(?:a|ab){1,1}c/', UNUSED = 'x';

    public function run(string $subject): bool
    {
        $matched = array_filter([$subject], static fn (string $text): bool => 1 === preg_match(self::PATTERN, $text));

        return [] !== $matched || 1 === preg_match_all(self::OTHER, $subject);
    }
}

?>
