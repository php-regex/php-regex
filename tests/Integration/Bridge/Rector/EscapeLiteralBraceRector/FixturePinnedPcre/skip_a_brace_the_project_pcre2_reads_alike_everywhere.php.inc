<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector\FixturePinnedPcre;

final class SkipABraceTheProjectPcre2ReadsAlikeEverywhere
{
    public function run(string $subject): bool
    {
        return 1 === preg_match('/a{,3}/', $subject);
    }
}

?>
