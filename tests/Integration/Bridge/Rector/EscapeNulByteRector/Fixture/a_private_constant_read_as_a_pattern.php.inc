<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeNulByteRector\Fixture;

final class APrivateConstantReadAsAPattern
{
    private const PATTERN = "/a\0b/";
    private const OTHER = "/[\0-\\x1F]/", UNUSED = 'x';

    public function run(string $subject): bool
    {
        $matched = array_filter([$subject], static fn (string $text): bool => 1 === preg_match(self::PATTERN, $text));

        return [] !== $matched || 1 === preg_match_all(self::OTHER, $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeNulByteRector\Fixture;

final class APrivateConstantReadAsAPattern
{
    private const PATTERN = '/a\x00b/';
    private const OTHER = '/[\x00-\x1F]/', UNUSED = 'x';

    public function run(string $subject): bool
    {
        $matched = array_filter([$subject], static fn (string $text): bool => 1 === preg_match(self::PATTERN, $text));

        return [] !== $matched || 1 === preg_match_all(self::OTHER, $subject);
    }
}

?>
