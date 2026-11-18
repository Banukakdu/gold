import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { MoneyPipe, TYPE_LABELS } from '../../core/money.pipe';
import { Page, Transaction } from '../../core/models';
import { errorText } from '../../core/api.interceptor';
import { LockBannerComponent } from '../period-locks/lock-banner.component';

@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe, DatePipe, LockBannerComponent, RouterLink],
  template: `
    <h1>Transactions</h1>
    <ag-lock-banner />
    <form class="filters" (ngSubmit)="load(1)">
      <input type="date" name="from" [(ngModel)]="f.from" />
      <input type="date" name="to" [(ngModel)]="f.to" />
      <select name="type" [(ngModel)]="f.type">
        <option value="">All types</option>
        @for (t of types; track t[0]) { <option [value]="t[0]">{{ t[1] }}</option> }
      </select>
      <select name="created_by" [(ngModel)]="f.created_by">
        <option value="">Anyone</option>
        @for (c of creators(); track c.id) { <option [value]="c.id">{{ c.name }}{{ c.tag ? ' (' + c.tag + ')' : '' }}</option> }
      </select>
      <select name="files" [(ngModel)]="f.files" aria-label="Files">
        <option value="">Files: any</option>
        <option value="with">Has files</option>
        <option value="none">No files</option>
        <option value="removed">Has removed files</option>
      </select>
      <input name="q" placeholder="Search ref, payee, note" [(ngModel)]="f.q" />
      <button type="submit">Filter</button>
      @if (fromReport()) {
        <span class="tag">From report: {{ categoryName || 'category ' + f.category_id }}{{ f.category_only ? ' (this line only)' : '' }}{{ branches.length ? ' · ' + branches.length + ' branch(es)' : '' }}</span>
        <button type="button" class="link" (click)="clearReportFilter()">Clear</button>
      }
    </form>
    @if (msg()) { <div class="alert">{{ msg() }}</div> }
    <div class="card">
      <table class="list">
        <thead><tr><th>Date</th><th>Ref</th><th>Branch</th><th>Type</th><th>Category</th><th>Account</th><th>Details</th><th class="num">Amount</th><th>Status</th><th>By</th><th>Files</th><th></th></tr></thead>
        <tbody>
          @for (t of page()?.data; track t.id) {
            <tr [class.struck]="t.status === 'void'">
              <td>{{ t.txn_date | date: 'd MMM y' }}</td>
              <td class="mono"><a [routerLink]="['/transactions', t.id]">{{ t.ref_no }}</a></td>
              <td>{{ t.branch?.code }}</td>
              <td>{{ label(t.type) }}</td>
              <td>{{ t.category ? (t.category.parent?.name ? t.category.parent?.name + ' › ' : '') + t.category.name : '' }}</td>
              <td>{{ t.account?.name ?? ((t.from_account?.name ?? '') + ' → ' + (t.to_account?.name ?? '')) }}</td>
              <td>{{ t.payee_payer || t.description }}</td>
              <td class="num" [class.neg]="t.type === 'expense'">{{ t.amount | money }}</td>
              <td><span class="tag" [attr.data-s]="t.status">{{ t.status }}</span>
                @if (t.legacy_void_note) { <br /><small class="muted">{{ t.legacy_void_note }}</small> }</td>
              <td>{{ t.creator?.name }} @if (t.creator_tag) { <span class="tag" data-s="inactive">{{ t.creator_tag }}</span> }</td>
              <td style="white-space:nowrap">{{ t.attachments_count || '' }}@if (t.attachments_removed_count) { <span class="muted"> ({{ t.attachments_removed_count }} removed)</span> }</td>
              <td>
                @if (t.status === 'posted' && (t.type === 'income' || t.type === 'expense') && auth.can(t.type + '.void')) {
                  <button class="link" (click)="void(t)">Void</button>
                }
                @if (t.status === 'posted' && canReverse(t.type)) {
                  <button class="link" (click)="reverse(t)">Reverse</button>
                }
              </td>
            </tr>
          } @empty { <tr><td colspan="12" class="muted">No transactions match.</td></tr> }
        </tbody>
      </table>
      @if (page(); as p) {
        <div class="pager">
          <button class="ghost" [disabled]="p.current_page <= 1" (click)="load(p.current_page - 1)">Previous</button>
          <span>Page {{ p.current_page }} of {{ p.last_page }} · {{ p.total }} entries</span>
          <button class="ghost" [disabled]="p.current_page >= p.last_page" (click)="load(p.current_page + 1)">Next</button>
        </div>
      }
    </div>
  `,
})
export class TransactionListComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  page = signal<Page<Transaction> | null>(null);
  msg = signal('');
  creators = signal<{ id: number; name: string; tag: string | null }[]>([]);
  f = { from: '', to: '', type: '', created_by: '', files: '', q: '', category_id: '', category_only: '' };
  branches: string[] = [];
  categoryName = ''; // label only, never sent to the API
  fromReport = signal(false);
  types = Object.entries(TYPE_LABELS);
  private route = inject(ActivatedRoute);

  ngOnInit() {
    // Reports drill-down (spec E1): filters arrive as URL query params.
    const q = this.route.snapshot.queryParamMap;
    for (const k of ['from', 'to', 'type', 'category_id', 'category_only', 'files'] as const) this.f[k] = q.get(k) ?? '';
    // branches[] is only valid in All mode; a stale value after a branch switch would give 403.
    this.branches = this.auth.isAllBranches() ? q.getAll('branches') : [];
    this.categoryName = q.get('category_name') ?? '';
    this.fromReport.set(!!(this.f.category_id || this.branches.length));
    this.load(1);
    // "Created by" keeps former members (accounting review finding 3).
    this.http.get<{ id: number; name: string; tag: string | null }[]>('/api/v1/transactions/creators').subscribe({
      next: c => this.creators.set(c), error: () => this.creators.set([]) });
  }

  load(p: number) {
    let params = new HttpParams().set('page', p);
    for (const [k, v] of Object.entries(this.f)) if (v) params = params.set(k, v);
    for (const b of this.branches) params = params.append('branches[]', b);
    this.http.get<Page<Transaction>>('/api/v1/transactions', { params }).subscribe({
      next: r => { this.page.set(r); this.msg.set(''); },
      error: e => this.msg.set(errorText(e)), // 401 is handled by the interceptor (back to login)
    });
  }

  clearReportFilter() {
    this.f.category_id = '';
    this.f.category_only = '';
    this.branches = [];
    this.categoryName = '';
    this.fromReport.set(false);
    this.load(1);
  }

  label(t: string) { return TYPE_LABELS[t] ?? t; }
  isTransfer(t: string) { return ['opening_allocation', 'fund_allocation', 'fund_return', 'branch_transfer', 'internal_transfer'].includes(t); }
  /** Controls review finding 8: opening balances and top-ups are reversed by a Super Admin only. */
  canReverse(t: string) {
    if (!this.isTransfer(t) || !this.auth.can('transfer.reverse')) return false;
    return !['opening_allocation', 'fund_allocation'].includes(t) || this.auth.isSuperAdmin();
  }

  void(t: Transaction) {
    // Controls review finding 10: void is for entries recorded in error only.
    const reason = prompt(`Void ${t.ref_no}?\n\nUse Void only for an entry recorded in error. For a real refund or return, record a new entry on the day it happens.\n\nReason (required):`);
    if (!reason) return;
    this.http.post(`/api/v1/transactions/${t.id}/void`, { reason }).subscribe({
      next: () => this.load(this.page()?.current_page ?? 1), error: e => this.msg.set(errorText(e)) });
  }

  reverse(t: Transaction) {
    const reason = prompt(`Reverse ${t.ref_no}? Enter a reason:`);
    if (!reason) return;
    this.http.post(`/api/v1/transactions/${t.id}/reverse`, { reason }).subscribe({
      next: () => this.load(this.page()?.current_page ?? 1), error: e => this.msg.set(errorText(e)) });
  }
}
