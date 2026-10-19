<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Abgleich und Zeitplan')]
class Schedule extends Component
{
    public const JOBS = [
        'billbee' => ['label' => 'EK und Bestand aus Billbee', 'default' => 15, 'options' => [5, 10, 15, 30, 60]],
        'orders' => ['label' => 'Verkäufe aus Billbee', 'default' => 30, 'options' => [15, 30, 60, 120]],
        'shopify' => ['label' => 'Änderungen aus Shopify', 'default' => 60, 'options' => [15, 30, 60, 120, 240]],
        'listings' => ['label' => 'Angebote auf eBay und Amazon lesen', 'default' => 60, 'options' => [30, 60, 120, 240, 1440]],
    ];

    /** @var array<string, array{enabled: bool, minutes: int}> */
    public array $jobs = [];

    public ?string $notice = null;

    public static function interval(string $job): int
    {
        return (int) rescue(fn () => Setting::get("schedule.{$job}.minutes"), null, false) ?: (int) (config("marktschreier.sync.{$job}_interval_minutes") ?: self::JOBS[$job]['default']);
    }

    public static function enabled(string $job): bool
    {
        return rescue(fn () => Setting::get("schedule.{$job}.enabled", '1'), '1', false) !== '0';
    }

    public function mount(): void
    {
        foreach (array_keys(self::JOBS) as $job) {
            $this->jobs[$job] = ['enabled' => self::enabled($job), 'minutes' => self::interval($job)];
        }
    }

    public function save(): void
    {
        foreach (self::JOBS as $job => $def) {
            $minutes = (int) ($this->jobs[$job]['minutes'] ?? $def['default']);
            if (! in_array($minutes, $def['options'], true)) {
                $minutes = $def['default'];
            }
            Setting::put("schedule.{$job}.minutes", (string) $minutes);
            Setting::put("schedule.{$job}.enabled", ! empty($this->jobs[$job]['enabled']) ? '1' : '0');
        }
        $this->notice = 'Gespeichert. Der neue Zeitplan gilt ab der nächsten vollen Minute.';
    }

    public static function label(int $minutes): string
    {
        return $minutes >= 1440 ? 'einmal am Tag' : ($minutes >= 60 ? 'alle '.($minutes / 60).' Std.' : "alle {$minutes} Min.");
    }

    public function render()
    {
        return view('livewire.settings.schedule');
    }
}
