import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import { BUSINESS_TZ } from './dates';

export interface FiscalYearInfo { start_year: number; label: string; start: string; end: string }

/** GET /settings/public (settings spec section 5). */
export interface PublicSettings {
  company_name: string;
  logo: { sha256: string; updated_at: string | null } | null;
  currency_code: string;
  currency_decimals: number;
  date_format: string;
  fiscal_year_start_month: number | string;
  current_fiscal_year: FiscalYearInfo;
  /** AG-051 R2: transfers above this amount need Main's approval; null = never. */
  transfer_approval_threshold?: string | null;
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/** S3: the date format setting -> display. `D MMM YYYY` is the default (today's look). */
export function formatYmd(ymd: string, format: string): string {
  const [y, m, d] = ymd.split('-');
  if (!y || !m || !d) return ymd;
  switch (format) {
    case 'DD/MM/YYYY': return `${d}/${m}/${y}`;
    case 'DD-MM-YYYY': return `${d}-${m}-${y}`;
    case 'YYYY-MM-DD': return `${y}-${m}-${d}`;
    default: return `${Number(d)} ${MONTHS[Number(m) - 1] ?? m} ${y}`;
  }
}

/** A YYYY-MM-DD date as-is, or the Asia/Colombo day (and HH:mm) of an ISO timestamp. */
export function colomboParts(value: string): { ymd: string; hm: string } {
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return { ymd: value, hm: '' };
  const d = new Date(value);
  if (isNaN(d.getTime())) return { ymd: value.slice(0, 10), hm: '' };
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-GB', {
    timeZone: BUSINESS_TZ, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).formatToParts(d).map(x => [x.type, x.value]));
  return { ymd: `${p['year']}-${p['month']}-${p['day']}`, hm: `${p['hour']}:${p['minute']}` };
}

/** Company settings for every signed-in user: name, logo, currency, date format and fiscal year (S2-S4). */
@Injectable({ providedIn: 'root' })
export class SettingsService {
  private http = inject(HttpClient);
  readonly settings = signal<PublicSettings | null>(null);
  /** Object URL of the logo, fetched with the bearer token and cached for the session (S2). */
  readonly logoUrl = signal<string | null>(null);
  private logoSha: string | null = null;

  readonly companyName = computed(() => this.settings()?.company_name ?? 'Account gold');
  readonly currency = computed(() => this.settings()?.currency_code ?? 'LKR');
  readonly dateFormat = computed(() => this.settings()?.date_format ?? 'D MMM YYYY');
  readonly startMonth = computed(() => Number(this.settings()?.fiscal_year_start_month ?? 4));

  async load(): Promise<void> {
    try {
      this.apply(await firstValueFrom(this.http.get<PublicSettings>('/api/v1/settings/public')));
    } catch { /* keep the defaults; screens still work */ }
  }

  /** After a save on the Settings screen (or a load): refresh the logo only when its sha256 changed. */
  apply(s: PublicSettings) {
    this.settings.set(s);
    const sha = s.logo?.sha256 ?? null;
    if (sha === this.logoSha) return;
    this.logoSha = sha;
    const old = this.logoUrl();
    this.logoUrl.set(null);
    if (old) URL.revokeObjectURL(old);
    if (!sha) return;
    this.http.get('/api/v1/settings/logo', { responseType: 'blob' }).subscribe({
      next: b => this.logoUrl.set(URL.createObjectURL(b)),
      error: () => this.logoUrl.set(null), // 404 / 409 / 410: the company name alone
    });
  }

  clear() {
    const old = this.logoUrl();
    if (old) URL.revokeObjectURL(old);
    this.logoUrl.set(null);
    this.logoSha = null;
    this.settings.set(null);
  }

  /** A business date (YYYY-MM-DD) or ISO timestamp as a date in the setting's format. */
  date(value: string | null | undefined): string {
    if (!value) return '';
    return formatYmd(colomboParts(value).ymd, this.dateFormat());
  }

  /** An ISO timestamp as "<date> HH:mm" (24 h, Asia/Colombo). */
  dateTime(value: string | null | undefined): string {
    if (!value) return '';
    const p = colomboParts(value);
    return `${formatYmd(p.ymd, this.dateFormat())}${p.hm ? ' ' + p.hm : ''}`;
  }

  /** The fiscal year that contains a YYYY-MM-DD date (R4). */
  fiscalYearOf(ymd: string): FiscalYearInfo {
    const [y, m] = ymd.split('-').map(Number);
    const sm = this.startMonth();
    return this.fiscalYear(m >= sm ? y : y - 1);
  }

  fiscalYear(startYear: number): FiscalYearInfo {
    const sm = this.startMonth();
    const pad = (n: number) => String(n).padStart(2, '0');
    const start = `${startYear}-${pad(sm)}-01`;
    const endY = sm === 1 ? startYear : startYear + 1;
    const endM = sm === 1 ? 12 : sm - 1;
    const end = `${endY}-${pad(endM)}-${pad(new Date(endY, endM, 0).getDate())}`;
    const label = sm === 1 ? `FY ${startYear}` : `FY ${startYear}-${pad((startYear + 1) % 100)}`;
    return { start_year: startYear, label, start, end };
  }

  /** Today's fiscal year (the server's, so the same Asia/Colombo day). */
  currentFiscalYear(): FiscalYearInfo {
    return this.settings()?.current_fiscal_year ?? this.fiscalYearOf(colomboParts(new Date().toISOString()).ymd);
  }
}
