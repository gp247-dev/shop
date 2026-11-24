<?php

namespace GP247\Shop\Privacy\Concerns;

use GP247\Core\PersonalData\Application\ErasureService;
use GP247\Core\PersonalData\Application\ErasureStepUp;
use GP247\Core\PersonalData\Application\Requests\PrivacyRequests;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Application\SubjectLookup;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Infrastructure\JobRepository;
use GP247\Core\PersonalData\Infrastructure\PersonalDataLanguage;
use GP247\Core\Support\StepUp\Concerns\ConfirmsStepUp;
use GP247\Core\Support\StepUp\StepUpGate;

/**
 * The Delete button of the admin Customers screen: instead of removing the account row only,
 * it opens a box that says what will be erased (orders kept for the accounts but made
 * anonymous, addresses, carts, sign-ins… deleted), refuses while an order is still in progress,
 * asks the administrator to confirm who they are, then runs the core erasure and shows its
 * progress. Same permission as before (the screen's delete right); the customer is looked up
 * again through the screen's own store-scoped query at every step, and only the job of that
 * customer can be driven from the box.
 *
 * No personal data is kept in the public (logged) properties: the admin operation log records
 * them with every action, so the customer's name is read on render and the edit form is
 * cleared when the box opens.
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-customer-privacy-admin-delete-complete
 * @aidlc-adr customer-privacy_admin-delete-flow, personal-data-registry_shared-step-up
 */
trait ErasesCustomers
{
    use ConfirmsStepUp;

    public bool $erasureOpen = false;
    public ?string $erasureCustomerId = null;
    /** @var list<array{label: string, rows: int, rule: string}> */
    public array $erasurePreview = [];
    /** @var list<array{reason: string, ref: string, label: string}> */
    public array $erasureBlockers = [];
    public ?string $erasureJobId = null;
    public string $erasureStatus = '';
    /** @var array{done: int, total: int} */
    public array $erasureProgress = ['done' => 0, 'total' => 0];

    /**
     * Opens the erasure box for one customer (replaces the plain row delete).
     *
     * @param int|string $id
     * @return void
     */
    public function delete($id): void
    {
        $this->authorizeAction('delete');
        $customer = $this->baseQuery()->find($id);
        if ($customer === null) {
            return;
        }
        if (!ErasureService::ready() || !RequestScheduler::ready()) {
            $this->notify('error', gp247_language_render('admin.personal_data.not_ready'));

            return;
        }
        $this->closeErasure();
        if ((string) $this->editingId === (string) $customer->id) {
            $this->resetForm();
            $this->syncEditUrl(null);
        }
        $subject = DataSubject::customer((string) $customer->id);
        $lookup = app(SubjectLookup::class);
        $this->erasurePreview = $this->previewRows($lookup->counts($lookup->expand($subject)));
        $this->erasureBlockers = app(ErasureService::class)->blockers($subject);
        $this->erasureCustomerId = (string) $customer->id;
        $this->erasureOpen = true;
    }

    /**
     * Confirms the erasure (after the step-up box when the proof is missing) and runs its
     * first slice.
     *
     * @return void
     */
    public function confirmErasure(): void
    {
        $this->authorizeAction('delete');
        $customer = $this->erasureCustomerId === null ? null : $this->baseQuery()->find($this->erasureCustomerId);
        if ($customer === null || $this->erasureJobId !== null) {
            return;
        }
        if (!$this->requireStepUp('confirmErasure')) {
            return;
        }
        $admin = function_exists('admin') ? admin()->user() : null;
        // Through a personal data request (source "admin", run at once), so the erasure is in the
        // request history like every other one (personal-data-requests_request-model).
        $result = app(PrivacyRequests::class)->adminErase(
            (string) $customer->id,
            true,
            (string) ($customer->store_id ?? $this->storeContext()),
            ['type' => 'admin', 'id' => $admin === null ? null : (string) $admin->getAuthIdentifier()],
        );
        if (isset($result['blockers']) && $result['blockers'] !== []) {
            $this->erasureBlockers = $result['blockers'];

            return;
        }
        $this->erasureJobId = isset($result['job_id']) ? (string) $result['job_id'] : null;
        $status = (string) ($result['status'] ?? '');
        if ($this->erasureJobId !== null && !in_array($status, [JobRepository::QUEUED, JobRepository::RUNNING, JobRepository::DONE, JobRepository::FAILED], true)) {
            $status = app(ErasureService::class)->status($this->erasureJobId);
        }
        $this->afterSlice($status);
    }

    /**
     * One more slice of the running erasure (polled by the box while it runs).
     *
     * @return void
     */
    public function tickErasure(): void
    {
        $this->authorizeAction('delete');
        if ($this->erasureJobId === null || $this->erasureCustomerId === null || !in_array($this->erasureStatus, JobRepository::ACTIVE, true)) {
            return;
        }
        $service = app(ErasureService::class);
        if (!$service->isJobFor($this->erasureJobId, DataSubject::customer($this->erasureCustomerId))) {
            $this->closeErasure();

            return;
        }
        $this->afterSlice($service->continue($this->erasureJobId));
    }

    /**
     * Closes the box. A running erasure goes on after later admin requests.
     *
     * @return void
     */
    public function closeErasure(): void
    {
        $this->reset(['erasureOpen', 'erasureCustomerId', 'erasurePreview', 'erasureBlockers', 'erasureJobId', 'erasureStatus', 'erasureProgress']);
    }

    /**
     * @return string For the box title: the customer's email or name (read now, never stored on
     *                the component).
     */
    public function erasureCustomerName(): string
    {
        $customer = $this->erasureCustomerId === null ? null : $this->baseQuery()->find($this->erasureCustomerId);
        if ($customer === null) {
            return '';
        }

        return trim((string) ($customer->email ?: $customer->first_name . ' ' . $customer->last_name));
    }

    /**
     * @return list<string>
     */
    protected function stepUpActions(): array
    {
        return ['confirmErasure'];
    }

    /**
     * @return StepUpGate
     */
    protected function stepUpGate(): StepUpGate
    {
        return ErasureStepUp::admin();
    }

    /**
     * @return int Validity of the step-up proof in minutes (for the box).
     */
    public function stepUpMinutes(): int
    {
        return intdiv(ErasureStepUp::SECONDS, 60);
    }

    /**
     * @return bool For the view: whether the step-up box asks for a second-step code.
     */
    public function stepUpAsksCode(): bool
    {
        return $this->stepUpOpen && $this->stepUpNeedsCode();
    }

    /**
     * @param string $status
     * @return void
     */
    private function afterSlice(string $status): void
    {
        $this->erasureStatus = $status;
        if ($this->erasureJobId !== null) {
            $this->erasureProgress = app(ErasureService::class)->progress($this->erasureJobId);
        }
        if ($status !== JobRepository::DONE) {
            return;
        }
        if ((string) $this->editingId === (string) $this->erasureCustomerId) {
            $this->resetForm();
            $this->syncEditUrl(null);
        }
        $this->resetPage();
        $this->notify('success', gp247_language_render('admin.personal_data.status.done'));
    }

    /**
     * @param list<array{table: string, label: string|array<string, string>, owner: string, rule: string, rows: int}> $counts
     * @return list<array{label: string, rows: int, rule: string}> Sources holding rows of the customer.
     */
    private function previewRows(array $counts): array
    {
        $out = [];
        foreach ($counts as $count) {
            if ($count['rows'] > 0 && $count['rule'] !== 'retain') {
                $out[] = ['label' => PersonalDataLanguage::label($count['label']), 'rows' => (int) $count['rows'], 'rule' => (string) $count['rule']];
            }
        }

        return $out;
    }
}
