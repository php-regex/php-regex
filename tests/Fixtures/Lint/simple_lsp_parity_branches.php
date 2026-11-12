<?php

function parityBranches(string $subject, bool $flag, int $kind): int
{
    return preg_match($flag
        ? '/(then/'
        : '/(else/', $subject)
        + preg_match(match ($kind) {
            1 => '/(one/',
            default => '/(other/',
        }, $subject);
}
