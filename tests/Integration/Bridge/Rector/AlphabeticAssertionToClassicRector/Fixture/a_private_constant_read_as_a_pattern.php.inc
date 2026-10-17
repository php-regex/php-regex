<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\AlphabeticAssertionToClassicRector\Fixture;

final class APrivateConstantReadAsAPattern
{
    private const PATTERN = '/(*pla:foo)\w+(*nlb:bar)/';
    private const OTHER = '/(*plb:x)y/', UNUSED = 'x';

    public function run(string $subject): bool
    {
        $matched = array_filter([$subject], static fn (string $text): bool => 1 === preg_match(self::PATTERN, $text));

        return [] !== $matched || 1 === preg_match_all(self::OTHER, $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\AlphabeticAssertionToClassicRector\Fixture;

final class APrivateConstantReadAsAPattern
{
    private const PATTERN = '/(?=foo)\w+(?<!bar)/';
    private const OTHER = '/(?<=x)y/', UNUSED = 'x';

    public function run(string $subject): bool
    {
        $matched = array_filter([$subject], static fn (string $text): bool => 1 === preg_match(self::PATTERN, $text));

        return [] !== $matched || 1 === preg_match_all(self::OTHER, $subject);
    }
}

?>
