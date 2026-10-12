<?php

declare(strict_types=1);

namespace App\Tests;

use PHPRegex\Toolkit\Regex;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class RegexBridgeTest extends KernelTestCase
{
    public function test_bundle_wires_the_regex_service(): void
    {
        $regex = static::getContainer()->get(Regex::class);

        self::assertInstanceOf(Regex::class, $regex);

        $result = $regex->validate('/\d{4}-\d{2}-\d{2}/');

        self::assertTrue($result->isValid);
    }

    public function test_kernel_registers_every_bridge_command(): void
    {
        static::bootKernel();
        $registered = (new Application(static::$kernel))->all();

        $expected = [
            'regex:analyze',
            'regex:compare',
            'regex:lint',
            'regex:routes',
            'regex:security',
            'regex:transpile',
        ];

        foreach ($expected as $name) {
            self::assertArrayHasKey(
                $name,
                $registered,
                \sprintf('The console pass did not register %s.', $name),
            );
        }
    }

    public function test_demo_endpoint_reports_pattern_validity(): void
    {
        $response = static::bootKernel()->handle(Request::create('/regex-demo'));

        self::assertSame(200, $response->getStatusCode());

        $report = \json_decode((string) $response->getContent(), true);

        self::assertTrue($report['date']['valid']);
        self::assertFalse($report['broken']['valid']);
        self::assertNotSame('', $report['broken']['error']);
    }

    public function test_slug_requirement_rejects_characters_outside_the_class(): void
    {
        self::assertSame(
            200,
            static::bootKernel()->handle(Request::create('/slug/hello-world'))->getStatusCode(),
        );
        self::assertSame(
            404,
            static::bootKernel()->handle(Request::create('/slug/not_a_slug'))->getStatusCode(),
        );
    }
}
