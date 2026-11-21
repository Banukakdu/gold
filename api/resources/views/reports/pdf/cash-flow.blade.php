@extends('reports.pdf.layout')
@php($m = fn ($v) => \App\Support\Money::format($v))
@php($cols = \App\Services\ReportExporter::CASH_FLOW_COLUMNS)
@php($month = fn (string $ym) => \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $ym.'-01')->format('M Y'))
@section('body')
{{-- AG-086: 15 columns on A4 landscape; a smaller font keeps the 12-digit amounts on one line. --}}
<style>
    table.cf th, table.cf td { font-size: 7px; padding: 3px 2px; }
    table.cf th { font-size: 6.5px; }
</style>
@if ($data['empty'])<p class="note"><b>{{ \App\Services\ReportService::CASH_FLOW_EMPTY }}</b></p>@endif
<table class="cf">
    <thead><tr><th>Month</th>@foreach ($cols as $label)<th class="num">{{ $label }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach ($data['months'] as $r)
        <tr><td>{{ $month($r['month']) }}</td>@foreach ($cols as $k => $label)<td class="num">{{ $m($r[$k]) }}</td>@endforeach</tr>
    @endforeach
    <tr class="b"><td>Total</td>@foreach ($cols as $k => $label)<td class="num">{{ $m($data['total'][$k]) }}</td>@endforeach</tr>
    </tbody>
</table>
@foreach ($notes as $note)<p class="note">{{ $note }}</p>@endforeach
@endsection
