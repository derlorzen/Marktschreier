<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChangeLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public static function record(Product $product, string $field, mixed $old, mixed $new, string $source, ?Variant $variant = null, ?int $userId = null): void
    {
        $fmt = fn ($v) => is_array($v) ? implode(', ', $v) : ($v === null ? null : (string) $v);
        self::create([
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'user_id' => $userId,
            'field' => $field,
            'old_value' => $fmt($old),
            'new_value' => $fmt($new),
            'source' => $source,
        ]);
    }
}
