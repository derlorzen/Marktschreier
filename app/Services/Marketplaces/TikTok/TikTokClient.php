<?php

namespace App\Services\Marketplaces\TikTok;

use App\Models\MarketplaceConnection;
use App\Services\Marketplaces\MarketplaceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * TikTok Shop Open API (Version 202309). Jede Anfrage wird mit HMAC-SHA256 signiert:
 * Secret + Pfad + sortierte Parameter (ohne sign, access_token) + Body + Secret.
 */
class TikTokClient
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
        return $this->connection ??= MarketplaceConnection::for(MarketplaceConnection::TIKTOK);
    }

    public function appConfigured(): bool
    {
        return filled(config('marktschreier.tiktok.app_key')) && filled(config('marktschreier.tiktok.app_secret'));
    }

    public function authorizeUrl(string $state): string
    {
        if ($serviceId = config('marktschreier.tiktok.service_id')) {
            return 'https://services.tiktokshop.com/open/authorize?'.http_build_query(['service_id' => $serviceId, 'state' => $state]);
        }

        return rtrim(config('marktschreier.tiktok.auth'), '/').'/oauth/authorize?'.http_build_query(['app_key' => config('marktschreier.tiktok.app_key'), 'state' => $state]);
    }

    public function exchangeCode(string $code): void
    {
        $this->storeTokens($this->tokenCall('get', ['auth_code' => $code, 'grant_type' => 'authorized_code']));
        // Shop-Kennung (cipher) für alle weiteren Aufrufe
        $shops = $this->request('GET', '/authorization/202309/shops')['shops'] ?? [];
        $shop = $shops[0] ?? null;
        if (! $shop) {
            throw new MarketplaceException('Für dieses Konto ist kein TikTok Shop freigegeben.');
        }
        $c = $this->connection();
        $c->storeSecrets(['shop_cipher' => $shop['cipher']]);
        $c->update(['status' => 'connected', 'connected_at' => now(), 'last_error' => null, 'account_name' => $shop['name'] ?? null]);
    }

    private function tokenCall(string $kind, array $params): array
    {
        try {
            $res = Http::acceptJson()->timeout(30)->get(rtrim(config('marktschreier.tiktok.auth'), '/').'/api/v2/token/'.$kind, $params + [
                'app_key' => config('marktschreier.tiktok.app_key'),
                'app_secret' => config('marktschreier.tiktok.app_secret'),
            ]);
        } catch (ConnectionException $e) {
            throw new MarketplaceException('TikTok nicht erreichbar: '.$e->getMessage(), 0, $e);
        }
        if (($res->json('code') ?? -1) !== 0 || ! $res->json('data.access_token')) {
            throw new MarketplaceException('TikTok verweigert das Token: '.($res->json('message') ?? 'HTTP '.$res->status()));
        }

        return $res->json('data');
    }

    private function storeTokens(array $data): void
    {
        $this->connection()->storeSecrets([
            'access_token' => $data['access_token'],
            // TikTok liefert Ablaufzeitpunkte als Unix-Zeit
            'access_expires_at' => (int) ($data['access_token_expire_in'] ?? time() + 3600) - 300,
            'refresh_token' => $data['refresh_token'] ?? null,
        ]);
    }

    public function accessToken(): string
    {
        $c = $this->connection();
        $s = $c->secrets();
        if (! empty($s['access_token']) && ($s['access_expires_at'] ?? 0) > time()) {
            return $s['access_token'];
        }
        if (empty($s['refresh_token'])) {
            throw new MarketplaceException('TikTok Shop ist nicht verbunden.');
        }
        try {
            $this->storeTokens($this->tokenCall('refresh', ['refresh_token' => $s['refresh_token'], 'grant_type' => 'refresh_token']));
        } catch (MarketplaceException $e) {
            $c->update(['status' => 'error', 'last_error' => $e->getMessage().' Bitte TikTok Shop neu verbinden.']);
            throw $e;
        }

        return $this->connection()->secrets()['access_token'];
    }

    public static function sign(string $path, array $query, string $body, string $secret): string
    {
        unset($query['sign'], $query['access_token']);
        ksort($query);
        $string = $path;
        foreach ($query as $k => $v) {
            if (! is_array($v)) {
                $string .= $k.$v;
            }
        }
        $string .= $body;

        return hash_hmac('sha256', $secret.$string.$secret, $secret);
    }

    /** @return array<string, mixed> Inhalt von "data" */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            $q = $query + ['app_key' => config('marktschreier.tiktok.app_key'), 'timestamp' => time()];
            if (! preg_match('#^/(authorization|seller)/#', $path) && ($cipher = $this->connection()->secrets()['shop_cipher'] ?? null)) {
                $q['shop_cipher'] = $cipher;
            }
            $json = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $q['sign'] = self::sign($path, $q, $method === 'GET' ? '' : $json, (string) config('marktschreier.tiktok.app_secret'));
            try {
                $req = Http::withHeaders(['x-tts-access-token' => $this->accessToken(), 'content-type' => 'application/json'])->timeout(60);
                $url = rtrim(config('marktschreier.tiktok.api'), '/').$path.'?'.http_build_query($q);
                $res = $method === 'GET' ? $req->get($url) : $req->withBody($json, 'application/json')->send($method, $url);
            } catch (ConnectionException $e) {
                if ($attempt >= 4) {
                    throw new MarketplaceException('TikTok nicht erreichbar: '.$e->getMessage(), 0, $e);
                }
                ($this->sleeper)(2 ** $attempt);

                continue;
            }
            if (($res->status() === 429 || $res->serverError()) && $attempt < 4) {
                ($this->sleeper)(2 ** $attempt);

                continue;
            }
            if (($res->json('code') ?? -1) !== 0) {
                throw new MarketplaceException('TikTok: '.($res->json('message') ?? 'HTTP '.$res->status()));
            }

            return $res->json('data') ?? [];
        }
    }
}
