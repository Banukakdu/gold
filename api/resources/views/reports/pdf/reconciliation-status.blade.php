@extends('reports.pdf.layout')
@php($X = \App\Services\ReportExporter::class)
@php($R = \App\Services\BankReconciliation::class)
{{-- The Branch column as on S7: All branches mode (or more than one branch) only. --}}
@php($showBranch = $data['all_branches'] || count($data['branches']) > 1)
@section('body')
{{-- AG-033e S7: the summary, check and filter lines under the page header; the S7 columns without the actions. --}}
@foreach ($X::statusLines($data) as $i => $line)
<p class="{{ $i === 1 && ($data['summary']['not_checked'] || $data['summary']['recheck']) ? 'alert' : 'note' }}">{{ $line }}</p>
@endforeach
<table>
    <thead><tr>
        @if ($showBranch)<th>Branch</th>@endif<th>Account</th><th>Status</th><th class="num">Months overdue</th>
        <th>Last reconciled</th><th>Last check</th>
    </tr></thead>
    <tbody>
    @forelse ($data['data'] as $r)
        <tr>
            @if ($showBranch)<td>{{ $r['branch']['code'] }}</td>@endif
            <td>{{ $X::listAccountText($r['account']) }}</td>
            <td>{{ $R::statusTagText($r, $data['due_month_end']) }}</td>
            <td class="num">{{ $r['months_overdue'] ?? '' }}</td>
            <td>{{ $X::lastReconciledText($r) }}</td>
            <td>{{ \App\Services\ReconciliationChecks::listText($r) }}</td>
        </tr>
    @empty
        <tr><td colspan="{{ 5 + ($showBranch ? 1 : 0) }}">{{ $X::statusEmptyText($data) }}</td></tr>
    @endforelse
    </tbody>
</table>
@endsection
