<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Whether a transaction adds to the balance or takes from it.
 *
 * The stored values are English while every label is Indonesian, matching the
 * rule the activity log already follows: what lands in the database is filtered
 * on and asserted in tests, so it must not move when a translation is reworded.
 * Only getLabel() is user-facing.
 */
enum TransactionType: string implements HasColor, HasIcon, HasLabel
{
    case Income = 'income';

    case Expense = 'expense';

    public function getLabel(): string
    {
        return match ($this) {
            self::Income => 'Pemasukan',
            self::Expense => 'Pengeluaran',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Income => 'success',
            self::Expense => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Income => Heroicon::ArrowDownLeft,
            self::Expense => Heroicon::ArrowUpRight,
        };
    }

    /**
     * The sign this type contributes to a running balance.
     *
     * Kept here rather than in a query so the rule has one home: a report, a
     * widget and a test all agree on what "saldo" means without repeating a
     * CASE expression.
     */
    public function sign(): int
    {
        return match ($this) {
            self::Income => 1,
            self::Expense => -1,
        };
    }
}
