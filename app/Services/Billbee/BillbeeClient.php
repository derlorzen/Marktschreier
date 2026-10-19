<?php

namespace App\Services\Billbee;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Lesender Client für die Billbee API (Produkte inkl. EK und Bestand).
 * Billbee erlaubt je Endpunkt nur 2 Anfragen pro Sekunde je API-Key und Benutzer.
 */
class BillbeeClient
{
    public const PAGE_SIZE = 250;

    private const MAX_ATTEMPTS = 5;

    private float $lastRequestAt = 0.0;

    /** @var callable(float): void */
    private $sleeper;

    public function __construct(
        private ?string $apiKey = null,
        private ?string $username = null,
        private ?string $password = null,
        private ?string $baseUrl = null,
        ?callable $sleeper = null,
    ) {
        $this->apiKey ??= Setting::get('billbee.api_key');
        $this->username ??= Setting::get('billbee.username');
        $this->password ??= Setting::get('billbee.password');
        $this->baseUrl ??= Setting::get('billbee.base_url', 'https://api.billbee.io/api/v1');
        $this->sleeper = $sleeper ?? fn (float $s) => usleep((int) ($s * 1_000_000));
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey) && filled($this->username) && filled($this->password);
    }

    /**
     * Eine Seite Produkte.
     *
     * @return array{data: list<array<string, mixed>>, page: int, total_pages: int|null}
     */
    public function products(int $page, int $pageSize = self::PAGE_SIZE): array
    {
        $json = $this->get('products', ['page' => $page, 'pageSize' => $pageSize]);
        $paging = $json['Paging'] ?? null;

        return [
            'data' => $json['Data'] ?? [],
            'page' => (int) ($paging['Page'] ?? $page),
            'total_pages' => isset($paging['TotalPages']) ? (int) $paging['TotalPages'] : null,
        ];
    }

    /** @return iterable<array<string, mixed>> alle Produkte, seitenweise geladen */
    public function allProducts(): iterable
    {
        $page = 1;
        while (true) {
            $res = $this->products($page);
            foreach ($res['data'] as $product) {
                yield $product;
            }
            $done = $res['data'] === []
                || ($res['total_pages'] !== null && $page >= $res['total_pages'])
                || ($res['total_pages'] === null && count($res['data']) < self::PAGE_SIZE);
            if ($done) {
                return;
            }
            $page++;
        }
    }

    /** @return array<string, mixed> */
    private function get(string $path, array $query = []): array
    {
        if (! $this->isConfigured()) {
            throw new BillbeeException('Billbee ist nicht eingerichtet: API-Key, Benutzername oder API-Passwort fehlt.');
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            $this->throttle();
            try {
                $response = Http::withBasicAuth($this->username, $this->password)
                    ->withHeaders(['X-Billbee-Api-Key' => $this->apiKey])
                    ->acceptJson()
                    ->timeout(60)
                    ->get(rtrim($this->baseUrl, '/').'/'.$path, $query);
            } catch (ConnectionException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new BillbeeException('Billbee nicht erreichbar: '.$e->getMessage(), 0, $e);
                }
                $this->sleep(min(30, 2 ** $attempt));

                continue;
            }

            if ($response->status() === 429 || $response->serverError()) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new BillbeeException('Billbee antwortet mit HTTP '.$response->status().'.');
                }
                $this->sleep((float) ($response->header('Retry-After') ?: min(30, 2 ** $attempt)));

                continue;
            }
            if ($response->status() === 401 || $response->status() === 403) {
                throw new BillbeeException('Billbee lehnt den Zugriff ab (HTTP '.$response->status().'). API-Key, Benutzer und API-Passwort prüfen.');
            }
            if (! $response->successful()) {
                throw new BillbeeException('Billbee-Fehler HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300));
            }

            $json = $response->json() ?? [];
            if (! empty($json['ErrorMessage'])) {
                throw new BillbeeException('Billbee meldet: '.$json['ErrorMessage']);
            }

            return $json;
        }
    }

    /** Höchstens 2 Anfragen pro Sekunde. */
    private function throttle(): void
    {
        $elapsed = microtime(true) - $this->lastRequestAt;
        if ($this->lastRequestAt > 0 && $elapsed < 0.5) {
            $this->sleep(0.5 - $elapsed);
        }
        $this->lastRequestAt = microtime(true);
    }

    private function sleep(float $seconds): void
    {
        ($this->sleeper)($seconds);
    }
}
