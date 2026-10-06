<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\Set\Fixture;

final class StringFunctions
{
    public function run(string $subject): array
    {
        return [
            (bool) preg_match('/foo/', $subject),
            preg_replace('/foo/', 'bar', $subject),
            preg_split('/,/', $subject),
        ];
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\Set\Fixture;

final class StringFunctions
{
    public function run(string $subject): array
    {
        return [
            \str_contains($subject, 'foo'),
            \str_replace('foo', 'bar', $subject),
            \explode(',', $subject),
        ];
    }
}

?>
