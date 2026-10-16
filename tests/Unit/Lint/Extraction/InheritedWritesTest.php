<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A parent class may write a protected property of its final child: under
 * a computed name, through a by-reference foreach or array_walk() over
 * $this, from this file or another. A trait the file does not declare may
 * write any property of the class using it. Such a property is not read;
 * PHP 8.4 gives "/(/" for $this->re in each case below.
 */
final class InheritedWritesTest extends TestCase
{
    use ExtractsWithBothStrategies;

    private const CHILD = <<<'PHP'

        final class D extends P
        {
            protected $re = '/a/';

            public function __construct()
            {
                $this->clob();
            }

            public function m($s)
            {
                return preg_match($this->re, $s);
            }
        }

        PHP;

    /**
     * @return iterable<string, array{parent: string}>
     */
    public static function provideParents(): iterable
    {
        yield 'a write under a computed name' => ['parent' => "class P\n{\n    public function clob() { \$this->set('re', '/(/'); }\n    public function set(\$n, \$v) { \$this->\$n = \$v; }\n}\n"];
        yield 'a by-reference foreach over $this' => ['parent' => "class P\n{\n    public function clob() { foreach (\$this as &\$v) { \$v = '/(/'; } }\n}\n"];
        yield 'array_walk() over $this' => ['parent' => "class P\n{\n    public function clob() { array_walk(\$this, function (&\$v) { \$v = '/(/'; }); }\n}\n"];
    }

    #[Test]
    #[DataProvider('provideParents')]
    public function test_a_protected_property_of_a_class_extending_another_is_not_read(string $parent): void
    {
        $this->assertExtractedByBoth([], "<?php\n".$parent.self::CHILD);
    }

    #[Test]
    public function test_a_parent_in_another_file_may_write_a_protected_property(): void
    {
        $this->assertExtractedByBoth([], [
            'Base.php' => "<?php\nabstract class Base\n{\n    public function __construct() { \$this->pattern = '/^[a-z]+\$/'; }\n}\n",
            'Child.php' => "<?php\nfinal class Child extends Base\n{\n    protected \$pattern = '/(/';\n    public function check(\$s) { return preg_match(\$this->pattern, \$s); }\n}\n",
        ], file: 'Child.php');
    }

    #[Test]
    public function test_a_trait_declared_in_another_file_may_write_any_property(): void
    {
        $this->assertExtractedByBoth([], [
            'Configures.php' => "<?php\ntrait Configures\n{\n    public function configure() { \$this->re = '/^[0-9]+\$/'; }\n}\n",
            'UsesTrait.php' => "<?php\nfinal class UsesTrait\n{\n    use Configures;\n    private \$re = '/[/';\n    public function __construct() { \$this->configure(); }\n    public function check(\$s) { return preg_match(\$this->re, \$s); }\n}\n",
        ], file: 'UsesTrait.php');
    }

    #[Test]
    public function test_a_trait_using_a_trait_of_another_file_may_write_any_property(): void
    {
        $this->assertExtractedByBoth([], <<<'PHP'
            <?php
            trait Local
            {
                use Elsewhere;
            }

            final class UsesTrait
            {
                use Local;
                private $re = '/local/';
                public function check($s) { return preg_match($this->re, $s); }
            }

            PHP);
    }

    #[Test]
    public function test_a_trait_of_the_file_writing_no_property_leaves_it_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/kept/', 'at' => "'/kept/'", 'location' => 'via $this->re'],
            ['pattern' => '/kept-too/', 'at' => "'/kept-too/'", 'location' => 'via $this->other'],
        ], <<<'PHP'
            <?php
            namespace App;

            trait Helps
            {
                public function help(): string { return 'help'; }
            }

            final class Matcher
            {
                use Helps, \App\Helps {
                    help as protected aid;
                }

                private $re = '/kept/';
                protected $other = '/kept-too/';
                public function check($s) { return preg_match($this->re, $s) + preg_match($this->other, $s); }
            }

            PHP);
    }

    #[Test]
    public function test_a_private_property_of_a_class_extending_another_is_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/private/', 'at' => "'/private/'", 'location' => 'via $this->re'],
        ], <<<'PHP'
            <?php
            final class Child extends \Exception
            {
                private $re = '/private/';
                public function check($s) { return preg_match($this->re, $s); }
            }

            PHP);
    }
}
