<?php

// A fixed sample of the repository corpus, linted by the benchmarks.

function lint_fixture_2(string $subject): void
{
    $matches = [];
    preg_match('/Host unknown/is', $subject);
    preg_replace('/\\[\\$?([^\\.]+)\\.([^\\.]+):\\.([^\\.]+)\\]/miu', '', $subject);
    preg_split('|^(\\w+_[\\w_]+)\\\\customfield\\\\([\\w_]+)_handler$|', $subject);
    preg_match_all('/(for\\([^;\\{]*;[^;\\{]*;(?:[^;\\{]*|[^;\\{]*function[^;\\{]*(\\{([^\\{\\}]*(?-2))*[^\\{\\}]*\\})?[^;\\{]*)\\));(\\}|$)/s', $subject, $matches);
    preg_match('/[\\r]+/si', $subject);
    preg_replace('/[\\,\\s]+/si', '', $subject);
    preg_split('/^<span(\\s+lang="[a-zA-Z0-9_-]+"|\\s+class="multilang"){2}\\s*>$/u', $subject);
    preg_match_all('/BEGIN PUBLIC KEY\\-+(?:\\s|\\n|\\r)+([^\\-]+)(?:\\s|\\n|\\r)*\\-+END PUBLIC KEY/i', $subject, $matches);
    preg_match('/(.+)#(.*)/is', $subject);
    preg_replace('#<.+?>#si', '', $subject);
    preg_split('/### Sample\\n\\n(```(.|\\n)*```)/', $subject);
    preg_match_all('{(\\.9{7})+}', $subject, $matches);
    preg_match('/[\\n\\r\\s\\t]+/', $subject);
}
