import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Account, Category } from '../../core/models';
import { MoneyPipe } from '../../core/money.pipe';
import { errorText } from '../../core/api.interceptor';
import { LockBannerComponent } from '../period-locks/lock-banner.component';
import { colomboToday } from '../../core/dates';
import { ATTACH_ACCEPT, fileSize, selectionProblem, uploadAttachment, uploadErrorText } from '../../core/attachments';
import { AuthService } from '../../core/auth.service';
import { ToastService } from '../../core/toast.service';
import { toCents } from '../../core/approvals';

interface UploadLine { name: string; size: number; pct: number; error?: string; done?: boolean }
interface SaveResult {
  id: number; ref: string; total: number; attached: number; failures: { name: string; message: string }[];
  /** AG-064: held for approval (status pending), the R2b same-day total and the R17 warning. */
  pending?: boolean; sameDay?: string | null; date?: string; warning?: string | null;
}
interface SaveResponse { id: number; ref_no: string; status?: string; hold_reason?: string | null; same_day_total?: string | null; warning?: string | null; txn_date?: string }
interface BranchLimit { id: number; expense_approval_threshold?: string | null }

/** Record income or expense (route /transactions/new/:kind). */
@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe, LockBannerComponent, RouterLink],
  template: `
    <h1>Record {{ kind() }}</h1>
    <ag-lock-banner />
    <form class="card form" (ngSubmit)="save()">
      <label>Date <input type="date" name="txn_date" [(ngModel)]="m.txn_date" [max]="today" required /></label>
      <label>{{ kind() === 'income' ? 'Received into' : 'Paid from' }}
        <select name="account_id" [(ngModel)]="m.account_id" required>
          @for (a of accounts(); track a.id) { <option [ngValue]="a.id">{{ a.name }} ({{ a.current_balance | money }})</option> }
        </select>
      </label>
      <label>Category
        <select name="parent" [(ngModel)]="parentId" (ngModelChange)="m.category_id = null" required>
          @for (c of categories(); track c.id) { <option [ngValue]="c.id">{{ c.name }}</option> }
        </select>
      </label>
      <label>Sub-category
        <select name="category_id" [(ngModel)]="m.category_id" required>
          @for (c of subs(); track c.id) { <option [ngValue]="c.id">{{ c.name }}</option> }
        </select>
      </label>
      <label>Amount <input type="number" name="amount" [(ngModel)]="m.amount" (ngModelChange)="amount.set($event)" min="0.01" step="0.01" required /></label>
      @if (needsApproval()) {
        <!-- AG-064 S2 (display only; the server decides, R2). -->
        <p class="alert info wide ap-hint">Above {{ threshold() | money }}: this expense needs approval before it is posted. Do not pay until it is approved.</p>
      }
      <label>{{ kind() === 'income' ? 'Received from' : 'Paid to' }} <input name="payee_payer" [(ngModel)]="m.payee_payer" /></label>
      <label>Payment method
        <select name="payment_method" [(ngModel)]="m.payment_method">
          <option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option>
          <option value="cheque">Cheque</option><option value="card">Card</option><option value="online">Online</option>
        </select>
      </label>
      <label>Reference / bill no. <input name="reference" [(ngModel)]="m.reference" /></label>
      <label class="wide">Description <textarea name="description" rows="2" [(ngModel)]="m.description"></textarea></label>
      <!-- AG-062: optional receipts / invoices, uploaded one by one after the entry is saved. -->
      <div class="wide att-files">
        <span class="muted">Attach receipt / invoice (JPG, PNG or PDF, up to 5 files, 5 MB each)</span>
        <input #picker type="file" multiple [accept]="accept" hidden (change)="pick(picker)" />
        <button type="button" class="ghost att-file-btn" [disabled]="busy()" (click)="picker.click()">Attach receipt / invoice</button>
        @if (uploads().length) {
          <ul class="att-picked">
            @for (u of uploads(); track $index) {
              <li>
                <span class="att-picked-name" [title]="u.name">{{ u.name }}</span>
                <span class="muted">{{ size(u.size) }}</span>
                @if (busy()) {
                  <progress max="100" [value]="u.pct"></progress>
                  @if (u.error) { <span class="neg">{{ u.error }}</span> } @else if (u.done) { <span class="pos">attached</span> }
                } @else {
                  <button type="button" class="link" [attr.aria-label]="'Remove ' + u.name" (click)="drop($index)">x</button>
                }
              </li>
            }
          </ul>
        }
        @if (fileError()) { <div class="alert">{{ fileError() }}</div> }
      </div>
      @if (error()) { <div class="alert wide">{{ error() }}</div> }
      @if (result(); as r) {
        @if (r.failures.length) {
          <div class="alert wide">Saved as {{ r.ref }}.
            {{ r.failures.length }} file{{ r.failures.length === 1 ? ' was' : 's were' }} not attached:
            @for (f of r.failures; track $index) { {{ f.name }} - {{ f.message }}{{ $last ? '' : ';' }} }
            <a [routerLink]="['/transactions', r.id]">Open {{ r.ref }}</a> to try again.</div>
        } @else if (r.pending) {
          <div class="ok wide ap-hint"><a [routerLink]="['/transactions', r.id]">{{ r.ref }}</a> sent for approval. Balances do not change until it is approved.@if (r.sameDay) { Together with your other expenses in this category dated {{ r.date }} ({{ r.sameDay | money }}) this is above the limit.}@if (r.total) { {{ r.attached }} of {{ r.total }} file{{ r.total === 1 ? '' : 's' }} attached.}</div>
        } @else {
          <div class="ok wide">Saved as <a [routerLink]="['/transactions', r.id]">{{ r.ref }}</a>.@if (r.total) { {{ r.attached }} of {{ r.total }} file{{ r.total === 1 ? '' : 's' }} attached.}</div>
        }
        @if (r.warning) { <div class="alert wide ap-hint">{{ r.warning }}</div> }
      }
      <div class="wide actions"><button type="submit" [disabled]="busy() || !!fileError()">{{ needsApproval() ? 'Send for approval' : 'Save ' + kind() }}</button></div>
    </form>
  `,
})
export class EntryFormComponent implements OnInit {
  kind = input.required<'income' | 'expense'>();
  private http = inject(HttpClient);
  private auth = inject(AuthService);
  private toast = inject(ToastService);
  /** AG-064 S2: the active branch's expense approval limit (GET /branches) and the typed amount. */
  threshold = signal<string | null>(null);
  amount = signal<unknown>(null);
  /** Display only (the server decides, R2): an expense, a limit, not Super Admin, amount strictly above, in cents. */
  needsApproval = computed(() => {
    if (this.kind() !== 'expense' || this.auth.isSuperAdmin()) return false;
    const t = toCents(this.threshold());
    const a = toCents(this.amount());
    return t !== null && a !== null && a > t;
  });
  private router = inject(Router);
  today = colomboToday();
  accounts = signal<Account[]>([]);
  categories = signal<Category[]>([]);
  parentIdSig = signal<number | null>(null);
  busy = signal(false);
  error = signal('');
  result = signal<SaveResult | null>(null);
  uploads = signal<UploadLine[]>([]);
  accept = ATTACH_ACCEPT;
  /** Client checks before save (spec S1); Save is blocked while one shows. */
  fileError = computed(() => selectionProblem(this.files()));
  private files = signal<File[]>([]);
  m: any = this.blank();

  get parentId() { return this.parentIdSig(); }
  set parentId(v: number | null) { this.parentIdSig.set(v); }
  subs = computed(() => this.categories().find(c => c.id === this.parentIdSig())?.children ?? []);

  ngOnInit() {
    if (this.kind() === 'expense') {
      const active = Number(this.auth.activeBranchId());
      this.http.get<BranchLimit[]>('/api/v1/branches').subscribe(b => this.threshold.set(b.find(x => x.id === active)?.expense_approval_threshold ?? null));
    }
    this.http.get<Account[]>('/api/v1/accounts').subscribe(a => {
      this.accounts.set(a.filter(x => x.status !== 'closed'));
      this.m['account_id'] ??= a[0]?.id;
    });
    // Categories spec S2: only parents with at least one active sub-category visible in this branch.
    this.http.get<Category[]>('/api/v1/categories', { params: { type: this.kind() } })
      .subscribe(c => this.categories.set(c.filter(p => (p.children ?? []).length > 0)));
  }

  size(n: number) { return fileSize(n); }

  pick(input: HTMLInputElement) {
    const added = Array.from(input.files ?? []);
    input.value = ''; // the same file can be picked again after removing it
    this.files.update(f => [...f, ...added]);
    this.syncLines();
  }

  drop(i: number) {
    this.files.update(f => f.filter((_, j) => j !== i));
    this.syncLines();
  }

  save() {
    if (this.fileError() || this.busy()) return; // double-submit guard (QA Q1): a second click can fire before the button disables
    this.busy.set(true);
    this.error.set('');
    this.result.set(null);
    this.http.post<SaveResponse>(`/api/v1/${this.kind() === 'income' ? 'incomes' : 'expenses'}`, this.m).subscribe({
      next: async r => {
        // AG-064 S2: the response's status picks the message; the R17 warning follows it (never a block).
        const pending = r.status === 'pending';
        if (pending) this.toast.show(`${r.ref_no} sent for approval. Balances do not change until it is approved.`);
        // The entry is posted (or held); a file problem never rolls it back (F1). Files upload to a pending entry too.
        const res = await this.uploadAll(r.id);
        this.result.set({ id: r.id, ref: r.ref_no, ...res, pending, warning: r.warning ?? null,
          sameDay: r.hold_reason === 'same_day_total' ? r.same_day_total ?? null : null, date: r.txn_date ?? this.m['txn_date'] });
        const keep = { account_id: this.m['account_id'], txn_date: this.m['txn_date'] };
        this.m = { ...this.blank(), ...keep };
        this.amount.set(null);
        this.files.set([]);
        this.uploads.set([]);
        this.busy.set(false);
        this.ngOnInit(); // refresh balances
      },
      error: e => { this.error.set(errorText(e)); this.busy.set(false); },
    });
  }

  /** One file per request, in order, each with its own progress bar. */
  private async uploadAll(txnId: number): Promise<Omit<SaveResult, 'id' | 'ref'>> {
    const files = this.files();
    const failures: { name: string; message: string }[] = [];
    for (const [i, f] of files.entries()) {
      try {
        await uploadAttachment(this.http, txnId, f, pct => this.patch(i, { pct }));
        this.patch(i, { pct: 100, done: true });
      } catch (e) {
        const message = uploadErrorText(e);
        failures.push({ name: f.name, message });
        this.patch(i, { error: message });
      }
    }
    return { total: files.length, attached: files.length - failures.length, failures };
  }

  private patch(i: number, p: Partial<UploadLine>) {
    this.uploads.update(lines => lines.map((l, j) => (j === i ? { ...l, ...p } : l)));
  }

  private syncLines() {
    this.uploads.set(this.files().map(f => ({ name: f.name, size: f.size, pct: 0 })));
  }

  private blank() {
    return { txn_date: this.today, account_id: null, category_id: null, amount: null, payee_payer: '', payment_method: 'cash', reference: '', description: '' };
  }
}
