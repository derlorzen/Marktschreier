<?php

namespace App\Livewire;

use App\Models\Channel;
use App\Models\PriceRule;
use App\Models\PriceRuleLine;
use App\Services\Pricing\PriceCalculator;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Kanäle und Preisregeln')]
class Pricing extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $channels = [];

    /** @var array<int, array<string, mixed>> */
    public array $rules = [];

    public ?string $notice = null;

    public function mount(): void
    {
        $this->load();
    }

    private function load(): void
    {
        $this->channels = Channel::orderBy('position')->get()->mapWithKeys(fn (Channel $c) => [$c->id => [
            'name' => $c->name,
            'active' => $c->active,
            'fee_percent' => Format::decimalForInput($c->fee_percent),
            'fee_fixed' => Format::decimalForInput($c->fee_fixed),
            'shipping_cost' => Format::decimalForInput($c->shipping_cost),
            'billbee_platforms' => (string) $c->billbee_platforms,
        ]])->all();

        $this->rules = PriceRule::with('lines')->orderBy('name')->get()->mapWithKeys(fn (PriceRule $r) => [$r->id => [
            'name' => $r->name,
            'description' => (string) $r->description,
            'min_profit' => Format::decimalForInput($r->min_profit),
            'lines' => collect($this->channels)->mapWithKeys(function ($c, $channelId) use ($r) {
                $line = $r->lines->firstWhere('channel_id', $channelId);

                return [$channelId => [
                    'target_margin' => $line ? Format::decimalForInput($line->target_margin) : '',
                    'ending' => $line->ending ?? '99',
                ]];
            })->all(),
        ]])->all();
    }

    protected function rules(): array
    {
        $decimal = function ($attr, $value, $fail) {
            if ($value !== '' && $value !== null && Format::parseDecimal($value) === null) {
                $fail('Bitte eine Zahl eingeben, z. B. 2,5.');
            }
        };

        return [
            'channels.*.name' => ['required', 'string', 'max:60'],
            'channels.*.fee_percent' => [$decimal],
            'channels.*.fee_fixed' => [$decimal],
            'channels.*.shipping_cost' => [$decimal],
            'channels.*.billbee_platforms' => ['nullable', 'string', 'max:255'],
            'rules.*.name' => ['required', 'string', 'max:80'],
            'rules.*.min_profit' => [$decimal],
            'rules.*.lines.*.target_margin' => [$decimal, function ($attr, $value, $fail) {
                $n = Format::parseDecimal($value);
                if ($n !== null && ((float) $n < 0 || (float) $n >= 80)) {
                    $fail('Zielmarge zwischen 0 und 80 %.');
                }
            }],
            'rules.*.lines.*.ending' => ['in:99,95,90,00'],
        ];
    }

    public function save(): void
    {
        $this->validate();
        DB::transaction(function () {
            foreach ($this->channels as $id => $c) {
                Channel::whereKey($id)->update([
                    'name' => trim($c['name']),
                    'active' => (bool) $c['active'],
                    'fee_percent' => Format::parseDecimal($c['fee_percent']) ?? 0,
                    'fee_fixed' => Format::parseDecimal($c['fee_fixed']) ?? 0,
                    'shipping_cost' => Format::parseDecimal($c['shipping_cost']) ?? 0,
                    'billbee_platforms' => trim($c['billbee_platforms']) ?: null,
                ]);
            }
            foreach ($this->rules as $id => $r) {
                $rule = PriceRule::find($id);
                $rule->update(['name' => trim($r['name']), 'description' => trim($r['description']) ?: null, 'min_profit' => Format::parseDecimal($r['min_profit']) ?? 0]);
                foreach ($r['lines'] as $channelId => $line) {
                    $margin = Format::parseDecimal($line['target_margin']);
                    if ($margin === null) {
                        $rule->lines()->where('channel_id', $channelId)->delete();

                        continue;
                    }
                    $rule->lines()->updateOrCreate(['channel_id' => $channelId], ['target_margin' => $margin, 'ending' => $line['ending']]);
                }
            }
        });
        $this->load();
        $this->notice = 'Gespeichert.';
    }

    public function addRule(): void
    {
        $rule = PriceRule::create(['name' => 'Neues Regelwerk', 'min_profit' => 1]);
        foreach (array_keys($this->channels) as $channelId) {
            $rule->lines()->create(['channel_id' => $channelId, 'target_margin' => 30, 'ending' => '99']);
        }
        $this->load();
    }

    public function deleteRule(int $id): void
    {
        if (PriceRule::count() <= 1) {
            $this->notice = 'Mindestens ein Regelwerk muss bleiben.';

            return;
        }
        PriceRule::whereKey($id)->delete();
        $this->load();
        $this->notice = 'Regelwerk gelöscht. Bereits gesetzte Preise bleiben unverändert.';
    }

    public function render()
    {
        // Beispielrechnung mit den noch nicht gespeicherten Eingaben, damit man sofort sieht, was herauskommt.
        $calc = new PriceCalculator;
        $examples = [];
        foreach ($this->rules as $rid => $r) {
            $rule = new PriceRule(['min_profit' => Format::parseDecimal($r['min_profit']) ?? 0]);
            $lines = [];
            foreach ($r['lines'] as $cid => $line) {
                if (($m = Format::parseDecimal($line['target_margin'])) !== null) {
                    $lines[] = new PriceRuleLine(['channel_id' => $cid, 'target_margin' => $m, 'ending' => $line['ending']]);
                }
            }
            $rule->setRelation('lines', collect($lines));
            foreach ($this->channels as $cid => $c) {
                $channel = new Channel([
                    'fee_percent' => Format::parseDecimal($c['fee_percent']) ?? 0,
                    'fee_fixed' => Format::parseDecimal($c['fee_fixed']) ?? 0,
                    'shipping_cost' => Format::parseDecimal($c['shipping_cost']) ?? 0,
                ]);
                $channel->id = $cid;
                $price = $calc->calculate(5.0, $channel, $rule);
                $examples[$rid][$cid] = $price === null ? null : ['price' => $price, 'profit' => $calc->profit($price, 5.0, $channel)];
            }
        }

        return view('livewire.pricing', ['examples' => $examples]);
    }
}
