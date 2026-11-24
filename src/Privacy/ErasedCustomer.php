<?php

namespace GP247\Shop\Privacy;

use GP247\Core\PersonalData\Domain\ColumnRule;

/**
 * How admin screens show an order whose customer was erased: the name kept on the order is the
 * erasure marker, shown as "Deleted customer" in the admin language.
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-customer-privacy-admin-delete-complete
 * @aidlc-adr customer-privacy_admin-delete-flow
 */
final class ErasedCustomer
{
    /**
     * @param string|null $name A name kept on an order (first name, or first + last name).
     * @return bool Whether it is the erasure marker.
     */
    public static function is(?string $name): bool
    {
        return trim(str_replace(ColumnRule::MARKER, '', (string) $name)) === '' && str_contains((string) $name, ColumnRule::MARKER);
    }

    /**
     * @param string|null $name
     * @return string The name, or "Deleted customer" when the customer was erased.
     */
    public static function display(?string $name): string
    {
        return self::is($name) ? (string) gp247_language_render('admin.personal_data.deleted_marker') : (string) $name;
    }
}
