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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Parser\Validation\Validator;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class ValidatorCacheTest extends TestCase
{
    public function test_unicode_property_cache_hit(): void
    {
        $this->expectNotToPerformAssertions();
        $regex = Regex::create();
        $validator = new Validator();

        // 1st pass: fills the cache
        $ast1 = $regex->parse('/\p{L}/');
        $ast1->accept($validator);

        // 2nd pass: uses the cache (covers "cache hit" branch)
        $ast2 = $regex->parse('/\p{L}/');
        $ast2->accept($validator);
    }
}
