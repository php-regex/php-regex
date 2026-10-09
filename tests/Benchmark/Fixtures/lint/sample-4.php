<?php

// A fixed sample of the repository corpus, linted by the benchmarks.

function lint_fixture_4(string $subject): void
{
    $matches = [];
    preg_match('/^(\\w)*/i', $subject);
    preg_replace('#^(?:\\.{2,})+/#', '', $subject);
    preg_split('/([\\.|\\,|\\:|\\¡|\\¿|\\>|\\{|\\(]?)#{1}(\\w*)([\\.|\\,|\\:|\\!|\\?|\\>|\\}|\\)]?)\\s/i', $subject);
    preg_match_all('/(\\d+)[%]/i', $subject, $matches);
    preg_match('/<use( [^>]*)xlink:href\\s*=\\s*["\']#([^>]*?)["\']([^>]*)\\/>/si', $subject);
    preg_replace('/(?<start>[^<]*)?(?<broken>(?:<\\/\\w+(?:\\s+\\w+=\\"[^"]+\\")*+[^<]+>)+)(?<end>.*)/u', '', $subject);
    preg_split('/^(\\w+)\\s*\\([^()]*\\)\\s*([<>=!]+.*)?$/', $subject);
    preg_match_all('/(?<separator>[^a-zA-Z0-9_{}])+(?<part>[a-zA-Z0-9_{}]*)/', $subject, $matches);
    preg_match('/="(\\/[^"]+)"/ism', $subject);
    preg_replace('~(\\s|\\x{3164}|\\x{1160})+~u', '', $subject);
    preg_split('/([0-9]{1,2}\\ [A-Z]{2,3}\\ [0-9]{2,4}\\ [0-9]{2}\\:[0-9]{2}\\:[0-9]{2}\\ [A-Z]{2}\\ \\-[0-9]{2}\\:[0-9]{2}\\ \\([A-Z]{2,3}\\ \\-[0-9]{2}:[0-9]{2}\\))+$/i', $subject);
    preg_match_all('!^DB://(\\w+)/(\\d+)(?:/([0-9a-fA-F]+)|)$!', $subject, $matches);
}
