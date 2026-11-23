<?php

namespace GP247\Shop\Tests\Feature\ApiSync\Support;

use Illuminate\Support\Facades\DB;

/**
 * Helpers of the event-log tests: switch the log on/off, register probe event types and
 * write rows straight into api_event (bypassing the recorder) for read/retention tests.
 * Needs ApiFixtures (forgetConfigMemo) and DatabaseTransactions on the test.
 */
trait EventLogFixtures
{
    /**
     * @param bool $on
     * @return void
     */
    protected function setEventLog(bool $on): void
    {
        $table = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
        $where = ['key' => 'api_event_log', 'store_id' => GP247_STORE_ID_GLOBAL];
        if ($table->clone()->where($where)->exists()) {
            $table->clone()->where($where)->update(['value' => $on ? '1' : '0']);
        } else {
            $table->clone()->insert($where + ['group' => 'global', 'code' => 'api_config', 'value' => $on ? '1' : '0', 'sort' => 90, 'detail' => 'admin.api_connection.event_log']);
        }
        $this->forgetConfigMemo();
    }

    /**
     * Register a probe event type readable by the holders of $permission (an admin route name).
     *
     * @param string   $type
     * @param string   $permission
     * @param string[] $fields
     * @return void
     */
    protected function registerEventType(string $type, string $permission, array $fields = ['status']): void
    {
        config(['gp247-config.api.events' => array_merge((array) config('gp247-config.api.events', []), [
            $type => ['fields' => $fields, 'permission' => $permission],
        ])]);
    }

    /**
     * Insert an event row directly.
     *
     * @param string      $type
     * @param string|null $storeId
     * @param mixed       $occurredAt
     * @param array       $data
     * @return int Event id.
     */
    protected function insertEvent(string $type, ?string $storeId = null, $occurredAt = null, array $data = []): int
    {
        return (int) $this->events()->insertGetId([
            'type' => $type, 'subject_id' => 'ZZ-' . uniqid(), 'store_id' => $storeId ?? (string) GP247_STORE_ID_ROOT,
            'source' => 'system', 'actor_type' => null, 'actor_id' => null,
            'data' => json_encode((object) $data), 'occurred_at' => $occurredAt ?? now()->subMinute(),
        ]);
    }

    /**
     * Highest event id now (0 when empty) — reads after it see only this test's rows.
     *
     * @return int
     */
    protected function lastEventId(): int
    {
        return (int) $this->events()->max('id');
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    protected function events()
    {
        return DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'api_event');
    }
}
