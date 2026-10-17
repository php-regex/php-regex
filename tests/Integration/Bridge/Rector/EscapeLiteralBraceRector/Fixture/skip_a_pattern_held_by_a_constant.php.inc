<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\Fixture;

final class SkipAPatternHeldByAConstant
{
    private const PATTERN = '/a{,3}/';

    public function run(string $subject): bool
    {
        $callbacks = ['/b{,3}/' => static fn (array $match): string => 'x'];

        return 1 === preg_match(self::PATTERN, $subject)
            && 7 === \strlen('/c{,3}/')
            && false !== \DateTime::createFromFormat('/d{,3}/', $subject)
            && null !== preg_replace_callback_array($callbacks, $subject);
    }
}

?>
