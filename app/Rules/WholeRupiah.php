<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A whole number of rupiah, written with or without thousands separators.
 *
 * This exists because the amount field cannot use ->numeric() or ->integer()
 * any more. Both of those set the input's type to "number" through
 * TextInput::getType(), and a number input refuses to display a thousands
 * separator — the browser blanks the field instead. Dropping them also drops
 * the `integer`, `min` and `max` rules they registered, so the guard is
 * reassembled here rather than left to the column.
 *
 * The one thing it has to catch is a decimal. The dot means two different
 * things: thousands in 1.500.000, an English decimal point in 1500.75.
 * Stripping dots blindly turns the second into 150075 — a hundredfold error
 * nothing downstream can detect, because the result is a valid integer.
 *
 * So the question asked here is narrow: *could this be a decimal?* A dot
 * followed by one or two digits at the very end could be. Anything else could
 * not, whatever its grouping, and is simply regrouped. That matters more than
 * it sounds: appending a digit to an already-formatted 10.000 gives 10.0000,
 * which is not valid grouping but is unmistakably 100.000 — demanding tidy
 * groups would reject the user's own editing halfway through.
 *
 * The column is an unsignedBigInteger holding whole rupiah — see the Keuangan
 * section of CLAUDE.md for why it is not a decimal.
 */
class WholeRupiah implements ValidationRule
{
    /**
     * Digits, optionally broken up by dots. Rejects a leading or trailing dot,
     * a doubled dot, a comma, a sign and anything non-numeric.
     */
    public const SHAPE = '/^\d+(\.\d+)*$/';

    /**
     * A dot with one or two digits after it, at the end of the value: the only
     * shape that could be a decimal rather than a separated thousand.
     */
    public const DECIMAL_TAIL = '/\.\d{1,2}$/';

    public function __construct(
        protected int $min = 1,
        protected int $max = 999_999_999_999,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! static::isUnambiguous($value)) {
            $fail('Jumlah harus rupiah penuh, tanpa sen. Contoh: 100.000');

            return;
        }

        $amount = static::toInteger($value);

        if ($amount < $this->min) {
            $fail('Jumlah minimal Rp '.number_format($this->min, 0, ',', '.').'.');

            return;
        }

        if ($amount > $this->max) {
            $fail('Jumlah maksimal Rp '.number_format($this->max, 0, ',', '.').'.');
        }
    }

    /**
     * Whether this value can be read as whole rupiah in exactly one way.
     *
     * Shared with the form so validation and the on-blur regrouping cannot
     * disagree about what is safe to reformat — regrouping something this
     * returns false for is how Rp 1.500,75 would silently become Rp 150.075.
     */
    public static function isUnambiguous(mixed $value): bool
    {
        // Anything that is not a string or an int never came from the field.
        // A float here means a decimal was submitted, which is the case this
        // whole class exists to refuse.
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        $raw = trim((string) $value);

        return $raw !== ''
            && preg_match(self::SHAPE, $raw) === 1
            && preg_match(self::DECIMAL_TAIL, $raw) === 0;
    }

    /**
     * The integer behind a value, with any separators removed. Kept next to the
     * patterns so the form's dehydration cannot drift away from the rule.
     */
    public static function toInteger(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $raw = trim((string) $value);

        return $raw === '' ? null : (int) str_replace('.', '', $raw);
    }

    /**
     * Group an integer the Indonesian way. Null and empty pass through so the
     * field can render blank on a fresh form.
     */
    public static function format(mixed $amount): ?string
    {
        if (blank($amount)) {
            return null;
        }

        return number_format((int) static::toInteger($amount), 0, ',', '.');
    }
}
