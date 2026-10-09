<?php

// A fixed sample of the repository corpus, linted by the benchmarks.

function lint_fixture_3(string $subject): void
{
    $matches = [];
    preg_match('~^\\w(?:(?!\\/\\.{0,2}\\/)[\\w\\/\\-\\.])*$~', $subject);
    preg_replace('/^(\\s*<p>\\s*)([^:]+):\\s*/sxi', '', $subject);
    preg_split('![\\w\\d-]+: .+!', $subject);
    preg_match_all('/^\\s*<<(?P<struct>.*)/is', $subject, $matches);
    preg_match('/([a-zd])([A-Z])/', $subject);
    preg_replace('/\\<(div|span) class=(\\")?required(\\")?\\s?>\\*<\\/(div|span)>/si', '', $subject);
    preg_split('\'fopen\\([^\\)]*\\$[^\\)]*\\)\'si', $subject);
    preg_match_all('/\\[(\\w+)\\:([\\w\\-\\d]*)\\:([^\\]]*)\\]/', $subject, $matches);
    preg_match('#/\\* Laminas_Code_Generator_FileGenerator-DocBlockMarker \\*/#m', $subject);
    preg_replace('/^(?<class>.+)(_[^_]+)$/i', '', $subject);
    preg_split('/<([\\w\\d])/', $subject);
    preg_match_all('/(^\\s*\\*\\/)/i', $subject, $matches);
    preg_match('/{\\s*[^$]+/s', $subject);
}
