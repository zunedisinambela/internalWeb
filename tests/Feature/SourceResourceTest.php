<?php

namespace Tests\Feature;

use App\Filament\Resources\Sources\Pages\CreateSource;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Filament\Resources\Sources\SourceResource;
use App\Filament\Resources\Transactions\Pages\CreateTransaction;
use App\Filament\Resources\Transactions\Pages\EditTransaction;
use App\Models\Source;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Sumber dana: daftarnya, aturan yang menjaganya tetap satu baris per rekening,
 * dan hubungannya dengan buku kas.
 *
 * Yang dijaga di sini adalah hal-hal yang gagalnya diam. Sebuah rekening yang
 * tercatat dua kali membelah saldonya tanpa ada yang salah di layar; sebuah
 * sumber yang hilang dari pilihan saat mengedit transaksi lama menghapus
 * kolomnya begitu Simpan ditekan.
 */
class SourceResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_super_admin_can_open_the_list(): void
    {
        Source::factory()->create(['name' => 'Kas Tunai']);

        $this->actingAs($this->superAdmin())
            ->get('/sources')
            ->assertOk()
            ->assertSee('Kas Tunai');
    }

    /**
     * Punya peran, tapi bukan izin ini: panelnya terbuka karena peran apa pun
     * membukanya, layarnya tidak. Bentuk yang sama dipakai
     * TransactionResourceTest — kalau ini lulus karena alasan yang salah,
     * biasanya karena canViewAny() ditimpa jadi true di suatu tempat.
     */
    public function test_a_role_without_the_permission_is_refused(): void
    {
        $this->seedRoles();

        $role = Role::create(['name' => 'tanpa_sumber', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findByName('ViewAny:Transaction'));

        $reader = $this->userWithRole(null, ['email' => 'tanpa-sumber@admin.com']);
        $reader->assignRole($role);

        $this->actingAs($reader)->get('/transactions')->assertOk();
        $this->actingAs($reader)->get('/sources')->assertForbidden();
    }

    public function test_creating_a_source_records_it_and_defaults_to_active(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(CreateSource::class)
            ->fillForm([
                'name' => 'BCA',
                'note' => '1234567890',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $source = Source::sole();

        $this->assertSame('BCA', $source->name);
        $this->assertTrue($source->is_active);
    }

    /**
     * Kolomnya unique, tapi SQLite membandingkan TEXT secara case sensitive —
     * jadi tanpa aturan tambahan di formnya, "bca" duduk di sebelah "BCA" dan
     * saldo rekening itu terbelah dua tanpa satu pun pesan galat.
     */
    public function test_a_name_that_differs_only_in_case_is_refused(): void
    {
        Source::factory()->create(['name' => 'BCA']);

        Livewire::actingAs($this->superAdmin())
            ->test(CreateSource::class)
            ->fillForm(['name' => 'bca'])
            ->call('create')
            ->assertHasFormErrors(['name']);

        $this->assertSame(1, Source::query()->count());
    }

    /**
     * Spasi ganda dan spasi di ujung tidak terlihat di layar, jadi tanpa
     * normalisasi "BCA " lolos dari kolom unique dan jadi rekening kedua yang
     * tampak persis sama dengan yang pertama.
     */
    public function test_stray_whitespace_in_a_name_is_folded_away(): void
    {
        $source = Source::create(['name' => '  Kas   Tunai  ']);

        $this->assertSame('Kas Tunai', $source->fresh()->name);
    }

    /**
     * Menghapus rekening yang pernah dipakai akan menghapus satu-satunya
     * keterangan lewat mana uang itu berpindah, dan itu tidak bisa disusun
     * ulang dari kolom lain. Basis datanya menolak lewat restrictOnDelete;
     * pemeriksaan di resource yang membuat penolakan itu jadi tombol yang
     * tidak ada, bukan layar error.
     */
    public function test_a_source_with_transactions_cannot_be_deleted(): void
    {
        // canDelete() menanyai kebijakannya juga, dan sebuah kebijakan tanpa
        // pengguna yang masuk selalu menjawab tidak — tanpa ini kedua sisi
        // pernyataan di bawah lulus karena alasan yang sama sekali berbeda.
        $this->actingAs($this->superAdmin());

        $used = Source::factory()->create(['name' => 'BCA']);
        $unused = Source::factory()->create(['name' => 'Dana']);

        Transaction::factory()->income()->for($used, 'source')->create();

        $this->assertFalse(SourceResource::canDelete($used));
        $this->assertTrue(SourceResource::canDelete($unused));
    }

    /**
     * Saldo per rekening dihitung dari dua subkueri, bukan dengan menjalankan
     * relasi tiap baris. Keduanya null pada sumber yang belum punya transaksi,
     * jadi yang diuji di sini bukan hanya angkanya tapi juga bahwa nol tetap
     * nol dan bukan galat tipe.
     */
    public function test_the_balance_column_nets_the_two_directions_per_source(): void
    {
        $bca = Source::factory()->create(['name' => 'BCA']);
        $kas = Source::factory()->create(['name' => 'Kas Tunai']);
        $kosong = Source::factory()->create(['name' => 'Dana']);

        Transaction::factory()->income(1_500_000)->for($bca, 'source')->create();
        Transaction::factory()->expense(250_000)->for($bca, 'source')->create();
        Transaction::factory()->expense(400_000)->for($kas, 'source')->create();

        $this->assertSame(1_250_000, $bca->balance());
        $this->assertSame(-400_000, $kas->balance());
        $this->assertSame(0, $kosong->balance());

        Livewire::actingAs($this->superAdmin())
            ->test(ListSources::class)
            ->assertCanSeeTableRecords([$bca, $kas, $kosong])
            ->assertSee('Rp 1.250.000');
    }

    /**
     * Sumber tidak aktif hilang dari form transaksi baru — itu gunanya.
     */
    public function test_an_inactive_source_is_not_offered_on_a_new_transaction(): void
    {
        $active = Source::factory()->create(['name' => 'BCA']);
        $retired = Source::factory()->inactive()->create(['name' => 'Rekening Lama']);

        $options = Livewire::actingAs($this->superAdmin())
            ->test(CreateTransaction::class)
            ->instance()
            ->getSchema('form')
            ->getComponent('source_id')
            ->getOptions();

        $this->assertArrayHasKey($active->getKey(), $options);
        $this->assertArrayNotHasKey($retired->getKey(), $options);
    }

    /**
     * Dan tetap ada saat mengedit baris yang sudah memakainya.
     *
     * Ini kegagalan yang paling mahal dari seluruh fitur ini: sebuah Select
     * membangun pilihannya dari kueri yang sama, jadi kalau sumber yang sudah
     * dinonaktifkan tidak ikut, field-nya tampil kosong pada transaksi yang
     * jelas punya sumber — dan menekan Simpan menulis null ke kolomnya tanpa
     * satu pun pesan. Barisnya tetap terlihat wajar sesudahnya.
     */
    public function test_a_retired_source_stays_selectable_on_the_row_already_using_it(): void
    {
        $retired = Source::factory()->inactive()->create(['name' => 'Rekening Lama']);
        $transaction = Transaction::factory()->income(500_000)->for($retired, 'source')->create();

        Livewire::actingAs($this->superAdmin())
            ->test(EditTransaction::class, ['record' => $transaction->getKey()])
            ->assertSchemaStateSet(['source_id' => $retired->getKey()], schema: 'form')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($retired->getKey(), $transaction->fresh()->source_id);
    }

    /**
     * Allowlist-nya diuji dari sisi yang hampir tidak pernah diuji: bukan apa
     * yang dicatat, tapi apa yang ditolak. Tanpa ini, melebarkan logOnly() atau
     * menambah kolom yang tersapu refactor tidak menggagalkan apa pun.
     */
    public function test_nothing_outside_the_allowlist_is_logged(): void
    {
        $source = Source::factory()->create(['name' => 'BCA']);

        $source->update(['name' => 'Bank BCA', 'note' => '0987654321']);

        $entry = Activity::query()->where('log_name', 'source')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame(
            ['name', 'note'],
            array_keys($entry->attribute_changes['attributes']),
            'Hanya kolom di allowlist yang boleh sampai ke log sumber dana.',
        );
    }
}
