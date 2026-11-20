/**
 * Unit-spec helper (AG-094 AC 30): a 390 x 844 iframe that carries the page's styles, so CSS media queries and layout
 * are measured at phone width whatever size the test browser has. Move a rendered element into it with `adopt`;
 * Angular keeps updating the moved nodes.
 */
export interface PhoneFrame {
  win: Window;
  doc: Document;
  adopt(el: HTMLElement): void;
  remove(): void;
}

export async function phoneFrame(width = 390, height = 844): Promise<PhoneFrame> {
  const frame = document.createElement('iframe');
  frame.style.cssText = `position:absolute;left:0;top:0;border:0;width:${width}px;height:${height}px`;
  document.body.appendChild(frame);
  const doc = frame.contentDocument!;
  doc.open();
  doc.write('<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body></body></html>');
  doc.close();
  const loads: Promise<unknown>[] = [];
  document.querySelectorAll('style, link[rel="stylesheet"]').forEach(node => {
    const copy = node.cloneNode(true) as HTMLElement;
    if (copy instanceof HTMLLinkElement) {
      copy.href = (node as HTMLLinkElement).href;
      loads.push(new Promise(r => { copy.onload = r; copy.onerror = r; }));
    }
    doc.head.appendChild(copy);
  });
  await Promise.all(loads);
  return {
    win: frame.contentWindow!,
    doc,
    adopt: el => { doc.body.appendChild(el); },
    remove: () => frame.remove(),
  };
}
