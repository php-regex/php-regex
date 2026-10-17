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

namespace PHPRegex\Tests\Unit\Lsp\Protocol;

use PHPRegex\LanguageServer\Protocol\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    #[Test]
    public function test_response_class_exists(): void
    {
        $this->assertTrue(class_exists(Response::class));
    }

    #[Test]
    public function test_response_has_success_method(): void
    {
        $reflection = new \ReflectionClass(Response::class);

        $this->assertTrue($reflection->hasMethod('success'));
        $this->assertTrue($reflection->getMethod('success')->isStatic());
        $this->assertTrue($reflection->getMethod('success')->isPublic());
    }

    #[Test]
    public function test_response_has_error_method(): void
    {
        $reflection = new \ReflectionClass(Response::class);

        $this->assertTrue($reflection->hasMethod('error'));
        $this->assertTrue($reflection->getMethod('error')->isStatic());
        $this->assertTrue($reflection->getMethod('error')->isPublic());
    }

    #[Test]
    public function test_response_has_notification_method(): void
    {
        $reflection = new \ReflectionClass(Response::class);

        $this->assertTrue($reflection->hasMethod('notification'));
        $this->assertTrue($reflection->getMethod('notification')->isStatic());
        $this->assertTrue($reflection->getMethod('notification')->isPublic());
    }

    #[Test]
    public function test_success_method_signature(): void
    {
        $reflection = new \ReflectionMethod(Response::class, 'success');
        $parameters = $reflection->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertSame('id', $parameters[0]->getName());
        $this->assertSame('result', $parameters[1]->getName());
    }

    #[Test]
    public function test_error_method_signature(): void
    {
        $reflection = new \ReflectionMethod(Response::class, 'error');
        $parameters = $reflection->getParameters();

        $this->assertCount(3, $parameters);
        $this->assertSame('id', $parameters[0]->getName());
        $this->assertSame('code', $parameters[1]->getName());
        $this->assertSame('message', $parameters[2]->getName());
    }

    #[Test]
    public function test_an_error_holds_its_code_and_message_only(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        Response::writeTo($stream);

        try {
            Response::error(7, -32601, 'Method not found: foo');
        } finally {
            Response::writeTo(null);
        }

        rewind($stream);
        $written = (string) stream_get_contents($stream);
        $body = substr($written, (int) strpos($written, "\r\n\r\n") + 4);

        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 7, 'error' => ['code' => -32601, 'message' => 'Method not found: foo']],
            json_decode($body, true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function test_notification_method_signature(): void
    {
        $reflection = new \ReflectionMethod(Response::class, 'notification');
        $parameters = $reflection->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertSame('method', $parameters[0]->getName());
        $this->assertSame('params', $parameters[1]->getName());
        $this->assertTrue($parameters[1]->isDefaultValueAvailable());
        $this->assertNull($parameters[1]->getDefaultValue());
    }

    #[Test]
    public function test_response_is_final(): void
    {
        $reflection = new \ReflectionClass(Response::class);

        $this->assertTrue($reflection->isFinal());
    }

    /**
     * The server also asks the client to do things: a request carries the
     * id its answer must repeat, beside the method and its params.
     */
    #[Test]
    public function test_response_has_request_method(): void
    {
        $reflection = new \ReflectionClass(Response::class);

        $this->assertTrue($reflection->hasMethod('request'));
        $this->assertTrue($reflection->getMethod('request')->isStatic());
        $this->assertTrue($reflection->getMethod('request')->isPublic());
    }

    #[Test]
    public function test_request_method_signature(): void
    {
        $reflection = new \ReflectionMethod(Response::class, 'request');
        $parameters = $reflection->getParameters();

        $this->assertCount(3, $parameters);
        $this->assertSame('id', $parameters[0]->getName());
        $this->assertSame('method', $parameters[1]->getName());
        $this->assertSame('params', $parameters[2]->getName());
    }

    #[Test]
    public function test_a_request_frame_carries_its_id_and_params(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        Response::writeTo($stream);

        try {
            Response::request(41, 'client/registerCapability', ['registrations' => []]);
        } finally {
            Response::writeTo(null);
        }

        rewind($stream);
        $written = (string) stream_get_contents($stream);
        $body = substr($written, (int) strpos($written, "\r\n\r\n") + 4);

        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 41, 'method' => 'client/registerCapability', 'params' => ['registrations' => []]],
            json_decode($body, true, 512, \JSON_THROW_ON_ERROR),
        );
    }
}
