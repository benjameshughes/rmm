<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Cron\CronExpression as CronParser;
use Illuminate\Contracts\Validation\ValidationRule;

final class CronExpression implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! CronParser::isValidExpression($value)) {
            $fail('The :attribute must be a valid cron expression.');
        }
    }
}
