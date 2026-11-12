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

namespace PHPRegex\Tests\Unit\Cli;

use PHPRegex\Cli\Command\AbstractCommand;
use PHPRegex\Cli\Command\TargetRange;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every command's banner names the target it resolved as lint's Target row
 * names it, never the raw option: the PHP and PCRE2 judged, where they came
 * from, and the later versions of a range.
 */
final class CommandTargetMetaTest extends TestCase
{
    use PcreRule;

    #[Test]
    public function test_the_banner_names_the_target_resolved_and_its_later_versions(): void
    {
        $meta = $this->targetMeta(ProjectTarget::fromSources(['--php-version' => '>=8.4'], ['--pcre-version' => null], null, []));

        $this->assertSame(['Target PHP' => 'PHP 8.4, PCRE2 '.self::judgedPcre(80400).' (--php-version); also PHP 8.4.25, 8.5, 8.5.10'], $meta);
    }

    #[Test]
    public function test_the_banner_names_the_pcre2_release_pinned_in_the_label(): void
    {
        $meta = $this->targetMeta(ProjectTarget::fromSources(['--php-version' => '8.4'], ['--pcre-version' => '10.42'], null, []));

        $this->assertSame(['Target PHP' => 'PHP 8.4, PCRE2 10.42 (--php-version; --pcre-version)'], $meta);
    }

    #[Test]
    public function test_the_banner_names_nothing_for_the_running_php(): void
    {
        $this->assertSame([], $this->targetMeta(ProjectTarget::fromSources([], [], null, [])));
    }

    /**
     * @return array<string, string>
     */
    private function targetMeta(ProjectTarget $target): array
    {
        $command = new class extends AbstractCommand {
            public function getName(): string
            {
                return 'probe';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getDescription(): string
            {
                return '';
            }

            public function run(Input $input, Output $output): int
            {
                return 0;
            }

            /**
             * @return array<string, string>
             */
            public function meta(Output $output, TargetRange $range): array
            {
                return $this->targetMeta($output, $range);
            }
        };

        $regex = Regex::create(['cache' => null] + $target->regexOptions());

        return $command->meta(OutputFactory::create(), new TargetRange($target, $regex, $target->rangeParsers($regex->parser())));
    }
}
