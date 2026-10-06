<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class PhpdocStringSubject
{
    /**
     * @param string $subject
     */
    public function run($subject): array
    {
        return preg_split('/,/', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregSplitToExplodeRector\Fixture;

final class PhpdocStringSubject
{
    /**
     * @param string $subject
     */
    public function run($subject): array
    {
        return \explode(',', $subject);
    }
}

?>
