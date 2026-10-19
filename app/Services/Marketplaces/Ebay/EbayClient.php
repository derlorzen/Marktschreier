<?php

namespace App\Services\Marketplaces\Ebay;

use App\Models\MarketplaceConnection;
use App\Services\Marketplaces\MarketplaceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

/**
 * eBay: OAuth mit Benutzerzustimmung, REST (Account, Taxonomy) und Trading API (XML).
 *
 * Listings legt Marktschreier über die Trading API an, nicht über die Inventory API:
 * Angebote aus der Inventory API lassen sich nur über diese API ändern, Billbee könnte
 * dann den Bestand nicht mehr pflegen. So bleibt Billbee für den Bestand zuständig.
 */
class EbayClient
{
    public const SCOPES = [
        'https://api.ebay.com/oauth/api_scope',
        'https://api.ebay.com/oauth/api_scope/sell.account.readonly',
        'https://api.ebay.com/oauth/api_scope/sell.inventory',
    ];

    private ?MarketplaceConnection $connection = null;

    /** @var callable(float): void */
    private $sleeper;

    public function __construct(?callable $sleeper = null)
    {
        $this->sleeper = $sleeper ?? fn (float $s) => usleep((int) ($s * 1_000_000));
    }

    public function connection(): MarketplaceConnection
    {
        return $this->connection ??= MarketplaceConnection::for(MarketplaceConnection::EBAY);
    }

    public function appConfigured(): bool
    {
        return filled(config('marktschreier.ebay.client_id')) && filled(config('marktschreier.ebay.client_secret')) && filled(config('marktschreier.ebay.runame'));
    }

    private function base(string $kind): string
    {
        $sandbox = (bool) config('marktschreier.ebay.sandbox');

        return match ($kind) {
            'auth' => $sandbox ? 'https://auth.sandbox.ebay.com' : 'https://auth.ebay.com',
            'api' => $sandbox ? 'https://api.sandbox.ebay.com' : 'https://api.ebay.com',
        };
    }

    public function authorizeUrl(string $state): string
    {
        return $this->base('auth').'/oauth2/authorize?'.http_build_query([
            'client_id' => config('marktschreier.ebay.client_id'),
            'redirect_uri' => config('marktschreier.ebay.runame'),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'prompt' => 'login',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Tauscht den Code aus dem Rücksprung gegen Tokens und speichert sie. */
    public function exchangeCode(string $code): void
    {
        $json = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => config('marktschreier.ebay.runame')]);
        $c = $this->connection();
        $c->storeSecrets([
            'access_token' => $json['access_token'],
            'access_expires_at' => now()->addSeconds((int) ($json['expires_in'] ?? 7200) - 120)->timestamp,
            'refresh_token' => $json['refresh_token'] ?? null,
            'refresh_expires_at' => isset($json['refresh_token_expires_in']) ? now()->addSeconds((int) $json['refresh_token_expires_in'])->timestamp : null,
        ]);
        $c->update(['status' => 'connected', 'connected_at' => now(), 'last_error' => null]);
    }

    private function tokenRequest(array $form): array
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(30)
                ->withBasicAuth((string) config('marktschreier.ebay.client_id'), (string) config('marktschreier.ebay.client_secret'))
                ->post($this->base('api').'/identity/v1/oauth2/token', $form);
        } catch (ConnectionException $e) {
            throw new MarketplaceException('eBay nicht erreichbar: '.$e->getMessage(), 0, $e);
        }
        if (! $response->successful() || ! $response->json('access_token')) {
            $err = $response->json('error_description') ?? $response->json('error') ?? 'HTTP '.$response->status();
            throw new MarketplaceException('eBay verweigert das Token: '.$err);
        }

        return $response->json();
    }

    /** Gültiges Benutzer-Token, bei Bedarf über das Refresh-Token erneuert. */
    public function userToken(): string
    {
        $c = $this->connection();
        $secrets = $c->secrets();
        if (! empty($secrets['access_token']) && ($secrets['access_expires_at'] ?? 0) > time()) {
            return $secrets['access_token'];
        }
        if (empty($secrets['refresh_token'])) {
            throw new MarketplaceException('eBay ist nicht verbunden.');
        }
        try {
            $json = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $secrets['refresh_token'], 'scope' => implode(' ', self::SCOPES)]);
        } catch (MarketplaceException $e) {
            $c->update(['status' => 'error', 'last_error' => $e->getMessage().' Bitte eBay neu verbinden.']);
            throw $e;
        }
        $c->storeSecrets(['access_token' => $json['access_token'], 'access_expires_at' => now()->addSeconds((int) ($json['expires_in'] ?? 7200) - 120)->timestamp]);

        return $json['access_token'];
    }

    /** Anwendungs-Token für öffentliche Daten (Kategorien). */
    public function appToken(): string
    {
        return cache()->remember('ebay.app_token', 7000, fn () => $this->tokenRequest([
            'grant_type' => 'client_credentials', 'scope' => 'https://api.ebay.com/oauth/api_scope',
        ])['access_token']);
    }

    /** REST-Aufruf, z. B. Account- oder Taxonomy-API. */
    public function rest(string $method, string $path, array $query = [], ?array $body = null, bool $appToken = false): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            $token = $appToken ? $this->appToken() : $this->userToken();
            try {
                $req = Http::withToken($token)->acceptJson()->timeout(60)
                    ->withHeaders(['X-EBAY-C-MARKETPLACE-ID' => config('marktschreier.ebay.marketplace_id'), 'Accept-Language' => 'de-DE', 'Content-Language' => 'de-DE']);
                $url = $this->base('api').$path.($query ? '?'.http_build_query($query) : '');
                $response = $body === null ? $req->send($method, $url) : $req->send($method, $url, ['json' => $body]);
            } catch (ConnectionException $e) {
                if ($attempt >= 4) {
                    throw new MarketplaceException('eBay nicht erreichbar: '.$e->getMessage(), 0, $e);
                }
                ($this->sleeper)(2 ** $attempt);

                continue;
            }
            if (($response->status() === 429 || $response->serverError()) && $attempt < 4) {
                ($this->sleeper)((float) ($response->header('Retry-After') ?: 2 ** $attempt));

                continue;
            }
            if (! $response->successful()) {
                $msg = collect($response->json('errors') ?? [])->pluck('message')->implode(' ') ?: 'HTTP '.$response->status();
                throw new MarketplaceException('eBay: '.$msg);
            }

            return $response->json() ?? [];
        }
    }

    /**
     * Trading-API-Aufruf. $inner ist der XML-Inhalt innerhalb des Request-Elements.
     */
    public function trading(string $call, string $inner): SimpleXMLElement
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            .'<'.$call.'Request xmlns="urn:ebay:apis:eBLBaseComponents">'
            .'<ErrorLanguage>de_DE</ErrorLanguage><WarningLevel>High</WarningLevel>'
            .$inner.'</'.$call.'Request>';

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $response = Http::withHeaders([
                    'X-EBAY-API-IAF-TOKEN' => $this->userToken(),
                    'X-EBAY-API-CALL-NAME' => $call,
                    'X-EBAY-API-SITEID' => (string) config('marktschreier.ebay.site_id'),
                    'X-EBAY-API-COMPATIBILITY-LEVEL' => (string) config('marktschreier.ebay.compatibility_level'),
                    'Content-Type' => 'text/xml',
                ])->timeout(90)->withBody($xml, 'text/xml')->post($this->base('api').'/ws/api.dll');
            } catch (ConnectionException $e) {
                if ($attempt >= 4) {
                    throw new MarketplaceException('eBay nicht erreichbar: '.$e->getMessage(), 0, $e);
                }
                ($this->sleeper)(2 ** $attempt);

                continue;
            }
            if ($response->serverError() && $attempt < 4) {
                ($this->sleeper)(2 ** $attempt);

                continue;
            }
            $doc = @simplexml_load_string($response->body());
            if (! $doc) {
                throw new MarketplaceException('eBay antwortet unverständlich (HTTP '.$response->status().').');
            }
            $ack = (string) $doc->Ack;
            if ($ack === 'Failure' || $ack === 'PartialFailure') {
                $errors = [];
                foreach ($doc->Errors as $error) {
                    if ((string) $error->SeverityCode === 'Error') {
                        $errors[] = trim((string) ($error->LongMessage ?: $error->ShortMessage));
                    }
                }
                if ($ack === 'Failure' || $errors) {
                    throw new MarketplaceException('eBay: '.(implode(' ', array_unique($errors)) ?: 'Unbekannter Fehler'));
                }
            }

            return $doc;
        }
    }

    public static function x(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
