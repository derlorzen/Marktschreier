<?php

// Nur die Meldungen, die Marktschreier tatsächlich nutzt. Fehlende fallen auf Englisch zurück.
return [
    'between' => ['numeric' => ':Attribute muss zwischen :min und :max liegen.'],
    'distinct' => ':Attribute ist doppelt vorhanden.',
    'email' => 'Bitte eine gültige E-Mail-Adresse eingeben.',
    'in' => 'Der gewählte Wert für :attribute ist ungültig.',
    'max' => ['string' => ':Attribute darf höchstens :max Zeichen haben.'],
    'numeric' => ':Attribute muss eine Zahl sein.',
    'regex' => 'Das Format von :attribute ist ungültig.',
    'required' => ':Attribute muss ausgefüllt sein.',
    'string' => ':Attribute muss ein Text sein.',
    'attributes' => [
        'email' => 'E-Mail', 'password' => 'Passwort',
        'form.title' => 'Titel', 'form.vendor' => 'Marke', 'form.product_type' => 'Produktart', 'form.tags' => 'Tags',
        'form.status' => 'Status', 'form.seo_title' => 'SEO-Titel', 'form.seo_description' => 'SEO-Beschreibung',
        'form.description_html' => 'Beschreibung', 'vatRate' => 'MwSt.', 'shopifyVersion' => 'API-Version',
        'variants.*.sku' => 'SKU', 'variants.*.price' => 'Preis', 'attrs.*.value' => 'Wert',
    ],
];
