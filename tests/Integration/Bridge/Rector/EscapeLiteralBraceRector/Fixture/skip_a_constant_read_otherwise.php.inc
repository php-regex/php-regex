<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\Fixture;

trait ReadsItsUserConstants
{
}

final class SkipAPublicOrProtectedConstant
{
    public const SHARED = '/a{,3}/';
    protected const INHERITED = '/b{,3}/';

    public function run(string $subject): bool
    {
        return 1 === preg_match(self::SHARED, $subject) && 1 === preg_match(self::INHERITED, $subject);
    }
}

final class SkipAPrivateConstantReadOtherwise
{
    private const MEASURED = '/c{,3}/';
    private const LATE = '/d{,3}/';
    private const NAMED = '/e{,3}/';
    private const COMPOSED = '/f{,3}/';
    private const PREFIXED = self::COMPOSED.'i';
    private const UNREAD = '/g{,3}/';
    private const SPLIT = '/h{,3}/';

    #[\JetBrains\PhpStorm\Pure(self::LATE)]
    public function run(string $subject): bool
    {
        return 1 === preg_match(self::MEASURED, $subject) && 7 === \strlen(self::MEASURED)
            && 1 === preg_match(static::LATE, $subject)
            && 1 === preg_match(self::NAMED, $subject) && 1 === preg_match(SkipAPrivateConstantReadOtherwise::NAMED, $subject)
            && 1 === preg_match(self::PREFIXED, $subject)
            && [] !== preg_split(pattern: self::SPLIT, subject: $subject);
    }
}

final class SkipAPrivateConstantReadByName
{
    private const PATTERN = '/a{,3}/';

    public function run(string $subject): bool
    {
        return 1 === preg_match(self::PATTERN, $subject) && '' !== \constant('self::PATTERN');
    }
}

final class SkipAPrivateConstantReadThroughAComputedName
{
    private const PATTERN = '/a{,3}/';

    public function run(string $subject, string $name): bool
    {
        return 1 === preg_match(self::PATTERN, $subject) && '' !== self::{$name};
    }
}

final class SkipAPrivateConstantACallByNameMayRead
{
    private const PATTERN = '/a{,3}/';

    public function run(string $subject, string $function): bool
    {
        return 1 === preg_match(self::PATTERN, $subject) && '' !== $function('self::PATTERN');
    }
}

final class SkipAPrivateConstantATraitMayRead
{
    use ReadsItsUserConstants;

    private const PATTERN = '/a{,3}/';

    public function run(string $subject): bool
    {
        return 1 === preg_match(self::PATTERN, $subject);
    }
}

final class SkipAConstantANestedClassDeclares
{
    public function run(string $subject): bool
    {
        $reader = new class() {
            private const PATTERN = '/a{,3}/';

            public function read(): string
            {
                return self::PATTERN;
            }
        };

        return 1 === preg_match('/\{/', $reader->read());
    }
}

?>
