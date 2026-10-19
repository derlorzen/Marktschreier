<x-mail::message>
# Deine Woche: {{ $review['period'] }}

**Umsatz** {{ $review['revenue'] }} ({{ $review['revenue_change'] }})<br>
**Gewinn** {{ $review['profit'] }} ({{ $review['profit_change'] }})<br>
**Bestellungen** {{ $review['orders'] }}

@if ($review['channels'])
<x-mail::table>
| Kanal | Umsatz | Gewinn |
|:------|-------:|-------:|
@foreach ($review['channels'] as $c)
| {{ $c['name'] }} | {{ $c['revenue'] }} | {{ $c['profit'] }} |
@endforeach
</x-mail::table>
@endif

@if ($review['top'])
**Bestseller der Woche:** {{ implode(', ', $review['top']) }}
@endif

@if ($review['chances'])
## Die wichtigsten Chancen
@foreach ($review['chances'] as $c)
- {{ $c }}
@endforeach
@endif

@if ($review['alerts'])
## Offene Hinweise
@foreach ($review['alerts'] as $a)
- {{ $a }}
@endforeach
@endif

<x-mail::button :url="url('/')">
Marktschreier öffnen
</x-mail::button>
</x-mail::message>
