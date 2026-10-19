<x-mail::message>
# {{ $alert->title }}

{{ $alert->body }}

@if ($alert->url)
<x-mail::button :url="url($alert->url)">
Ansehen
</x-mail::button>
@endif

Diese Mail kommt, weil bei dir „Sofort bei Fehlern“ eingeschaltet ist. Ändern unter Mein Konto.
</x-mail::message>
