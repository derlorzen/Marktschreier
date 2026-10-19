# Marktschreier

Laravel 13 + Livewire 4 (Klassen-Komponenten in `app/Livewire`, Views in `resources/views/livewire`), schlichtes CSS in `public/css/app.css`, kein Vite.

- Oberfläche und Texte auf Deutsch, keine Gedankenstriche in UI-Texten.
- Änderungen an Artikeln immer über `App\Services\ProductEditor`, damit Verlauf und Abgleichsstatus stimmen.
- Billbee wird nur gelesen, niemals schreiben. Bestand gehört Billbee.
- Zu Shopify nur geänderte Felder senden (`ProductPusher::buildPayload`).
- Inline-`@php(...)` nicht mit `@php ... @endphp` in derselben View mischen (Blade-Fehler).
- Tests: `php artisan test`, externe APIs über `Tests\Concerns\FakesApis` (Antwort-Warteschlangen).
