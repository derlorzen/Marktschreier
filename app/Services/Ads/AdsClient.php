<?php

namespace App\Services\Ads;

use App\Models\MarketplaceConnection;
use App\Services\Marketplaces\MarketplaceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Amazon Advertising API (Sponsored Products v3, Reporting v3), Region Europa. */
class AdsClient
{
    public const PROVIDER = 'amazon_ads';

    private ?MarketplaceConnection $connection = null;

    /** @var callable(float): void */
    private $sleeper;

    public function __construct(?callable $sleeper = null)
    {
        $this->sleeper = $sleeper ?? fn (float $s) => usleep((int) ($s * 1_000_000));
    }

    public function connection(): MarketplaceConnection
    {
        return $this->connection ??= MarketplaceConnection::for(self::PROVIDER);
    }

    public function appConfigured(): bool
    {
        return filled(config('marktschreier.amazon_ads.client_id')) && filled(config('marktschreier.amazon_ads.client_secret'));
    }

    public function sleep(float $s): void
    {
        ($this->sleeper)($s);
    }

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return config('marktschreier.amazon_ads.authorize_url').'?'.http_build_query([
            'client_id' => config('marktschreier.amazon_ads.client_id'),
            'scope' => 'advertising::campaign_management',
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): void
    {
        $json = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
        $c = $this->connection();
        $c->storeSecrets(['refresh_token' => $json['refresh_token'] ?? null, 'access_token' => $json['access_token'], 'access_expires_at' => time() + (int) ($json['expires_in'] ?? 3600) - 120]);
        $c->update(['status' => 'connected', 'connected_at' => now(), 'last_error' => null]);
    }

    private function token(array $form): array
    {
        try {
            $r = Http::asForm()->acceptJson()->timeout(30)->post((string) config('marktschreier.amazon_ads.token_url'), $form + [
                'client_id' => config('marktschreier.amazon_ads.client_id'),
                'client_secret' => config('marktschreier.amazon_ads.client_secret'),
            ]);
        } catch (ConnectionException $e) {
            throw new MarketplaceException('Amazon nicht erreichbar: '.$e->getMessage(), 0, $e);
        }
        if (! $r->successful() || ! $r->json('access_token')) {
            throw new MarketplaceException('Amazon Werbung verweigert das Token: '.($r->json('error_description') ?? $r->json('error') ?? 'HTTP '.$r->status()));
        }

        return $r->json();
    }

    private function accessToken(): string
    {
        $c = $this->connection();
        $s = $c->secrets();
        if (! empty($s['access_token']) && ($s['access_expires_at'] ?? 0) > time()) {
            return $s['access_token'];
        }
        if (empty($s['refresh_token'])) {
            throw new MarketplaceException('Amazon Werbung ist nicht verbunden.');
        }
        try {
            $json = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $s['refresh_token']]);
        } catch (MarketplaceException $e) {
            $c->update(['status' => 'error', 'last_error' => $e->getMessage().' Bitte neu verbinden.']);
            throw $e;
        }
        $c->storeSecrets(['access_token' => $json['access_token'], 'access_expires_at' => time() + (int) ($json['expires_in'] ?? 3600) - 120]);

        return $json['access_token'];
    }

    /**
     * @param  string|null  $mediaType  z. B. spCampaign für application/vnd.spCampaign.v3+json
     */
    public function request(string $method, string $path, ?array $body = null, ?string $mediaType = null, bool $withProfile = true): array
    {
        $headers = [
            'Amazon-Advertising-API-ClientId' => (string) config('marktschreier.amazon_ads.client_id'),
        ];
        if ($withProfile) {
            $profile = $this->connection()->setting('profile_id');
            if (! $profile) {
                throw new MarketplaceException('Für Amazon Werbung ist noch kein Werbekonto (Profil) gewählt.');
            }
            $headers['Amazon-Advertising-API-Scope'] = (string) $profile;
        }
        if ($mediaType) {
            $type = str_contains($mediaType, '/') ? $mediaType : 'application/vnd.'.$mediaType.'.v3+json';
            $headers['Content-Type'] = $type;
            $headers['Accept'] = $type;
        }
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $req = Http::withToken($this->accessToken())->withHeaders($headers)->timeout(60);
                $url = rtrim((string) config('marktschreier.amazon_ads.endpoint'), '/').$path;
                $response = $body === null ? $req->send($method, $url) : $req->withBody(json_encode($body), $headers['Content-Type'] ?? 'application/json')->send($method, $url);
            } catch (ConnectionException $e) {
                if ($attempt >= 5) {
                    throw new MarketplaceException('Amazon Werbung nicht erreichbar: '.$e->getMessage(), 0, $e);
                }
                $this->sleep(2 ** $attempt);

                continue;
            }
            if (($response->status() === 429 || $response->serverError()) && $attempt < 5) {
                $this->sleep((float) ($response->header('Retry-After') ?: 2 ** $attempt));

                continue;
            }
            if (! $response->successful()) {
                $msg = $response->json('details') ?? $response->json('message') ?? $response->json('code') ?? 'HTTP '.$response->status();
                throw new MarketplaceException('Amazon Werbung: '.(is_array($msg) ? json_encode($msg) : $msg));
            }

            return $response->json() ?? [];
        }
    }

    /** Lädt einen fertigen Bericht (GZIP-JSON) von der vorsignierten Adresse, ohne eigene Zugangsdaten. */
    public function download(string $url): array
    {
        $r = Http::timeout(120)->get($url);
        if (! $r->successful()) {
            throw new MarketplaceException('Bericht konnte nicht geladen werden (HTTP '.$r->status().').');
        }
        $body = $r->body();
        $json = str_starts_with($body, "\x1f\x8b") ? gzdecode($body) : $body;

        return json_decode((string) $json, true) ?? [];
    }
}
