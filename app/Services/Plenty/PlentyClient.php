<?php

namespace App\Services\Plenty;

use App\Models\Setting;
use App\Services\Marketplaces\MarketplaceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** PlentyONE REST-API, nur lesend. Anmeldung mit API-Benutzer, Token wird zwischengespeichert. */
class PlentyClient
{
    /** @var callable(float): void */
    private $sleeper;

    public function __construct(
        private ?string $url = null,
        private ?string $username = null,
        private ?string $password = null,
        ?callable $sleeper = null,
    ) {
        $this->url ??= Setting::get('plenty.url');
        $this->username ??= Setting::get('plenty.username');
        $this->password ??= Setting::get('plenty.password');
        $this->sleeper = $sleeper ?? fn (float $s) => usleep((int) ($s * 1_000_000));
    }

    public function isConfigured(): bool
    {
        return filled($this->url) && filled($this->username) && filled($this->password);
    }

    private function base(): string
    {
        $url = rtrim((string) $this->url, '/');

        return str_starts_with($url, 'http') ? $url : 'https://'.$url;
    }

    private function cacheKey(): string
    {
        return 'plenty.token.'.md5($this->base().$this->username);
    }

    private function token(bool $fresh = false): string
    {
        if ($fresh) {
            Cache::forget($this->cacheKey());
        }
        if ($t = Cache::get($this->cacheKey())) {
            return $t;
        }
        try {
            $res = Http::acceptJson()->timeout(30)->post($this->base().'/rest/login', ['username' => $this->username, 'password' => $this->password]);
        } catch (ConnectionException $e) {
            throw new MarketplaceException('PlentyONE nicht erreichbar: '.$e->getMessage(), 0, $e);
        }
        $token = $res->json('access_token');
        if (! $res->successful() || ! $token) {
            throw new MarketplaceException('PlentyONE lehnt die Anmeldung ab. Benutzer, Passwort und Rechte des API-Benutzers prüfen.');
        }
        Cache::put($this->cacheKey(), $token, max(60, (int) $res->json('expires_in', 86400) - 600));

        return $token;
    }

    public function get(string $path, array $query = []): array
    {
        $attempt = 0;
        $relogged = false;
        while (true) {
            $attempt++;
            try {
                $res = Http::withToken($this->token())->acceptJson()->timeout(90)->get($this->base().'/rest/'.ltrim($path, '/'), $query);
            } catch (ConnectionException $e) {
                if ($attempt >= 4) {
                    throw new MarketplaceException('PlentyONE nicht erreichbar: '.$e->getMessage(), 0, $e);
                }
                ($this->sleeper)(2 ** $attempt);

                continue;
            }
            if ($res->status() === 401 && ! $relogged) {
                $this->token(fresh: true);
                $relogged = true;

                continue;
            }
            if (($res->status() === 429 || $res->serverError()) && $attempt < 5) {
                ($this->sleeper)((float) ($res->header('Retry-After') ?: 2 ** $attempt));

                continue;
            }
            if (! $res->successful()) {
                throw new MarketplaceException('PlentyONE: HTTP '.$res->status().' bei '.$path.'. '.mb_substr((string) ($res->json('error.message') ?? $res->json('message') ?? ''), 0, 200));
            }
            // plenty nennt das verbleibende Kontingent im Header, bei wenig Rest kurz warten
            $left = $res->header('X-Plenty-Global-Short-Period-Calls-Left');
            if ($left !== '' && $left !== null && (int) $left < 3) {
                ($this->sleeper)((float) ($res->header('X-Plenty-Global-Short-Period-Decay') ?: 2));
            }

            return $res->json() ?? [];
        }
    }

    /**
     * Alle Seiten eines paginierten Endpunkts.
     *
     * @return iterable<array<string, mixed>>
     */
    public function paginate(string $path, array $query = [], int $perPage = 100): iterable
    {
        $page = 1;
        do {
            $json = $this->get($path, $query + ['page' => $page, 'itemsPerPage' => $perPage]);
            foreach ($json['entries'] ?? [] as $entry) {
                yield $entry;
            }
            $last = (bool) ($json['isLastPage'] ?? true);
            $page++;
        } while (! $last && $page <= 10000);
    }
}
