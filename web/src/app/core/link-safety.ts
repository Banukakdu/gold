import { DestroyRef, afterNextRender, inject } from '@angular/core';
import { Location } from '@angular/common';
import { Meta } from '@angular/platform-browser';
import { HttpErrorResponse } from '@angular/common/http';

/**
 * Password reset spec 4.3 link safety (also /set-password, Q6): no Referer header while the page is open, and the
 * token is removed from the address bar once read. Call in a component's constructor or field initialiser.
 */
export function protectTokenLink(): void {
  const meta = inject(Meta);
  const location = inject(Location);
  meta.updateTag({ name: 'referrer', content: 'no-referrer' });
  inject(DestroyRef).onDestroy(() => meta.removeTag('name="referrer"'));

  // After the first render, so the router has already written the navigated URL that we now replace.
  afterNextRender(() => {
    const [path, query = ''] = location.path().split('?');
    const params = new URLSearchParams(query);
    if (params.has('token')) {
      params.delete('token');
      location.replaceState(path, params.toString());
    }
  });
}

/** The first server message for one field of a 422, or ''. */
export function fieldError(err: unknown, field: string): string {
  return (err as HttpErrorResponse)?.error?.errors?.[field]?.[0] ?? '';
}

/** The one hint for the shared password policy (spec R10). */
export const PASSWORD_HINT = 'At least 8 characters, with a letter and a number.';
