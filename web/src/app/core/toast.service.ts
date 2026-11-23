import { Injectable, signal } from '@angular/core';

export type ToastKind = 'info' | 'warning';

/** AG-033b: one button on the message (e.g. "Attach bank statement" after Complete). */
export interface ToastAction { label: string; run: () => void }

/**
 * AG-094: one short message at the bottom of the screen (shown by the shell), e.g. "Preference saved.".
 * AG-064 S2: a warning can follow the current message (`then`): it shows once the current one has had its time.
 */
@Injectable({ providedIn: 'root' })
export class ToastService {
  readonly text = signal('');
  readonly kind = signal<ToastKind>('info');
  readonly action = signal<ToastAction | null>(null);
  private timer: ReturnType<typeof setTimeout> | null = null;
  private queue: { text: string; kind: ToastKind; ms: number }[] = [];

  show(text: string, ms = 4000, kind: ToastKind = 'info', action: ToastAction | null = null) {
    this.queue = [];
    this.display(text, ms, kind);
    this.action.set(action);
  }

  /** Runs the message's button and closes the message. */
  runAction() {
    const a = this.action();
    this.clear();
    a?.run();
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
    this.action.set(null);
  }

  private display(text: string, ms: number, kind: ToastKind) {
    this.text.set(text);
    this.kind.set(kind);
    this.action.set(null);
    if (this.timer) clearTimeout(this.timer);
    this.timer = setTimeout(() => {
      const next = this.queue.shift();
      if (next) this.display(next.text, next.ms, next.kind);
      else { this.text.set(''); this.kind.set('info'); this.action.set(null); }
    }, ms);
  }
}
