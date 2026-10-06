<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class PhpdocStringSubject
{
    /**
     * @param string $subject
     */
    public function run($subject): string
    {
        return preg_replace('/foo/', 'X', $subject);
    }
}

?>
-----
<?php

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector\Fixture;

final class PhpdocStringSubject
{
    /**
     * @param string $subject
     */
    public function run($subject): string
    {
        return \str_replace('foo', 'X', $subject);
    }
}

?>
