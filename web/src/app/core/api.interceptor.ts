import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { environment } from '../../environments/environment';
import { AuthService } from './auth.service';

/** Adds API base URL, bearer token and the active branch header to every API call. */
export const apiInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const router = inject(Router);
  if (!req.url.startsWith('/api/')) return next(req);

  let headers = req.headers.set('Accept', 'application/json');
  const token = auth.token();
  if (token) headers = headers.set('Authorization', `Bearer ${token}`);
  // A request may target another branch explicitly (Super Admin managing memberships); keep its header.
  const branch = auth.activeBranchId();
  if (branch && !req.headers.has('X-Branch-Id')) headers = headers.set('X-Branch-Id', branch);

  return next(req.clone({ url: environment.apiUrl + req.url, headers })).pipe(
    catchError((err: HttpErrorResponse) => {
      if (err.status === 401) {
        auth.clear();
        router.navigateByUrl('/login');
      }
      return throwError(() => err);
    }),
  );
};

/** Turns a Laravel error response into one readable line. */
export function errorText(err: unknown): string {
  const e = err as HttpErrorResponse;
  const body = e?.error;
  if (body?.errors) return Object.values(body.errors as Record<string, string[]>).flat().join(' ');
  return body?.message ?? 'Something went wrong. Please try again.';
}
