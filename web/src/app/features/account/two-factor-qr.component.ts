import { Component, input } from '@angular/core';

/**
 * AG-005 S7: the only place a QR code of the setup key may be drawn.
 * The QR library decision is pending with the product owner (spec Q1), so this renders nothing and the setup panel
 * (S3) works with the setup key alone. If a library is approved, it is imported here and nowhere else, and it must draw
 * an SVG in the browser (error correction M, at least 180 x 180 px, white quiet zone, alt "QR code for the setup key").
 * The otpauth:// URI holds the secret: it is never sent to any external service and the server never makes QR images.
 */
@Component({
  selector: 'app-two-factor-qr',
  standalone: true,
  template: ``,
})
export class TwoFactorQrComponent {
  /** The otpauth:// URI (P2 / M2). */
  uri = input<string>('');
}
