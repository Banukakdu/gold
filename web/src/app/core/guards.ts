import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from './auth.service';

export const authGuard: CanActivateFn = async (_route, state) => {
  const auth = inject(AuthService);
  if (auth.me() || (await auth.loadMe())) return true;
  // AG-087 S2: a deep link (the export email's /exports?id=...) comes back after sign-in.
  const router = inject(Router);
  return state.url && state.url !== '/' ? router.createUrlTree(['/login'], { queryParams: { returnUrl: state.url } }) : router.parseUrl('/login');
};

/** Only an in-app path ("/..." but not "//host") is followed after sign-in. */
export function safeReturnUrl(url: string | null): string {
  return url && url.startsWith('/') && !url.startsWith('//') && !url.startsWith('/login') ? url : '/dashboard';
}

export const permissionGuard = (permission: string): CanActivateFn => () => {
  const auth = inject(AuthService);
  return auth.can(permission) || inject(Router).parseUrl('/dashboard');
};

export const superAdminGuard: CanActivateFn = () =>
  inject(AuthService).isSuperAdmin() || inject(Router).parseUrl('/dashboard');

/** Pages that need a single branch (e.g. New user): redirect elsewhere in "All branches" mode. */
export const branchModeGuard = (redirectTo: string): CanActivateFn => () => {
  const auth = inject(AuthService);
  return !auth.isAllBranches() || inject(Router).parseUrl(redirectTo);
};
