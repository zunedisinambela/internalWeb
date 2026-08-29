<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Exports\TransactionsExport;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Jobs\ExportCashBook;
use App\Models\Source;
use App\Models\Transaction;
use App\Reports\CashBook;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The spreadsheet export of the cash book.
 *
 * Two groups of assertions here, and they answer different questions.
 *
 * The first is authorization. An export is a read surface that leaves the
 * panel, and it does not pass through the Shield policy that guards the screen
 * unless something makes it — a file of rows the caller cannot view would be a
 * way around the gate rather than a convenience.
 *
 * The second is the shape of the file. The screen shows one signed amount; the
 * spreadsheet splits the two directions into their own columns so each side can
 * be summed, and carries a running balance that only means anything if the rows
 * are in chronological order. None of that is visible from the export class
 * being "green" — it has to be read back out of the generated workbook.
 */
class TransactionExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Written by every test that inspects the file, and removed again in
     * tearDown — phpspreadsheet reads from a path, not from a string.
     */
    private ?string $tempFile = null;

    protected function tearDown(): void
    {
        if ($this->tempFile !== null && file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }

        parent::tearDown();
    }

    public function test_a_role_that_cannot_list_transactions_cannot_export_them(): void
    {
        $this->seedRoles();

        $role = Role::create(['name' => 'tanpa-keuangan', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findByName('ViewAny:Activity'));

        $user = $this->userWithRole(null, ['email' => 'tanpa@admin.com']);
        $user->assignRole($role);

        $this->actingAs($user);

        // The rule itself, and the screen it hangs off. Both have to refuse:
        // the button is only hidden because canExport() says so, and hiding a
        // button is not authorization on its own.
        $this->assertFalse(TransactionResource::canExport());

        $this->get('/transactions')->assertForbidden();
    }

    public function test_someone_who_can_list_transactions_may_export_them(): void
    {
        $this->seedRoles();

        $role = Role::create(['name' => 'pembaca-kas', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findByName('ViewAny:Transaction'));

        $user = $this->userWithRole(null, ['email' => 'pembaca-kas@admin.com']);
        $user->assignRole($role);

        $this->actingAs($user);

        $this->assertTrue(TransactionResource::canExport());

        Livewire::actingAs($user)
            ->test(ListTransactions::class)
            ->assertActionVisible(TestAction::make('exportExcel'));
    }

    /**
     * The action queues the render; it does not hand back a file.
     *
     * The whole point of the change is that nobody waits on phpspreadsheet
     * inside a web request, so an assertion that a download came back would be
     * asserting the thing that was removed. What matters instead is that the
     * job carries the filtered set and a name stamped at the moment it was
     * asked for, rather than whenever a worker happens to pick it up.
     */
    public function test_asking_for_a_spreadsheet_queues_the_render(): void
    {
        Bus::fake();

        Carbon::setTestNow('2026-08-14 15:30:00');

        $transaction = Transaction::factory()->income(1_500_000)->create();

        Livewire::actingAs($this->superAdmin())
            ->test(ListTransactions::class)
            ->callAction(TestAction::make('exportExcel'));

        Bus::assertDispatched(
            ExportCashBook::class,
            fn (ExportCashBook $job): bool => $job->ids === [$transaction->id]
                && $job->format === 'xlsx'
                && $job->fileName === 'buku-kas-2026-08-14-153000.xlsx',
        );
    }

    /**
     * The button is a button, so it gets clicked twice. Each click unguarded is
     * its own job: two full copies of the book on disk, two
     * `transactions_exported` entries for one act, and two notifications
     * offering the same thing.
     */
    public function test_clicking_twice_on_the_same_screen_queues_one_render(): void
    {
        Bus::fake();

        $this->makeBook();

        $page = Livewire::actingAs($this->superAdmin())->test(ListTransactions::class);

        $page->callAction(TestAction::make('exportExcel'));
        $page->callAction(TestAction::make('exportExcel'));

        Bus::assertDispatchedTimes(ExportCashBook::class, 1);
    }

    /**
     * The other half of the guard, and the reason the key is the row set rather
     * than just the user and the format.
     *
     * Keying on userId.format alone would refuse this too: filter the screen
     * differently, click again, and the export is silently discarded while the
     * screen says it is being processed. A narrower key only refuses a genuine
     * repeat.
     */
    public function test_a_different_row_set_or_format_is_a_different_request(): void
    {
        Bus::fake();

        $admin = $this->superAdmin();

        ExportCashBook::dispatch([1, 2, 3], 'xlsx', $admin->id, 'a.xlsx');
        ExportCashBook::dispatch([1, 2], 'xlsx', $admin->id, 'b.xlsx');
        ExportCashBook::dispatch([1, 2, 3], 'pdf', $admin->id, 'c.pdf');

        Bus::assertDispatchedTimes(ExportCashBook::class, 3);
    }

    /**
     * The ids arrive in whatever order the filtered query returned them — the
     * action calls reorder(), so there is no ORDER BY at all and the same set
     * can come back arranged differently. uniqueId() sorts before hashing for
     * exactly that reason, and without this the guard would quietly do nothing:
     * every click would hash to a fresh key and every duplicate would queue.
     */
    public function test_the_same_rows_in_a_different_order_are_the_same_request(): void
    {
        Bus::fake();

        $admin = $this->superAdmin();

        ExportCashBook::dispatch([3, 1, 2], 'xlsx', $admin->id, 'a.xlsx');
        ExportCashBook::dispatch([1, 2, 3], 'xlsx', $admin->id, 'b.xlsx');

        Bus::assertDispatchedTimes(ExportCashBook::class, 1);
    }

    /**
     * The finished file is a complete copy of the cash book, so it goes to the
     * private disk — the same place receipts go, for the same reason. Landing
     * it on `public` would publish the book by URL and sidestep every policy
     * the panel enforces.
     *
     * The notification is the other half: the request that asked for the file
     * has ended by the time it exists, so a flash message could never reach the
     * user. Without a database notification the file is written and nobody is
     * ever told where.
     */
    public function test_the_rendered_file_lands_on_the_private_disk_and_is_announced(): void
    {
        Storage::fake(ExportCashBook::DISK);

        Carbon::setTestNow('2026-08-14 15:30:00');

        $admin = $this->superAdmin();

        $this->makeBook();

        // The queue connection is `sync` under test, so the job runs here.
        Livewire::actingAs($admin)
            ->test(ListTransactions::class)
            ->callAction(TestAction::make('exportExcel'));

        Storage::disk(ExportCashBook::DISK)
            ->assertExists(ExportCashBook::DIRECTORY.'/buku-kas-2026-08-14-153000.xlsx');

        $notification = DatabaseNotification::query()->sole();

        $this->assertTrue($admin->is($notification->notifiable));
        $this->assertStringContainsString('siap diunduh', $notification->data['title']);
    }

    /**
     * A copy of the book must not outlive the link that reaches it. The cutoff
     * and the signature expiry are the same constant, so this also pins that
     * they cannot drift apart.
     */
    public function test_a_rendered_file_is_pruned_once_its_link_has_expired(): void
    {
        Storage::fake(ExportCashBook::DISK);

        $disk = Storage::disk(ExportCashBook::DISK);

        $disk->put(ExportCashBook::DIRECTORY.'/buku-kas-lama.xlsx', 'x');
        touch(
            $disk->path(ExportCashBook::DIRECTORY.'/buku-kas-lama.xlsx'),
            now()->subHours(ExportCashBook::RETENTION_HOURS + 1)->getTimestamp(),
        );

        $disk->put(ExportCashBook::DIRECTORY.'/buku-kas-baru.xlsx', 'x');

        $this->artisan('exports:prune')->assertSuccessful();

        $disk->assertMissing(ExportCashBook::DIRECTORY.'/buku-kas-lama.xlsx');
        $disk->assertExists(ExportCashBook::DIRECTORY.'/buku-kas-baru.xlsx');
    }

    /**
     * The audit entry is the only record that a copy of the book left the
     * panel. Nothing else can produce one: the rows are read, not written, so
     * no model event fires and LogsActivity never sees the request.
     */
    public function test_downloading_is_audited(): void
    {
        Storage::fake(ExportCashBook::DISK);

        $admin = $this->superAdmin();

        Transaction::factory()->income(1_000_000)->create();
        Transaction::factory()->expense(400_000)->create();

        Livewire::actingAs($admin)
            ->test(ListTransactions::class)
            ->callAction(TestAction::make('exportExcel'));

        $entry = Activity::query()
            ->where('log_name', 'monitoring')
            ->where('event', 'transactions_exported')
            ->sole();

        $this->assertTrue($entry->causer->is($admin));
        $this->assertSame(2, $entry->properties['rows']);
        $this->assertStringEndsWith('.xlsx', $entry->properties['file_name']);
    }

    public function test_asking_for_a_pdf_queues_the_render(): void
    {
        Bus::fake();

        Carbon::setTestNow('2026-08-14 15:30:00');

        $this->makeBook();

        Livewire::actingAs($this->superAdmin())
            ->test(ListTransactions::class)
            ->callAction(TestAction::make('exportPdf'));

        Bus::assertDispatched(
            ExportCashBook::class,
            fn (ExportCashBook $job): bool => $job->format === 'pdf'
                && $job->fileName === 'buku-kas-2026-08-14-153000.pdf',
        );
    }

    /**
     * dompdf takes the same route as the spreadsheet: rendered in the job,
     * written to the private disk, announced afterwards. Asserting the magic
     * bytes off the stored file is what proves the render actually happened
     * rather than an empty placeholder being written.
     */
    public function test_the_rendered_pdf_lands_on_the_private_disk(): void
    {
        Storage::fake(ExportCashBook::DISK);

        Carbon::setTestNow('2026-08-14 15:30:00');

        $this->makeBook();

        Livewire::actingAs($this->superAdmin())
            ->test(ListTransactions::class)
            ->callAction(TestAction::make('exportPdf'));

        $path = ExportCashBook::DIRECTORY.'/buku-kas-2026-08-14-153000.pdf';

        Storage::disk(ExportCashBook::DISK)->assertExists($path);

        $this->assertStringStartsWith(
            '%PDF-',
            Storage::disk(ExportCashBook::DISK)->get($path),
        );
    }

    /**
     * Both formats write one event, told apart by a property rather than by two
     * keys — downloading the book is a single act and the extension is a detail
     * of it, so "who took a copy" must not mean remembering to check two events.
     */
    public function test_the_pdf_download_is_audited_under_the_same_event(): void
    {
        Storage::fake(ExportCashBook::DISK);

        $admin = $this->superAdmin();

        $this->makeBook();

        Livewire::actingAs($admin)
            ->test(ListTransactions::class)
            ->callAction(TestAction::make('exportPdf'));

        $entry = Activity::query()
            ->where('log_name', 'monitoring')
            ->where('event', 'transactions_exported')
            ->sole();

        $this->assertTrue($entry->causer->is($admin));
        $this->assertSame('pdf', $entry->properties['format']);
        $this->assertSame(3, $entry->properties['rows']);
    }

    /**
     * dompdf compresses object streams, so the ledger text is not greppable in
     * the output — the magic bytes are what a PDF assertion can rely on. What
     * the document *says* is asserted through CashBook instead, which is the
     * same source the template renders from.
     */
    public function test_the_pdf_is_a_pdf(): void
    {
        $this->makeBook();

        $bytes = $this->pdf();

        $this->assertStringStartsWith('%PDF-', $bytes);
    }

    /**
     * The empty book has its own branch: there is no period to print when
     * nothing was folded, and an "Rp 0 – Rp 0" header over a blank table would
     * be a worse answer than saying so. A filter matching nothing is ordinary,
     * so it must not produce a broken document.
     */
    public function test_an_empty_book_still_renders(): void
    {
        $book = new CashBook(Transaction::query());
        $book->lines();

        $this->assertNull($book->period());
        $this->assertSame(
            ['income' => 0, 'expense' => 0, 'balance' => 0, 'rows' => 0],
            $book->totals(),
        );

        $this->assertStringStartsWith('%PDF-', $this->pdf());
    }

    /**
     * The template interpolates a description the user typed. dompdf's chroot is
     * base_path() and `file://` is in allowed_protocols, so markup reaching the
     * document can read any file in the project — .env included, and the APP_KEY
     * in it decrypts every user's two-factor secret. Blade's `{{ }}` is what
     * stops that, and this asserts the template never switched to `{!! !!}`.
     */
    public function test_a_description_is_escaped_rather_than_parsed_as_markup(): void
    {
        Transaction::factory()->income(100_000)->create([
            'occurred_at' => '2026-08-01 09:00:00',
            'description' => '<b>tebal</b>',
        ]);

        $book = new CashBook(Transaction::query());

        $html = view($book->view(), $book->viewData())->render();

        $this->assertStringContainsString('&lt;b&gt;tebal&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>tebal</b>', $html);
    }

    /**
     * Only the balance can be negative — `amount` is unsigned and the direction
     * lives in `type` — and `number_format()` alone would put the sign inside
     * the currency, giving "Rp -1.830.000".
     */
    public function test_a_negative_balance_puts_the_sign_before_the_currency(): void
    {
        Transaction::factory()->expense(1_830_000)->create([
            'occurred_at' => '2026-08-01 09:00:00',
            'description' => 'Defisit',
        ]);

        $book = new CashBook(Transaction::query());

        $html = view($book->view(), $book->viewData())->render();

        $this->assertStringContainsString('-Rp 1.830.000', $html);
        $this->assertStringNotContainsString('Rp -1.830.000', $html);
    }

    /**
     * The ledger shape: income and expense in separate columns, blank on the
     * side that does not apply, and a running balance beside them.
     */
    public function test_the_two_columns_split_the_directions_and_carry_a_running_balance(): void
    {
        $this->makeBook();

        $sheet = $this->export();

        // A .. H = Waktu, Keterangan, Sumber, Pemasukan, Pengeluaran, Saldo,
        // Bukti, Dicatat oleh.
        $this->assertSame(
            ['Waktu', 'Keterangan', 'Sumber', 'Pemasukan', 'Pengeluaran', 'Saldo', 'Bukti', 'Dicatat oleh'],
            $this->row($sheet, 1),
        );

        $this->assertSame(['Setoran awal', 1_500_000, null, 1_500_000], $this->ledger($sheet, 2));
        $this->assertSame(['Beli ATK', null, 250_000, 1_250_000], $this->ledger($sheet, 3));
        $this->assertSame(['Jual barang', 750_000, null, 2_000_000], $this->ledger($sheet, 4));

        // The totals row sums each side and ends on the closing balance.
        $this->assertSame(['TOTAL', 2_250_000, 250_000, 2_000_000], $this->ledger($sheet, 5));
    }

    /**
     * The table is sorted newest-first, which is how a cash book is read and
     * the reverse of how a running balance accumulates. The export imposes its
     * own order rather than inheriting the screen's, so this asserts the rows
     * come out oldest-first even though the list would show them the other way.
     */
    public function test_rows_come_out_oldest_first_whatever_the_table_shows(): void
    {
        $this->makeBook();

        $sheet = $this->export();

        $this->assertSame(
            ['Setoran awal', 'Beli ATK', 'Jual barang'],
            [$sheet->getCell('B2')->getValue(), $sheet->getCell('B3')->getValue(), $sheet->getCell('B4')->getValue()],
        );
    }

    /**
     * A filtered screen must produce a filtered file. The action reads
     * getFilteredTableQuery(), so anything the caller narrowed on screen
     * narrows the export too — and the running balance restarts from the
     * filtered set rather than carrying in a total nothing in the file explains.
     */
    public function test_it_exports_the_filtered_set_rather_than_the_whole_book(): void
    {
        $this->makeBook();

        $sheet = $this->export(
            Transaction::query()->where('type', TransactionType::Income->value),
        );

        $this->assertSame(['Setoran awal', 1_500_000, null, 1_500_000], $this->ledger($sheet, 2));
        $this->assertSame(['Jual barang', 750_000, null, 2_250_000], $this->ledger($sheet, 3));
        $this->assertSame(['TOTAL', 2_250_000, 0, 2_250_000], $this->ledger($sheet, 4));
    }

    /**
     * The whole reason the sign moved out of the figure and into the column
     * layout. A cell holding "+ Rp 1.500.000" is a string, and a column of
     * strings cannot be summed, pivoted or charted — which is most of what a
     * spreadsheet is for.
     */
    public function test_amounts_reach_the_cells_as_numbers_not_text(): void
    {
        $this->makeBook();

        $sheet = $this->export();

        $this->assertIsInt($sheet->getCell('D2')->getValue());
        $this->assertIsInt($sheet->getCell('F2')->getValue());

        // Displayed as rupiah by the cell's number format, so the figure stays
        // numeric while still reading as money.
        $this->assertSame('"Rp" #,##0', $sheet->getStyle('D2')->getNumberFormat()->getFormatCode());
    }

    /**
     * Written as a real Excel date rather than a preformatted string, so the
     * column sorts and filters as a date once the file is open.
     */
    public function test_the_timestamp_is_a_real_date_cell(): void
    {
        $this->makeBook();

        $sheet = $this->export();

        $this->assertIsFloat($sheet->getCell('A2')->getValue());
        $this->assertSame('dd/mm/yyyy hh:mm', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
        $this->assertSame('01/08/2026 09:00', $sheet->getCell('A2')->getFormattedValue());
    }

    public function test_the_receipt_column_counts_only_the_receipts_collection(): void
    {
        // Only the count is asserted, so the faked disk costs nothing here —
        // and without it the run leaves real files under storage/app/private.
        Storage::fake('local');

        $transaction = Transaction::factory()->income(100_000)->create([
            'occurred_at' => '2026-08-01 09:00:00',
            'description' => 'Dengan bukti',
        ]);

        // A real image, not a string: the collection pins acceptsMimeTypes(),
        // so anything else is refused before it reaches the media table.
        $transaction->addMedia(UploadedFile::fake()->image('struk.jpg'))
            ->toMediaCollection(Transaction::RECEIPTS);

        $sheet = $this->export();

        $this->assertSame(1, $sheet->getCell('G2')->getValue());
    }

    /**
     * A zero and an empty cell mean different things here, and fromArray() is
     * happy to confuse them: it skips any cell equal to its null value, and
     * under the loose comparison it uses by default `0 != null` is false. So
     * without WithStrictNullComparison every zero in the file silently becomes
     * a blank — a transaction with no receipts would read as "unknown" rather
     * than "none", and a one-directional export would total to nothing.
     *
     * The two are asserted together because only the contrast is the point.
     */
    public function test_a_zero_prints_as_zero_while_a_blank_side_stays_empty(): void
    {
        Transaction::factory()->income(100_000)->create([
            'occurred_at' => '2026-08-01 09:00:00',
            'description' => 'Tanpa bukti',
        ]);

        $sheet = $this->export();

        // No receipts: a real zero.
        $this->assertSame(0, $sheet->getCell('G2')->getValue());

        // Not an expense: genuinely not applicable, so the cell stays empty.
        $this->assertNull($sheet->getCell('E2')->getValue());
    }

    /**
     * Sumbernya ikut ke dalam berkas, dan baris yang tidak punya mengatakannya
     * dengan kata — bukan dengan sel kosong.
     *
     * Kolomnya nullable karena baris yang dicatat sebelum sumber dana ada
     * memang tidak punya jawaban. Di layar itu placeholder; di dalam berkas
     * yang keluar dari panel, sel kosong terbaca sebagai kolom yang lupa
     * diisi, dan pembacanya tidak punya cara membedakan keduanya.
     */
    public function test_the_source_reaches_the_spreadsheet_and_a_row_without_one_says_so(): void
    {
        $bca = Source::factory()->create(['name' => 'BCA']);

        Transaction::factory()->income(1_500_000)->for($bca, 'source')->create([
            'occurred_at' => '2026-08-01 09:00:00',
            'description' => 'Setoran awal',
        ]);

        Transaction::factory()->expense(250_000)->create([
            'occurred_at' => '2026-08-02 11:30:00',
            'description' => 'Beli ATK',
        ]);

        $sheet = $this->export();

        $this->assertSame('BCA', $sheet->getCell('C2')->getValue());
        $this->assertSame(CashBook::UNKNOWN_SOURCE, $sheet->getCell('C3')->getValue());

        // Baris TOTAL tidak menjumlah nama, jadi selnya kosong — dan itu harus
        // tetap kosong ketimbang mewarisi nama terakhir.
        $this->assertNull($sheet->getCell('C4')->getValue());
    }

    /**
     * Rekap per sumber dikumpulkan sambil baris dilipat, bukan lewat kueri
     * agregat kedua — jadi yang diuji di sini adalah bahwa jumlahnya persis
     * sama dengan baris-baris yang benar-benar tercetak.
     */
    public function test_the_recap_carries_a_balance_for_every_source(): void
    {
        $bca = Source::factory()->create(['name' => 'BCA']);
        $kas = Source::factory()->create(['name' => 'Kas Tunai']);

        Transaction::factory()->income(1_500_000)->for($bca, 'source')->create(['occurred_at' => '2026-08-01 09:00:00']);
        Transaction::factory()->expense(250_000)->for($bca, 'source')->create(['occurred_at' => '2026-08-02 09:00:00']);
        Transaction::factory()->expense(400_000)->for($kas, 'source')->create(['occurred_at' => '2026-08-03 09:00:00']);

        $book = new CashBook(Transaction::query());
        $book->lines();

        $this->assertSame(
            [
                ['name' => 'BCA', 'income' => 1_500_000, 'expense' => 250_000, 'balance' => 1_250_000],
                ['name' => 'Kas Tunai', 'income' => 0, 'expense' => 400_000, 'balance' => -400_000],
            ],
            $book->sources(),
        );

        // Dan sampai ke halamannya, dengan tanda minus di depan "Rp" seperti
        // seluruh angka lain di laporan ini.
        $html = view($book->view(), $book->viewData())->render();

        $this->assertStringContainsString('Saldo per sumber dana', $html);
        $this->assertStringContainsString('-Rp 400.000', $html);
    }

    /**
     * Baris tanpa sumber selalu di urutan terakhir.
     *
     * Ia bukan rekening, jadi menaruhnya di antara nama-nama rekening akan
     * terbaca seolah-olah ia salah satunya — dan urutan abjad akan melakukan
     * persis itu, karena "Tidak diketahui" jatuh di tengah.
     */
    public function test_the_recap_puts_rows_without_a_source_last(): void
    {
        Transaction::factory()->income(100_000)->for(Source::factory()->create(['name' => 'Zenith']), 'source')
            ->create(['occurred_at' => '2026-08-01 09:00:00']);

        Transaction::factory()->income(200_000)->create(['occurred_at' => '2026-08-02 09:00:00']);

        Transaction::factory()->income(300_000)->for(Source::factory()->create(['name' => 'Alfa']), 'source')
            ->create(['occurred_at' => '2026-08-03 09:00:00']);

        $book = new CashBook(Transaction::query());
        $book->lines();

        $this->assertSame(
            ['Alfa', 'Zenith', CashBook::UNKNOWN_SOURCE],
            array_column($book->sources(), 'name'),
        );
    }

    /**
     * Satu sumber berarti tidak ada rekap.
     *
     * Setiap angkanya akan sama persis dengan kartu ringkasan di atasnya, dan
     * tabel yang hanya mengulang tetap memakan tinggi halaman — pada buku
     * sepanjang setahun itu satu halaman penuh yang tidak mengatakan apa pun.
     */
    public function test_the_recap_is_omitted_when_the_book_has_only_one_source(): void
    {
        $bca = Source::factory()->create(['name' => 'BCA']);

        Transaction::factory()->income(1_500_000)->for($bca, 'source')->create(['occurred_at' => '2026-08-01 09:00:00']);
        Transaction::factory()->expense(250_000)->for($bca, 'source')->create(['occurred_at' => '2026-08-02 09:00:00']);

        $book = new CashBook(Transaction::query());

        $html = view($book->view(), $book->viewData())->render();

        $this->assertStringNotContainsString('Saldo per sumber dana', $html);
    }

    /**
     * Three rows in a fixed order, so every assertion above can name an exact
     * cell. Amounts are explicit for the same reason TransactionFactory's
     * states take one — a total asserted against a random figure proves nothing.
     */
    private function makeBook(): void
    {
        Transaction::factory()->income(1_500_000)->create([
            'occurred_at' => '2026-08-01 09:00:00',
            'description' => 'Setoran awal',
        ]);

        Transaction::factory()->expense(250_000)->create([
            'occurred_at' => '2026-08-02 11:30:00',
            'description' => 'Beli ATK',
        ]);

        Transaction::factory()->income(750_000)->create([
            'occurred_at' => '2026-08-03 08:15:00',
            'description' => 'Jual barang',
        ]);
    }

    /**
     * Generate the workbook and hand back its only sheet.
     *
     * phpspreadsheet reads from a path rather than a string, so the raw bytes
     * go through a temp file that tearDown removes.
     */
    private function export(?Builder $query = null): Worksheet
    {
        $raw = Excel::raw(
            new TransactionsExport(new CashBook($query ?? Transaction::query())),
            ExcelFormat::XLSX,
        );

        $this->tempFile = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        file_put_contents($this->tempFile, $raw);

        return IOFactory::load($this->tempFile)->getActiveSheet();
    }

    /**
     * Render the report and hand back the raw bytes.
     */
    private function pdf(?Builder $query = null): string
    {
        $book = new CashBook($query ?? Transaction::query());

        // viewData(), not a hand-built array. The template reads whatever the
        // report hands it, so a test that assembles its own set of keys is
        // testing a document the job never renders — the missing key surfaces
        // as an undefined variable in Blade rather than as a failed assertion
        // about the report.
        return Pdf::loadView($book->view(), $book->viewData())
            ->setPaper('a4', 'landscape')
            ->output();
    }

    /**
     * The four columns the ledger shape is about: description, in, out, balance.
     *
     * @return array<int, mixed>
     */
    private function ledger(Worksheet $sheet, int $row): array
    {
        return [
            $sheet->getCell("B{$row}")->getValue() ?? $sheet->getCell("A{$row}")->getValue(),
            $sheet->getCell("D{$row}")->getValue(),
            $sheet->getCell("E{$row}")->getValue(),
            $sheet->getCell("F{$row}")->getValue(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function row(Worksheet $sheet, int $row): array
    {
        return array_map(
            fn (string $column): mixed => $sheet->getCell("{$column}{$row}")->getValue(),
            ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'],
        );
    }
}
