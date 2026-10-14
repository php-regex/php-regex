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

namespace PHPRegex\Linter;

/**
 * Where a lint run stands, step by step: the files found, the declarations
 * and the patterns read, then the analysis and the optimizations.
 *
 * Workers tick while the run waits on them; a run in one process advances
 * item by item and never ticks, so one slow pattern holds its clock still.
 *
 * @internal
 */
interface LintProgress
{
    /**
     * The files the run lints, as they are found: no total until the walk ends.
     */
    public const STEP_FILES = 'files';

    /**
     * The project and vendor files read for the pattern functions they declare.
     */
    public const STEP_DECLARATIONS = 'declarations';

    /**
     * The files read for the namespaced functions named as a pattern function.
     */
    public const STEP_FUNCTIONS = 'functions';

    /**
     * The linted files read for their patterns.
     */
    public const STEP_PATTERNS = 'patterns';

    public const STEP_ANALYSIS = 'analysis';

    public const STEP_OPTIMIZATIONS = 'optimizations';

    /**
     * A step starts, its count back at 0; a null total is not known yet.
     */
    public function step(string $step, ?int $total): void;

    public function advance(int $count = 1): void;

    /**
     * Nothing advanced, but the run is alive: the clock moves on.
     */
    public function tick(): void;

    /**
     * How many workers share the current step.
     */
    public function workers(int $count): void;

    public function finish(): void;
}
