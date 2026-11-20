import { Injectable, signal } from '@angular/core';

export type ToastKind = 'info' | 'warning';

/**
 * AG-094: one short message at the bottom of the screen (shown by the shell), e.g. "Preference saved.".
 * AG-064 S2: a warning can follow the current message (`then`): it shows once the current one has had its time.
 */
@Injectable({ providedIn: 'root' })
export class ToastService {
  readonly text = signal('');
  readonly kind = signal<ToastKind>('info');
  private timer: ReturnType<typeof setTimeout> | null = null;
  private queue: { text: string; kind: ToastKind; ms: number }[] = [];

  show(text: string, ms = 4000, kind: ToastKind = 'info') {
    this.queue = [];
    this.display(text, ms, kind);
  }

  /** Show after the current message (or now when none is showing). */
  then(text: string, kind: ToastKind = 'warning', ms = 6000) {
    if (!this.text()) this.display(text, ms, kind);
    else this.queue.push({ text, kind, ms });
  }

  clear() {
    if (this.timer) clearTimeout(this.timer);
    this.queue = [];
    this.text.set('');
    this.kind.set('info');
  }

  private display(text: string, ms: number, kind: ToastKind) {
    this.text.set(text);
    this.kind.set(kind);
    if (this.timer) clearTimeout(this.timer);
    this.timer = setTimeout(() => {
      const next = this.queue.shift();
      if (next) this.display(next.text, next.ms, next.kind);
      else { this.text.set(''); this.kind.set('info'); }
    }, ms);
  }
}
