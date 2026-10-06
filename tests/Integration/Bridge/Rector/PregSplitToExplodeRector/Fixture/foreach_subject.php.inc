<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class ForeachSubject
{
    public function run(string $subject): array
    {
        $parts = [];
        foreach (preg_split('/,/', $subject) as $part) {
            $parts[] = $part;
        }

        return $parts;
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class ForeachSubject
{
    public function run(string $subject): array
    {
        $parts = [];
        foreach (\explode(',', $subject) as $part) {
            $parts[] = $part;
        }

        return $parts;
    }
}

?>
