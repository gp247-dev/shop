<?php
use GP247\Shop\Models\ShopCustomer;
use GP247\Core\Models\AdminCustomField;


if (!function_exists('customer') && !in_array('customer', config('gp247_functions_except', []))) {
    /**
     * Customer login information
     */
    function customer()
    {
        return auth()->guard('customer');
    }
}

/**
 * Send email reset password. The link is built against the configured domain of
 * the store (gp247_mail_link_url), never the Host of the request that asked for it.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-CMP-mail-link-configured-domain
 * @aidlc-adr compat-foundation_forwarded-header-trust
 */
if (!function_exists('gp247_customer_sendmail_reset_notification') && !in_array('gp247_customer_sendmail_reset_notification', config('gp247_functions_except', []))) {
    function gp247_customer_sendmail_reset_notification(string $token, string $emailReset)
    {
            $url = gp247_mail_link_url(fn () => gp247_route_front('customer.password_reset', ['token' => $token]));
            $dataView = [
                'title' => gp247_language_render('email.forgot_password.title'),
                'reason_sendmail' => gp247_language_render('email.forgot_password.reason_sendmail'),
                'note_sendmail' => gp247_language_render('email.forgot_password.note_sendmail', ['count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]),
                'note_access_link' => gp247_language_render('email.forgot_password.note_access_link', ['reset_button' => gp247_language_render('email.forgot_password.reset_button'), 'url' => $url]),
                'reset_link' => $url,
                'reset_button' => gp247_language_render('email.forgot_password.reset_button'),
            ];
    
            $config = [
                'to' => $emailReset,
                'subject' => gp247_language_render('email.forgot_password.reset_button'),
            ];
            gp247_mail_send(gp247_shop_mail_view('email.shop_forgot_password'), $dataView, $config, []);
    }
}


/**
 * Send email verify. The signed link is built against the configured domain of the
 * store (gp247_mail_link_url), never the Host of the request that asked for it.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-CMP-mail-link-configured-domain, US-LW-email-verify-signed-link
 * @aidlc-adr compat-foundation_forwarded-header-trust
 */
if (!function_exists('gp247_customer_sendmail_verify') && !in_array('gp247_customer_sendmail_verify', config('gp247_functions_except', []))) {
    function gp247_customer_sendmail_verify(string $emailVerify, string $userId)
    {

        $url = gp247_mail_link_url(fn () => \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'customer.verify_process',
            \Carbon\Carbon::now()->addMinutes(config('auth.verification', 60)),
            [
                'id' => $userId,
                'token' => sha1($emailVerify),
            ]
        ));

        $dataView = [
            'title' => gp247_language_render('email.verification_content.title'),
            'reason_sendmail' => gp247_language_render('email.verification_content.reason_sendmail'),
            'note_sendmail' => gp247_language_render('email.verification_content.note_sendmail', ['count' => config('auth.verification')]),
            'note_access_link' => gp247_language_render('email.verification_content.note_access_link', ['reset_button' => gp247_language_render('customer.verify_email.button_verify'), 'url' => $url]),
            'url_verify' => $url,
            'button' => gp247_language_render('customer.verify_email.button_verify'),
        ];

        $config = [
            'to' => $emailVerify,
            'subject' => gp247_language_render('customer.verify_email.button_verify'),
        ];

        gp247_mail_send(gp247_shop_mail_view('email.shop_customer_verify'), $dataView, $config, []);
        return true;
    }
}

/**
 * Send email welcome
 */
if (!function_exists('gp247_customer_sendmail_welcome') && !in_array('gp247_customer_sendmail_welcome', config('gp247_functions_except', []))) {
    function gp247_customer_sendmail_welcome(ShopCustomer $customer)
    {
        if (gp247_config('welcome_customer')) {
                $dataView = [
                    'title' => gp247_language_render('email.welcome_customer.title'),
                    'first_name' => $customer['first_name'],
                    'last_name' => $customer['last_name'],
                ];
                $config = [
                    'to' => $customer['email'],
                    'subject' => gp247_language_render('email.welcome_customer.title'),
                ];
                gp247_mail_send(gp247_shop_mail_view('email.shop_welcome_customer'), $dataView, $config, []);
        }
    }
}

/**
 * Validation rules, messages and data to store for an address-book entry.
 *
 * The address fields (which are shown, which are required, their rules) come from
 * AddressForm; when an address plugin serves the country, its names replace the typed
 * ones and its codes are added as `address_meta` (AddressService::applyToMapping).
 *
 * @param array<string, mixed> $dataRaw Submitted address.
 * @return array{validate: array<string, mixed>, messages: array<string, string>, dataAddress: array<string, mixed>}
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-customer-address
 * @aidlc-story US-shop-address-unified-field-rules
 * @aidlc-story US-shop-address-server-normalize
 */
if (!function_exists('gp247_customer_address_mapping') && !in_array('gp247_customer_address_mapping', config('gp247_functions_except', []))) {
    function gp247_customer_address_mapping(array $dataRaw)
    {
        $dataAddress = [
            'first_name' => $dataRaw['first_name'] ?? '',
            'address1' => $dataRaw['address1'] ?? '',
        ];
        $validate = [
            'first_name' => config('validation.customer.first_name', 'required|string|max:100'),
            'address1' => config('validation.customer.address1_required', 'required|string|max:100'),
        ];
        if (gp247_config('customer_lastname')) {
            $validate['last_name'] = config('validation.customer.last_name_required', 'required|string|max:100');
            $dataAddress['last_name'] = $dataRaw['last_name']??'';
        }
        // Address fields: which are shown, which are required and their rules come from
        // the one resolver every address form shares (AddressForm); values are still
        // copied field by field, only for fields the shop switched on.
        $addressSet = \GP247\Shop\Address\AddressForm::make()->resolve(
            null,
            isset($dataRaw['country']) && is_string($dataRaw['country']) ? $dataRaw['country'] : null,
            \GP247\Shop\Address\AddressForm::CONTEXT_ADDRESS_BOOK
        );
        $validate = array_merge($validate, $addressSet->rulesFor(['city', 'district', 'ward', 'address2', 'address3']));
        foreach (['city', 'district', 'ward', 'address2', 'address3'] as $addressField) {
            if ($addressSet->shows($addressField)) {
                $dataAddress[$addressField] = $dataRaw[$addressField] ?? '';
            }
        }

        if (gp247_config('customer_phone')) {
            // WHY: honour customer_phone_required like every other customer form — an
            // optional phone used to be forced here.
            $validate['phone'] = gp247_config('customer_phone_required')
                ? config('validation.customer.phone_required', 'required|regex:/^0[^0][0-9\-]{6,12}$/')
                : config('validation.customer.phone_null', 'nullable|regex:/^0[^0][0-9\-]{6,12}$/');
            $dataAddress['phone'] = $dataRaw['phone']??'';
        }
        $validate = array_merge($validate, $addressSet->rulesFor(['country', 'postcode']));
        foreach (['country', 'postcode'] as $addressField) {
            if ($addressSet->shows($addressField)) {
                $dataAddress[$addressField] = $dataRaw[$addressField] ?? '';
            }
        }

        $messages = [
            'last_name.required'  => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.last_name')]),
            'first_name.required' => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.first_name')]),
            'city.required'       => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.city')]),
            'district.required'   => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.district')]),
            'ward.required'   => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.ward')]),
            'address1.required'   => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.address1')]),
            'address2.required'   => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.address2')]),
            'address3.required'   => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.address3')]),
            'phone.required'      => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.phone')]),
            'country.required'    => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.country')]),
            'postcode.required'   => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.postcode')]),
            'phone.regex'         => gp247_language_render('customer.phone_regex'),
            'postcode.min'        => gp247_language_render('validation.min', ['attribute'=> gp247_language_render('customer.postcode')]),
            'country.min'         => gp247_language_render('validation.min', ['attribute'=> gp247_language_render('customer.country')]),
            'first_name.max'      => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.first_name')]),
            'city.max'            => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.city')]),
            'district.max'        => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.district')]),
            'ward.max'        => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.ward')]),
            'address1.max'        => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.address1')]),
            'address2.max'        => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.address2')]),
            'address3.max'        => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.address3')]),
            'last_name.max'       => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.last_name')]),
        ];

        // Address plugin of the country (when one serves it): its names replace the typed ones,
        // the codes go to `address_meta`, a value outside its list fails on its field.
        [$validate, $dataAddress] = \GP247\Shop\Address\AddressService::make()->applyToMapping($addressSet, $dataRaw, $validate, $dataAddress);
        $dataMap = [
            'validate' => $validate,
            'messages' => $messages,
            'dataAddress' => $dataAddress
        ];
        return $dataMap;
    }
}


/**
 * Mapping data customer before insert
 *
 * @param   [array]  $dataRaw  [$dataRaw description]
 *
 * @return  [array]              [return description]
 */
if (!function_exists('gp247_customer_data_insert_mapping') && !in_array('gp247_customer_data_insert_mapping', config('gp247_functions_except', []))) {
    function gp247_customer_data_insert_mapping(array $dataRaw)
    {
        $dataInsert = [
            'first_name' => $dataRaw['first_name'] ?? '',
            // WHY: store NULL (not '') when no email is given. The email column is
            // nullable but carries a UNIQUE index; MySQL allows many NULLs yet only
            // one '' — so a second email-less customer collided with
            // `1062 Duplicate entry '' for key gp247_shop_customer_email_unique`.
            // Validation ('nullable|...|unique') never caught it because Laravel
            // skips non-implicit rules (incl. unique) on an empty string. Mirrors
            // the edit path, which already skips empty email. (US-SADM-004)
            'email'      => (isset($dataRaw['email']) && $dataRaw['email'] !== '') ? $dataRaw['email'] : null,
        ];
        // WHY isset: the validator builds its rules through this mapping BEFORE validating, so a
        // JSON body without the key (API register) must reach the "password required" rule (422)
        // instead of crashing here (500). Forms always post the key, so their result is unchanged.
        if (isset($dataRaw['password'])) {
            $dataInsert['password'] = gp247_password_hash($dataRaw['password']);
        }

        $emailUnique = '|unique:"'.ShopCustomer::class.'",email';
        $validate = [
            'first_name' => config('validation.customer.first_name', 'required|string|max:100'),
            'email'      => gp247_config_admin('customer_email')
                ? (gp247_config_admin('customer_email_required')
                    ? config('validation.customer.email', 'required|string|email|max:255').$emailUnique
                    : config('validation.customer.email_null', 'nullable|string|email|max:255').$emailUnique)
                : 'sometimes|nullable|string|email|max:255',
            'password'   => gp247_customer_validate_password()['password_confirm'],
        ];

        if (isset($dataRaw['status'])) {
            $dataInsert['status']  = $dataRaw['status'];
        }

        // WHY: route custom-field rules through the shared validator so the public register
        // form enforces required + typed rules per field option. The old
        // (new AdminCustomField)->getCustomField('shop_customer') returned a single detail
        // row keyed on the wrong table/rel_id and never listed the definitions, so this
        // validation was silently dead (RISK-TECH-custom-field-typing).
        $validate = gp247_custom_field_validate($validate, (new ShopCustomer)->getTable());

        if (gp247_config('customer_lastname')) {
            if (gp247_config('customer_lastname_required')) {
                $validate['last_name'] = config('validation.customer.last_name_required', 'required|string|max:100');
            } else {
                $validate['last_name'] = config('validation.customer.last_name_null', 'nullable|string|max:100');
            }
            if (!empty($dataRaw['last_name'])) {
                $dataInsert['last_name'] = $dataRaw['last_name'];
            }
        }

        // Address fields: which are shown, which are required and their rules come from
        // the one resolver every address form shares (AddressForm); values are still
        // copied field by field, only for fields the shop switched on.
        $addressSet = \GP247\Shop\Address\AddressForm::make()->resolve(
            null,
            isset($dataRaw['country']) && is_string($dataRaw['country']) ? $dataRaw['country'] : null,
            \GP247\Shop\Address\AddressForm::CONTEXT_CUSTOMER_INSERT
        );
        $validate = array_merge($validate, $addressSet->rulesFor(['city', 'district', 'ward', 'address1', 'address2', 'address3']));
        foreach (['city', 'district', 'ward', 'address1', 'address2', 'address3'] as $addressField) {
            if ($addressSet->shows($addressField) && !empty($dataRaw[$addressField])) {
                $dataInsert[$addressField] = $dataRaw[$addressField];
            }
        }


        if (gp247_config('customer_phone')) {
            if (gp247_config('customer_phone_required')) {
                $validate['phone'] = config('validation.customer.phone_required', 'regex:/^0[^0][0-9\-]{6,12}$/');
            } else {
                $validate['phone'] = config('validation.customer.phone_null', 'nullable|regex:/^0[^0][0-9\-]{6,12}$/');
            }
            if (!empty($dataRaw['phone'])) {
                $dataInsert['phone'] = $dataRaw['phone'];
            }
        }

        $validate = array_merge($validate, $addressSet->rulesFor(['country', 'postcode']));
        foreach (['country', 'postcode'] as $addressField) {
            if ($addressSet->shows($addressField) && !empty($dataRaw[$addressField])) {
                $dataInsert[$addressField] = $dataRaw[$addressField];
            }
        }

        if (gp247_config('customer_company')) {
            if (gp247_config('customer_company_required')) {
                $validate['company'] = config('validation.customer.company_required', 'required|string|max:100');
            } else {
                $validate['company'] = config('validation.customer.company_null', 'nullable|string|max:100');
            }
            if (!empty($dataRaw['company'])) {
                $dataInsert['company'] = $dataRaw['company'];
            }
        }

        if (gp247_config('customer_sex')) {
            if (gp247_config('customer_sex_required')) {
                $validate['sex'] = config('validation.customer.sex_required', 'required|integer|max:10');
            } else {
                $validate['sex'] = config('validation.customer.sex_null', 'nullable|integer|max:10');
            }
            if (!empty($dataRaw['sex'])) {
                $dataInsert['sex'] = $dataRaw['sex'];
            }
        }

        if (gp247_config('customer_birthday')) {
            if (gp247_config('customer_birthday_required')) {
                $validate['birthday'] = config('validation.customer.birthday_required', 'required|date|date_format:Y-m-d');
            } else {
                $validate['birthday'] = config('validation.customer.birthday_null', 'nullable|date|date_format:Y-m-d');
            }
            if (!empty($dataRaw['birthday'])) {
                $dataInsert['birthday'] = $dataRaw['birthday'];
            }
        }

        if (gp247_config('customer_group')) {
            if (gp247_config('customer_group_required')) {
                $validate['group'] = config('validation.customer.group_required', 'required|integer|max:10');
            } else {
                $validate['group'] = config('validation.customer.group_null', 'nullable|integer|max:10');
            }
            if (!empty($dataRaw['group'])) {
                $dataInsert['group'] = $dataRaw['group'];
            }
        }

        if (gp247_config('customer_name_kana')) {
            if (gp247_config('customer_name_kana_required')) {
                $validate['first_name_kana'] = config('validation.customer.name_kana_required', 'required|string|max:100');
                $validate['last_name_kana'] = config('validation.customer.name_kana_required', 'required|string|max:100');
            } else {
                $validate['first_name_kana'] = config('validation.customer.name_kana_null', 'nullable|string|max:100');
                $validate['last_name_kana'] = config('validation.customer.name_kana_null', 'nullable|string|max:100');
            }
            if (!empty($dataRaw['first_name_kana'])) {
                $dataInsert['first_name_kana'] = $dataRaw['first_name_kana'];
            }
            if (!empty($dataRaw['last_name_kana'])) {
                $dataInsert['last_name_kana'] = $dataRaw['last_name_kana'];
            }
        }

        if (!empty($dataRaw['fields'])) {
            $dataInsert['fields'] = $dataRaw['fields'];
        }

        $messages = [
            'last_name.required'   => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.last_name')]),
            'first_name.required'  => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.first_name')]),
            'email.required'       => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.email')]),
            'password.required'    => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.password')]),
            'city.required'        => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.city')]),
            'district.required'    => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.district')]),
            'ward.required'    => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.ward')]),
            'address1.required'    => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.address1')]),
            'address2.required'    => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.address2')]),
            'address3.required'    => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.address3')]),
            'phone.required'       => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.phone')]),
            'country.required'     => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.country')]),
            'postcode.required'    => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.postcode')]),
            'company.required'     => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.company')]),
            'sex.required'         => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.sex')]),
            'birthday.required'    => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.birthday')]),
            'email.email'          => gp247_language_render('validation.email', ['attribute'=> gp247_language_render('customer.email')]),
            'phone.regex'          => gp247_language_render('customer.phone_regex'),
            'password.confirmed'   => gp247_language_render('validation.confirmed', ['attribute'=> gp247_language_render('customer.password')]),
            'postcode.min'         => gp247_language_render('validation.min', ['attribute'=> gp247_language_render('customer.postcode')]),
            'country.min'          => gp247_language_render('validation.min', ['attribute'=> gp247_language_render('customer.country')]),
            'first_name.max'       => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.first_name')]),
            'email.max'            => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.email')]),
            'city.max'             => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.city')]),
            'district.max'         => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.district')]),
            'ward.max'         => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.ward')]),
            'address1.max'         => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.address1')]),
            'address2.max'         => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.address2')]),
            'address3.max'         => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.address3')]),
            'last_name.max'        => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.last_name')]),
            'birthday.date'        => gp247_language_render('validation.date', ['attribute'=> gp247_language_render('customer.birthday')]),
            'birthday.date_format' => gp247_language_render('validation.date_format', ['attribute'=> gp247_language_render('customer.birthday')]),
            'password.min' => gp247_language_render('validation.password.min', ['attribute'=> gp247_language_render('customer.password')]),
            'password.max' => gp247_language_render('validation.password.max', ['attribute'=> gp247_language_render('customer.password')]),
            'password.letters' => gp247_language_render('validation.password.letters', ['attribute'=> gp247_language_render('customer.password')]),
            'password.mixed' => gp247_language_render('validation.password.mixed', ['attribute'=> gp247_language_render('customer.password')]),
            'password.numbers' => gp247_language_render('validation.password.numbers', ['attribute'=> gp247_language_render('customer.password')]),
            'password.symbols' => gp247_language_render('validation.password.symbols', ['attribute'=> gp247_language_render('customer.password')]),
        ];
        // Address plugin of the country (when one serves it): its names replace the typed ones,
        // the codes go to `address_meta`, a value outside its list fails on its field.
        [$validate, $dataInsert] = \GP247\Shop\Address\AddressService::make()->applyToMapping($addressSet, $dataRaw, $validate, $dataInsert);
        $dataMap = [
            'validate' => $validate,
            'messages' => $messages,
            'dataInsert' => $dataInsert
        ];
        return $dataMap;
    }
}

/**
 * Mapping data customer before edit
 *
 * @param   [array]  $dataRaw  [$dataRaw description]
 *
 * @return  [array]              [return description]
 */
if (!function_exists('gp247_customer_data_edit_mapping') && !in_array('gp247_customer_data_edit_mapping', config('gp247_functions_except', []))) {
    function gp247_customer_data_edit_mapping(array $dataRaw)
    {
        $dataUpdate = [
            // WHY ??: as in the insert mapping — a body without the key must reach the "required" rule.
            'first_name' => $dataRaw['first_name'] ?? '',
        ];
        if (isset($dataRaw['status'])) {
            $dataUpdate['status']  = $dataRaw['status'];
        }
        $validate = [
            'first_name' => config('validation.customer.first_name', 'required|string|max:100'),
            'password' => gp247_customer_validate_password()['password_nullable'],
        ];

        // WHY: see the register path — delegate to the shared validator (required + typed)
        // instead of the dead getCustomField() loop. The $dataUpdate['fields'] assignment
        // must live OUTSIDE any custom-field-exists guard: previously it sat inside the dead
        // block, so submitted values never reached gp247_custom_field_update() on profile edit.
        $validate = gp247_custom_field_validate($validate, (new ShopCustomer)->getTable());
        $dataUpdate['fields'] = $dataRaw['fields'] ?? [];

        if (!empty($dataRaw['password'])) {
            $dataUpdate['password'] = gp247_password_hash($dataRaw['password']);
        }
        if (gp247_config_admin('customer_email')) {
            $emailUnique = '|unique:"'.ShopCustomer::class.'",email,'.$dataRaw['id'].',id';
            if (gp247_config_admin('customer_email_required')) {
                $validate['email'] = config('validation.customer.email', 'required|string|email|max:255').$emailUnique;
            } else {
                $validate['email'] = config('validation.customer.email_null', 'nullable|string|email|max:255').$emailUnique;
            }
            if (!empty($dataRaw['email'])) {
                $dataUpdate['email'] = $dataRaw['email'];
            }
        }
        // Stored address of the customer being edited: an unchanged address is not checked again.
        $currentAddress = !empty($dataRaw['id'])
            ? ShopCustomer::whereKey($dataRaw['id'])->first(['city', 'district', 'ward', 'address1', 'address2', 'address3', 'country', 'postcode'])?->toArray()
            : null;
        //Dont update id
        unset($dataRaw['id']);

        if (gp247_config('customer_lastname')) {
            if (gp247_config('customer_lastname_required')) {
                $validate['last_name'] = config('validation.customer.last_name_required', 'required|string|max:100');
            } else {
                $validate['last_name'] = config('validation.customer.last_name_null', 'nullable|string|max:100');
            }
            if (!empty($dataRaw['last_name'])) {
                $dataUpdate['last_name'] = $dataRaw['last_name'];
            }
        }

        // Address fields: which are shown, which are required and their rules come from
        // the one resolver every address form shares (AddressForm); values are still
        // copied field by field, only for fields the shop switched on.
        $addressSet = \GP247\Shop\Address\AddressForm::make()->resolve(
            null,
            isset($dataRaw['country']) && is_string($dataRaw['country']) ? $dataRaw['country'] : null,
            \GP247\Shop\Address\AddressForm::CONTEXT_CUSTOMER_EDIT
        );
        $validate = array_merge($validate, $addressSet->rulesFor(['city', 'district', 'ward', 'address1', 'address2', 'address3']));
        foreach (['city', 'district', 'ward', 'address1', 'address2', 'address3'] as $addressField) {
            if ($addressSet->shows($addressField) && !empty($dataRaw[$addressField])) {
                $dataUpdate[$addressField] = $dataRaw[$addressField];
            }
        }


        if (gp247_config('customer_phone')) {
            if (gp247_config('customer_phone_required')) {
                $validate['phone'] = config('validation.customer.phone_required', 'regex:/^0[^0][0-9\-]{6,12}$/');
            } else {
                $validate['phone'] = config('validation.customer.phone_null', 'nullable|regex:/^0[^0][0-9\-]{6,12}$/');
            }
            if (!empty($dataRaw['phone'])) {
                $dataUpdate['phone'] = $dataRaw['phone'];
            }
        }

        $validate = array_merge($validate, $addressSet->rulesFor(['country', 'postcode']));
        foreach (['country', 'postcode'] as $addressField) {
            if ($addressSet->shows($addressField) && !empty($dataRaw[$addressField])) {
                $dataUpdate[$addressField] = $dataRaw[$addressField];
            }
        }

        if (gp247_config('customer_company')) {
            if (gp247_config('customer_company_required')) {
                $validate['company'] = config('validation.customer.company_required', 'required|string|max:100');
            } else {
                $validate['company'] = config('validation.customer.company_null', 'nullable|string|max:100');
            }
            if (!empty($dataRaw['company'])) {
                $dataUpdate['company'] = $dataRaw['company'];
            }
        }

        if (gp247_config('customer_sex')) {
            if (gp247_config('customer_sex_required')) {
                $validate['sex'] = config('validation.customer.sex_required', 'required|integer|max:10');
            } else {
                $validate['sex'] = config('validation.customer.sex_null', 'nullable|integer|max:10');
            }
            if (!empty($dataRaw['sex'])) {
                $dataUpdate['sex'] = $dataRaw['sex'];
            }
        }

        if (gp247_config('customer_birthday')) {
            if (gp247_config('customer_birthday_required')) {
                $validate['birthday'] = config('validation.customer.birthday_required', 'required|date|date_format:Y-m-d');
            } else {
                $validate['birthday'] = config('validation.customer.birthday_null', 'nullable|date|date_format:Y-m-d');
            }
            if (!empty($dataRaw['birthday'])) {
                $dataUpdate['birthday'] = $dataRaw['birthday'];
            }
        }

        if (gp247_config('customer_group')) {
            if (gp247_config('customer_group_required')) {
                $validate['group'] = config('validation.customer.group_required', 'required|integer|max:10');
            } else {
                $validate['group'] = config('validation.customer.group_null', 'nullable|integer|max:10');
            }
            if (!empty($dataRaw['group'])) {
                $dataUpdate['group'] = $dataRaw['group'];
            }
        }

        if (gp247_config('customer_name_kana')) {
            if (gp247_config('customer_name_kana_required')) {
                $validate['first_name_kana'] = config('validation.customer.name_kana_required', 'required|string|max:100');
                $validate['last_name_kana'] = config('validation.customer.name_kana_required', 'required|string|max:100');
            } else {
                $validate['first_name_kana'] = config('validation.customer.name_kana_null', 'nullable|string|max:100');
                $validate['last_name_kana'] = config('validation.customer.name_kana_null', 'nullable|string|max:100');
            }
            $dataUpdate['first_name_kana'] = $dataRaw['first_name_kana']?? '';
            $dataUpdate['last_name_kana'] = $dataRaw['last_name_kana']?? '';
        }

        $messages = [
            'last_name.required'   => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.last_name')]),
            'first_name.required'  => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.first_name')]),
            'email.required'       => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.email')]),
            'password.required'    => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.password')]),
            'city.required'        => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.city')]),
            'district.required'    => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.district')]),
            'ward.required'    => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.ward')]),
            'address1.required'    => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.address1')]),
            'address2.required'    => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.address2')]),
            'address3.required'    => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.address3')]),
            'phone.required'       => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.phone')]),
            'country.required'     => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.country')]),
            'postcode.required'    => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.postcode')]),
            'company.required'     => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.company')]),
            'sex.required'         => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.sex')]),
            'birthday.required'    => gp247_language_render('validation.required', ['attribute'=> gp247_language_render('customer.birthday')]),
            'email.email'          => gp247_language_render('validation.email', ['attribute'=> gp247_language_render('customer.email')]),
            'phone.regex'          => gp247_language_render('customer.phone_regex'),
            'password.confirmed'   => gp247_language_render('validation.confirmed', ['attribute'=> gp247_language_render('customer.password')]),
            'postcode.min'         => gp247_language_render('validation.min', ['attribute'=> gp247_language_render('customer.postcode')]),
            'country.min'          => gp247_language_render('validation.min', ['attribute'=> gp247_language_render('customer.country')]),
            'first_name.max'       => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.first_name')]),
            'email.max'            => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.email')]),
            'city.max'             => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.city')]),
            'district.max'         => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.district')]),
            'ward.max'         => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.ward')]),
            'address1.max'         => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.address1')]),
            'address2.max'         => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.address2')]),
            'address3.max'         => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.address3')]),
            'last_name.max'        => gp247_language_render('validation.max', ['attribute'=> gp247_language_render('customer.last_name')]),
            'birthday.date'        => gp247_language_render('validation.date', ['attribute'=> gp247_language_render('customer.birthday')]),
            'birthday.date_format' => gp247_language_render('validation.date_format', ['attribute'=> gp247_language_render('customer.birthday')]),
            'password.min' => gp247_language_render('validation.password.min', ['attribute'=> gp247_language_render('customer.password')]),
            'password.max' => gp247_language_render('validation.password.max', ['attribute'=> gp247_language_render('customer.password')]),
            'password.letters' => gp247_language_render('validation.password.letters', ['attribute'=> gp247_language_render('customer.password')]),
            'password.mixedCase' => gp247_language_render('validation.password.mixedCase', ['attribute'=> gp247_language_render('customer.password')]),
            'password.numbers' => gp247_language_render('validation.password.numbers', ['attribute'=> gp247_language_render('customer.password')]),
            'password.symbols' => gp247_language_render('validation.password.symbols', ['attribute'=> gp247_language_render('customer.password')]),
        ];
        // Address plugin of the country (when one serves it): its names replace the typed ones,
        // the codes go to `address_meta`, a value outside its list fails on its field.
        [$validate, $dataUpdate] = \GP247\Shop\Address\AddressService::make()->applyToMapping($addressSet, $dataRaw, $validate, $dataUpdate, $currentAddress);
        $dataMap = [
            'validate' => $validate,
            'messages' => $messages,
            'dataUpdate' => $dataUpdate
        ];
        return $dataMap;
    }
}

/**
 * Process after customer created by client
 */
if (!function_exists('gp247_customer_created_by_client') && !in_array('gp247_customer_created_by_client', config('gp247_functions_except', []))) {
    function gp247_customer_created_by_client(ShopCustomer $user)
    {
        //Send email welcome
        gp247_customer_sendmail_welcome($user);

        //Send email verify
        $user->sendEmailVerify();
    }
}

/**
 * Process after customer created by admin
 */
if (!function_exists('gp247_customer_created_by_admin') && !in_array('gp247_customer_created_by_admin', config('gp247_functions_except', []))) {
    function gp247_customer_created_by_admin(ShopCustomer $user)
    {
        //
    }
}

/**
 * Rules of a customer password, from the customer block of the core Password policy screen
 * (one site-wide policy; length and character switches all live in the rule object).
 * Developers may still replace this helper through gp247_functions_except.
 *
 * @return array{password: array<int, mixed>, password_confirm: array<int, mixed>, password_nullable: array<int, mixed>}
 *
 * @aidlc-unit storefront
 * @aidlc-story US-admin-shell-password-policy-single-source
 * @aidlc-adr admin-shell_password-policy-registry
 */
if (!function_exists('gp247_customer_validate_password') && !in_array('gp247_customer_validate_password', config('gp247_functions_except', []))) {
    function gp247_customer_validate_password()
    {
        $passwordValidate = gp247_password_rule(\GP247\Shop\Support\CustomerPasswordPolicy::AUDIENCE);

        return [
            'password'          => ['required', 'string', $passwordValidate],
            'password_confirm'  => ['nullable', 'string', $passwordValidate, 'confirmed'],
            'password_nullable' => ['nullable', 'string', $passwordValidate],
        ];
    }
}
