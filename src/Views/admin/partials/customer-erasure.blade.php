{{--
    Erasure box of the Customers screen (US-customer-privacy-admin-delete-complete), driven by the
    ErasesCustomers trait: what will be erased, the orders that block it, then the progress. The
    "confirm it is you" box (shared, core) opens on top when the confirmation is missing.
    Same overlay classes as <x-gp247::modal> (prebuilt bundle).

    @aidlc-unit customer-privacy
    @aidlc-story US-customer-privacy-admin-delete-complete
    @aidlc-adr customer-privacy_admin-delete-flow
--}}
@if ($erasureOpen)
    @php($erasureRunning = in_array($erasureStatus, ['queued', 'running'], true))
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="customer-privacy-erase-dialog"
        @if ($erasureRunning) wire:poll.1s="tickErasure" @endif>
        <div class="absolute inset-0 bg-gray-900/50" @unless ($erasureRunning) wire:click="closeErasure" @endunless></div>
        <div class="relative w-full max-w-lg rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-700">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">{{ gp247_language_render('admin.customer.erase.title', ['customer' => $this->erasureCustomerName()]) }}</h3>
                <button type="button" wire:click="closeErasure" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" aria-label="{{ gp247_language_render('admin.step_up.cancel') }}">&times;</button>
            </div>
            <div class="space-y-4 p-5 text-sm text-gray-700 dark:text-gray-200">
                @if ($erasureStatus === 'done')
                    <div data-testid="customer-privacy-erase-done"><x-gp247::alert type="success">{{ gp247_language_render('admin.personal_data.status.done') }}</x-gp247::alert></div>
                @elseif ($erasureStatus === 'failed')
                    <div data-testid="customer-privacy-erase-failed"><x-gp247::alert type="error">{{ gp247_language_render('admin.personal_data.status.failed') }}</x-gp247::alert></div>
                @elseif ($erasureRunning)
                    <p data-testid="customer-privacy-erase-progress">{{ gp247_language_render('admin.personal_data.status.running', $erasureProgress) }}</p>
                    <div class="h-2 w-full rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-2 rounded-full bg-blue-600" style="width: {{ $erasureProgress['total'] > 0 ? (int) round(100 * $erasureProgress['done'] / $erasureProgress['total']) : 0 }}%"></div>
                    </div>
                @elseif ($erasureBlockers !== [])
                    <div data-testid="customer-privacy-erase-blocked"><x-gp247::alert type="warning">{{ gp247_language_render('admin.customer.erase.blocked') }}</x-gp247::alert></div>
                    <ul class="list-disc space-y-1 ps-5">
                        @foreach ($erasureBlockers as $blocker)
                            <li data-testid="customer-privacy-erase-blocker">
                                @if (in_array($blocker['reason'], ['open_order', 'open_shipment'], true) && \Illuminate\Support\Facades\Route::has('admin_order.detail'))
                                    <a href="{{ gp247_route_admin('admin_order.detail', ['id' => $blocker['ref']]) }}" class="text-blue-600 hover:underline dark:text-blue-400">{{ $blocker['label'] }}</a>
                                @else
                                    {{ $blocker['label'] }}
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p>{{ gp247_language_render('admin.customer.erase.intro') }}</p>
                    @if ($erasurePreview !== [])
                        <ul class="space-y-1" data-testid="customer-privacy-erase-preview">
                            @foreach ($erasurePreview as $item)
                                <li class="flex justify-between gap-4">
                                    <span>{{ $item['label'] }}</span>
                                    <span class="text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.customer.erase.rule_' . $item['rule'], ['rows' => $item['rows']]) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <x-gp247::alert type="error">{{ gp247_language_render('admin.customer.erase.irreversible') }}</x-gp247::alert>
                @endif
                <div class="flex justify-end gap-2">
                    @if ($erasureStatus === '' && $erasureBlockers === [])
                        <x-gp247::button type="button" variant="secondary" wire:click="closeErasure" data-testid="customer-privacy-erase-cancel">{{ gp247_language_render('admin.step_up.cancel') }}</x-gp247::button>
                        <x-gp247::button type="button" variant="danger" wire:click="confirmErasure" wire:loading.attr="disabled" data-testid="customer-privacy-erase-confirm">{{ gp247_language_render('admin.customer.erase.confirm') }}</x-gp247::button>
                    @elseif (!$erasureRunning)
                        <x-gp247::button type="button" variant="secondary" wire:click="closeErasure" data-testid="customer-privacy-erase-close">{{ gp247_language_render('admin.customer.erase.close') }}</x-gp247::button>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif

<x-gp247::step-up-box :open="$stepUpOpen" :needs-code="$this->stepUpAsksCode()" :error="$stepUpError"
    :minutes="$this->stepUpMinutes()" testid="customer-privacy-step-up" />
