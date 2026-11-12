<?php

$pattern = (string) getenv('PATTERN');
preg_match($pattern, (string) $subject);
