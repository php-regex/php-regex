<?php

namespace App\Support;

// "use Str;" in a class body uses a trait: Str still names App\Support\Str.
final class Mailer
{
    use Str;
    use Helpers, Other {
        Helpers::send insteadof Other;
    }

    public function send(string $subject): void
    {
        Str::matches($subject, '/in-method/');

        $check = function () use ($subject) {
            return Str::matches($subject, '/in-closure/');
        };
    }
}

enum Status
{
    use Str;

    public function check(string $subject): bool
    {
        return Str::matches($subject, '/in-enum/');
    }
}
