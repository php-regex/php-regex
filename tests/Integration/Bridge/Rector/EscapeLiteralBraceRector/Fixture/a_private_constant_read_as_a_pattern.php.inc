<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\Fixture;

final class APrivateConstantReadAsAPattern
{
    private const PATTERN = '/a{,3}/';
    private const KEY = '/b{,3}/';

    public function run(string $subject): bool
    {
        $callbacks = ['/b{,3}/' => static fn (array $match): string => 'x'];

        return 1 === preg_match(self::PATTERN, $subject)
            && 1 === preg_match(self::PATTERN, $subject.'x')
            && 7 === \strlen('/c{,3}/')
            && false !== \DateTime::createFromFormat('/d{,3}/', $subject)
            && null !== preg_replace_callback_array($callbacks, $subject)
            && null !== preg_replace_callback_array([self::KEY => static fn (array $match): string => 'y'], $subject)
            && 7 === (new class() {
                private const PATTERN = '/z{,3}/';

                public function length(): int
                {
                    return \strlen(self::PATTERN);
                }
            })->length();
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\Fixture;

final class APrivateConstantReadAsAPattern
{
    private const PATTERN = '/a\{,3}/';
    private const KEY = '/b\{,3}/';

    public function run(string $subject): bool
    {
        $callbacks = ['/b{,3}/' => static fn (array $match): string => 'x'];

        return 1 === preg_match(self::PATTERN, $subject)
            && 1 === preg_match(self::PATTERN, $subject.'x')
            && 7 === \strlen('/c{,3}/')
            && false !== \DateTime::createFromFormat('/d{,3}/', $subject)
            && null !== preg_replace_callback_array($callbacks, $subject)
            && null !== preg_replace_callback_array([self::KEY => static fn (array $match): string => 'y'], $subject)
            && 7 === (new class() {
                private const PATTERN = '/z{,3}/';

                public function length(): int
                {
                    return \strlen(self::PATTERN);
                }
            })->length();
    }
}

?>
