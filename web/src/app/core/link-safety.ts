import { afterNextRender, inject } from '@angular/core';
import { Location } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';

/**
 * Password reset spec 4.3 link safety (also /set-password, Q6): the token is removed from the address bar once read.
 * "No Referer" is not set here: that is too late for requests made while the app loads (reviewer R5), so it is in
 * index.html (<meta name="referrer" content="no-referrer">) and in the host's Referrer-Policy header (README).
 * Call in a component's constructor or field initialiser.
 */
export function protectTokenLink(): void {
  const location = inject(Location);

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
