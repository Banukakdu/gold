import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from './auth.service';

export const authGuard: CanActivateFn = async () => {
  const auth = inject(AuthService);
  if (auth.me() || (await auth.loadMe())) return true;
  return inject(Router).parseUrl('/login');
};

export const permissionGuard = (permission: string): CanActivateFn => () => {
  const auth = inject(AuthService);
  return auth.can(permission) || inject(Router).parseUrl('/dashboard');
};

export const superAdminGuard: CanActivateFn = () =>
  inject(AuthService).isSuperAdmin() || inject(Router).parseUrl('/dashboard');
