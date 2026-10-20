# Marktschreier

Schlanke Artikelverwaltung mit Marktplatzanbindung („plenty light“) für Händler mit Shopify, Billbee oder PlentyONE. Eine Instanz je Kunde.

**Sortiment**
- **Artikel**: Import aus Shopify (oder PlentyONE), Suche, Filter, Gruppenfunktionen, Detailansicht mit Reitern, Artikel und Varianten direkt in Marktschreier anlegen, Bilder hochladen und sortieren, Merkmale (auch als Shopify-Metafelder), Verlauf jeder Änderung.
- **Zurückschreiben**: Nach dem Import ist Marktschreier führend für Texte, Tags, Status, SEO, Preise, SKU und GTIN. Nur geänderte Felder gehen nach Freigabe zu Shopify. Konflikte werden erkannt.
- **Texte je Kanal**: eigener Titel, Beschreibung, Stichpunkte und Suchbegriffe für Amazon, eBay und TikTok, optional mit KI-Vorschlägen.
- **Bestand**: eigenes Lager und Lagerorte aus Billbee, FBA aus Amazon, Mengen auf den Marktplätzen, Warnung bei Überverkaufsgefahr.
- **Kanäle und Preisregeln**: Gebühren und Versand je Kanal, Regelwerke mit Zielmarge, Mindestgewinn und Preisendung, einmalig angewendet mit Vorschau.

**Verkaufen**
- **Marktplätze**: eBay (Trading API), Amazon (SP-API) und TikTok Shop. Angebote lesen und zuordnen, Preise auf Klick übertragen, neue Angebote anlegen (eBay mit Kategorie und Merkmalen, Amazon zu vorhandener ASIN per GTIN oder als neues Produkt nach Produkttyp-Definition).
- **Preiswächter (Buy Box)**: je Variante einschaltbar, mit Untergrenze, Strategie und Tageslimit.
- **Werbung**: Amazon Ads mit Kampagnen, Suchbegriffen, Vorschlägen für Gebote und Budgets.
- **Chancen**: regelbasierter Chancenfinder ohne Werbebudget (fehlt auf Kanal, Daten unvollständig, zu wenig Gewinn, bald ausverkauft, Ladenhüter, Probleme mit Angeboten) und mit Werbung (Werbekosten-Grenze, Ziel-ACoS, Startbudget).
- **Kalender**: Set-Releases, Vorbestellungen und Aktionen mit Erinnerung und passenden Artikeln.

**Auswerten**
- **Startseite** mit Umsatz, Gewinn, Verlauf, Kanälen und den wichtigsten Chancen.
- **Berichte**: Wochenrückblick und Monatsabschluss (brutto, netto, Umsatzsteuer, Rohertrag, Werbekosten) als PDF, auf Wunsch per Mail.
- **Was-wäre-wenn-Rechner**: Preis, Einkauf, Menge und Werbebudget durchspielen.
- **Hinweise**: in der App und per Mail bei Fehlern, Überverkaufsgefahr, verlorener Buy Box, Terminen.

**Betrieb**
- **Einstellungen** mit Übersicht, Benutzer mit Rollen, Zwei-Faktor-Anmeldung, Passwort vergessen per Mail, Zeitplan, tägliche Sicherung.
- **PlentyONE andocken**: Artikel, Preise, EK, Bestand und Aufträge aus PlentyONE lesen, um einen Umzug vorzubereiten. PlentyONE bleibt bis dahin führend.

## Wer schreibt welches Feld

| Feld | führend | Richtung |
|---|---|---|
| Titel, Beschreibung, Marke, Produktart, Tags, Status, SEO | Marktschreier | → Shopify nach Freigabe |
| Preis, Streichpreis, SKU, GTIN | Marktschreier | → Shopify nach Freigabe |
| Bilder, neue Varianten | Shopify | → Marktschreier (vorerst nur Anzeige) |
| Einkaufspreis, Bestand, Bestellungen | Billbee | → Marktschreier, nie zurück |
| Preise je Marktplatz | Marktschreier | → eBay/Amazon auf Klick, nie automatisch |
| Menge auf eBay/Amazon | Billbee (FBA: Amazon) | Marktschreier setzt nur den Startbestand beim Anlegen |
| Merkmale | Marktschreier | → Shopify-Metafelder, eBay-Artikelmerkmale, Amazon-Attribute |
| Artikel aus PlentyONE | PlentyONE | → Marktschreier, nie zurück (bis zum Umzug) |

Ändern sich dasselbe Feld hier und in Shopify zwischen zwei Abgleichen, bleibt der Wert aus Marktschreier und der Artikel wird als **Konflikt** markiert. Mit „Speichern“ in der Detailansicht gilt er als geprüft.

## Lokal starten

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan db:seed --class=DemoSeeder      # optional: Beispielartikel, Login demo@marktschreier.test / demo-passwort
php artisan marktschreier:user du@example.com "Dein Name"
php artisan serve                            # http://localhost:8000
php artisan queue:work                       # für Import und Übertragung aus der Oberfläche
```

Tests: `php artisan test` (alle APIs werden gefälscht, es geht nichts nach außen).

## Eine Instanz je Kunde (Docker / Coolify)

Jeder Kunde bekommt eine eigene Instanz mit eigener Datenbank. `docker-compose.yml` startet:

- `app` (nginx + PHP-FPM, Port 8080, führt beim Start die Migrationen aus)
- `worker` (Queue: Importe, Übertragungen)
- `scheduler` (Billbee EK und Bestand alle 15 Minuten, Verkäufe alle 30 Minuten, Shopify-Änderungen stündlich)
- `postgres`, `redis`

In Coolify: neue Ressource „Docker Compose“ aus dem Repo, Umgebungsvariablen setzen, beim Dienst `app` die Domain mit `:8080` eintragen (z. B. `https://marktschreier.example.de:8080`). Lokal mit Port: `docker compose -f docker-compose.yml -f docker-compose.local.yml up`.

Pflicht-Variablen:

```
APP_KEY=base64:...          # php artisan key:generate --show
APP_URL=https://kunde.example.de
DB_PASSWORD=...             # langes Zufallspasswort
```

Danach einmalig den ersten Benutzer anlegen:

```bash
docker compose exec app php artisan marktschreier:user benny@johtomarket.de "Benny"
```

Zugangsdaten für Shopify und Billbee können als Umgebungsvariablen oder in der Oberfläche unter **Einstellungen** gesetzt werden (dort verschlüsselt mit `APP_KEY` gespeichert, die Oberfläche hat Vorrang). `APP_KEY` darf sich nach dem ersten Speichern nicht mehr ändern.

Backups: das Volume `postgres-data` bzw. `pg_dump` täglich, in Coolify über „Backups“ der Datenbank oder per Cronjob.

### Shopify-App anlegen

Seit 1. Januar 2026 lassen sich im Shopify-Admin keine neuen „Custom Apps“ mit festem Token mehr anlegen. Neue Apps entstehen im **Shopify Dev Dashboard**:

1. Mit dem Konto des Shop-Inhabers im Dev Dashboard eine App anlegen (die App muss zur selben Organisation gehören wie der Shop, sonst verweigert Shopify das Token).
2. In der App-Version die Admin-API-Berechtigungen `read_products`, `write_products`, `read_inventory` setzen, Version veröffentlichen und die App im Shop installieren.
3. Client-ID und Client-Secret in Marktschreier unter Einstellungen eintragen. Marktschreier holt sich das Zugriffstoken (24 Stunden gültig) selbst über den Client-Credentials-Grant und erneuert es automatisch.

Gibt es bereits eine ältere Custom App mit `shpat_…`-Token, kann stattdessen dieses Token eingetragen werden.

### eBay (eine Entwickler-App für alle Instanzen)

1. Im eBay-Entwicklerkonto (Production Keyset) Client-ID und Client-Secret notieren.
2. Je Instanz einen RuName anlegen („User Tokens“, „Get a Token from eBay via Your Application“) und als „Your auth accepted URL“ `https://<instanz>/einstellungen/ebay/rueckkehr` eintragen.
3. `EBAY_CLIENT_ID`, `EBAY_CLIENT_SECRET`, `EBAY_RUNAME` setzen. Der Kunde klickt unter Einstellungen, eBay auf „Mit eBay verbinden“.
4. Im eBay-Konto des Kunden müssen Geschäftsrichtlinien (Versand, Zahlung, Rücknahme) aktiv sein, sie werden in Marktschreier ausgewählt.

Marktschreier legt Angebote über die Trading API an (nicht über die Inventory API), damit Billbee die Menge weiter pflegen kann. Preise werden nur als Preis übertragen, nie mit Menge.

### Amazon (SP-API)

1. Im Solution Provider Portal bzw. Developer Central eine App mit den Rollen „Produktlisting“ und „Preisgestaltung“ registrieren. LWA-Client-ID und -Secret notieren, App-ID ebenfalls.
2. Als Weiterleitungs-URI je Instanz `https://<instanz>/einstellungen/amazon/rueckkehr` eintragen.
3. `AMAZON_LWA_CLIENT_ID`, `AMAZON_LWA_CLIENT_SECRET`, `AMAZON_APP_ID` setzen. Solange die App im Entwurf ist, bleibt `AMAZON_APP_DRAFT=true`.
4. Der Kunde klickt „Mit Amazon verbinden“ und bestätigt in Seller Central. Alternativ: eine selbst autorisierte App, dann Verkäufer-ID und Refresh-Token einfügen.

### Amazon Werbung (Ads API)

Eigene Zustimmung für die Advertising API in der Amazon-Entwickler-App (gleiche LWA-App möglich). Weiterleitungs-URI `https://<instanz>/einstellungen/amazon-werbung/rueckkehr`. Optional eigene Zugangsdaten über `AMAZON_ADS_CLIENT_ID` und `AMAZON_ADS_CLIENT_SECRET`.

### TikTok Shop

App im TikTok Shop Partner Center anlegen, `TIKTOK_APP_KEY`, `TIKTOK_APP_SECRET` und, falls vorhanden, `TIKTOK_SERVICE_ID` setzen. Weiterleitungs-URL `https://<instanz>/einstellungen/tiktok/rueckkehr`.

### PlentyONE (für Umzüge)

In PlentyONE einen eigenen API-Benutzer mit Leserechten auf Artikel, Bestand und Aufträge anlegen. Unter Einstellungen, PlentyONE die Systemadresse (z. B. `https://p2241.my.plentysystems.com`), Benutzer und Passwort eintragen, Verbindung testen, Verkaufspreis und Lager wählen, dann „Artikel einlesen“.

### Mail

Für Hinweise, Wochenrückblick, Monatsabschluss und „Passwort vergessen“ braucht die Instanz einen Mailversand (`MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`). Ohne Mail zeigt die Anmeldung statt „Passwort vergessen“ einen Hinweis an die Administratoren.

### KI-Vorschläge (optional)

`ANTHROPIC_API_KEY` setzen oder unter Einstellungen, Allgemein hinterlegen. Ohne Schlüssel bleiben die Textfelder einfach manuell.

### Billbee

API-Key bei Billbee anfragen (support@billbee.io), in Billbee unter Einstellungen → API ein API-Passwort setzen. Benutzer ist die Login-E-Mail. Unter Einstellungen angeben, ob der Einkaufspreis in Billbee netto oder brutto gepflegt ist.

## Befehle

```
php artisan marktschreier:user {email} {name?} [--staff]   Benutzer anlegen (Standard: Administrator) oder Passwort setzen
php artisan marktschreier:marketplace {ebay|amazon|tiktok} [listings|prices]   Angebote lesen bzw. offene Preise übertragen
php artisan marktschreier:plenty [items|orders] [--full]   PlentyONE lesen
php artisan marktschreier:import [--full]       Shopify-Import (ohne --full nur Änderungen)
php artisan marktschreier:billbee               EK und Bestand aus Billbee lesen
php artisan marktschreier:orders [--rematch]    Verkäufe aus Billbee lesen (erstmals 12 Monate)
```

## Aufbau

- `app/Services/Shopify` – GraphQL-Client (Drosselung, Wiederholungen), Import, Übertragung
- `app/Services/Billbee` – lesender Client (2 Anfragen/s), Abgleich EK und Bestand
- `app/Services/ProductEditor.php` – alle Änderungen aus der Oberfläche inkl. Verlauf
- `app/Services/Pricing` – Rechenkern (Gewinn, Preis aus EK) und einmaliges Anwenden von Regelwerken
- `app/Services/Sales/SalesStats.php` – Umsatz und Gewinn aus den Bestellungen
- `app/Services/Opportunities` – Chancenfinder, Schwellen als Konstanten in `OpportunityFinder`
- `app/Services/Marketplaces` – eBay (OAuth, Trading API, Taxonomy) und Amazon (LWA, Listings, Catalog), gemeinsamer Einstieg `MarketplaceManager`
- `app/Services/Marketplaces/TikTok` – TikTok Shop (signierte Anfragen, Angebote, Preise)
- `app/Services/Marketplaces/Amazon/AmazonProductBuilder.php` – neue ASINs nach Produkttyp-Definition
- `app/Services/Plenty` – PlentyONE lesen (Artikel, Varianten, Aufträge)
- `app/Services/Reports` – Monatsabschluss und PDF
- `app/Services/Auth/TwoFactor.php` – Zwei-Faktor-Anmeldung
- `app/Livewire/Settings` – Einstellungsbereiche, Benutzerverwaltung, Mein Konto
- `app/Models/Concerns/TracksShopifySync.php` – merkt sich den letzten Shopify-Stand und erkennt offene Änderungen
- `app/Livewire` – Oberfläche (Livewire 4, schlichtes CSS in `public/css/app.css`, kein Build-Schritt)
