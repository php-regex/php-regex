<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\Set\Fixture;

final class SkipUnproven
{
    public function run(string $subject): array
    {
        return [
            (bool) preg_match('/foo/i', $subject),
            preg_replace('/ab?/', 'X', $subject),
            preg_split('//', $subject),
        ];
    }
}

?>
