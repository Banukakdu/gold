import { HttpErrorResponse } from '@angular/common/http';
import { colomboDateTime } from './dates';

/** AG-005 (docs/specs/two-factor.md): shared texts and helpers of the two-factor screens. */

/** POST /auth/login when a second step is needed (R4). Kept in component memory only, never in storage or the URL. */
export interface TwoFactorChallenge { two_factor: 'challenge' | 'enrol'; challenge_token: string; expires_at: string }

/** P2 / M2: the pending secret. */
export interface TwoFactorSetup { secret: string; otpauth_uri: string; expires_at: string }

/** M1 GET /me/two-factor */
export interface TwoFactorStatus {
  enabled: boolean;
  confirmed_at: string | null;
  required: boolean;
  recovery_codes_left: number;
  setup_pending_until: string | null;
}

export const CODE_ERROR = "That code didn't work. Check that the time on your phone is set automatically.";
export const RECOVERY_ERROR = 'That recovery code is not valid or has already been used.';
export const EXPIRED_SIGN_IN = 'Your sign-in has expired. Enter your email and password again.';
export const SETUP_EXPIRED = 'This setup has expired. Start again.';
export const GENERIC_ERROR = 'Something went wrong. Try again.';
export const RECOVERY_FILE = 'account-gold-recovery-codes.txt';

/** The code typed or pasted: digits only (spaces and anything else removed), at most 6. */
export function cleanCode(v: string): string {
  return (v ?? '').replace(/\D/g, '').slice(0, 6);
}

/** S3: the setup key in groups of 4 ("ABCD EFGH ..."). */
export function groupKey(secret: string): string {
  return (secret ?? '').replace(/\s/g, '').match(/.{1,4}/g)?.join(' ') ?? '';
}

/** "HH:mm" in Asia/Colombo for an ISO time from the API (R19: the UI never computes expiry times). */
export function colomboTime(iso: string | null | undefined): string {
  return colomboDateTime(iso).slice(11);
}

/** The text of the downloaded recovery codes file: the codes, the email and the date, never the secret (S4). */
export function recoveryFileText(codes: string[], email: string, when: string): string {
  return ['Account-gold recovery codes', `Account: ${email}`, `Created: ${when}`, '',
    'Each code works once. Keep them somewhere safe and private.', '', ...codes, ''].join('\n');
}

/** The first message of a 422 field, or ''. */
export function fieldMessage(err: unknown, field: string): string {
  return (err as HttpErrorResponse)?.error?.errors?.[field]?.[0] ?? '';
}

/** 0 (network), 5xx: "Something went wrong. Try again."; otherwise the API message. */
export function apiMessage(err: unknown): string {
  const e = err as HttpErrorResponse;
  if (!e || !e.status || e.status >= 500) return GENERIC_ERROR;
  const body = e.error;
  if (body?.errors) return Object.values(body.errors as Record<string, string[]>).flat().join(' ');
  return body?.message ?? GENERIC_ERROR;
}
