<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $head['title'] }}</title>
<style>
    @page { margin: 150px 32px 48px 32px; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 9px; color: #1f1d1a; }
    header { position: fixed; top: -132px; left: 0; right: 0; height: 122px; border-bottom: 1px solid #999; }
    header .company { font-size: 14px; font-weight: bold; }
    header .title { font-size: 12px; font-weight: bold; margin: 2px 0 4px; }
    header .line { margin: 1px 0; }
    header .provisional { color: #b3362c; font-weight: bold; font-size: 11px; margin-top: 3px; }
    header img { position: absolute; right: 0; top: 0; max-height: 48px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 3px 4px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
    th { background: #f1ead8; font-size: 8px; text-transform: uppercase; }
    .num { text-align: right; white-space: nowrap; }
    .b td, tr.b td { font-weight: bold; }
    .carry td { background: #f6f1e4; font-style: italic; }
    .sub td { background: #faf6ec; }
    h2 { font-size: 11px; margin: 8px 0 4px; }
    .note { color: #555; margin-top: 6px; }
    /* Core fonts have no em dash: draw it with DejaVu Sans (bundled with dompdf). */
    .g { font-family: 'DejaVu Sans', sans-serif; }
    .page-break { page-break-after: always; }
    .sign { margin-top: 28px; width: 100%; }
    .sign td { border: 0; padding: 18px 8px 2px; width: 33%; }
    .sign .l { border-top: 1px solid #333; padding-top: 3px; }
</style>
</head>
<body>
<header>
    @if ($head['logo'])<img src="{{ $head['logo'] }}" alt="">@endif
    <div class="company">{{ $head['company'] }}</div>
    <div class="title">{{ $head['title'] }}</div>
    <div class="line">Branches: {{ $head['branches'] }}</div>
    <div class="line">Period: {{ $head['period'] }}</div>
    <div class="line">Amounts in LKR &middot; {{ \App\Services\ReportService::BASIS }}</div>
    <div class="line">{{ $head['printed'] }}</div>
    @if ($head['provisional'])<div class="provisional">PROVISIONAL - period not locked</div>@endif
</header>
@yield('body')
</body>
</html>
