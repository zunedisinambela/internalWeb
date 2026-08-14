<?php

namespace App\Filament\Resources\Sales\Actions;

use App\Filament\Resources\Sales\Pages\EditSale;
use App\Models\Product;
use App\Rules\WholeRupiah;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Refills every line of a sale with the prices its products carry today.
 *
 * **This is the deliberate escape hatch from the snapshot, and it exists only
 * because the snapshot is not negotiable.** `sale_items.catalog_price` and
 * `.marketing_price` are copies taken when a line is entered, so a new catalogue
 * never rewrites a sale that already happened — a sale recorded at a marketing
 * price of 17.000 keeps saying 17.000 after the product drops to 15.000, because
 * that is what was actually paid. See Oriflame in CLAUDE.md.
 *
 * What that leaves unserved is the honest mistake: a product entered at the
 * wrong price, or a sale recorded before the catalogue was filled in properly.
 * Each line's price fields stay editable for exactly that, and this button is
 * the same correction for a sale with six lines instead of one.
 *
 * Three properties make it a correction rather than a silent rewrite, and none
 * of them is decoration:
 *
 * - **It is asked for.** Nothing recalculates on its own, so a price change on
 *   the product screen still cannot reach a recorded sale.
 * - **It shows what it will do first.** The confirmation lists every line that
 *   would move and both figures, so "nothing changes" and "four lines change"
 *   are not the same click.
 * - **It does not save.** It writes into the open form and stops. The user
 *   reviews and presses Simpan, which is the ordinary path — so the `sale_item`
 *   audit entries are written by `LogsActivity` the same way a hand correction
 *   is, and abandoning the page changes nothing.
 *
 * That last point is why it operates on `$livewire->data` rather than on the
 * rows. Writing the rows directly would be fewer lines, would skip the form's
 * own validation, and would silently discard whatever else the user had already
 * typed into the page.
 */
class RefreshPricesAction
{
    public static function make(): Action
    {
        return Action::make('refreshPrices')
            ->label('Ambil harga terbaru')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            // Not ->requiresConfirmation(), which renders a generic "are you
            // sure": the point of the confirmation here is the list of what
            // would move, so the modal is built out rather than borrowed.
            ->modalHeading('Ambil harga terbaru dari katalog')
            ->modalDescription(fn (EditSale $livewire): Htmlable|string => self::describe($livewire))
            ->modalSubmitActionLabel('Terapkan ke formulir')
            // Hidden when there is nothing to do, so the button itself answers
            // "are my prices current?" without opening a modal that says no.
            ->visible(fn (EditSale $livewire): bool => self::changes($livewire) !== [])
            ->action(function (EditSale $livewire): void {
                $changes = self::changes($livewire);

                if ($changes === []) {
                    return;
                }

                $items = $livewire->data['items'] ?? [];

                foreach ($changes as $change) {
                    // Written back formatted, because that is the shape the
                    // field holds while the form is open — RupiahInput groups on
                    // the way in and strips on the way out. Putting a bare
                    // integer here would show "15000" in a field that formats
                    // everything else.
                    $items[$change['key']]['catalog_price'] = WholeRupiah::format($change['catalog']['new']);
                    $items[$change['key']]['marketing_price'] = WholeRupiah::format($change['marketing']['new']);
                }

                $livewire->data['items'] = $items;

                Notification::make()
                    ->title(count($changes).' baris diperbarui')
                    // The whole point of the action is that this step is the
                    // user's. A notification that read like a completed save
                    // would leave them thinking the correction was recorded when
                    // closing the tab would still discard it.
                    ->body('Belum tersimpan. Periksa angkanya, lalu tekan Simpan.')
                    ->warning()
                    ->persistent()
                    ->send();
            });
    }

    /**
     * The lines whose stored prices differ from their product's current ones.
     *
     * Compared as integers through WholeRupiah, never as the strings the form
     * holds: "15.000" and "15000" are the same price written two ways, and a
     * string comparison would report every line as changed the moment one of
     * them was retyped by hand.
     *
     * Lines with no product picked yet are skipped rather than treated as
     * changed — a blank new row has nothing to refresh from.
     *
     * @return array<int, array{key: string, name: string, catalog: array{old: int, new: int}, marketing: array{old: int, new: int}}>
     */
    private static function changes(EditSale $livewire): array
    {
        $items = $livewire->data['items'] ?? [];

        $products = Product::query()
            ->whereKey(array_filter(array_column($items, 'product_id')))
            ->get()
            ->keyBy('id');

        $changes = [];

        foreach ($items as $key => $item) {
            $product = $products->get($item['product_id'] ?? null);

            if ($product === null) {
                continue;
            }

            $catalog = (int) WholeRupiah::toInteger($item['catalog_price'] ?? null);
            $marketing = (int) WholeRupiah::toInteger($item['marketing_price'] ?? null);

            if ($catalog === $product->catalog_price && $marketing === $product->marketing_price) {
                continue;
            }

            $changes[] = [
                'key' => $key,
                'name' => $product->name,
                'catalog' => ['old' => $catalog, 'new' => $product->catalog_price],
                'marketing' => ['old' => $marketing, 'new' => $product->marketing_price],
            ];
        }

        return $changes;
    }

    /**
     * The confirmation body: every line that would move, with both figures.
     *
     * Product names are typed by a user, so they are escaped with e() before
     * reaching HtmlString. Filament renders a modal description as HTML, so this
     * is the one place in the feature where an unescaped value would be markup
     * rather than text.
     */
    private static function describe(EditSale $livewire): Htmlable|string
    {
        $changes = self::changes($livewire);

        if ($changes === []) {
            return 'Semua harga pada penjualan ini sudah sama dengan katalog.';
        }

        $rows = array_map(
            fn (array $change): string => '<li class="mb-1"><strong>'.e($change['name']).'</strong><br>'
                .'Katalog: '.self::movement($change['catalog']).'<br>'
                .'Marketing: '.self::movement($change['marketing'])
                .'</li>',
            $changes,
        );

        return new HtmlString(
            '<p class="mb-3">Harga tersimpan pada penjualan ini akan diganti dengan harga katalog saat ini. '
            .'Gunakan hanya bila harga lama memang salah — bukan untuk penjualan yang sudah benar tercatat '
            .'dengan harga lama, karena itulah yang benar-benar Anda bayar.</p>'
            .'<ul class="list-disc ps-5">'.implode('', $rows).'</ul>'
        );
    }

    /**
     * @param  array{old: int, new: int}  $figures
     */
    private static function movement(array $figures): string
    {
        if ($figures['old'] === $figures['new']) {
            return 'Rp '.number_format($figures['new'], 0, ',', '.').' (tetap)';
        }

        return 'Rp '.number_format($figures['old'], 0, ',', '.')
            .' &rarr; <strong>Rp '.number_format($figures['new'], 0, ',', '.').'</strong>';
    }
}
