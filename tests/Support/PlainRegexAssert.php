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

namespace PHPRegex\Tests\Support;

use PHPRegex\PHPUnit\RegexAssertions;
use PHPUnit\Framework\Assert;

/**
 * The assertions used where no TestCase exists: a bare subclass of Assert is
 * the narrowest surface the trait still has to work on, so helper objects
 * and assertions in arrange-only code can lean on it too.
 */
final class PlainRegexAssert extends Assert
{
    use RegexAssertions;
}
