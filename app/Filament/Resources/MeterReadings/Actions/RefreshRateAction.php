<?php

namespace App\Filament\Resources\MeterReadings\Actions;

use App\Filament\Resources\MeterReadings\Pages\EditMeterReading;
use App\Models\ElectricityTariff;
use App\Rules\WholeRupiah;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Refills a recorded reading's rate from the tariff that was in force when its
 * period closed.
 *
 * **The deliberate escape hatch from the snapshot**, and now the only one in
 * this project — the Oriflame sales feature had a `RefreshPricesAction` of the
 * same shape until it stopped copying prices at all. `meter_readings.rate` is a
 * copy taken
 * when the reading is recorded, so a tariff raise never rewrites a bill that was
 * already read off the meter — and the rate field is hidden on both form screens
 * precisely so nobody retypes it at the meter. Together those two decisions left
 * one case unserved: a rate that is genuinely wrong. A reading entered before the
 * tariff screen was filled in, or one recorded while the tariff itself carried a
 * typo, could until now only be fixed from tinker or by deleting the row and
 * entering it again. This button is that correction.
 *
 * **It takes the tariff in force at `end_read_at`, not the newest one.** Tariffs
 * are versioned, so a July reading corrected in August has two candidate
 * answers — and the newest one is the wrong one. Copying August's rate onto July's
 * reading is exactly the repricing the snapshot exists to prevent, arriving
 * through a button instead of through a join.
 *
 * The date is read from the open form rather than from the row, so a correction
 * that also moves the closing moment offers the tariff for the new date.
 *
 * Four properties keep it a correction rather than an automatic recalculation:
 *
 * - **It is asked for.** Saving a tariff still cannot reach a recorded reading.
 * - **It shows what it would do.** The confirmation names both rates, the date the
 *   chosen tariff took effect, and what the bill becomes.
 * - **It does not save.** It writes into the open form and stops, so the
 *   `meter_reading` audit entry is written by `LogsActivity` on the ordinary
 *   Simpan — the same way a rate fixed by hand would be.
 * - **It hides itself when the stored rate already matches.** The button's
 *   absence answers "is this bill on the right tariff?" without opening a modal
 *   that says nothing would change.
 *
 * The rate field itself is hidden while all this happens, which is why the
 * confirmation and the notification both name the figure: the `Perhitungan`
 * section's total is the only thing on screen that moves, and it is what the user
 * reviews before pressing Simpan.
 */
class RefreshRateAction
{
    public static function make(): Action
    {
        return Action::make('refreshRate')
            ->label('Ambil tarif yang berlaku')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            // Not ->requiresConfirmation(): a generic "are you sure" would hide
            // the only thing worth confirming, which is which tariff was picked
            // and what the bill becomes.
            ->modalHeading('Ambil tarif yang berlaku untuk periode ini')
            ->modalDescription(fn (EditMeterReading $livewire): Htmlable|string => self::describe($livewire))
            ->modalSubmitActionLabel('Terapkan ke formulir')
            ->visible(fn (EditMeterReading $livewire): bool => self::change($livewire) !== null)
            ->action(function (EditMeterReading $livewire): void {
                $change = self::change($livewire);

                if ($change === null) {
                    return;
                }

                // Written grouped, because that is the shape the field holds
                // while the form is open — formatStateUsing() groups on the way
                // in and dehydrateStateUsing() strips on the way out. A bare
                // integer here would still save correctly today, and would break
                // silently the moment the field is ever shown again.
                $livewire->data['rate'] = WholeRupiah::format($change['new']);

                Notification::make()
                    ->title('Tarif diganti menjadi '.self::rupiah($change['new']).'/kWh')
                    // The commit is the user's. A notification reading like a
                    // finished save would leave them thinking the bill was
                    // corrected when closing the tab still discards it.
                    ->body('Belum tersimpan. Periksa total tagihannya, lalu tekan Simpan.')
                    ->warning()
                    ->persistent()
                    ->send();
            });
    }

    /**
     * What the rate would become, or null when there is nothing to do.
     *
     * Null covers three cases that all mean "no button": the form holds no usable
     * closing moment, no tariff had taken effect by then, and the stored rate
     * already equals that tariff's.
     *
     * Compared as integers through WholeRupiah, never as the strings the form
     * holds — "1.650" and "1650" are the same rate written two ways.
     *
     * @return array{old: int, new: int, effective_from: Carbon, note: ?string}|null
     */
    private static function change(EditMeterReading $livewire): ?array
    {
        $closedAt = self::closedAt($livewire);

        if ($closedAt === null) {
            return null;
        }

        $tariff = ElectricityTariff::current($closedAt);

        if ($tariff === null) {
            return null;
        }

        $stored = (int) WholeRupiah::toInteger($livewire->data['rate'] ?? null);

        if ($stored === $tariff->rate) {
            return null;
        }

        return [
            'old' => $stored,
            'new' => $tariff->rate,
            'effective_from' => $tariff->effective_from,
            'note' => $tariff->note,
        ];
    }

    /**
     * The moment the period closes, as the form currently stands.
     *
     * Read from the form rather than from the row so that a correction which also
     * moves the closing moment offers the tariff for the date being saved — the
     * date is what decides which tariff applies, so the two have to be read at the
     * same time.
     *
     * A half-typed date is not an error here: the picker validates it on save.
     * This only has to decide whether the button can say anything useful yet.
     */
    private static function closedAt(EditMeterReading $livewire): ?Carbon
    {
        $value = $livewire->data['end_read_at'] ?? null;

        if (blank($value)) {
            return null;
        }

        return rescue(fn (): Carbon => Carbon::parse($value), null, report: false);
    }

    /**
     * The confirmation body: both rates, the date the tariff took effect, and the
     * bill before and after.
     *
     * The total is the part that matters, because the rate field is hidden — a
     * user cannot check this correction by looking at the form, only by looking at
     * what the tenant is charged.
     *
     * The tariff's note is typed by a user and a modal description is rendered as
     * HTML, so it goes through e() — an Htmlable is handed straight to toHtml()
     * by Laravel's e(), so building one out of user text is where that escape
     * stops being optional. See the Gotchas section of CLAUDE.md.
     */
    private static function describe(EditMeterReading $livewire): Htmlable|string
    {
        $change = self::change($livewire);

        if ($change === null) {
            return 'Tarif pada pencatatan ini sudah sesuai dengan tarif yang berlaku.';
        }

        $usage = self::usage($livewire);

        $note = filled($change['note'])
            ? '<p class="mb-3">Catatan tarif: <em>'.e($change['note']).'</em></p>'
            : '';

        return new HtmlString(
            '<p class="mb-3">Tarif tersimpan akan diganti dengan tarif yang berlaku sejak '
            .'<strong>'.e($change['effective_from']->translatedFormat('d M Y')).'</strong> — '
            .'yaitu tarif pada saat periode ini ditutup, bukan tarif hari ini. '
            .'Gunakan hanya bila tarif lama memang salah.</p>'
            .$note
            .'<ul class="list-disc ps-5">'
            .'<li class="mb-1">Tarif: '.self::rupiah($change['old']).' &rarr; <strong>'.self::rupiah($change['new']).'</strong> /kWh</li>'
            .'<li>Total tagihan ('.number_format($usage, 0, ',', '.').' kWh): '
            .self::rupiah($usage * $change['old']).' &rarr; <strong>'.self::rupiah($usage * $change['new']).'</strong></li>'
            .'</ul>'
        );
    }

    /**
     * kWh as the form currently stands. Not clamped, for the same reason
     * MeterReading::$usage_kwh is not — a negative period should read as broken
     * rather than as a plausible bill of Rp 0.
     */
    private static function usage(EditMeterReading $livewire): int
    {
        return (int) ($livewire->data['end_kwh'] ?? 0) - (int) ($livewire->data['start_kwh'] ?? 0);
    }

    private static function rupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}
