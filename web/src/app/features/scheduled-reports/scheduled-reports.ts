import { SettingsService, colomboParts } from '../../core/settings.service';

/** AG-088 (docs/specs/scheduled-reports.md section 5): the `schedule` resource. */
export interface ScheduleRecipient {
  id: number; name: string; email: string; status: string; is_super_admin: boolean; eligible: boolean; reason: string | null;
}
export interface ScheduleLastRun { id: string; planned_at: string; status: RunStatus; sent: number; skipped: number; failed: number; queued: number }
export type RunStatus = 'queued' | 'running' | 'done' | 'failed' | 'missed';
export interface Schedule {
  id: number; name: string; report: string; title: string; params: { type?: string; types?: string[] }; params_label: string;
  branches: { id: number; code: string; status: string | null }[]; branches_label: string;
  frequency: 'daily' | 'weekly'; weekday: number | null; send_time: string;
  status: 'active' | 'paused' | 'archived'; paused_reason: string | null; paused_reason_text: string | null;
  owner: { id: number; name: string } | null; created_by: { id: number; name: string } | null; created_at: string | null;
  updated_by: { id: number; name: string } | null; updated_at: string | null;
  archived_at: string | null; archived_by: { id: number; name: string } | null; archive_reason: string | null;
  next_run_at: string | null; next_period: { from: string; to: string } | null;
  recipients: ScheduleRecipient[]; cannot_receive: number; last_run: ScheduleLastRun | null;
}
export interface RunDelivery {
  user: { id: number; name: string }; email: string; status: 'queued' | 'sent' | 'skipped' | 'failed';
  reason: string | null; reason_text: string | null; sent_at: string | null; failed_at: string | null;
}
export interface ScheduleRun {
  id: string; planned_at: string; period_from: string; period_to: string; status: RunStatus; failure_code: string | null;
  error: string | null; missed_count: number | null; rows: number | null; attached: boolean; size_bytes: number | null;
  data_as_at: string | null; started_at: string | null; finished_at: string | null; attempts: number;
  sent: number; skipped: number; failed: number; queued: number; deliveries: RunDelivery[];
}
export interface RecipientOption { id: number; name: string; email: string; is_super_admin: boolean; status: string; eligible: boolean; reason: string | null }
export interface PreviewTime { next_run_at: string; period_from: string; period_to: string }

/** Section 1: the four reports a schedule may send (ReportExporter::TITLES). */
export const SCHEDULE_REPORTS: [string, string][] = [
  ['income-expense', 'Income & Expense by Category'],
  ['branch-comparison', 'Branch Comparison'],
  ['transfers', 'Transfers & Allocations'],
  ['cash-book', 'Daily Cash Book'],
];

/** ReportService::MOVEMENT_TYPES with their labels. */
export const MOVEMENT_TYPES: [string, string][] = [
  ['opening_allocation', 'Opening allocation'], ['fund_allocation', 'Top-up'], ['fund_return', 'Fund return'], ['branch_transfer', 'Branch transfer'],
];

/** ISO weekday 1 = Monday .. 7 = Sunday. */
export const WEEKDAYS = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const SHORT_DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** S2: 00:00 .. 23:30 in 30-minute steps. */
export const SEND_TIMES: string[] = Array.from({ length: 48 }, (_, i) => `${String(Math.floor(i / 2)).padStart(2, '0')}:${i % 2 ? '30' : '00'}`);

export const SCHEDULE_NOTE = 'The report is emailed as a PDF to each recipient. An email cannot be recalled once sent. Recipients without access when it is sent are skipped.';
export const MULTI_BRANCH_NOTE = 'Only Super Admins can receive reports covering more than one branch.';

/** "Daily 07:00" / "Weekly, Monday 07:00". */
export function frequencyLabel(s: Pick<Schedule, 'frequency' | 'weekday' | 'send_time'>): string {
  return s.frequency === 'weekly' ? `Weekly, ${WEEKDAYS[s.weekday ?? 1]} ${s.send_time}` : `Daily ${s.send_time}`;
}

/** "Sat 21 Nov 2026" for a YYYY-MM-DD business date, in the date format setting. */
export function dayLabel(settings: SettingsService, ymd: string): string {
  const [y, m, d] = ymd.split('-').map(Number);
  return `${SHORT_DAYS[new Date(Date.UTC(y, m - 1, d)).getUTCDay()]} ${settings.date(ymd)}`;
}

/** "Sat 21 Nov 2026 07:00" for an ISO timestamp (Asia/Colombo). */
export function dayTimeLabel(settings: SettingsService, iso: string): string {
  const p = colomboParts(iso);
  return `${dayLabel(settings, p.ymd)} ${p.hm}`;
}

/** S2 live line: "Next email: Sat 21 Nov 2026 07:00, covering Fri 20 Nov 2026" (weekly: "Mon 16 Nov 2026 - Sun 22 Nov 2026"). */
export function nextEmailLine(settings: SettingsService, nextRunAt: string, from: string, to: string): string {
  const covering = from === to ? dayLabel(settings, from) : `${dayLabel(settings, from)} - ${dayLabel(settings, to)}`;
  return `Next email: ${dayTimeLabel(settings, nextRunAt)}, covering ${covering}`;
}

/** S2 "Preview this report" and the email link (R7): the report screen with the period and branch scope (W1). */
export function reportUrl(report: string, from: string, to: string, branches: number[]): string {
  const base = `/reports/${report}?from=${from}&to=${to}`;
  if (branches.length === 1) return `${base}&branch=${branches[0]}`;
  return `${base}&branch=all${branches.map(b => `&branches=${b}`).join('')}`;
}

/** S1 / S3 badges: Sent (green), Partly sent (amber), Failed / Missed (red), Nothing sent (grey). */
export function runBadge(r: { status: RunStatus; sent: number; skipped: number; failed: number; queued: number }): { label: string; s: string } {
  switch (r.status) {
    case 'failed': return { label: 'Failed', s: 'sr-red' };
    case 'missed': return { label: 'Missed', s: 'sr-red' };
    case 'queued': return { label: 'Queued', s: 'sr-grey' };
    case 'running': return { label: 'Running', s: 'sr-grey' };
  }
  if (r.queued > 0) return { label: 'Sending', s: 'sr-grey' };
  if (r.sent === 0) return { label: 'Nothing sent', s: 'sr-grey' };
  if (r.skipped + r.failed > 0) return { label: 'Partly sent', s: 'sr-amber' };
  return { label: 'Sent', s: 'sr-green' };
}

export const STATUS_LABELS: Record<Schedule['status'], string> = { active: 'Active', paused: 'Paused', archived: 'Archived' };
export const DELIVERY_LABELS: Record<RunDelivery['status'], string> = { queued: 'Queued', sent: 'Sent', skipped: 'Skipped', failed: 'Failed' };

/** "12 KB" / "1.2 MB". */
export function fileSize(bytes: number | null): string {
  if (!bytes) return '';
  return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.ceil(bytes / 1024))} KB`;
}
