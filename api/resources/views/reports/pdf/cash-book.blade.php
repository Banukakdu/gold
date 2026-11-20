@extends('reports.pdf.layout')
@php($m = fn ($v) => \App\Support\Money::format($v))
@section('body')
@foreach ($books as $book)
    @php($a = $book['account'])
    @foreach ($book['pages'] as $i => $page)
        <h2>{{ $a['branch_code'] }} - {{ $a['name'] }} ({{ $a['type'] }})@if ($a['status'] !== 'active') (Inactive)@endif @if (count($book['pages']) > 1) - page {{ $i + 1 }} of {{ count($book['pages']) }} for this account @endif</h2>
        <table>
            <thead><tr><th>Date</th><th>Ref no</th><th>Type</th><th>Description / payee</th><th class="num">Opening</th><th class="num">Receipts</th><th class="num">Payments</th><th class="num">Closing</th></tr></thead>
            <tbody>
            @if ($i === 0)<tr class="b"><td colspan="7">Period opening balance</td><td class="num">{{ $m($book['opening']) }}</td></tr>@endif
            <tr class="carry"><td colspan="5">Brought forward</td><td class="num">{{ $m($page['bf']['receipt']) }}</td><td class="num">{{ $m($page['bf']['payment']) }}</td><td></td></tr>
            @forelse ($page['rows'] as $r)
                @if ($r['kind'] === 'day')
                    <tr class="b sub"><td>{{ $r['date'] }}</td><td colspan="3">Day</td><td class="num">{{ $m($r['opening']) }}</td><td class="num">{{ $m($r['receipts']) }}</td><td class="num">{{ $m($r['payments']) }}</td><td class="num">{{ $m($r['closing']) }}</td></tr>
                @else
                    <tr><td></td><td>{{ $r['ref_no'] }}</td><td>{{ \App\Services\ReportService::typeLabel($r['type']) }}</td><td>{{ \Illuminate\Support\Str::limit($r['description'], 60) }}</td><td></td>
                        <td class="num">{{ $r['receipt'] !== '0.00' ? $m($r['receipt']) : '' }}</td><td class="num">{{ $r['payment'] !== '0.00' ? $m($r['payment']) : '' }}</td><td></td></tr>
                @endif
            @empty
                <tr><td colspan="8">No movements in this period.</td></tr>
            @endforelse
            <tr class="b"><td colspan="5">Page total</td><td class="num">{{ $m($page['page']['receipt']) }}</td><td class="num">{{ $m($page['page']['payment']) }}</td><td></td></tr>
            <tr class="carry b"><td colspan="5">Carried forward</td><td class="num">{{ $m($page['cf']['receipt']) }}</td><td class="num">{{ $m($page['cf']['payment']) }}</td><td></td></tr>
            @if ($loop->last)
                <tr class="b"><td colspan="4">Account total</td><td class="num">{{ $m($book['opening']) }}</td><td class="num">{{ $m($book['receipts']) }}</td><td class="num">{{ $m($book['payments']) }}</td><td class="num">{{ $m($book['closing']) }}</td></tr>
            @endif
            </tbody>
        </table>
        @if (! ($loop->last && $loop->parent->last))<div class="page-break"></div>@endif
    @endforeach
@endforeach
@include('reports.pdf.signatures')
@endsection
