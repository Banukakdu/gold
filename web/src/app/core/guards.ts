import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from './auth.service';

export const authGuard: CanActivateFn = async (_route, state) => {
  // Both injections before the first await: after it the guard is outside the injection context (NG0203).
  const auth = inject(AuthService);
  const router = inject(Router);
  if (auth.me() || (await auth.loadMe())) return true;
  // AG-087 S2: a deep link (the export email's /exports?id=...) comes back after sign-in.
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

/** AG-066 S1: any one of the keys in the active branch (Super Admin always). */
export const anyPermissionGuard = (...permissions: string[]): CanActivateFn => () => {
  const auth = inject(AuthService);
  return permissions.some(p => auth.can(p)) || inject(Router).parseUrl('/dashboard');
};

export const superAdminGuard: CanActivateFn = () =>
  inject(AuthService).isSuperAdmin() || inject(Router).parseUrl('/dashboard');

/** Pages that need a single branch (e.g. New user): redirect elsewhere in "All branches" mode. */
export const branchModeGuard = (redirectTo: string): CanActivateFn => () => {
  const auth = inject(AuthService);
  return !auth.isAllBranches() || inject(Router).parseUrl(redirectTo);
};

/**
 * AG-066 QA D2: an income or expense belongs to one branch, so its form is closed in "All branches" mode (the API
 * refuses it too). A template link goes to Expense templates, where "Switch to <branch> and use" picks the branch.
 */
export const entryBranchGuard: CanActivateFn = route => {
  const auth = inject(AuthService);
  if (!auth.isAllBranches()) return true;
  return inject(Router).parseUrl(route.queryParamMap.get('template') ? '/expense-templates' : '/transactions');
};
