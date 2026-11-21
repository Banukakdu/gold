import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { StepUpAnswer, StepUpDialogComponent } from '../account/step-up-dialog.component';
import { errorText } from '../../core/api.interceptor';
import { fileSize } from '../../core/attachments';
import { MONTH_NAMES, PublicSettings, SettingsService, formatYmd } from '../../core/settings.service';

interface Person { id: number; name: string }
interface LogoInfo {
  original_name: string; mime: string; size_bytes: number; width: number; height: number; sha256: string;
  uploaded_by: Person | null; uploaded_at: string | null; file_problem: 'missing' | 'changed' | null;
}
/** GET /settings (settings spec section 5). */
interface FullSettings extends Omit<PublicSettings, 'logo'> {
  logo: LogoInfo | null;
  currency_locked: boolean;
  fiscal_year_start_locked: boolean;
  date_formats: string[];
  currencies: string[];
  last_changed: Record<string, { by: Person | null; at: string | null }>;
  /** AG-052 S1. */
  transfer_receipt_required: boolean;
  /** AG-051 S1 / R18. */
  transfer_approval_threshold: string | null;
  transfer_approval_warning: string | null;
  /** AG-005 S6. */
  two_factor_required_for_admins: boolean;
  two_factor_pending_admins: { count: number; users: { id: number; name: string; email: string }[] };
}
type Editable = 'company_name' | 'currency_code' | 'date_format' | 'fiscal_year_start_month' | 'transfer_receipt_required' | 'transfer_approval_threshold'
  | 'two_factor_required_for_admins';

const MAX_LOGO = 1024 * 1024;
const SAMPLE = '2026-10-05';

/** AG-092 S1 Settings. Super Admin only, any switcher mode. */
@Component({
  standalone: true,
  imports: [FormsModule, RouterLink, StepUpDialogComponent],
  template: `
    <h1>Settings</h1>
    @if (msg()) { <div class="alert">{{ msg() }}</div> }
    @if (ok()) { <div class="ok">{{ ok() }}</div> }
    @if (s(); as s) {
      <form class="settings-form" (ngSubmit)="save()">
        <fieldset>
          <legend>Company</legend>
          <label>Company name
            <input name="company_name" [(ngModel)]="form.company_name" required minlength="2" maxlength="120" />
            @if (err()['company_name']) { <span class="neg">{{ err()['company_name'] }}</span> }
          </label>
          <div class="changed">{{ changed('company_name') }}</div>
          <div>
            <div class="muted">Logo</div>
            <div class="logo-box">
              @if (settings.logoUrl(); as url) { <img [src]="url" [alt]="s.company_name" /> }
              @if (s.logo; as l) {
                <div>
                  <div>{{ l.original_name }} · {{ size(l.size_bytes) }} · {{ l.width }} × {{ l.height }} px</div>
                  <small class="muted">Uploaded by {{ l.uploaded_by?.name ?? '?' }} on {{ settings.date(l.uploaded_at) }}</small>
                  @if (l.file_problem) { <div class="neg">The logo file is missing or was changed outside the app. Upload it again.</div> }
                </div>
              } @else { <span class="muted">No logo. Reports and the header show the company name only.</span> }
            </div>
            <input #picker type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" hidden (change)="upload(picker)" />
            <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
              <button type="button" class="ghost" [disabled]="busy()" (click)="picker.click()">{{ s.logo ? 'Replace logo' : 'Upload logo' }}</button>
              @if (s.logo) { <button type="button" class="ghost" [disabled]="busy()" (click)="removeLogo()">Remove logo</button> }
            </div>
            <small class="muted">JPG or PNG, at most 1 MB, 16 to 2000 pixels each side.</small>
            @if (logoMsg()) { <div class="alert">{{ logoMsg() }}</div> }
          </div>
        </fieldset>

        <fieldset>
          <legend>Money</legend>
          <label>Currency
            <select name="currency_code" [(ngModel)]="form.currency_code" [disabled]="s.currency_locked">
              @for (c of s.currencies; track c) { <option [value]="c">{{ c }}</option> }
            </select>
            @if (err()['currency_code']) { <span class="neg">{{ err()['currency_code'] }}</span> }
          </label>
          @if (s.currency_locked) { <small class="muted">The currency cannot be changed after entries have been posted.</small> }
          <div class="changed">{{ changed('currency_code') }}</div>
          <label>Decimal places <input name="decimals" value="2" readonly /></label>
          <small class="muted">Amounts are kept to 2 decimal places.</small>
        </fieldset>

        <fieldset>
          <legend>Display</legend>
          <label>Date format
            <select name="date_format" [(ngModel)]="form.date_format">
              @for (f of s.date_formats; track f) { <option [value]="f">{{ f }} ({{ sample(f) }})</option> }
            </select>
            @if (err()['date_format']) { <span class="neg">{{ err()['date_format'] }}</span> }
          </label>
          <small class="muted">Changes how dates are shown, not how they are entered or stored.</small>
          <div class="changed">{{ changed('date_format') }}</div>
        </fieldset>

        <fieldset>
          <legend>Fiscal year</legend>
          <label>Fiscal year starts in
            <select name="fiscal_year_start_month" [(ngModel)]="form.fiscal_year_start_month" [disabled]="s.fiscal_year_start_locked">
              @for (m of months; track $index) { <option [ngValue]="$index + 1">{{ m }}</option> }
            </select>
            @if (err()['fiscal_year_start_month']) { <span class="neg">{{ err()['fiscal_year_start_month'] }}</span> }
          </label>
          @if (s.fiscal_year_start_locked) { <small class="muted">Cannot be changed after a year-end close.</small> }
          <div>Current fiscal year: {{ s.current_fiscal_year.label }} ({{ settings.date(s.current_fiscal_year.start) }} – {{ settings.date(s.current_fiscal_year.end) }})</div>
          <div class="changed">{{ changed('fiscal_year_start_month') }}</div>
        </fieldset>

        <fieldset>
          <legend>Transfers</legend>
          <label class="tr-check">
            <input type="checkbox" style="width:auto" name="transfer_receipt_required" [(ngModel)]="form.transfer_receipt_required" />
            <span>Receiving branch must confirm receipt of transfers between branches</span>
          </label>
          <small class="muted">Applies to transfers posted after you turn it on. It does not change balances or reports.
            Grant 'transfer.acknowledge' to the Branch Admin, Accountant and Cashier roles in each branch before turning this on
            (branch roles already copied from the templates do not get it automatically).</small>
          @if (err()['transfer_receipt_required']) { <span class="neg">{{ err()['transfer_receipt_required'] }}</span> }
          <div class="changed">{{ changed('transfer_receipt_required') }}</div>
          <!-- AG-051 S1 -->
          <label>Transfers above this amount need approval
            <input name="transfer_approval_threshold" inputmode="decimal" [(ngModel)]="form.transfer_approval_threshold" placeholder="Never" />
            @if (err()['transfer_approval_threshold']) { <span class="neg">{{ err()['transfer_approval_threshold'] }}</span> }
          </label>
          <small class="muted">Leave empty for never. 0 means every branch transfer and fund return. Applies to branch transfers and fund
            returns made by branch users; Super Admin transfers and internal transfers are never held. Grant 'transfer.approve' in MAIN before setting this.</small>
          @if (s.transfer_approval_warning) { <div class="warn">{{ s.transfer_approval_warning }}</div> }
          <div class="changed">{{ changed('transfer_approval_threshold') }}</div>
        </fieldset>

        <!-- AG-005 S6 -->
        <fieldset>
          <legend>Sign-in security</legend>
          <label class="tr-check">
            <input type="checkbox" style="width:auto" name="two_factor_required_for_admins" [(ngModel)]="form.two_factor_required_for_admins" />
            <span>Require two-factor sign-in for admins</span>
          </label>
          <small class="muted">Admins are Super Admins and anyone whose role can create users, edit users, manage roles, or approve transfers or
            expenses in any branch. They must set up two-factor sign-in at their next sign-in or request.</small>
          @if (err()['two_factor_required_for_admins']; as e) {
            <span class="neg">{{ e }} @if (e.includes('your own account')) { <a routerLink="/account/security">Security</a> }</span>
          }
          @if (s.two_factor_pending_admins.count) {
            <div>{{ s.two_factor_pending_admins.count }} admins have not set it up yet:</div>
            <ul class="tf-pending">
              @for (u of s.two_factor_pending_admins.users; track u.id) { <li><a [routerLink]="['/users', u.id]">{{ u.name }} &lt;{{ u.email }}&gt;</a></li> }
            </ul>
            @if (s.two_factor_pending_admins.count > s.two_factor_pending_admins.users.length) {
              <div class="muted">and {{ s.two_factor_pending_admins.count - s.two_factor_pending_admins.users.length }} more</div>
            }
          } @else { <div class="muted">Every admin has two-factor sign-in.</div> }
          <div class="changed">{{ changed('two_factor_required_for_admins') }}</div>
        </fieldset>

        <fieldset>
          <legend>Approvals</legend>
          <span class="muted">Expense approval thresholds are set here when expense approval (AG-064) is released.</span>
        </fieldset>

        <div style="display:flex;gap:8px">
          <button type="submit" [disabled]="!dirty() || busy()">{{ busy() ? 'Saving...' : 'Save' }}</button>
          <button type="button" class="ghost" [disabled]="!dirty() || busy()" (click)="reset()">Cancel</button>
        </div>
      </form>
    } @else if (!msg()) { <p class="muted">Loading...</p> }
    @if (stepUp()) {
      <app-step-up-dialog title="Confirm the change" text="Changing two-factor sign-in for admins needs your password and current code."
        confirmLabel="Save" [second]="auth.me()?.two_factor?.enabled ? 'code' : 'none'" [busy]="busy()" [errors]="stepUpErrors()"
        [alert]="stepUpAlert()" (submitted)="save($event)" (cancel)="stepUp.set(false)" />
    }
  `,
})
export class SettingsComponent implements OnInit {
  private http = inject(HttpClient);
  settings = inject(SettingsService);
  auth = inject(AuthService);
  /** AG-005 S6: the step-up dialog before a save that changes two_factor_required_for_admins. */
  stepUp = signal(false);
  stepUpErrors = signal<Record<string, string>>({});
  stepUpAlert = signal('');
  months = MONTH_NAMES;
  s = signal<FullSettings | null>(null);
  form: Record<Editable, string | number | boolean> = { company_name: '', currency_code: '', date_format: '', fiscal_year_start_month: 4,
    transfer_receipt_required: false, transfer_approval_threshold: '', two_factor_required_for_admins: false };
  msg = signal('');
  ok = signal('');
  logoMsg = signal('');
  err = signal<Record<string, string>>({});
  busy = signal(false);

  /** Save and Cancel stay disabled until something changes (S1). */
  dirty() { return Object.keys(this.changes()).length > 0; }

  ngOnInit() { this.load(); }

  async load() {
    try {
      this.take(await firstValueFrom(this.http.get<FullSettings>('/api/v1/settings')));
    } catch (e) { this.msg.set(errorText(e)); }
  }

  private take(s: FullSettings) {
    this.s.set(s);
    this.reset();
    this.settings.apply({ ...s, logo: s.logo ? { sha256: s.logo.sha256, updated_at: s.logo.uploaded_at } : null });
  }

  reset() {
    const s = this.s();
    if (!s) return;
    this.form = { company_name: s.company_name, currency_code: s.currency_code, date_format: s.date_format,
      fiscal_year_start_month: Number(s.fiscal_year_start_month), transfer_receipt_required: !!s.transfer_receipt_required,
      transfer_approval_threshold: s.transfer_approval_threshold ?? '', two_factor_required_for_admins: !!s.two_factor_required_for_admins };
    this.err.set({});
  }

  /** Only the changed fields are sent (S1). */
  private changes(): Partial<Record<Editable, string | number | boolean>> {
    const s = this.s();
    if (!s) return {};
    const out: Partial<Record<Editable, string | number | boolean>> = {};
    const name = String(this.form.company_name).trim();
    if (name !== s.company_name) out.company_name = name;
    if (this.form.currency_code !== s.currency_code) out.currency_code = this.form.currency_code;
    if (this.form.date_format !== s.date_format) out.date_format = this.form.date_format;
    if (Number(this.form.fiscal_year_start_month) !== Number(s.fiscal_year_start_month)) out.fiscal_year_start_month = Number(this.form.fiscal_year_start_month);
    if (!!this.form.transfer_receipt_required !== !!s.transfer_receipt_required) out.transfer_receipt_required = !!this.form.transfer_receipt_required;
    // AG-051: sent as typed (the API checks the format and compares in cents); '' = never.
    const threshold = String(this.form.transfer_approval_threshold ?? '').trim();
    if (threshold !== (s.transfer_approval_threshold ?? '')) out.transfer_approval_threshold = threshold;
    if (!!this.form.two_factor_required_for_admins !== !!s.two_factor_required_for_admins) {
      out.two_factor_required_for_admins = !!this.form.two_factor_required_for_admins;
    }
    return out;
  }

  async save(stepUp?: StepUpAnswer) {
    const changes = this.changes();
    if (!Object.keys(changes).length || this.busy()) return;
    // AG-005 S6: changing the 2FA switch first asks for the password (and code when the Super Admin has 2FA on).
    if (changes.two_factor_required_for_admins !== undefined && !stepUp) {
      this.stepUpErrors.set({});
      this.stepUpAlert.set('');
      this.stepUp.set(true);
      return;
    }
    const body: Record<string, unknown> = { ...changes, ...(stepUp ?? {}) };
    // R4 / S1 (QA Q2): a new fiscal-year start moves every year boundary.
    if (changes.fiscal_year_start_month !== undefined
      && !confirm(`Change the fiscal year start to ${MONTH_NAMES[Number(changes.fiscal_year_start_month) - 1]}? Trial balances will use the new year boundaries.`)) return;
    this.busy.set(true);
    this.msg.set(''); this.ok.set(''); this.err.set({});
    try {
      this.take(await firstValueFrom(this.http.put<FullSettings>('/api/v1/settings', body)));
      this.stepUp.set(false);
      this.ok.set('Settings saved.');
    } catch (e: any) {
      const errors = e?.error?.errors as Record<string, string[]> | undefined;
      const flat: Record<string, string> = errors ? Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]])) : {};
      if (this.stepUp() && (flat['password'] || flat['code'] || e?.status === 429)) {
        // Wrong password or code: the dialog stays open with the message.
        this.stepUpErrors.set({ ...(flat['password'] ? { password: flat['password'] } : {}), ...(flat['code'] ? { code: flat['code'] } : {}) });
        this.stepUpAlert.set(e?.status === 429 ? errorText(e) : '');
        return;
      }
      this.stepUp.set(false);
      this.err.set(flat);
      this.msg.set(errorText(e));
    } finally { this.busy.set(false); }
  }

  async upload(input: HTMLInputElement) {
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    this.logoMsg.set(''); this.ok.set('');
    // Client checks before sending (the server is the authority, R3).
    if (!/\.(jpe?g|png)$/i.test(file.name)) { this.logoMsg.set('Choose a JPG or PNG file.'); return; }
    if (file.size > MAX_LOGO) { this.logoMsg.set(`${file.name} is larger than 1 MB.`); return; }
    const data = new FormData();
    data.append('file', file);
    this.busy.set(true);
    try {
      await firstValueFrom(this.http.post('/api/v1/settings/logo', data));
      await this.load();
      this.ok.set('Logo saved.');
    } catch (e) { this.logoMsg.set(errorText(e)); } finally { this.busy.set(false); }
  }

  async removeLogo() {
    if (!confirm('Remove the logo? Reports and the header will show the company name only.')) return;
    this.busy.set(true);
    this.logoMsg.set(''); this.ok.set('');
    try {
      await firstValueFrom(this.http.post('/api/v1/settings/logo/remove', {}));
      await this.load();
      this.ok.set('Logo removed.');
    } catch (e) { this.logoMsg.set(errorText(e)); } finally { this.busy.set(false); }
  }

  changed(key: string): string {
    const c = this.s()?.last_changed?.[key];
    return c ? `Last changed by ${c.by?.name ?? '?'} on ${this.settings.date(c.at)}` : '';
  }

  sample(format: string) { return formatYmd(SAMPLE, format); }
  size(n: number) { return fileSize(n); }
}
