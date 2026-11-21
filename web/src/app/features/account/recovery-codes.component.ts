import { Component, HostListener, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RECOVERY_FILE, recoveryFileText } from '../../core/two-factor';
import { colomboDateTime } from '../../core/dates';

/**
 * AG-005 S4: the recovery codes, shown once. They live only in the parent's memory and are cleared on "Done" or when
 * the user leaves. The browser asks before leaving while "I have saved these codes" is not ticked.
 */
@Component({
  selector: 'app-recovery-codes',
  standalone: true,
  imports: [FormsModule],
  template: `
    <div class="tf-codes-panel">
      <h2>Save your recovery codes</h2>
      <p>Each code works once if you lose your phone. Keep them somewhere safe and private. They will not be shown again.</p>
      <ul class="tf-codes" data-testid="recovery-codes">
        @for (c of codes(); track c) { <li>{{ c }}</li> }
      </ul>
      <div class="tf-actions">
        <button type="button" class="ghost" (click)="copyAll()">{{ copied() ? 'Copied' : 'Copy all' }}</button>
        <button type="button" class="ghost" (click)="download()">Download .txt</button>
        <button type="button" class="ghost" (click)="print()">Print</button>
      </div>
      <label class="tf-check"><input type="checkbox" name="saved" [(ngModel)]="saved" /> I have saved these codes</label>
      <button type="button" class="tf-done" [disabled]="!saved" (click)="finish()">Done</button>
    </div>
  `,
})
export class RecoveryCodesComponent {
  codes = input.required<string[]>();
  email = input('');
  done = output<void>();
  saved = false;
  copied = signal(false);

  @HostListener('window:beforeunload', ['$event'])
  beforeUnload(e: BeforeUnloadEvent) {
    if (!this.saved) {
      e.preventDefault();
      e.returnValue = '';
    }
  }

  async copyAll() {
    try {
      await navigator.clipboard.writeText(this.codes().join('\n'));
      this.copied.set(true);
    } catch { /* the codes stay on screen */ }
  }

  /** The codes, the email and the date; never the secret. */
  download() {
    const text = recoveryFileText(this.codes(), this.email(), colomboDateTime(new Date().toISOString()) + ' (Asia/Colombo)');
    const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = RECOVERY_FILE;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 0);
  }

  print() { window.print(); }

  finish() {
    if (!this.saved) return;
    this.done.emit();
  }
}
