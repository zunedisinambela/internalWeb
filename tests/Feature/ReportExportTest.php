<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\MeterReadings\MeterReadingResource;
use App\Filament\Resources\MeterReadings\Pages\ListMeterReadings;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\SaleResource;
use App\Jobs\ExportCustomers;
use App\Jobs\ExportMeterReadings;
use App\Jobs\ExportReport;
use App\Jobs\ExportSales;
use App\Models\Customer;
use App\Models\MeterReading;
use App\Models\Sale;
use App\Models\Transaction;
use App\Reports\CashBook;
use App\Reports\CustomerReport;
use App\Reports\MeterReadingReport;
use App\Reports\Report;
use App\Reports\SalesReport;
use App\Support\PdfImage;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Testing\TestAction;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The three list screens that gained an export, and the evidence column the
 * cash book PDF gained at the same time.
 *
 * TransactionExportTest already covers the plumbing in depth — the uniqueness
 * lock, the retention, the audit entry, the shape of a workbook — against the
 * cash book. Since that plumbing now lives on App\Jobs\ExportReport and
 * App\Exports\ReportExport and is inherited rather than repeated, this file
 * does not re-assert it three more times. What it asserts instead is the part
 * that is genuinely per-screen and would fail silently:
 *
 * - that each screen dispatches **its own** job, so one screen's double-click
 *   guard cannot swallow another's export;
 * - that each report's figures are its own arithmetic rather than the cash
 *   book's, including the aggregates that arrive as null rather than 0;
 * - that user text reaches every one of the four PDF views escaped, because
 *   dompdf's chroot is base_path() and a parsed tag there can read .env;
 * - that the evidence column prints the *conversion* and never the original
 *   upload, which is the difference between a PDF and a PDF carrying the GPS
 *   coordinates of a building with tenants in it.
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Written by every test that inspects a workbook, and removed again in
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

    /**
     * The three screens that gained an export, with the job each must dispatch.
     *
     * The job class is the load-bearing element rather than a detail: Laravel
     * keys the ShouldBeUnique lock on get_class($job), so a screen sharing
     * another's job class would share its lock — and two exports of rows that
     * happen to carry the same ids would silently become one.
     *
     * @return array<string, array{class-string, class-string<ExportReport>, class-string<Report>, string, string}>
     */
    public static function screens(): array
    {
        return [
            'penjualan' => [ListSales::class, ExportSales::class, SalesReport::class, 'penjualan', 'sales_exported'],
            'pelanggan' => [ListCustomers::class, ExportCustomers::class, CustomerReport::class, 'pelanggan', 'customers_exported'],
            'meteran listrik' => [ListMeterReadings::class, ExportMeterReadings::class, MeterReadingReport::class, 'meteran-listrik', 'meter_readings_exported'],
        ];
    }

    /**
     * The four PDF views, with a factory that puts user-typed text on the page.
     *
     * @return array<string, array{class-string<Report>, callable, string}>
     */
    public static function pdfViews(): array
    {
        return [
            'buku kas' => [CashBook::class, 'seedMarkupTransaction', '<b>tebal</b>'],
            'penjualan' => [SalesReport::class, 'seedMarkupSale', '<b>tebal</b>'],
            'pelanggan' => [CustomerReport::class, 'seedMarkupCustomer', '<b>tebal</b>'],
            'meteran listrik' => [MeterReadingReport::class, 'seedMarkupReading', '<b>tebal</b>'],
        ];
    }

    // ---------------------------------------------------------------- actions

    #[DataProvider('screens')]
    public function test_the_screen_offers_both_formats(string $page, string $job, string $report, string $slug, string $event): void
    {
        $this->seedRows($report);

        Livewire::actingAs($this->superAdmin())
            ->test($page)
            ->assertActionVisible(TestAction::make('exportExcel'))
            ->assertActionVisible(TestAction::make('exportPdf'));
    }

    /**
     * Each screen dispatches its own job, with its own file name, over the rows
     * the screen is currently showing.
     */
    #[DataProvider('screens')]
    public function test_asking_for_a_spreadsheet_queues_that_screens_own_job(string $page, string $job, string $report, string $slug, string $event): void
    {
        Bus::fake();

        Carbon::setTestNow('2026-08-14 15:30:00');

        $ids = $this->seedRows($report);

        Livewire::actingAs($this->superAdmin())
            ->test($page)
            ->callAction(TestAction::make('exportExcel'));

        Bus::assertDispatched(
            $job,
            fn (ExportReport $dispatched): bool => $dispatched->format === 'xlsx'
                && $dispatched->fileName === $slug.'-2026-08-14-153000.xlsx'
                && array_diff($dispatched->ids, $ids) === [],
        );
    }

    #[DataProvider('screens')]
    public function test_asking_for_a_pdf_queues_that_screens_own_job(string $page, string $job, string $report, string $slug, string $event): void
    {
        Bus::fake();

        Carbon::setTestNow('2026-08-14 15:30:00');

        $this->seedRows($report);

        Livewire::actingAs($this->superAdmin())
            ->test($page)
            ->callAction(TestAction::make('exportPdf'));

        Bus::assertDispatched(
            $job,
            fn (ExportReport $dispatched): bool => $dispatched->format === 'pdf'
                && $dispatched->fileName === $slug.'-2026-08-14-153000.pdf',
        );
    }

    /**
     * End to end on the sync queue: a file on the private disk, a notification
     * carrying its link, and an audit entry naming this report rather than the
     * cash book's.
     *
     * The event key is the assertion that matters. All four exports write under
     * the `monitoring` log name, so a shared key would make "who took a copy of
     * the customer list" unanswerable without reading every properties blob.
     */
    #[DataProvider('screens')]
    public function test_running_the_job_writes_a_file_a_notification_and_its_own_audit_entry(string $page, string $job, string $report, string $slug, string $event): void
    {
        Storage::fake('local');

        Carbon::setTestNow('2026-08-14 15:30:00');

        $rows = count($this->seedRows($report));

        $admin = $this->superAdmin();

        // The queue connection is `sync` under test, so the job runs here.
        Livewire::actingAs($admin)
            ->test($page)
            ->callAction(TestAction::make('exportExcel'));

        Storage::disk(ExportReport::DISK)
            ->assertExists(ExportReport::DIRECTORY.'/'.$slug.'-2026-08-14-153000.xlsx');

        $notification = DatabaseNotification::query()->sole();

        $this->assertTrue($admin->is($notification->notifiable));

        $activity = Activity::query()->where('event', $event)->sole();

        $this->assertSame('monitoring', $activity->log_name);
        $this->assertTrue($admin->is($activity->causer));
        $this->assertSame($rows, $activity->properties['rows']);
        $this->assertSame('xlsx', $activity->properties['format']);
    }

    /**
     * Both halves have to refuse. The button is only hidden because canExport()
     * says so, and hiding a button is not authorization on its own.
     */
    public function test_someone_without_the_list_permission_may_not_export(): void
    {
        $this->seedRoles();

        $role = Role::create(['name' => 'tanpa-oriflame', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findByName('ViewAny:Activity'));

        $user = $this->userWithRole(null, ['email' => 'tanpa-oriflame@admin.com']);
        $user->assignRole($role);

        $this->actingAs($user);

        $this->assertFalse(SaleResource::canExport());
        $this->assertFalse(CustomerResource::canExport());
        $this->assertFalse(MeterReadingResource::canExport());
    }

    public function test_someone_who_can_list_a_screen_may_export_it(): void
    {
        $this->seedRoles();

        $role = Role::create(['name' => 'pembaca-penjualan', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findByName('ViewAny:Sale'));

        $user = $this->userWithRole(null, ['email' => 'pembaca-penjualan@admin.com']);
        $user->assignRole($role);

        $this->actingAs($user);

        $this->assertTrue(SaleResource::canExport());
        // Scoped to the one resource: holding the sales list does not hand over
        // the customer directory, which carries home addresses.
        $this->assertFalse(CustomerResource::canExport());
    }

    // ---------------------------------------------------------------- figures

    /**
     * The footer sums the three prices and derives the margin from those sums,
     * rather than summing a per-row margin. The two agree because the margin is
     * linear; deriving it is what keeps them agreeing if it stops being.
     */
    public function test_the_sales_report_totals_the_three_prices_and_derives_the_margin(): void
    {
        Sale::factory()->priced(marketing: 100_000, catalog: 150_000, shipping: 10_000)->quantity(3)->create();
        Sale::factory()->priced(marketing: 200_000, catalog: 260_000, shipping: 0)->quantity(4)->create();

        $totals = $this->render(SalesReport::class)['totals'];

        $this->assertSame(7, $totals['quantity']);
        $this->assertSame(300_000, $totals['marketing']);
        $this->assertSame(10_000, $totals['shipping']);
        $this->assertSame(410_000, $totals['catalog']);
        $this->assertSame(100_000, $totals['profit']);
    }

    /**
     * A loss is printed as a loss. Sale::$profit is deliberately not clamped —
     * a small order posted a long way genuinely costs more than it earns, and
     * max(0, …) would render it as an order that happened to earn nothing.
     */
    public function test_a_sale_that_lost_money_stays_negative_in_the_report(): void
    {
        Sale::factory()->priced(marketing: 100_000, catalog: 90_000, shipping: 20_000)->create();

        $this->assertSame(-30_000, $this->render(SalesReport::class)['totals']['profit']);
    }

    /**
     * The customer aggregates arrive from subqueries, which answer **null**
     * rather than 0 for a customer with no orders at all. Without the casts in
     * CustomerReport that is a TypeError on the first customer added and never
     * sold to — which is every customer, for a moment.
     */
    public function test_a_customer_with_no_orders_counts_as_zero_rather_than_failing(): void
    {
        Customer::factory()->named('Belum Belanja')->create();

        $line = $this->render(CustomerReport::class)['lines']->sole();

        $this->assertSame(0, $line['orders']);
        $this->assertSame(0, $line['quantity']);
        $this->assertSame(0, $line['earned']);
        $this->assertSame(0, $line['claimed']);
    }

    /**
     * The bonus is counted across a customer's orders, not within one.
     *
     * Two orders of twelve earn one free item here and nothing at all per sale
     * — summing Sale::$free_quantity would throw away the remainder at each row
     * boundary. This is the assertion that keeps the report reading the
     * customer-level figure.
     */
    public function test_free_items_are_counted_across_a_customers_orders(): void
    {
        $customer = Customer::factory()->create();

        Sale::factory()->forCustomer($customer)->quantity(12)->create();
        Sale::factory()->forCustomer($customer)->quantity(12)->create();

        $line = $this->render(CustomerReport::class)['lines']->sole();

        $this->assertSame(24, $line['quantity']);
        $this->assertSame(1, $line['earned']);
        $this->assertSame(0, $line['claimed']);
        $this->assertSame(1, $line['outstanding']);
    }

    /**
     * Two independent columns. Nothing here multiplies one by the other, and
     * nothing divides them either — the panel records a bill rather than
     * computing one.
     */
    public function test_the_meter_report_totals_usage_and_the_amount_separately(): void
    {
        MeterReading::factory()->usage(kwh: 120, total: 250_000, start: 1_000)->create([
            'start_read_at' => '2026-06-01 08:00:00',
            'end_read_at' => '2026-06-30 08:00:00',
        ]);
        MeterReading::factory()->usage(kwh: 80, total: 180_000, start: 1_120)->create([
            'start_read_at' => '2026-06-30 08:00:00',
            'end_read_at' => '2026-07-30 08:00:00',
        ]);

        $rendered = $this->render(MeterReadingReport::class);

        $this->assertSame(200, $rendered['totals']['usage']);
        $this->assertSame(430_000, $rendered['totals']['amount']);
        // Read off the fold, so it describes the rows that were printed rather
        // than the filter that selected them.
        $this->assertSame('30 Juni 2026', $rendered['period']['from']->translatedFormat('d F Y'));
        $this->assertSame('30 Juli 2026', $rendered['period']['until']->translatedFormat('d F Y'));
    }

    /**
     * A directory is ordered by name, not by time, so there is no period to
     * print and the header has to say a count instead of a date range.
     */
    public function test_the_customer_report_has_no_period(): void
    {
        Customer::factory()->count(2)->create();

        $this->assertNull($this->render(CustomerReport::class)['period']);
    }

    // -------------------------------------------------------------- workbooks

    /**
     * The sheet's shape, read back out of the generated workbook.
     *
     * App\Exports\ReportExport derives the rightmost column from headings()
     * and writes the TOTAL row through fromArray(), so a cells() array of a
     * different length than headings() misaligns every figure in the footer
     * without failing anything. Reading the cells back is the only assertion
     * that catches it.
     */
    public function test_the_sales_workbook_puts_its_totals_under_the_right_columns(): void
    {
        Sale::factory()->priced(marketing: 100_000, catalog: 150_000, shipping: 10_000)->quantity(3)->create();
        Sale::factory()->priced(marketing: 200_000, catalog: 260_000, shipping: 0)->quantity(4)->create();

        $sheet = $this->sheet(SalesReport::class);

        $this->assertSame('Tanggal', $sheet->getCell('A1')->getValue());
        $this->assertSame('Keuntungan', $sheet->getCell('G1')->getValue());
        $this->assertSame('Dicatat oleh', $sheet->getCell('K1')->getValue());

        $this->assertSame('TOTAL', $sheet->getCell('A4')->getValue());
        $this->assertSame(7, $sheet->getCell('C4')->getValue());
        $this->assertSame(300_000, $sheet->getCell('D4')->getValue());
        $this->assertSame(10_000, $sheet->getCell('E4')->getValue());
        $this->assertSame(410_000, $sheet->getCell('F4')->getValue());
        $this->assertSame(100_000, $sheet->getCell('G4')->getValue());
    }

    /**
     * The dial figures have no total, and that is not the same as a total of
     * zero: a column of meter positions has nothing to add up. fromArray()
     * skips a null cell under WithStrictNullComparison, which is exactly the
     * behaviour wanted here — and exactly the behaviour that must *not* apply
     * to the zero beside it.
     */
    public function test_the_meter_workbook_leaves_the_dial_columns_out_of_the_total(): void
    {
        MeterReading::factory()->usage(kwh: 120, total: 250_000, start: 1_000)->create();

        $sheet = $this->sheet(MeterReadingReport::class);

        $this->assertSame('TOTAL', $sheet->getCell('A3')->getValue());
        $this->assertNull($sheet->getCell('C3')->getValue());
        $this->assertNull($sheet->getCell('D3')->getValue());
        $this->assertSame(120, $sheet->getCell('E3')->getValue());
        $this->assertSame(250_000, $sheet->getCell('F3')->getValue());
    }

    /**
     * A customer with no orders has to print 0, not an empty cell.
     *
     * fromArray() drops any cell equal to its null value, and under the default
     * loose comparison `0 != null` is false — so without WithStrictNullComparison
     * on the base class every zero in this sheet silently disappears, and a
     * directory of new customers reads as a directory with no counts at all.
     */
    public function test_a_zero_count_reaches_the_customer_workbook_as_a_zero(): void
    {
        Customer::factory()->named('Belum Belanja')->create();

        $sheet = $this->sheet(CustomerReport::class);

        $this->assertSame('Belum Belanja', $sheet->getCell('A2')->getValue());
        $this->assertSame(0, $sheet->getCell('D2')->getValue());
        $this->assertSame(0, $sheet->getCell('E2')->getValue());
        $this->assertSame(0, $sheet->getCell('F2')->getValue());
        $this->assertSame(0, $sheet->getCell('G2')->getValue());
        $this->assertSame(0, $sheet->getCell('H2')->getValue());
        // Rendered as a word rather than as TRUE/FALSE: a spreadsheet column of
        // booleans reads differently in every locale that opens it.
        $this->assertSame('Aktif', $sheet->getCell('I2')->getValue());
        $this->assertSame('TOTAL', $sheet->getCell('A3')->getValue());
    }

    // ------------------------------------------------------------- the images

    /**
     * The change this feature was asked for: the Bukti column prints the
     * receipt, not how many receipts there are.
     */
    public function test_the_cash_book_pdf_prints_the_receipt_rather_than_a_count(): void
    {
        Storage::fake('local');

        $transaction = Transaction::factory()->income(100_000)->create();

        // A real image, not a string: the collection pins acceptsMimeTypes(),
        // so anything else is refused before it reaches the media table.
        $transaction->addMedia(UploadedFile::fake()->image('struk.jpg'))
            ->toMediaCollection(Transaction::RECEIPTS);

        $html = $this->html(CashBook::class);

        $this->assertStringContainsString('<img class="bukti"', $html);
    }

    /**
     * Always the conversion, never the original.
     *
     * The re-encode is what drops the EXIF the phone wrote, GPS included, and
     * an exported PDF is a file that leaves the building. A fallback to the
     * original would be invisible in every way except the metadata it carried.
     */
    public function test_the_pdf_embeds_the_thumbnail_and_never_the_original_upload(): void
    {
        Storage::fake('local');

        $transaction = Transaction::factory()->income(100_000)->create();

        $transaction->addMedia(UploadedFile::fake()->image('struk.jpg'))
            ->toMediaCollection(Transaction::RECEIPTS);

        $media = $transaction->getFirstMedia(Transaction::RECEIPTS);

        $html = $this->html(CashBook::class);

        $this->assertStringContainsString($media->getPath(Transaction::THUMBNAIL), $html);
        $this->assertStringNotContainsString($media->getPath(), $html);
    }

    /**
     * A cell fits two thumbnails. The rest are counted in the open rather than
     * dropped: a report that truncates its own contents without saying so reads
     * as though that was all there was.
     */
    public function test_receipts_beyond_the_cells_capacity_are_counted_rather_than_dropped(): void
    {
        Storage::fake('local');

        $transaction = Transaction::factory()->income(100_000)->create();

        foreach (range(1, 4) as $n) {
            $transaction->addMedia(UploadedFile::fake()->image("struk-{$n}.jpg"))
                ->toMediaCollection(Transaction::RECEIPTS);
        }

        $html = $this->html(CashBook::class);

        $this->assertSame(2, substr_count($html, '<img class="bukti"'));
        $this->assertStringContainsString('+2', $html);
    }

    public function test_a_transaction_with_no_receipt_prints_a_dash(): void
    {
        Transaction::factory()->income(100_000)->create();

        $html = $this->html(CashBook::class);

        $this->assertStringNotContainsString('<img class="bukti"', $html);
        $this->assertStringContainsString('<span class="samar">&ndash;</span>', $html);
    }

    /**
     * The sale keeps its two evidence columns apart.
     *
     * A file already says which it is through collection_name, and merging them
     * into one cell would throw that away — a resi is the one attachment
     * carrying a customer's home address, so which strip a photograph is in is
     * not decoration.
     */
    public function test_a_sales_two_attachment_collections_print_in_separate_columns(): void
    {
        Storage::fake('local');

        $sale = Sale::factory()->create();

        $sale->addMedia(UploadedFile::fake()->image('transfer.jpg'))->toMediaCollection(Sale::PAYMENT_PROOFS);
        $sale->addMedia(UploadedFile::fake()->image('resi.jpg'))->toMediaCollection(Sale::SHIPPING_PROOFS);

        $transfer = $sale->getFirstMedia(Sale::PAYMENT_PROOFS);
        $resi = $sale->getFirstMedia(Sale::SHIPPING_PROOFS);

        $html = $this->html(SalesReport::class);

        $this->assertSame(2, substr_count($html, '<img class="bukti"'));
        // Order on the page follows the column order, which is what proves they
        // did not both land in one cell.
        $this->assertLessThan(
            strpos($html, $resi->getPath(Sale::THUMBNAIL)),
            strpos($html, $transfer->getPath(Sale::THUMBNAIL)),
        );
    }

    /**
     * Three ways for an attachment to be unprintable, and all of them have to
     * fail the same quiet way: the render happens on a queue, where there is
     * nobody to tell.
     *
     * dompdf answers a missing file by drawing nothing and logging nothing —
     * `show_warnings` is false — so the check has to be ours, and the report
     * prints a dash where the photograph would be.
     */
    public function test_an_attachment_whose_file_is_gone_prints_nothing_at_all(): void
    {
        Storage::fake('local');

        $transaction = Transaction::factory()->income(100_000)->create();

        $transaction->addMedia(UploadedFile::fake()->image('struk.jpg'))
            ->toMediaCollection(Transaction::RECEIPTS);

        $media = $transaction->getFirstMedia(Transaction::RECEIPTS);

        unlink($media->getPath(Transaction::THUMBNAIL));

        $this->assertNull(PdfImage::path($media, Transaction::THUMBNAIL));

        $html = $this->html(CashBook::class);

        $this->assertStringNotContainsString('<img class="bukti"', $html);
        $this->assertStringContainsString('<span class="samar">&ndash;</span>', $html);
    }

    /**
     * A conversion that was never generated is not silently replaced by the
     * original — see App\Support\PdfImage for why that fallback would be a leak
     * rather than a nicety.
     */
    public function test_a_missing_conversion_is_not_replaced_by_the_original(): void
    {
        Storage::fake('local');

        $transaction = Transaction::factory()->income(100_000)->create();

        $transaction->addMedia(UploadedFile::fake()->image('struk.jpg'))
            ->toMediaCollection(Transaction::RECEIPTS);

        $media = $transaction->getFirstMedia(Transaction::RECEIPTS);

        $media->generated_conversions = [];
        $media->save();

        $this->assertNull(PdfImage::path($media->fresh(), Transaction::THUMBNAIL));
    }

    /**
     * Through dompdf for real, not just through Blade.
     *
     * The HTML assertions above would all pass against a document dompdf
     * refuses to lay out — CSS 2.1 is all it understands, and `show_warnings`
     * is false, so a rule it cannot parse produces a valid PDF that looks
     * wrong rather than an error. This is the one test that proves each view
     * survives the renderer.
     *
     * @param  class-string<Report>  $report
     */
    #[DataProvider('screens')]
    public function test_each_report_renders_through_dompdf(string $page, string $job, string $report, string $slug, string $event): void
    {
        Storage::fake('local');

        Carbon::setTestNow('2026-08-14 15:30:00');

        $this->seedRows($report);

        Livewire::actingAs($this->superAdmin())
            ->test($page)
            ->callAction(TestAction::make('exportPdf'));

        $path = ExportReport::DIRECTORY.'/'.$slug.'-2026-08-14-153000.pdf';

        Storage::disk(ExportReport::DISK)->assertExists($path);

        $bytes = Storage::disk(ExportReport::DISK)->get($path);

        $this->assertStringStartsWith('%PDF-', $bytes);
    }

    /**
     * The receipt is really in the file, and the file size is the only way to
     * tell.
     *
     * A PDF is compressed, so the path that was embedded cannot be grepped out
     * of the bytes — and dompdf drops an image it cannot read without a word,
     * `show_warnings` being false. The same document rendered with and without
     * an attachment is the assertion that survives both of those.
     */
    public function test_a_receipt_makes_the_rendered_pdf_measurably_bigger(): void
    {
        Storage::fake('local');

        $transaction = Transaction::factory()->income(100_000)->create();

        $without = strlen($this->pdfBytes(CashBook::class));

        $transaction->addMedia(UploadedFile::fake()->image('struk.jpg', 400, 300))
            ->toMediaCollection(Transaction::RECEIPTS);

        $with = strlen($this->pdfBytes(CashBook::class));

        $this->assertGreaterThan($without, $with);
    }

    /**
     * The header separator is markup, so it has to be written where markup is
     * not escaped.
     *
     * A report that assembles "2 penjualan &middot; 30 barang" in PHP and hands
     * it to {{ }} prints the entity verbatim on the page — a bug that is
     * invisible in every HTML assertion, since the escaped form is exactly what
     * a correctly-escaped user string looks like. This is what makes the two
     * cases tell each other apart.
     */
    public function test_the_header_separator_is_rendered_and_not_printed_as_an_entity(): void
    {
        Sale::factory()->quantity(6)->create();

        $html = $this->html(SalesReport::class);

        $this->assertStringContainsString('1 penjualan', $html);
        $this->assertStringContainsString('6 barang', $html);
        $this->assertStringNotContainsString('&amp;middot;', $html);
    }

    // ------------------------------------------------------------- the escape

    /**
     * dompdf's chroot is base_path() and `file://` is in allowed_protocols, so
     * markup that reaches the parser can read any file in the project — .env
     * included, and APP_KEY in it decrypts every user's two-factor secret.
     *
     * One assertion per view rather than one for the shared partials: the
     * escape is per interpolation, and a single {!! !!} added to any one of the
     * four is the whole exposure.
     *
     * @param  callable-string  $seed
     */
    #[DataProvider('pdfViews')]
    public function test_user_text_is_escaped_rather_than_parsed_as_markup(string $report, string $seed, string $markup): void
    {
        $this->{$seed}($markup);

        $html = $this->html($report);

        $this->assertStringContainsString('&lt;b&gt;tebal&lt;/b&gt;', $html);
        $this->assertStringNotContainsString($markup, $html);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Seed two rows for a report and hand back their ids.
     *
     * @param  class-string<Report>  $report
     * @return array<int, int>
     */
    private function seedRows(string $report): array
    {
        $model = match ($report) {
            SalesReport::class => Sale::class,
            CustomerReport::class => Customer::class,
            MeterReadingReport::class => MeterReading::class,
            CashBook::class => Transaction::class,
        };

        // Sale::factory() builds a Customer of its own, so the customer screen
        // is seeded directly rather than through it — two sales would leave two
        // customers and make the row count depend on a relation.
        return $model::factory()->count(2)->create()->modelKeys();
    }

    /**
     * The view data a report hands its PDF, built the way the job builds it.
     *
     * @param  class-string<Report>  $report
     * @return array<string, mixed>
     */
    private function render(string $report): array
    {
        $model = match ($report) {
            SalesReport::class => Sale::class,
            CustomerReport::class => Customer::class,
            MeterReadingReport::class => MeterReading::class,
            CashBook::class => Transaction::class,
        };

        return $report::forIds($model::query()->pluck('id')->all())->viewData();
    }

    /**
     * The rendered PDF markup, without going through dompdf.
     *
     * Asserting on the HTML rather than on the PDF bytes is deliberate: a PDF
     * is compressed, so a string that leaked into it is not findable in the
     * output. The escape has to be checked where it happens.
     *
     * @param  class-string<Report>  $report
     */
    private function html(string $report): string
    {
        $instance = $report::forIds(
            match ($report) {
                SalesReport::class => Sale::query()->pluck('id')->all(),
                CustomerReport::class => Customer::query()->pluck('id')->all(),
                MeterReadingReport::class => MeterReading::query()->pluck('id')->all(),
                CashBook::class => Transaction::query()->pluck('id')->all(),
            }
        );

        return view($instance->view(), $instance->viewData())->render();
    }

    /**
     * The report rendered by dompdf, as bytes.
     *
     * @param  class-string<Report>  $report
     */
    private function pdfBytes(string $report): string
    {
        $instance = $report::forIds(Transaction::query()->pluck('id')->all());

        return Pdf::loadView($instance->view(), $instance->viewData())
            ->setPaper(...$instance->paper())
            ->output();
    }

    /**
     * The generated workbook's first sheet.
     *
     * phpspreadsheet reads from a path rather than from a string, so the bytes
     * go to a temporary file that tearDown removes.
     *
     * @param  class-string<Report>  $report
     */
    private function sheet(string $report): Worksheet
    {
        $instance = $report::forIds(
            match ($report) {
                SalesReport::class => Sale::query()->pluck('id')->all(),
                CustomerReport::class => Customer::query()->pluck('id')->all(),
                MeterReadingReport::class => MeterReading::query()->pluck('id')->all(),
                CashBook::class => Transaction::query()->pluck('id')->all(),
            }
        );

        $this->tempFile = tempnam(sys_get_temp_dir(), 'export').'.xlsx';

        file_put_contents($this->tempFile, Excel::raw($instance->excel(), ExcelFormat::XLSX));

        return IOFactory::load($this->tempFile)->getActiveSheet();
    }

    private function seedMarkupTransaction(string $markup): void
    {
        Transaction::factory()->income(100_000)->create(['description' => $markup]);
    }

    private function seedMarkupSale(string $markup): void
    {
        Sale::factory()->forCustomer(Customer::factory()->named($markup)->create())->create();
    }

    private function seedMarkupCustomer(string $markup): void
    {
        Customer::factory()->named($markup)->create();
    }

    private function seedMarkupReading(string $markup): void
    {
        MeterReading::factory()->create(['note' => $markup]);
    }
}
