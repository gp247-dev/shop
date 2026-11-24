{{--
    "My data" (US-customer-privacy-self-export): the customer types their password (and their
    two-step code when they use one), the shop prepares a package of everything it holds about
    them, the page asks for progress every few seconds (each question builds one more part — no
    cron) and reloads when it is ready; the download is a button (POST), so no secret is ever in
    a URL. Templates that do not have this file fall back to it (gp247_shop_process_view).
    Only class combinations already in the template's built CSS are used.

    Variables:
    - $available (bool): this server can make packages (ZIP extension, site updated)
    - $needsCode (bool): the customer must also type a two-step code
    - $package (array{status, expires_at, next_at, downloads_left}): the package of the last 24 hours

    @aidlc-unit customer-privacy
    @aidlc-story US-customer-privacy-self-export
    @aidlc-adr customer-privacy_self-service-surfaces
--}}
@php
    $view = gp247_shop_process_view($GP247TemplatePath, 'account.shop_layout');
@endphp
@extends($view)

@section('block_main_profile')
<div class="card p-6 max-w-xl space-y-4" data-testid="customer-privacy-page">
    @if (session('privacy_success'))
        <div class="rounded-lg bg-green-50 text-green-700 text-sm px-4 py-3" role="status">{{ session('privacy_success') }}</div>
    @endif
    @if (session('privacy_error'))
        <div class="rounded-lg bg-red-50 text-red-700 text-sm px-4 py-3" role="alert" data-testid="customer-privacy-error">{{ session('privacy_error') }}</div>
    @endif

    <p class="text-sm text-gray-600">{{ gp247_language_render('customer.privacy.export_intro') }}</p>

    @if (!$available)
        <p class="text-sm text-gray-600" data-testid="customer-privacy-unavailable">{{ gp247_language_render('customer.privacy.unavailable') }}</p>
    @elseif ($package['status'] === null)
        <form method="POST" action="{{ gp247_route_front('customer.privacy_export') }}" class="space-y-4" data-testid="customer-privacy-export-form">
            @csrf
            <div>
                <label class="label" for="privacy-password">{{ gp247_language_render('customer.privacy.password') }}</label>
                <input id="privacy-password" type="password" name="password" class="input" autocomplete="current-password" required data-testid="customer-privacy-password">
            </div>
            @if ($needsCode)
                <div>
                    <label class="label" for="privacy-code">{{ gp247_language_render('customer.privacy.code') }}</label>
                    <input id="privacy-code" type="text" name="code" class="input" autocomplete="one-time-code" inputmode="numeric" required data-testid="customer-privacy-code">
                </div>
            @endif
            <button class="btn-primary" type="submit" data-testid="customer-privacy-export-submit">{{ gp247_language_render('customer.privacy.export_button') }}</button>
        </form>
    @else
        <div id="privacy-package" data-status="{{ $package['status'] }}" data-status-url="{{ gp247_route_front('customer.privacy_export_status') }}" data-testid="customer-privacy-package">
            <p class="text-sm text-gray-600" id="privacy-package-text">
                @if ($package['status'] === 'building')
                    {{ gp247_language_render('customer.privacy.building') }}
                @elseif ($package['status'] === 'ready')
                    {{ gp247_language_render('customer.privacy.ready', ['at' => $package['expires_at'], 'count' => $package['downloads_left']]) }}
                @else
                    {{ gp247_language_render('customer.privacy.again_at', ['at' => $package['next_at']]) }}
                @endif
            </p>
            @if ($package['status'] === 'ready')
                <form method="POST" action="{{ gp247_route_front('customer.privacy_export_download') }}" class="mt-3">
                    @csrf
                    <button class="btn-primary" type="submit" data-testid="customer-privacy-download">{{ gp247_language_render('customer.privacy.download') }}</button>
                </form>
            @endif
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
    (function () {
        var box = document.getElementById('privacy-package');
        if (!box || box.getAttribute('data-status') !== 'building') {
            return;
        }
        var failed = @json(gp247_language_render('customer.privacy.failed'));
        function ask() {
            fetch(box.getAttribute('data-status-url'), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (body) {
                    var state = body && body.data ? body.data : {};
                    if (state.status === 'building') {
                        setTimeout(ask, 3000);
                    } else if (state.status === 'ready') {
                        window.location.reload();
                    } else {
                        document.getElementById('privacy-package-text').textContent = failed;
                    }
                })
                .catch(function () { setTimeout(ask, 6000); });
        }
        setTimeout(ask, 1500);
    })();
</script>
@endpush
