import { Component, ElementRef, effect, input, signal, viewChild } from '@angular/core';
import { toCanvas } from 'qrcode';

/**
 * AG-005 S7: the only place a QR code of the setup key is drawn, and the only place the QR library (`qrcode`, approved
 * by the product owner, spec Q1) is imported. It draws on a canvas in the browser: error correction M, 200 x 200 px,
 * white quiet zone of 4 modules. No network call is made and nothing is logged: the otpauth:// URI holds the secret,
 * so it is never sent to any external service and the server never makes QR images. The setup panel (S3) always shows
 * the setup key as well, and works with the key alone if drawing fails.
 */
@Component({
  selector: 'app-two-factor-qr',
  standalone: true,
  template: `
    @if (uri() && !failed()) {
      <canvas #qr class="tf-qr" width="200" height="200" role="img" aria-label="QR code for the setup key"></canvas>
    }
  `,
})
export class TwoFactorQrComponent {
  /** The otpauth:// URI (P2 / M2). */
  uri = input<string>('');
  failed = signal(false);
  private canvas = viewChild<ElementRef<HTMLCanvasElement>>('qr');

  constructor() {
    effect(() => {
      const uri = this.uri();
      const el = this.canvas()?.nativeElement;
      if (!uri || !el) return;
      toCanvas(el, uri, { errorCorrectionLevel: 'M', margin: 4, width: 200, color: { dark: '#000000', light: '#ffffff' } })
        .catch(() => this.failed.set(true)); // no details: the error could quote the URI
    });
  }
}
