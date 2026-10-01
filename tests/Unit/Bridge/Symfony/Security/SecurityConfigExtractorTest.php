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

namespace PhpRegex\Tests\Unit\Bridge\Symfony\Security;

use PhpRegex\Symfony\Security\SecurityConfigExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SecurityConfigExtractorTest extends TestCase
{
    #[Test]
    public function test_extracts_access_control_and_firewalls(): void
    {
        $path = dirname(__DIR__, 4).'/Fixtures/Symfony/security_access_control.yaml';

        $extractor = new SecurityConfigExtractor();
        $result = $extractor->extract($path);

        $this->assertCount(2, $result['accessControl']);
        $this->assertSame($path, $result['accessControl'][0]['file']);
        $this->assertSame(3, $result['accessControl'][0]['line']);
        $this->assertSame('^/api', $result['accessControl'][0]['path']);
        $this->assertSame(['PUBLIC_ACCESS'], $result['accessControl'][0]['roles']);

        $this->assertSame($path, $result['accessControl'][1]['file']);
        $this->assertSame(4, $result['accessControl'][1]['line']);
        $this->assertSame('^/api/admin', $result['accessControl'][1]['path']);
        $this->assertSame(['ROLE_ADMIN'], $result['accessControl'][1]['roles']);
        $this->assertSame(['GET', 'POST'], $result['accessControl'][1]['methods']);

        $this->assertCount(2, $result['firewalls']);
        $this->assertSame('main', $result['firewalls'][0]['name']);
        $this->assertSame($path, $result['firewalls'][0]['file']);
        $this->assertSame(9, $result['firewalls'][0]['line']);
        $this->assertSame('^/api/(a+)+$', $result['firewalls'][0]['pattern']);

        $this->assertSame('dev', $result['firewalls'][1]['name']);
        $this->assertSame($path, $result['firewalls'][1]['file']);
        $this->assertSame(10, $result['firewalls'][1]['line']);
        $this->assertSame('dev.matcher', $result['firewalls'][1]['requestMatcher']);
    }
}
