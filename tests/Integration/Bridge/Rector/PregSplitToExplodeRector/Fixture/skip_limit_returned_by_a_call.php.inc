<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class SkipLimitReturnedByACall
{
    public function run(string $subject): array
    {
        return preg_split('/,/', $subject, self::limit());
    }

    /**
     * @return -1
     */
    private static function limit(): int
    {
        return -1;
    }
}

?>
