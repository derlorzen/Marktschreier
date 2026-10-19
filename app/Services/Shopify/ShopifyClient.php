<?php

namespace App\Services\Shopify;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Schlanker Client für die Shopify Admin GraphQL API.
 * Beachtet die kostenbasierte Drosselung (throttleStatus) und wiederholt bei 429/5xx und THROTTLED.
 */
class ShopifyClient
{
    /** Ab diesem Restbudget wartet der Client, bis genug Punkte nachgeladen sind. */
    private const MIN_AVAILABLE = 200;

    private const MAX_ATTEMPTS = 5;

    /** @var callable(float): void */
    private $sleeper;

    public function __construct(
        private ?string $shop = null,
        private ?string $token = null,
        private ?string $version = null,
        ?callable $sleeper = null,
        private ?string $clientId = null,
        private ?string $clientSecret = null,
    ) {
        $this->shop ??= Setting::get('shopify.shop');
        $this->token ??= Setting::get('shopify.token');
        $this->clientId ??= Setting::get('shopify.client_id');
        $this->clientSecret ??= Setting::get('shopify.client_secret');
        $this->version ??= Setting::get('shopify.api_version', '2026-10');
        $this->sleeper = $sleeper ?? fn (float $seconds) => usleep((int) ($seconds * 1_000_000));
    }

    public function isConfigured(): bool
    {
        return filled($this->shop) && ($this->usesStaticToken() || (filled($this->clientId) && filled($this->clientSecret)));
    }

    /** Alte „Custom App“ aus dem Shopify-Admin mit festem Token (shpat_…). */
    private function usesStaticToken(): bool
    {
        return filled($this->token);
    }

    private function shopDomain(): string
    {
        return preg_replace('#^https?://#', '', rtrim((string) $this->shop, '/'));
    }

    /**
     * Zugriffstoken. Apps aus dem Dev Dashboard (seit 2026 der Normalfall) bekommen es über den
     * Client-Credentials-Grant, es gilt 24 Stunden und wird zwischengespeichert.
     */
    public function accessToken(bool $fresh = false): string
    {
        if ($this->usesStaticToken()) {
            return $this->token;
        }
        $key = 'shopify.access_token.'.md5($this->shopDomain().$this->clientId);
        if ($fresh) {
            Cache::forget($key);
        }
        if ($cached = Cache::get($key)) {
            return $cached;
        }
        try {
            $response = Http::asForm()->acceptJson()->timeout(30)->post('https://'.$this->shopDomain().'/admin/oauth/access_token', [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);
        } catch (ConnectionException $e) {
            throw new ShopifyException('Shopify nicht erreichbar: '.$e->getMessage(), 0, $e);
        }
        $token = $response->json('access_token');
        if (! $response->successful() || ! $token) {
            $hint = str_contains($response->body(), 'shop_not_permitted')
                ? ' Die App muss im Dev Dashboard derselben Organisation angelegt sein wie der Shop.'
                : ' Client-ID und Client-Secret prüfen und ob die App im Shop installiert ist.';
            throw new ShopifyException('Shopify gibt kein Zugriffstoken heraus (HTTP '.$response->status().').'.$hint);
        }
        $ttl = max(60, (int) $response->json('expires_in', 86399) - 600);
        Cache::put($key, $token, $ttl);

        return $token;
    }

    public function endpoint(): string
    {
        return "https://{$this->shopDomain()}/admin/api/{$this->version}/graphql.json";
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed> Inhalt von "data"
     */
    public function query(string $query, array $variables = []): array
    {
        if (! $this->isConfigured()) {
            throw new ShopifyException('Shopify ist nicht eingerichtet: Shop-Adresse und Zugriffstoken oder Client-ID und Secret fehlen.');
        }

        $attempt = 0;
        $refreshed = false;
        while (true) {
            $attempt++;
            try {
                $response = Http::withHeaders(['X-Shopify-Access-Token' => $this->accessToken()])
                    ->acceptJson()
                    ->timeout(60)
                    ->post($this->endpoint(), ['query' => $query, 'variables' => $variables ?: new \stdClass]);
            } catch (ConnectionException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new ShopifyException('Shopify nicht erreichbar: '.$e->getMessage(), 0, $e);
                }
                $this->sleep($this->backoff($attempt));

                continue;
            }

            if ($response->status() === 429 || $response->serverError()) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new ShopifyException('Shopify antwortet mit HTTP '.$response->status().'.');
                }
                $this->sleep((float) ($response->header('Retry-After') ?: $this->backoff($attempt)));

                continue;
            }

            if ($response->status() === 401 && ! $this->usesStaticToken() && ! $refreshed) {
                // Token abgelaufen oder widerrufen: einmal neu holen.
                $this->accessToken(fresh: true);
                $refreshed = true;

                continue;
            }
            if ($response->status() === 401 || $response->status() === 403) {
                throw new ShopifyException('Shopify lehnt den Zugriff ab (HTTP '.$response->status().'). Token und Berechtigungen prüfen.');
            }

            if (! $response->successful()) {
                throw new ShopifyException('Shopify-Fehler HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300));
            }

            $json = $response->json();
            if ($this->isThrottled($json)) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new ShopifyException('Shopify drosselt dauerhaft, bitte später erneut versuchen.');
                }
                $this->sleep($this->waitFor($json, self::MIN_AVAILABLE * 2));

                continue;
            }

            if (! empty($json['errors'])) {
                $msg = collect($json['errors'])->pluck('message')->filter()->implode('; ');
                throw new ShopifyException('GraphQL-Fehler: '.($msg ?: 'unbekannt'));
            }

            $this->respectBudget($json);

            return $json['data'] ?? [];
        }
    }

    private function isThrottled(?array $json): bool
    {
        foreach ($json['errors'] ?? [] as $error) {
            if (($error['extensions']['code'] ?? null) === 'THROTTLED') {
                return true;
            }
        }

        return false;
    }

    private function respectBudget(?array $json): void
    {
        $status = $json['extensions']['cost']['throttleStatus'] ?? null;
        if ($status && ($status['currentlyAvailable'] ?? PHP_INT_MAX) < self::MIN_AVAILABLE) {
            $this->sleep($this->waitFor($json, self::MIN_AVAILABLE));
        }
    }

    private function waitFor(?array $json, int $needed): float
    {
        $status = $json['extensions']['cost']['throttleStatus'] ?? [];
        $available = (float) ($status['currentlyAvailable'] ?? 0);
        $restore = max(1.0, (float) ($status['restoreRate'] ?? 50));

        return max(0.5, ($needed - $available) / $restore);
    }

    private function backoff(int $attempt): float
    {
        return min(30, 2 ** ($attempt - 1));
    }

    private function sleep(float $seconds): void
    {
        ($this->sleeper)($seconds);
    }

    /** Für den Verbindungstest in den Einstellungen. */
    public function shopName(): string
    {
        $data = $this->query('{ shop { name myshopifyDomain } }');

        return $data['shop']['name'] ?? '?';
    }
}
