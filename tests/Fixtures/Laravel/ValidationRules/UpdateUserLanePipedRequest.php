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

namespace PHPRegex\Tests\Fixtures\Laravel\ValidationRules;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateUserLanePipedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|regex:/^#[0-9a-f]{6}$/',
            'direction' => 'required|regex:/^(?:north|south)-\d+$/',
            'slug' => 'required|regex:{^[a-z]+$}',
            'label' => 'nullable|not_regex:(^admin$)i',
            'code' => "regex:/^[A-Z]{3}$/|not_regex:<^XXX$>",
        ];
    }
}
