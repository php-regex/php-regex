<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use PHPRegex\Laravel\Facades\Regex;
use Tests\TestCase;

final class RegexBridgeTest extends TestCase
{
    public function test_facade_alias_resolves_and_validates_patterns(): void
    {
        $result = \Regex::validate('/\d{4}-\d{2}-\d{2}/');

        $this->assertTrue($result->isValid);
        $this->assertTrue(Regex::validate('/^[a-z]+$/')->isValid);
    }

    public function test_artisan_registers_the_bridge_commands(): void
    {
        $expected = [
            'regex:compare',
            'regex:explain',
            'regex:lint',
            'regex:routes',
            'regex:transpile',
        ];

        foreach ($expected as $name) {
            $this->assertArrayHasKey(
                $name,
                Artisan::all(),
                \sprintf('The artisan console did not register %s.', $name),
            );
        }
    }

    public function test_explain_command_explains_a_pattern(): void
    {
        $this->artisan('regex:explain', ['pattern' => '/ab+c/'])
            ->assertExitCode(0);
    }

    public function test_demo_endpoint_reports_pattern_validity(): void
    {
        $response = $this->get('/regex-demo');

        $response->assertOk();

        $report = $response->json();

        $this->assertTrue($report['date']['valid']);
        $this->assertFalse($report['broken']['valid']);
        $this->assertNotSame('', $report['broken']['error']);
    }

    public function test_slug_route_requirement_rejects_invalid_characters(): void
    {
        $this->get('/regex-demo/slug/hello-world')->assertOk();
        $this->get('/regex-demo/slug/not_a_slug')->assertNotFound();
    }
}
