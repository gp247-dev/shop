{{--
    Account section layout — sidebar (shop_nav_customer) + content area.
    Vendor's fallback (vendor/gp247/shop/.../front/account/shop_layout.blade.php)
    overrides `block_main` with a Bootstrap container/row/col-md-3/col-md-9
    grid. GP247Front's layout.blade.php instead exposes `block_main_content_center`
    (see layout.blade.php's grid-cols-12 structure used by every prior phase),
    so this overrides that section instead with a Tailwind grid. Child screens
    keep filling `block_main_profile` exactly like the vendor originals — no
    change needed in any A01-A07 file's section name.

    Variables (unchanged from vendor):
    - $title: page title, shown above the content area

    @aidlc-unit frontend-template-dev
    @aidlc-story US-TPL-009
    @aidlc-adr ADR-014
--}}
@extends($GP247TemplatePath.'.layout')

@section('block_main_content_center')
<div class="lg:col-span-12 w-full grid grid-cols-1 md:grid-cols-4 gap-6">
    <aside class="md:col-span-1">
        @php
            $view = gp247_shop_process_view($GP247TemplatePath, 'account.shop_nav_customer');
        @endphp
        @include($view)
    </aside>
    <div class="md:col-span-3">
        <h1 class="section-title mb-6">{{ $title }}</h1>
        @php
            $privacyErasure = function_exists('gp247_shop_privacy_pending_erasure') ? gp247_shop_privacy_pending_erasure() : null;
        @endphp
        @if ($privacyErasure)
            {{-- The account is about to be erased: say when, offer to call it off (US-customer-privacy-self-delete). --}}
            <div class="rounded-lg bg-red-50 text-red-700 text-sm px-4 py-3 mb-4 flex flex-wrap items-center justify-between gap-3" role="status" data-testid="customer-privacy-delete-banner">
                <span>{{ $privacyErasure['status'] === 'on_hold'
                    ? gp247_language_render('customer.privacy.delete_on_hold')
                    : gp247_language_render('customer.privacy.delete_scheduled', ['date' => (string) $privacyErasure['runs_at']]) }}</span>
                <form method="POST" action="{{ gp247_route_front('customer.privacy_delete_cancel') }}">
                    @csrf
                    <button class="btn-outline" type="submit" data-testid="customer-privacy-delete-banner-cancel">{{ gp247_language_render('customer.privacy.cancel_button') }}</button>
                </form>
            </div>
        @endif
        @section('block_main_profile')
        @show
    </div>
</div>
@endsection
