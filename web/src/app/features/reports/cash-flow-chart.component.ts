import { AfterViewInit, Component, ElementRef, OnDestroy, computed, input, signal, viewChild } from '@angular/core';

/** One month of the cash flow response (amount strings, 2 decimals). */
export interface CashFlowMonth {
  month: string;
  opening: string; income: string; expenses: string; operating_net: string;
  capital: string; funds_received: string; transfers_in: string; transfers_out: string; funding_net: string;
  inflow: string; outflow: string; net: string; closing: string; eliminated: string;
}

const SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** "2026-10" -> "Oct 2026" */
export function monthLabel(ym: string): string {
  return `${SHORT[Number(ym.slice(5, 7)) - 1]} ${ym.slice(0, 4)}`;
}

/** "-1234567.5" -> "-1,234,567.50" (display only; the API sends exact 2-decimal strings). */
function money(v: string): string {
  const neg = v.startsWith('-');
  const [i, d = '00'] = v.replace('-', '').split('.');
  return `${neg ? '-' : ''}${i.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${(d + '00').slice(0, 2)}`;
}

/** Axis label: 1,250,000 -> "1.25M", 30,000 -> "30k". */
function compact(n: number, step: number): string {
  const a = Math.abs(n);
  // Review m2: below 1.00 per step (e.g. a few cents) show decimals, so ticks do not read "0, 0, 1".
  const s = a >= 1e6 ? `${+(a / 1e6).toFixed(2)}M` : a >= 1e3 ? `${+(a / 1e3).toFixed(1)}k` : step < 1 ? a.toFixed(2) : `${+a.toFixed(0)}`;
  return (n < 0 && a > 0 ? '-' : '') + s;
}

interface Scale { lo: number; hi: number; step: number; ticks: number[] }

/** A round step giving about 4 intervals over `span`. */
function niceStep(span: number): number {
  const raw = span / 4;
  const mag = 10 ** Math.floor(Math.log10(raw));
  return [1, 2, 2.5, 5, 10].map(f => f * mag).find(s => s >= raw) ?? 10 * mag;
}

function tickList(lo: number, hi: number, step: number): number[] {
  const ticks: number[] = [];
  for (let t = Math.ceil(lo / step - 1e-9) * step; t <= hi + 1e-9 * step; t += step) ticks.push(+t.toFixed(6));
  return ticks;
}

/**
 * Bar panel: domain and 3-5 round ticks covering [lo, hi], always including 0 (bars start at zero). A small negative
 * value (a reversal) gets a little room below zero instead of a whole empty tick band. All zero: the zero line only.
 */
function barScale(lo: number, hi: number): Scale {
  lo = Math.min(0, lo);
  hi = Math.max(0, hi);
  if (lo === hi) return { lo: -1, hi: 1, step: 1, ticks: [0] };
  const step = niceStep(hi - lo);
  const top = Math.ceil(hi / step - 1e-9) * step;
  const floor = Math.floor(lo / step + 1e-9) * step;
  const bottom = lo < 0 ? Math.max(floor, lo - 0.08 * (top - lo)) : 0;
  return { lo: bottom, hi: top, step, ticks: tickList(bottom, top, step) };
}

/** Line panel (review m3): fitted to the closing balances with some padding; zero only when the data reach it. */
function lineScale(lo: number, hi: number): Scale {
  if (lo === 0 && hi === 0) return { lo: 0, hi: 1, step: 1, ticks: [0] }; // no balance yet: the zero line only
  const pad = hi > lo ? (hi - lo) * 0.15 : Math.max(Math.abs(hi) * 0.05, 1);
  let a = lo - pad, b = hi + pad;
  if (lo >= 0 && a < 0) a = 0; // never invent a negative balance on the axis
  if (hi <= 0 && b > 0) b = 0;
  const step = niceStep(b - a);
  a = Math.floor(a / step + 1e-9) * step;
  b = Math.ceil(b / step - 1e-9) * step;
  return { lo: a, hi: b, step, ticks: tickList(a, b, step) };
}

interface Bar { x: number; y: number; w: number; h: number; cls: string; title: string }
interface Pt { x: number; y: number; title: string }

/**
 * AG-086 chart (spec section 4): two panels on one month axis, no dual y-axis. Top: Inflow / Outflow bars with a Net
 * marker (negative below the zero line). Bottom: the cumulative Closing balance. Inline SVG drawn at the container's
 * pixel width (ResizeObserver), so text stays legible on phones; month labels thin out when narrow. Colours come from
 * CSS tokens; every mark has a <title> tooltip; the table under the chart is the accessible source.
 */
@Component({
  selector: 'ag-cash-flow-chart',
  standalone: true,
  template: `
    <div class="cf-chart" #box>
      <div class="cf-legend" aria-hidden="true">
        <span><i class="cf-sw in"></i>Inflow</span><span><i class="cf-sw out"></i>Outflow</span>
        <span><i class="cf-sw net"></i>Net change</span><span><i class="cf-sw close"></i>Closing balance</span>
      </div>
      @if (g(); as g) {
        <svg [attr.viewBox]="'0 0 ' + g.w + ' ' + g.h" [attr.width]="g.w" [attr.height]="g.h" role="img" [attr.aria-label]="label()">
          <!-- top panel: grid + axis -->
          @for (t of g.topTicks; track t.v) {
            <line class="cf-grid" [class.cf-zero]="t.v === 0" [attr.x1]="g.left" [attr.x2]="g.w - g.right" [attr.y1]="t.y" [attr.y2]="t.y" />
            <text class="cf-axis" [attr.x]="g.left - 6" [attr.y]="t.y + 4" text-anchor="end">{{ t.label }}</text>
          }
          @for (b of g.bars; track $index) {
            <rect [attr.class]="b.cls" [attr.x]="b.x" [attr.y]="b.y" [attr.width]="b.w" [attr.height]="b.h" rx="2"><title>{{ b.title }}</title></rect>
          }
          @for (p of g.nets; track $index) {
            <rect class="cf-net" [attr.x]="p.x - 5" [attr.y]="p.y - 5" width="10" height="10" [attr.transform]="'rotate(45 ' + p.x + ' ' + p.y + ')'"><title>{{ p.title }}</title></rect>
          }
          <!-- bottom panel: closing balance -->
          <text class="cf-axis cf-panel" [attr.x]="g.left" [attr.y]="g.bottomTop - 8">Closing balance</text>
          @for (t of g.botTicks; track t.v) {
            <line class="cf-grid" [class.cf-zero]="t.v === 0" [attr.x1]="g.left" [attr.x2]="g.w - g.right" [attr.y1]="t.y" [attr.y2]="t.y" />
            <text class="cf-axis" [attr.x]="g.left - 6" [attr.y]="t.y + 4" text-anchor="end">{{ t.label }}</text>
          }
          <polyline class="cf-line" [attr.points]="g.line" />
          @for (p of g.points; track $index) {
            <circle class="cf-pt" [attr.cx]="p.x" [attr.cy]="p.y" r="4"><title>{{ p.title }}</title></circle>
          }
          @for (m of g.labels; track m.x) {
            <text class="cf-axis" [attr.x]="m.x" [attr.y]="g.h - 6" text-anchor="middle">{{ m.text }}</text>
          }
        </svg>
      }
    </div>
  `,
  styles: [`
    :host { display: block; }
    .cf-chart {
      --cf-in: var(--pos); --cf-out: var(--gold-2); --cf-net: var(--ink); --cf-close: var(--gold);
      --cf-grid: var(--line); --cf-text: var(--muted); --cf-surface: var(--card);
      width: 100%; min-width: 0; overflow: hidden;
    }
    svg { display: block; max-width: 100%; height: auto; }
    .cf-legend { display: flex; flex-wrap: wrap; gap: 4px 14px; color: var(--cf-text); font-size: 12px; margin-bottom: 6px; }
    .cf-sw { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 5px; vertical-align: -1px; }
    .cf-sw.in { background: var(--cf-in); } .cf-sw.out { background: var(--cf-out); }
    .cf-sw.net { background: var(--cf-net); transform: rotate(45deg) scale(.8); border-radius: 0; }
    .cf-sw.close { background: var(--cf-close); height: 3px; vertical-align: 3px; border-radius: 2px; }
    .cf-grid { stroke: var(--cf-grid); stroke-width: 1; }
    .cf-zero { stroke: var(--cf-text); }
    .cf-axis { fill: var(--cf-text); font-size: 11px; font-variant-numeric: tabular-nums; }
    .cf-panel { font-weight: 600; }
    .cf-in { fill: var(--cf-in); } .cf-out { fill: var(--cf-out); }
    .cf-net { fill: var(--cf-net); stroke: var(--cf-surface); stroke-width: 2; }
    .cf-line { fill: none; stroke: var(--cf-close); stroke-width: 2; stroke-linejoin: round; }
    .cf-pt { fill: var(--cf-close); stroke: var(--cf-surface); stroke-width: 2; }
    rect:hover, circle:hover { opacity: .8; }
    @media (forced-colors: active) { .cf-in, .cf-out, .cf-net, .cf-pt { fill: CanvasText; } .cf-line { stroke: CanvasText; } }
  `],
})
export class CashFlowChartComponent implements AfterViewInit, OnDestroy {
  months = input.required<CashFlowMonth[]>();
  private box = viewChild.required<ElementRef<HTMLElement>>('box');
  private width = signal(0);
  private observer?: ResizeObserver;

  label = computed(() => {
    const m = this.months();
    if (!m.length) return 'Cash flow chart';
    return `Cash flow chart, ${monthLabel(m[0].month)} to ${monthLabel(m[m.length - 1].month)}: inflow, outflow and net change per month, `
      + `and the closing balance at each month end (${m.map(x => `${monthLabel(x.month)} ${money(x.closing)}`).join(', ')}). The table below lists every figure.`;
  });

  ngAfterViewInit() {
    const el = this.box().nativeElement;
    this.width.set(Math.floor(el.clientWidth));
    if (typeof ResizeObserver !== 'undefined') {
      this.observer = new ResizeObserver(e => this.width.set(Math.floor(e[0].contentRect.width)));
      this.observer.observe(el);
    }
  }

  ngOnDestroy() { this.observer?.disconnect(); }

  g = computed(() => {
    const months = this.months();
    const w = Math.max(this.width(), 260);
    if (!months.length || !this.width()) return null;
    const left = 52, right = 8, topH = 170, gap = 34, botH = 100, axisH = 22;
    const top = 8, bottomTop = top + topH + gap, h = bottomTop + botH + axisH;
    const plotW = w - left - right;
    const slot = plotW / months.length;
    const cx = (i: number) => left + slot * (i + 0.5);

    // Top panel scale: bars and net markers.
    const vals = months.flatMap(m => [+m.inflow, +m.outflow, +m.net]);
    const ts = barScale(Math.min(...vals), Math.max(...vals));
    const ty = (v: number) => top + topH - ((v - ts.lo) / (ts.hi - ts.lo)) * topH;
    const bw = Math.max(2, Math.min(18, (slot - 8) / 2));
    const bars: Bar[] = [];
    const nets: Pt[] = [];
    months.forEach((m, i) => {
      const name = monthLabel(m.month);
      const bar = (v: string, x: number, cls: string, series: string, note = '') => {
        const y0 = ty(0), y1 = ty(+v);
        // A non-zero amount is at least 2 px tall, on its own side of the zero line.
        const hgt = +v !== 0 ? Math.max(Math.abs(y1 - y0), 2) : 0;
        bars.push({ x, y: +v < 0 ? y0 : y0 - hgt, w: bw, h: hgt, cls, title: `${name} - ${series} ${money(v)}${note}` });
      };
      bar(m.inflow, cx(i) - bw - 1, 'cf-in', 'Inflow', +m.inflow < 0 ? ' (includes reversals)' : '');
      bar(m.outflow, cx(i) + 1, 'cf-out', 'Outflow', +m.outflow < 0 ? ' (includes reversals)' : '');
      // A tiny non-zero net (e.g. -4,000 on a 1M scale) sits visibly on its side of the zero line (min 7 px).
      const n = +m.net, y0 = ty(0);
      const ny = n === 0 ? y0 : n < 0 ? Math.max(ty(n), y0 + 7) : Math.min(ty(n), y0 - 7);
      nets.push({ x: cx(i), y: ny, title: `${name} - Net change ${money(m.net)}` });
    });

    // Bottom panel scale: closing balance.
    const closes = months.map(m => +m.closing);
    const bs = lineScale(Math.min(...closes), Math.max(...closes));
    const by = (v: number) => bottomTop + botH - ((v - bs.lo) / (bs.hi - bs.lo)) * botH;
    const points: Pt[] = months.map((m, i) => ({ x: cx(i), y: by(+m.closing), title: `${monthLabel(m.month)} - Closing balance ${money(m.closing)}` }));

    // Month labels: every 1st, 2nd, 3rd... month so each has about 52 px.
    const every = Math.max(1, Math.ceil(52 / slot));
    const labels = months.map((m, i) => ({ x: cx(i), text: `${SHORT[Number(m.month.slice(5, 7)) - 1]} ${m.month.slice(2, 4)}`, i }))
      .filter(l => l.i % every === 0);

    return {
      w, h, left, right, bottomTop, bars, nets, points, labels,
      topTicks: ts.ticks.map(v => ({ v, y: ty(v), label: compact(v, ts.step) })),
      botTicks: bs.ticks.map(v => ({ v, y: by(v), label: compact(v, bs.step) })),
      line: points.map(p => `${p.x},${p.y}`).join(' '),
    };
  });
}
