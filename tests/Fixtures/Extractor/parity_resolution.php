<?php

namespace App\Parity;

const NAMESPACED = '/namespace-const/';

final class Resolution
{
    private const RE = '/self-constant/';
    private const PART = 'abc';
    private const BUILT = '/'.self::PART.'+/i';
    private const LIST = ['/list-a/', '/list-b/'];
    private string $property = '/property/';

    public function run(string $subject): void
    {
        preg_match(NAMESPACED, $subject);
        preg_match(self::RE, $subject);
        preg_match(self::BUILT, $subject);
        preg_replace(self::LIST, '', $subject);
        preg_match($this->property, $subject);
        preg_match(self::RE, $subject);
    }
}

function resolution(string $subject, bool $flag): int
{
    $re = '/variable-a/';
    if ($flag) {
        $re = '/variable-b/';
    }

    return preg_match($re, $subject);
}
