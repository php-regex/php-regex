<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Lsp\Protocol;

use PhpRegex\LanguageServer\Protocol\Response;
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

        $this->assertCount(4, $parameters);
        $this->assertSame('id', $parameters[0]->getName());
        $this->assertSame('code', $parameters[1]->getName());
        $this->assertSame('message', $parameters[2]->getName());
        $this->assertSame('data', $parameters[3]->getName());
        $this->assertTrue($parameters[3]->isDefaultValueAvailable());
        $this->assertNull($parameters[3]->getDefaultValue());
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
}
