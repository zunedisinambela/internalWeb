<?php

namespace App\Filament\Forms\Components;

use App\Rules\WholeRupiah;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * A text input that shows `1.500.000` and stores `1500000`.
 *
 * Three pieces make that work and removing any one of them breaks it without an
 * error, which is why they are assembled here rather than typed out per field:
 *
 * | Piece                                     | Does                                             |
 * |-------------------------------------------|--------------------------------------------------|
 * | ->live(onBlur: true) + afterStateUpdated  | regroups what was typed, once the field is left   |
 * | ->formatStateUsing()                      | groups the stored integer when a row is reopened  |
 * | ->dehydrateStateUsing()                   | strips the separators on the way into the column  |
 *
 * The failure mode is what justifies the class. Drop `dehydrateStateUsing` and
 * the column receives the string "1.500.000"; SQLite is loosely typed, so an
 * INTEGER-affinity column takes it, casts it, and stores **1**. No exception, no
 * validation message, and a price that reads as a rounding bug months later.
 * One copy of that trio is one place for it to go wrong.
 *
 * Neither ->numeric() nor ->integer() is set, and neither may be added: both make
 * TextInput::getType() return "number", and a number input refuses to render a
 * thousands separator — the browser blanks the field. The rules they would have
 * registered live in WholeRupiah instead.
 *
 * The default rule is deliberately the widest one. Filament's ->rule() appends
 * rather than replaces, so a caller narrowing the range — ->rule(new
 * WholeRupiah(max: 100_000)) — ends up with both, and a value has to satisfy
 * each. Narrowing composes; there is no way to accidentally widen.
 *
 * `Transaction::$amount` and `MeterReading::$rate` predate this component and
 * still spell the trio out inline. Converting them is a separate change to
 * tested financial code, not a side effect of adding a new screen.
 */
class RupiahInput extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix('Rp')
            ->inputMode('numeric')
            ->maxLength(19)
            ->rule(new WholeRupiah)
            // On blur rather than per keystroke. Filament v5 bundles no Alpine
            // mask plugin — no directive("mask"), no magic("money") anywhere in
            // its dist — so ->mask(RawJs::make('$money(…)')) renders an attribute
            // nothing implements and silently does nothing. A blur costs one
            // Livewire round trip and is testable from PHPUnit, which a
            // client-side mask would not be.
            ->live(onBlur: true)
            ->afterStateUpdated(static function (Set $set, mixed $state, TextInput $component): void {
                // Regroups anything that can only be read one way, however
                // untidy: "10.0000" is what an already-grouped 10.000 becomes
                // when one more digit is typed, and it cannot mean anything but
                // 100.000. "1500.75" is left exactly as typed instead — stripping
                // its dot would turn Rp 1.500,75 into Rp 150.075, so the rule has
                // to be what refuses it rather than the formatter what mangles it.
                if (! WholeRupiah::isUnambiguous($state)) {
                    return;
                }

                // Addressed by the component's own statePath, so this works
                // unchanged inside a Repeater, where the path carries the item's
                // uuid and a hardcoded field name would write to the wrong row.
                $set($component->getName(), WholeRupiah::format($state));
            })
            // Inverses of each other, and both go through WholeRupiah so they
            // cannot drift apart.
            ->formatStateUsing(static fn (mixed $state): ?string => WholeRupiah::format($state))
            ->dehydrateStateUsing(static fn (mixed $state): ?int => WholeRupiah::toInteger($state));
    }

    /**
     * Refuses a value above another rupiah field on the same schema.
     *
     * **Laravel's own ->lte() cannot be used for this, and fails quietly.** It
     * picks its comparison from `is_numeric()`, which answers true for "150.000"
     * — a valid float string meaning 150.0 — and false for "1.500.000", which
     * has two dots. So one side of the same comparison is read as a number and
     * the other as a *string length*, with no error either way. It happens to be
     * right whenever both figures land in the same shape, which is most of the
     * time in testing and not at all reliable.
     *
     * Comparing through WholeRupiah::toInteger() is the only reading that is
     * always the one the column will receive.
     *
     * Both sides null-check: a half-filled form is ->required()'s problem, and
     * two rules reporting the same empty field is noise.
     */
    public function notGreaterThan(string $otherField, string $message): static
    {
        return $this->rule(
            static fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get, $otherField, $message): void {
                $ceiling = WholeRupiah::toInteger($get($otherField));
                $amount = WholeRupiah::toInteger($value);

                if ($ceiling === null || $amount === null) {
                    return;
                }

                if ($amount > $ceiling) {
                    $fail($message);
                }
            },
        );
    }
}
