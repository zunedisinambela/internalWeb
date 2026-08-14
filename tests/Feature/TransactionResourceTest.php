<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Filament\Resources\Transactions\Pages\CreateTransaction;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Models\Transaction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TransactionResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin/transactions')->assertRedirect('/admin/login');
    }

    public function test_users_without_a_role_are_forbidden(): void
    {
        $this->actingAs($this->userWithRole(null))
            ->get('/admin/transactions')
            ->assertForbidden();
    }

    public function test_a_super_admin_can_open_the_list(): void
    {
        Transaction::factory()->income(2_500_000)->create(['description' => 'Pembayaran klien']);

        $this->actingAs($this->superAdmin())
            ->get('/admin/transactions')
            ->assertOk()
            ->assertSee('Pembayaran klien');
    }

    /**
     * The screen is gated by its Shield policy like every other resource, not by
     * a hardcoded override. A role that can read but not write must be refused
     * the create page — if this passes for the wrong reason it is usually
     * because canCreate() was overridden to true somewhere.
     */
    public function test_a_read_only_role_cannot_reach_the_create_page(): void
    {
        $this->seedRoles();

        $role = Role::create(['name' => 'pembaca', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findByName('ViewAny:Transaction'));

        $user = $this->userWithRole(null, ['email' => 'pembaca@admin.com']);
        $user->assignRole($role);

        $this->actingAs($user)->get('/admin/transactions')->assertOk();
        $this->actingAs($user)->get('/admin/transactions/create')->assertForbidden();
    }

    /**
     * The requirement behind the screen: the date and time field arrives filled
     * with the current moment, and the row keeps it.
     */
    public function test_the_date_and_time_default_to_now(): void
    {
        Carbon::setTestNow('2026-08-14 15:12:00');

        Livewire::actingAs($this->superAdmin())
            ->test(CreateTransaction::class)
            // Without seconds: the picker is configured ->seconds(false), so
            // that is the shape the form state carries.
            ->assertSchemaStateSet(['occurred_at' => '2026-08-14 15:12'], schema: 'form');

        Carbon::setTestNow();
    }

    public function test_creating_a_transaction_stores_whole_rupiah_and_stamps_the_author(): void
    {
        $admin = $this->superAdmin();

        Livewire::actingAs($admin)
            ->test(CreateTransaction::class)
            ->fillForm([
                'type' => TransactionType::Expense->value,
                'amount' => 1_500_000,
                'description' => 'Sewa kantor Agustus',
                'occurred_at' => '2026-08-14 09:00:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $transaction = Transaction::sole();

        $this->assertSame(TransactionType::Expense, $transaction->type);
        // Integer, not a float: a decimal column on SQLite would come back as
        // 1500000.0 and this assertion is what catches the change.
        $this->assertSame(1_500_000, $transaction->amount);
        $this->assertSame($admin->getKey(), $transaction->user_id);
        $this->assertSame('2026-08-14 09:00:00', $transaction->occurred_at->toDateTimeString());
    }

    public function test_a_fractional_amount_is_rejected_rather_than_silently_truncated(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(CreateTransaction::class)
            ->fillForm([
                'type' => TransactionType::Income->value,
                'amount' => 1500.75,
                'description' => 'Bunga bank',
                'occurred_at' => '2026-08-14 09:00:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['amount']);

        $this->assertSame(0, Transaction::query()->count());
    }

    /**
     * Why the form guards the amount rather than trusting the column.
     *
     * The column is an integer, but SQLite is loosely typed and stores a real
     * value in an INTEGER-affinity column as-is when it cannot convert it
     * losslessly. Nothing raises — the value only becomes an integer on the way
     * back out, through the cast. So a fractional amount that reaches the model
     * is lost quietly, which is what ->integer() on the form field prevents.
     */
    public function test_a_fractional_amount_reaching_the_model_is_lost_quietly(): void
    {
        $transaction = Transaction::factory()->expense()->create();

        $transaction->forceFill(['amount' => 1500.75])->saveQuietly();

        $this->assertSame(1500, $transaction->fresh()->amount);
    }

    /**
     * Backs the claim in the Keuangan section of CLAUDE.md: deleting a row
     * writes its own entry *and* one per receipt that went down with it.
     *
     * Worth pinning because it depends on two unrelated mechanisms lining up —
     * medialibrary deleting its files from the `deleting` event, and the Media
     * listener in AppServiceProvider firing per row. Either could change under
     * an upgrade and leave the log quietly thinner.
     */
    public function test_deleting_a_transaction_audits_the_row_and_each_receipt(): void
    {
        Storage::fake('local');

        $transaction = Transaction::factory()->expense()->create();
        $transaction->addMedia(UploadedFile::fake()->image('struk-a.jpg'))
            ->toMediaCollection(Transaction::RECEIPTS);
        $transaction->addMedia(UploadedFile::fake()->image('struk-b.jpg'))
            ->toMediaCollection(Transaction::RECEIPTS);

        $transaction->delete();

        $this->assertSame(1, Activity::query()
            ->where('log_name', 'transaction')
            ->where('event', 'deleted')
            ->count());

        $this->assertSame(2, Activity::query()
            ->where('log_name', 'transaction')
            ->where('event', 'receipt_deleted')
            ->count());
    }

    public function test_the_balance_is_income_minus_expense(): void
    {
        Transaction::factory()->income(5_000_000)->create();
        Transaction::factory()->income(1_250_000)->create();
        Transaction::factory()->expense(2_000_000)->create();

        $this->assertSame(4_250_000, Transaction::balance());
    }

    public function test_the_balance_of_an_empty_table_is_zero(): void
    {
        $this->assertSame(0, Transaction::balance());
    }

    /**
     * The disk decision, asserted rather than left to a comment.
     *
     * `public` resolves to storage/app/public behind the public/storage symlink,
     * where anything is readable by URL with no role check and no policy. A
     * receipt carries amounts, account numbers and addresses, so this test is
     * what stops the collection drifting back to the default.
     */
    public function test_receipts_are_stored_on_the_private_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $transaction = Transaction::factory()->expense()->create();
        $transaction->addMedia(UploadedFile::fake()->image('struk.jpg'))
            ->toMediaCollection(Transaction::RECEIPTS);

        $media = $transaction->refresh()->getFirstMedia(Transaction::RECEIPTS);

        $this->assertNotNull($media);
        $this->assertSame('local', $media->disk);
        $this->assertNotSame('public', $media->disk);
    }

    /**
     * The authorization test CLAUDE.md asks the first medialibrary model to
     * arrive with.
     *
     * The `local` disk sets serve => true and no visibility key, so Laravel
     * treats it as private and its /storage route refuses any request without a
     * valid signature. That is what keeps a receipt from being readable by
     * anyone who guesses the path, signed in or not.
     */
    public function test_a_receipt_cannot_be_fetched_without_a_signature(): void
    {
        Storage::fake('local');

        $transaction = Transaction::factory()->expense()->create();
        $transaction->addMedia(UploadedFile::fake()->image('struk.jpg'))
            ->toMediaCollection(Transaction::RECEIPTS);

        $path = $transaction->refresh()
            ->getFirstMedia(Transaction::RECEIPTS)
            ->getPathRelativeToRoot();

        // ServeFile checks the signature before it looks for the file, so this
        // is the real route answering even though the disk underneath is faked.
        $this->get('/storage/'.$path)->assertForbidden();
    }

    /**
     * The other half of the rule above: the route does serve a signed request,
     * so the 403 is a signature check and not simply a missing route.
     *
     * This one runs against the real `local` disk. Storage::fake() replaces
     * temporaryUrl() with a stub that returns an unsigned link with no /storage
     * prefix, so a faked disk cannot exercise signing at all — it would pass or
     * fail for reasons that have nothing to do with the configuration under
     * test.
     */
    public function test_the_private_disk_serves_a_correctly_signed_url(): void
    {
        $directory = 'transaction-signing-test';
        $path = $directory.'/struk.txt';

        Storage::disk('local')->put($path, 'bukti');

        try {
            $this->get(Storage::disk('local')->temporaryUrl($path, now()->addMinutes(5)))
                ->assertOk();
        } finally {
            Storage::disk('local')->deleteDirectory($directory);
        }
    }

    /**
     * Renders the two screens that actually resolve a receipt URL.
     *
     * Both the image column and the image entry take a separate code path when
     * the component is marked private — they ask medialibrary for a temporary
     * URL instead of a plain one, and that call throws on a driver that cannot
     * sign. Nothing else in this file would notice: a broken URL still renders,
     * it just renders as a broken image.
     */
    public function test_the_list_and_view_screens_render_with_a_receipt_attached(): void
    {
        Storage::fake('local');

        $transaction = Transaction::factory()->expense(320_000)->create([
            'description' => 'Beli kertas',
        ]);
        $transaction->addMedia(UploadedFile::fake()->image('struk.jpg'))
            ->toMediaCollection(Transaction::RECEIPTS);

        $admin = $this->superAdmin();

        $this->actingAs($admin)->get('/admin/transactions')->assertOk();
        $this->actingAs($admin)->get('/admin/transactions/create')->assertOk();
        $this->actingAs($admin)
            ->get('/admin/transactions/'.$transaction->getKey())
            ->assertOk()
            ->assertSee('Beli kertas');
        $this->actingAs($admin)
            ->get('/admin/transactions/'.$transaction->getKey().'/edit')
            ->assertOk();
    }

    /**
     * Receipts are a relation, so LogsActivity cannot see them. Removing one is
     * an edit to the evidence behind an amount, which is exactly the kind of
     * change the audit trail exists for.
     */
    public function test_deleting_a_receipt_is_audited(): void
    {
        Storage::fake('local');

        $transaction = Transaction::factory()->expense()->create();
        $transaction->addMedia(UploadedFile::fake()->image('struk.jpg'))
            ->toMediaCollection(Transaction::RECEIPTS);

        $transaction->refresh()->getFirstMedia(Transaction::RECEIPTS)->delete();

        $entry = Activity::query()->where('event', 'receipt_deleted')->sole();

        $this->assertSame('transaction', $entry->log_name);
        $this->assertSame('Bukti transaksi dihapus', $entry->description);
        $this->assertSame('struk.jpg', $entry->properties->get('file_name'));
        $this->assertSame($transaction->getKey(), $entry->properties->get('transaction_id'));
    }

    /**
     * Bulk deletion goes through per-record deletes. Filament's single-query
     * path fires no model events, which would take both the activity entry and
     * the receipt file cleanup down with it — rows gone, images orphaned on disk
     * with nothing left pointing at them.
     */
    public function test_bulk_deletion_audits_every_row(): void
    {
        $transactions = Transaction::factory()->count(2)->expense()->create();

        Livewire::actingAs($this->superAdmin())
            ->test(ListTransactions::class)
            ->selectTableRecords($transactions->modelKeys())
            ->callAction(TestAction::make('delete')->table()->bulk());

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(2, Activity::query()
            ->where('log_name', 'transaction')
            ->where('event', 'deleted')
            ->count());
    }

    /**
     * The allowlist, asserted the way UserActivityLoggingTest asserts User's.
     * A column added later must not start appearing in the log unless somebody
     * chose to put it there.
     */
    public function test_the_audit_log_records_only_the_allowed_columns(): void
    {
        $transaction = Transaction::factory()->expense(500_000)->create();
        $transaction->update(['amount' => 750_000, 'description' => 'Revisi nota']);

        // activitylog v5 keeps the diff in its own attribute_changes column,
        // shaped ['old' => [...], 'attributes' => [...]], rather than inside
        // properties the way v4 did.
        $changes = Activity::query()
            ->where('log_name', 'transaction')
            ->where('event', 'updated')
            ->sole()
            ->attribute_changes;

        $this->assertSame(
            ['amount', 'description'],
            array_keys($changes['attributes']),
        );
    }
}
