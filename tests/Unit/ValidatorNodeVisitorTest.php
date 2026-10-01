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

namespace PhpRegex\Tests\Unit;

use PhpRegex\Parser\Exception\SemanticErrorException;
use PhpRegex\Parser\Validation\Validator;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class ValidatorNodeVisitorTest extends TestCase
{
    public function test_validator_visitor_rejects_invalid_quantifier_range(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/a{2,1}/');
        $visitor = new Validator();

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid quantifier range');

        $ast->accept($visitor);
    }
}
