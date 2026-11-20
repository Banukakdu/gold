import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { AuthService } from '../../core/auth.service';

interface LockStatus { branch_id: number | null; closed_through: string | null; label: string | null }

/**
 * Read-only lock indicator for the active branch (spec section 2, reviewer R11). Branch users cannot open the
 * Period locks screen; this tells them which dates are closed before they try to post.
 */
@Component({
  selector: 'ag-lock-banner',
  standalone: true,
  template: `
    @if (status()?.label; as label) {
      <div class="ok" style="margin-bottom:12px">
        Books are closed through {{ label }} for {{ auth.activeBranch()?.code ?? 'this branch' }}: entries dated in or before
        {{ label }} cannot be created, edited, voided or reversed.
      </div>
    }
  `,
})
export class LockBannerComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  status = signal<LockStatus | null>(null);

  ngOnInit() {
    if (this.auth.isAllBranches()) return;
    this.http.get<LockStatus>('/api/v1/period-locks/status').subscribe({ next: s => this.status.set(s), error: () => this.status.set(null) });
  }
}
