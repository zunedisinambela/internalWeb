<?php

namespace App\Filament\Pages;

use App\Console\Commands\PruneMonitoring;
use App\Models\AuthenticationMonitoring;
use App\Models\MonitoringSetting;
use App\Models\VisitMonitoring;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;

/**
 * Retention settings for the monitoring tables.
 *
 * The package keeps its cutoff in config/user-monitoring.php, which a screen
 * cannot write to, so the numbers live in the monitoring_settings table and
 * App\Console\Commands\PruneMonitoring reads them from there.
 *
 * @property-read Schema $form
 */
class MonitoringSettings extends Page
{
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Penyimpanan Data';

    protected static ?string $slug = 'monitoring';

    protected static ?int $navigationSort = 93;

    protected string $view = 'filament.pages.monitoring-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function getTitle(): string
    {
        return 'Penyimpanan data pemantauan';
    }

    public function mount(): void
    {
        $this->form->fill(
            MonitoringSetting::current()->only([
                'visit_retention_days',
                'authentication_retention_days',
                'activity_retention_days',
            ])
        );
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Penghapusan otomatis')
                    ->description('Data yang lebih tua dari jumlah hari di bawah ini dihapus sekali sehari. Kosongkan sebuah kolom untuk menyimpan tabel itu selamanya.')
                    ->columns(2)
                    ->components([
                        TextInput::make('visit_retention_days')
                            ->label('Simpan kunjungan selama')
                            ->numeric()
                            ->minValue(1)
                            // Two decades. The column is an unsignedSmallInteger,
                            // so anything past 65535 would silently wrap.
                            ->maxValue(7300)
                            ->suffix('hari')
                            ->placeholder('Simpan selamanya')
                            ->helperText(fn (): string => number_format(VisitMonitoring::query()->count()).' baris tersimpan saat ini.'),

                        TextInput::make('authentication_retention_days')
                            ->label('Simpan riwayat masuk selama')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(7300)
                            ->suffix('hari')
                            ->placeholder('Simpan selamanya')
                            // Sign-in history is the privilege-escalation trail,
                            // so the default of keeping it is the safe one and
                            // the screen says as much rather than staying quiet.
                            ->helperText(fn (): string => number_format(AuthenticationMonitoring::query()->count())
                                .' baris tersimpan saat ini. Riwayat masuk adalah catatan siapa yang punya akses dan kapan — menghapusnya memperpendek seberapa jauh ke belakang sebuah penelusuran bisa menjangkau.'),

                        TextInput::make('activity_retention_days')
                            ->label('Simpan log aktivitas selama')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(7300)
                            ->suffix('hari')
                            ->placeholder('Simpan selamanya')
                            // Left blank by default on purpose. Deletions made
                            // on the other two screens are recorded here, so
                            // expiring this expires the evidence of those too.
                            ->helperText(fn (): string => number_format(Activity::query()->count())
                                .' baris tersimpan saat ini. Pemberian peran dan penghapusan yang dilakukan di layar lain tercatat di sini — inilah jejak yang dipakai untuk memeriksa layar-layar tersebut.'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        MonitoringSetting::current()->update([
            // An emptied field arrives as null, which is what "keep forever"
            // is stored as.
            'visit_retention_days' => $data['visit_retention_days'] ?: null,
            'authentication_retention_days' => $data['authentication_retention_days'] ?: null,
            'activity_retention_days' => $data['activity_retention_days'] ?: null,
        ]);

        Notification::make()
            ->title('Pengaturan tersimpan')
            ->body($this->schedulerHasRun()
                ? 'Diterapkan pada jadwal harian berikutnya.'
                : 'Tidak ada yang akan dihapus sampai scheduler berjalan — lihat keterangan di bawah.')
            ->success()
            ->send();
    }

    /**
     * The page body. Filament v5 builds this as a schema rather than as Blade,
     * so the form, its submit button and the status callout are all components
     * here and the view is only a wrapper.
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make($this->getFormActions())->key('form-actions'),
                    ]),

                $this->getSchedulerStatusComponent(),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Simpan')
                ->submit('save'),
        ];
    }

    /**
     * Says whether retention is actually being applied.
     *
     * This is as much the point of the page as the form is. `php artisan dev`
     * starts serve, queue:listen, pail and vite but no scheduler, so a saved
     * retention with nothing running would quietly keep every record forever
     * while the screen showed a number. The last run makes that visible.
     */
    protected function getSchedulerStatusComponent(): Callout
    {
        $lastPrunedAt = MonitoringSetting::current()->last_pruned_at;

        if ($lastPrunedAt === null) {
            return Callout::make('Penghapusan otomatis tidak berjalan')
                ->danger()
                ->components([
                    Text::make('Belum ada data yang pernah dihapus. Jadwal ini butuh penjalan: `php artisan schedule:work` saat mengembangkan, atau entri cron setiap menit yang memanggil `php artisan schedule:run` di server. Sampai itu ada, pakai tombol Jalankan sekarang di atas.'),
                ]);
        }

        // The job is scheduled daily, so a gap this wide means it stopped.
        if ($lastPrunedAt->lt(now()->subDays(2))) {
            return Callout::make('Terakhir berjalan '.$lastPrunedAt->diffForHumans())
                ->warning()
                ->components([
                    Text::make('Tugas ini dijadwalkan harian, jadi jeda selama ini berarti scheduler sudah berhenti. Data tetap disimpan tanpa memedulikan pengaturan di atas.'),
                ]);
        }

        return Callout::make('Terakhir berjalan '.$lastPrunedAt->diffForHumans())
            ->info()
            ->components([
                Text::make('Berjalan setiap hari pukul 03:00 ('.$lastPrunedAt->translatedFormat('d F Y H:i').'). Setiap penghapusan tercatat di Log Aktivitas.'),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('prune')
                ->label('Jalankan sekarang')
                ->icon(Heroicon::Trash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Terapkan penghapusan sekarang')
                ->modalDescription('Menghapus semua data yang melewati batas di atas. Tindakan ini tidak bisa dibatalkan.')
                ->modalSubmitActionLabel('Hapus sekarang')
                // Present so retention is usable before anyone sets up cron,
                // and so the setting can be verified rather than trusted.
                ->action(function (): void {
                    Artisan::call(PruneMonitoring::class);

                    Notification::make()
                        ->title('Penghapusan dijalankan')
                        ->body(trim(Artisan::output()))
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function schedulerHasRun(): bool
    {
        return MonitoringSetting::current()->last_pruned_at !== null;
    }
}
