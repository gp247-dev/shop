<?php

namespace GP247\Shop\Tests\Feature\Compat;

use PHPUnit\Framework\TestCase;

/**
 * Static guard: the SQL text handed to a raw query call (whereRaw, orderByRaw, selectRaw,
 * havingRaw, DB::raw, ->raw, statement, unprepared, DB::select …) in gp247/core, front,
 * shop and mcp may only be built from constants, column lists the model derives from its
 * own table, identifiers quoted through quote(), or numbers already cast with (int)/(float)
 * — never from a value that could carry user input. Values go through bindings.
 *
 * Safe forms are recognised by pattern (ADR compat-foundation_static-security-guards §3); a
 * call outside those forms must sit in ALLOWLIST with the reason it is safe, or be fixed. An
 * allowlist entry that no longer matches fails too.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-dynamic-sql-guard
 * @aidlc-adr compat-foundation_static-security-guards
 */
class DynamicSqlGuardTest extends TestCase
{
    /** Packages whose PHP sources are guarded (relative to vendor/gp247). */
    private const PACKAGES = ['core', 'front', 'shop', 'mcp'];

    /** Methods whose first argument is SQL text, whatever the receiver. */
    private const RAW_METHODS = [
        'whereRaw', 'orWhereRaw', 'orderByRaw', 'selectRaw', 'havingRaw', 'orHavingRaw', 'groupByRaw',
        'raw', 'statement', 'unprepared', 'affectingStatement',
    ];

    /** Static `DB::` facade calls whose first argument is SQL text. */
    private const DB_FACADE_METHODS = ['select', 'selectOne', 'insert', 'update', 'delete'];

    /**
     * Sub-expressions that are safe inside SQL text: pattern => reason. Each match is
     * replaced by a placeholder before the remaining text is checked for variables.
     */
    private const SAFE_FRAGMENTS = [
        '/\((?:int|float)\)\s*(?:\$[\w]+(?:->\w+|\[[^\]]*\])*|\((?:[^()]++|(?R))*\))/' => 'number cast with (int)/(float)',
        '/(?:\$[\w]+(?:->\w+)*->)?quote(\((?:[^()]++|(?1))*\))/' => 'identifier quoted through quote()',
        '/(?:\$[\w]+|\(new [\w\\\\]+\))->getTable\(\)/' => 'table name of the model',
        '/\$dataSelect\b/' => 'column list the model builds from its own table names',
        '/\b(?:number_format|sqlNumericLiteral)(\((?:[^()]++|(?1))*\))/' => 'number formatted from a float',
    ];

    /**
     * Per-file exceptions: path => [normalised SQL argument => reason].
     */
    private const ALLOWLIST = [
        'core/src/Backup/Infrastructure/Database/SchemaInspector.php' => [
            '$statement' => 'CREATE TRIGGER read back from the site\'s own schema during restore (createTrigger)',
        ],
        'core/src/Backup/Infrastructure/Database/ShadowLoader.php' => [
            '$created' => 'CREATE TABLE read from sqlite_master, renamed to a quote()d shadow name',
            '$statement' => 'CREATE INDEX read from sqlite_master, renamed to quote()d identifiers',
        ],
        'core/src/Backup/Infrastructure/Database/TableSwapper.php' => [
            '\'RENAME TABLE \' . implode(\', \', array_map( fn ($p) => $this->schema->quote($p[0]) . \' TO \' . $this->schema->quote($p[1]), $pairs ))'
                => 'every table name passes through quote()',
        ],
        'front/src/Library/SeoAudit.php' => [
            '\'c.id, \' . self::titleSql($src) . \' as label\''
                => 'titleSql(): fixed SQL + name column of a declared content source (strict identifier pattern, column exists) quoted by the grammar',
            '\'c.id, sd.canonical, \' . self::titleSql($src) . \' as label\''
                => 'titleSql(): fixed SQL + name column of a declared content source (strict identifier pattern, column exists) quoted by the grammar',
            '$title . \' as t, COUNT(*) as n\''
                => '$title = titleSql(): fixed SQL + grammar-quoted name column of a declared content source',
            '$title' => '$title = titleSql(): fixed SQL + grammar-quoted name column of a declared content source',
            '\'c.id, \' . $title . \' as label\''
                => '$title = titleSql(): fixed SQL + grammar-quoted name column of a declared content source',
        ],
        'shop/src/Admin/Database/Migrations/upgrade/2026_08_30_150100_order_money_audit_data.php' => [
            '"UPDATE `{$orders}` SET `discount` = -`discount` WHERE `discount` < 0"' => 'table name = GP247_DB_PREFIX constant + literal',
            '"UPDATE `{$orders}` SET `received` = -`received` WHERE `received` < 0"' => 'table name = GP247_DB_PREFIX constant + literal',
            '"UPDATE `{$totals}` SET `value` = -`value` WHERE `code` IN (\'discount\', \'received\') AND `value` < 0"' => 'table name = GP247_DB_PREFIX constant + literal',
            '"UPDATE `{$orders}` SET `balance` = `total` - `received` WHERE `balance` <> `total` - `received`"' => 'table name = GP247_DB_PREFIX constant + literal',
            '$sql' => 'INSERT … SELECT with table names = GP247_DB_PREFIX constant + literal; the note goes as a binding',
            '" UPDATE `{$orders}` SET `payment_status` = CASE WHEN `total` - `received` < 0 THEN 4 WHEN `total` - `received` = 0 THEN 3 ELSE 2 END WHERE `received` <> 0 "' => 'table name = GP247_DB_PREFIX constant + literal',
            '"UPDATE `{$orders}` SET `payment_status` = 1 WHERE `received` = 0 AND `payment_status` = 0"' => 'table name = GP247_DB_PREFIX constant + literal',
            '" UPDATE `{$details}` SET `tax_rate` = ROUND(`tax` / `total_price` * 100, 2) WHERE `tax_rate` = 0 AND `tax` <> 0 AND `total_price` > 0 "' => 'table name = GP247_DB_PREFIX constant + literal',
        ],
        'shop/src/Library/Helpers/cart_store.php' => [
            '$tableStore.\'.code,\'.$tableStore.\'.id,\'.$tableSupplier.\'.id as supplier_id\'' => 'table names from (new Model)->getTable()',
        ],
        'shop/src/Models/ShopCurrency.php' => [
            '"UPDATE {$prefix}shop_product SET price = price * {$factor}, cost = cost * {$factor}"' => 'GP247_DB_PREFIX + factor = sqlNumericLiteral((float) rate)',
            '"UPDATE {$prefix}shop_product_attribute SET add_price = add_price * {$factor}"' => 'GP247_DB_PREFIX + factor = sqlNumericLiteral((float) rate)',
            '"UPDATE {$prefix}shop_product_promotion SET price_promotion = price_promotion * {$factor}"' => 'GP247_DB_PREFIX + factor = sqlNumericLiteral((float) rate)',
            '"exchange_rate / {$factor}"' => 'factor = sqlNumericLiteral((float) rate)',
        ],
        'shop/src/Models/ShopProduct.php' => [
            '\'stock + \' . $q' => '$q = number_format($delta, 4, \'.\', \'\') — a decimal literal',
            '\'stock - \' . $q' => '$q = number_format($qtyChange, 4, \'.\', \'\') — a decimal literal',
            '\'sold + \' . $q' => '$q = number_format($qtyChange, 4, \'.\', \'\') — a decimal literal',
        ],
    ];

    /**
     * Collapse whitespace so multi-line arguments compare as one line.
     *
     * @param string $text
     * @return string
     */
    private static function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Whether a normalised SQL argument only holds constants and safe fragments.
     *
     * @param string $sql
     * @return bool
     */
    public static function isSafeSql(string $sql): bool
    {
        foreach (array_keys(self::SAFE_FRAGMENTS) as $pattern) {
            $sql = (string) preg_replace($pattern, '0', $sql);
        }

        return !str_contains($sql, '$');
    }

    /**
     * Raw SQL calls of one PHP source: the first argument of each call, with its line.
     *
     * @param string $source
     * @return array<int, array{line:int, call:string, sql:string}>
     */
    public static function rawCalls(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $calls = [];

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING) {
                continue;
            }
            $name = $tokens[$i][1];
            $prev = self::previousSignificant($tokens, $i);
            $isMethod = $prev !== null && in_array($prev['text'], ['->', '?->', '::'], true) && in_array($name, self::RAW_METHODS, true);
            $isFacade = $prev !== null && $prev['text'] === '::' && in_array($name, self::DB_FACADE_METHODS, true)
                && self::previousSignificant($tokens, $prev['index']) !== null
                && preg_match('/(^|\\\\)DB$/', self::previousSignificant($tokens, $prev['index'])['text']);
            if (!$isMethod && !$isFacade) {
                continue;
            }

            $open = self::nextSignificant($tokens, $i);
            if ($open === null || $open['text'] !== '(') {
                continue;
            }

            $argument = self::firstArgument($tokens, $open['index']);
            if ($argument === '') {
                continue;
            }

            $calls[] = ['line' => $tokens[$i][2], 'call' => $name, 'sql' => self::normalise($argument)];
        }

        return $calls;
    }

    /**
     * Source text of the first argument of the call whose "(" is at $open (comments dropped).
     *
     * @param array<int, mixed> $tokens
     * @param int $open
     * @return string
     */
    private static function firstArgument(array $tokens, int $open): string
    {
        $depth = 0;
        $text = '';
        $count = count($tokens);
        for ($j = $open; $j < $count; $j++) {
            $token = $tokens[$j];
            $value = is_array($token) ? $token[1] : $token;
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (in_array($value, ['(', '[', '{'], true) || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                if ($j === $open) {
                    continue;
                }
            } elseif (in_array($value, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($value === ',' && $depth === 1) {
                break;
            }
            $text .= $value;
        }

        return $text;
    }

    /**
     * @param array<int, mixed> $tokens
     * @param int $i
     * @return array{index:int, text:string}|null
     */
    private static function previousSignificant(array $tokens, int $i): ?array
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return ['index' => $j, 'text' => is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j]];
        }

        return null;
    }

    /**
     * @param array<int, mixed> $tokens
     * @param int $i
     * @return array{index:int, text:string}|null
     */
    private static function nextSignificant(array $tokens, int $i): ?array
    {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return ['index' => $j, 'text' => is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j]];
        }

        return null;
    }

    /**
     * Every raw SQL call of the guarded packages: path (relative to vendor/gp247) => calls.
     *
     * @return array<string, array<int, array{line:int, call:string, sql:string}>>
     */
    private function scan(): array
    {
        // WHY APP_BASE_PATH: the packages scanned are the ones installed in the host application
        // (tests/bootstrap.php sets it), not siblings of this checkout.
        $root = ($_ENV['APP_BASE_PATH'] ?? $_SERVER['APP_BASE_PATH'] ?? (getenv('APP_BASE_PATH') ?: dirname(__DIR__, 6))) . '/vendor/gp247';
        $result = [];
        foreach (self::PACKAGES as $package) {
            $dir = $root . '/' . $package . '/src';
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $name = $file->getFilename();
                if (!str_ends_with($name, '.php') || str_ends_with($name, '.blade.php')) {
                    continue;
                }
                $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $calls = self::rawCalls((string) file_get_contents($file->getPathname()));
                if ($calls !== []) {
                    $result[$path] = $calls;
                }
            }
        }
        ksort($result);

        return $result;
    }

    public function test_every_raw_sql_text_is_built_from_constants_or_safe_identifiers(): void
    {
        $violations = [];
        foreach ($this->scan() as $path => $calls) {
            foreach ($calls as $call) {
                if (self::isSafeSql($call['sql']) || isset(self::ALLOWLIST[$path][$call['sql']])) {
                    continue;
                }
                $violations[] = $path . ':' . $call['line'] . '  ' . $call['call'] . '(' . $call['sql'] . ')';
            }
        }

        $this->assertSame(
            [],
            $violations,
            count($violations) . " raw SQL call(s) build SQL text from a variable — use a binding, cast the number,"
            . " quote() the identifier, or add an ALLOWLIST entry with the reason:\n" . implode("\n", $violations)
        );
    }

    public function test_every_allowlist_entry_still_matches_a_raw_sql_call(): void
    {
        $scan = $this->scan();
        $orphans = [];
        foreach (self::ALLOWLIST as $path => $entries) {
            $sqls = array_column($scan[$path] ?? [], 'sql');
            foreach (array_keys($entries) as $sql) {
                if (!in_array($sql, $sqls, true)) {
                    $orphans[] = $path . '  ' . $sql;
                }
            }
        }

        $this->assertSame([], $orphans, "Allowlist entries that no longer match — remove them:\n" . implode("\n", $orphans));
    }

    public function test_the_guard_flags_sql_built_from_a_variable_and_lets_safe_forms_through(): void
    {
        $php = <<<'PHP'
            <?php
            $q->whereRaw("name = '$name'");
            $q->orderByRaw($request->input('sort') . ' desc');
            DB::select('SELECT * FROM t WHERE id = ' . $id);
            $q->whereRaw('name = ?', [$name]);
            $q->selectRaw($dataSelect);
            DB::raw('stock + ' . (int) $qty);
            $db->statement('DROP TABLE ' . $this->quote($table));
            $q->selectRaw(GP247_DB_PREFIX . 'shop_order.id');
            $recovery->raw();
            $q->select('code', 'name');
            PHP;

        $flagged = [];
        foreach (self::rawCalls($php) as $call) {
            if (!self::isSafeSql($call['sql'])) {
                $flagged[] = $call['line'] . ':' . $call['call'];
            }
        }

        $this->assertSame(['2:whereRaw', '3:orderByRaw', '4:select'], $flagged);
    }
}
