<?php

namespace App\Services\Content;

use App\Models\Channel;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Marketplaces\MarketplaceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Textvorschläge je Kanal über die Claude API. Nur Vorschläge: gespeichert wird erst,
 * wenn jemand sie prüft und übernimmt. Es werden keine Fakten erfunden, nur vorhandene Daten genutzt.
 */
class AiWriter
{
    public function configured(): bool
    {
        return filled(Setting::get('ai.api_key'));
    }

    /** @return array{title: string, bullets: list<string>, search_terms: string, description_html: string} */
    public function suggest(Product $product, Channel $channel): array
    {
        $product->loadMissing('variants', 'attributeValues');
        $facts = [
            'Titel' => $product->title,
            'Marke' => $product->vendor,
            'Produktart' => $product->product_type,
            'Beschreibung' => trim(strip_tags((string) $product->description_html)),
            'Merkmale' => $product->attributeValues->mapWithKeys(fn ($a) => [$a->key => $a->value])->all(),
            'Varianten' => $product->variants->map(fn ($v) => $v->label())->values()->all(),
            'Tags' => $product->tags ?? [],
        ];
        $rules = match ($channel->key) {
            'amazon' => 'Amazon.de: Titel höchstens 200 Zeichen (Marke, Produktart, wichtigste Eigenschaften, Menge/Größe). Genau 5 Stichpunkte, je höchstens 250 Zeichen, jeweils mit einem kurzen Nutzen am Anfang. Versteckte Suchbegriffe höchstens 249 Zeichen, mit Leerzeichen getrennt, ohne Marke, ohne Wiederholungen aus dem Titel, ohne Kommas. Beschreibung als einfaches HTML mit <p> und <ul>.',
            'ebay' => 'eBay.de: Titel höchstens 80 Zeichen, die wichtigsten Suchwörter vorne, keine Sonderzeichen wie ! oder *. Keine Stichpunkte nötig (leere Liste). Suchbegriffe leer lassen. Beschreibung als einfaches HTML mit <p> und <ul>, ohne Links und ohne Skripte.',
            default => 'Titel höchstens 100 Zeichen, kurz und klar. Bis zu 5 Stichpunkte. Beschreibung als einfaches HTML.',
        };
        $prompt = "Schreibe verkaufsstarke, sachliche deutsche Texte für den Kanal {$channel->name}.\n"
            ."Regeln: {$rules}\n"
            ."Nutze ausschließlich die folgenden Angaben. Erfinde keine Eigenschaften, Maße, Materialien oder Versprechen, die hier nicht stehen. Keine Gedankenstriche.\n"
            .'Angaben: '.json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n"
            .'Antworte nur mit JSON in der Form {"title": "...", "bullets": ["..."], "search_terms": "...", "description_html": "..."}.';

        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) Setting::get('ai.api_key'),
                'anthropic-version' => '2023-06-01',
            ])->acceptJson()->timeout(90)->post('https://api.anthropic.com/v1/messages', [
                'model' => Setting::get('ai.model', 'claude-sonnet-5-5'),
                'max_tokens' => 2000,
                'system' => 'Du bist Texter für einen deutschen Onlinehändler für Sammelkarten-Zubehör und andere Waren. Du hältst dich strikt an die Kanalvorgaben und an die gegebenen Fakten.',
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
        } catch (ConnectionException $e) {
            throw new MarketplaceException('KI nicht erreichbar: '.$e->getMessage(), 0, $e);
        }
        if (! $response->successful()) {
            throw new MarketplaceException('KI-Anfrage fehlgeschlagen: '.($response->json('error.message') ?? 'HTTP '.$response->status()));
        }
        $text = collect($response->json('content') ?? [])->where('type', 'text')->pluck('text')->implode('');
        if (! preg_match('/\{.*\}/s', $text, $m) || ! is_array($json = json_decode($m[0], true))) {
            throw new MarketplaceException('Die KI hat keine verwertbare Antwort geliefert, bitte erneut versuchen.');
        }

        $limits = \App\Models\ChannelContent::LIMITS[$channel->key] ?? ['title' => 255];

        return [
            'title' => mb_substr(trim((string) ($json['title'] ?? '')), 0, $limits['title']),
            'bullets' => collect($json['bullets'] ?? [])->map(fn ($b) => mb_substr(trim((string) $b), 0, $limits['bullet'] ?? 500))->filter()->take($limits['bullets'] ?? 5)->values()->all(),
            'search_terms' => mb_substr(trim((string) ($json['search_terms'] ?? '')), 0, $limits['search_terms'] ?? 500),
            'description_html' => trim((string) ($json['description_html'] ?? '')),
        ];
    }
}
