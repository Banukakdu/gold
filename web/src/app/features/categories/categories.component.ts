import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { NgTemplateOutlet } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Observable } from 'rxjs';
import { ActivatedRoute, Router } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { SettingsService } from '../../core/settings.service';
import { MoneyPipe } from '../../core/money.pipe';
import { errorText } from '../../core/api.interceptor';

type CatType = 'income' | 'expense';

/** A node of GET /categories?manage=1 (categories spec section 5). */
export interface CategoryNode {
  id: number; name: string; type: CatType; parent_id: number | null; branch_id: number | null;
  branch: { id: number; code: string } | null; ledger_code: string | null; is_active: boolean; effective_active: boolean;
  usage_count: number; year_balance: string; can_edit: boolean; can_promote: boolean; children?: CategoryNode[];
}
interface ManageMeta {
  fiscal_year: { label: string; from: string; to: string };
  locked_through: { period: string; label: string } | null;
  closed_year: string | null;
}
type DialogKind = 'add' | 'addSub' | 'edit' | 'deactivate' | 'promote';
interface Dialog { kind: DialogKind; node?: CategoryNode; parent?: CategoryNode }

/** Categories spec S1 (AG-070/071/072): the category editor, route /categories. */
@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe, NgTemplateOutlet],
  template: `
    <div class="cat-head">
      <h1>Categories</h1>
      @if (auth.isSuperAdmin()) { <button type="button" class="cat-add" (click)="open({ kind: 'add' })">Add category</button> }
    </div>
    <div class="cat-bar">
      <div class="cat-tabs" role="tablist">
        <button type="button" role="tab" [class.ghost]="type() !== 'income'" [attr.aria-selected]="type() === 'income'" (click)="setType('income')">Income</button>
        <button type="button" role="tab" [class.ghost]="type() !== 'expense'" [attr.aria-selected]="type() === 'expense'" (click)="setType('expense')">Expense</button>
      </div>
      <label class="cat-check"><input type="checkbox" [ngModel]="showInactive()" (ngModelChange)="showInactive.set($event)" /> Show inactive</label>
      @if (auth.isAllBranches()) {
        <!-- Accounting review finding 10: review branch-only sub-categories (promote, or ask the branch to reclassify). -->
        <label class="cat-check"><input type="checkbox" [ngModel]="branchOnly()" (ngModelChange)="branchOnly.set($event)" /> Branch-only</label>
      }
    </div>

    @if (!auth.isSuperAdmin()) {
      <p class="muted cat-note">🔒 Global categories are managed by the Super Admin. Sub-categories you add are for this branch only.</p>
    }
    @if (msg()) { <div class="alert">{{ msg() }}</div> }
    @if (ok()) { <div class="ok">{{ ok() }}</div> }
    @if (loading() && !nodes().length) { <p class="muted">Loading...</p> }

    @if (!loading() || nodes().length) {
      @for (p of visible(); track p.id) {
        <div class="card cat-card" [class.cat-off]="!p.is_active">
          <ng-container *ngTemplateOutlet="row; context: { $implicit: p, parent: null }" />
          @for (s of p.children; track s.id) {
            <ng-container *ngTemplateOutlet="row; context: { $implicit: s, parent: p }" />
          }
        </div>
      } @empty {
        @if (!loading()) {
          <div class="card">
            @if (branchOnly()) { <p class="muted">No branch-only {{ type() }} sub-categories.</p> }
            @else if (nodes().length) { <p class="muted">All {{ type() }} categories are inactive. Tick "Show inactive" to see them.</p> }
            @else {
              <p class="muted">No {{ type() }} categories yet.</p>
              @if (auth.isSuperAdmin()) { <button type="button" (click)="open({ kind: 'add' })">Add category</button> }
              @else { <p class="muted">Ask a Super Admin to add categories.</p> }
            }
          </div>
        }
      }
    }

    <ng-template #row let-n let-parent="parent">
      <div class="cat-row" [class.cat-sub]="!!parent" [class.cat-off]="!n.is_active">
        <div class="cat-name">
          @if (parent) { <span>{{ n.name }}</span> } @else { <b>{{ n.name }}</b> }
          @if (n.branch) { <span class="tag">{{ auth.isAllBranches() ? n.branch.code + ' only' : 'This branch only' }}</span> }
          @if (!n.is_active) { <span class="tag" data-s="inactive">Inactive</span> }
          @if (parent && n.is_active && !parent.is_active) { <span class="tag" data-s="inactive">Hidden: parent inactive</span> }
          @if (!n.can_edit) { <span class="cat-lock" title="Managed by Super Admin" aria-label="Managed by Super Admin" role="img">🔒</span> }
        </div>
        <div class="cat-meta">
          <span class="mono">{{ n.ledger_code }}</span>
          <span>{{ n.usage_count ? 'Used in ' + n.usage_count : 'Not used' }}</span>
        </div>
        @if (actions(n, parent); as acts) {
          @if (acts.length) {
            <div class="cat-acts">
              @for (a of acts; track a.key) { <button type="button" class="ghost" [disabled]="busy()" (click)="run(a.key, n)">{{ a.label }}</button> }
            </div>
            <div class="cat-more">
              <button type="button" class="ghost" [attr.aria-expanded]="menu() === n.id" [attr.aria-label]="'Actions for ' + n.name"
                (click)="menu.set(menu() === n.id ? null : n.id)">⋯</button>
              @if (menu() === n.id) {
                <div class="cat-menu" role="menu">
                  @for (a of acts; track a.key) { <button type="button" role="menuitem" [disabled]="busy()" (click)="run(a.key, n)">{{ a.label }}</button> }
                </div>
              }
            </div>
          }
        }
      </div>
    </ng-template>

    @if (dialog(); as d) {
      <div class="att-backdrop" (click)="close()"></div>
      <form class="card att-dialog cat-dialog" role="dialog" aria-modal="true" aria-labelledby="cat-dlg-title" (ngSubmit)="submit()">
        <h2 id="cat-dlg-title">{{ title(d) }}</h2>
        @switch (d.kind) {
          @case ('add') {
            <label>Name <input name="name" [(ngModel)]="name" maxlength="100" required /></label>
            <p class="muted">Type: {{ type() === 'income' ? 'Income' : 'Expense' }}</p>
          }
          @case ('addSub') {
            <p class="muted">Category: {{ d.parent!.name }}</p>
            <label>Name <input name="name" [(ngModel)]="name" maxlength="100" required /></label>
            <p class="muted">Scope: {{ auth.isSuperAdmin() ? 'All branches' : 'This branch only (' + (auth.activeBranch()?.code ?? '') + ')' }}</p>
          }
          @case ('edit') {
            <label>Name <input name="name" [(ngModel)]="name" maxlength="100" required /></label>
            <p class="muted">Type, parent and code cannot be changed. To move entries, create the right category and change the category on each entry.</p>
            <!-- Accounting review finding 7: a rename relabels every period. -->
            @if (usage(d.node!)) {
              <div class="warn">Used in {{ usage(d.node!) }} entries. Reports for all periods, including locked months and closed years, will show the new name.
                @if (meta()?.locked_through; as l) { This includes locked months up to {{ l.label }}. }
                @if (meta()?.closed_year; as y) { This includes closed {{ y }}. }
                Rename only to correct or clarify the name. If the category now means something different, create a new category and deactivate this one.</div>
            }
          }
          @case ('deactivate') {
            <p>Deactivate '{{ d.node!.name }}'? It will be hidden from entry forms. Past entries and reports keep it.
              @if (activeSubs(d.node!); as n) { {{ n }} active sub-categor{{ n === 1 ? 'y' : 'ies' }} will also be hidden. }
              {{ usage(d.node!) ? 'Used in ' + usage(d.node!) + ' entries.' : 'Not used.' }}</p>
            <!-- Accounting review finding 9: deactivating does not hide a figure. -->
            @if (+d.node!.year_balance !== 0) {
              <div class="warn">{{ settings.currency() }} {{ d.node!.year_balance | money }} this year ({{ meta()?.fiscal_year?.label }}). Its balance is still closed at year end.</div>
            }
          }
          @case ('promote') {
            <p>'{{ d.node!.name }}' ({{ d.node!.branch?.code }} only, code {{ d.node!.ledger_code }}) will be available in every branch with the same code.
              Its past entries stay in {{ d.node!.branch?.code }}.</p>
            <p class="muted">This cannot be undone.</p>
          }
        }
        @if (dlgMsg()) { <div class="alert">{{ dlgMsg() }}</div> }
        <div class="att-dialog-actions">
          <button type="button" class="ghost" (click)="close()">Cancel</button>
          <button type="submit" [disabled]="busy() || (needsName(d) && !name.trim())">{{ d.kind === 'deactivate' || d.kind === 'promote' ? 'Confirm' : 'Save' }}</button>
        </div>
      </form>
    }
  `,
  styles: [`
    .cat-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
    .cat-head h1 { margin: 0 0 8px; }
    .cat-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
    .cat-tabs { display: inline-flex; gap: 6px; }
    .cat-check { display: inline-flex; align-items: center; gap: 6px; color: var(--muted); input { width: auto; } }
    .cat-card { margin-bottom: 12px; padding: 4px 16px; overflow: visible; }
    .cat-row { display: grid; grid-template-columns: minmax(0, 1fr) 70px 110px 330px; gap: 8px; align-items: center; padding: 8px 0;
      border-bottom: 1px solid var(--line); }
    .cat-row:last-child { border-bottom: 0; }
    .cat-sub .cat-name { padding-left: 24px; }
    .cat-row.cat-off { opacity: .7; }
    .cat-name { min-width: 0; overflow-wrap: anywhere; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    .cat-meta { display: contents; color: var(--muted); }
    .cat-acts { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; button { padding: 4px 10px; } }
    .cat-lock { font-size: 11px; opacity: .7; }
    .cat-note { margin: -4px 0 12px; }
    .cat-more { display: none; position: relative; }
    .cat-menu { position: absolute; right: 0; top: 100%; z-index: 5; background: #fff; border: 1px solid var(--line); border-radius: 8px;
      box-shadow: 0 4px 16px #0002; display: flex; flex-direction: column; min-width: 180px; padding: 4px; }
    .cat-menu button { background: none; color: var(--ink); text-align: left; min-height: 44px; border-radius: 4px; }
    .cat-menu button:hover { background: #f6f1e4; }
    .cat-dialog { top: 15%; }
    @media (max-width: 760px) {
      .cat-head .cat-add { width: 100%; min-height: 44px; }
      .cat-row { grid-template-columns: minmax(0, 1fr) auto; gap: 2px 8px; }
      .cat-name { grid-column: 1; }
      .cat-meta { display: flex; gap: 0 6px; grid-column: 1; grid-row: 2; font-size: 12px; }
      .cat-meta > span + span::before { content: '· '; }
      .cat-sub .cat-meta { padding-left: 24px; }
      .cat-acts { display: none; }
      .cat-more { display: block; grid-column: 2; grid-row: 1 / span 2; }
      .cat-more > button { min-width: 44px; min-height: 44px; font-size: 18px; padding: 0; }
      .cat-dialog { top: auto; bottom: 0; left: 0; right: 0; transform: none; width: 100%; max-height: 90vh; overflow-y: auto;
        border-radius: 12px 12px 0 0; }
    }
  `],
})
export class CategoriesComponent implements OnInit {
  auth = inject(AuthService);
  settings = inject(SettingsService);
  private http = inject(HttpClient);
  private router = inject(Router);
  private route = inject(ActivatedRoute);

  type = signal<CatType>('income');
  showInactive = signal(true);
  branchOnly = signal(false);
  nodes = signal<CategoryNode[]>([]);
  meta = signal<ManageMeta | null>(null);
  loading = signal(false);
  busy = signal(false);
  msg = signal('');
  ok = signal('');
  menu = signal<number | null>(null);
  dialog = signal<Dialog | null>(null);
  dlgMsg = signal('');
  name = '';

  /** The tree after the "Show inactive" and "Branch-only" filters (S1, review finding 10). */
  visible = computed(() => {
    const inactive = this.showInactive();
    const branchOnly = this.branchOnly() && this.auth.isAllBranches();
    return this.nodes()
      .filter(p => inactive || p.is_active)
      .map(p => ({ ...p, children: (p.children ?? []).filter(s => (inactive || s.is_active) && (!branchOnly || !!s.branch)) }))
      .filter(p => !branchOnly || p.children.length > 0);
  });

  ngOnInit() {
    const t = this.route.snapshot.queryParamMap.get('type');
    if (t === 'income' || t === 'expense') this.type.set(t);
    this.load();
  }

  setType(t: CatType) {
    if (t === this.type()) return;
    this.type.set(t);
    this.menu.set(null);
    this.router.navigate([], { relativeTo: this.route, queryParams: { type: t }, replaceUrl: true });
    this.load();
  }

  load() {
    this.loading.set(true);
    this.http.get<{ data: CategoryNode[]; meta: ManageMeta }>('/api/v1/categories', { params: { manage: 1, type: this.type() } }).subscribe({
      next: r => { this.nodes.set(r.data); this.meta.set(r.meta); this.msg.set(''); this.loading.set(false); },
      // Error state: the API message in the page alert, list unchanged.
      error: e => { this.msg.set(errorText(e)); this.loading.set(false); },
    });
  }

  /** Actions this user may perform on a row; the API decides can_edit / can_promote (R1). */
  actions(n: CategoryNode, parent: CategoryNode | null): { key: string; label: string }[] {
    const out: { key: string; label: string }[] = [];
    if (!parent && n.is_active) out.push({ key: 'addSub', label: 'Add sub-category' });
    if (n.can_edit) {
      out.push({ key: 'edit', label: 'Edit' });
      out.push(n.is_active ? { key: 'deactivate', label: 'Deactivate' } : { key: 'reactivate', label: 'Reactivate' });
    }
    if (n.can_promote && n.branch) out.push({ key: 'promote', label: 'Make global' });
    return out;
  }

  run(key: string, n: CategoryNode) {
    this.menu.set(null);
    if (key === 'reactivate') return this.save(this.http.put(`/api/v1/categories/${n.id}`, { is_active: true }));
    if (key === 'addSub') return this.open({ kind: 'addSub', parent: n });
    this.open({ kind: key as DialogKind, node: n });
  }

  open(d: Dialog) {
    this.menu.set(null);
    this.ok.set('');
    this.dlgMsg.set('');
    this.name = d.kind === 'edit' ? d.node!.name : '';
    this.dialog.set(d);
  }

  close() {
    if (this.busy()) return;
    this.dialog.set(null);
  }

  title(d: Dialog): string {
    return { add: 'Add category', addSub: 'Add sub-category', edit: 'Edit category', deactivate: 'Deactivate', promote: 'Make global' }[d.kind];
  }

  needsName(d: Dialog) { return d.kind === 'add' || d.kind === 'addSub' || d.kind === 'edit'; }

  usage(n: CategoryNode) { return n.usage_count; }

  activeSubs(n: CategoryNode): number { return (n.children ?? []).filter(s => s.is_active).length; }

  submit() {
    const d = this.dialog();
    if (!d || this.busy()) return; // double-submit guard: a second click can fire before the button disables
    const name = this.name.trim();
    if (this.needsName(d) && !name) return;
    const req = {
      add: () => this.http.post('/api/v1/categories', { name, type: this.type() }),
      addSub: () => this.http.post('/api/v1/categories', { name, parent_id: d.parent!.id }),
      edit: () => this.http.put(`/api/v1/categories/${d.node!.id}`, { name }),
      deactivate: () => this.http.put(`/api/v1/categories/${d.node!.id}`, { is_active: false }),
      promote: () => this.http.post(`/api/v1/categories/${d.node!.id}/promote`, {}),
    }[d.kind]();
    this.save(req, true);
  }

  private save(req: Observable<unknown>, inDialog = false) {
    if (this.busy()) return;
    this.busy.set(true);
    this.msg.set('');
    this.ok.set('');
    req.subscribe({
      next: () => {
        this.busy.set(false);
        this.dialog.set(null);
        this.ok.set('Saved.');
        this.load();
      },
      error: e => {
        this.busy.set(false);
        (inDialog ? this.dlgMsg : this.msg).set(errorText(e));
      },
    });
  }
}
