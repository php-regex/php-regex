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

namespace PHPRegex\LanguageServer\Protocol;

/**
 * The caps a frame is read under.
 *
 * A body past maxContentLength is refused and its declared bytes drained,
 * and the session goes on; a header block past maxHeaderBlock is a flood
 * whose end never comes, so the input behind it is never read again.
 *
 * @internal
 */
final class MessageLimits
{
    /**
     * The most body bytes a frame may declare: documents are full-synced,
     * so large real files must pass.
     */
    public const MAX_CONTENT_LENGTH = 16 * 1024 * 1024;

    /**
     * The most bytes a header block may count, through the blank line that
     * ends it.
     */
    public const MAX_HEADER_BLOCK = 8 * 1024;

    public function __construct(public readonly int $maxContentLength = self::MAX_CONTENT_LENGTH, public readonly int $maxHeaderBlock = self::MAX_HEADER_BLOCK) {}
}
