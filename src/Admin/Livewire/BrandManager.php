<?php

namespace GP247\Shop\Admin\Livewire;

use GP247\Core\AdminShell\Infrastructure\ResourcePanel;
use GP247\Core\AdminShell\Infrastructure\HasValidationLabels;
use GP247\Front\Admin\Livewire\Concerns\HasSeoPanel;
use GP247\Shop\Models\ShopBrand;
use GP247\Shop\Services\Catalog\BrandWriter;
use GP247\Shop\Services\Catalog\WriteContext;

/**
 * Brand manager (shop-admin Unit) — two-panel screen (add/edit form left, list
 * right) on the shared core ResourcePanel base, matching the legacy
 * AdminBrandController layout (rule ui-tailadmin P1). Image (LFM), name, alias
 * (auto from name, unique), url, sort, status. Domain unchanged (ShopBrand).
 * Gated by `admin_brand`.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-002
 * @aidlc-adr ADR-001, ADR-005, ADR-006, ADR-007
 */
class BrandManager extends ResourcePanel
{
    use HasValidationLabels;
    use HasSeoPanel;

    protected ?string $permission = 'admin_brand';

    /**
     * Keep list state (page/keyword/sort) and the edited record on screen when
     * editing/saving, instead of remounting via route navigation.
     *
     * @var bool
     * @aidlc-story US-AUI-two-panel-state-preservation
     * @aidlc-adr ADR-admin-shell-rbac-two-panel-state-preservation
     */
    protected bool $keepStateOnSave = true;

    /**
     * Store-scoped: pick a store on create (root admin), show it in the list, lock
     * it on edit. Brand is a leaf entity (no cross-store related fields to reset).
     *
     * @return array<string, mixed>|null
     *
     * @aidlc-unit shop-admin
     * @aidlc-story US-SADM-store-content-assignment
     * @aidlc-adr admin-shell_store-scoped-resource-panel
     */
    protected function storeScoped(): ?array
    {
        return ['display' => 'name', 'reset' => []];
    }

    /**
     * Store-scoped brand query: root admin shows every store's brands (each row
     * labelled by its store); a scoped context (store-admin/switcher) or a
     * single-store install filters to the own store.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function baseQuery()
    {
        $query = ShopBrand::query();
        if (!($this->storeScopeActive() && $this->isRootScope())) {
            $query->where('store_id', $this->storeContext());
        }

        return $query;
    }

    /**
     * @return array<int, string>
     */
    protected function searchable(): array
    {
        return ['name'];
    }

    /**
     * Language keys naming the searched columns (shown in the search box).
     *
     * @return array<string, string>
     *
     * @aidlc-story US-admin-shell-list-search-scope-hint
     */
    protected function searchLabels(): array
    {
        return [
            'name' => 'admin.brand.name',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function sortableColumns(): array
    {
        return ['name', 'sort', 'status'];
    }

    /**
     * @return string
     */
    protected function panelView(): string
    {
        return 'gp247-shop-admin::brand-manager';
    }

    /**
     * @return string
     */
    protected function pageTitle(): string
    {
        return gp247_language_render('admin.brand.title');
    }

    /**
     * @return string
     */
    protected function baseRoute(): string
    {
        return 'admin_brand.index';
    }

    /**
     * @return array<string, mixed>
     */
    protected function formDefaults(): array
    {
        return ['image' => '', 'name' => '', 'alias' => '', 'url' => '', 'sort' => 0, 'status' => 1];
    }

    /**
     * Reset to create mode, SEO panel included.
     *
     * @return void
     */
    public function resetForm(): void
    {
        parent::resetForm();
        $this->fillSeo(null);
    }

    /**
     * @param ShopBrand $model
     * @return array<string, mixed>
     */
    protected function fillForm($model): array
    {
        // Store is immutable on edit — expose it for the read-only display.
        $this->formStoreId = (string) $model->store_id;
        $this->fillSeo($model->id);

        return [
            'image' => (string) $model->image,
            'name' => (string) $model->name,
            'alias' => (string) $model->alias,
            'url' => (string) $model->url,
            'sort' => (int) $model->sort,
            'status' => (int) $model->status,
        ];
    }

    /**
     * The form rules, owned by the shared write service (US-SADM-catalog-write-service).
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return array_merge((new BrandWriter)->rules($this->editingId), $this->seoRules());
    }

    /**
     * Reuse the existing v1 brand label keys.
     *
     * @return array<string, string>
     */
    protected function attributeLabels(): array
    {
        return (new BrandWriter)->labelKeys() + $this->seoAttributeLabels();
    }

    /**
     * Persist through the shared write service; the form was validated by save() with the
     * service's own rules.
     *
     * @param array<string, mixed> $data Sanitised form.
     * @return void
     *
     * @aidlc-story US-SADM-catalog-write-service
     * @aidlc-adr shop-admin_catalog-write-service
     */
    protected function persist(array $data): void
    {
        $this->persistWithSeo(fn () => $this->persistContent($data));
    }

    /**
     * SEO content-type key of this screen (US-SEO-content-meta).
     *
     * @return string
     */
    protected function seoType(): string
    {
        return 'brand';
    }

    /**
     * Save the record itself; the SEO panel is saved right after, in the same
     * transaction (persistWithSeo, ADR-seo-content-meta-table).
     *
     * @param array<string, mixed> $data Sanitised form.
     * @return void
     */
    private function persistContent(array $data): void
    {
        $editing = $this->editingId !== null && $this->editingId !== '';
        // Store is immutable on edit (ADR 1-1): the record's own store, read from the DB.
        // WHY baseQuery(): only a brand of the current store scope may be written; one
        // outside it (another store) fails closed with a 404 instead of lending its own
        // store to the WriteContext, which would let the writer's same-store check pass.
        $store = $editing
            ? (string) $this->baseQuery()->whereKey($this->editingId)->firstOrFail()->store_id
            : (string) $this->resolveCreateStore();
        $record = (new BrandWriter)->save(
            new WriteContext($store !== '' ? $store : (string) $this->storeContext()),
            ['form' => $data],
            $editing ? $this->editingId : null,
            ['validated' => true, 'clean' => false]
        );
        // ResourcePanel create contract: expose the id so save() edits it in place.
        $this->editingId = (string) $record->id;
    }

    /**
     * @param int|string $id
     * @return void
     */
    protected function deleteModel($id): void
    {
        $model = $this->baseQuery()->find($id);
        if ($model !== null) {
            $model->delete();
        }
    }

    /**
     * Derive alias from name (brownfield parity) before the secure save pipeline.
     *
     * @return void
     */
    public function save(): void
    {
        $this->form['alias'] = BrandWriter::alias((string) ($this->form['alias'] ?? ''), (string) ($this->form['name'] ?? ''));

        parent::save();
    }
}
