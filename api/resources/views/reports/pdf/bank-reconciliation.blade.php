@extends('reports.pdf.layout')
@php($m = fn ($v) => \App\Support\Money::format($v))
@php($date = fn ($v) => app(\App\Support\Settings::class)->formatDate($v))
@php($colombo = fn ($v) => \Carbon\CarbonImmutable::parse($v)->setTimezone('Asia/Colombo')->format('Y-m-d H:i'))
@php($st = $data['statement'])
@section('body')
@if ($st && $st['undone'])<p class="alert">{{ \App\Services\ReportExporter::undoneBanner($st) }}</p>@endif
{{-- AG-033 S3: one section per bank account; with a statement, the bank reconciliation statement to sign. --}}
@forelse ($data['accounts'] as $a)
    @php($acc = $a['account'])
    @php($last = $a['last_statement'])
    <h2>{{ $acc['branch']['code'] }} &middot; {{ $acc['name'] }}@if ($acc['code']) ({{ $acc['code'] }})@endif
        <span class="g">&mdash;</span>
        @if ($last) last reconciled to {{ $date($last['statement_date']) }} (statement balance {{ \App\Services\ReportExporter::statementBalanceText($last['statement_balance']) }})
        @else never reconciled @endif
        @if ($acc['status'] !== 'active') ({{ ucfirst($acc['status']) }}) @endif
    </h2>
    <table>
        <thead><tr><th>Date</th><th>Ref</th><th>Type</th><th>Description / payee</th><th>Reference</th><th class="num">In</th><th class="num">Out</th><th class="num">Age</th></tr></thead>
        <tbody>
        @forelse ($a['rows'] as $r)
            <tr>
                <td>{{ $date($r['date']) }}</td><td>{{ $r['ref_no'] }}</td><td>{{ \App\Services\ReportService::typeLabel($r['type']) }}</td>
                <td>{{ \Illuminate\Support\Str::limit($r['description'], 60) }}@if ($r['stale']) <b>[{{ \App\Services\BankReconciliation::STALE_TAG }}]</b>@endif</td>
                <td>{{ $r['reference'] }}</td>
                <td class="num">{{ $r['in'] !== '0.00' ? $m($r['in']) : '' }}</td><td class="num">{{ $r['out'] !== '0.00' ? $m($r['out']) : '' }}</td>
                <td class="num">{{ $r['age_days'] }}</td>
            </tr>
        @empty
        @endforelse
        @if ($a['empty_text'] ?? null)<tr><td colspan="8">{{ $a['empty_text'] }}</td></tr>@endif
        </tbody>
    </table>
    <table style="width: 60%; margin-top: 6px">
        <tr><td>Book balance at {{ $date($data['as_at']) }}</td><td class="num">{{ $m($a['book_balance']) }}</td></tr>
        <tr><td>less uncleared receipts</td><td class="num">{{ $m($a['uncleared_receipts']) }}</td></tr>
        <tr><td>add uncleared payments</td><td class="num">{{ $m($a['uncleared_payments']) }}</td></tr>
        @if ($a['cleared_booked_later_rows'])
            <tr><td>add/less entries cleared by the bank on or before {{ $date($data['as_at']) }}, booked after</td><td class="num">{{ $m($a['cleared_booked_later']) }}</td></tr>
            @foreach ($a['cleared_booked_later_rows'] as $r)
                <tr class="sub"><td>&nbsp;&nbsp;{{ $r['ref_no'] }} dated {{ $date($r['date']) }}, cleared {{ $date($r['cleared_date']) }}: {{ \Illuminate\Support\Str::limit($r['description'], 40) }}</td>
                    <td class="num">{{ $m(\App\Support\Money::add($r['in'], '-'.$r['out'])) }}</td></tr>
            @endforeach
        @endif
        <tr class="b"><td>= Expected bank balance</td><td class="num">{{ $m($a['expected_bank_balance']) }}</td></tr>
        @if ($st)
            <tr class="b"><td>Balance per bank statement</td><td class="num">{{ \App\Services\ReportExporter::statementBalanceText($st['statement_balance']) }}</td></tr>
            <tr class="b"><td>Difference</td><td class="num">{{ $m($st['difference']) }}</td></tr>
        @endif
    </table>
    @if (! $loop->last)<div class="page-break"></div>@endif
@empty
    <p>No bank accounts in scope.</p>
@endforelse

@if ($st)
    @if ($st['changed_since_completion'])<p class="alert">Changed since completion</p>@endif
    {{-- AG-033d S3: the Check block, after the statement balance / difference (and the AG-033b copy), before the late entries. --}}
    <h2>Check</h2>
    @foreach (\App\Services\ReconciliationChecks::blockLines($st) as $line)
        <p class="{{ str_starts_with($line, 'Re-check needed') ? 'alert' : 'note' }}">{{ $line }}</p>
    @endforeach
    @if ($st['late_entries'])
        <h2>Late entries (added or voided after the statement was completed)</h2>
        <table>
            <thead><tr><th>Ref</th><th>Date</th><th class="num">Amount</th><th>Recorded / voided by</th><th>At</th></tr></thead>
            <tbody>
            @foreach ($st['late_entries'] as $l)
                <tr><td>{{ $l['ref_no'] }}</td><td>{{ $date($l['date']) }}</td><td class="num">{{ $m($l['amount']) }}</td>
                    <td>{{ ucfirst($l['kind']) }} by {{ $l['by'] ?? '-' }}@if ($l['after_check'] ?? false) <b>[after check]</b>@endif</td><td>{{ $colombo($l['at']) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif
    <p class="note">Prepared by {{ $st['completed_by']['name'] ?? '-' }} on {{ $colombo($st['completed_at']) }}</p>
    <p class="note">{{ $st['self_count'] }} of {{ $st['lines_count'] }} {{ $st['lines_count'] === 1 ? 'line was' : 'lines were' }} recorded by the preparer</p>
    {{-- AG-033d S3: the check recorded in the system replaces the blank Checked by line; no Approved by line (D1). --}}
    @php($signed = \App\Services\ReconciliationChecks::signatureLine($st))
    @if ($signed)
        <p class="note"><b>{{ $signed }}</b></p>
    @else
        <table class="sign">
            <tr>
                <td>
                    <div><b>Checked by</b></div>
                    <div style="margin-top:18px" class="l">Name</div>
                    <div style="margin-top:18px" class="l">Signature</div>
                    <div style="margin-top:18px" class="l">Date</div>
                </td>
                <td><div class="note">Not checked in the system</div></td>
            </tr>
        </table>
    @endif
@else
    @include('reports.pdf.signatures')
@endif
@endsection
