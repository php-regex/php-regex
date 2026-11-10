<?php

function parityComposed(string $subject): int
{
    $open = '((a+)+b';

    return preg_match('/' . $open . '/', $subject)
        + preg_match("/{$open}/", $subject)
        + preg_match(sprintf('/%s/', $open), $subject)
        + preg_match(implode('|', ['/((a', 'b/']), $subject);
}
