<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · Marktschreier</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="auth">
    <div class="card card-pad" style="max-width:440px">
        <div class="brand" style="padding:0 0 14px"><span class="brand-mark" aria-hidden="true">M</span> Marktschreier</div>
        <h1 style="margin-bottom:8px">@yield('title')</h1>
        <p class="muted">@yield('message')</p>
        <a class="btn btn-primary" href="{{ url('/') }}">Zur Startseite</a>
    </div>
</div>
</body>
</html>
