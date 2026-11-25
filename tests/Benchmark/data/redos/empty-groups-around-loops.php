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

return [
    'pattern' => '/^(?:(?:u7{0})*)(?:(?:v7{0})*)(?:(?:w7{0})*)(?:(?:x7{0})*)(?:(?:y7{0})*)(?:(?:b8{0})*)(?:(?:c8{0})*)(?:(?:d8{0})*)(?:(?:e8{0})*)(?=(?:f8{0}|)(?:g8{0}|)(?:h8{0}|)(?:i8{0}|)(?:j8{0}|)(?:k8{0}|)(?:l8{0}|)(?:m8{0}|)(?:n8{0}|)(?:o8{0}|).)(?:p8{0}|)(?:q8{0}|)(?:r8{0}|)(?:s8{0}|)(?:t8{0}|)(?:u8{0}|)(?:v8{0}|)(?:w8{0}|)(?:x8{0}|)(?:y8{0}|)(?:(?:(?:b9{0})*)(?:(?:c9{0})*)(?:(?:d9{0})*)(?:(?:e9{0})*)(?:(?:f9{0})*)(?:(?:g9{0})*)(?:xa?)+(?:h9{0}|))*(?:i9{0}|)(?:j9{0}|)(?:k9{0}|)(?:l9{0}|)(?=(?:m9{0}|\\K)(?:n9{0}|\\K)(?:o9{0}|\\K)(?:p9{0}|\\K)(?:q9{0}|\\K)(?:r9{0}|\\K)(?:s9{0}|\\K)(?:t9{0}|\\K)(?:u9{0}|\\K)(?:v9{0}|\\K)[ab])(?:w9{0}|\\K)(?:x9{0}|\\K)(?:y9{0}|\\K)(?:b10{0}|\\K)(?:c10{0}|\\K)(?:d10{0}|\\K)(?:e10{0}|\\K)(?:f10{0}|\\K)(?:g10{0}|\\K)(?:h10{0}|\\K)./i',
    'origin' => 'synthetic',
    'note' => 'Groups that read nothing, some of them with \\K, around two loops and in two lookaheads: the count of the paths one attempt tries at once meets the same sets of states at many steps, until the budget runs out.',
    'expect' => 'budget_exceeded',
];
