<?php

namespace GP247\Shop\Address\Http;

use GP247\Shop\Address\AddressForm;
use GP247\Shop\Address\AddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

/**
 * Read-only address data for forms that are not Livewire components: the fields of a
 * country, the choices of a field, the addresses of a postcode. Public data only — no
 * customer data is read or returned — rate limited per IP by the route.
 *
 * Parameters are checked against strict patterns before they reach the address plugin;
 * none is ever placed in a query. A failing plugin answers `fallback: true` (the form
 * then lets the shopper type), never an error page.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-data-endpoints
 * @aidlc-adr shop-address_provider-capability-contract
 */
class AddressDataController extends Controller
{
    /**
     * Contexts a form may ask a schema for. The admin ones are listed too: the answer is only the
     * shop's public field definitions (which fields show, how they are entered), never data.
     */
    private const CONTEXTS = [
        AddressForm::CONTEXT_CHECKOUT, AddressForm::CONTEXT_CUSTOMER_INSERT,
        AddressForm::CONTEXT_CUSTOMER_EDIT, AddressForm::CONTEXT_ADDRESS_BOOK,
        AddressForm::CONTEXT_ADMIN_CUSTOMER, AddressForm::CONTEXT_ADMIN_ORDER, AddressForm::CONTEXT_API,
    ];

    /** Seconds a browser may reuse a schema / a list of choices. */
    private const SCHEMA_MAX_AGE = 300;
    private const OPTIONS_MAX_AGE = 3600;

    /**
     * Fields of the address form for a country.
     *
     * @param Request $request `country` (ISO alpha-2), optional `context`.
     * @return JsonResponse
     */
    public function schema(Request $request): JsonResponse
    {
        $this->applyLocale($request);
        $input = $this->validated($request, [
            'country' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'lang' => ['nullable', 'string', 'max:10'],
            'context' => ['nullable', 'string', 'in:' . implode(',', self::CONTEXTS)],
        ]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        $set = AddressForm::make()->resolve(null, $input['country'], $input['context'] ?? AddressForm::CONTEXT_CHECKOUT);

        return $this->json($set->toArray() + ['data_version' => $set->dataVersion], 'private, max-age=' . self::SCHEMA_MAX_AGE);
    }

    /**
     * Choices of a list field, limited to a parent when given.
     *
     * @param Request $request `country`, `component`, optional `parent`.
     * @return JsonResponse
     */
    public function options(Request $request): JsonResponse
    {
        $this->applyLocale($request);
        $input = $this->validated($request, [
            'country' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'lang' => ['nullable', 'string', 'max:10'],
            'component' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'parent' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.\-]+$/'],
        ]);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        $result = AddressService::make()->options(null, $input['country'], $input['component'], $input['parent'] ?? null);

        // WHY: a fallback answer must not stick in the browser while the plugin recovers.
        return $this->json($result, $result['fallback'] ? 'no-store' : 'private, max-age=' . self::OPTIONS_MAX_AGE);
    }

    /**
     * Addresses matching a postcode.
     *
     * @param Request $request `country`, `postcode`.
     * @return JsonResponse
     */
    public function lookup(Request $request): JsonResponse
    {
        $this->applyLocale($request);
        $input = $this->validated($request, [
            'country' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'lang' => ['nullable', 'string', 'max:10'],
            'postcode' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9 \-]+$/'],
        ]);
        if ($input instanceof JsonResponse) {
            return $input;
        }

        return $this->json(AddressService::make()->lookup(null, $input['country'], $input['postcode']), 'no-store');
    }

    /**
     * Answers in the language of the page that asks (`lang`), not the session's: the endpoints have
     * no language prefix, and a shopper may have switched language in another tab. Only an active
     * language is used, and only for this request (the session is left as it is).
     *
     * @param Request $request Request.
     * @return void
     */
    private function applyLocale(Request $request): void
    {
        $lang = $request->query('lang');
        if (is_string($lang) && $lang !== '' && function_exists('gp247_language_all')
            && array_key_exists($lang, gp247_language_all()->toArray())) {
            app()->setLocale($lang);
        }
    }

    /**
     * @param Request                               $request Request.
     * @param array<string, array<int, string>>     $rules   Rules of the query parameters.
     * @return array<string, string>|JsonResponse Validated parameters, or the 422 answer.
     */
    private function validated(Request $request, array $rules): array|JsonResponse
    {
        $validator = Validator::make($request->query(), $rules);
        if ($validator->fails()) {
            return response()->json(['error' => 1, 'errors' => $validator->errors()->toArray()], 422)
                ->header('Cache-Control', 'no-store');
        }

        return array_map(static fn ($value): string => (string) $value, array_filter($validator->validated(), 'is_scalar'));
    }

    /**
     * @param array<string, mixed> $payload      Body.
     * @param string               $cacheControl Cache-Control value (never `public`: the storefront sets a session cookie).
     * @return JsonResponse
     */
    private function json(array $payload, string $cacheControl): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', $cacheControl);
    }
}
