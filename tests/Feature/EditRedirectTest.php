<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\MeterReadings\MeterReadingResource;
use App\Filament\Resources\MeterReadings\Pages\EditMeterReading;
use App\Filament\Resources\Sales\Pages\EditSale;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Resources\Transactions\Pages\EditTransaction;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Customer;
use App\Models\MeterReading;
use App\Models\Sale;
use App\Models\Source;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Where Simpan lands, for the four screens that are worked in daily.
 *
 * One file rather than a test in each of the four resource suites, because
 * there is one behaviour here: App\Filament\Resources\Concerns\
 * ReturnsToListAfterSaving, mounted four times. Four copies of the assertion
 * would be four things to update the day the trait changes, and the thing most
 * worth protecting is not any single screen — it is that the set is exactly
 * these four and no more.
 */
class EditRedirectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The fourth element is that screen's free-text field. Transaction calls
     * it `description` where the other three call it `note`, so the edit the
     * second test makes cannot be one literal.
     *
     * @return array<string, array{class-string, class-string, class-string, string}>
     */
    public static function editScreens(): array
    {
        return [
            'keuangan' => [EditTransaction::class, TransactionResource::class, Transaction::class, 'description'],
            'penjualan' => [EditSale::class, SaleResource::class, Sale::class, 'note'],
            'pelanggan' => [EditCustomer::class, CustomerResource::class, Customer::class, 'note'],
            'meteran listrik' => [EditMeterReading::class, MeterReadingResource::class, MeterReading::class, 'note'],
        ];
    }

    #[DataProvider('editScreens')]
    public function test_saving_an_edit_returns_to_the_list(string $page, string $resource, string $model, string $field): void
    {
        $record = $this->savable($model);

        Livewire::actingAs($this->superAdmin())
            ->test($page, ['record' => $record->getKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect($resource::getUrl('index'));
    }

    /**
     * The redirect is a redirect, not a discard.
     *
     * Filament saves, sends its notification and only then reads
     * getRedirectUrl(), so leaving the page cannot cost the write. Asserted
     * because the two are indistinguishable on screen from a user's side: the
     * list looks the same either way until the edited row is looked at again,
     * and by then nobody remembers which save it was.
     */
    #[DataProvider('editScreens')]
    public function test_the_edit_is_written_before_the_redirect(string $page, string $resource, string $model, string $field): void
    {
        $record = $this->savable($model);

        Livewire::actingAs($this->superAdmin())
            ->test($page, ['record' => $record->getKey()])
            ->fillForm([$field => 'Diubah lalu disimpan'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect($resource::getUrl('index'));

        $this->assertSame('Diubah lalu disimpan', $record->fresh()->{$field});
    }

    /**
     * The counter-case, and the reason this file exists at all.
     *
     * Nothing here is a panel-wide setting. Filament offers one —
     * ->resourceEditPageRedirect('index') in AdminPanelProvider — and swapping
     * the trait for it would leave the four tests above green while silently
     * changing every resource added afterwards. This is the assertion that
     * notices: Pengguna is not one of the four, so it must still stay on its
     * form.
     */
    public function test_a_screen_without_the_trait_stays_on_its_form(): void
    {
        $user = $this->userWithRole('super_admin');

        Livewire::actingAs($user)
            ->test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['name' => 'Nama Baru'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNoRedirect();
    }

    /**
     * A record whose edit form passes validation untouched.
     *
     * The tests above press Simpan on a form they did not fill, so anything the
     * factory leaves out of a required field fails validation and the redirect
     * is never reached — a failure that reads as "the trait is missing" when it
     * is nothing of the sort.
     *
     * Transaksi is the case today: source_id is nullable in the column, because
     * rows recorded before sumber dana existed genuinely have no answer, while
     * the form requires it of everything recorded from now on.
     * TransactionFactory follows the column rather than the form, so the source
     * is attached here.
     *
     * @param  class-string<Model>  $model
     */
    private function savable(string $model): Model
    {
        $factory = $model::factory();

        if ($model === Transaction::class) {
            $factory = $factory->for(Source::factory(), 'source');
        }

        return $factory->create();
    }
}
