<?php

preg_match(<<<'RE'
/((a+)+b/
RE
    , (string) $subject);

preg_match('/a/' . 'b+', (string) $subject);
