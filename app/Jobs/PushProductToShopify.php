<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Shopify\ProductPusher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PushProductToShopify implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $productId, public ?int $userId = null)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public function handle(ProductPusher $pusher): void
    {
        $product = Product::find($this->productId);
        if ($product && $product->hasPendingChanges()) {
            $pusher->push($product, $this->userId);
        }
    }
}
