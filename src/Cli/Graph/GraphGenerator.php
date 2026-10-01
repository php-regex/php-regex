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

namespace PHPRegex\Cli\Graph;

use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Cli\CliException;

final class GraphGenerator
{
    public function generate(Nfa $nfa, string $format): string
    {
        return match ($format) {
            'dot', 'graphviz' => (new GraphvizDumper())->dump($nfa),
            'mermaid' => (new MermaidDumper())->dump($nfa),
            default => throw new CliException("Unsupported format: $format"),
        };
    }
}
