<?php

namespace App\Services\Marketplaces\Amazon;

use App\Models\MarketplaceConnection;
use App\Services\Marketplaces\MarketplaceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Amazon Selling Partner API. Zugriff über Login with Amazon (LWA), seit 2023 ohne AWS-Signatur.
 */
class AmazonClient
{
    private ?MarketplaceConnection $connection = null;

    /** @var callable(float): void */
    private $sleeper;

    public function __construct(?callable $sleeper = null)
    {
        $this->sleeper = $sleeper ?? fn (float $s) => usleep((int) ($s * 1_000_000));
    }

    public function connection(): MarketplaceConnection
    {
        return $this->connection ??= MarketplaceConnection::for(MarketplaceConnection::AMAZON);
    }

    public function appConfigured(): bool
    {
        return filled(config('marktschreier.amazon.client_id')) && filled(config('marktschreier.amazon.client_secret'));
    }

    public function canAuthorizeViaWebsite(): bool
    {
        return $this->appConfigured() && filled(config('marktschreier.amazon.app_id'));
    }

    public function marketplaceId(): string
    {
        return (string) config('marktschreier.amazon.marketplace_id');
    }

    public function sellerId(): string
    {
        $id = $this->connection()->secrets()['seller_id'] ?? null;
        if (! $id) {
            throw new MarketplaceException('Amazon-Verkäufer-ID fehlt. Bitte Amazon neu verbinden.');
        }

        return $id;
    }

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        $query = ['application_id' => config('marktschreier.amazon.app_id'), 'state' => $state, 'redirect_uri' => $redirectUri];
        if (config('marktschreier.amazon.draft')) {
            $query['version'] = 'beta';
        }

        return rtrim((string) config('marktschreier.amazon.seller_central'), '/').'/apps/authorize/consent?'.http_build_query($query);
    }

    public function exchangeCode(string $code, string $redirectUri, string $sellerId): void
    {
        $json = $this->lwa(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
        $this->saveTokens($json, $sellerId);
    }

    /** Für Verkäufer, die die App selbst autorisiert haben und ein Refresh-Token einfügen. */
    public function connectWithRefreshToken(string $refreshToken, string $sellerId): void
    {
        $json = $this->lwa(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
        $json['refresh_token'] = $refreshToken;
        $this->saveTokens($json, $sellerId);
    }

    private function saveTokens(array $json, string $sellerId): void
    {
        $c = $this->connection();
        $c->storeSecrets([
            'refresh_token' => $json['refresh_token'] ?? null,
            'access_token' => $json['access_token'],
            'access_expires_at' => now()->addSeconds((int) ($json['expires_in'] ?? 3600) - 120)->timestamp,
            'seller_id' => $sellerId,
        ]);
        $c->update(['status' => 'connected', 'connected_at' => now(), 'last_error' => null]);
    }

    private function lwa(array $form): array
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(30)->post('https://api.amazon.com/auth/o2/token', $form + [
                'client_id' => config('marktschreier.amazon.client_id'),
                'client_secret' => config('marktschreier.amazon.client_secret'),
            ]);
        } catch (ConnectionException $e) {
            throw new MarketplaceException('Amazon nicht erreichbar: '.$e->getMessage(), 0, $e);
        }
        if (! $response->successful() || ! $response->json('access_token')) {
            throw new MarketplaceException('Amazon verweigert das Token: '.($response->json('error_description') ?? $response->json('error') ?? 'HTTP '.$response->status()));
        }

        return $response->json();
    }

    public function accessToken(): string
    {
        $c = $this->connection();
        $s = $c->secrets();
        if (! empty($s['access_token']) && ($s['access_expires_at'] ?? 0) > time()) {
            return $s['access_token'];
        }
        if (empty($s['refresh_token'])) {
            throw new MarketplaceException('Amazon ist nicht verbunden.');
        }
        try {
            $json = $this->lwa(['grant_type' => 'refresh_token', 'refresh_token' => $s['refresh_token']]);
        } catch (MarketplaceException $e) {
            $c->update(['status' => 'error', 'last_error' => $e->getMessage().' Bitte Amazon neu verbinden.']);
            throw $e;
        }
        $c->storeSecrets(['access_token' => $json['access_token'], 'access_expires_at' => now()->addSeconds((int) ($json['expires_in'] ?? 3600) - 120)->timestamp]);

        return $json['access_token'];
    }

    /** @return array<string, mixed> */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $req = Http::withHeaders(['x-amz-access-token' => $this->accessToken(), 'user-agent' => 'Marktschreier/1.0 (Language=PHP)'])
                    ->acceptJson()->timeout(60);
                $url = rtrim((string) config('marktschreier.amazon.endpoint'), '/').$path.($query ? '?'.http_build_query($query) : '');
                $response = $body === null ? $req->send($method, $url) : $req->send($method, $url, ['json' => $body]);
            } catch (ConnectionException $e) {
                if ($attempt >= 5) {
                    throw new MarketplaceException('Amazon nicht erreichbar: '.$e->getMessage(), 0, $e);
                }
                ($this->sleeper)(2 ** $attempt);

                continue;
            }
            if (($response->status() === 429 || $response->serverError()) && $attempt < 5) {
                // Amazon drosselt je Operation, dann kurz warten.
                ($this->sleeper)(min(30, 2 ** $attempt));

                continue;
            }
            if ($response->status() === 403) {
                throw new MarketplaceException('Amazon verweigert den Zugriff. Fehlt der App eine Rolle (z. B. Produktlisting) oder ist die Freigabe abgelaufen?');
            }
            if (! $response->successful()) {
                $msg = collect($response->json('errors') ?? [])->map(fn ($e) => $e['message'] ?? $e['code'] ?? '')->filter()->implode(' ') ?: 'HTTP '.$response->status();
                throw new MarketplaceException('Amazon: '.$msg);
            }

            return $response->json() ?? [];
        }
    }
}
