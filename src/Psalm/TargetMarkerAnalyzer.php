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

namespace PHPRegex\Psalm;

use PHPRegex\Linter\Extraction\TargetMarkers;
use Psalm\CodeLocation\Raw;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterFileAnalysisEvent;

/**
 * Reports, once per file, each "@regex-target" marker whose constraint
 * cannot be read and each that keeps no version of the series Psalm's PHP
 * is judged for, as InvalidRegexTarget on the marker. The calls a marker
 * covers are judged by PregCallAnalyzer, for the versions CallTarget
 * reads.
 *
 * @internal
 */
final class TargetMarkerAnalyzer implements AfterFileAnalysisInterface
{
    public static function afterAnalyzeFile(AfterFileAnalysisEvent $event): void
    {
        $source = $event->getStatementsSource();
        $codebase = $event->getCodebase();
        $path = $source->getFilePath();
        $content = $codebase->getFileContents($path);
        foreach (TargetMarkers::read($content) as $marker) {
            $message = CallTarget::markerError($marker, $codebase);
            if (null !== $message) {
                IssueBuffer::maybeAdd(
                    new InvalidRegexTarget($message, new Raw($content, $path, $source->getFileName(), $marker->offset, $marker->offset + \strlen($marker->marker))),
                    $source->getSuppressedIssues(),
                );
            }
        }
    }
}
