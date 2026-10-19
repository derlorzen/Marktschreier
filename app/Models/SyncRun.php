<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    public const TYPE_SHOPIFY_IMPORT = 'shopify_import';
    public const TYPE_SHOPIFY_PUSH = 'shopify_push';
    public const TYPE_BILLBEE = 'billbee_sync';
    public const TYPE_BILLBEE_ORDERS = 'billbee_orders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['stats' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public static function start(string $type): self
    {
        return self::create(['type' => $type, 'status' => 'running', 'started_at' => now(), 'stats' => []]);
    }

    public function finish(array $stats, ?string $message = null): void
    {
        $this->update(['status' => 'ok', 'stats' => $stats, 'message' => $message, 'finished_at' => now()]);
    }

    public function fail(string $message, array $stats = []): void
    {
        $this->update(['status' => 'failed', 'stats' => $stats, 'message' => $message, 'finished_at' => now()]);
    }

    public function typeLabel(): string
    {
        return [self::TYPE_SHOPIFY_IMPORT => 'Import aus Shopify', self::TYPE_SHOPIFY_PUSH => 'Übertragung zu Shopify', self::TYPE_BILLBEE => 'Abgleich mit Billbee', self::TYPE_BILLBEE_ORDERS => 'Verkäufe aus Billbee'][$this->type] ?? $this->type;
    }

    public static function lastSuccessful(string $type): ?self
    {
        return self::where('type', $type)->where('status', 'ok')->latest('finished_at')->first();
    }
}
