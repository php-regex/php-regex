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

namespace PHPRegex\Linter\Source;

use PHPRegex\Linter\PatternExtractor;

/**
 * Extracts regex patterns from PHP source files.
 *
 * @internal
 */
final readonly class PhpFilePatternSource implements PatternSourceInterface
{
    public function __construct(private PatternExtractor $extractor) {}

    public function getName(): string
    {
        return 'php';
    }

    public function isSupported(): bool
    {
        return true;
    }

    public function extract(PatternSourceContext $context): array
    {
        $progress = \is_callable($context->progress) ? $context->progress : null;

        return $this->extractor->extract(
            $context->paths,
            $context->excludePaths,
            $progress,
            $context->workers,
            $context->declarationPaths,
            $context->vendorPaths,
        );
    }
}
