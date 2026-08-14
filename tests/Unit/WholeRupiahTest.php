<?php

namespace Tests\Unit;

use App\Rules\WholeRupiah;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * WholeRupiah is the amount field's only remaining guard. The field cannot use
 * ->numeric() or ->integer() — either one forces type="number", which cannot
 * show a thousands separator — so the `integer`, `min` and `max` rules they
 * registered now live here. It needs no database, so it is tested directly.
 *
 * The case that matters is "1500.75": a dot means thousands in 1.500.000 and
 * decimals in 1500.75, and stripping it blindly turns the second into 150075.
 * That error survives every later check, because the result is a valid integer.
 */
class WholeRupiahTest extends TestCase
{
    /**
     * @return array<string, array{mixed}>
     */
    public static function acceptedAmounts(): array
    {
        return [
            'bare digits' => ['1500000'],
            'grouped' => ['1.500.000'],
            'grouped thousands' => ['100.000'],
            'single group' => ['1.000'],
            'the minimum' => ['1'],
            'an integer, not a string' => [1_500_000],
            'the maximum' => ['999.999.999.999'],
            'padded by the browser' => [' 250.000 '],
            // What an already-grouped 10.000 becomes when one more digit is
            // typed onto the end. Untidy, but it cannot mean anything except
            // 100.000, so refusing it would only interrupt the typing.
            'a digit appended to a grouped value' => ['10.0000'],
            'several digits appended' => ['1.500.00000'],
            'a group of four' => ['1.5000'],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function refusedAmounts(): array
    {
        return [
            // The whole reason this rule exists.
            'an English decimal' => ['1500.75'],
            'an Indonesian decimal' => ['1500,75'],
            // A float never comes from the field. If one arrives, a decimal
            // was submitted.
            'a float' => [1500.75],
            // The only shape that could be a decimal: one or two digits after
            // a dot, at the very end.
            'a one-digit tail' => ['1.5'],
            'a two-digit tail' => ['1.50'],
            'a decimal tail after real grouping' => ['1.500.00'],
            'a trailing separator' => ['1.500.'],
            'a doubled separator' => ['1..000'],
            'a leading separator' => ['.500'],
            'zero, which is below the minimum' => ['0'],
            'above the maximum' => ['1.000.000.000.000'],
            'negative' => ['-5000'],
            'not a number at all' => ['seratus ribu'],
            'empty' => [''],
        ];
    }

    #[DataProvider('acceptedAmounts')]
    public function test_it_accepts_whole_rupiah(mixed $value): void
    {
        $this->assertSame([], $this->failures($value));
    }

    #[DataProvider('refusedAmounts')]
    public function test_it_refuses_anything_else(mixed $value): void
    {
        $this->assertNotSame([], $this->failures($value));
    }

    public function test_the_integer_behind_a_grouped_value_is_the_grouping_removed(): void
    {
        $this->assertSame(1_500_000, WholeRupiah::toInteger('1.500.000'));
        $this->assertSame(1_500_000, WholeRupiah::toInteger('1500000'));
        $this->assertSame(1_500_000, WholeRupiah::toInteger(1_500_000));
        $this->assertNull(WholeRupiah::toInteger(''));
        $this->assertNull(WholeRupiah::toInteger(null));
    }

    public function test_formatting_and_parsing_are_inverses(): void
    {
        $this->assertSame('1.500.000', WholeRupiah::format(1_500_000));
        $this->assertSame('1.500.000', WholeRupiah::format('1.500.000'));
        $this->assertSame('100.000', WholeRupiah::format('100000'));
        $this->assertSame('0', WholeRupiah::format(0));
        $this->assertNull(WholeRupiah::format(null));
        $this->assertNull(WholeRupiah::format(''));
    }

    /**
     * The rule reports through a callback rather than a return value, so this
     * collects what it said.
     *
     * @return array<int, string>
     */
    private function failures(mixed $value): array
    {
        $messages = [];

        (new WholeRupiah)->validate(
            'amount',
            $value,
            function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        return $messages;
    }
}
