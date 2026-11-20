import { Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { NgTemplateOutlet } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, ParamMap, Params, Router, RouterLink } from '@angular/router';
import { Subscription, firstValueFrom } from 'rxjs';
import { AppDatePipe } from '../../core/app-date.pipe';
import { AuthService } from '../../core/auth.service';
import { MoneyPipe, TYPE_LABELS } from '../../core/money.pipe';
import { Page, Transaction } from '../../core/models';
import { errorText } from '../../core/api.interceptor';
import { SettingsService } from '../../core/settings.service';
import { LockBannerComponent } from '../period-locks/lock-banner.component';
import { BranchPickerComponent } from '../reports/report-common';

interface Creator { id: number; name: string; tag: string | null }
interface AccountOpt { id: number; name: string; status?: string; branch_id: number; branch?: { code: string } }
interface CategoryNode { id: number; name: string; type: string; is_active: boolean; parent_id: number | null; children?: CategoryNode[] }
interface BranchOpt { id: number; code: string; name: string }

/** The filter params of the URL and the API (spec S1: param names are the API names, so the URL is the API query). */
const FILTER_KEYS = ['from', 'to', 'type', 'status', 'account_id', 'category_id', 'category_only', 'created_by', 'min', 'max', 'q', 'files', 'branches'] as const;
const STATUS_LABELS: Record<string, string> = { posted: 'Posted', void: 'Void', reversed: 'Reversed' };
const FILES_LABELS: Record<string, string> = { with: 'Has files', none: 'No files', removed: 'Has removed files' };
const SORTS: [string, string][] = [
  ['date:desc', 'Date, newest first'], ['date:asc', 'Date, oldest first'],
  ['amount:desc', 'Amount, highest first'], ['amount:asc', 'Amount, lowest first'],
];
const AMOUNT = /^\d{1,13}(\.\d{1,2})?$/;
const BRANCH_NOTE = 'The branch filter applies in All branches mode only.';

interface Form {
  from: string; to: string; types: string[]; statuses: string[]; account_id: string;
  parent: string; sub: string; created_by: string; min: string; max: string; q: string; files: string; branches: number[];
}
interface Chip { key: string; label: string; remove: string[] }

/** AG-065 (docs/specs/transaction-search.md S1): search, filters, URL state, phone layout and Excel export. */
@Component({
  standalone: true,
  imports: [FormsModule, NgTemplateOutlet, MoneyPipe, AppDatePipe, LockBannerComponent, RouterLink, BranchPickerComponent],
  template: `
    <h1>Transactions</h1>
    <ag-lock-banner />

    <button type="button" class="ghost ts-toggle" [attr.aria-expanded]="panelOpen()" aria-controls="ts-panel"
      (click)="panelOpen.set(!panelOpen())">{{ chips().length ? 'Filters (' + chips().length + ')' : 'Filters' }}</button>

    <form id="ts-panel" class="ts-panel" [class.open]="panelOpen()" (ngSubmit)="apply()" novalidate>
      <label>From <input type="date" name="from" [(ngModel)]="f.from" /></label>
      <label>To <input type="date" name="to" [(ngModel)]="f.to" />
        @if (errors()['to']) { <small class="field-error">{{ errors()['to'] }}</small> }</label>
      <div class="ts-field"><span>Type</span>
        <details class="picker">
          <summary>{{ f.types.length ? typeSummary(f.types) : 'All types' }}</summary>
          @for (t of types; track t[0]) {
            <label><input type="checkbox" [checked]="f.types.includes(t[0])" (change)="toggle(f.types, t[0])" /> {{ t[1] }}</label>
          }
        </details>
      </div>
      <fieldset class="ts-field ts-status"><legend>Status</legend>
        @for (s of statuses; track s[0]) {
          <label><input type="checkbox" [checked]="f.statuses.includes(s[0])" (change)="toggle(f.statuses, s[0])" /> {{ s[1] }}</label>
        }
      </fieldset>
      <label>Account
        <select name="account_id" [(ngModel)]="f.account_id">
          <option value="">Any account</option>
          @if (f.account_id && !accountById(f.account_id)) { <option [value]="f.account_id">Account #{{ f.account_id }}</option> }
          @for (g of accountGroups(); track g.code) {
            <optgroup [label]="g.code || 'Accounts'">
              @for (a of g.accounts; track a.id) { <option [value]="'' + a.id">{{ accountLabel(a) }}</option> }
            </optgroup>
          }
        </select>
      </label>
      <label>Category
        <select name="parent" [ngModel]="f.parent" (ngModelChange)="f.parent = $event; f.sub = ''">
          <option value="">Any category</option>
          @if (f.parent && !categoryById(f.parent)) { <option [value]="f.parent">Category #{{ f.parent }}</option> }
          @for (g of categoryGroups(); track g.label) {
            <optgroup [label]="g.label">
              @for (c of g.items; track c.id) { <option [value]="'' + c.id">{{ c.name }}{{ c.is_active ? '' : ' (inactive)' }}</option> }
            </optgroup>
          }
        </select>
      </label>
      <label>Sub-category
        <select name="sub" [(ngModel)]="f.sub" [disabled]="!f.parent">
          <option value="">All sub-categories</option>
          <option value="none">(no sub-category)</option>
          @if (f.sub && f.sub !== 'none' && !subsOf(f.parent).some(isId(f.sub))) { <option [value]="f.sub">Category #{{ f.sub }}</option> }
          @for (s of subsOf(f.parent); track s.id) {
            <option [value]="'' + s.id">{{ s.name }}{{ s.is_active && categoryById(f.parent)?.is_active !== false ? '' : ' (inactive)' }}</option>
          }
        </select>
      </label>
      <label>Created by
        <select name="created_by" [(ngModel)]="f.created_by">
          <option value="">Anyone</option>
          @if (f.created_by && !creatorById(f.created_by)) { <option [value]="f.created_by">User #{{ f.created_by }}</option> }
          @for (c of creators(); track c.id) { <option [value]="'' + c.id">{{ c.name }}{{ c.tag ? ' (' + c.tag + ')' : '' }}</option> }
        </select>
      </label>
      <label>Amount from <input name="min" inputmode="decimal" autocomplete="off" [(ngModel)]="f.min" />
        @if (errors()['min']) { <small class="field-error">{{ errors()['min'] }}</small> }</label>
      <label>Amount to <input name="max" inputmode="decimal" autocomplete="off" [(ngModel)]="f.max" />
        @if (errors()['max']) { <small class="field-error">{{ errors()['max'] }}</small> }</label>
      <label class="ts-wide">Search <input name="q" maxlength="100" placeholder="Ref no, payee/payer, reference, description" [(ngModel)]="f.q" />
        @if (errors()['q']) { <small class="field-error">{{ errors()['q'] }}</small> }</label>
      <label>Files
        <select name="files" [(ngModel)]="f.files">
          <option value="">Any</option>
          <option value="with">Has files</option>
          <option value="none">No files</option>
          <option value="removed">Has removed files</option>
        </select>
      </label>
      @if (auth.isAllBranches()) {
        <div class="ts-field"><span>Branches</span><ag-branch-picker [(selected)]="f.branches" /></div>
      }
      <div class="ts-actions">
        <button type="submit">Apply</button>
        <button type="button" class="ghost" (click)="clearAll()">Clear all</button>
        @if (auth.can('report.export')) {
          <button type="button" class="ghost" [disabled]="exporting() || !page()?.total" (click)="exportXlsx()">{{ exporting() ? 'Exporting…' : 'Export to Excel' }}</button>
        }
      </div>
    </form>

    <div class="ts-bar">
      <select class="ts-sort" aria-label="Sort" [ngModel]="sortKey()" (ngModelChange)="setSort($event)">
        @for (s of sorts; track s[0]) { <option [value]="s[0]">{{ s[1] }}</option> }
      </select>
      @if (chips().length) {
        <div class="ts-chips">
          @for (c of chips(); track c.key) {
            <span class="ts-chip">{{ c.label }} <button type="button" class="link" [attr.aria-label]="'Remove ' + c.label" (click)="removeChip(c)">×</button></span>
          }
        </div>
      }
    </div>

    @if (note()) { <div class="att-note">{{ note() }}</div> }
    @if (msg()) {
      <div class="alert">{{ msg() }} @if (listError()) { <a href="" (click)="$event.preventDefault(); clearFilters()">Clear filters</a> }</div>
    }

    <div class="card ts-card">
      <table class="list ts-table">
        <thead><tr><th>Date</th><th>Ref</th>@if (auth.isAllBranches()) { <th>Branch</th> }<th>Type</th><th>Category</th><th>Account</th>
          <th>Details</th><th class="num">Amount</th><th>Status</th><th>By</th><th>Files</th><th></th></tr></thead>
        <tbody>
          @for (t of rows(); track t.id) {
            <tr [class.struck]="t.status === 'void'">
              <td>{{ t.txn_date | appDate }}</td>
              <td class="mono"><a [routerLink]="['/transactions', t.id]">{{ t.ref_no }}</a></td>
              @if (auth.isAllBranches()) { <td>{{ t.branch?.code }}</td> }
              <td>{{ label(t.type) }} @if (t.direction === 'in') { <span class="tag">In</span> }</td>
              <td>{{ categoryText(t) }}</td>
              <td>{{ accountText(t) }}</td>
              <td class="ts-wrap">{{ t.payee_payer || t.description }}</td>
              <td class="num" [class.neg]="t.type === 'expense'">{{ t.type === 'year_end_close' ? '—' : (t.amount | money) }}</td>
              <td><ng-container *ngTemplateOutlet="status; context: { $implicit: t }" /></td>
              <td>{{ t.creator?.name }} @if (t.creator_tag) { <span class="tag" data-s="inactive">{{ t.creator_tag }}</span> }</td>
              <td style="white-space:nowrap">{{ t.attachments_count || '' }}@if (t.attachments_removed_count) { <span class="muted"> ({{ t.attachments_removed_count }} removed)</span> }</td>
              <td><ng-container *ngTemplateOutlet="actions; context: { $implicit: t }" /></td>
            </tr>
          } @empty {
            <tr><td [attr.colspan]="auth.isAllBranches() ? 12 : 11" class="muted"><ng-container *ngTemplateOutlet="empty" /></td></tr>
          }
        </tbody>
      </table>

      <ul class="ts-cards">
        @for (t of rows(); track t.id) {
          <li [class.struck]="t.status === 'void'">
            <div class="ts-l1"><span>{{ t.txn_date | appDate }} · <a class="mono" [routerLink]="['/transactions', t.id]">{{ t.ref_no }}</a></span>
              <b class="num" [class.neg]="t.type === 'expense'">{{ t.type === 'year_end_close' ? '—' : (t.amount | money) }}</b></div>
            <div class="ts-l2">@if (auth.isAllBranches()) { {{ t.branch?.code }} · }{{ label(t.type) }} @if (t.direction === 'in') { <span class="tag">In</span> }
              · {{ categoryText(t) || accountText(t) }}</div>
            @if (t.payee_payer || t.description) { <div class="ts-wrap muted">{{ t.payee_payer || t.description }}</div> }
            <div class="ts-l3"><ng-container *ngTemplateOutlet="status; context: { $implicit: t }" />
              <span>· {{ t.creator?.name }}</span> @if (t.creator_tag) { <span class="tag" data-s="inactive">{{ t.creator_tag }}</span> }
              @if (t.attachments_count) { <span>· {{ t.attachments_count }} file(s)</span> }</div>
            <div class="ts-card-actions"><ng-container *ngTemplateOutlet="actions; context: { $implicit: t }" /></div>
          </li>
        } @empty { <li class="muted"><ng-container *ngTemplateOutlet="empty" /></li> }
      </ul>

      @if (page(); as p) {
        <div class="pager ts-pager">
          <button class="ghost" [disabled]="p.current_page <= 1" (click)="goPage(p.current_page - 1)">Previous</button>
          <span>Page {{ p.current_page }} of {{ p.last_page }} · {{ p.total }} {{ p.total === 1 ? 'entry' : 'entries' }}</span>
          <button class="ghost" [disabled]="p.current_page >= p.last_page" (click)="goPage(p.current_page + 1)">Next</button>
          <select aria-label="Rows per page" [ngModel]="'' + perPage()" (ngModelChange)="setPerPage($event)">
            @for (n of perPageOptions(); track n) { <option [value]="n">{{ n }} per page</option> }
          </select>
        </div>
      }
    </div>

    <ng-template #status let-t>
      @if (t.type === 'reversal') {
        <span class="tag" data-s="reversal">Reversal of</span>
        @if (t.reversal_of_id) { <a class="mono" [routerLink]="['/transactions', t.reversal_of_id]">{{ t.reversal_of_ref }}</a> }
      } @else if (t.status === 'reversed') {
        <span class="tag" data-s="reversed">Reversed</span>
        @if (t.reversed_by) { <br /><small>by <a class="mono" [routerLink]="['/transactions', t.reversed_by.id]">{{ t.reversed_by.ref_no }}</a></small> }
      } @else if (t.status === 'void') {
        <span class="tag" data-s="void">{{ t.type === 'year_end_close' ? 'Void (year reopened)' : 'Void' }}</span>
        @if (t.legacy_void_note) { <br /><small class="muted">{{ t.legacy_void_note }}</small> }
      } @else {
        <span class="tag" [attr.data-s]="t.status">{{ statusLabel(t.status) }}</span>
      }
    </ng-template>

    <ng-template #actions let-t>
      @if (t.in_active_branch !== false && t.type !== 'year_end_close') {
        @if (t.status === 'posted' && (t.type === 'income' || t.type === 'expense') && auth.can(t.type + '.void')) {
          <button class="link" [disabled]="busy()" (click)="void(t)">Void</button>
        }
        @if (t.status === 'posted' && canReverse(t.type)) {
          <button class="link" [disabled]="busy()" (click)="reverse(t)">Reverse</button>
        }
      }
    </ng-template>

    <ng-template #empty>
      @if (chips().length) {
        No transactions match these filters. <a href="" (click)="$event.preventDefault(); clearFilters()">Clear filters</a>
      } @else { No transactions yet. }
    </ng-template>
  `,
})
export class TransactionListComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private settings = inject(SettingsService);
  private destroyRef = inject(DestroyRef);

  page = signal<Page<Transaction> | null>(null);
  rows = computed(() => this.page()?.data ?? []);
  msg = signal('');
  /** The list request failed (422 on the URL): the message comes with a "Clear filters" link. */
  listError = signal(false);
  note = signal('');
  /** QA Q1: one void / reverse at a time; a second click cannot post a second request. */
  busy = signal(false);
  exporting = signal(false);
  panelOpen = signal(false);
  errors = signal<Record<string, string>>({});

  creators = signal<Creator[]>([]);
  accounts = signal<AccountOpt[]>([]);
  categories = signal<CategoryNode[]>([]);
  branchOpts = signal<BranchOpt[]>([]);

  /** The applied state: the current URL's query params. */
  private applied = signal<Params>({});
  sortKey = computed(() => `${this.applied()['sort'] ?? 'date'}:${this.applied()['dir'] ?? 'desc'}`);
  perPage = computed(() => Number(this.applied()['per_page'] ?? 25));
  chips = computed(() => this.buildChips(this.applied()));

  f: Form = this.emptyForm();
  types = Object.entries(TYPE_LABELS);
  statuses = Object.entries(STATUS_LABELS);
  sorts = SORTS;
  private creatorsFor = '';
  private listSub?: Subscription;
  /** Rows per page: 200 stays valid for existing links (AC 20), so it is offered when the URL holds it. */
  perPageOptions = computed(() => {
    const base = ['25', '50', '100'];
    const cur = String(this.perPage());
    return base.includes(cur) ? base : [...base, cur];
  });

  ngOnInit() {
    this.http.get<AccountOpt[]>('/api/v1/accounts').subscribe({ next: a => this.accounts.set(a), error: () => this.accounts.set([]) });
    this.http.get<CategoryNode[]>('/api/v1/categories', { params: { include_inactive: 1 } }).subscribe({
      next: c => { this.categories.set(c); this.resolveCategory(); }, error: () => this.categories.set([]) });
    if (this.auth.isAllBranches()) {
      this.http.get<BranchOpt[]>('/api/v1/branches').subscribe({ next: b => this.branchOpts.set(b), error: () => this.branchOpts.set([]) });
    }
    // Back / Forward and shared links load exactly the list the URL describes.
    this.route.queryParamMap.pipe(takeUntilDestroyed(this.destroyRef)).subscribe(m => this.fromUrl(m));
  }

  // ------------------------------------------------------------------ URL state

  private fromUrl(m: ParamMap) {
    const p: Params = {};
    for (const k of m.keys) {
      if (k === 'branches') {
        // QA F5: an empty branches value is no branch filter.
        const b = m.getAll(k).filter(Boolean);
        if (b.length) p[k] = b;
      } else p[k] = m.get(k);
    }
    // F3 / AC 16: branches only exist in All mode; a link from All mode is dropped with a note.
    if (p['branches']?.length && !this.auth.isAllBranches()) {
      delete p['branches'];
      this.note.set(BRANCH_NOTE);
      this.router.navigate([], { queryParams: p, replaceUrl: true });
      return;
    }
    this.applied.set(p);
    this.fillForm(p);
    this.loadCreators(p['branches'] ?? []);
    this.load(p);
  }

  /** Navigate to a new filter set: empty values removed, page reset (pushes a history entry). */
  private go(params: Params, replaceUrl = false) {
    const clean: Params = {};
    for (const [k, v] of Object.entries(params)) {
      if (v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length)) continue;
      if ((k === 'sort' && v === 'date') || (k === 'dir' && v === 'desc') || (k === 'per_page' && String(v) === '25') || (k === 'page' && String(v) === '1')) continue;
      clean[k] = v;
    }
    this.router.navigate([], { queryParams: clean, replaceUrl });
  }

  private keep(p: Params, keys: string[]): Params {
    const out: Params = {};
    for (const k of keys) if (p[k] !== undefined) out[k] = p[k];
    return out;
  }

  apply() {
    const e = this.validate();
    this.errors.set(e);
    if (Object.keys(e).length) { this.panelOpen.set(true); return; }
    this.note.set('');
    this.panelOpen.set(false);
    this.go({ ...this.formParams(), ...this.keep(this.applied(), ['sort', 'dir', 'per_page']) });
  }

  clearAll() {
    this.f = this.emptyForm();
    this.errors.set({});
    this.note.set('');
    this.go(this.keep(this.applied(), ['sort', 'dir', 'per_page']));
  }

  clearFilters() { this.clearAll(); }

  removeChip(c: Chip) {
    const p: Params = { ...this.applied() };
    for (const k of [...c.remove, 'page']) delete p[k];
    this.go(p);
  }

  setSort(key: string) {
    const [sort, dir] = key.split(':');
    const p: Params = { ...this.applied(), sort, dir };
    delete p['page'];
    this.go(p);
  }

  goPage(n: number) { this.go({ ...this.applied(), page: n }, true); }

  setPerPage(n: string) {
    const p: Params = { ...this.applied(), per_page: n };
    delete p['page'];
    this.go(p, true);
  }

  /** Form -> URL / API params (S1 Sub-category rules; commas stripped from amounts). */
  private formParams(): Params {
    const f = this.f;
    const p: Params = {
      from: f.from, to: f.to, type: f.types.join(','), status: f.statuses.join(','), account_id: f.account_id,
      created_by: f.created_by, min: this.amount(f.min), max: this.amount(f.max), q: f.q.trim(), files: f.files,
      branches: this.auth.isAllBranches() ? f.branches.map(String) : [],
    };
    if (f.parent) {
      if (f.sub === 'none') { p['category_id'] = f.parent; p['category_only'] = '1'; }
      else if (f.sub) p['category_id'] = f.sub;
      else p['category_id'] = f.parent;
    }
    // Reports drill-down label (accepted, not needed): kept while the category is unchanged.
    if (this.applied()['category_name'] && p['category_id'] === this.applied()['category_id']) p['category_name'] = this.applied()['category_name'];
    return p;
  }

  /**
   * Categories arrived after the URL was read: place a sub-category id under its parent. Only the category fields are
   * touched, and only while they still hold the unresolved URL value (fields the user is editing are kept).
   */
  private resolveCategory() {
    const p = this.applied();
    const id = p['category_id'] ?? '';
    const node = id ? this.findCategory(id) : undefined;
    if (!node?.parent_id || this.f.parent !== String(id)) return;
    this.f = { ...this.f, parent: String(node.parent_id), sub: String(node.id) };
  }

  /** URL -> form. A category id that is a sub selects its parent and the sub; an unknown id shows "Category #<id>". */
  private fillForm(p: Params) {
    const list = (v: unknown) => String(v ?? '').split(',').map(s => s.trim()).filter(Boolean);
    const f = this.emptyForm();
    f.from = p['from'] ?? ''; f.to = p['to'] ?? '';
    f.types = list(p['type']); f.statuses = list(p['status']);
    f.account_id = p['account_id'] ?? ''; f.created_by = p['created_by'] ?? '';
    f.min = p['min'] ?? ''; f.max = p['max'] ?? ''; f.q = p['q'] ?? ''; f.files = p['files'] ?? '';
    f.branches = ((p['branches'] ?? []) as string[]).map(Number);
    const id = p['category_id'] ?? '';
    if (id) {
      const only = ['1', 'true'].includes(String(p['category_only'] ?? ''));
      const node = this.findCategory(id);
      if (node?.parent_id) { f.parent = String(node.parent_id); f.sub = String(node.id); }
      else { f.parent = id; f.sub = only ? 'none' : ''; }
    }
    this.f = f;
  }

  private emptyForm(): Form {
    return { from: '', to: '', types: [], statuses: [], account_id: '', parent: '', sub: '', created_by: '', min: '', max: '', q: '', files: '', branches: [] };
  }

  private amount(v: string) { return v.replace(/,/g, '').trim(); }

  /** Client mirror of the API rules (R2, R8, R9). */
  private validate(): Record<string, string> {
    const e: Record<string, string> = {};
    const f = this.f;
    if (f.from && f.to && f.to < f.from) e['to'] = 'To must be on or after From.';
    const min = this.amount(f.min);
    const max = this.amount(f.max);
    const bad = (v: string) => v !== '' && (!AMOUNT.test(v) || Number(v) < 0.01);
    if (bad(min)) e['min'] = 'Enter an amount of at least 0.01 with up to 2 decimals.';
    if (bad(max)) e['max'] = 'Enter an amount of at least 0.01 with up to 2 decimals.';
    if (!e['min'] && !e['max'] && min && max && this.cents(max) < this.cents(min)) e['max'] = 'Amount to must be at least Amount from.';
    if (f.q.trim().length > 100) e['q'] = 'Search may not be longer than 100 characters.';
    return e;
  }

  private cents(v: string): number {
    const [i, d = ''] = v.split('.');
    return Number(i) * 100 + Number((d + '00').slice(0, 2));
  }

  // ------------------------------------------------------------------ loading

  private apiParams(p: Params): HttpParams {
    let params = new HttpParams();
    for (const [k, v] of Object.entries(p)) {
      if (k === 'category_name') continue;
      if (k === 'branches') for (const b of v as string[]) params = params.append('branches[]', b);
      else if (v !== null && v !== undefined && v !== '') params = params.set(k, String(v));
    }
    return params;
  }

  private load(p: Params) {
    // Reviewer m1: a newer URL cancels the request of the older one, so a late response never overwrites the list.
    this.listSub?.unsubscribe();
    this.listSub = this.http.get<Page<Transaction>>('/api/v1/transactions', { params: this.apiParams(p) })
      .pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: r => {
        // AC 21: a page past the end moves to the last page (page 1 when nothing matches), without an extra history entry.
        if (r.current_page > 1 && r.current_page > r.last_page) { this.goPage(Math.max(1, r.last_page)); return; }
        this.page.set(r); this.msg.set(''); this.listError.set(false);
      },
      error: e => { this.page.set(null); this.msg.set(errorText(e)); this.listError.set(e?.status === 422 || e?.status === 403); },
    });
  }

  private loadCreators(branches: string[]) {
    const key = branches.join(',');
    if (key === this.creatorsFor && this.creators().length) return;
    this.creatorsFor = key;
    let params = new HttpParams();
    for (const b of branches) params = params.append('branches[]', b);
    // "Created by" keeps former members (accounting review finding 3); R7: only users of the scope set.
    this.http.get<Creator[]>('/api/v1/transactions/creators', { params }).subscribe({
      next: c => this.creators.set(c), error: () => this.creators.set([]) });
  }

  refresh() { this.load(this.applied()); }

  async exportXlsx() {
    if (this.exporting()) return;
    this.exporting.set(true);
    this.msg.set('');
    const p: Params = { ...this.applied() };
    delete p['page']; delete p['per_page']; delete p['category_name'];
    const body: Record<string, unknown> = { ...p, format: 'xlsx' };
    if (p['branches']) body['branches'] = (p['branches'] as string[]).map(Number);
    try {
      const blob = await firstValueFrom(this.http.post('/api/v1/transactions/export', body, { responseType: 'blob' }));
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `transactions_${p['from'] || 'all'}_${p['to'] || 'all'}.xlsx`;
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
      // Error bodies arrive as a Blob because of responseType: 'blob'.
      const err = e as HttpErrorResponse;
      let msg = errorText(e);
      if (err?.status && err.status < 500 && err.error instanceof Blob) {
        try { msg = JSON.parse(await err.error.text())?.message ?? msg; } catch { /* keep default */ }
      }
      this.msg.set(msg);
    } finally {
      this.exporting.set(false);
    }
  }

  // ------------------------------------------------------------------ options and labels

  accountGroups = computed(() => {
    const groups = new Map<string, AccountOpt[]>();
    for (const a of this.accounts()) {
      const code = this.auth.isAllBranches() ? (a.branch?.code ?? '') : '';
      groups.set(code, [...(groups.get(code) ?? []), a]);
    }
    return [...groups.entries()].map(([code, accounts]) => ({ code, accounts }));
  });

  categoryGroups = computed(() => [
    { label: 'Income', items: this.categories().filter(c => c.type === 'income') },
    { label: 'Expense', items: this.categories().filter(c => c.type === 'expense') },
  ].filter(g => g.items.length));

  accountLabel(a: AccountOpt) {
    const name = this.auth.isAllBranches() && a.branch ? `${a.branch.code} – ${a.name}` : a.name;
    return name + (a.status && a.status !== 'active' ? ' (inactive)' : '');
  }

  accountById(id: string) { return this.accounts().find(a => String(a.id) === String(id)); }
  creatorById(id: string) { return this.creators().find(c => String(c.id) === String(id)); }
  categoryById(id: string) { return this.categories().find(c => String(c.id) === String(id)); }
  subsOf(parent: string) { return this.categoryById(parent)?.children ?? []; }
  isId(id: string) { return (c: { id: number }) => String(c.id) === String(id); }

  private findCategory(id: string): CategoryNode | undefined {
    for (const c of this.categories()) {
      if (String(c.id) === String(id)) return c;
      const s = c.children?.find(x => String(x.id) === String(id));
      if (s) return { ...s, parent_id: c.id };
    }
    return undefined;
  }

  private categoryLabel(id: string, only: boolean): string {
    const node = this.findCategory(id);
    if (!node) return this.applied()['category_name'] || `Category #${id}`;
    if (node.parent_id) return `${this.categoryById(String(node.parent_id))?.name} › ${node.name}`;
    return only ? `${node.name} › (no sub-category)` : node.name;
  }

  typeSummary(types: string[]) { return types.map(t => TYPE_LABELS[t] ?? t).join(', '); }

  private date(v: string) { return this.settings.date(v); }

  private money(v: string) {
    return Number(v).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  /** One chip per active filter (S1); × removes that filter and applies. */
  private buildChips(p: Params): Chip[] {
    const c: Chip[] = [];
    const list = (v: unknown) => String(v ?? '').split(',').map(s => s.trim()).filter(Boolean);
    if (p['from']) c.push({ key: 'from', label: `From ${this.date(p['from'])}`, remove: ['from'] });
    if (p['to']) c.push({ key: 'to', label: `To ${this.date(p['to'])}`, remove: ['to'] });
    if (p['type']) c.push({ key: 'type', label: `Type: ${this.typeSummary(list(p['type']))}`, remove: ['type'] });
    if (p['status']) c.push({ key: 'status', label: `Status: ${list(p['status']).map(s => STATUS_LABELS[s] ?? s).join(', ')}`, remove: ['status'] });
    if (p['account_id']) {
      const a = this.accountById(p['account_id']);
      c.push({ key: 'account', label: `Account: ${a ? this.accountLabel(a) : '#' + p['account_id']}`, remove: ['account_id'] });
    }
    if (p['category_id']) {
      const only = ['1', 'true'].includes(String(p['category_only'] ?? ''));
      c.push({ key: 'category', label: `Category: ${this.categoryLabel(p['category_id'], only)}`, remove: ['category_id', 'category_only', 'category_name'] });
    }
    if (p['created_by']) {
      const u = this.creatorById(p['created_by']);
      c.push({ key: 'created_by', label: `Created by: ${u ? u.name : '#' + p['created_by']}`, remove: ['created_by'] });
    }
    if (p['min'] || p['max']) {
      const label = p['min'] && p['max'] ? `${this.money(p['min'])} to ${this.money(p['max'])}`
        : p['min'] ? `from ${this.money(p['min'])}` : `up to ${this.money(p['max'])}`;
      c.push({ key: 'amount', label: `Amount: ${label}`, remove: ['min', 'max'] });
    }
    if (p['q']) c.push({ key: 'q', label: `Search: "${p['q']}"`, remove: ['q'] });
    if (p['files']) c.push({ key: 'files', label: `Files: ${FILES_LABELS[p['files']] ?? p['files']}`, remove: ['files'] });
    if (p['branches']?.length) {
      const codes = (p['branches'] as string[]).map(id => this.branchOpts().find(b => String(b.id) === id)?.code ?? '#' + id);
      c.push({ key: 'branches', label: `Branches: ${codes.join(', ')}`, remove: ['branches'] });
    }
    return c;
  }

  // ------------------------------------------------------------------ rows

  label(t: string) { return TYPE_LABELS[t] ?? t; }
  statusLabel(s: string) { return STATUS_LABELS[s] ?? s; }

  categoryText(t: Transaction): string {
    const c = t.category;
    if (!c) return '';
    const inactive = c.is_active === false || c.parent?.is_active === false;
    return (c.parent?.name ? c.parent.name + ' › ' : '') + c.name + (inactive ? ' (inactive)' : '');
  }

  accountText(t: Transaction): string {
    if (t.account) return t.account.name;
    if (!t.from_account && !t.to_account) return '';
    const side = (a?: { name: string; branch?: { code: string } }) => (a ? `${a.branch?.code ? a.branch.code + ' ' : ''}${a.name}` : '');
    return `${side(t.from_account)} → ${side(t.to_account)}`;
  }

  toggle(list: string[], v: string) {
    const i = list.indexOf(v);
    if (i >= 0) list.splice(i, 1); else list.push(v);
  }

  isTransfer(t: string) { return ['opening_allocation', 'fund_allocation', 'fund_return', 'branch_transfer', 'internal_transfer'].includes(t); }
  /** Controls review finding 8 / fund return G5: opening balances, top-ups and fund returns are reversed by a Super Admin only. */
  canReverse(t: string) {
    if (!this.isTransfer(t) || !this.auth.can('transfer.reverse')) return false;
    return !['opening_allocation', 'fund_allocation', 'fund_return'].includes(t) || this.auth.isSuperAdmin();
  }

  void(t: Transaction) {
    if (this.busy()) return;
    // Controls review finding 10: void is for entries recorded in error only.
    const reason = prompt(`Void ${t.ref_no}?\n\nUse Void only for an entry recorded in error. For a real refund or return, record a new entry on the day it happens.\n\nReason (required):`);
    if (!reason || this.busy()) return;
    this.busy.set(true);
    this.http.post(`/api/v1/transactions/${t.id}/void`, { reason }).subscribe({
      next: () => { this.busy.set(false); this.refresh(); },
      error: e => { this.busy.set(false); this.msg.set(errorText(e)); this.listError.set(false); } });
  }

  reverse(t: Transaction) {
    if (this.busy()) return;
    const reason = prompt(`Reverse ${t.ref_no}? Enter a reason:`);
    if (!reason || this.busy()) return;
    this.busy.set(true);
    this.http.post(`/api/v1/transactions/${t.id}/reverse`, { reason }).subscribe({
      next: () => { this.busy.set(false); this.refresh(); },
      error: e => { this.busy.set(false); this.msg.set(errorText(e)); this.listError.set(false); } });
  }
}
