<?php

declare(strict_types=1);

const DATA_FILE = __DIR__ . '/data.json';
const BASELINE_PRICE_DATE = '2000-01-01';
const BASELINE_PRICE_TIMESTAMP = '2000-01-01 00:00:00';

function seedProducts(): array
{
    return [
        'cheers' => ['name' => 'Cheers', 'sell_price' => 3000, 'stock' => 0, 'avg_cost' => 0],
        'floridina' => ['name' => 'Floridina', 'sell_price' => 5000, 'stock' => 0, 'avg_cost' => 0],
        'teh_pucuk' => ['name' => 'Teh Pucuk', 'sell_price' => 5000, 'stock' => 0, 'avg_cost' => 0],
        'kopi_abc' => ['name' => 'Kopi ABC', 'sell_price' => 5000, 'stock' => 0, 'avg_cost' => 0],
        'ultra_milk' => ['name' => 'Ultra Milk', 'sell_price' => 8000, 'stock' => 0, 'avg_cost' => 0],
    ];
}

function schemaDefaults(): array
{
    return [
        'settings' => [
            'products' => [],
            'archived_products' => [],
            'ingredients' => [],
            'archived_ingredients' => [],
            'mixed_menus' => [],
            'archived_mixed_menus' => [],
        ],
        'summary' => [
            'cash_balance' => 0,
            'external_capital' => 0,
            'total_actual_profit' => 0,
            'total_theoretical_revenue' => 0,
            'total_actual_revenue' => 0,
            'total_self_payment_diff' => 0,
            'total_operational_expense' => 0,
            'total_owner_withdrawal' => 0,
            'total_restock_cost' => 0,
        ],
        'logs' => [],
    ];
}

function defaultData(): array
{
    $data = schemaDefaults();
    $data['settings']['products'] = seedProducts();
    return $data;
}

function ensureDataFile(): void
{
    if (!file_exists(DATA_FILE)) {
        file_put_contents(DATA_FILE, json_encode(defaultData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

function mergeDefaults(array $data, array $defaults): array
{
    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $data)) {
            $data[$key] = $value;
            continue;
        }
        if (is_array($value) && is_array($data[$key])) {
            $data[$key] = mergeDefaults($data[$key], $value);
        }
    }
    return $data;
}

function loadData(): array
{
    ensureDataFile();
    $json = file_get_contents(DATA_FILE);
    $data = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($data)) {
        $data = defaultData();
        saveData($data);
        return $data;
    }
    $data = mergeDefaults($data, schemaDefaults());
    $products = $data['settings']['products'] ?? [];
    $archivedProducts = $data['settings']['archived_products'] ?? [];
    $ingredients = $data['settings']['ingredients'] ?? [];
    $archivedIngredients = $data['settings']['archived_ingredients'] ?? [];
    $mixedMenus = $data['settings']['mixed_menus'] ?? [];
    $archivedMixedMenus = $data['settings']['archived_mixed_menus'] ?? [];
    $data['settings']['products'] = normalizeProducts(is_array($products) ? $products : []);
    $data['settings']['archived_products'] = normalizeProducts(is_array($archivedProducts) ? $archivedProducts : [], true);
    $data['settings']['ingredients'] = normalizeIngredients(is_array($ingredients) ? $ingredients : []);
    $data['settings']['archived_ingredients'] = normalizeIngredients(is_array($archivedIngredients) ? $archivedIngredients : [], true);
    $data['settings']['mixed_menus'] = normalizeMixedMenus(is_array($mixedMenus) ? $mixedMenus : []);
    $data['settings']['archived_mixed_menus'] = normalizeMixedMenus(is_array($archivedMixedMenus) ? $archivedMixedMenus : [], true);
    return $data;
}

function saveData(array $data): void
{
    $fp = fopen(DATA_FILE, 'c+');
    if (!$fp) {
        throw new RuntimeException('Gagal membuka data.json');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('Gagal mengunci data.json');
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function rupiah(float $value): string
{
    return 'Rp' . number_format($value, 0, ',', '.');
}

function postText(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_array($value) ? $default : trim((string) $value);
}

function postNum(string $key, float $default = 0): float
{
    $value = $_POST[$key] ?? $default;
    if (is_array($value)) {
        return $default;
    }
    $value = str_replace([' ', ','], ['', ''], (string) $value);
    return is_numeric($value) ? (float) $value : $default;
}

function addLog(array &$data, string $type, string $date, string $description, array $meta = []): void
{
    array_unshift($data['logs'], [
        'id' => uniqid('', true),
        'type' => $type,
        'date' => $date,
        'description' => $description,
        'meta' => $meta,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function getAssetPurchaseTotal(array $logs): float
{
    $total = 0;
    foreach ($logs as $log) {
        if (($log['type'] ?? '') === 'purchase') {
            $total += (float) ($log['meta']['amount'] ?? 0);
        }
    }
    return $total;
}

function getAssetsByItem(array $logs): array
{
    $items = [];
    foreach ($logs as $log) {
        if (($log['type'] ?? '') !== 'purchase') {
            continue;
        }
        $name = trim((string) ($log['meta']['item_name'] ?? 'Tanpa Nama'));
        $amount = (float) ($log['meta']['amount'] ?? 0);
        if (!isset($items[$name])) {
            $items[$name] = 0;
        }
        $items[$name] += $amount;
    }
    arsort($items);
    return $items;
}

function getAssetsByItemUntilMonth(array $logs, string $endMonth): array
{
    $filteredLogs = [];
    foreach ($logs as $log) {
        $logDate = trim((string) ($log['date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate) !== 1) {
            continue;
        }

        if (substr($logDate, 0, 7) > $endMonth) {
            continue;
        }

        $filteredLogs[] = $log;
    }

    return getAssetsByItem($filteredLogs);
}

function renderHistoryLogDetails(array $log): string
{
    $type = (string) ($log['type'] ?? '');
    $meta = is_array($log['meta'] ?? null) ? $log['meta'] : [];

    ob_start();
    if ($type === 'restock'): ?>
        Produk: <?= htmlspecialchars((string) ($meta['product'] ?? '')) ?><br>
        Qty: <?= (int) ($meta['qty'] ?? 0) ?><br>
        Total modal: <?= rupiah((float) ($meta['total_cost'] ?? 0)) ?><br>
        Modal beli/item: <?= rupiah((float) ($meta['unit_cost'] ?? 0)) ?><br>
        Modal rata-rata baru: <?= rupiah((float) ($meta['new_avg_cost'] ?? 0)) ?><br>
        <span class="small"><?= htmlspecialchars((string) ($meta['note'] ?? '')) ?></span>
    <?php elseif ($type === 'restock_ingredient'): ?>
        Total modal: <?= rupiah((float) ($meta['total_cost'] ?? 0)) ?><br>
        Jumlah bahan dicatat: <?= count($meta['items'] ?? []) ?> item
        <details>
            <summary class="small">Lihat bahan</summary>
            <?php foreach (($meta['items'] ?? []) as $item): ?>
                <div class="small" style="margin-bottom:6px;">
                    <?= htmlspecialchars((string) ($item['ingredient'] ?? '')) ?> | masuk <?= (int) ($item['qty'] ?? 0) ?> <?= htmlspecialchars((string) ($item['unit'] ?? 'pcs')) ?> | modal/item <?= rupiah((float) ($item['unit_cost'] ?? 0)) ?> | modal rata-rata baru <?= rupiah((float) ($item['new_avg_cost'] ?? 0)) ?>
                </div>
            <?php endforeach; ?>
            <div class="small"><?= htmlspecialchars((string) ($meta['note'] ?? '')) ?></div>
        </details>
    <?php elseif ($type === 'update_stock'): ?>
        Uang masuk: <?= rupiah((float) ($meta['actual_cash_in'] ?? 0)) ?><br>
        Omzet teoritis: <?= rupiah((float) ($meta['theoretical_revenue'] ?? 0)) ?><br>
        Modal barang keluar: <?= rupiah((float) ($meta['cost_out'] ?? 0)) ?><br>
        Selisih self payment: <span class="<?= ((float) ($meta['self_payment_diff'] ?? 0)) >= 0 ? 'good' : 'bad' ?>"><?= rupiah((float) ($meta['self_payment_diff'] ?? 0)) ?></span><br>
        Laba aktual: <span class="<?= ((float) ($meta['actual_profit'] ?? 0)) >= 0 ? 'good' : 'bad' ?>"><?= rupiah((float) ($meta['actual_profit'] ?? 0)) ?></span>
        <details>
            <summary class="small">Lihat item</summary>
            <?php if (!empty($meta['items'])): ?>
                <div class="small" style="margin:8px 0 6px; color:#314252; font-weight:700;">Produk siap jual</div>
                <?php foreach (($meta['items'] ?? []) as $item): ?>
                    <div class="small" style="margin-bottom:6px;">
                        <?= htmlspecialchars((string) ($item['product'] ?? '')) ?> | stok awal <?= (int) ($item['previous_stock'] ?? 0) ?> | sisa <?= (int) ($item['remaining_stock'] ?? 0) ?> | keluar <?= (int) ($item['qty_out'] ?? 0) ?> | harga jual <?= rupiah((float) ($item['sell_price'] ?? 0)) ?><?php if (($item['sell_price_effective_date'] ?? '') !== ''): ?> (efektif <?= htmlspecialchars((string) ($item['sell_price_effective_date'] ?? '')) ?>)<?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($meta['mixed_menu_items'])): ?>
                <div class="small" style="margin:8px 0 6px; color:#314252; font-weight:700;">Menu racikan</div>
                <?php foreach (($meta['mixed_menu_items'] ?? []) as $item): ?>
                    <div class="small" style="margin-bottom:6px;">
                        <?= htmlspecialchars((string) ($item['menu'] ?? '')) ?> | terjual <?= (int) ($item['qty_sold'] ?? 0) ?> | harga jual <?= rupiah((float) ($item['sell_price'] ?? 0)) ?><?php if (($item['sell_price_effective_date'] ?? '') !== ''): ?> (efektif <?= htmlspecialchars((string) ($item['sell_price_effective_date'] ?? '')) ?>)<?php endif; ?> | omzet teoritis <?= rupiah((float) ($item['theoretical_revenue'] ?? 0)) ?> | HPP resep <?= rupiah((float) ($item['recipe_cost'] ?? 0)) ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($meta['ingredient_usage'])): ?>
                <div class="small" style="margin:8px 0 6px; color:#314252; font-weight:700;">Pemakaian bahan racikan</div>
                <?php foreach (($meta['ingredient_usage'] ?? []) as $usage): ?>
                    <div class="small" style="margin-bottom:6px;">
                        <?= htmlspecialchars((string) ($usage['ingredient'] ?? '')) ?> | stok awal <?= (int) ($usage['previous_stock'] ?? 0) ?> | terpakai <?= (int) ($usage['qty_used'] ?? 0) ?> <?= htmlspecialchars((string) ($usage['unit'] ?? 'pcs')) ?> | sisa <?= (int) ($usage['remaining_stock'] ?? 0) ?> | modal keluar <?= rupiah((float) ($usage['cost_out'] ?? 0)) ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="small"><?= htmlspecialchars((string) ($meta['note'] ?? '')) ?></div>
        </details>
    <?php elseif ($type === 'purchase'): ?>
        Item: <?= htmlspecialchars((string) ($meta['item_name'] ?? '')) ?><br>
        Nilai: <?= rupiah((float) ($meta['amount'] ?? 0)) ?><br>
        <span class="small"><?= htmlspecialchars((string) ($meta['note'] ?? '')) ?></span>
    <?php elseif ($type === 'owner_withdrawal'): ?>
        Nominal penarikan: <?= rupiah((float) ($meta['amount'] ?? 0)) ?><br>
        <span class="small"><?= htmlspecialchars((string) ($meta['note'] ?? '')) ?></span>
    <?php else: ?>
        Nominal: <?= rupiah((float) ($meta['amount'] ?? 0)) ?><br>
        <span class="small"><?= htmlspecialchars((string) ($meta['note'] ?? '')) ?></span>
    <?php endif;

    return trim((string) ob_get_clean());
}

function renderHistoryLogRows(array $logs): string
{
    ob_start();
    foreach ($logs as $log): ?>
        <tr>
            <td><?= htmlspecialchars((string) ($log['date'] ?? '')) ?></td>
            <td><span class="badge type-<?= htmlspecialchars((string) ($log['type'] ?? '')) ?>"><?= htmlspecialchars((string) ($log['type'] ?? '')) ?></span></td>
            <td><?= htmlspecialchars((string) ($log['description'] ?? '')) ?></td>
            <td><?= renderHistoryLogDetails($log) ?></td>
        </tr>
    <?php endforeach;

    return (string) ob_get_clean();
}

function normalizeDateValue(string $value, string $default): string
{
    $value = trim($value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $default;
}

function normalizeDateTimeValue(string $value, string $default): string
{
    $value = trim($value);
    if ($value === '' && $default === '') {
        return '';
    }
    return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1 ? $value : $default;
}

function normalizeMonthValue(string $value, string $default): string
{
    $value = trim($value);
    return preg_match('/^\d{4}-\d{2}$/', $value) === 1 ? $value : $default;
}

function normalizeWorkspaceValue(string $value, string $default): string
{
    $value = trim($value);
    $allowed = ['ringkasan', 'operasional', 'riwayat'];
    return in_array($value, $allowed, true) ? $value : $default;
}

function reportMonthOptions(array $logs): array
{
    $months = [date('Y-m') => true];
    foreach ($logs as $log) {
        $date = trim((string) ($log['date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            continue;
        }
        $months[substr($date, 0, 7)] = true;
    }

    $options = array_keys($months);
    rsort($options);
    return $options;
}

function reportMonthLabel(string $month): string
{
    [$year, $monthNumber] = array_pad(explode('-', $month, 2), 2, '');
    $labels = [
        '01' => 'Januari',
        '02' => 'Februari',
        '03' => 'Maret',
        '04' => 'April',
        '05' => 'Mei',
        '06' => 'Juni',
        '07' => 'Juli',
        '08' => 'Agustus',
        '09' => 'September',
        '10' => 'Oktober',
        '11' => 'November',
        '12' => 'Desember',
    ];

    return ($labels[$monthNumber] ?? $month) . ' ' . $year;
}

function reportPeriodLabel(string $startMonth, string $endMonth): string
{
    if ($startMonth === $endMonth) {
        return reportMonthLabel($startMonth);
    }

    return reportMonthLabel($startMonth) . ' s.d. ' . reportMonthLabel($endMonth);
}

function nextMonthValue(string $month): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m', $month);
    if (!$date) {
        return date('Y-m');
    }

    return $date->modify('+1 month')->format('Y-m');
}

function reportDateLabel(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
    if (!$parsed) {
        return $date;
    }

    return $parsed->format('d/m/Y');
}

function monthlyReportTypeLabel(string $type): string
{
    $labels = [
        'restock' => 'Restock Produk',
        'restock_ingredient' => 'Restock Bahan',
        'update_stock' => 'Rekap Penjualan',
        'purchase' => 'Pembelian Aset',
        'expense' => 'Biaya Operasional',
        'owner_withdrawal' => 'Penarikan PMK Kota',
    ];

    return $labels[$type] ?? ucfirst(str_replace('_', ' ', $type));
}

function monthlyReportDetailText(array $log): string
{
    $type = (string) ($log['type'] ?? '');
    $meta = is_array($log['meta'] ?? null) ? $log['meta'] : [];
    $note = trim((string) ($meta['note'] ?? ''));

    if ($type === 'restock') {
        return trim((string) ($meta['product'] ?? 'Produk') . ' | qty ' . (int) ($meta['qty'] ?? 0) . ' | modal/item ' . rupiah((float) ($meta['unit_cost'] ?? 0)));
    }
    if ($type === 'restock_ingredient') {
        return 'Bahan dicatat: ' . count($meta['items'] ?? []) . ' item | total modal ' . rupiah((float) ($meta['total_cost'] ?? 0));
    }
    if ($type === 'update_stock') {
        $productParts = [];
        foreach (($meta['items'] ?? []) as $item) {
            if (is_array($item) && (int) ($item['qty_out'] ?? 0) > 0) {
                $productParts[] = trim((string) ($item['product'] ?? 'Produk')) . ' ' . (int) ($item['qty_out'] ?? 0) . ' unit';
            }
        }

        $menuParts = [];
        foreach (($meta['mixed_menu_items'] ?? []) as $item) {
            if (is_array($item) && (int) ($item['qty_sold'] ?? 0) > 0) {
                $menuParts[] = trim((string) ($item['menu'] ?? 'Menu')) . ' ' . (int) ($item['qty_sold'] ?? 0) . ' porsi';
            }
        }

        $parts = [];
        if ($productParts !== []) {
            $parts[] = 'Produk: ' . implode(', ', $productParts);
        }
        if ($menuParts !== []) {
            $parts[] = 'Menu: ' . implode(', ', $menuParts);
        }
        if ($note !== '') {
            $parts[] = $note;
        }

        return $parts === [] ? 'Rekap penjualan harian.' : implode(' | ', $parts);
    }
    if ($type === 'purchase') {
        $text = trim((string) ($meta['item_name'] ?? 'Pembelian aset'));
        return $note !== '' ? $text . ' | ' . $note : $text;
    }
    if ($type === 'expense') {
        return $note !== '' ? $note : 'Biaya operasional';
    }
    if ($type === 'owner_withdrawal') {
        return $note !== '' ? $note : 'Penarikan PMK Kota';
    }

    return trim((string) ($log['description'] ?? ''));
}

function monthlyReportCashFlowParts(array $log): array
{
    $type = (string) ($log['type'] ?? '');
    $meta = is_array($log['meta'] ?? null) ? $log['meta'] : [];

    if ($type === 'update_stock') {
        return [
            'cash_in' => max(0, (float) ($meta['actual_cash_in'] ?? 0)),
            'cash_out' => 0.0,
        ];
    }

    if ($type === 'restock' || $type === 'restock_ingredient') {
        return [
            'cash_in' => 0.0,
            'cash_out' => max(0, (float) ($meta['total_cost'] ?? 0)),
        ];
    }

    if ($type === 'purchase' || $type === 'expense' || $type === 'owner_withdrawal') {
        return [
            'cash_in' => 0.0,
            'cash_out' => max(0, (float) ($meta['amount'] ?? 0)),
        ];
    }

    return [
        'cash_in' => 0.0,
        'cash_out' => 0.0,
    ];
}

function monthlyReportAmountLabel(array $log): string
{
    $type = (string) ($log['type'] ?? '');
    $meta = is_array($log['meta'] ?? null) ? $log['meta'] : [];

    if ($type === 'update_stock') {
        return '+' . rupiah((float) ($meta['actual_cash_in'] ?? 0));
    }
    if ($type === 'restock' || $type === 'restock_ingredient') {
        return '-' . rupiah((float) ($meta['total_cost'] ?? 0));
    }
    if ($type === 'purchase' || $type === 'expense' || $type === 'owner_withdrawal') {
        return '-' . rupiah((float) ($meta['amount'] ?? 0));
    }

    return rupiah(0);
}

function findSnapshotItemKeyByName(array $items, string $name): ?string
{
    $needle = trim($name);
    if ($needle === '') {
        return null;
    }

    foreach ($items as $key => $item) {
        if (trim((string) ($item['name'] ?? '')) === $needle) {
            return (string) $key;
        }
    }

    return null;
}

function buildBalanceSnapshotBeforeMonth(array $data, string $month): array
{
    $monthStart = $month . '-01';
    $products = array_merge(
        is_array($data['settings']['products'] ?? null) ? $data['settings']['products'] : [],
        is_array($data['settings']['archived_products'] ?? null) ? $data['settings']['archived_products'] : []
    );
    $ingredients = array_merge(
        is_array($data['settings']['ingredients'] ?? null) ? $data['settings']['ingredients'] : [],
        is_array($data['settings']['archived_ingredients'] ?? null) ? $data['settings']['archived_ingredients'] : []
    );
    $cashBalance = (float) ($data['summary']['cash_balance'] ?? 0);
    $nonStockAssets = getAssetPurchaseTotal($data['logs'] ?? []);
    $logsToReverse = [];

    foreach (($data['logs'] ?? []) as $log) {
        $logDate = trim((string) ($log['date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate) !== 1) {
            continue;
        }
        if ($logDate < $monthStart) {
            continue;
        }
        $logsToReverse[] = $log;
    }

    usort($logsToReverse, static function (array $a, array $b): int {
        $createdCompare = strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        if ($createdCompare !== 0) {
            return $createdCompare;
        }

        return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
    });

    foreach ($logsToReverse as $log) {
        $type = (string) ($log['type'] ?? '');
        $meta = is_array($log['meta'] ?? null) ? $log['meta'] : [];

        if ($type === 'update_stock') {
            $cashBalance -= (float) ($meta['actual_cash_in'] ?? 0);

            foreach (($meta['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $key = findSnapshotItemKeyByName($products, (string) ($item['product'] ?? ''));
                if ($key === null) {
                    continue;
                }
                $products[$key]['stock'] = (int) ($item['previous_stock'] ?? ($products[$key]['stock'] ?? 0));
                $products[$key]['avg_cost'] = (float) ($item['avg_cost'] ?? ($products[$key]['avg_cost'] ?? 0));
            }

            foreach (($meta['ingredient_usage'] ?? []) as $usage) {
                if (!is_array($usage)) {
                    continue;
                }
                $key = findSnapshotItemKeyByName($ingredients, (string) ($usage['ingredient'] ?? ''));
                if ($key === null) {
                    continue;
                }
                $ingredients[$key]['stock'] = (int) ($usage['previous_stock'] ?? ($ingredients[$key]['stock'] ?? 0));
                $ingredients[$key]['avg_cost'] = (float) ($usage['avg_cost'] ?? ($ingredients[$key]['avg_cost'] ?? 0));
            }
        } elseif ($type === 'restock') {
            $cashBalance += (float) ($meta['total_cost'] ?? 0);
            $key = findSnapshotItemKeyByName($products, (string) ($meta['product'] ?? ''));
            if ($key !== null) {
                $qty = (int) ($meta['qty'] ?? 0);
                $newStock = (int) ($products[$key]['stock'] ?? 0);
                $newAvg = (float) ($products[$key]['avg_cost'] ?? ($meta['new_avg_cost'] ?? 0));
                $totalCost = (float) ($meta['total_cost'] ?? 0);
                $oldStock = max(0, $newStock - $qty);
                $oldValue = max(0, ($newAvg * $newStock) - $totalCost);
                $products[$key]['stock'] = $oldStock;
                $products[$key]['avg_cost'] = $oldStock > 0 ? round($oldValue / $oldStock, 2) : 0.0;
            }
        } elseif ($type === 'restock_ingredient') {
            $cashBalance += (float) ($meta['total_cost'] ?? 0);
            foreach (($meta['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $key = findSnapshotItemKeyByName($ingredients, (string) ($item['ingredient'] ?? ''));
                if ($key === null) {
                    continue;
                }
                $qty = (int) ($item['qty'] ?? 0);
                $newStock = (int) ($ingredients[$key]['stock'] ?? 0);
                $newAvg = (float) ($ingredients[$key]['avg_cost'] ?? ($item['new_avg_cost'] ?? 0));
                $totalCost = (float) ($item['total_cost'] ?? 0);
                $oldStock = max(0, $newStock - $qty);
                $oldValue = max(0, ($newAvg * $newStock) - $totalCost);
                $ingredients[$key]['stock'] = $oldStock;
                $ingredients[$key]['avg_cost'] = $oldStock > 0 ? round($oldValue / $oldStock, 2) : 0.0;
            }
        } elseif ($type === 'purchase') {
            $amount = (float) ($meta['amount'] ?? 0);
            $cashBalance += $amount;
            $nonStockAssets -= $amount;
        } elseif ($type === 'expense' || $type === 'owner_withdrawal') {
            $cashBalance += (float) ($meta['amount'] ?? 0);
        }
    }

    $productStockValue = 0.0;
    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }
        $productStockValue += (float) ($product['stock'] ?? 0) * (float) ($product['avg_cost'] ?? 0);
    }

    $ingredientStockValue = 0.0;
    foreach ($ingredients as $ingredient) {
        if (!is_array($ingredient)) {
            continue;
        }
        $ingredientStockValue += (float) ($ingredient['stock'] ?? 0) * (float) ($ingredient['avg_cost'] ?? 0);
    }

    $stockValue = $productStockValue + $ingredientStockValue;
    $nonStockAssets = max(0, $nonStockAssets);

    return [
        'cash_balance' => $cashBalance,
        'product_stock_value' => $productStockValue,
        'ingredient_stock_value' => $ingredientStockValue,
        'stock_value' => $stockValue,
        'non_stock_assets' => $nonStockAssets,
        'operational_position' => $cashBalance + $stockValue,
        'business_position' => $cashBalance + $stockValue + $nonStockAssets,
        'products' => $products,
        'ingredients' => $ingredients,
    ];
}

function buildStockItemsFromSnapshot(array $snapshot): array
{
    $items = [];

    foreach (($snapshot['products'] ?? []) as $product) {
        if (!is_array($product)) {
            continue;
        }

        $stock = (int) ($product['stock'] ?? 0);
        if ($stock <= 0) {
            continue;
        }

        $avgCost = (float) ($product['avg_cost'] ?? 0);
        $items[] = [
            'name' => (string) ($product['name'] ?? 'Produk'),
            'category' => 'Produk',
            'stock_label' => number_format($stock, 0, ',', '.') . ' unit',
            'avg_cost' => $avgCost,
            'stock_value' => $stock * $avgCost,
        ];
    }

    foreach (($snapshot['ingredients'] ?? []) as $ingredient) {
        if (!is_array($ingredient)) {
            continue;
        }

        $stock = (int) ($ingredient['stock'] ?? 0);
        if ($stock <= 0) {
            continue;
        }

        $avgCost = (float) ($ingredient['avg_cost'] ?? 0);
        $items[] = [
            'name' => (string) ($ingredient['name'] ?? 'Bahan'),
            'category' => 'Bahan',
            'stock_label' => number_format($stock, 0, ',', '.') . ' ' . normalizeIngredientUnit((string) ($ingredient['unit'] ?? 'pcs')),
            'avg_cost' => $avgCost,
            'stock_value' => $stock * $avgCost,
        ];
    }

    usort($items, static function (array $a, array $b): int {
        $categoryOrder = [
            'Produk' => 0,
            'Bahan' => 1,
        ];
        $categoryCompare = ($categoryOrder[(string) ($a['category'] ?? '')] ?? 9) <=> ($categoryOrder[(string) ($b['category'] ?? '')] ?? 9);
        if ($categoryCompare !== 0) {
            return $categoryCompare;
        }

        return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return $items;
}

function buildPeriodReportData(array $data, string $startMonth, string $endMonth): array
{
    $counts = [
        'restock' => 0,
        'restock_ingredient' => 0,
        'update_stock' => 0,
        'purchase' => 0,
        'expense' => 0,
        'owner_withdrawal' => 0,
    ];
    $totals = [
        'actual_cash_in' => 0.0,
        'theoretical_revenue' => 0.0,
        'cost_out' => 0.0,
        'actual_profit' => 0.0,
        'self_payment_diff' => 0.0,
        'restock_cost' => 0.0,
        'asset_purchase' => 0.0,
        'operational_expense' => 0.0,
        'owner_withdrawal' => 0.0,
    ];
    $reportLogs = [];
    $soldItemsMap = [];

    foreach (($data['logs'] ?? []) as $log) {
        $date = (string) ($log['date'] ?? '');
        $logMonth = substr($date, 0, 7);
        if ($logMonth < $startMonth || $logMonth > $endMonth) {
            continue;
        }

        $type = (string) ($log['type'] ?? '');
        $meta = is_array($log['meta'] ?? null) ? $log['meta'] : [];
        if (isset($counts[$type])) {
            $counts[$type]++;
        }

        if ($type === 'update_stock') {
            $totals['actual_cash_in'] += (float) ($meta['actual_cash_in'] ?? 0);
            $totals['theoretical_revenue'] += (float) ($meta['theoretical_revenue'] ?? 0);
            $totals['cost_out'] += (float) ($meta['cost_out'] ?? 0);
            $totals['actual_profit'] += (float) ($meta['actual_profit'] ?? 0);
            $totals['self_payment_diff'] += (float) ($meta['self_payment_diff'] ?? 0);

            foreach (($meta['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $name = trim((string) ($item['product'] ?? ''));
                $qty = (int) ($item['qty_out'] ?? 0);
                if ($name === '' || $qty <= 0) {
                    continue;
                }

                $key = 'product:' . strtolower($name);
                if (!isset($soldItemsMap[$key])) {
                    $soldItemsMap[$key] = [
                        'name' => $name,
                        'category' => 'Produk',
                        'unit' => 'unit',
                        'qty' => 0,
                        'sold_value' => 0.0,
                    ];
                }
                $soldItemsMap[$key]['qty'] += $qty;
                $soldItemsMap[$key]['sold_value'] += (float) ($item['cost_out'] ?? 0);
            }

            foreach (($meta['mixed_menu_items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $name = trim((string) ($item['menu'] ?? ''));
                $qty = (int) ($item['qty_sold'] ?? 0);
                if ($name === '' || $qty <= 0) {
                    continue;
                }

                $key = 'menu:' . strtolower($name);
                if (!isset($soldItemsMap[$key])) {
                    $soldItemsMap[$key] = [
                        'name' => $name,
                        'category' => 'Menu',
                        'unit' => 'porsi',
                        'qty' => 0,
                        'sold_value' => 0.0,
                    ];
                }
                $soldItemsMap[$key]['qty'] += $qty;
                $soldItemsMap[$key]['sold_value'] += (float) ($item['recipe_cost'] ?? 0);
            }
        } elseif ($type === 'restock' || $type === 'restock_ingredient') {
            $totals['restock_cost'] += (float) ($meta['total_cost'] ?? 0);
        } elseif ($type === 'purchase') {
            $totals['asset_purchase'] += (float) ($meta['amount'] ?? 0);
        } elseif ($type === 'expense') {
            $totals['operational_expense'] += (float) ($meta['amount'] ?? 0);
        } elseif ($type === 'owner_withdrawal') {
            $totals['owner_withdrawal'] += (float) ($meta['amount'] ?? 0);
        }

        $reportLogs[] = $log;
    }

    usort($reportLogs, static function (array $a, array $b): int {
        $dateCompare = strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
        if ($dateCompare !== 0) {
            return $dateCompare;
        }
        return strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
    });

    $soldItems = array_values($soldItemsMap);
    usort($soldItems, static function (array $a, array $b): int {
        $qtyCompare = ((int) ($b['qty'] ?? 0)) <=> ((int) ($a['qty'] ?? 0));
        if ($qtyCompare !== 0) {
            return $qtyCompare;
        }

        return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    $previousSnapshot = buildBalanceSnapshotBeforeMonth($data, $startMonth);
    $periodEndSnapshot = buildBalanceSnapshotBeforeMonth($data, nextMonthValue($endMonth));
    $totals['gross_profit'] = $totals['actual_profit'];
    $totals['net_profit'] = $totals['gross_profit'] - $totals['operational_expense'];
    $totals['previous_month_cash_balance'] = (float) ($previousSnapshot['cash_balance'] ?? 0);
    $totals['previous_month_product_stock_value'] = (float) ($previousSnapshot['product_stock_value'] ?? 0);
    $totals['previous_month_ingredient_stock_value'] = (float) ($previousSnapshot['ingredient_stock_value'] ?? 0);
    $totals['previous_month_operational_position'] = (float) ($previousSnapshot['operational_position'] ?? 0);
    $totals['previous_month_non_stock_assets'] = (float) ($previousSnapshot['non_stock_assets'] ?? 0);
    $totals['previous_month_business_position'] = (float) ($previousSnapshot['business_position'] ?? 0);
    $totals['current_cash_balance'] = (float) ($periodEndSnapshot['cash_balance'] ?? 0);
    $totals['current_product_stock_value'] = (float) ($periodEndSnapshot['product_stock_value'] ?? 0);
    $totals['current_ingredient_stock_value'] = (float) ($periodEndSnapshot['ingredient_stock_value'] ?? 0);
    $totals['current_stock_value'] = (float) ($periodEndSnapshot['stock_value'] ?? 0);
    $totals['current_non_stock_assets'] = (float) ($periodEndSnapshot['non_stock_assets'] ?? 0);
    $totals['current_operational_position'] = (float) ($periodEndSnapshot['operational_position'] ?? 0);
    $totals['current_business_position'] = (float) ($periodEndSnapshot['business_position'] ?? 0);

    $currentStockItems = buildStockItemsFromSnapshot($periodEndSnapshot);
    $runningCashBalance = (float) ($previousSnapshot['cash_balance'] ?? 0);
    $timelineLogs = [];
    foreach ($reportLogs as $log) {
        $cashFlow = monthlyReportCashFlowParts($log);
        $runningCashBalance += (float) ($cashFlow['cash_in'] ?? 0);
        $runningCashBalance -= (float) ($cashFlow['cash_out'] ?? 0);

        $timelineLogs[] = [
            'date' => (string) ($log['date'] ?? ''),
            'date_label' => reportDateLabel((string) ($log['date'] ?? '')),
            'type_label' => monthlyReportTypeLabel((string) ($log['type'] ?? '')),
            'detail' => monthlyReportDetailText($log),
            'cash_in' => (float) ($cashFlow['cash_in'] ?? 0),
            'cash_out' => (float) ($cashFlow['cash_out'] ?? 0),
            'balance_after' => $runningCashBalance,
        ];
    }

    return [
        'start_month' => $startMonth,
        'end_month' => $endMonth,
        'period_label' => reportPeriodLabel($startMonth, $endMonth),
        'generated_at' => date('Y-m-d H:i:s'),
        'transaction_count' => count($reportLogs),
        'counts' => $counts,
        'totals' => $totals,
        'sold_items' => $soldItems,
        'asset_items' => getAssetsByItemUntilMonth($data['logs'] ?? [], $endMonth),
        'current_stock_items' => $currentStockItems,
        'timeline_logs' => $timelineLogs,
    ];
}

function pdfSafeText(string $text): string
{
    $text = str_replace(["\r\n", "\r", "\n", "\t"], [' ', ' ', ' ', ' '], $text);
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    $text = trim($text);

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }

    return $text;
}

function pdfEscapeText(string $text): string
{
    $text = pdfSafeText($text);
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace('(', '\(', $text);
    $text = str_replace(')', '\)', $text);
    return $text;
}

function pdfWrapText(string $text, int $maxChars): array
{
    $text = pdfSafeText($text);
    if ($text === '') {
        return ['-'];
    }

    $maxChars = max(12, $maxChars);
    $words = explode(' ', $text);
    $lines = [];
    $current = '';

    foreach ($words as $word) {
        if ($word === '') {
            continue;
        }

        $candidate = $current === '' ? $word : $current . ' ' . $word;
        if (strlen($candidate) <= $maxChars) {
            $current = $candidate;
            continue;
        }

        if ($current !== '') {
            $lines[] = $current;
            $current = '';
        }

        while (strlen($word) > $maxChars) {
            $lines[] = substr($word, 0, $maxChars - 1) . '-';
            $word = substr($word, $maxChars - 1);
        }

        $current = $word;
    }

    if ($current !== '') {
        $lines[] = $current;
    }

    return $lines === [] ? ['-'] : $lines;
}

function pdfTruncateText(string $text, int $maxChars): string
{
    $text = pdfSafeText($text);
    if (strlen($text) <= $maxChars) {
        return $text;
    }
    return rtrim(substr($text, 0, max(1, $maxChars - 3))) . '...';
}

function pdfEstimateTextWidth(string $text, float $fontSize, string $font = 'F1'): float
{
    $text = pdfSafeText($text);
    $factor = 0.52;
    if ($font === 'F2') {
        $factor = 0.56;
    } elseif ($font === 'F3') {
        $factor = 0.60;
    }

    return strlen($text) * $fontSize * $factor;
}

function renderReportPdf(array $report): string
{
    $pageWidth = 595.28;
    $pageHeight = 841.89;
    $marginLeft = 42.0;
    $marginRight = 42.0;
    $bottomMargin = 48.0;
    $contentWidth = $pageWidth - $marginLeft - $marginRight;
    $headerHeight = 110.0;
    $pages = [];
    $commands = [];
    $pageIndex = 0;
    $y = 0.0;

    $inkDark = [0.086, 0.118, 0.192];
    $inkMuted = [0.365, 0.424, 0.514];
    $brandDark = [0.122, 0.169, 0.286];
    $brandAccent = [0.235, 0.420, 0.808];
    $panelFill = [0.978, 0.984, 0.992];
    $panelAltFill = [0.962, 0.972, 0.988];
    $headerFill = [0.929, 0.949, 0.980];
    $border = [0.820, 0.855, 0.898];
    $white = [1.000, 1.000, 1.000];

    $writeLine = function (
        array &$target,
        string $font,
        float $size,
        float $x,
        float $yPos,
        string $text,
        ?array $color = null
    ) use ($inkDark): void {
        $textColor = $color ?? $inkDark;
        $target[] = sprintf(
            "BT %.3F %.3F %.3F rg /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET",
            $textColor[0],
            $textColor[1],
            $textColor[2],
            $font,
            $size,
            $x,
            $yPos,
            pdfEscapeText($text)
        );
    };

    $writeAlignedLine = function (
        array &$target,
        string $font,
        float $size,
        float $x,
        float $yPos,
        float $width,
        string $text,
        string $align = 'left',
        ?array $color = null
    ) use ($writeLine): void {
        $drawX = $x;
        $estimatedWidth = pdfEstimateTextWidth($text, $size, $font);
        if ($align === 'right') {
            $drawX = $x + max(0, $width - $estimatedWidth);
        } elseif ($align === 'center') {
            $drawX = $x + max(0, ($width - $estimatedWidth) / 2);
        }

        $writeLine($target, $font, $size, $drawX, $yPos, $text, $color);
    };

    $renderWrapped = function (
        string $text,
        string $font,
        float $size,
        float $indent,
        float $lineHeight,
        int $maxChars,
        ?array $color = null
    ) use (&$commands, &$y, $marginLeft, $writeLine): void {
        foreach (pdfWrapText($text, $maxChars) as $line) {
            $writeLine($commands, $font, $size, $marginLeft + $indent, $y, $line, $color);
            $y -= $lineHeight;
        }
    };

    $drawRect = static function (
        array &$target,
        float $x,
        float $yPos,
        float $width,
        float $height,
        ?array $fillColor = null,
        ?array $strokeColor = null,
        float $lineWidth = 1.0
    ): void {
        $parts = ['q'];
        if ($fillColor !== null) {
            $parts[] = sprintf('%.3F %.3F %.3F rg', $fillColor[0], $fillColor[1], $fillColor[2]);
        }
        if ($strokeColor !== null) {
            $parts[] = sprintf('%.3F %.3F %.3F RG %.2F w', $strokeColor[0], $strokeColor[1], $strokeColor[2], $lineWidth);
        }
        $parts[] = sprintf('%.2F %.2F %.2F %.2F re', $x, $yPos, $width, $height);
        if ($fillColor !== null && $strokeColor !== null) {
            $parts[] = 'B';
        } elseif ($fillColor !== null) {
            $parts[] = 'f';
        } else {
            $parts[] = 'S';
        }
        $parts[] = 'Q';
        $target[] = implode(' ', $parts);
    };

    $drawLine = static function (
        array &$target,
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        array $strokeColor,
        float $lineWidth = 1.0
    ): void {
        $target[] = sprintf(
            'q %.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S Q',
            $strokeColor[0],
            $strokeColor[1],
            $strokeColor[2],
            $lineWidth,
            $x1,
            $y1,
            $x2,
            $y2
        );
    };

    $openPage = function () use (
        &$pages,
        &$commands,
        &$pageIndex,
        &$y,
        $pageWidth,
        $pageHeight,
        $marginLeft,
        $marginRight,
        $headerHeight,
        $report,
        $drawRect,
        $drawLine,
        $writeLine,
        $writeAlignedLine,
        $brandDark,
        $brandAccent,
        $white,
        $inkMuted,
        $border,
        $panelFill
    ): void {
        if ($commands !== []) {
            $pages[] = implode("\n", $commands);
            $commands = [];
            $pageIndex++;
        }

        $y = $pageHeight - $headerHeight - 20;
        $drawRect($commands, 0, $pageHeight - $headerHeight, $pageWidth, $headerHeight, $brandDark, null);
        $drawRect($commands, $marginLeft, $pageHeight - 58, 150, 24, $panelFill, null);
        $writeLine($commands, 'F2', 10.0, $marginLeft + 12, $pageHeight - 49, 'Laporan Dana Usaha', $brandAccent);
        $writeLine($commands, 'F2', 20.5, $marginLeft, $pageHeight - 76, 'PMK Kota Surabaya', $white);
        $writeLine($commands, 'F1', 10.2, $marginLeft, $pageHeight - 95, 'Periode ' . $report['period_label'], $white);

        $metaBoxWidth = 132.0;
        $metaBoxX = $pageWidth - $marginRight - $metaBoxWidth;
        $drawRect($commands, $metaBoxX, $pageHeight - 88, $metaBoxWidth, 48, [0.188, 0.247, 0.396], [0.311, 0.435, 0.769], 0.8);
        $writeLine($commands, 'F1', 8.4, $metaBoxX + 12, $pageHeight - 58, 'TOTAL TRANSAKSI', [0.854, 0.898, 0.964]);
        $writeLine($commands, 'F2', 18.0, $metaBoxX + 12, $pageHeight - 77, number_format((int) ($report['transaction_count'] ?? 0), 0, ',', '.'), $white);

        $drawLine($commands, $marginLeft, 34, $pageWidth - $marginRight, 34, $border, 0.7);
        $writeLine($commands, 'F1', 8.2, $marginLeft, 20, 'Dokumen laporan dana usaha', $inkMuted);
        $writeAlignedLine($commands, 'F1', 8.2, $pageWidth - $marginRight - 60, 20, 60, 'Halaman ' . ($pageIndex + 1), 'right', $inkMuted);
    };

    $ensureSpace = function (float $heightNeeded) use (&$y, $bottomMargin, $openPage): void {
        if (($y - $heightNeeded) < $bottomMargin) {
            $openPage();
        }
    };

    $renderSectionTitle = function (string $title, string $subtitle = '') use (
        &$commands,
        &$y,
        $ensureSpace,
        $marginLeft,
        $pageWidth,
        $marginRight,
        $writeLine,
        $drawLine,
        $renderWrapped,
        $brandAccent,
        $inkMuted,
        $border
    ): void {
        $ensureSpace($subtitle !== '' ? 52 : 28);
        $writeLine($commands, 'F1', 8.8, $marginLeft, $y, strtoupper($title), $brandAccent);
        $y -= 14;
        if ($subtitle !== '') {
            $renderWrapped($subtitle, 'F1', 9.4, 0, 12, 88, $inkMuted);
            $y -= 2;
        }
        $drawLine($commands, $marginLeft, $y, $pageWidth - $marginRight, $y, $border, 0.7);
        $y -= 12;
    };

    $renderMetricCards = function (array $cards) use (
        &$commands,
        &$y,
        $ensureSpace,
        $marginLeft,
        $contentWidth,
        $drawRect,
        $writeLine,
        $renderWrapped,
        $panelFill,
        $panelAltFill,
        $border,
        $brandAccent,
        $inkMuted
    ): void {
        $columns = 2;
        $gap = 14.0;
        $cardWidth = ($contentWidth - $gap) / $columns;
        $cardHeight = 76.0;
        $rows = array_chunk($cards, $columns);

        foreach ($rows as $rowCards) {
            $ensureSpace($cardHeight + 10);
            $top = $y;
            foreach ($rowCards as $index => $card) {
                $x = $marginLeft + ($index * ($cardWidth + $gap));
                $fill = $index % 2 === 0 ? $panelFill : $panelAltFill;
                $drawRect($commands, $x, $top - $cardHeight, $cardWidth, $cardHeight, $fill, $border, 0.8);
                $drawRect($commands, $x, $top - 4, $cardWidth, 4, $brandAccent, null);
                $writeLine($commands, 'F1', 8.2, $x + 12, $top - 18, strtoupper((string) ($card['label'] ?? 'RINGKASAN')), $inkMuted);
                $writeLine($commands, 'F2', 16.2, $x + 12, $top - 42, (string) ($card['value'] ?? '-'));

                $noteLines = array_slice(pdfWrapText((string) ($card['note'] ?? ''), 34), 0, 2);
                $noteY = $top - 56;
                foreach ($noteLines as $noteLine) {
                    $writeLine($commands, 'F1', 8.4, $x + 12, $noteY, $noteLine, $inkMuted);
                    $noteY -= 10;
                }
            }
            $y = $top - $cardHeight - 12;
        }
    };

    $renderTable = function (
        array $headers,
        array $rows,
        array $widths,
        array $alignments = [],
        array $bodyFonts = [],
        ?callable $rowFillSelector = null,
        array $maxLines = []
    ) use (
        &$commands,
        &$y,
        $marginLeft,
        $bottomMargin,
        $ensureSpace,
        $openPage,
        $drawRect,
        $writeAlignedLine,
        $headerFill,
        $panelFill,
        $panelAltFill,
        $border,
        $inkDark,
        $inkMuted
    ): void {
        $headerHeight = 26.0;
        $defaultFillSelector = static function (int $rowIndex) use ($panelFill, $panelAltFill): array {
            return $rowIndex % 2 === 0 ? $panelFill : $panelAltFill;
        };
        $fillSelector = $rowFillSelector ?? $defaultFillSelector;

        $drawHeader = function () use (&$commands, &$y, $marginLeft, $headers, $widths, $drawRect, $writeAlignedLine, $headerFill, $border): void {
            $top = $y;
            $x = $marginLeft;
            foreach ($headers as $columnIndex => $header) {
                $width = $widths[$columnIndex] ?? 80.0;
                $drawRect($commands, $x, $top - 26, $width, 26, $headerFill, $border, 0.8);
                $writeAlignedLine($commands, 'F2', 9.0, $x + 8, $top - 16, $width - 16, (string) $header, 'left');
                $x += $width;
            }
            $y -= 26;
        };

        $ensureSpace($headerHeight + 24);
        $drawHeader();

        foreach ($rows as $rowIndex => $row) {
            $cellLines = [];
            $rowLineCount = 1;
            foreach ($widths as $columnIndex => $width) {
                $text = (string) ($row[$columnIndex] ?? '-');
                $maxChars = max(8, (int) floor(($width - 16) / 5.3));
                $wrappedLines = pdfWrapText($text, $maxChars);
                $columnMaxLines = max(1, (int) ($maxLines[$columnIndex] ?? 3));
                if (count($wrappedLines) > $columnMaxLines) {
                    $wrappedLines = array_slice($wrappedLines, 0, $columnMaxLines);
                    $wrappedLines[$columnMaxLines - 1] = pdfTruncateText($wrappedLines[$columnMaxLines - 1], $maxChars);
                }
                $cellLines[$columnIndex] = $wrappedLines;
                $rowLineCount = max($rowLineCount, count($wrappedLines));
            }

            $rowHeight = max(24.0, 14.0 + ($rowLineCount * 10.0));
            if (($y - $rowHeight) < $bottomMargin) {
                $openPage();
                $drawHeader();
            }

            $top = $y;
            $x = $marginLeft;
            $rowFill = $fillSelector($rowIndex, $row);
            foreach ($widths as $columnIndex => $width) {
                $drawRect($commands, $x, $top - $rowHeight, $width, $rowHeight, $rowFill, $border, 0.7);
                $font = $bodyFonts[$columnIndex] ?? 'F1';
                $align = $alignments[$columnIndex] ?? 'left';
                $lineY = $top - 15;
                foreach ($cellLines[$columnIndex] as $line) {
                    $textColor = $font === 'F2' ? $inkDark : $inkMuted;
                    $writeAlignedLine($commands, $font, 9.2, $x + 8, $lineY, $width - 16, $line, $align, $textColor);
                    $lineY -= 10;
                }
                $x += $width;
            }
            $y -= $rowHeight;
        }

        $y -= 14;
    };

    $openPage();

    $renderSectionTitle(
        'Ringkasan Eksekutif',
        'Laporan ini merangkum aktivitas penjualan, pengeluaran, posisi saldo, dan aset pembelian pada periode yang dipilih.'
    );
    $renderMetricCards([
        [
            'label' => 'Saldo kas',
            'value' => rupiah((float) ($report['totals']['current_cash_balance'] ?? 0)),
            'note' => 'Posisi saldo kas pada akhir periode laporan.',
        ],
        [
            'label' => 'Kas masuk aktual',
            'value' => rupiah((float) ($report['totals']['actual_cash_in'] ?? 0)),
            'note' => 'Uang aktual yang masuk dari penjualan selama periode laporan.',
        ],
        [
            'label' => 'Modal + biaya operasional',
            'value' => rupiah((float) (($report['totals']['cost_out'] ?? 0) + ($report['totals']['operational_expense'] ?? 0))),
            'note' => 'Total modal item terjual ditambah biaya operasional pada periode laporan.',
        ],
        [
            'label' => 'Laba bersih',
            'value' => rupiah((float) ($report['totals']['net_profit'] ?? 0)),
            'note' => 'Hasil usaha setelah dikurangi biaya operasional periode ini.',
        ],
    ]);

    $renderSectionTitle(
        'Produk Terjual dan Stok Akhir',
        'Ringkasan item yang laku pada periode laporan beserta posisi stok yang masih tersisa pada akhir periode.'
    );
    $salesAndStockMap = [];
    foreach (($report['sold_items'] ?? []) as $soldItem) {
        $rowKey = strtolower(trim((string) ($soldItem['category'] ?? 'Item')) . '|' . trim((string) ($soldItem['name'] ?? 'Item')));
        $salesAndStockMap[$rowKey] = [
            'name' => (string) ($soldItem['name'] ?? 'Item'),
            'category' => (string) ($soldItem['category'] ?? 'Item'),
            'sold_label' => number_format((int) ($soldItem['qty'] ?? 0), 0, ',', '.') . ' ' . (string) ($soldItem['unit'] ?? 'unit'),
            'sold_value' => rupiah((float) ($soldItem['sold_value'] ?? 0)),
            'stock_label' => '-',
            'stock_value' => '-',
        ];
    }

    foreach (($report['current_stock_items'] ?? []) as $stockItem) {
        $rowKey = strtolower(trim((string) ($stockItem['category'] ?? 'Item')) . '|' . trim((string) ($stockItem['name'] ?? 'Item')));
        if (!isset($salesAndStockMap[$rowKey])) {
            $salesAndStockMap[$rowKey] = [
                'name' => (string) ($stockItem['name'] ?? 'Item'),
                'category' => (string) ($stockItem['category'] ?? 'Item'),
                'sold_label' => '-',
                'sold_value' => '-',
                'stock_label' => '-',
                'stock_value' => '-',
            ];
        }

        $salesAndStockMap[$rowKey]['stock_label'] = (string) ($stockItem['stock_label'] ?? '-');
        $salesAndStockMap[$rowKey]['stock_value'] = rupiah((float) ($stockItem['stock_value'] ?? 0));
    }

    $categoryOrder = [
        'Produk' => 0,
        'Menu' => 1,
        'Bahan' => 2,
    ];
    uasort($salesAndStockMap, static function (array $a, array $b) use ($categoryOrder): int {
        $categoryCompare = ($categoryOrder[(string) ($a['category'] ?? '')] ?? 9) <=> ($categoryOrder[(string) ($b['category'] ?? '')] ?? 9);
        if ($categoryCompare !== 0) {
            return $categoryCompare;
        }

        return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    $salesAndStockRows = [];
    foreach ($salesAndStockMap as $row) {
        $salesAndStockRows[] = [
            (string) ($row['name'] ?? 'Item'),
            (string) ($row['category'] ?? '-'),
            (string) ($row['sold_label'] ?? '-'),
            (string) ($row['sold_value'] ?? '-'),
            (string) ($row['stock_label'] ?? '-'),
            (string) ($row['stock_value'] ?? '-'),
        ];
    }
    if ($salesAndStockRows === []) {
        $salesAndStockRows[] = ['Belum ada data penjualan atau stok pada periode ini.', '-', '-', '-', '-', '-'];
    }
    $renderTable(
        ['Item', 'Kategori', 'Laku di periode', 'Nilai modal', 'Stok akhir', 'Nilai stok akhir'],
        $salesAndStockRows,
        [142.0, 60.0, 78.0, 88.0, 67.0, 76.0],
        ['left', 'left', 'right', 'right', 'right', 'right'],
        ['F1', 'F1', 'F2', 'F2', 'F2', 'F2']
    );

    $renderSectionTitle(
        'Ringkasan Keuangan',
        'Ikhtisar nilai keuangan utama yang tercatat untuk periode laporan.'
    );
    $financeRows = [
        ['Total uang yang didapat', rupiah((float) ($report['totals']['actual_cash_in'] ?? 0))],
        ['Total modal item terjual', rupiah((float) ($report['totals']['cost_out'] ?? 0))],
        ['Keuntungan kotor', rupiah((float) ($report['totals']['gross_profit'] ?? 0))],
        ['Biaya operasional', rupiah((float) ($report['totals']['operational_expense'] ?? 0))],
        ['Keuntungan bersih', rupiah((float) ($report['totals']['net_profit'] ?? 0))],
        ['Penarikan PMK Kota', rupiah((float) ($report['totals']['owner_withdrawal'] ?? 0))],
        ['Pembelian aset non stok', rupiah((float) ($report['totals']['asset_purchase'] ?? 0))],
    ];
    $renderTable(
        ['Komponen', 'Nilai'],
        $financeRows,
        [361.0, 150.0],
        ['left', 'right'],
        ['F1', 'F2'],
        static function (int $rowIndex, array $row) use ($panelFill, $panelAltFill, $headerFill): array {
            if ((string) ($row[0] ?? '') === 'Keuntungan bersih') {
                return $headerFill;
            }
            return $rowIndex % 2 === 0 ? $panelFill : $panelAltFill;
        }
    );

    $renderSectionTitle(
        'Perbandingan Saldo',
        'Perbandingan posisi saldo sebelum periode dimulai dengan posisi pada akhir periode laporan.'
    );
    $balanceRows = [
        ['Saldo kas', rupiah((float) ($report['totals']['previous_month_cash_balance'] ?? 0)), rupiah((float) ($report['totals']['current_cash_balance'] ?? 0))],
        ['Nilai stok produk', rupiah((float) ($report['totals']['previous_month_product_stock_value'] ?? 0)), rupiah((float) ($report['totals']['current_product_stock_value'] ?? 0))],
        ['Nilai stok bahan', rupiah((float) ($report['totals']['previous_month_ingredient_stock_value'] ?? 0)), rupiah((float) ($report['totals']['current_ingredient_stock_value'] ?? 0))],
        ['Posisi operasional', rupiah((float) ($report['totals']['previous_month_operational_position'] ?? 0)), rupiah((float) ($report['totals']['current_operational_position'] ?? 0))],
        ['Total aset non stok', rupiah((float) ($report['totals']['previous_month_non_stock_assets'] ?? 0)), rupiah((float) ($report['totals']['current_non_stock_assets'] ?? 0))],
        ['Posisi usaha + aset', rupiah((float) ($report['totals']['previous_month_business_position'] ?? 0)), rupiah((float) ($report['totals']['current_business_position'] ?? 0))],
    ];
    $renderTable(
        ['Komponen', 'Sebelum periode', 'Akhir periode'],
        $balanceRows,
        [241.0, 135.0, 135.0],
        ['left', 'right', 'right'],
        ['F1', 'F1', 'F2']
    );

    $renderSectionTitle(
        'Aset Pembelian',
        'Daftar aset non stok yang sudah tercatat sampai akhir periode laporan.'
    );
    $assetRows = [];
    foreach (($report['asset_items'] ?? []) as $assetName => $assetAmount) {
        $assetRows[] = [(string) $assetName, rupiah((float) $assetAmount)];
    }
    if ($assetRows === []) {
        $assetRows[] = ['Belum ada aset pembelian yang tercatat sampai akhir periode ini.', '-'];
    }
    $renderTable(
        ['Nama Aset', 'Nilai Pembelian'],
        $assetRows,
        [361.0, 150.0],
        ['left', 'right'],
        ['F1', 'F2']
    );

    $renderSectionTitle(
        'Log Periode',
        'Seluruh log pada periode laporan berikut arus kas masuk, arus kas keluar, dan posisi saldo kas setelah setiap transaksi.'
    );
    $timelineRows = [[
        '-',
        'Saldo awal periode',
        'Posisi saldo kas sebelum log pertama pada periode ini.',
        '-',
        '-',
        rupiah((float) ($report['totals']['previous_month_cash_balance'] ?? 0)),
    ]];
    foreach (($report['timeline_logs'] ?? []) as $timelineLog) {
        $timelineRows[] = [
            (string) ($timelineLog['date_label'] ?? '-'),
            (string) ($timelineLog['type_label'] ?? 'Aktivitas'),
            (string) ($timelineLog['detail'] ?? '-'),
            (float) ($timelineLog['cash_in'] ?? 0) > 0 ? rupiah((float) ($timelineLog['cash_in'] ?? 0)) : '-',
            (float) ($timelineLog['cash_out'] ?? 0) > 0 ? rupiah((float) ($timelineLog['cash_out'] ?? 0)) : '-',
            rupiah((float) ($timelineLog['balance_after'] ?? 0)),
        ];
    }
    if (count($timelineRows) === 1) {
        $timelineRows[] = ['-', 'Belum ada log periode ini', 'Tidak ada transaksi yang tercatat pada rentang bulan terpilih.', '-', '-', rupiah((float) ($report['totals']['previous_month_cash_balance'] ?? 0))];
    }
    $renderTable(
        ['Tanggal', 'Aktivitas', 'Rincian', 'Masuk', 'Keluar', 'Saldo Kas'],
        $timelineRows,
        [62.0, 88.0, 171.0, 58.0, 58.0, 74.0],
        ['left', 'left', 'left', 'right', 'right', 'right'],
        ['F1', 'F2', 'F1', 'F1', 'F1', 'F2'],
        static function (int $rowIndex, array $row) use ($panelFill, $panelAltFill, $headerFill): array {
            if ((string) ($row[1] ?? '') === 'Saldo awal periode') {
                return $headerFill;
            }
            return $rowIndex % 2 === 0 ? $panelFill : $panelAltFill;
        },
        [1 => 2, 2 => 6]
    );

    if ($commands !== []) {
        $pages[] = implode("\n", $commands);
    }

    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = '';
    $pageKids = [];
    $pageCount = count($pages);
    $firstPageObjectId = 3;
    $firstContentObjectId = $firstPageObjectId + $pageCount;

    for ($i = 0; $i < $pageCount; $i++) {
        $pageObjectId = $firstPageObjectId + $i;
        $contentObjectId = $firstContentObjectId + $i;
        $pageKids[] = $pageObjectId . ' 0 R';
        $objects[] = sprintf(
            "<< /Type /PagesPlaceholder /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R /F3 %d 0 R >> >> /Contents %d 0 R >>",
            $pageWidth,
            $pageHeight,
            $firstContentObjectId + $pageCount,
            $firstContentObjectId + $pageCount + 1,
            $firstContentObjectId + $pageCount + 2,
            $contentObjectId
        );
    }

    $objects[1] = "<< /Type /Pages /Kids [" . implode(' ', $pageKids) . "] /Count " . $pageCount . " >>";

    for ($i = 0; $i < $pageCount; $i++) {
        $stream = $pages[$i];
        $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
    }

    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $objectBody) {
        $offsets[] = strlen($pdf);
        $objectId = $index + 1;
        if ($objectId >= $firstPageObjectId && $objectId < $firstContentObjectId) {
            $objectBody = str_replace('/Type /PagesPlaceholder', '/Type /Page', $objectBody);
        }
        $pdf .= $objectId . " 0 obj\n" . $objectBody . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

    return $pdf;
}

function buildPriceHistoryEntry(
    float $price,
    ?float $previousPrice,
    string $effectiveDate,
    string $recordedAt,
    string $note = ''
): array {
    return [
        'effective_date' => $effectiveDate,
        'price' => round(max(0, $price), 2),
        'previous_price' => $previousPrice === null ? null : round(max(0, $previousPrice), 2),
        'recorded_at' => $recordedAt,
        'note' => trim($note),
    ];
}

function initialPriceHistory(float $price, ?string $effectiveDate = null, string $note = 'Harga awal sistem'): array
{
    $effectiveDate = $effectiveDate === null
        ? BASELINE_PRICE_DATE
        : normalizeDateValue($effectiveDate, BASELINE_PRICE_DATE);
    $recordedAt = $effectiveDate === BASELINE_PRICE_DATE
        ? BASELINE_PRICE_TIMESTAMP
        : $effectiveDate . ' 00:00:00';

    return [
        buildPriceHistoryEntry($price, null, $effectiveDate, $recordedAt, $note),
    ];
}

function comparePriceHistoryEntries(array $a, array $b): int
{
    $dateCompare = strcmp((string) ($a['effective_date'] ?? ''), (string) ($b['effective_date'] ?? ''));
    if ($dateCompare !== 0) {
        return $dateCompare;
    }
    return strcmp((string) ($a['recorded_at'] ?? ''), (string) ($b['recorded_at'] ?? ''));
}

function normalizePriceHistory(array $history, float $fallbackPrice): array
{
    $normalized = [];
    foreach ($history as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $effectiveDate = normalizeDateValue((string) ($entry['effective_date'] ?? ''), BASELINE_PRICE_DATE);
        $recordedAtDefault = $effectiveDate === BASELINE_PRICE_DATE
            ? BASELINE_PRICE_TIMESTAMP
            : $effectiveDate . ' 00:00:00';
        $previousPrice = null;
        if (array_key_exists('previous_price', $entry) && $entry['previous_price'] !== null && $entry['previous_price'] !== '') {
            $previousPrice = max(0, (float) $entry['previous_price']);
        }

        $normalized[] = buildPriceHistoryEntry(
            (float) ($entry['price'] ?? $fallbackPrice),
            $previousPrice,
            $effectiveDate,
            normalizeDateTimeValue((string) ($entry['recorded_at'] ?? ''), $recordedAtDefault),
            (string) ($entry['note'] ?? '')
        );
    }

    if ($normalized === []) {
        return initialPriceHistory($fallbackPrice);
    }

    usort($normalized, 'comparePriceHistoryEntries');
    return $normalized;
}

function getCurrentPriceEntry(array $product): array
{
    $history = $product['price_history'] ?? [];
    if (!is_array($history) || $history === []) {
        return initialPriceHistory((float) ($product['sell_price'] ?? 0))[0];
    }
    return $history[array_key_last($history)];
}

function currentSellPrice(array $product): float
{
    return (float) (getCurrentPriceEntry($product)['price'] ?? 0);
}

function getSellPriceForDate(array $product, string $date): array
{
    $history = $product['price_history'] ?? [];
    if (!is_array($history) || $history === []) {
        return [
            'price' => max(0, (float) ($product['sell_price'] ?? 0)),
            'effective_date' => BASELINE_PRICE_DATE,
        ];
    }

    $targetDate = normalizeDateValue($date, date('Y-m-d'));
    $selected = $history[0];
    foreach ($history as $entry) {
        if (($entry['effective_date'] ?? BASELINE_PRICE_DATE) <= $targetDate) {
            $selected = $entry;
            continue;
        }
        break;
    }

    return [
        'price' => (float) ($selected['price'] ?? 0),
        'effective_date' => (string) ($selected['effective_date'] ?? BASELINE_PRICE_DATE),
        'previous_price' => $selected['previous_price'] ?? null,
    ];
}

function appendProductPriceHistory(array &$product, float $newPrice, string $effectiveDate, string $note = ''): void
{
    $history = $product['price_history'] ?? [];
    if (!is_array($history)) {
        $history = [];
    }

    $history[] = buildPriceHistoryEntry(
        $newPrice,
        currentSellPrice($product),
        normalizeDateValue($effectiveDate, date('Y-m-d')),
        date('Y-m-d H:i:s'),
        $note
    );

    $product['price_history'] = normalizePriceHistory($history, $newPrice);
    $product['sell_price'] = currentSellPrice($product);
}

function normalizeProducts(array $products, bool $archived = false): array
{
    $normalized = [];
    foreach ($products as $key => $product) {
        if (!is_string($key) || $key === '' || !is_array($product)) {
            continue;
        }

        $name = trim((string) ($product['name'] ?? ''));
        $sellPrice = max(0, (float) ($product['sell_price'] ?? 0));
        $priceHistory = normalizePriceHistory(
            is_array($product['price_history'] ?? null) ? $product['price_history'] : [],
            $sellPrice
        );

        $normalizedProduct = [
            'name' => $name !== '' ? $name : ucwords(str_replace('_', ' ', $key)),
            'sell_price' => (float) ($priceHistory[array_key_last($priceHistory)]['price'] ?? $sellPrice),
            'stock' => max(0, (int) ($product['stock'] ?? 0)),
            'avg_cost' => max(0, (float) ($product['avg_cost'] ?? 0)),
            'price_history' => $priceHistory,
        ];

        if ($archived) {
            $normalizedProduct['deleted_at'] = normalizeDateTimeValue((string) ($product['deleted_at'] ?? ''), '');
            $normalizedProduct['original_key'] = trim((string) ($product['original_key'] ?? $key));
        }

        $normalized[$key] = $normalizedProduct;
    }
    return $normalized;
}

function normalizeIngredientUnit(string $unit): string
{
    $unit = trim($unit);
    return $unit !== '' ? $unit : 'pcs';
}

function normalizeIngredients(array $ingredients, bool $archived = false): array
{
    $normalized = [];
    foreach ($ingredients as $key => $ingredient) {
        if (!is_string($key) || $key === '' || !is_array($ingredient)) {
            continue;
        }

        $name = trim((string) ($ingredient['name'] ?? ''));
        $normalizedIngredient = [
            'name' => $name !== '' ? $name : ucwords(str_replace('_', ' ', $key)),
            'unit' => normalizeIngredientUnit((string) ($ingredient['unit'] ?? 'pcs')),
            'stock' => max(0, (int) ($ingredient['stock'] ?? 0)),
            'avg_cost' => max(0, (float) ($ingredient['avg_cost'] ?? 0)),
        ];

        if ($archived) {
            $normalizedIngredient['deleted_at'] = normalizeDateTimeValue((string) ($ingredient['deleted_at'] ?? ''), '');
            $normalizedIngredient['original_key'] = trim((string) ($ingredient['original_key'] ?? $key));
        }

        $normalized[$key] = $normalizedIngredient;
    }

    return $normalized;
}

function normalizeMenuRecipe(array $recipe): array
{
    $normalized = [];
    foreach ($recipe as $item) {
        if (!is_array($item)) {
            continue;
        }

        $ingredientKey = trim((string) ($item['ingredient_key'] ?? ''));
        $qty = max(0, (int) ($item['qty'] ?? 0));
        if ($ingredientKey === '' || $qty <= 0) {
            continue;
        }

        if (!isset($normalized[$ingredientKey])) {
            $normalized[$ingredientKey] = [
                'ingredient_key' => $ingredientKey,
                'qty' => 0,
            ];
        }
        $normalized[$ingredientKey]['qty'] += $qty;
    }

    return array_values($normalized);
}

function normalizeMixedMenus(array $menus, bool $archived = false): array
{
    $normalized = [];
    foreach ($menus as $key => $menu) {
        if (!is_string($key) || $key === '' || !is_array($menu)) {
            continue;
        }

        $name = trim((string) ($menu['name'] ?? ''));
        $sellPrice = max(0, (float) ($menu['sell_price'] ?? 0));
        $priceHistory = normalizePriceHistory(
            is_array($menu['price_history'] ?? null) ? $menu['price_history'] : [],
            $sellPrice
        );

        $normalizedMenu = [
            'name' => $name !== '' ? $name : ucwords(str_replace('_', ' ', $key)),
            'sell_price' => (float) ($priceHistory[array_key_last($priceHistory)]['price'] ?? $sellPrice),
            'price_history' => $priceHistory,
            'recipe' => normalizeMenuRecipe(is_array($menu['recipe'] ?? null) ? $menu['recipe'] : []),
        ];

        if ($archived) {
            $normalizedMenu['deleted_at'] = normalizeDateTimeValue((string) ($menu['deleted_at'] ?? ''), '');
            $normalizedMenu['original_key'] = trim((string) ($menu['original_key'] ?? $key));
        }

        $normalized[$key] = $normalizedMenu;
    }

    return $normalized;
}

function makeProductKey(string $name): string
{
    $key = strtolower(trim($name));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';
    $key = trim($key, '_');
    return $key !== '' ? $key : 'product';
}

function productNameExists(array $products, string $name): bool
{
    $needle = strtolower(trim($name));
    foreach ($products as $item) {
        $existingName = strtolower(trim((string) ($item['name'] ?? '')));
        if ($existingName === $needle) {
            return true;
        }
    }
    return false;
}

function ingredientNameExists(array $ingredients, string $name): bool
{
    return productNameExists($ingredients, $name);
}

function mixedMenuNameExists(array $menus, string $name): bool
{
    return productNameExists($menus, $name);
}

function nextProductKey(array $products, string $name): string
{
    $baseKey = makeProductKey($name);
    $candidate = $baseKey;
    $suffix = 2;
    while (isset($products[$candidate])) {
        $candidate = $baseKey . '_' . $suffix;
        $suffix++;
    }
    return $candidate;
}

function nextArchivedProductKey(array $archivedProducts, string $productKey): string
{
    $baseKey = $productKey . '__deleted_' . date('YmdHis');
    $candidate = $baseKey;
    $suffix = 2;
    while (isset($archivedProducts[$candidate])) {
        $candidate = $baseKey . '_' . $suffix;
        $suffix++;
    }
    return $candidate;
}

function nextIngredientKey(array $ingredients, string $name): string
{
    return nextProductKey($ingredients, $name);
}

function nextArchivedIngredientKey(array $archivedIngredients, string $ingredientKey): string
{
    return nextArchivedProductKey($archivedIngredients, $ingredientKey);
}

function nextMixedMenuKey(array $menus, string $name): string
{
    return nextProductKey($menus, $name);
}

function nextArchivedMixedMenuKey(array $archivedMenus, string $menuKey): string
{
    return nextArchivedProductKey($archivedMenus, $menuKey);
}

function parseRecipeFromPost(array $ingredients): array
{
    $ingredientKeys = $_POST['recipe_ingredient'] ?? [];
    $qtyValues = $_POST['recipe_qty'] ?? [];

    if (!is_array($ingredientKeys) || !is_array($qtyValues)) {
        throw new RuntimeException('Format resep menu tidak valid.');
    }

    $recipe = [];
    $rowCount = max(count($ingredientKeys), count($qtyValues));
    for ($index = 0; $index < $rowCount; $index++) {
        $ingredientKey = trim((string) ($ingredientKeys[$index] ?? ''));
        $qtyRaw = $qtyValues[$index] ?? 0;
        if (is_array($qtyRaw)) {
            $qtyRaw = 0;
        }
        $qtyString = str_replace([' ', ','], ['', ''], (string) $qtyRaw);
        $qty = is_numeric($qtyString) ? (int) $qtyString : 0;

        if ($ingredientKey === '' && $qty <= 0) {
            continue;
        }
        if ($ingredientKey === '') {
            throw new RuntimeException('Masih ada baris resep yang belum memilih bahan.');
        }
        if (!isset($ingredients[$ingredientKey])) {
            throw new RuntimeException('Bahan resep tidak ditemukan.');
        }
        if ($qty <= 0) {
            throw new RuntimeException('Qty bahan resep harus lebih dari 0.');
        }

        if (!isset($recipe[$ingredientKey])) {
            $recipe[$ingredientKey] = [
                'ingredient_key' => $ingredientKey,
                'qty' => 0,
            ];
        }
        $recipe[$ingredientKey]['qty'] += $qty;
    }

    if ($recipe === []) {
        throw new RuntimeException('Resep menu harus memiliki minimal satu bahan.');
    }

    return array_values($recipe);
}

function ingredientUsedByMixedMenus(array $mixedMenus, string $ingredientKey): bool
{
    foreach ($mixedMenus as $menu) {
        foreach (($menu['recipe'] ?? []) as $recipeItem) {
            if (($recipeItem['ingredient_key'] ?? '') === $ingredientKey) {
                return true;
            }
        }
    }

    return false;
}

function buildIngredientCatalog(array $ingredients, array $archivedIngredients = []): array
{
    return $ingredients + $archivedIngredients;
}

function ingredientDisplayName(array $ingredientCatalog, string $ingredientKey): string
{
    if (isset($ingredientCatalog[$ingredientKey]['name'])) {
        return (string) $ingredientCatalog[$ingredientKey]['name'];
    }

    return ucwords(str_replace('_', ' ', $ingredientKey));
}

function ingredientDisplayUnit(array $ingredientCatalog, string $ingredientKey): string
{
    return normalizeIngredientUnit((string) ($ingredientCatalog[$ingredientKey]['unit'] ?? 'pcs'));
}

function describeRecipe(array $recipe, array $ingredientCatalog): string
{
    $parts = [];
    foreach ($recipe as $recipeItem) {
        $ingredientKey = (string) ($recipeItem['ingredient_key'] ?? '');
        $qty = (int) ($recipeItem['qty'] ?? 0);
        if ($ingredientKey === '' || $qty <= 0) {
            continue;
        }

        $parts[] = $qty . ' x ' . ingredientDisplayName($ingredientCatalog, $ingredientKey);
    }

    return $parts !== [] ? implode(' | ', $parts) : 'Belum ada resep.';
}

function getMixedMenuCurrentCost(array $menu, array $ingredients): float
{
    $total = 0;
    foreach (($menu['recipe'] ?? []) as $recipeItem) {
        $ingredientKey = (string) ($recipeItem['ingredient_key'] ?? '');
        if (!isset($ingredients[$ingredientKey])) {
            continue;
        }

        $total += (int) ($recipeItem['qty'] ?? 0) * (float) $ingredients[$ingredientKey]['avg_cost'];
    }

    return $total;
}

function getMixedMenuAvailableServings(array $menu, array $ingredients): int
{
    $recipe = $menu['recipe'] ?? [];
    if (!is_array($recipe) || $recipe === []) {
        return 0;
    }

    $maxServings = null;
    foreach ($recipe as $recipeItem) {
        $ingredientKey = (string) ($recipeItem['ingredient_key'] ?? '');
        $qtyPerMenu = max(0, (int) ($recipeItem['qty'] ?? 0));
        if ($ingredientKey === '' || $qtyPerMenu <= 0 || !isset($ingredients[$ingredientKey])) {
            return 0;
        }

        $possibleServings = intdiv((int) $ingredients[$ingredientKey]['stock'], $qtyPerMenu);
        $maxServings = $maxServings === null ? $possibleServings : min($maxServings, $possibleServings);
    }

    return $maxServings ?? 0;
}

$data = loadData();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = postText('action');
        $date = postText('date', date('Y-m-d')) ?: date('Y-m-d');

        if ($action === 'add_product') {
            $productName = postText('product_name');
            $sellPrice = postNum('sell_price');
            $today = date('Y-m-d');

            if ($productName === '') {
                throw new RuntimeException('Nama produk harus diisi.');
            }
            if ($sellPrice < 0) {
                throw new RuntimeException('Harga jual tidak boleh negatif.');
            }
            if (productNameExists($data['settings']['products'], $productName)) {
                throw new RuntimeException('Nama produk sudah ada. Gunakan nama lain.');
            }

            $productKey = nextProductKey($data['settings']['products'], $productName);
            $data['settings']['products'][$productKey] = [
                'name' => $productName,
                'sell_price' => round($sellPrice, 2),
                'stock' => 0,
                'avg_cost' => 0,
                'price_history' => initialPriceHistory($sellPrice, $today, 'Produk ditambahkan'),
            ];

            saveData($data);
            $message = 'Produk berhasil ditambahkan.';
        } elseif ($action === 'update_product_price') {
            $productKey = postText('product_key');
            $sellPrice = postNum('sell_price');
            $today = date('Y-m-d');

            if (!isset($data['settings']['products'][$productKey])) {
                throw new RuntimeException('Produk tidak ditemukan.');
            }
            if ($sellPrice < 0) {
                throw new RuntimeException('Harga jual tidak boleh negatif.');
            }

            $product = &$data['settings']['products'][$productKey];
            $currentPrice = currentSellPrice($product);
            if (round($currentPrice, 2) === round($sellPrice, 2)) {
                $message = 'Harga jual ' . $product['name'] . ' tidak berubah.';
            } else {
                appendProductPriceHistory($product, $sellPrice, $today, 'Perubahan harga jual');
                saveData($data);
                $message = 'Harga jual ' . $product['name'] . ' diperbarui dan berlaku mulai ' . $today . '.';
            }
            unset($product);
        } elseif ($action === 'delete_product') {
            $productKey = postText('product_key');

            if (!isset($data['settings']['products'][$productKey])) {
                throw new RuntimeException('Produk tidak ditemukan.');
            }

            $product = $data['settings']['products'][$productKey];
            if ((int) ($product['stock'] ?? 0) > 0) {
                throw new RuntimeException('Produk masih memiliki stok. Habiskan atau nolkan stoknya dulu sebelum dihapus.');
            }

            $archiveKey = nextArchivedProductKey($data['settings']['archived_products'], $productKey);
            $product['deleted_at'] = date('Y-m-d H:i:s');
            $product['original_key'] = $productKey;
            $data['settings']['archived_products'][$archiveKey] = $product;
            unset($data['settings']['products'][$productKey]);
            saveData($data);
            $message = 'Produk berhasil dihapus dan dipindahkan ke arsip.';
        } elseif ($action === 'add_ingredient') {
            $ingredientName = postText('ingredient_name');
            $ingredientUnit = normalizeIngredientUnit(postText('ingredient_unit', 'pcs'));

            if ($ingredientName === '') {
                throw new RuntimeException('Nama bahan harus diisi.');
            }
            if (ingredientNameExists($data['settings']['ingredients'], $ingredientName)) {
                throw new RuntimeException('Nama bahan sudah ada. Gunakan nama lain.');
            }

            $ingredientKey = nextIngredientKey($data['settings']['ingredients'], $ingredientName);
            $data['settings']['ingredients'][$ingredientKey] = [
                'name' => $ingredientName,
                'unit' => $ingredientUnit,
                'stock' => 0,
                'avg_cost' => 0,
            ];

            saveData($data);
            $message = 'Bahan racikan berhasil ditambahkan.';
        } elseif ($action === 'delete_ingredient') {
            $ingredientKey = postText('ingredient_key');

            if (!isset($data['settings']['ingredients'][$ingredientKey])) {
                throw new RuntimeException('Bahan racikan tidak ditemukan.');
            }

            $ingredient = $data['settings']['ingredients'][$ingredientKey];
            if ((int) ($ingredient['stock'] ?? 0) > 0) {
                throw new RuntimeException('Bahan masih memiliki stok. Habiskan atau nolkan stoknya dulu sebelum dihapus.');
            }
            if (ingredientUsedByMixedMenus($data['settings']['mixed_menus'], $ingredientKey)) {
                throw new RuntimeException('Bahan masih dipakai oleh menu racikan aktif. Arsipkan atau ubah menu yang memakainya dulu.');
            }

            $archiveKey = nextArchivedIngredientKey($data['settings']['archived_ingredients'], $ingredientKey);
            $ingredient['deleted_at'] = date('Y-m-d H:i:s');
            $ingredient['original_key'] = $ingredientKey;
            $data['settings']['archived_ingredients'][$archiveKey] = $ingredient;
            unset($data['settings']['ingredients'][$ingredientKey]);

            saveData($data);
            $message = 'Bahan racikan berhasil diarsipkan.';
        } elseif ($action === 'add_mixed_menu') {
            $menuName = postText('menu_name');
            $sellPrice = postNum('sell_price');
            $today = date('Y-m-d');

            if ($menuName === '') {
                throw new RuntimeException('Nama menu racikan harus diisi.');
            }
            if ($sellPrice < 0) {
                throw new RuntimeException('Harga jual menu tidak boleh negatif.');
            }
            if ($data['settings']['ingredients'] === []) {
                throw new RuntimeException('Tambahkan bahan racikan dulu sebelum membuat menu.');
            }
            if (mixedMenuNameExists($data['settings']['mixed_menus'], $menuName)) {
                throw new RuntimeException('Nama menu racikan sudah ada. Gunakan nama lain.');
            }

            $recipe = parseRecipeFromPost($data['settings']['ingredients']);
            $menuKey = nextMixedMenuKey($data['settings']['mixed_menus'], $menuName);
            $data['settings']['mixed_menus'][$menuKey] = [
                'name' => $menuName,
                'sell_price' => round($sellPrice, 2),
                'price_history' => initialPriceHistory($sellPrice, $today, 'Menu racikan ditambahkan'),
                'recipe' => $recipe,
            ];

            saveData($data);
            $message = 'Menu racikan berhasil ditambahkan.';
        } elseif ($action === 'update_mixed_menu_price') {
            $menuKey = postText('menu_key');
            $sellPrice = postNum('sell_price');
            $today = date('Y-m-d');

            if (!isset($data['settings']['mixed_menus'][$menuKey])) {
                throw new RuntimeException('Menu racikan tidak ditemukan.');
            }
            if ($sellPrice < 0) {
                throw new RuntimeException('Harga jual menu tidak boleh negatif.');
            }

            $menu = &$data['settings']['mixed_menus'][$menuKey];
            $currentPrice = currentSellPrice($menu);
            if (round($currentPrice, 2) === round($sellPrice, 2)) {
                $message = 'Harga jual ' . $menu['name'] . ' tidak berubah.';
            } else {
                appendProductPriceHistory($menu, $sellPrice, $today, 'Perubahan harga jual menu racikan');
                saveData($data);
                $message = 'Harga jual ' . $menu['name'] . ' diperbarui dan berlaku mulai ' . $today . '.';
            }
            unset($menu);
        } elseif ($action === 'delete_mixed_menu') {
            $menuKey = postText('menu_key');

            if (!isset($data['settings']['mixed_menus'][$menuKey])) {
                throw new RuntimeException('Menu racikan tidak ditemukan.');
            }

            $menu = $data['settings']['mixed_menus'][$menuKey];
            $archiveKey = nextArchivedMixedMenuKey($data['settings']['archived_mixed_menus'], $menuKey);
            $menu['deleted_at'] = date('Y-m-d H:i:s');
            $menu['original_key'] = $menuKey;
            $data['settings']['archived_mixed_menus'][$archiveKey] = $menu;
            unset($data['settings']['mixed_menus'][$menuKey]);

            saveData($data);
            $message = 'Menu racikan berhasil diarsipkan.';
        } elseif ($action === 'restock') {
            $productKey = postText('product_key');
            $qty = (int) postNum('qty');
            $totalCost = postNum('total_cost');
            $note = postText('note');

            if (!isset($data['settings']['products'][$productKey])) {
                throw new RuntimeException('Produk tidak valid.');
            }
            if ($qty <= 0) {
                throw new RuntimeException('Jumlah item restock harus lebih dari 0.');
            }
            if ($totalCost < 0) {
                throw new RuntimeException('Total modal belanja tidak boleh negatif.');
            }

            $product = &$data['settings']['products'][$productKey];
            $oldStock = (int) $product['stock'];
            $oldAvgCost = (float) $product['avg_cost'];
            $oldValue = $oldStock * $oldAvgCost;
            $unitCost = $totalCost / $qty;
            $newStock = $oldStock + $qty;
            $newAvgCost = $newStock > 0 ? (($oldValue + $totalCost) / $newStock) : 0;

            $product['stock'] = $newStock;
            $product['avg_cost'] = round($newAvgCost, 2);

            $data['summary']['cash_balance'] -= $totalCost;
            $data['summary']['total_restock_cost'] += $totalCost;

            addLog($data, 'restock', $date, 'Restock ' . $product['name'], [
                'product' => $product['name'],
                'qty' => $qty,
                'total_cost' => $totalCost,
                'unit_cost' => round($unitCost, 2),
                'new_avg_cost' => round($newAvgCost, 2),
                'note' => $note,
            ]);
            unset($product);

            saveData($data);
            $message = 'Restock berhasil disimpan.';
        } elseif ($action === 'restock_ingredients') {
            $note = postText('note');
            $items = [];
            $totalCost = 0;

            foreach ($data['settings']['ingredients'] as $ingredientKey => &$ingredient) {
                $qty = (int) postNum('ingredient_qty_' . $ingredientKey, 0);
                $lineCost = postNum('ingredient_cost_' . $ingredientKey, 0);

                if ($qty <= 0 && $lineCost <= 0) {
                    continue;
                }
                if ($qty <= 0) {
                    throw new RuntimeException('Qty untuk bahan ' . $ingredient['name'] . ' harus lebih dari 0 jika ada modal yang dicatat.');
                }
                if ($lineCost < 0) {
                    throw new RuntimeException('Total modal bahan ' . $ingredient['name'] . ' tidak boleh negatif.');
                }

                $oldStock = (int) $ingredient['stock'];
                $oldAvgCost = (float) $ingredient['avg_cost'];
                $oldValue = $oldStock * $oldAvgCost;
                $unitCost = $qty > 0 ? ($lineCost / $qty) : 0;
                $newStock = $oldStock + $qty;
                $newAvgCost = $newStock > 0 ? (($oldValue + $lineCost) / $newStock) : 0;

                $ingredient['stock'] = $newStock;
                $ingredient['avg_cost'] = round($newAvgCost, 2);

                $items[] = [
                    'ingredient' => $ingredient['name'],
                    'unit' => $ingredient['unit'],
                    'previous_stock' => $oldStock,
                    'qty' => $qty,
                    'total_cost' => round($lineCost, 2),
                    'unit_cost' => round($unitCost, 2),
                    'new_avg_cost' => round($newAvgCost, 2),
                ];
                $totalCost += $lineCost;
            }
            unset($ingredient);

            if ($items === []) {
                throw new RuntimeException('Isi minimal satu bahan pada restock bahan racikan.');
            }

            $data['summary']['cash_balance'] -= $totalCost;
            $data['summary']['total_restock_cost'] += $totalCost;

            addLog($data, 'restock_ingredient', $date, 'Restock bahan racikan', [
                'items' => $items,
                'total_cost' => round($totalCost, 2),
                'note' => $note,
            ]);

            saveData($data);
            $message = 'Restock bahan racikan berhasil disimpan.';
        } elseif ($action === 'update_stock') {
            $actualCashIn = postNum('actual_cash_in');
            if ($actualCashIn < 0) {
                throw new RuntimeException('Uang aktual masuk tidak boleh negatif.');
            }
            $note = postText('note');
            $items = [];
            $mixedMenuItems = [];
            $ingredientUsage = [];
            $totalTheoreticalRevenue = 0;
            $totalCostOut = 0;
            $hasSales = false;

            foreach ($data['settings']['products'] as $key => &$product) {
                $previousStock = (int) $product['stock'];
                $remainingStock = (int) postNum('remaining_' . $key, $previousStock);
                if ($remainingStock < 0) {
                    throw new RuntimeException('Sisa stok ' . $product['name'] . ' tidak boleh negatif.');
                }
                if ($remainingStock > $previousStock) {
                    throw new RuntimeException('Sisa stok ' . $product['name'] . ' tidak boleh melebihi stok sebelumnya.');
                }

                $qtyOut = $previousStock - $remainingStock;
                if ($qtyOut > 0) {
                    $priceInfo = getSellPriceForDate($product, $date);
                    $sellPrice = (float) ($priceInfo['price'] ?? 0);
                    $lineRevenue = $qtyOut * $sellPrice;
                    $lineCost = $qtyOut * (float) $product['avg_cost'];
                    $product['stock'] = $remainingStock;
                    $items[] = [
                        'product' => $product['name'],
                        'previous_stock' => $previousStock,
                        'remaining_stock' => $remainingStock,
                        'qty_out' => $qtyOut,
                        'sell_price' => $sellPrice,
                        'sell_price_effective_date' => (string) ($priceInfo['effective_date'] ?? ''),
                        'avg_cost' => (float) $product['avg_cost'],
                        'theoretical_revenue' => round($lineRevenue, 2),
                        'cost_out' => round($lineCost, 2),
                    ];
                    $totalTheoreticalRevenue += $lineRevenue;
                    $totalCostOut += $lineCost;
                    $hasSales = true;
                }
            }
            unset($product);

            foreach ($data['settings']['mixed_menus'] as $menuKey => $menu) {
                $qtySold = (int) postNum('mixed_qty_' . $menuKey, 0);
                if ($qtySold < 0) {
                    throw new RuntimeException('Qty terjual untuk menu ' . $menu['name'] . ' tidak boleh negatif.');
                }
                if ($qtySold === 0) {
                    continue;
                }
                if (($menu['recipe'] ?? []) === []) {
                    throw new RuntimeException('Resep untuk menu ' . $menu['name'] . ' belum valid.');
                }

                $priceInfo = getSellPriceForDate($menu, $date);
                $sellPrice = (float) ($priceInfo['price'] ?? 0);
                $lineRevenue = $qtySold * $sellPrice;
                $lineCost = 0;
                $recipeItems = [];

                foreach (($menu['recipe'] ?? []) as $recipeItem) {
                    $ingredientKey = (string) ($recipeItem['ingredient_key'] ?? '');
                    $qtyPerMenu = max(0, (int) ($recipeItem['qty'] ?? 0));

                    if ($ingredientKey === '' || $qtyPerMenu <= 0 || !isset($data['settings']['ingredients'][$ingredientKey])) {
                        throw new RuntimeException('Resep menu ' . $menu['name'] . ' tidak valid. Pastikan semua bahan masih aktif.');
                    }

                    $ingredient = $data['settings']['ingredients'][$ingredientKey];
                    $qtyUsed = $qtySold * $qtyPerMenu;
                    $componentCost = $qtyUsed * (float) $ingredient['avg_cost'];
                    $lineCost += $componentCost;

                    if (!isset($ingredientUsage[$ingredientKey])) {
                        $ingredientUsage[$ingredientKey] = [
                            'ingredient' => $ingredient['name'],
                            'unit' => $ingredient['unit'],
                            'qty_used' => 0,
                            'cost_out' => 0,
                        ];
                    }
                    $ingredientUsage[$ingredientKey]['qty_used'] += $qtyUsed;
                    $ingredientUsage[$ingredientKey]['cost_out'] += $componentCost;

                    $recipeItems[] = [
                        'ingredient' => $ingredient['name'],
                        'ingredient_key' => $ingredientKey,
                        'unit' => $ingredient['unit'],
                        'qty_per_menu' => $qtyPerMenu,
                        'qty_used' => $qtyUsed,
                        'avg_cost' => (float) $ingredient['avg_cost'],
                        'cost_out' => round($componentCost, 2),
                    ];
                }

                $mixedMenuItems[] = [
                    'menu' => $menu['name'],
                    'qty_sold' => $qtySold,
                    'sell_price' => $sellPrice,
                    'sell_price_effective_date' => (string) ($priceInfo['effective_date'] ?? ''),
                    'theoretical_revenue' => round($lineRevenue, 2),
                    'recipe_cost' => round($lineCost, 2),
                    'recipe_items' => $recipeItems,
                ];

                $totalTheoreticalRevenue += $lineRevenue;
                $totalCostOut += $lineCost;
                $hasSales = true;
            }

            foreach ($ingredientUsage as $ingredientKey => &$usage) {
                $ingredient = &$data['settings']['ingredients'][$ingredientKey];
                $previousStock = (int) $ingredient['stock'];
                if ($usage['qty_used'] > $previousStock) {
                    throw new RuntimeException('Stok bahan ' . $ingredient['name'] . ' tidak cukup untuk penjualan yang diinput.');
                }

                $ingredient['stock'] = $previousStock - $usage['qty_used'];
                $usage['previous_stock'] = $previousStock;
                $usage['remaining_stock'] = (int) $ingredient['stock'];
                $usage['avg_cost'] = (float) $ingredient['avg_cost'];
                $usage['cost_out'] = round((float) $usage['cost_out'], 2);
            }
            unset($usage, $ingredient);

            if (!$hasSales) {
                throw new RuntimeException('Isi minimal satu perubahan sisa stok atau qty menu racikan terjual.');
            }

            $selfPaymentDiff = $actualCashIn - $totalTheoreticalRevenue;
            $actualProfit = $actualCashIn - $totalCostOut;

            $data['summary']['cash_balance'] += $actualCashIn;
            $data['summary']['total_actual_profit'] += $actualProfit;
            $data['summary']['total_theoretical_revenue'] += $totalTheoreticalRevenue;
            $data['summary']['total_actual_revenue'] += $actualCashIn;
            $data['summary']['total_self_payment_diff'] += $selfPaymentDiff;

            addLog($data, 'update_stock', $date, 'Rekap stok keluar / self payment', [
                'items' => $items,
                'actual_cash_in' => $actualCashIn,
                'theoretical_revenue' => round($totalTheoreticalRevenue, 2),
                'cost_out' => round($totalCostOut, 2),
                'self_payment_diff' => round($selfPaymentDiff, 2),
                'actual_profit' => round($actualProfit, 2),
                'mixed_menu_items' => $mixedMenuItems,
                'ingredient_usage' => array_values($ingredientUsage),
                'note' => $note,
            ]);

            saveData($data);
            $message = 'Rekap stok berhasil disimpan.';
        } elseif ($action === 'purchase') {
            $itemName = postText('item_name');
            $amount = postNum('amount');
            $note = postText('note');
            if ($itemName === '') {
                throw new RuntimeException('Nama item pembelian harus diisi.');
            }
            if ($amount <= 0) {
                throw new RuntimeException('Nilai pembelian harus lebih dari 0.');
            }

            $data['summary']['cash_balance'] -= $amount;
            addLog($data, 'purchase', $date, 'Pembelian aset', [
                'item_name' => $itemName,
                'amount' => $amount,
                'note' => $note,
            ]);

            saveData($data);
            $message = 'Pembelian aset berhasil dicatat.';
        } elseif ($action === 'expense') {
            $amount = postNum('amount');
            $note = postText('note');
            if ($amount <= 0) {
                throw new RuntimeException('Nominal biaya harus lebih dari 0.');
            }
            $data['summary']['cash_balance'] -= $amount;
            $data['summary']['total_operational_expense'] += $amount;
            $data['summary']['total_actual_profit'] -= $amount;

            addLog($data, 'expense', $date, 'Biaya operasional', [
                'amount' => $amount,
                'note' => $note,
            ]);

            saveData($data);
            $message = 'Biaya operasional berhasil dicatat.';
        } elseif ($action === 'owner_withdrawal') {
            $amount = postNum('amount');
            $note = postText('note');
            if ($amount <= 0) {
                throw new RuntimeException('Nominal penarikan pemilik harus lebih dari 0.');
            }

            $data['summary']['cash_balance'] -= $amount;
            $data['summary']['total_owner_withdrawal'] += $amount;

            addLog($data, 'owner_withdrawal', $date, 'Penarikan ke pemilik (PMK Kota Surabaya)', [
                'amount' => $amount,
                'note' => $note,
            ]);

            saveData($data);
            $message = 'Penarikan ke pemilik berhasil dicatat.';
        } elseif ($action === 'reset_all') {
            $data = defaultData();
            saveData($data);
            $message = 'Semua data berhasil direset.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $data = loadData();
}

$historyLogsPageSize = 8;

if ((string) ($_GET['ajax'] ?? '') === 'history_logs') {
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $limit = max(1, min(12, (int) ($_GET['limit'] ?? $historyLogsPageSize)));
    $historyLogs = array_values(is_array($data['logs'] ?? null) ? $data['logs'] : []);
    $historyLogChunk = array_slice($historyLogs, $offset, $limit);
    $nextOffset = $offset + count($historyLogChunk);

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'html' => renderHistoryLogRows($historyLogChunk),
        'loaded_count' => count($historyLogChunk),
        'next_offset' => $nextOffset,
        'has_more' => $nextOffset < count($historyLogs),
        'total_count' => count($historyLogs),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$reportMonths = reportMonthOptions($data['logs'] ?? []);
$defaultReportMonth = $reportMonths[0] ?? date('Y-m');
$earliestReportMonth = $reportMonths === [] ? $defaultReportMonth : $reportMonths[array_key_last($reportMonths)];
$selectedReportStartMonth = normalizeMonthValue((string) ($_GET['month_from'] ?? ''), $defaultReportMonth);
$selectedReportEndMonth = normalizeMonthValue((string) ($_GET['month_to'] ?? ''), $defaultReportMonth);

if (!in_array($selectedReportStartMonth, $reportMonths, true)) {
    $selectedReportStartMonth = $earliestReportMonth;
}

if (!in_array($selectedReportEndMonth, $reportMonths, true)) {
    $selectedReportEndMonth = $defaultReportMonth;
}

if ($selectedReportStartMonth > $selectedReportEndMonth) {
    [$selectedReportStartMonth, $selectedReportEndMonth] = [$selectedReportEndMonth, $selectedReportStartMonth];
}

if ((string) ($_GET['export'] ?? '') === 'report_pdf') {
    $report = buildPeriodReportData($data, $selectedReportStartMonth, $selectedReportEndMonth);
    $pdf = renderReportPdf($report);
    $filename = 'laporan-' . $selectedReportStartMonth . '-sd-' . $selectedReportEndMonth . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $pdf;
    exit;
}

$products = $data['settings']['products'];
$archivedProducts = $data['settings']['archived_products'];
$ingredients = $data['settings']['ingredients'];
$archivedIngredients = $data['settings']['archived_ingredients'];
$mixedMenus = $data['settings']['mixed_menus'];
$archivedMixedMenus = $data['settings']['archived_mixed_menus'];
uasort($archivedProducts, static function (array $a, array $b): int {
    return strcmp((string) ($b['deleted_at'] ?? ''), (string) ($a['deleted_at'] ?? ''));
});
uasort($archivedIngredients, static function (array $a, array $b): int {
    return strcmp((string) ($b['deleted_at'] ?? ''), (string) ($a['deleted_at'] ?? ''));
});
uasort($archivedMixedMenus, static function (array $a, array $b): int {
    return strcmp((string) ($b['deleted_at'] ?? ''), (string) ($a['deleted_at'] ?? ''));
});
$summary = $data['summary'];
$hasProducts = !empty($products);
$hasArchivedProducts = !empty($archivedProducts);
$hasIngredients = !empty($ingredients);
$hasArchivedIngredients = !empty($archivedIngredients);
$hasMixedMenus = !empty($mixedMenus);
$hasArchivedMixedMenus = !empty($archivedMixedMenus);
$ingredientCatalog = buildIngredientCatalog($ingredients, $archivedIngredients);
$productStockValue = 0;
$ingredientStockValue = 0;
$totalStockValue = 0;
$totalPotentialRevenue = 0;
$totalProductStockUnits = 0;
$totalIngredientStockUnits = 0;
foreach ($products as $product) {
    $totalProductStockUnits += (int) $product['stock'];
    $productStockValue += (float) $product['stock'] * (float) $product['avg_cost'];
    $totalPotentialRevenue += (float) $product['stock'] * (float) $product['sell_price'];
}
$mixedMenuPotentialRevenue = 0;
foreach ($ingredients as $ingredient) {
    $totalIngredientStockUnits += (int) $ingredient['stock'];
    $ingredientStockValue += (float) $ingredient['stock'] * (float) $ingredient['avg_cost'];
}
foreach ($mixedMenus as $menu) {
    $mixedMenuPotentialRevenue += getMixedMenuAvailableServings($menu, $ingredients) * currentSellPrice($menu);
}
$totalStockValue = $productStockValue + $ingredientStockValue;
$totalStockUnits = $totalProductStockUnits + $totalIngredientStockUnits;
$totalPotentialRevenue += $mixedMenuPotentialRevenue;
$potentialGrossProfit = $totalPotentialRevenue - $totalStockValue;
$totalAssetPurchases = getAssetPurchaseTotal($data['logs']);
$assetItems = getAssetsByItem($data['logs']);
$totalAssetsValue = $totalStockValue + $totalAssetPurchases;
$netOperationalPosition = (float) $summary['cash_balance'] + $totalStockValue;
$assetPosition = (float) $summary['cash_balance'] + $totalAssetsValue;
$activeProductCount = count($products);
$archivedProductCount = count($archivedProducts);
$activeIngredientCount = count($ingredients);
$archivedIngredientCount = count($archivedIngredients);
$activeMixedMenuCount = count($mixedMenus);
$archivedMixedMenuCount = count($archivedMixedMenus);
$activeReadySellCount = $activeProductCount + $activeMixedMenuCount;
$transactionCount = count($data['logs']);
$historyLogsAjaxUrl = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? 'index.php'), PHP_URL_PATH);
$historyLogsAjaxUrl = $historyLogsAjaxUrl !== '' ? $historyLogsAjaxUrl : 'index.php';
$latestLogDate = (string) ($data['logs'][0]['date'] ?? '');
$workspaceViews = [
    'ringkasan' => [
        'kicker' => 'Pantau Kondisi',
        'title' => 'Ringkasan Bisnis',
        'copy' => 'Lihat posisi kas, stok aktif, analisis inti, dan bahan racikan tanpa gangguan area lain.',
    ],
    'operasional' => [
        'kicker' => 'Kerja Harian',
        'title' => 'Operasional',
        'copy' => 'Fokus ke aksi harian seperti rekap, restock, pengelolaan master data, dan input biaya.',
    ],
    'riwayat' => [
        'kicker' => 'Audit & Arsip',
        'title' => 'Riwayat Usaha',
        'copy' => 'Buka histori transaksi, aset pembelian, status master data, dan export laporan bulanan.',
    ],
];
$currentWorkspace = normalizeWorkspaceValue((string) ($_GET['workspace'] ?? ''), 'ringkasan');
$logTypeCounts = [
    'restock' => 0,
    'restock_ingredient' => 0,
    'update_stock' => 0,
    'purchase' => 0,
    'expense' => 0,
    'owner_withdrawal' => 0,
];
foreach ($data['logs'] as $log) {
    $type = (string) ($log['type'] ?? '');
    if (isset($logTypeCounts[$type])) {
        $logTypeCounts[$type]++;
    }
}
$logTypeLabels = [
    'restock' => 'Restock produk',
    'restock_ingredient' => 'Restock bahan',
    'update_stock' => 'Rekap penjualan',
    'purchase' => 'Pembelian aset',
    'expense' => 'Biaya operasional',
    'owner_withdrawal' => 'Penarikan pemilik',
];
$dominantLogType = '';
$dominantLogCount = 0;
foreach ($logTypeCounts as $type => $count) {
    if ($count > $dominantLogCount) {
        $dominantLogType = $type;
        $dominantLogCount = $count;
    }
}
$dominantLogTypeLabel = $dominantLogType !== '' ? ($logTypeLabels[$dominantLogType] ?? monthlyReportTypeLabel($dominantLogType)) : 'Belum ada aktivitas';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem Laporan Dana Usaha</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #edf1ef;
            --bg-accent: #dfeae6;
            --surface: rgba(249, 252, 251, 0.84);
            --surface-strong: rgba(255, 255, 255, 0.95);
            --surface-soft: rgba(237, 243, 240, 0.9);
            --panel2: #f7fbf9;
            --line: rgba(73, 94, 112, 0.14);
            --line-strong: rgba(53, 73, 90, 0.2);
            --text: #17212b;
            --muted: #5e6d7b;
            --blue: #0f766e;
            --green: #15803d;
            --red: #c2410c;
            --yellow: #b45309;
            --navy: #18324a;
            --accent-soft: #d7f0ea;
            --shadow: 0 28px 72px rgba(19, 33, 45, 0.1);
            --shadow-soft: 0 18px 42px rgba(19, 33, 45, 0.07);
            --radius-xl: 32px;
            --radius-lg: 24px;
            --radius-md: 18px;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Manrope", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(15,118,110,0.14), transparent 26%),
                radial-gradient(circle at 86% 12%, rgba(28,100,242,0.08), transparent 18%),
                linear-gradient(180deg, #f8fbfa 0%, var(--bg) 52%, #e8eeeb 100%);
            color: var(--text);
            position: relative;
            overflow-x: hidden;
        }
        body.modal-open {
            overflow: hidden;
        }
        body::before,
        body::after {
            content: "";
            position: fixed;
            inset: auto;
            pointer-events: none;
            z-index: 0;
            filter: blur(10px);
        }
        body::before {
            top: 110px;
            right: -90px;
            width: 280px;
            height: 280px;
            background: radial-gradient(circle, rgba(15,118,110,0.1), transparent 72%);
        }
        body::after {
            bottom: 80px;
            left: -110px;
            width: 320px;
            height: 320px;
            background: radial-gradient(circle, rgba(24,50,74,0.08), transparent 72%);
        }
        a { color: inherit; }
        h1, h2, h3, h4, h5, h6, button, .metric, .eyebrow, .section-kicker, .badge, .pill {
            font-family: "Space Grotesk", sans-serif;
        }
        button, input, select, textarea {
            font: inherit;
        }
        .page-shell {
            width: min(1380px, calc(100% - 24px));
            margin: 18px auto 38px;
            position: relative;
            z-index: 1;
        }
        .container {
            display: grid;
            gap: 18px;
        }
        .container > *,
        .board > *,
        .stack > *,
        .section-head > *,
        .panel-head > * {
            min-width: 0;
        }
        .hero {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(300px, 420px);
            gap: 16px;
            padding: clamp(20px, 2.2vw, 26px);
            border-radius: var(--radius-xl);
            background:
                linear-gradient(135deg, rgba(255,255,255,0.96), rgba(245,250,248,0.86)),
                linear-gradient(120deg, rgba(15,118,110,0.08), transparent 45%);
            border: 1px solid rgba(255,255,255,0.9);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }
        .hero::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(120deg, rgba(15,118,110,0.06), transparent 38%),
                linear-gradient(290deg, rgba(24,50,74,0.06), transparent 30%);
            pointer-events: none;
        }
        .hero-copy,
        .hero-side {
            position: relative;
            z-index: 1;
        }
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.78);
            border: 1px solid rgba(22,50,79,0.08);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--navy);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.9);
        }
        .eyebrow::before {
            content: "";
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: linear-gradient(180deg, #14b8a6, #0f766e);
            box-shadow: 0 0 0 6px rgba(15,118,110,0.12);
        }
        .hero h1 {
            margin: 0;
            max-width: 14ch;
            font-size: clamp(2rem, 4vw, 3.2rem);
            line-height: 0.96;
            letter-spacing: -0.05em;
            color: #14202c;
        }
        .hero-lead {
            max-width: 58ch;
            margin: 15px 0 0;
            font-size: 0.93rem;
            line-height: 1.62;
            color: #566574;
        }
        .hero-side {
            display: grid;
            gap: 12px;
            align-content: start;
        }
        .hero-spotlight {
            padding: clamp(18px, 2vw, 22px);
            border-radius: 24px;
            background:
                linear-gradient(180deg, rgba(22,50,79,0.98), rgba(20,39,61,0.96)),
                radial-gradient(circle at top right, rgba(94,234,212,0.14), transparent 34%);
            color: #f9fafb;
            box-shadow: 0 26px 60px rgba(22, 50, 79, 0.22);
        }
        .hero-spotlight .eyebrow {
            margin-bottom: 10px;
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.12);
            color: #d8ece8;
        }
        .hero-spotlight .eyebrow::before {
            background: linear-gradient(180deg, #fde68a, #f59e0b);
            box-shadow: 0 0 0 6px rgba(245,158,11,0.14);
        }
        .spotlight-value {
            font-size: clamp(1.8rem, 3.5vw, 2.75rem);
            line-height: 1;
            letter-spacing: -0.05em;
            margin: 0 0 8px;
        }
        .spotlight-copy {
            margin: 0;
            color: rgba(241,245,249,0.8);
            line-height: 1.55;
            font-size: 0.9rem;
        }
        .hero-spotlight .good { color: #5eead4; }
        .hero-spotlight .bad { color: #fdba74; }
        .muted { color: var(--muted); }
        .section {
            display: grid;
            gap: 10px;
        }
        .section-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .section-title {
            margin: 4px 0 0;
            font-size: clamp(1.2rem, 1.8vw, 1.45rem);
            letter-spacing: -0.04em;
        }
        .section-kicker {
            display: inline-block;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--blue);
        }
        .section-copy {
            margin: 0;
            max-width: 54ch;
            color: var(--muted);
            line-height: 1.55;
            font-size: 0.9rem;
        }
        .workspace-hub {
            display: grid;
            gap: 14px;
        }
        .workspace-shell {
            display: grid;
            gap: 14px;
            padding: clamp(16px, 2vw, 20px);
            border-radius: 28px;
            background:
                linear-gradient(180deg, rgba(255,255,255,0.92), rgba(247,241,231,0.82)),
                radial-gradient(circle at top right, rgba(15,118,110,0.14), transparent 38%);
            border: 1px solid rgba(255,255,255,0.86);
            box-shadow: var(--shadow-soft);
        }
        .workspace-shell-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .workspace-shell-note {
            max-width: 32ch;
            color: var(--muted);
            font-size: 0.84rem;
            line-height: 1.55;
        }
        .workspace-tabs {
            display: flex;
            align-items: stretch;
            gap: 8px;
            padding: 6px;
            border-radius: 22px;
            background: rgba(247, 241, 231, 0.74);
            border: 1px solid rgba(22,50,79,0.08);
            overflow-x: auto;
            scrollbar-gutter: stable;
            scrollbar-width: thin;
        }
        .workspace-link {
            min-width: 176px;
            flex: 1 1 0;
            display: grid;
            gap: 5px;
            text-align: left;
            padding: 12px 14px 13px;
            border-radius: 16px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--text);
            box-shadow: none;
            cursor: pointer;
            transition: transform .18s ease, border-color .18s ease, background .18s ease, box-shadow .18s ease, color .18s ease;
        }
        .workspace-link:hover {
            transform: translateY(-1px);
            border-color: rgba(15,118,110,0.22);
            background: rgba(255,255,255,0.68);
        }
        .workspace-link:focus-visible {
            outline: none;
            border-color: rgba(15,118,110,0.4);
            box-shadow: 0 0 0 4px rgba(15,118,110,0.12);
        }
        .workspace-link.is-active {
            border-color: rgba(15,118,110,0.3);
            background:
                linear-gradient(180deg, rgba(233,248,245,0.98), rgba(255,255,255,0.98)),
                linear-gradient(120deg, rgba(15,118,110,0.12), transparent 50%);
            box-shadow: 0 12px 22px rgba(15,118,110,0.12);
        }
        .workspace-link-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--blue);
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .workspace-link-kicker::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: linear-gradient(180deg, #14b8a6, #0f766e);
            box-shadow: 0 0 0 5px rgba(20,184,166,0.1);
        }
        .workspace-link strong {
            font-size: 0.96rem;
            letter-spacing: -0.03em;
        }
        .workspace-active {
            position: relative;
            overflow: hidden;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: end;
            gap: 16px;
            padding: clamp(16px, 2vw, 20px);
            border-radius: 22px;
            color: #e8f5f1;
            background:
                linear-gradient(135deg, rgba(13,97,90,0.98), rgba(15,118,110,0.9)),
                radial-gradient(circle at top right, rgba(255,255,255,0.16), transparent 34%);
            box-shadow: 0 22px 44px rgba(15,118,110,0.22);
        }
        .workspace-active-main {
            display: grid;
            gap: 8px;
        }
        .workspace-active::after {
            content: "";
            position: absolute;
            inset: auto -28px -48px auto;
            width: 170px;
            height: 170px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(253,224,71,0.18), transparent 66%);
            pointer-events: none;
        }
        .workspace-active-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(232,245,241,0.86);
        }
        .workspace-active-kicker::before {
            content: "";
            width: 9px;
            height: 9px;
            border-radius: 999px;
            background: linear-gradient(180deg, #fde68a, #f59e0b);
            box-shadow: 0 0 0 6px rgba(245,158,11,0.16);
        }
        .workspace-active h3 {
            margin: 4px 0 0;
            font-size: clamp(1.28rem, 2vw, 1.62rem);
            letter-spacing: -0.05em;
        }
        .workspace-active p {
            margin: 0;
            max-width: 42ch;
            color: rgba(232,245,241,0.84);
            line-height: 1.6;
            font-size: 0.9rem;
        }
        .workspace-active-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
        }
        .workspace-active-chip {
            display: inline-flex;
            align-items: center;
            padding: 8px 11px;
            border-radius: 999px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.14);
            color: #f8fafc;
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 0.04em;
        }
        .workspace-stage {
            display: grid;
            gap: 14px;
            min-height: 280px;
        }
        .card,
        .panel {
            background:
                linear-gradient(180deg, var(--surface), var(--surface-strong)),
                linear-gradient(120deg, rgba(15,118,110,0.02), transparent 42%);
            border: 1px solid rgba(255,255,255,0.9);
            border-radius: var(--radius-lg);
            padding: clamp(16px, 1.8vw, 18px);
            box-shadow: var(--shadow-soft);
            backdrop-filter: blur(18px);
        }
        .panel h3,
        .card h3 {
            margin: 0 0 14px;
            font-size: 1.05rem;
            letter-spacing: -0.02em;
        }
        .grid { display: grid; gap: 16px; }
        .modal-grid {
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            align-items: start;
        }
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(215px, 1fr));
            gap: 12px;
        }
        .metric-card {
            position: relative;
            overflow: hidden;
        }
        .metric-card::after {
            content: "";
            position: absolute;
            inset: auto -30px -40px auto;
            width: 140px;
            height: 140px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(15,118,110,0.08), transparent 65%);
            pointer-events: none;
        }
        .metric-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }
        .metric-label {
            color: var(--muted);
            font-size: 0.82rem;
        }
        .metric-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(15,118,110,0.08);
            color: var(--blue);
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .metric {
            font-size: clamp(1.35rem, 2.6vw, 1.85rem);
            font-weight: 700;
            margin: 0;
            letter-spacing: -0.05em;
            line-height: 1.05;
        }
        .metric-note {
            margin-top: 10px;
            color: var(--muted);
            line-height: 1.5;
            font-size: 0.82rem;
        }
        .good { color: var(--green); }
        .bad { color: var(--red); }
        .warn { color: var(--yellow); }
        .action-cluster-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 12px;
        }
        .action-group {
            border: 1px solid rgba(255,255,255,0.84);
            background:
                linear-gradient(180deg, rgba(255,255,255,0.94), rgba(245,249,247,0.78)),
                linear-gradient(120deg, rgba(15,118,110,0.07), transparent 40%);
            border-radius: 22px;
            box-shadow: var(--shadow-soft);
            overflow: hidden;
            position: relative;
        }
        .action-group::before {
            content: "";
            position: absolute;
            inset: auto -20px -36px auto;
            width: 110px;
            height: 110px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(15,118,110,0.16), transparent 70%);
            pointer-events: none;
        }
        .action-group summary {
            list-style: none;
        }
        .action-group summary::-webkit-details-marker {
            display: none;
        }
        .action-group-summary {
            display: grid;
            gap: 10px;
            padding: 15px;
            cursor: pointer;
            position: relative;
            z-index: 1;
        }
        .action-group-summary::after {
            content: "Buka";
            color: var(--blue);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .action-group[open] .action-group-summary::after {
            content: "Tutup";
        }
        .action-group-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }
        .action-group-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(15,118,110,0.08);
            color: var(--blue);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .action-group-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--navy);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .action-group-label::before {
            content: "";
            width: 9px;
            height: 9px;
            border-radius: 999px;
            background: linear-gradient(180deg, #14b8a6, #0f766e);
        }
        .action-group-title {
            margin: 0;
            font-size: 1rem;
            letter-spacing: -0.03em;
        }
        .action-group-copy {
            margin: 0;
            color: var(--muted);
            font-size: 0.82rem;
            line-height: 1.55;
            max-width: 30ch;
        }
        .action-subactions {
            display: grid;
            gap: 8px;
            padding: 0 15px 15px;
            position: relative;
            z-index: 1;
        }
        .action-subbtn {
            border: 1px solid rgba(22,50,79,0.08);
            background: rgba(255,255,255,0.82);
            color: var(--text);
            padding: 12px 13px;
            border-radius: 16px;
            text-align: left;
            cursor: pointer;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.88);
            transition: border-color .2s ease, background .2s ease, transform .2s ease;
        }
        .action-subbtn:hover {
            transform: translateY(-1px);
            border-color: rgba(15,118,110,0.18);
            background: rgba(255,255,255,0.92);
        }
        .action-subbtn small {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            color: var(--navy);
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .action-subbtn small::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: linear-gradient(180deg, #14b8a6, #0f766e);
        }
        .action-subbtn strong {
            display: block;
            font-size: 0.92rem;
            margin-bottom: 4px;
            letter-spacing: -0.03em;
        }
        .action-subbtn span {
            display: block;
            color: var(--muted);
            font-size: 0.78rem;
            line-height: 1.5;
        }
        .board {
            display: grid;
            grid-template-columns: minmax(0, 1.28fr) minmax(280px, 0.92fr);
            gap: 14px;
            align-items: start;
        }
        .stack {
            display: grid;
            gap: 14px;
        }
        .panel-head {
            display: flex;
            align-items: start;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .panel-head-inline {
            align-items: center;
            flex-wrap: nowrap;
        }
        .panel-title {
            margin: 0;
            font-size: 1rem;
            letter-spacing: -0.03em;
        }
        .panel-copy {
            margin: 4px 0 0;
            color: var(--muted);
            line-height: 1.5;
            font-size: 0.84rem;
        }
        .panel-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 9px 12px;
            border-radius: 999px;
            background: rgba(15,118,110,0.08);
            color: var(--blue);
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            white-space: nowrap;
            max-width: 100%;
        }
        .summary-list {
            display: grid;
            gap: 10px;
        }
        .summary-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            padding: 11px 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.72);
            border: 1px solid rgba(22,50,79,0.08);
        }
        .summary-row span:first-child {
            color: var(--muted);
            min-width: 0;
        }
        .summary-row strong {
            font-weight: 800;
            letter-spacing: -0.03em;
            font-size: 0.94rem;
        }
        .panel-form-actions {
            display: flex;
            justify-content: flex-start;
            margin-top: 12px;
        }
        .report-form {
            display: grid;
            gap: 12px;
        }
        .report-form .row {
            margin-bottom: 0;
        }
        .report-export-note {
            padding: 12px 14px;
            border-radius: 16px;
            background: rgba(255,255,255,0.72);
            border: 1px solid rgba(22,50,79,0.08);
        }
        .table-shell,
        .scroll-box,
        .asset-scroll {
            overflow: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-x: contain;
            scrollbar-gutter: stable;
            border-radius: 20px;
            border: 1px solid rgba(22,50,79,0.07);
            background: rgba(255,255,255,0.8);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.9);
        }
        table {
            width: 100%;
            min-width: 100%;
            border-collapse: collapse;
        }
        .data-table {
            width: 100%;
        }
        .products-table { min-width: 880px; }
        .ingredients-table {
            min-width: 620px;
            table-layout: fixed;
        }
        .logs-table { min-width: 900px; }
        .assets-table { min-width: 360px; }
        th, td {
            padding: 13px 15px;
            border-bottom: 1px solid rgba(22,50,79,0.08);
            text-align: left;
            vertical-align: top;
        }
        th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: rgba(241, 246, 244, 0.96);
            color: #4d5c6b;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        thead th:first-child {
            border-top-left-radius: 16px;
        }
        thead th:last-child {
            border-top-right-radius: 16px;
        }
        .cell-right {
            text-align: right;
        }
        .cell-center {
            text-align: center;
        }
        tbody tr:hover {
            background: rgba(15,118,110,0.04);
        }
        tbody tr:last-child td {
            border-bottom: none;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 11px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            background: #ecf2f2;
        }
        .type-restock { color: #075985; background: rgba(56,189,248,.16); }
        .type-restock_ingredient { color: #0f766e; background: rgba(20,184,166,.16); }
        .type-update_stock { color: #166534; background: rgba(34,197,94,.16); }
        .type-expense { color: #9a3412; background: rgba(249,115,22,.14); }
        .type-owner_withdrawal { color: #7c2d12; background: rgba(251,146,60,.18); }
        .type-purchase { color: #92400e; background: rgba(245,158,11,.18); }
        .alert {
            padding: 16px 18px;
            border-radius: 20px;
            border: 1px solid;
            box-shadow: var(--shadow-soft);
        }
        .alert-success {
            background: rgba(21,128,61,.08);
            border-color: rgba(21,128,61,.18);
            color: #166534;
        }
        .alert-error {
            background: rgba(194,65,12,.08);
            border-color: rgba(194,65,12,.18);
            color: #9a3412;
        }
        .ajax-toast-stack {
            position: fixed;
            top: 18px;
            right: 18px;
            z-index: 5000;
            display: grid;
            gap: 10px;
            width: min(360px, calc(100vw - 24px));
            pointer-events: none;
        }
        .ajax-toast {
            pointer-events: auto;
            backdrop-filter: blur(12px);
            animation: toast-slide-in .18s ease;
        }
        @keyframes toast-slide-in {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .small,
        .history-item,
        .compact-note,
        .empty-state,
        .panel-copy,
        .section-copy {
            overflow-wrap: anywhere;
        }
        .small { font-size: 12px; color: var(--muted); line-height: 1.6; }
        button, input, select, textarea {
            width: 100%;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,0.88);
            color: var(--text);
            padding: 13px 14px;
            font-size: 14px;
            transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
        }
        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: rgba(15,118,110,0.42);
            box-shadow: 0 0 0 4px rgba(15,118,110,0.12);
            background: #fff;
        }
        textarea { min-height: 88px; resize: vertical; }
        button[type="submit"], .btn {
            border: none;
            background: linear-gradient(180deg, #0f766e, #0d615a);
            color: #f8fafc;
            cursor: pointer;
            font-weight: 700;
            letter-spacing: -0.02em;
            box-shadow: 0 12px 26px rgba(15,118,110,0.22);
        }
        body.ajax-busy {
            cursor: progress;
        }
        button:disabled,
        .btn:disabled,
        button.is-loading,
        .btn.is-loading {
            opacity: 0.74;
            cursor: progress;
            box-shadow: none;
        }
        .btn-danger {
            background: linear-gradient(180deg, #c2410c, #9a3412);
            box-shadow: 0 12px 26px rgba(194,65,12,0.2);
        }
        .btn-secondary {
            background: linear-gradient(180deg, #3f4f5f, #314252);
            box-shadow: none;
        }
        .row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 14px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.88rem;
            font-weight: 700;
            color: #314252;
        }
        details {
            margin-top: 10px;
        }
        details summary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            color: var(--blue);
            font-weight: 700;
        }
        .modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(23, 29, 39, 0.4);
            backdrop-filter: blur(10px);
            z-index: 50;
        }
        .modal.show { display: flex; }
        .modal-box {
            width: min(960px, calc(100vw - 32px));
            max-height: min(91vh, 980px);
            overflow: auto;
            background: linear-gradient(180deg, rgba(255,251,245,0.98), rgba(255,247,236,0.98));
            border: 1px solid rgba(255,255,255,0.92);
            border-radius: 26px;
            padding: clamp(14px, 2vw, 18px);
            box-shadow: 0 26px 72px rgba(22,50,79,0.18);
            position: relative;
        }
        .modal-box::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 120px;
            background: linear-gradient(180deg, rgba(15,118,110,0.08), transparent 82%);
            pointer-events: none;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 10px;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(22,50,79,0.08);
            position: relative;
            z-index: 1;
        }
        .modal-header > div {
            flex: 1;
            min-width: 0;
        }
        .modal-header h3 {
            margin: 0;
            font-size: clamp(0.98rem, 1.35vw, 1.14rem);
        }
        .modal-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 5px;
            padding: 4px 8px;
            border-radius: 999px;
            background: rgba(15,118,110,0.10);
            color: var(--blue);
            font-size: 0.64rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .modal-kicker::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: linear-gradient(180deg, #14b8a6, #0f766e);
        }
        .modal-subtitle {
            margin-top: 4px;
            max-width: 62ch;
            font-size: 0.76rem;
            line-height: 1.4;
        }
        .modal-close {
            width: 38px;
            height: 38px;
            min-width: 38px;
            padding: 0;
            border-radius: 12px;
            display: grid;
            place-items: center;
            border: 1px solid rgba(22,50,79,0.1);
            background: rgba(255,255,255,0.84);
            flex-shrink: 0;
            position: relative;
            z-index: 1;
            color: #425466;
            box-shadow: 0 8px 18px rgba(22,50,79,0.1), inset 0 1px 0 rgba(255,255,255,0.92);
            cursor: pointer;
        }
        .modal-close:hover {
            color: var(--blue);
            background: rgba(255,255,255,0.96);
            transform: translateY(-1px);
            box-shadow: 0 12px 22px rgba(22,50,79,0.12), inset 0 1px 0 rgba(255,255,255,0.96);
        }
        .modal-close:active {
            transform: translateY(0);
        }
        .modal-close-icon {
            position: relative;
            width: 14px;
            height: 14px;
            display: block;
        }
        .modal-close-icon::before,
        .modal-close-icon::after {
            content: "";
            position: absolute;
            left: 6px;
            top: 0;
            width: 2px;
            height: 14px;
            border-radius: 999px;
            background: currentColor;
        }
        .modal-close-icon::before {
            transform: rotate(45deg);
        }
        .modal-close-icon::after {
            transform: rotate(-45deg);
        }
        .modal-form {
            display: grid;
            gap: 10px;
            position: relative;
            z-index: 1;
        }
        .form-section {
            padding: 12px;
            border-radius: 16px;
            background: rgba(255,255,255,0.76);
            border: 1px solid rgba(22,50,79,0.08);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.92);
        }
        .form-section-title {
            margin: 0;
            font-size: 0.84rem;
            letter-spacing: -0.02em;
        }
        .form-section-copy {
            margin: 3px 0 8px;
            color: var(--muted);
            font-size: 0.73rem;
            line-height: 1.38;
        }
        .field-stack {
            display: grid;
            gap: 5px;
        }
        .field-hint {
            margin-top: 5px;
            color: var(--muted);
            font-size: 0.68rem;
            line-height: 1.35;
        }
        .modal-form .row {
            margin-bottom: 0;
            gap: 10px;
        }
        .modal-form .row > div {
            padding: 9px;
            border-radius: 13px;
            background: rgba(255,255,255,0.82);
            border: 1px solid rgba(22,50,79,0.08);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.92);
        }
        .modal-form label,
        .price-form label {
            margin-bottom: 4px;
            font-size: 0.75rem;
        }
        .modal-form input,
        .modal-form select,
        .modal-form textarea,
        .price-form input,
        .price-form select,
        .price-form textarea {
            padding: 9px 10px;
            border-radius: 12px;
            font-size: 12.5px;
        }
        .modal-form textarea,
        .price-form textarea {
            min-height: 68px;
        }
        .modal-form-footer {
            position: sticky;
            bottom: -24px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 2px;
            padding: 8px 0 0;
            background: linear-gradient(180deg, rgba(255,247,236,0), rgba(255,247,236,0.98) 42%);
        }
        .modal-submit {
            width: auto;
            min-width: 160px;
            padding: 10px 14px;
            font-size: 0.84rem;
        }
        .empty-modal-state {
            padding: 12px;
            border-radius: 16px;
            background: rgba(255,255,255,0.74);
            border: 1px dashed rgba(22,50,79,0.14);
            color: var(--muted);
            line-height: 1.45;
            font-size: 0.82rem;
        }
        .stock-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
        }
        .stock-item {
            border: 1px solid rgba(22,50,79,0.08);
            border-radius: 16px;
            padding: 12px;
            background: rgba(255,255,255,0.74);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.9);
            min-width: 0;
        }
        .stock-item strong {
            display: block;
            margin-bottom: 7px;
            font-size: 0.84rem;
        }
        .stock-meta {
            margin: 0 0 8px;
            padding: 7px 9px;
            border-radius: 12px;
            background: rgba(15,118,110,0.06);
        }
        .modal .card {
            padding: 14px;
            border-radius: 18px;
        }
        .modal .card h3 {
            margin-bottom: 10px;
            font-size: 0.96rem;
        }
        .manage-list { display: grid; gap: 10px; }
        .manage-item {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(220px, 280px);
            align-items: flex-start;
            gap: 12px;
            padding: 14px;
            border: 1px solid rgba(22,50,79,0.08);
            border-radius: 18px;
            background: rgba(255,255,255,0.72);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.86);
        }
        .manage-main { min-width: 0; }
        .manage-actions { width: 100%; min-width: 0; display: grid; gap: 8px; }
        .price-form { margin: 0; width: 100%; display: grid; gap: 8px; }
        .price-form button,
        .inline-form button {
            padding: 10px 12px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
        .history-list { display: grid; gap: 5px; margin-top: 8px; }
        .history-item { font-size: 11.5px; color: var(--muted); }
        .inline-form { margin: 0; width: 100%; }
        .inline-form button { width: 100%; min-width: 0; }
        .product-modal-stack {
            display: grid;
            gap: 14px;
        }
        .product-card-head {
            display: grid;
            gap: 4px;
            margin-bottom: 12px;
        }
        .product-card-head-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            flex-wrap: wrap;
        }
        .product-card-copy {
            margin: 0;
            color: var(--muted);
            font-size: 0.8rem;
            line-height: 1.5;
        }
        .product-create-panel {
            margin: 0;
            padding: 0;
            border: none;
            background: transparent;
        }
        .product-create-panel summary {
            list-style: none;
        }
        .product-create-panel summary::-webkit-details-marker {
            display: none;
        }
        .product-create-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 12px 14px;
            border-radius: 14px;
            background: linear-gradient(180deg, #0f766e, #0d615a);
            color: #f8fafc;
            cursor: pointer;
            font-size: 0.86rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            box-shadow: 0 12px 26px rgba(15,118,110,0.22);
        }
        .product-create-toggle::before {
            content: "+";
            font-size: 1rem;
            line-height: 1;
        }
        .product-create-panel[open] .product-create-toggle {
            margin-bottom: 10px;
        }
        .product-create-body {
            display: grid;
            gap: 10px;
            padding: 12px;
            border-radius: 18px;
            border: 1px solid rgba(22,50,79,0.08);
            background: linear-gradient(180deg, rgba(255,255,255,0.82), rgba(247,242,233,0.72));
        }
        .product-item {
            display: block;
            padding: 0;
            overflow: hidden;
        }
        .product-item summary {
            list-style: none;
        }
        .product-item summary::-webkit-details-marker {
            display: none;
        }
        .product-item-summary {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            padding: 15px;
            cursor: pointer;
        }
        .product-item-summary::after {
            content: "Buka";
            color: var(--blue);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            flex-shrink: 0;
        }
        .product-item[open] .product-item-summary::after {
            content: "Tutup";
        }
        .product-item-brief {
            display: grid;
            gap: 10px;
            min-width: 0;
            flex: 1;
        }
        .product-title-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .product-title {
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: -0.03em;
        }
        .product-brief-copy {
            margin: 0;
            color: var(--muted);
            font-size: 0.78rem;
            line-height: 1.5;
        }
        .product-brief-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .product-brief-chip {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255,255,255,0.86);
            border: 1px solid rgba(22,50,79,0.08);
            color: #314252;
            font-size: 0.74rem;
            font-weight: 600;
            line-height: 1.3;
        }
        .product-item-body {
            display: grid;
            gap: 14px;
            padding: 0 15px 15px;
            border-top: 1px solid rgba(22,50,79,0.08);
        }
        .product-item-body-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(240px, 300px);
            gap: 14px;
            align-items: start;
            padding-top: 14px;
        }
        .product-meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 8px;
        }
        .product-meta {
            padding: 9px 10px;
            border-radius: 14px;
            border: 1px solid rgba(22,50,79,0.08);
            background: rgba(255,255,255,0.82);
        }
        .product-meta span {
            display: block;
            color: var(--muted);
            font-size: 0.66rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .product-meta strong {
            display: block;
            margin-top: 4px;
            font-size: 0.84rem;
            letter-spacing: -0.02em;
        }
        .product-history {
            padding: 10px 12px;
            border-radius: 14px;
            border: 1px solid rgba(22,50,79,0.08);
            background: rgba(245,248,248,0.74);
        }
        .product-history summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            list-style: none;
        }
        .product-history summary::-webkit-details-marker {
            display: none;
        }
        .product-history summary::after {
            content: "Buka";
            color: var(--blue);
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .product-history[open] summary::after {
            content: "Tutup";
        }
        .product-actions-title {
            margin: 0 0 8px;
            color: #314252;
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .product-price-form {
            padding: 12px;
            border-radius: 16px;
            border: 1px solid rgba(22,50,79,0.08);
            background: linear-gradient(180deg, rgba(255,255,255,0.84), rgba(247,242,233,0.72));
        }
        .product-price-form .small {
            margin-top: 2px;
        }
        .product-footnote {
            margin-top: 12px;
            padding: 10px 12px;
            border-radius: 14px;
            background: rgba(15,118,110,0.06);
            color: #405162;
            font-size: 0.78rem;
            line-height: 1.5;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            padding: 5px 10px;
            border-radius: 999px;
            background: rgba(15,118,110,0.12);
            color: var(--blue);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .pill-archive {
            background: rgba(180,83,9,0.12);
            color: var(--yellow);
        }
        .scroll-box,
        .asset-scroll {
            max-height: 520px;
        }
        .compact-note {
            margin-top: 14px;
            padding: 12px 14px;
            border-radius: 16px;
            background: rgba(15,118,110,0.06);
            color: #3a4a58;
            font-size: 0.84rem;
            line-height: 1.6;
        }
        .empty-state {
            padding: 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.72);
            border: 1px dashed rgba(22,50,79,0.14);
            color: var(--muted);
        }
        .reveal {
            animation: riseIn .5s ease both;
        }
        @keyframes riseIn {
            from {
                opacity: 0;
                transform: translateY(14px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            .reveal { animation: none; }
            .action-subbtn,
            button,
            input,
            select,
            textarea {
                transition: none;
            }
        }
        @media (max-width: 1180px) {
            .hero {
                grid-template-columns: 1fr;
            }
            .board {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 920px) {
            .metrics-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .modal-grid {
                grid-template-columns: 1fr;
            }
            .workspace-shell-head {
                flex-direction: column;
            }
            .workspace-shell-note {
                max-width: 100%;
            }
            .workspace-active {
                grid-template-columns: 1fr;
            }
            .workspace-active-meta {
                justify-content: flex-start;
            }
        }
        @media (max-width: 768px) {
            .page-shell {
                width: min(100%, calc(100% - 16px));
                margin: 12px auto 28px;
            }
            body::before,
            body::after {
                display: none;
            }
            .hero,
            .panel,
            .card,
            .modal-box {
                border-radius: 20px;
                padding: 15px;
            }
            .hero h1 {
                max-width: 100%;
                font-size: clamp(1.7rem, 7vw, 2.4rem);
            }
            .hero-lead,
            .section-copy,
            .panel-copy {
                font-size: 0.86rem;
                line-height: 1.55;
            }
            .action-cluster-grid {
                grid-template-columns: 1fr;
            }
            .workspace-shell,
            .workspace-active {
                border-radius: 22px;
            }
            .workspace-tabs {
                margin: 0 -2px;
                padding: 5px;
            }
            .section-head,
            .panel-head,
            .modal-header {
                gap: 10px;
            }
            .action-group-head {
                flex-direction: column;
            }
            .manage-item {
                grid-template-columns: 1fr;
            }
            .product-item-summary {
                flex-direction: column;
            }
            .spotlight-value {
                font-size: clamp(1.9rem, 9vw, 2.45rem);
            }
            th, td {
                padding: 12px;
            }
            .summary-row {
                align-items: flex-start;
            }
            .workspace-active-meta {
                gap: 6px;
            }
            .modal {
                align-items: end;
                padding: 0;
            }
            .modal-box {
                width: 100%;
                max-height: 92vh;
                border-radius: 22px 22px 0 0;
            }
            .modal-box::before {
                height: 88px;
            }
            .modal-header {
                padding-right: 44px;
                position: relative;
            }
            .modal-close {
                position: absolute;
                top: 0;
                right: 0;
                width: 36px;
                height: 36px;
                min-width: 36px;
            }
            .modal-form-footer {
                position: static;
                padding-top: 6px;
                background: transparent;
            }
            .modal-submit {
                width: 100%;
                min-width: 0;
            }
            .stock-grid {
                grid-template-columns: 1fr;
            }
            .product-item-body-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 680px) {
            .ingredients-table {
                min-width: 0;
                table-layout: auto;
            }
            .ingredients-table colgroup,
            .ingredients-table thead {
                display: none;
            }
            .ingredients-table,
            .ingredients-table tbody,
            .ingredients-table tr,
            .ingredients-table td {
                display: block;
                width: 100%;
            }
            .ingredients-table tbody {
                display: grid;
                gap: 10px;
                padding: 12px;
            }
            .ingredients-table tr {
                padding: 12px;
                border-radius: 16px;
                border: 1px solid rgba(22,50,79,0.08);
                background: rgba(255,255,255,0.84);
                box-shadow: inset 0 1px 0 rgba(255,255,255,0.9);
            }
            .ingredients-table td {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 12px;
                padding: 0;
                border-bottom: none;
                text-align: left !important;
            }
            .ingredients-table td + td {
                margin-top: 8px;
            }
            .ingredients-table td::before {
                content: attr(data-label);
                color: var(--muted);
                font-size: 0.66rem;
                font-weight: 700;
                letter-spacing: 0.06em;
                text-transform: uppercase;
            }
            .ingredients-table td:first-child {
                display: block;
                margin-bottom: 8px;
                padding-bottom: 8px;
                border-bottom: 1px solid rgba(22,50,79,0.08);
                font-size: 0.98rem;
                font-weight: 700;
                letter-spacing: -0.02em;
            }
            .ingredients-table td:first-child::before {
                display: none;
            }
            .ingredients-table tbody tr:hover {
                background: rgba(15,118,110,0.05);
            }
        }
        @media (max-width: 560px) {
            .hero-copy,
            .hero-side {
                gap: 14px;
            }
            .eyebrow {
                width: 100%;
                justify-content: center;
            }
            .metrics-grid {
                grid-template-columns: 1fr;
            }
            .metric-top {
                flex-wrap: wrap;
            }
            .summary-row {
                min-width: 0;
                flex-direction: column;
                gap: 6px;
            }
            .panel-form-actions {
                display: block;
            }
            .row {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            .panel-badge {
                font-size: 0.72rem;
                padding: 8px 10px;
                white-space: normal;
            }
            .workspace-link {
                min-width: 168px;
                padding: 12px 13px;
            }
            .workspace-active {
                padding: 16px;
            }
            .modal-subtitle {
                max-width: 100%;
            }
            .product-brief-meta {
                flex-direction: column;
                align-items: stretch;
            }
            .product-meta-grid {
                grid-template-columns: 1fr;
            }
        }

html {
    -webkit-text-size-adjust: 100%;
    text-size-adjust: 100%;
}

:root {
    --bg: #eef2f6;
    --bg-accent: #f8fafc;
    --surface: rgba(255, 255, 255, 0.82);
    --surface-strong: rgba(255, 255, 255, 0.96);
    --surface-soft: rgba(248, 250, 252, 0.9);
    --panel2: #f8fafc;
    --line: rgba(15, 23, 42, 0.09);
    --line-strong: rgba(15, 23, 42, 0.16);
    --text: #102033;
    --muted: #64748b;
    --blue: #2563eb;
    --green: #0f766e;
    --red: #b45309;
    --yellow: #ca8a04;
    --navy: #0f172a;
    --accent-soft: rgba(37, 99, 235, 0.1);
    --shadow: 0 18px 34px rgba(15, 23, 42, 0.09);
    --shadow-soft: 0 8px 18px rgba(15, 23, 42, 0.05);
    --radius-xl: 38px;
    --radius-lg: 28px;
    --radius-md: 20px;
}

html,
body {
    font-family: "Plus Jakarta Sans", sans-serif;
    overflow-x: clip;
    max-width: 100%;
    background: linear-gradient(180deg, #f7f9fc 0%, #eef2f7 100%);
}

body::before {
    display: none;
}

body::after {
    display: none;
}

h1,
h2,
h3,
h4,
h5,
h6,
button,
.metric,
.eyebrow,
.section-kicker,
.badge,
.pill {
    font-family: "Sora", sans-serif;
}

::selection {
    background: rgba(15, 118, 110, 0.2);
    color: var(--navy);
}

.page-shell {
    width: 100%;
    max-width: none;
    margin: 0;
    overflow-x: clip;
}

.container {
    gap: 24px;
}

.hero {
    grid-template-columns: minmax(0, 1.45fr) minmax(360px, 0.95fr);
    gap: 22px;
    min-height: 0;
    align-items: center;
    padding: clamp(26px, 3vw, 38px);
    border-radius: 38px;
    background:
        linear-gradient(145deg, rgba(255,255,255,0.96), rgba(245,248,251,0.9)),
        linear-gradient(120deg, rgba(37,99,235,0.08), transparent 38%);
    border: 1px solid rgba(255,255,255,0.82);
    box-shadow: var(--shadow);
    overflow: hidden;
}

.hero::before {
    background:
        radial-gradient(circle at top left, rgba(37,99,235,0.1), transparent 34%),
        linear-gradient(125deg, rgba(37,99,235,0.06), transparent 42%),
        linear-gradient(300deg, rgba(15,23,42,0.05), transparent 28%);
}

.hero::after {
    content: "";
    position: absolute;
    right: -70px;
    bottom: -90px;
    width: 280px;
    height: 280px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(37, 99, 235, 0.1), transparent 70%);
    pointer-events: none;
    z-index: 0;
}

.hero-copy {
    display: grid;
    align-content: center;
    gap: 18px;
}

.hero h1 {
    max-width: 11ch;
    font-size: clamp(2.75rem, 5vw, 4.6rem);
    line-height: 0.94;
    letter-spacing: -0.07em;
}

.hero-lead {
    max-width: 62ch;
    margin: 18px 0 0;
    font-size: 1rem;
    line-height: 1.78;
    color: #536476;
}

.eyebrow {
    gap: 12px;
    padding: 8px 14px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    background: rgba(255, 255, 255, 0.72);
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
}

.eyebrow::before {
    width: 11px;
    height: 11px;
    background: linear-gradient(180deg, #60a5fa, #2563eb);
    box-shadow: 0 0 0 7px rgba(37, 99, 235, 0.12);
}

.hero-strip {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
}

.hero-stat,
.hero-mini-card {
    position: relative;
    overflow: hidden;
    backdrop-filter: blur(12px);
}

.hero-stat {
    display: grid;
    gap: 8px;
    padding: 18px 18px 20px;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.58);
    border: 1px solid rgba(15, 23, 42, 0.08);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.78);
}

.hero-stat::before,
.hero-mini-card::before,
.panel::before,
.card::before,
.metric-card::before,
.workspace-shell::before,
.workspace-active::before,
.action-group::before,
.product-create-panel::before,
.manage-item::before {
    content: "";
    position: absolute;
    inset: 0 0 auto;
    height: 4px;
    background: linear-gradient(90deg, #0f766e, #0b7285 58%, #d97706);
    opacity: 0.9;
}

.hero-stat span,
.hero-mini-label {
    font-size: 0.74rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--blue);
}

.hero-stat strong,
.hero-mini-value {
    font-family: "Sora", sans-serif;
    font-size: 1.28rem;
    line-height: 1.18;
    letter-spacing: -0.04em;
    color: var(--navy);
}

.hero-stat small,
.hero-mini-note {
    margin: 0;
    color: var(--muted);
    line-height: 1.6;
    font-size: 0.88rem;
}

.hero-side {
    grid-template-rows: minmax(0, 1fr);
    gap: 0;
    align-content: center;
}

.hero-spotlight {
    display: grid;
    gap: 14px;
    padding: 26px;
    border-radius: 30px;
    background:
        linear-gradient(160deg, rgba(15,23,42,0.98), rgba(30,41,59,0.96) 58%, rgba(37,99,235,0.84) 160%);
    box-shadow: 0 32px 56px rgba(15, 23, 42, 0.28);
}

.hero-spotlight::after {
    content: "";
    position: absolute;
    inset: auto -20px -70px auto;
    width: 180px;
    height: 180px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(255,255,255,0.16), transparent 72%);
}

.hero-spotlight .eyebrow {
    margin-bottom: 4px;
    background: rgba(255,255,255,0.1);
    border-color: rgba(255,255,255,0.1);
    box-shadow: none;
}

.hero-spotlight .eyebrow::before {
    background: linear-gradient(180deg, #93c5fd, #3b82f6);
    box-shadow: 0 0 0 7px rgba(59, 130, 246, 0.14);
}

.spotlight-value {
    font-family: "Sora", sans-serif;
    font-size: clamp(2.35rem, 4.2vw, 3.4rem);
    margin: 0;
    letter-spacing: -0.06em;
}

.spotlight-copy {
    max-width: 30ch;
    font-size: 0.94rem;
    line-height: 1.7;
    color: rgba(241, 245, 249, 0.82);
}

.hero-side-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.hero-mini-card {
    display: grid;
    gap: 10px;
    min-height: 138px;
    padding: 18px;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.72);
    border: 1px solid rgba(15, 23, 42, 0.08);
    box-shadow: var(--shadow-soft);
}

.workspace-hub,
.section {
    gap: 18px;
}

.workspace-shell,
.panel,
.card,
.metric-card,
.action-group,
.product-create-panel,
.manage-item {
    position: relative;
    overflow: hidden;
    background: linear-gradient(180deg, rgba(255,255,255,0.9), rgba(246,248,251,0.86));
    border: 1px solid rgba(255,255,255,0.82);
    box-shadow: var(--shadow-soft);
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
}

.workspace-shell {
    --workspace-corner: 32px;
    display: grid;
    gap: 0;
    padding: 0 0 24px;
    border-radius: 0 0 var(--workspace-corner) var(--workspace-corner);
    overflow: visible;
    background: linear-gradient(180deg, rgba(255,255,255,0.9), rgba(246,248,251,0.86));
    border: none;
    box-shadow: none;
}

.workspace-shell::before {
    display: none;
}

.workspace-tabs {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    align-items: stretch;
    gap: 0;
    position: sticky;
    top: 0;
    z-index: 40;
    isolation: isolate;
    width: 100%;
    min-width: 0;
    margin-top: 0;
    padding: 0;
    overflow: visible;
    background: linear-gradient(180deg, rgba(244, 247, 251, 0.98), rgba(244, 247, 251, 0.94));
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
    border-bottom: none;
    border-radius: 0 !important;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
    scrollbar-width: none;
    -ms-overflow-style: none;
    border-top-left-radius: 0;
    border-top-right-radius: 0;
}

.workspace-tabs::after {
    display: none;
}

.workspace-tabs::-webkit-scrollbar {
    display: none;
}

.workspace-link {
    display: flex;
    appearance: none;
    -webkit-appearance: none;
    flex: 1 1 0;
    position: relative;
    align-items: center;
    justify-content: center;
    min-width: 0 !important;
    max-width: 100%;
    min-height: 64px;
    width: 100%;
    padding: 0 12px 14px;
    border: none !important;
    border-bottom: none !important;
    border-radius: 0 !important;
    background: transparent !important;
    box-shadow: none;
    text-align: center;
    transition: background-color 0.22s ease, color 0.22s ease, opacity 0.22s ease, box-shadow 0.22s ease;
    z-index: 1;
    box-shadow: inset 0 -1px 0 rgba(15, 23, 42, 0.08);
}

.workspace-link + .workspace-link {
    border-left: 1px solid rgba(15, 23, 42, 0.1);
}

.workspace-link strong {
    display: block;
    min-width: 0;
    margin-top: 0;
    max-width: 100%;
    font-size: clamp(0.72rem, 1.1vw, 0.92rem);
    line-height: 1.12;
    letter-spacing: -0.02em;
    color: rgba(15, 23, 42, 0.52);
    text-align: center;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.workspace-link-kicker,
.section-kicker,
.modal-kicker,
.action-group-label {
    display: inline-block;
    font-size: 0.74rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--blue);
}

.workspace-link-kicker {
    display: none;
}

.workspace-link:hover,
.workspace-link:focus-visible {
    transform: none;
    background: rgba(255, 255, 255, 0.62) !important;
    border-color: transparent;
    box-shadow: none;
}

.workspace-link.is-active {
    z-index: 3;
    background: linear-gradient(180deg, rgba(255,255,255,0.995), rgba(249,250,252,0.99)) !important;
    box-shadow:
        0 -4px 0 var(--blue) inset,
        0 0 0 1px rgba(226, 232, 240, 0.92),
        0 10px 18px rgba(15, 23, 42, 0.05);
}

.workspace-link.is-active:first-child {
    border-top-left-radius: calc(var(--workspace-corner) - 6px) !important;
}

.workspace-link.is-active:last-child {
    border-top-right-radius: calc(var(--workspace-corner) - 6px) !important;
}

.workspace-link.is-active::before,
.workspace-link.is-active::after {
    content: "";
    position: absolute;
    left: 0;
    right: 0;
    border-radius: 0 !important;
}

.workspace-link.is-active::before {
    top: 0;
    height: 4px;
    background: var(--blue);
}

.workspace-link.is-active::after {
    left: -1px;
    right: -1px;
    bottom: -10px;
    height: 12px;
    background: linear-gradient(180deg, rgba(249,250,252,0.995), rgba(248,250,252,0.985));
    box-shadow: 0 1px 0 rgba(226, 232, 240, 0.92) inset;
    z-index: -1;
}

.workspace-link.is-active strong,
.workspace-link.is-active .workspace-link-kicker {
    color: var(--navy);
}

.workspace-stage {
    display: grid;
    gap: 14px;
    position: relative;
    z-index: 1;
    background: transparent;
    margin-top: 0;
    padding: 14px 22px 0;
}

.workspace-stage::before {
    display: none;
}

.workspace-stage > *,
.metrics-grid > *,
.overview-grid > *,
.board > *,
.stack > *,
.row > *,
.action-cluster-grid > * {
    content-visibility: auto;
    contain-intrinsic-size: 320px 480px;
    contain: layout paint style;
    position: relative;
    z-index: 1;
}

.section-head {
    padding: 0 4px;
    align-items: end;
}

.section-title {
    margin: 6px 0 0;
    font-size: clamp(1.28rem, 2vw, 1.7rem);
    letter-spacing: -0.05em;
}

.section-copy {
    max-width: 60ch;
    font-size: 0.9rem;
    line-height: 1.62;
}

.metrics-grid {
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: 14px;
}

.metric-card {
    grid-column: span 12;
    min-height: 188px;
    padding: 18px;
    border-radius: 24px;
}

.metric-top,
.panel-head,
.panel-head-inline,
.product-card-head-row,
.action-group-head {
    gap: 12px;
}

.metric-label {
    font-size: 0.76rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--muted);
}

.metric-chip,
.panel-badge,
.pill,
.action-group-count {
    padding: 7px 11px;
    border-radius: 999px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    background: rgba(255,255,255,0.76);
    color: var(--navy);
    font-size: 0.74rem;
    font-weight: 700;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.88);
    white-space: nowrap;
}

.metric {
    font-size: clamp(1.72rem, 2.7vw, 2.35rem);
    margin: 18px 0 10px;
    line-height: 0.98;
    letter-spacing: -0.06em;
    color: var(--navy);
}

.metric-note {
    line-height: 1.58;
    font-size: 0.86rem;
    color: var(--muted);
}

.overview-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}

.overview-card {
    position: relative;
    overflow: hidden;
    padding: 18px;
    border-radius: 22px;
    background: linear-gradient(180deg, rgba(255,255,255,0.94), rgba(246,248,251,0.88));
    border: 1px solid rgba(255, 255, 255, 0.82);
    box-shadow: var(--shadow-soft);
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
}

.overview-card::before {
    content: "";
    position: absolute;
    inset: 0 0 auto;
    height: 4px;
    background: linear-gradient(90deg, #2563eb, #60a5fa 58%, #93c5fd);
}

.overview-card.is-emphasis {
    background:
        linear-gradient(145deg, rgba(15,23,42,0.98), rgba(30,41,59,0.96) 68%, rgba(37,99,235,0.8) 170%);
    border-color: rgba(37, 99, 235, 0.2);
}

.overview-label {
    display: inline-block;
    font-size: 0.74rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--blue);
}

.overview-value {
    display: block;
    margin: 10px 0 8px;
    font-family: "Sora", sans-serif;
    font-size: clamp(1.28rem, 2vw, 1.85rem);
    line-height: 1.05;
    letter-spacing: -0.05em;
    color: var(--navy);
}

.overview-note {
    margin: 0;
    font-size: 0.84rem;
    line-height: 1.56;
    color: var(--muted);
}

.overview-card.is-emphasis .overview-label {
    color: #bfdbfe;
}

.overview-card.is-emphasis .overview-value {
    color: #f8fafc;
}

.overview-card.is-emphasis .overview-note {
    color: rgba(241, 245, 249, 0.82);
}

.board {
    grid-template-columns: minmax(0, 1.55fr) minmax(320px, 0.95fr);
    gap: 14px;
    align-items: start;
}

.board-single {
    grid-template-columns: minmax(0, 1fr);
}

.board-sync-panels {
    align-items: stretch;
}

.board-sync-panels > * {
    min-height: 0;
}

.stack {
    gap: 14px;
}

.board-sync-sidebar {
    align-content: start;
}

.panel,
.card {
    padding: 18px;
    border-radius: 24px;
}

.synced-board-panel {
    height: var(--synced-panel-height, auto);
    min-height: 0;
}

.synced-scroll-panel {
    display: grid;
    grid-template-rows: auto minmax(0, 1fr);
    align-content: start;
}

.panel-title {
    margin: 0;
    font-size: 1.12rem;
    letter-spacing: -0.05em;
    color: var(--navy);
}

.panel-copy,
.product-card-copy {
    margin: 8px 0 0;
    line-height: 1.58;
    font-size: 0.9rem;
    color: var(--muted);
}

.table-shell,
.scroll-box,
.asset-scroll {
    overflow-x: auto;
    overflow-y: visible;
    border-radius: 20px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    background: rgba(255,255,255,0.74);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.82);
}

.synced-scroll-shell {
    min-height: 0;
    height: 100%;
    overflow-x: auto;
    overflow-y: auto;
    scrollbar-gutter: stable;
}

.scroll-box {
    max-height: none;
}

.history-scroll-box {
    display: grid;
    gap: 12px;
    align-content: start;
}

.history-load-state {
    padding: 2px 4px 0;
    text-align: center;
    color: var(--muted);
    font-size: 0.82rem;
    line-height: 1.5;
}

.history-load-state.is-loading {
    color: var(--navy);
}

.history-load-state.is-error {
    color: var(--red);
}

.history-load-state.is-complete {
    color: #496074;
}

.history-load-state.is-hidden {
    display: none;
}

.history-load-sentinel {
    width: 100%;
    height: 1px;
}

.asset-scroll {
    max-height: none;
}

.data-table {
    min-width: 720px;
    border-collapse: separate;
    border-spacing: 0;
}

.logs-table {
    min-width: 760px;
}

.data-table thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    padding: 12px 14px;
    background: #f3f6fa;
    border-bottom: 1px solid rgba(15, 23, 42, 0.12);
    color: var(--muted);
    font-size: 0.7rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.data-table tbody td {
    padding: 13px 14px;
    border-bottom: 1px solid rgba(15, 23, 42, 0.08);
    background: rgba(255,255,255,0.48);
    color: var(--text);
    line-height: 1.5;
    vertical-align: top;
}

.data-table tbody tr:nth-child(even) td {
    background: rgba(246,248,251,0.92);
}

.data-table tbody tr:hover td {
    background: rgba(37,99,235,0.05);
}

.summary-list {
    gap: 8px;
}

.summary-row {
    padding: 12px 14px;
    border-radius: 16px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    background: rgba(255,255,255,0.66);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.8);
}

.summary-row span {
    color: var(--muted);
}

.summary-row strong {
    font-family: "Sora", sans-serif;
    font-size: 0.94rem;
    letter-spacing: -0.03em;
    text-align: right;
    color: var(--navy);
}

.action-cluster-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
}

.action-group {
    border-radius: 28px;
}

.action-group-summary,
.product-item-summary,
.product-create-toggle {
    position: relative;
    list-style: none;
    cursor: pointer;
    padding-right: 56px;
}

.action-group-summary {
    display: grid;
    gap: 12px;
    min-height: 188px;
    padding: 18px 50px 18px 18px;
}

.action-group-summary::after,
.product-item-summary::after,
.product-create-toggle::after {
    content: "" !important;
    position: absolute;
    right: 24px;
    top: 50%;
    width: 11px;
    height: 11px;
    border-right: 2px solid rgba(15, 23, 42, 0.48);
    border-bottom: 2px solid rgba(15, 23, 42, 0.48);
    transform: translateY(-70%) rotate(45deg);
    transition: transform 0.2s ease;
    pointer-events: none;
}

.action-group[open] > .action-group-summary::after,
.manage-item[open] > .product-item-summary::after,
.product-create-panel[open] > .product-create-toggle::after {
    content: "" !important;
    transform: translateY(-20%) rotate(-135deg);
}

.action-group-title {
    margin: 6px 0 0;
    font-size: 1.1rem;
    letter-spacing: -0.04em;
    color: var(--navy);
}

.action-group-copy {
    margin: 0;
    line-height: 1.66;
    color: var(--muted);
}

.action-subactions {
    gap: 10px;
    padding: 0 18px 18px;
}

@media (min-width: 1181px) {
    .action-group-summary {
        cursor: default;
        padding-right: 18px;
    }

    .action-group-summary::after {
        display: none;
    }

    .action-group > summary {
        pointer-events: none;
    }
}

.action-subbtn {
    display: grid;
    gap: 7px;
    width: 100%;
    padding: 15px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    border-radius: 18px;
    background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(246,248,251,0.94));
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.88);
    text-align: left;
}

.action-subbtn:hover,
.action-subbtn:focus-visible {
    transform: translateY(-2px);
    border-color: rgba(37, 99, 235, 0.18);
    box-shadow: 0 18px 30px rgba(15, 23, 42, 0.08);
}

.action-subbtn small {
    font-family: "Sora", sans-serif;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--blue);
}

.action-subbtn strong {
    font-family: "Sora", sans-serif;
    font-size: 1rem;
    letter-spacing: -0.03em;
    color: var(--navy);
}

.action-subbtn span {
    line-height: 1.58;
    color: var(--muted);
}

.empty-state,
.empty-modal-state,
.compact-note,
.product-footnote {
    padding: 14px 16px;
    border-radius: 18px;
    border: 1px dashed rgba(37, 99, 235, 0.2);
    background: linear-gradient(135deg, rgba(37,99,235,0.06), rgba(255,255,255,0.8));
    line-height: 1.68;
    color: #334155;
}

.compact-note {
    margin-top: 14px;
}

.product-modal-stack,
.manage-list,
.modal-form,
.price-form,
.report-form,
.field-stack {
    gap: 14px;
}

.report-export-panel {
    padding: 20px 22px;
}

.report-export-panel .panel-head {
    align-items: start;
}

.report-export-form {
    gap: 16px;
}

.report-export-grid {
    display: grid;
    grid-template-columns: minmax(280px, 0.95fr) minmax(0, 1.05fr);
    gap: 14px;
    align-items: end;
}

.report-export-field {
    display: grid;
    gap: 8px;
    max-width: 560px;
}

.report-export-range {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.report-export-subfield {
    display: grid;
    gap: 7px;
}

.report-export-subfield > span {
    font-size: 0.76rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: rgba(71, 85, 105, 0.88);
}

.report-export-note {
    display: flex;
    align-items: center;
    min-height: 100%;
    margin: 0;
    padding: 14px 16px;
    border-radius: 18px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    background: linear-gradient(180deg, rgba(255,255,255,0.9), rgba(246,248,251,0.86));
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.86);
}

.report-export-actions {
    justify-content: flex-start;
    margin-top: 0;
}

.report-export-btn {
    min-width: 250px;
}

.modal {
    display: none;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(15, 23, 42, 0.48);
    backdrop-filter: blur(14px);
}

.modal.show {
    display: flex;
}

.modal-box {
    width: min(1180px, 100%);
    max-height: 90vh;
    max-height: 90dvh;
    overflow: auto;
    margin: auto;
    position: relative;
    padding: 0 24px 24px;
    border-radius: 32px;
    background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(245,248,252,0.96));
    border: 1px solid rgba(255,255,255,0.82);
    box-shadow: 0 42px 84px rgba(15, 23, 42, 0.24);
}

.modal-box::before {
    display: none !important;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    position: sticky;
    top: 0;
    margin: 0 -24px 12px;
    padding: 12px 16px;
    background: rgba(250, 252, 254, 0.94);
    border-bottom: 1px solid rgba(15, 23, 42, 0.06);
    backdrop-filter: none;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
    border-top-left-radius: 32px;
    border-top-right-radius: 32px;
    z-index: 4;
}

.modal-header h3 {
    margin: 8px 0 0;
    font-size: 1.5rem;
    letter-spacing: -0.05em;
    color: var(--navy);
}

.modal-close {
    position: relative;
    flex: 0 0 auto;
    width: 48px;
    height: 48px;
    border-radius: 50%;
    border: 1px solid rgba(15, 23, 42, 0.08);
    background: rgba(255,255,255,0.72);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.8);
}

.modal-close:hover,
.modal-close:focus-visible {
    transform: rotate(90deg);
    background: rgba(15, 118, 110, 0.1);
}

.modal-close-icon::before,
.modal-close-icon::after {
    content: "";
    position: absolute;
    top: 50%;
    left: 50%;
    width: 18px;
    height: 2px;
    border-radius: 999px;
    background: var(--navy);
    transform: translate(-50%, -50%) rotate(45deg);
}

.modal-close-icon::after {
    transform: translate(-50%, -50%) rotate(-45deg);
}

.manage-item {
    border-radius: 24px;
}

.product-create-panel {
    border: none;
    background: transparent;
    box-shadow: none;
    overflow: visible;
}

.product-create-panel::before {
    display: none;
}

.product-create-toggle {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 14px;
    min-height: 62px;
    padding: 14px 58px 14px 16px;
    border-radius: 20px;
    border: 1px solid rgba(15, 118, 110, 0.16);
    background: linear-gradient(135deg, rgba(255,255,255,0.98), rgba(233,244,242,0.96));
    box-shadow: 0 12px 28px rgba(15, 118, 110, 0.10), inset 0 1px 0 rgba(255,255,255,0.94);
    font-size: clamp(0.98rem, 1.18vw, 1.08rem);
    font-weight: 760;
    letter-spacing: -0.03em;
    color: var(--navy);
    text-align: left;
}

.product-create-toggle::before {
    content: "";
    display: block;
    width: 30px;
    height: 30px;
    margin-right: 0;
    border-radius: 999px;
    background:
        linear-gradient(#f8fafc, #f8fafc) center / 12px 2.5px no-repeat,
        linear-gradient(#f8fafc, #f8fafc) center / 2.5px 12px no-repeat,
        linear-gradient(135deg, #3f8c82 0%, #2f726b 100%);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.16), 0 8px 18px rgba(15, 118, 110, 0.16);
}

.product-create-toggle::after {
    right: 22px;
    width: 10px;
    height: 10px;
    border-right: 2.5px solid rgba(15, 23, 42, 0.32);
    border-bottom: 2.5px solid rgba(15, 23, 42, 0.32);
}

.product-create-toggle:hover,
.product-create-toggle:focus-visible {
    transform: translateY(-1px);
    background: linear-gradient(135deg, rgba(255,255,255,0.99), rgba(225,240,237,0.98));
    box-shadow: 0 14px 30px rgba(15, 118, 110, 0.12), inset 0 1px 0 rgba(255,255,255,0.98);
}

.product-create-panel[open] > .product-create-toggle {
    margin-bottom: 10px;
    border-color: rgba(15, 118, 110, 0.22);
    background: linear-gradient(135deg, rgba(244,251,249,0.99), rgba(221,239,235,0.98));
    box-shadow: 0 14px 32px rgba(15, 118, 110, 0.12), inset 0 1px 0 rgba(255,255,255,0.98);
}

.product-create-body,
.product-item-body {
    padding: 0 22px 22px;
}

.product-item-body-grid {
    grid-template-columns: minmax(0, 1.4fr) minmax(270px, 0.8fr);
    gap: 16px;
}

.product-title-row {
    gap: 12px;
}

.product-title {
    font-size: 1.08rem;
    letter-spacing: -0.04em;
    color: var(--navy);
}

.product-brief-copy {
    margin: 8px 0 0;
    line-height: 1.62;
    color: var(--muted);
}

.product-brief-meta {
    margin-top: 14px;
    gap: 8px;
}

.product-brief-chip {
    padding: 8px 12px;
    border-radius: 999px;
    background: rgba(15, 118, 110, 0.08);
    border: 1px solid rgba(15, 118, 110, 0.16);
    color: #24505d;
    font-size: 0.82rem;
    font-weight: 700;
}

.manage-main,
.manage-actions {
    display: grid;
    gap: 14px;
}

.manage-actions {
    align-content: start;
    padding: 18px;
    border-radius: 22px;
    background: rgba(246,248,251,0.94);
    border: 1px solid rgba(15, 23, 42, 0.08);
}

.product-actions-title,
label {
    font-size: 0.76rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #496074;
}

.product-meta-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.product-meta,
.stock-item {
    padding: 16px;
    border-radius: 18px;
    background: rgba(255,255,255,0.72);
    border: 1px solid rgba(15, 23, 42, 0.08);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.8);
}

.product-meta span {
    font-size: 0.76rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--muted);
}

.product-meta strong,
.stock-item strong {
    display: block;
    margin-top: 6px;
    font-family: "Sora", sans-serif;
    font-size: 1rem;
    letter-spacing: -0.03em;
    color: var(--navy);
}

.stock-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.stock-meta {
    line-height: 1.6;
    color: var(--muted);
}

.product-history,
.logs-table details {
    margin-top: 14px;
    padding: 14px 16px;
    border-radius: 18px;
    background: rgba(15, 23, 42, 0.04);
    border: 1px solid rgba(15, 23, 42, 0.08);
}

.product-history > summary,
.logs-table details > summary {
    cursor: pointer;
    font-weight: 700;
    color: var(--navy);
}

.history-list {
    gap: 8px;
    margin-top: 12px;
}

.history-item {
    padding: 10px 12px;
    border-radius: 14px;
    background: rgba(255,255,255,0.82);
    border: 1px solid rgba(15, 23, 42, 0.08);
    color: var(--muted);
    line-height: 1.56;
}

input[type="text"],
input[type="number"],
input[type="date"],
select,
textarea {
    width: 100%;
    padding: 14px 16px;
    border-radius: 16px;
    border: 1px solid rgba(15, 23, 42, 0.12);
    background: rgba(255,255,255,0.92);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.92);
    color: var(--text);
    transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
}

textarea {
    min-height: 120px;
    resize: vertical;
}

input[type="text"]:focus,
input[type="number"]:focus,
input[type="date"]:focus,
select:focus,
textarea:focus,
button:focus-visible,
summary:focus-visible,
a:focus-visible {
    outline: none;
    border-color: rgba(15, 118, 110, 0.42);
    box-shadow: 0 0 0 5px rgba(15, 118, 110, 0.12);
}

.row {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.asset-form-row {
    margin-top: 14px;
    align-items: start;
}

.asset-note-field textarea {
    min-height: 112px;
}

.form-section {
    padding-top: 18px;
    border-top: 1px solid rgba(15, 23, 42, 0.08);
}

.form-section:first-child {
    padding-top: 0;
    border-top: none;
}

.form-section-title {
    margin: 0;
    font-size: 1.04rem;
    letter-spacing: -0.03em;
    color: var(--navy);
}

.form-section-copy,
.field-hint,
.small,
.report-export-note,
.modal-subtitle {
    line-height: 1.62;
    color: var(--muted);
    font-size: 0.86rem;
}

.form-section-copy {
    margin: 8px 0 0;
}

.panel-form-actions,
.modal-form-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 18px;
}

.btn,
.modal-submit,
.price-form button,
.inline-form button,
.product-create-toggle {
    transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, border-color 0.2s ease;
}

.btn,
.modal-submit,
.price-form button,
.inline-form button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 14px 18px;
    border-radius: 16px;
    border: none;
    background: linear-gradient(135deg, #0f172a, #1f2937);
    color: #f8fafc;
    font-family: "Sora", sans-serif;
    font-weight: 700;
    letter-spacing: -0.02em;
    box-shadow: 0 18px 28px rgba(15, 23, 42, 0.16);
    cursor: pointer;
}

.btn:hover,
.btn:focus-visible,
.modal-submit:hover,
.modal-submit:focus-visible,
.price-form button:hover,
.price-form button:focus-visible,
.inline-form button:hover,
.inline-form button:focus-visible {
    transform: translateY(-2px);
    box-shadow: 0 22px 34px rgba(15, 23, 42, 0.18);
}

.btn-danger {
    background: linear-gradient(135deg, #9a3412, #c2410c);
    box-shadow: 0 18px 28px rgba(154, 52, 18, 0.16);
}

.alert {
    padding: 14px 18px;
    border-radius: 20px;
    border: 1px solid transparent;
    box-shadow: var(--shadow-soft);
}

.alert-success {
    background: rgba(15, 118, 110, 0.12);
    border-color: rgba(15, 118, 110, 0.2);
    color: #0f766e;
}

.alert-error {
    background: rgba(185, 28, 28, 0.1);
    border-color: rgba(185, 28, 28, 0.18);
    color: #b91c1c;
}

.ajax-toast-stack {
    top: 18px;
    right: 18px;
    width: min(360px, calc(100% - 36px));
    gap: 12px;
    z-index: 80;
}

.ajax-toast {
    animation: none;
}

@keyframes toast-slide-in {
    from {
        opacity: 0;
        transform: translateY(-10px) scale(0.98);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.badge {
    display: inline-flex;
    align-items: center;
    padding: 8px 12px;
    border-radius: 999px;
    border: 1px solid transparent;
    font-size: 0.76rem;
    letter-spacing: 0.03em;
}

.type-restock {
    background: rgba(15, 118, 110, 0.1);
    border-color: rgba(15, 118, 110, 0.14);
    color: #0f766e;
}

.type-restock_ingredient {
    background: rgba(11, 114, 133, 0.1);
    border-color: rgba(11, 114, 133, 0.14);
    color: #0b7285;
}

.type-update_stock {
    background: rgba(180, 83, 9, 0.12);
    border-color: rgba(180, 83, 9, 0.14);
    color: #b45309;
}

.type-purchase {
    background: rgba(30, 41, 59, 0.1);
    border-color: rgba(30, 41, 59, 0.14);
    color: #1e293b;
}

.type-expense {
    background: rgba(185, 28, 28, 0.1);
    border-color: rgba(185, 28, 28, 0.14);
    color: #b91c1c;
}

.type-owner_withdrawal {
    background: rgba(120, 53, 15, 0.12);
    border-color: rgba(120, 53, 15, 0.14);
    color: #92400e;
}

.good {
    color: var(--green);
}

.bad {
    color: var(--red);
}

body.ajax-busy {
    cursor: progress;
}

button.is-loading,
.btn.is-loading {
    opacity: 0.76;
    pointer-events: none;
}

.reveal {
    animation: none;
}

@keyframes fade-rise {
    from {
        opacity: 0;
        transform: translateY(14px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (min-width: 980px) {
    .metric-card:nth-child(1),
    .metric-card:nth-child(2) {
        grid-column: span 6;
    }

    .metric-card:nth-child(n + 3) {
        grid-column: span 4;
    }
}

@media (max-width: 1180px) {
    .hero,
    .board {
        grid-template-columns: 1fr;
    }

    .overview-grid,
    .stack {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .action-cluster-grid {
        grid-template-columns: 1fr;
    }

    .product-item-body-grid {
        grid-template-columns: 1fr;
    }

    .hero-strip {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .synced-board-panel {
        height: auto;
    }

    .synced-scroll-shell {
        height: auto;
        overflow-y: visible;
    }
}

@media (max-width: 860px) {
    .page-shell {
        width: 100%;
        max-width: none;
        margin: 0;
    }

    .container {
        gap: 16px;
    }

    .hero {
        min-height: auto;
        padding: 20px;
        border-radius: 28px;
    }

    .workspace-shell {
        --workspace-corner: 26px;
        padding: 0 0 18px;
        border-radius: 0 0 var(--workspace-corner) var(--workspace-corner);
    }

    .workspace-tabs {
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        gap: 0 !important;
        padding: 0 !important;
        margin: 0 !important;
        overflow: visible !important;
    }

    .hero-strip,
    .hero-side-grid,
    .overview-grid,
    .stack,
    .row,
    .stock-grid,
    .product-meta-grid {
        grid-template-columns: 1fr;
    }

    .workspace-link {
        flex: 1 1 0 !important;
        min-width: 0 !important;
        min-height: 40px;
        padding: 0 2px 9px;
    }

    .workspace-link strong {
        font-size: 0.62rem !important;
        line-height: 1.02 !important;
        letter-spacing: 0 !important;
    }

    .workspace-stage {
        gap: 14px;
        margin-top: 0;
        padding: 14px 16px 0;
    }

    .metrics-grid {
        grid-template-columns: 1fr;
    }

    .metric-card,
    .metric-card:nth-child(1),
    .metric-card:nth-child(2),
    .metric-card:nth-child(n + 3) {
        grid-column: auto;
    }

    .panel,
    .card,
    .metric-card {
        padding: 18px;
        border-radius: 24px;
    }

    .board-single .panel {
        padding: 16px;
    }

    .report-export-panel {
        padding: 18px;
    }

    .report-export-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .report-export-field {
        max-width: none;
    }

    .report-export-range {
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .board-single .panel-head {
        gap: 10px;
    }

    .board-single .panel-title {
        font-size: 1.02rem;
    }

    .board-single .panel-copy {
        font-size: 0.84rem;
        line-height: 1.5;
    }

    .board-single .panel-badge {
        padding: 6px 9px;
        font-size: 0.7rem;
    }

    .logs-table {
        min-width: 640px;
    }

    .logs-table thead th {
        padding: 10px 11px;
        font-size: 0.64rem;
        letter-spacing: 0.1em;
    }

    .logs-table tbody td {
        padding: 10px 11px;
        font-size: 0.84rem;
        line-height: 1.42;
    }

    .logs-table details {
        margin-top: 10px;
        padding: 10px 12px;
        border-radius: 14px;
    }

    .logs-table details > summary,
    .logs-table .small {
        font-size: 0.76rem;
        line-height: 1.48;
    }

    .logs-table .badge {
        padding: 5px 8px;
        font-size: 0.62rem;
        letter-spacing: 0.08em;
    }

    .scroll-box {
        border-radius: 16px;
    }

    .data-table {
        min-width: 640px;
    }

    .modal {
        padding: 10px;
    }

    .modal-box {
        max-height: 90vh;
        max-height: 90dvh;
        padding: 0 18px 18px;
        border-radius: 24px;
    }

    .modal-header {
        top: 0;
        margin: 0 -18px 10px;
        padding: 10px 12px;
        border-top-left-radius: 24px;
        border-top-right-radius: 24px;
    }
}

@media (max-width: 640px) {
    .hero h1 {
        max-width: none;
        font-size: clamp(2rem, 10vw, 3rem);
    }

    .hero-strip,
    .hero-side-grid,
    .overview-grid,
    .stack,
    .row,
    .stock-grid {
        grid-template-columns: 1fr;
    }

    .workspace-shell,
    .panel,
    .card,
    .metric-card,
    .action-group,
    .product-create-panel,
    .manage-item {
        border-radius: 22px;
    }

    .workspace-shell {
        --workspace-corner: 22px;
        border-radius: 0 0 var(--workspace-corner) var(--workspace-corner);
    }

    .workspace-link {
        flex: 1 1 0 !important;
        min-width: 0 !important;
        min-height: 36px;
        padding: 0 1px 8px;
    }

    .workspace-link strong {
        font-size: 0.56rem !important;
        line-height: 1 !important;
    }

    .workspace-stage {
        gap: 12px;
        margin-top: 0;
        padding: 12px 14px 0;
    }

    .product-create-toggle {
        min-height: 56px;
        padding: 12px 48px 12px 14px;
        border-radius: 18px;
        gap: 10px;
        font-size: 0.94rem;
    }

    .product-create-toggle::before {
        width: 26px;
        height: 26px;
        background:
            linear-gradient(#f8fafc, #f8fafc) center / 10px 2.25px no-repeat,
            linear-gradient(#f8fafc, #f8fafc) center / 2.25px 10px no-repeat,
            linear-gradient(135deg, #3f8c82 0%, #2f726b 100%);
    }

    .product-create-toggle::after {
        right: 18px;
        width: 9px;
        height: 9px;
    }

    .action-group-summary {
        min-height: auto;
    }

    .board-single .panel {
        padding: 14px;
        border-radius: 20px;
    }

    .report-export-panel {
        padding: 14px;
    }

    .report-export-note {
        padding: 12px 13px;
        border-radius: 14px;
        font-size: 0.76rem;
        line-height: 1.46;
    }

    .report-export-subfield > span {
        font-size: 0.72rem;
    }

    .report-export-btn {
        min-width: 0;
        width: 100%;
    }

    .board-single .panel-head {
        gap: 8px;
    }

    .board-single .panel-title {
        font-size: 0.96rem;
    }

    .board-single .panel-copy {
        margin-top: 6px;
        font-size: 0.78rem;
        line-height: 1.42;
    }

    .board-single .panel-badge {
        padding: 5px 8px;
        font-size: 0.64rem;
    }

    .scroll-box {
        border-radius: 14px;
    }

    .logs-table {
        min-width: 520px;
    }

    .logs-table thead th {
        padding: 8px 9px;
        font-size: 0.58rem;
        letter-spacing: 0.08em;
    }

    .logs-table tbody td {
        padding: 9px;
        font-size: 0.78rem;
        line-height: 1.38;
    }

    .logs-table details {
        margin-top: 8px;
        padding: 8px 10px;
        border-radius: 12px;
    }

    .logs-table details > summary,
    .logs-table .small {
        font-size: 0.72rem;
        line-height: 1.42;
    }

    .logs-table .badge {
        padding: 4px 7px;
        font-size: 0.56rem;
    }

    .data-table {
        min-width: 560px;
    }

    .ajax-toast-stack {
        top: 10px;
        right: 10px;
        width: calc(100% - 20px);
    }
}

@media (prefers-reduced-motion: reduce) {
    .reveal,
    .ajax-toast {
        animation: none;
    }

    .workspace-link,
    .action-subbtn,
    .btn,
    .modal-submit,
    .price-form button,
    .inline-form button,
    .modal-close {
        transition: none;
    }
}
    </style>

</head>
<body>
<div class="ajax-toast-stack" id="ajax-toast-stack" aria-live="polite" aria-atomic="true"></div>
<div id="app-root">
<div class="page-shell">
    <main class="container">
        <?php if ($message !== ''): ?>
            <div class="alert alert-success reveal"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error reveal"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="workspace-hub reveal" id="workspace-hub">
            <section class="workspace-shell">
                <div class="workspace-tabs" role="tablist" aria-label="Area kerja dashboard">
                    <?php foreach ($workspaceViews as $workspaceKey => $workspaceView): ?>
                        <button
                            type="button"
                            role="tab"
                            class="workspace-link <?= $workspaceKey === $currentWorkspace ? 'is-active' : '' ?>"
                            data-workspace-target="<?= htmlspecialchars($workspaceKey) ?>"
                            aria-selected="<?= $workspaceKey === $currentWorkspace ? 'true' : 'false' ?>"
                            aria-controls="workspace-stage"
                            tabindex="<?= $workspaceKey === $currentWorkspace ? '0' : '-1' ?>"
                        >
                            <span class="workspace-link-kicker"><?= htmlspecialchars($workspaceView['kicker']) ?></span>
                            <strong><?= htmlspecialchars($workspaceView['title']) ?></strong>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="workspace-stage" id="workspace-stage">
        <?php if ($currentWorkspace === 'ringkasan'): ?>
        <section class="section reveal">
            <div class="metrics-grid">
                <article class="panel metric-card">
                    <div class="metric-top">
                        <span class="metric-label">Saldo Kas</span>
                        <span class="metric-chip">Kas</span>
                    </div>
                    <p class="metric <?= ((float)$summary['cash_balance']) >= 0 ? 'good' : 'bad' ?>"><?= rupiah((float)$summary['cash_balance']) ?></p>
                    <div class="metric-note">Uang yang benar-benar ada di kas setelah restock, pembelian aset, biaya operasional, dan uang masuk aktual dicatat.</div>
                </article>
                <article class="panel metric-card">
                    <div class="metric-top">
                        <span class="metric-label">Posisi Bersih</span>
                        <span class="metric-chip">Utama</span>
                    </div>
                    <p class="metric <?= $netOperationalPosition >= 0 ? 'good' : 'bad' ?>"><?= rupiah($netOperationalPosition) ?></p>
                    <div class="metric-note">Saldo kas + nilai stok dagangan. Card ini fokus ke dana yang masih berputar di kas dan persediaan aktif, tanpa memasukkan aset pembelian.</div>
                </article>
                <article class="panel metric-card">
                    <div class="metric-top">
                        <span class="metric-label">Aset</span>
                        <span class="metric-chip">Aset</span>
                    </div>
                    <p class="metric <?= $assetPosition >= 0 ? 'good' : 'bad' ?>"><?= rupiah($assetPosition) ?></p>
                    <div class="metric-note">Saldo kas + nilai stok + aset pembelian. Nilai ini bisa turun oleh penarikan pemilik, meski laba aktual tidak ikut berkurang.</div>
                </article>
                <article class="panel metric-card">
                    <div class="metric-top">
                        <span class="metric-label">Stok Dagangan</span>
                        <span class="metric-chip">Modal</span>
                    </div>
                    <p class="metric"><?= rupiah($totalStockValue) ?></p>
                    <div class="metric-note"><?= $totalProductStockUnits ?> unit produk siap jual + <?= $totalIngredientStockUnits ?> unit bahan racikan dinilai dari modal rata-rata. Nilai ini masih berupa persediaan, belum menjadi laba sampai barang benar-benar keluar.</div>
                </article>
                <article class="panel metric-card">
                    <div class="metric-top">
                        <span class="metric-label">Laba Aktual</span>
                        <span class="metric-chip">Profit</span>
                    </div>
                    <p class="metric"><?= rupiah((float)$summary['total_actual_profit']) ?></p>
                    <div class="metric-note">Akumulasi laba dari transaksi yang sudah terjadi: uang aktual masuk dikurangi modal barang yang keluar, lalu dikurangi biaya operasional. Penarikan pemilik tidak mengurangi angka ini.</div>
                </article>
            </div>
        </section>

        <section class="board reveal board-single">
            <article class="panel">
                <div class="panel-head panel-head-inline">
                    <div>
                        <h3 class="panel-title">Produk Siap Jual</h3>
                    </div>
                    <span class="panel-badge"><?= $activeReadySellCount ?> item siap jual</span>
                </div>
                <?php if ($hasProducts || $hasMixedMenus): ?>
                    <div class="table-shell">
                        <table class="data-table products-table">
                            <thead><tr><th>Produk</th><th>Stok</th><th>Modal Rata-rata</th><th>Nilai Modal Stok</th><th>Harga Jual Aktif</th><th>Potensi Nilai Jual</th></tr></thead>
                            <tbody>
                            <?php foreach ($products as $product): ?>
                                <?php
                                $stock = (int) $product['stock'];
                                $avgCost = (float) $product['avg_cost'];
                                $sellPrice = (float) $product['sell_price'];
                                ?>
                                <tr>
                                    <td><div><?= htmlspecialchars((string)$product['name']) ?></div><div class="small">Produk stok fisik</div></td>
                                    <td><?= $stock ?> unit</td>
                                    <td><?= rupiah($avgCost) ?></td>
                                    <td><?= rupiah($stock * $avgCost) ?></td>
                                    <td><?= rupiah($sellPrice) ?></td>
                                    <td><?= rupiah($stock * $sellPrice) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php foreach ($mixedMenus as $menu): ?>
                                <?php
                                $menuCost = getMixedMenuCurrentCost($menu, $ingredients);
                                $menuPrice = currentSellPrice($menu);
                                $menuAvailableServings = getMixedMenuAvailableServings($menu, $ingredients);
                                ?>
                                <tr>
                                    <td><div><?= htmlspecialchars((string)$menu['name']) ?></div><div class="small">Menu racikan | stok dari bahan aktif</div></td>
                                    <td><?= $menuAvailableServings ?> porsi</td>
                                    <td><?= rupiah($menuCost) ?></td>
                                    <td><?= rupiah($menuAvailableServings * $menuCost) ?></td>
                                    <td><?= rupiah($menuPrice) ?></td>
                                    <td><?= rupiah($menuAvailableServings * $menuPrice) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">Belum ada produk siap jual atau menu racikan aktif. Tambahkan dulu lewat menu Kelola Produk atau Bahan & Menu Racikan agar tabel ini terisi.</div>
                <?php endif; ?>
            </article>
        </section>

        <section class="section reveal">
            <article class="panel">
                <div class="panel-head">
                    <div>
                        <h3 class="panel-title">Stok Bahan Racikan</h3>
                        <p class="panel-copy">Cup, sendok, sachet, dan bahan lain dicatat sebagai persediaan bahan. Nilainya ikut masuk ke posisi bersih dan aset usaha.</p>
                    </div>
                    <span class="panel-badge"><?= $activeIngredientCount ?> bahan aktif</span>
                </div>
                <?php if ($hasIngredients): ?>
                    <div class="table-shell">
                        <table class="data-table ingredients-table">
                            <colgroup>
                                <col style="width:32%">
                                <col style="width:14%">
                                <col style="width:12%">
                                <col style="width:20%">
                                <col style="width:22%">
                            </colgroup>
                            <thead><tr><th>Bahan</th><th class="cell-center">Satuan</th><th class="cell-right">Stok</th><th class="cell-right">Modal Rata-rata</th><th class="cell-right">Nilai Stok</th></tr></thead>
                            <tbody>
                            <?php foreach ($ingredients as $ingredient): ?>
                                <?php
                                $stock = (int) $ingredient['stock'];
                                $avgCost = (float) $ingredient['avg_cost'];
                                ?>
                                <tr>
                                    <td data-label="Bahan"><?= htmlspecialchars((string)$ingredient['name']) ?></td>
                                    <td data-label="Satuan" class="cell-center"><?= htmlspecialchars((string)$ingredient['unit']) ?></td>
                                    <td data-label="Stok" class="cell-right"><?= $stock ?></td>
                                    <td data-label="Modal Rata-rata" class="cell-right"><?= rupiah($avgCost) ?></td>
                                    <td data-label="Nilai Stok" class="cell-right"><?= rupiah($stock * $avgCost) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">Belum ada bahan racikan. Tambahkan dulu bahan seperti cup, sendok, atau sachet agar menu racikan bisa dihitung dengan benar.</div>
                <?php endif; ?>
            </article>
        </section>
        <?php endif; ?>

        <?php if ($currentWorkspace === 'operasional'): ?>
        <section class="section reveal">
            <div class="action-cluster-grid">
                <details class="action-group">
                    <summary class="action-group-summary">
                        <div class="action-group-head">
                            <div>
                                <span class="action-group-label">Operasional Harian</span>
                                <h3 class="action-group-title">Penjualan & Persediaan</h3>
                            </div>
                            <span class="action-group-count">3 aksi</span>
                        </div>
                        <p class="action-group-copy">Masuk ke proses yang paling sering dipakai sehari-hari: rekap penjualan dan dua jalur restock.</p>
                    </summary>
                    <div class="action-subactions">
                        <button class="action-subbtn" data-open="modal-stock"><small>Penjualan</small><strong>Rekap Stok Keluar</strong><span>Input stok tersisa dan uang aktual masuk sesuai tanggal transaksi.</span></button>
                        <button class="action-subbtn" data-open="modal-restock"><small>Produk</small><strong>Input Restock</strong><span>Tambah stok masuk produk siap jual dan hitung ulang modal rata-rata.</span></button>
                        <button class="action-subbtn" data-open="modal-ingredient-restock"><small>Bahan</small><strong>Restock Bahan</strong><span>Tambah stok bahan racikan sekaligus dalam satu belanja.</span></button>
                    </div>
                </details>

                <details class="action-group">
                    <summary class="action-group-summary">
                        <div class="action-group-head">
                            <div>
                                <span class="action-group-label">Master Data</span>
                                <h3 class="action-group-title">Produk & Racikan</h3>
                            </div>
                            <span class="action-group-count">2 aksi</span>
                        </div>
                        <p class="action-group-copy">Kelola katalog produk siap jual, bahan racikan, menu aktif, dan perubahan harga jual.</p>
                    </summary>
                    <div class="action-subactions">
                        <button class="action-subbtn" data-open="modal-products"><small>Produk</small><strong>Kelola Produk</strong><span>Tambah produk, ubah harga jual mulai hari ini, dan arsipkan produk kosong.</span></button>
                        <button class="action-subbtn" data-open="modal-mix-master"><small>Racikan</small><strong>Bahan & Menu Racikan</strong><span>Atur bahan, buat menu gelas, dan pantau resep aktif.</span></button>
                    </div>
                </details>

                <details class="action-group">
                    <summary class="action-group-summary">
                        <div class="action-group-head">
                            <div>
                                <span class="action-group-label">Keuangan</span>
                                <h3 class="action-group-title">Aset, Biaya, & Pemilik</h3>
                            </div>
                            <span class="action-group-count">3 aksi</span>
                        </div>
                        <p class="action-group-copy">Catat pembelian aset, biaya operasional, dan penarikan pemilik tanpa menumpuk semua tombol di awal.</p>
                    </summary>
                    <div class="action-subactions">
                        <button class="action-subbtn" data-open="modal-purchase"><small>Aset</small><strong>Kelola Aset</strong><span>Catat pembelian aset dan pantau daftar aset non-stok dari satu tempat.</span></button>
                        <button class="action-subbtn" data-open="modal-expense"><small>Biaya</small><strong>Biaya Operasional</strong><span>Masukkan biaya yang mengurangi kas dan laba aktual.</span></button>
                        <button class="action-subbtn" data-open="modal-owner-withdrawal"><small>Pemilik</small><strong>PMK Kota Surabaya</strong><span>Catat penarikan kas ke pemilik tanpa mengurangi laba aktual.</span></button>
                    </div>
                </details>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($currentWorkspace === 'riwayat'): ?>
        <section class="board reveal board-single">
            <article class="panel report-export-panel">
                <div class="panel-head">
                    <div>
                        <h3 class="panel-title">Export Laporan</h3>
                        <p class="panel-copy">Unduh laporan usaha berdasarkan rentang bulan yang dipilih.</p>
                    </div>
                    <span class="panel-badge">PDF</span>
                </div>
                <form method="get" class="report-form report-export-form" target="_blank" data-ajax-download="true">
                    <input type="hidden" name="export" value="report_pdf">
                    <div class="report-export-grid">
                        <div class="report-export-field">
                            <label>Periode Laporan</label>
                            <div class="report-export-range">
                                <label class="report-export-subfield">
                                    <span>Dari bulan</span>
                                    <input
                                        type="month"
                                        name="month_from"
                                        value="<?= htmlspecialchars($selectedReportStartMonth) ?>"
                                        min="<?= htmlspecialchars($earliestReportMonth) ?>"
                                        max="<?= htmlspecialchars($defaultReportMonth) ?>"
                                    >
                                </label>
                                <label class="report-export-subfield">
                                    <span>Sampai bulan</span>
                                    <input
                                        type="month"
                                        name="month_to"
                                        value="<?= htmlspecialchars($selectedReportEndMonth) ?>"
                                        min="<?= htmlspecialchars($earliestReportMonth) ?>"
                                        max="<?= htmlspecialchars($defaultReportMonth) ?>"
                                    >
                                </label>
                            </div>
                        </div>
                        <div class="small report-export-note">PDF berisi produk atau menu yang laku pada periode terpilih, total uang yang didapat, total modal item terjual, keuntungan kotor, biaya operasional, keuntungan bersih, penarikan pemilik, detail saldo, dan aset pembelian.</div>
                    </div>
                    <div class="panel-form-actions report-export-actions">
                        <button type="submit" class="btn report-export-btn">Export Laporan</button>
                    </div>
                </form>
            </article>

            <article class="panel">
                <div class="panel-head">
                    <div>
                        <h3 class="panel-title">Riwayat Transaksi</h3>
                        <p class="panel-copy">Seluruh transaksi ditampilkan dalam format yang lebih nyaman dipindai. Detail item penting tetap bisa dibuka per entri saat dibutuhkan.</p>
                    </div>
                    <span class="panel-badge"><?= $transactionCount ?> entri</span>
                </div>
                <?php if (empty($data['logs'])): ?>
                    <div class="empty-state">Belum ada data. Sistem siap dipakai dan riwayat transaksi akan muncul di area ini.</div>
                <?php else: ?>
                    <div
                        class="scroll-box history-scroll-box"
                        data-history-loader
                        data-history-url="<?= htmlspecialchars($historyLogsAjaxUrl) ?>"
                        data-history-offset="0"
                        data-history-limit="<?= $historyLogsPageSize ?>"
                        data-history-complete="false"
                    >
                        <table class="data-table logs-table">
                            <thead><tr><th>Tanggal</th><th>Jenis</th><th>Deskripsi</th><th>Detail</th></tr></thead>
                            <tbody data-history-list></tbody>
                        </table>
                        <div class="history-load-state" data-history-status>Scroll ke bagian ini untuk memuat riwayat transaksi.</div>
                        <div class="history-load-sentinel" data-history-sentinel aria-hidden="true"></div>
                    </div>
                <?php endif; ?>
            </article>
        </section>
        <?php endif; ?>
                </div>
            </section>
        </div>
    </main>
</div>

<div class="modal" id="modal-products">
    <div class="modal-box">
        <div class="modal-header"><span class="modal-kicker">Produk</span><button class="modal-close" type="button" data-close aria-label="Tutup modal"><span class="modal-close-icon" aria-hidden="true"></span></button></div>
        <div class="product-modal-stack">
            <details class="product-create-panel">
                <summary class="product-create-toggle">Tambah Produk</summary>
                <div class="product-create-body">
                    <form method="post" class="modal-form">
                        <input type="hidden" name="action" value="add_product">
                        <div class="row">
                            <div><label>Nama Produk</label><input type="text" name="product_name" placeholder="Contoh: Air Mineral" required></div>
                            <div><label>Harga Jual</label><input type="number" name="sell_price" min="0" step="0.01" value="0" required></div>
                        </div>
                        <div class="product-footnote">Harga awal produk akan dicatat mulai <?= date('Y-m-d') ?>. Riwayat ini dipakai saat membaca transaksi lama agar perubahan harga hari berikutnya tidak mengubah data yang sudah terjadi.</div>
                        <div class="modal-form-footer">
                            <button type="submit" class="modal-submit">Simpan Produk Baru</button>
                        </div>
                    </form>
                </div>
            </details>
            <div class="card">
                <div class="product-card-head">
                    <div class="product-card-head-row">
                        <div>
                            <h3 style="margin-top:0;">Produk Aktif</h3>
                            <p class="product-card-copy">Ubah harga jual mulai hari ini atau arsipkan produk kosong tanpa mengganggu riwayat transaksi yang sudah ada.</p>
                        </div>
                        <span class="pill"><?= $activeProductCount ?> aktif</span>
                    </div>
                </div>
                <?php if (!$hasProducts): ?>
                    <div class="empty-modal-state">Belum ada produk aktif. Gunakan tombol Tambah Produk di bagian atas untuk menambahkan produk pertama.</div>
                <?php else: ?>
                    <div class="manage-list">
                        <?php foreach ($products as $key => $product): ?>
                            <?php $currentPriceEntry = getCurrentPriceEntry($product); ?>
                            <details class="manage-item product-item">
                                <summary class="product-item-summary">
                                    <div class="product-item-brief">
                                        <div class="product-title-row">
                                            <strong class="product-title"><?= htmlspecialchars((string)$product['name']) ?></strong>
                                            <span class="pill">Aktif</span>
                                        </div>
                                        <p class="product-brief-copy">Tampilkan detail untuk melihat histori harga, mengubah harga jual mulai hari ini, atau mengarsipkan produk.</p>
                                        <div class="product-brief-meta">
                                            <span class="product-brief-chip">Stok: <?= (int)$product['stock'] ?> unit</span>
                                            <span class="product-brief-chip">Harga aktif: <?= rupiah((float)$product['sell_price']) ?></span>
                                            <span class="product-brief-chip">Berlaku: <?= htmlspecialchars((string)($currentPriceEntry['effective_date'] ?? '-')) ?></span>
                                        </div>
                                    </div>
                                </summary>
                                <div class="product-item-body">
                                    <div class="product-item-body-grid">
                                        <div class="manage-main">
                                            <div class="product-meta-grid">
                                                <div class="product-meta"><span>Stok</span><strong><?= (int)$product['stock'] ?> unit</strong></div>
                                                <div class="product-meta"><span>Harga Aktif</span><strong><?= rupiah((float)$product['sell_price']) ?></strong></div>
                                                <div class="product-meta"><span>Berlaku Sejak</span><strong><?= htmlspecialchars((string)($currentPriceEntry['effective_date'] ?? '-')) ?></strong></div>
                                                <div class="product-meta"><span>Modal Rata-rata</span><strong><?= rupiah((float)$product['avg_cost']) ?></strong></div>
                                            </div>
                                            <details class="product-history">
                                                <summary class="small">Riwayat harga jual</summary>
                                                <div class="history-list">
                                                    <?php foreach (array_reverse($product['price_history'] ?? []) as $historyEntry): ?>
                                                        <div class="history-item">
                                                            <?= htmlspecialchars((string)($historyEntry['effective_date'] ?? '')) ?>: <?= rupiah((float)($historyEntry['price'] ?? 0)) ?>
                                                            <?php if (($historyEntry['previous_price'] ?? null) !== null): ?>
                                                                | sebelumnya <?= rupiah((float)($historyEntry['previous_price'] ?? 0)) ?>
                                                            <?php endif; ?>
                                                            <?php if (($historyEntry['note'] ?? '') !== ''): ?>
                                                                | <?= htmlspecialchars((string)($historyEntry['note'] ?? '')) ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </details>
                                        </div>
                                        <div class="manage-actions">
                                            <div class="product-actions-title">Aksi Produk</div>
                                            <form method="post" class="price-form product-price-form">
                                                <input type="hidden" name="action" value="update_product_price">
                                                <input type="hidden" name="product_key" value="<?= htmlspecialchars((string)$key) ?>">
                                                <div>
                                                    <label>Harga Jual Baru</label>
                                                    <input type="number" name="sell_price" min="0" step="0.01" value="<?= htmlspecialchars((string)$product['sell_price']) ?>" required>
                                                </div>
                                                <div class="small">Jika disimpan sekarang, harga ini berlaku mulai <?= date('Y-m-d') ?>. Catatan transaksi lama tetap memakai harga yang aktif pada tanggalnya.</div>
                                                <button type="submit">Simpan Harga Hari Ini</button>
                                            </form>
                                            <form method="post" class="inline-form" onsubmit="return confirm('Arsipkan produk ini? Riwayat transaksi lama tetap dipertahankan.');">
                                                <input type="hidden" name="action" value="delete_product">
                                                <input type="hidden" name="product_key" value="<?= htmlspecialchars((string)$key) ?>">
                                                <button type="submit" class="btn-danger">Arsipkan Produk</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                    <div class="product-footnote">Produk hanya bisa diarsipkan jika stoknya sudah 0 agar nilai persediaan tidak hilang dari sistem. Saat diarsipkan, riwayat harga dan transaksi lama tetap aman.</div>
                <?php endif; ?>
            </div>
        </div>
</div>
</div>

<div class="modal" id="modal-mix-master">
    <div class="modal-box">
        <div class="modal-header"><span class="modal-kicker">Racikan</span><button class="modal-close" type="button" data-close aria-label="Tutup modal"><span class="modal-close-icon" aria-hidden="true"></span></button></div>
        <div class="product-modal-stack">
            <details class="product-create-panel">
                <summary class="product-create-toggle">Tambah Bahan Racikan</summary>
                <div class="product-create-body">
                    <form method="post" class="modal-form">
                        <input type="hidden" name="action" value="add_ingredient">
                        <div class="row">
                            <div><label>Nama Bahan</label><input type="text" name="ingredient_name" placeholder="Contoh: Cup 14 oz" required></div>
                            <div><label>Satuan</label><input type="text" name="ingredient_unit" value="pcs" placeholder="Contoh: pcs, sachet, cup" required></div>
                        </div>
                        <div class="product-footnote">Bahan aktif akan dipakai sebagai komponen resep menu racikan dan ikut masuk ke nilai persediaan usaha.</div>
                        <div class="modal-form-footer">
                            <button type="submit" class="modal-submit">Simpan Bahan Baru</button>
                        </div>
                    </form>
                </div>
            </details>

            <details class="product-create-panel">
                <summary class="product-create-toggle">Tambah Menu Racikan</summary>
                <div class="product-create-body">
                    <?php if (!$hasIngredients): ?>
                        <div class="empty-modal-state">Belum ada bahan aktif. Tambahkan dulu bahan seperti cup, sendok, atau sachet agar resep menu bisa disusun.</div>
                    <?php else: ?>
                        <form method="post" class="modal-form">
                            <input type="hidden" name="action" value="add_mixed_menu">
                            <div class="row">
                                <div><label>Nama Menu</label><input type="text" name="menu_name" placeholder="Contoh: Good Day Gelas" required></div>
                                <div><label>Harga Jual</label><input type="number" name="sell_price" min="0" step="0.01" value="5000" required></div>
                            </div>
                            <div class="product-footnote">Harga jual awal menu dicatat mulai <?= date('Y-m-d') ?>. Isi resep dengan bahan per porsi, misalnya 1 cup + 1 sendok + 1 sachet.</div>
                            <?php for ($index = 0; $index < 6; $index++): ?>
                                <div class="row">
                                    <div>
                                        <label>Bahan Resep <?= $index + 1 ?></label>
                                        <select name="recipe_ingredient[]">
                                            <option value="">Pilih bahan</option>
                                            <?php foreach ($ingredients as $ingredientKey => $ingredient): ?>
                                                <option value="<?= htmlspecialchars((string)$ingredientKey) ?>"><?= htmlspecialchars((string)$ingredient['name']) ?> (<?= htmlspecialchars((string)$ingredient['unit']) ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label>Qty per Porsi</label>
                                        <input type="number" name="recipe_qty[]" min="0" step="1" value="0">
                                    </div>
                                </div>
                            <?php endfor; ?>
                            <div class="modal-form-footer">
                                <button type="submit" class="modal-submit">Simpan Menu Racikan</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </details>

            <div class="card">
                <div class="product-card-head">
                    <div class="product-card-head-row">
                        <div>
                            <h3 style="margin-top:0;">Bahan Aktif</h3>
                            <p class="product-card-copy">Ringkasan awal dibuat singkat. Klik satu bahan untuk melihat detail stok, modal rata-rata, dan opsi arsip.</p>
                        </div>
                        <span class="pill"><?= $activeIngredientCount ?> aktif</span>
                    </div>
                </div>
                <?php if (!$hasIngredients): ?>
                    <div class="empty-modal-state">Belum ada bahan racikan aktif. Gunakan tombol Tambah Bahan Racikan di bagian atas untuk menambahkan bahan pertama.</div>
                <?php else: ?>
                    <div class="manage-list mix-accordion-group">
                        <?php foreach ($ingredients as $key => $ingredient): ?>
                            <?php $usedByMenu = ingredientUsedByMixedMenus($mixedMenus, $key); ?>
                            <details class="manage-item product-item mix-master-item">
                                <summary class="product-item-summary">
                                    <div class="product-item-brief">
                                        <div class="product-title-row">
                                            <strong class="product-title"><?= htmlspecialchars((string)$ingredient['name']) ?></strong>
                                            <span class="pill">Aktif</span>
                                        </div>
                                        <p class="product-brief-copy">Klik untuk melihat detail bahan dan opsi arsip.</p>
                                        <div class="product-brief-meta">
                                            <span class="product-brief-chip">Satuan: <?= htmlspecialchars((string)$ingredient['unit']) ?></span>
                                            <span class="product-brief-chip">Stok: <?= (int)$ingredient['stock'] ?></span>
                                            <span class="product-brief-chip">Dipakai menu: <?= $usedByMenu ? 'Ya' : 'Tidak' ?></span>
                                        </div>
                                    </div>
                                </summary>
                                <div class="product-item-body">
                                    <div class="product-item-body-grid">
                                        <div class="manage-main">
                                            <div class="product-meta-grid">
                                                <div class="product-meta"><span>Satuan</span><strong><?= htmlspecialchars((string)$ingredient['unit']) ?></strong></div>
                                                <div class="product-meta"><span>Stok</span><strong><?= (int)$ingredient['stock'] ?></strong></div>
                                                <div class="product-meta"><span>Modal Rata-rata</span><strong><?= rupiah((float)$ingredient['avg_cost']) ?></strong></div>
                                                <div class="product-meta"><span>Dipakai Menu Aktif</span><strong><?= $usedByMenu ? 'Ya' : 'Tidak' ?></strong></div>
                                            </div>
                                        </div>
                                        <div class="manage-actions">
                                            <div class="product-actions-title">Aksi Bahan</div>
                                            <form method="post" class="inline-form" onsubmit="return confirm('Arsipkan bahan ini? Riwayat transaksi lama tetap dipertahankan.');">
                                                <input type="hidden" name="action" value="delete_ingredient">
                                                <input type="hidden" name="ingredient_key" value="<?= htmlspecialchars((string)$key) ?>">
                                                <button type="submit" class="btn-danger">Arsipkan Bahan</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                    <div class="product-footnote">Bahan hanya bisa diarsipkan jika stoknya sudah 0 dan tidak dipakai oleh menu racikan aktif.</div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="product-card-head">
                    <div class="product-card-head-row">
                        <div>
                            <h3 style="margin-top:0;">Menu Racikan Aktif</h3>
                            <p class="product-card-copy">Klik satu menu untuk membuka resep, riwayat harga, pengaturan harga jual, dan opsi arsip.</p>
                        </div>
                        <span class="pill"><?= $activeMixedMenuCount ?> aktif</span>
                    </div>
                </div>
                <?php if (!$hasMixedMenus): ?>
                    <div class="empty-modal-state">Belum ada menu racikan aktif. Gunakan tombol Tambah Menu Racikan di bagian atas untuk membuat menu pertama.</div>
                <?php else: ?>
                    <div class="manage-list mix-accordion-group">
                        <?php foreach ($mixedMenus as $key => $menu): ?>
                            <?php
                            $currentPriceEntry = getCurrentPriceEntry($menu);
                            $menuCost = getMixedMenuCurrentCost($menu, $ingredients);
                            $menuMargin = currentSellPrice($menu) - $menuCost;
                            $menuAvailableServings = getMixedMenuAvailableServings($menu, $ingredients);
                            ?>
                            <details class="manage-item product-item mix-master-item">
                                <summary class="product-item-summary">
                                    <div class="product-item-brief">
                                        <div class="product-title-row">
                                            <strong class="product-title"><?= htmlspecialchars((string)$menu['name']) ?></strong>
                                            <span class="pill">Aktif</span>
                                        </div>
                                        <p class="product-brief-copy">Klik untuk melihat detail resep, histori harga, edit harga jual, dan opsi arsip.</p>
                                        <div class="product-brief-meta">
                                            <span class="product-brief-chip">Harga aktif: <?= rupiah((float)$menu['sell_price']) ?></span>
                                            <span class="product-brief-chip">Siap dijual: <?= $menuAvailableServings ?> porsi</span>
                                            <span class="product-brief-chip">Est. margin: <?= rupiah($menuMargin) ?></span>
                                        </div>
                                    </div>
                                </summary>
                                <div class="product-item-body">
                                    <div class="product-item-body-grid">
                                        <div class="manage-main">
                                            <div class="product-meta-grid">
                                                <div class="product-meta"><span>Harga Aktif</span><strong><?= rupiah((float)$menu['sell_price']) ?></strong></div>
                                                <div class="product-meta"><span>Berlaku Sejak</span><strong><?= htmlspecialchars((string)($currentPriceEntry['effective_date'] ?? '-')) ?></strong></div>
                                                <div class="product-meta"><span>Est. HPP Resep</span><strong><?= rupiah($menuCost) ?></strong></div>
                                                <div class="product-meta"><span>Est. Margin</span><strong><?= rupiah($menuMargin) ?></strong></div>
                                                <div class="product-meta"><span>Siap Dijual</span><strong><?= $menuAvailableServings ?> porsi</strong></div>
                                            </div>
                                            <div class="product-footnote">Resep aktif: <?= htmlspecialchars(describeRecipe($menu['recipe'] ?? [], $ingredientCatalog)) ?></div>
                                            <details class="product-history">
                                                <summary class="small">Riwayat harga jual</summary>
                                                <div class="history-list">
                                                    <?php foreach (array_reverse($menu['price_history'] ?? []) as $historyEntry): ?>
                                                        <div class="history-item">
                                                            <?= htmlspecialchars((string)($historyEntry['effective_date'] ?? '')) ?>: <?= rupiah((float)($historyEntry['price'] ?? 0)) ?>
                                                            <?php if (($historyEntry['previous_price'] ?? null) !== null): ?>
                                                                | sebelumnya <?= rupiah((float)($historyEntry['previous_price'] ?? 0)) ?>
                                                            <?php endif; ?>
                                                            <?php if (($historyEntry['note'] ?? '') !== ''): ?>
                                                                | <?= htmlspecialchars((string)($historyEntry['note'] ?? '')) ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </details>
                                        </div>
                                        <div class="manage-actions">
                                            <div class="product-actions-title">Aksi Menu</div>
                                            <form method="post" class="price-form product-price-form">
                                                <input type="hidden" name="action" value="update_mixed_menu_price">
                                                <input type="hidden" name="menu_key" value="<?= htmlspecialchars((string)$key) ?>">
                                                <div>
                                                    <label>Harga Jual Baru</label>
                                                    <input type="number" name="sell_price" min="0" step="0.01" value="<?= htmlspecialchars((string)$menu['sell_price']) ?>" required>
                                                </div>
                                                <div class="small">Jika disimpan sekarang, harga menu ini berlaku mulai <?= date('Y-m-d') ?>. Data transaksi lama tidak berubah.</div>
                                                <button type="submit">Simpan Harga Hari Ini</button>
                                            </form>
                                            <form method="post" class="inline-form" onsubmit="return confirm('Arsipkan menu racikan ini? Riwayat transaksi lama tetap dipertahankan.');">
                                                <input type="hidden" name="action" value="delete_mixed_menu">
                                                <input type="hidden" name="menu_key" value="<?= htmlspecialchars((string)$key) ?>">
                                                <button type="submit" class="btn-danger">Arsipkan Menu</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                    <div class="product-footnote">Menu racikan yang diarsipkan tetap mempertahankan riwayat harga dan transaksi lama. Harga baru yang disimpan di sini hanya berlaku mulai hari ini.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal" id="modal-ingredient-restock">
    <div class="modal-box">
        <div class="modal-header"><span class="modal-kicker">Bahan</span><button class="modal-close" type="button" data-close aria-label="Tutup modal"><span class="modal-close-icon" aria-hidden="true"></span></button></div>
        <?php if (!$hasIngredients): ?>
            <div class="empty-modal-state">Belum ada bahan racikan. Tambahkan dulu bahan aktif lewat menu Bahan & Menu Racikan.</div>
        <?php else: ?>
            <form method="post" class="modal-form">
                <input type="hidden" name="action" value="restock_ingredients">
                <section class="form-section">
                    <h4 class="form-section-title">Informasi Restock</h4>
                    <p class="form-section-copy">Gunakan tanggal transaksi belanja yang sebenarnya. Modal belanja per bahan dapat diisi manual agar cocok dengan bon yang campuran.</p>
                    <div class="row">
                        <div><label>Tanggal</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
                    </div>
                </section>
                <section class="form-section">
                    <h4 class="form-section-title">Bahan yang Dibeli</h4>
                    <p class="form-section-copy">Isi qty masuk dan total modal untuk masing-masing bahan yang dibeli pada transaksi ini. Bahan yang tidak dibeli biarkan 0.</p>
                    <div class="stock-grid">
                        <?php foreach ($ingredients as $key => $ingredient): ?>
                            <div class="stock-item">
                                <strong><?= htmlspecialchars((string)$ingredient['name']) ?></strong>
                                <div class="stock-meta small">Stok saat ini: <?= (int)$ingredient['stock'] ?> <?= htmlspecialchars((string)$ingredient['unit']) ?><br>Modal rata-rata: <?= rupiah((float)$ingredient['avg_cost']) ?></div>
                                <label>Qty Masuk</label>
                                <input type="number" name="ingredient_qty_<?= htmlspecialchars((string)$key) ?>" min="0" step="1" value="0">
                                <label style="margin-top:8px;">Total Modal Bahan</label>
                                <input type="number" name="ingredient_cost_<?= htmlspecialchars((string)$key) ?>" min="0" step="0.01" value="0">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
                <section class="form-section">
                    <h4 class="form-section-title">Catatan Tambahan</h4>
                    <p class="form-section-copy">Opsional untuk mencatat toko, nomor bon, atau cara pembagian modal jika belanjanya campuran.</p>
                    <div class="field-stack">
                        <label>Catatan</label>
                        <textarea name="note" placeholder="Contoh: belanja campuran cup, sendok, dan sachet"></textarea>
                    </div>
                </section>
                <div class="modal-form-footer">
                    <button type="submit" class="modal-submit">Simpan Restock Bahan</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="modal" id="modal-restock">
    <div class="modal-box">
        <div class="modal-header"><span class="modal-kicker">Restock</span><button class="modal-close" type="button" data-close aria-label="Tutup modal"><span class="modal-close-icon" aria-hidden="true"></span></button></div>
        <?php if (!$hasProducts): ?>
            <div class="empty-modal-state">Belum ada produk. Tambahkan dulu lewat menu Kelola Produk agar restock bisa dicatat dengan benar.</div>
        <?php else: ?>
            <form method="post" class="modal-form">
                <input type="hidden" name="action" value="restock">
                <section class="form-section">
                    <h4 class="form-section-title">Informasi Restock</h4>
                    <p class="form-section-copy">Tentukan kapan restock dilakukan dan produk mana yang menerima stok tambahan.</p>
                    <div class="row">
                        <div><label>Tanggal</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"><div class="field-hint">Gunakan tanggal transaksi belanja yang sebenarnya.</div></div>
                        <div><label>Produk</label><select name="product_key"><?php foreach ($products as $key => $product): ?><option value="<?= htmlspecialchars((string)$key) ?>"><?= htmlspecialchars((string)$product['name']) ?></option><?php endforeach; ?></select><div class="field-hint">Pilih produk yang stoknya akan bertambah.</div></div>
                    </div>
                </section>
                <section class="form-section">
                    <h4 class="form-section-title">Jumlah dan Nilai Belanja</h4>
                    <p class="form-section-copy">Sistem akan menghitung modal per item dan memperbarui modal rata-rata secara otomatis.</p>
                    <div class="row">
                        <div><label>Jumlah Item Masuk</label><input type="number" name="qty" min="1" step="1" required></div>
                        <div><label>Total Modal Belanja</label><input type="number" name="total_cost" min="0" step="0.01" required></div>
                    </div>
                </section>
                <section class="form-section">
                    <h4 class="form-section-title">Catatan Tambahan</h4>
                    <p class="form-section-copy">Opsional, tetapi berguna untuk audit seperti asal pembelian atau keterangan promo.</p>
                    <div class="field-stack">
                        <label>Catatan</label>
                        <textarea name="note" placeholder="Contoh: beli 1 dus online"></textarea>
                    </div>
                </section>
                <div class="modal-form-footer">
                    <button type="submit" class="modal-submit">Simpan Restock</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="modal" id="modal-purchase">
    <div class="modal-box">
        <div class="modal-header"><span class="modal-kicker">Aset</span><button class="modal-close" type="button" data-close aria-label="Tutup modal"><span class="modal-close-icon" aria-hidden="true"></span></button></div>
        <div class="product-modal-stack">
            <details class="product-create-panel">
                <summary class="product-create-toggle">Tambah Aset</summary>
                <div class="product-create-body">
                    <form method="post" class="modal-form">
                        <input type="hidden" name="action" value="purchase">
                        <section class="form-section">
                            <h4 class="form-section-title">Informasi Aset</h4>
                            <p class="form-section-copy">Isi data dasar pembelian agar aset usaha tercatat rapi dan mudah ditelusuri.</p>
                            <div class="row">
                                <div><label>Tanggal</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
                                <div><label>Nama Item</label><input type="text" name="item_name" placeholder="Contoh: Stand Kertas A4" required><div class="field-hint">Gunakan nama item yang spesifik agar mudah dicari lagi nanti.</div></div>
                            </div>
                            <div class="row asset-form-row">
                                <div>
                                    <label>Nilai Pembelian</label>
                                    <input type="number" name="amount" min="0.01" step="0.01" required>
                                    <div class="field-hint">Nominal ini akan tercatat sebagai aset dan mengurangi saldo kas usaha.</div>
                                </div>
                                <div class="field-stack asset-note-field">
                                    <label>Catatan</label>
                                    <textarea name="note" placeholder="Contoh: beli di marketplace"></textarea>
                                    <div class="field-hint">Opsional untuk mencatat toko, marketplace, atau kondisi pembelian.</div>
                                </div>
                            </div>
                        </section>
                        <div class="modal-form-footer">
                            <button type="submit" class="modal-submit">Simpan Pembelian Aset</button>
                        </div>
                    </form>
                </div>
            </details>

            <section class="card">
                <div class="product-card-head">
                    <div class="product-card-head-row">
                        <div>
                            <h3 style="margin-top:0;">Daftar Aset Pembelian</h3>
                            <p class="product-card-copy">Pantau aset non-stok yang sudah pernah dibeli tanpa pindah ke workspace riwayat.</p>
                        </div>
                        <span class="pill"><?= count($assetItems) ?> item</span>
                    </div>
                </div>
                <?php if (empty($assetItems)): ?>
                    <div class="empty-modal-state">Belum ada pembelian aset yang tercatat.</div>
                <?php else: ?>
                    <div class="asset-scroll">
                        <table class="data-table assets-table">
                            <thead><tr><th>Item</th><th>Nilai</th></tr></thead>
                            <tbody>
                            <?php foreach ($assetItems as $name => $amount): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)$name) ?></td>
                                    <td><?= rupiah((float)$amount) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>

<div class="modal" id="modal-expense">
    <div class="modal-box">
        <div class="modal-header"><span class="modal-kicker">Biaya</span><button class="modal-close" type="button" data-close aria-label="Tutup modal"><span class="modal-close-icon" aria-hidden="true"></span></button></div>
        <form method="post" class="modal-form">
            <input type="hidden" name="action" value="expense">
            <section class="form-section">
                <h4 class="form-section-title">Informasi Pengeluaran</h4>
                <p class="form-section-copy">Isi tanggal dan nominal biaya yang ingin dibebankan ke operasional.</p>
                <div class="row">
                    <div><label>Tanggal</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
                    <div><label>Nominal Biaya</label><input type="number" name="amount" min="0.01" step="0.01" required><div class="field-hint">Masukkan total biaya aktual yang keluar dari kas.</div></div>
                </div>
            </section>
            <section class="form-section">
                <h4 class="form-section-title">Catatan Tambahan</h4>
                <p class="form-section-copy">Tulis konteks pengeluaran seperti transport, plastik, atau kebutuhan lain.</p>
                <div class="field-stack">
                    <label>Catatan</label>
                    <textarea name="note" placeholder="Contoh: plastik, transport, biaya lain"></textarea>
                </div>
            </section>
            <div class="modal-form-footer">
                <button type="submit" class="modal-submit">Simpan Biaya Operasional</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="modal-owner-withdrawal">
    <div class="modal-box">
        <div class="modal-header"><span class="modal-kicker">Pemilik</span><button class="modal-close" type="button" data-close aria-label="Tutup modal"><span class="modal-close-icon" aria-hidden="true"></span></button></div>
        <form method="post" class="modal-form">
            <input type="hidden" name="action" value="owner_withdrawal">
            <section class="form-section">
                <h4 class="form-section-title">Informasi Penarikan</h4>
                <p class="form-section-copy">Catat kapan dana ditarik dari usaha ke pemilik agar selisih antara aset dan laba aktual tetap bisa dijelaskan.</p>
                <div class="row">
                    <div><label>Tanggal</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
                    <div><label>Nominal Penarikan</label><input type="number" name="amount" min="0.01" step="0.01" required><div class="field-hint">Nominal ini mengurangi kas usaha, tetapi tidak dicatat sebagai biaya operasional.</div></div>
                </div>
            </section>
            <section class="form-section">
                <h4 class="form-section-title">Catatan Tambahan</h4>
                <p class="form-section-copy">Opsional untuk menandai tujuan penarikan, penerima, atau konteks lain yang dibutuhkan.</p>
                <div class="field-stack">
                    <label>Catatan</label>
                    <textarea name="note" placeholder="Contoh: PMK Kota Surabaya bulan Maret"></textarea>
                </div>
            </section>
            <div class="modal-form-footer">
                <button type="submit" class="modal-submit">Simpan Penarikan Pemilik</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="modal-stock">
    <div class="modal-box">
        <div class="modal-header"><span class="modal-kicker">Rekap</span><button class="modal-close" type="button" data-close aria-label="Tutup modal"><span class="modal-close-icon" aria-hidden="true"></span></button></div>
        <?php if (!$hasProducts && !$hasMixedMenus): ?>
            <div class="empty-modal-state">Belum ada produk siap jual atau menu racikan aktif. Tambahkan dulu salah satunya agar rekap penjualan bisa dibuat.</div>
        <?php else: ?>
            <form method="post" class="modal-form">
                <input type="hidden" name="action" value="update_stock">
                <section class="form-section">
                    <h4 class="form-section-title">Informasi Rekap</h4>
                    <p class="form-section-copy">Pilih tanggal periode rekap dan masukkan total uang aktual yang benar-benar masuk.</p>
                    <div class="row">
                        <div><label>Tanggal</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
                        <div><label>Uang Aktual Masuk</label><input type="number" name="actual_cash_in" min="0" step="0.01" required><div class="field-hint">Nilai ini dipakai untuk menghitung selisih terhadap omzet teoritis.</div></div>
                    </div>
                </section>
                <?php if ($hasProducts): ?>
                    <section class="form-section">
                        <h4 class="form-section-title">Sisa Stok Produk Siap Jual</h4>
                        <p class="form-section-copy">Sistem akan membandingkan stok sebelumnya dengan sisa stok yang Anda isi untuk menentukan jumlah keluar dari produk botol atau barang jadi.</p>
                        <div class="stock-grid">
                            <?php foreach ($products as $key => $product): ?>
                                <?php $currentPriceEntry = getCurrentPriceEntry($product); ?>
                                <div class="stock-item">
                                    <strong><?= htmlspecialchars((string)$product['name']) ?></strong>
                                    <div class="stock-meta small">Stok saat ini: <?= (int)$product['stock'] ?><br>Modal rata-rata: <?= rupiah((float)$product['avg_cost']) ?><br>Harga aktif saat ini: <?= rupiah((float)$product['sell_price']) ?><br>Berlaku sejak: <?= htmlspecialchars((string)($currentPriceEntry['effective_date'] ?? '')) ?></div>
                                    <label>Sisa Stok Sekarang</label>
                                    <input type="number" name="remaining_<?= htmlspecialchars((string)$key) ?>" min="0" max="<?= (int)$product['stock'] ?>" step="1" value="<?= (int)$product['stock'] ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
                <?php if ($hasMixedMenus): ?>
                    <section class="form-section">
                        <h4 class="form-section-title">Penjualan Menu Racikan</h4>
                        <p class="form-section-copy">Masukkan jumlah porsi terjual per menu. Sistem akan mengurangi stok bahan otomatis sesuai resep aktif masing-masing menu.</p>
                        <div class="stock-grid">
                            <?php foreach ($mixedMenus as $key => $menu): ?>
                                <?php
                                $currentPriceEntry = getCurrentPriceEntry($menu);
                                $menuCost = getMixedMenuCurrentCost($menu, $ingredients);
                                $menuAvailableServings = getMixedMenuAvailableServings($menu, $ingredients);
                                ?>
                                <div class="stock-item">
                                    <strong><?= htmlspecialchars((string)$menu['name']) ?></strong>
                                    <div class="stock-meta small">Harga aktif saat ini: <?= rupiah((float)$menu['sell_price']) ?><br>Berlaku sejak: <?= htmlspecialchars((string)($currentPriceEntry['effective_date'] ?? '')) ?><br>Est. HPP resep: <?= rupiah($menuCost) ?><br>Stok siap jual dari bahan: <?= $menuAvailableServings ?> porsi</div>
                                    <div class="small" style="margin-bottom:8px;">Resep: <?= htmlspecialchars(describeRecipe($menu['recipe'] ?? [], $ingredientCatalog)) ?></div>
                                    <label>Qty Terjual</label>
                                    <input type="number" name="mixed_qty_<?= htmlspecialchars((string)$key) ?>" min="0" max="<?= $menuAvailableServings ?>" step="1" value="0">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
                <section class="form-section">
                    <h4 class="form-section-title">Catatan Tambahan</h4>
                    <p class="form-section-copy">Opsional untuk menyimpan konteks seperti periode rekap, kurang bayar, atau penyesuaian tertentu.</p>
                    <div class="field-stack">
                        <label>Catatan</label>
                        <textarea name="note" placeholder="Contoh: rekap 3 hari, ada kurang bayar"></textarea>
                    </div>
                </section>
                <div class="modal-form-footer">
                    <button type="submit" class="modal-submit">Simpan Rekap Stok</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
</div>

<script>
    function syncModalState() {
        var hasOpenModal = document.querySelector('.modal.show') !== null;
        document.body.classList.toggle('modal-open', hasOpenModal);
    }

    function openModal(modal) {
        if (!modal) return;
        modal.classList.add('show');
        syncModalState();
        var firstField = modal.querySelector('input:not([type="hidden"]), select, textarea, button[type="submit"]');
        if (firstField) {
            setTimeout(function () {
                firstField.focus();
            }, 30);
        }
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('show');
        syncModalState();
    }

    function bindExclusiveAccordion(selector) {
        document.querySelectorAll(selector).forEach(function (group) {
            if (group.dataset.accordionBound === 'true') {
                return;
            }

            group.dataset.accordionBound = 'true';
            var items = group.querySelectorAll('details');
            items.forEach(function (item) {
                item.addEventListener('toggle', function () {
                    if (window.innerWidth > 1180) return;
                    if (!item.open) return;

                    items.forEach(function (otherItem) {
                        if (otherItem !== item) {
                            otherItem.open = false;
                        }
                    });
                });
            });
        });
    }

    function syncActionGroupMode() {
        document.querySelectorAll('.action-cluster-grid').forEach(function (group) {
            var items = Array.prototype.slice.call(group.querySelectorAll('.action-group'));

            if (items.length === 0) {
                return;
            }

            if (window.innerWidth > 1180) {
                items.forEach(function (item) {
                    item.open = true;
                });
                return;
            }

            var openItems = items.filter(function (item) {
                return item.open;
            });

            if (openItems.length === 0) {
                return;
            }

            if (openItems.length > 1) {
                items.forEach(function (item) {
                    item.open = false;
                });
            }
        });
    }

    var syncedBoardObservers = [];

    function resetSyncedBoardObservers() {
        syncedBoardObservers.forEach(function (observer) {
            observer.disconnect();
        });
        syncedBoardObservers = [];
    }

    function syncBoardPanelHeights() {
        resetSyncedBoardObservers();

        document.querySelectorAll('[data-sync-board]').forEach(function (board) {
            var leftPanel = board.querySelector('[data-sync-left]');
            var rightPanel = board.querySelector('[data-sync-right]');

            if (!leftPanel || !rightPanel) {
                return;
            }

            function applyHeight() {
                leftPanel.style.removeProperty('--synced-panel-height');

                if (window.innerWidth <= 1180) {
                    return;
                }

                var rightHeight = Math.ceil(rightPanel.getBoundingClientRect().height);
                if (rightHeight > 0) {
                    leftPanel.style.setProperty('--synced-panel-height', rightHeight + 'px');
                }
            }

            applyHeight();

            if ('ResizeObserver' in window) {
                var observer = new ResizeObserver(function () {
                    applyHeight();
                });

                observer.observe(rightPanel);
                syncedBoardObservers.push(observer);
            }
        });
    }

    var historyLogObservers = [];

    function resetHistoryLogObservers() {
        historyLogObservers.forEach(function (observer) {
            observer.disconnect();
        });
        historyLogObservers = [];
    }

    function setHistoryLoadStatus(container, text, state) {
        var status = container ? container.querySelector('[data-history-status]') : null;
        if (!status) {
            return;
        }

        status.classList.remove('is-loading', 'is-error', 'is-complete', 'is-hidden');
        if (!text) {
            status.textContent = '';
            status.classList.add('is-hidden');
            return;
        }

        status.textContent = text;
        if (state) {
            status.classList.add('is-' + state);
        }
    }

    async function loadHistoryLogBatch(container) {
        if (!container || container.dataset.loading === 'true' || container.dataset.historyComplete === 'true') {
            return;
        }

        var list = container.querySelector('[data-history-list]');
        if (!list) {
            return;
        }

        var offset = parseInt(container.dataset.historyOffset || '0', 10);
        var limit = parseInt(container.dataset.historyLimit || '8', 10);
        var requestUrl = new URL(container.dataset.historyUrl || window.location.pathname, window.location.href);
        requestUrl.searchParams.set('ajax', 'history_logs');
        requestUrl.searchParams.set('offset', String(offset));
        requestUrl.searchParams.set('limit', String(limit));

        container.dataset.loading = 'true';
        setHistoryLoadStatus(container, offset === 0 ? 'Memuat riwayat transaksi...' : 'Memuat entri berikutnya...', 'loading');

        try {
            var response = await fetch(requestUrl.toString(), {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error('Riwayat transaksi gagal dimuat.');
            }

            var payload = await response.json();
            if (!document.body.contains(container)) {
                return;
            }

            if (payload.html) {
                list.insertAdjacentHTML('beforeend', payload.html);
            }

            container.dataset.historyOffset = String(payload.next_offset || offset);
            container.dataset.historyComplete = payload.has_more ? 'false' : 'true';

            if ((payload.loaded_count || 0) === 0 && offset === 0) {
                setHistoryLoadStatus(container, 'Belum ada riwayat transaksi untuk dimuat.', 'complete');
                return;
            }

            if (payload.has_more) {
                setHistoryLoadStatus(container, 'Scroll lebih bawah untuk memuat entri berikutnya.', '');
            } else {
                setHistoryLoadStatus(container, 'Semua riwayat transaksi sudah dimuat.', 'complete');
            }
        } catch (error) {
            if (document.body.contains(container)) {
                setHistoryLoadStatus(container, error && error.message ? error.message : 'Riwayat transaksi gagal dimuat.', 'error');
            }
        } finally {
            if (document.body.contains(container)) {
                container.dataset.loading = 'false';
            }
        }
    }

    function initLazyHistoryLogs() {
        resetHistoryLogObservers();

        document.querySelectorAll('[data-history-loader]').forEach(function (container) {
            var sentinel = container.querySelector('[data-history-sentinel]');
            if (!sentinel) {
                return;
            }

            if (!('IntersectionObserver' in window)) {
                loadHistoryLogBatch(container);
                return;
            }

            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        loadHistoryLogBatch(container);
                    }
                });
            }, {
                rootMargin: '40px 0px'
            });

            observer.observe(sentinel);
            historyLogObservers.push(observer);
        });
    }

    function initInterface() {
        bindExclusiveAccordion('#modal-products .manage-list');
        bindExclusiveAccordion('#modal-mix-master .mix-accordion-group');
        bindExclusiveAccordion('.action-cluster-grid');
        syncModalState();
        syncActionGroupMode();
        syncBoardPanelHeights();
        initLazyHistoryLogs();
    }

    function showToast(type, message) {
        if (!message) return;

        var stack = document.getElementById('ajax-toast-stack');
        if (!stack) return;

        var toast = document.createElement('div');
        toast.className = 'alert ajax-toast ' + (type === 'error' ? 'alert-error' : 'alert-success');
        toast.textContent = message;
        stack.appendChild(toast);

        setTimeout(function () {
            toast.remove();
        }, 4200);
    }

    function collectAlerts(container) {
        return Array.prototype.map.call(container.querySelectorAll('.alert'), function (alert) {
            return {
                type: alert.classList.contains('alert-error') ? 'error' : 'success',
                text: alert.textContent.trim()
            };
        });
    }

    function parseDownloadFilename(response, fallbackName) {
        var disposition = response.headers.get('content-disposition') || '';
        var utfMatch = disposition.match(/filename\\*=UTF-8''([^;]+)/i);
        if (utfMatch && utfMatch[1]) {
            return decodeURIComponent(utfMatch[1]);
        }

        var plainMatch = disposition.match(/filename=\"?([^\";]+)\"?/i);
        if (plainMatch && plainMatch[1]) {
            return plainMatch[1];
        }

        return fallbackName;
    }

    function triggerBlobDownload(blob, filename) {
        var blobUrl = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = blobUrl;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () {
            URL.revokeObjectURL(blobUrl);
        }, 1000);
    }

    function replaceAppRoot(html) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');
        var nextRoot = doc.getElementById('app-root');
        var currentRoot = document.getElementById('app-root');

        if (!nextRoot || !currentRoot) {
            throw new Error('Respon halaman tidak valid.');
        }

        var alerts = collectAlerts(doc);
        currentRoot.innerHTML = nextRoot.innerHTML;
        initInterface();
        return alerts;
    }

    function replaceWorkspaceHub(html) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');
        var nextHub = doc.getElementById('workspace-hub');
        var currentHub = document.getElementById('workspace-hub');

        if (!nextHub || !currentHub) {
            throw new Error('Workspace gagal dimuat.');
        }

        currentHub.innerHTML = nextHub.innerHTML;
        initInterface();
    }

    function setSubmitterLoading(submitter, loading, label) {
        if (!submitter || !(submitter instanceof HTMLElement)) {
            return;
        }

        if (loading) {
            submitter.dataset.originalHtml = submitter.innerHTML;
            submitter.classList.add('is-loading');
            submitter.disabled = true;
            if (label) {
                submitter.textContent = label;
            }
            return;
        }

        if (!document.body.contains(submitter)) {
            return;
        }

        submitter.classList.remove('is-loading');
        submitter.disabled = false;
        if (submitter.dataset.originalHtml) {
            submitter.innerHTML = submitter.dataset.originalHtml;
            delete submitter.dataset.originalHtml;
        }
    }

    function closeAllOpenModals() {
        document.querySelectorAll('.modal.show').forEach(function (modal) {
            closeModal(modal);
        });
    }

    function buildWorkspaceUrl(workspace) {
        var nextUrl = new URL(window.location.href);
        nextUrl.searchParams.set('workspace', workspace);
        nextUrl.searchParams.delete('export');
        return nextUrl;
    }

    async function switchWorkspace(workspace, trigger, historyMode) {
        if (!workspace) {
            return;
        }

        var currentButton = document.querySelector('[data-workspace-target].is-active');
        if (currentButton && currentButton.getAttribute('data-workspace-target') === workspace && historyMode !== 'replace') {
            return;
        }

        var nextUrl = buildWorkspaceUrl(workspace);
        document.body.classList.add('ajax-busy');
        setSubmitterLoading(trigger, true);
        closeAllOpenModals();

        try {
            var response = await fetch(nextUrl.toString(), {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error('Bagian halaman gagal dimuat.');
            }

            var html = await response.text();
            replaceWorkspaceHub(html);

            if (historyMode === 'replace') {
                window.history.replaceState({ workspace: workspace }, '', nextUrl);
            } else {
                window.history.pushState({ workspace: workspace }, '', nextUrl);
            }
        } catch (error) {
            showToast('error', error && error.message ? error.message : 'Bagian halaman gagal dimuat.');
        } finally {
            document.body.classList.remove('ajax-busy');
            setSubmitterLoading(trigger, false);
        }
    }

    async function submitAjaxForm(form, submitter) {
        var method = (form.getAttribute('method') || 'get').toUpperCase();
        var formAction = form.getAttribute('action') || window.location.href;
        var requestUrl = new URL(formAction, window.location.href);
        var formData = new FormData(form);
        var sourceModal = form.closest('.modal');
        var sourceModalId = sourceModal ? sourceModal.id : '';
        var loadingLabel = method === 'GET' ? 'Menyiapkan...' : 'Menyimpan...';

        document.body.classList.add('ajax-busy');
        setSubmitterLoading(submitter, true, loadingLabel);

        try {
            if (method === 'GET') {
                var downloadUrl = new URL(requestUrl.toString());
                formData.forEach(function (value, key) {
                    downloadUrl.searchParams.append(key, value);
                });

                var downloadResponse = await fetch(downloadUrl.toString(), {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                if (!downloadResponse.ok) {
                    throw new Error('Export gagal diproses.');
                }

                var blob = await downloadResponse.blob();
                var filename = parseDownloadFilename(downloadResponse, 'download.pdf');
                triggerBlobDownload(blob, filename);
                showToast('success', 'File PDF berhasil disiapkan.');
                return;
            }

            var response = await fetch(requestUrl.toString(), {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            var html = await response.text();
            var alerts = replaceAppRoot(html);

            if (alerts.length === 0) {
                showToast('success', 'Perubahan berhasil disimpan.');
            } else {
                alerts.forEach(function (alert) {
                    showToast(alert.type, alert.text);
                });
            }

            var hasError = alerts.some(function (alert) {
                return alert.type === 'error';
            });

            if (hasError && sourceModalId) {
                openModal(document.getElementById(sourceModalId));
            }
        } catch (error) {
            showToast('error', error && error.message ? error.message : 'Permintaan gagal diproses.');
            if (sourceModalId) {
                openModal(document.getElementById(sourceModalId));
            }
        } finally {
            document.body.classList.remove('ajax-busy');
            setSubmitterLoading(submitter, false);
        }
    }

    document.addEventListener('click', function (event) {
        var workspaceButton = event.target.closest('[data-workspace-target]');
        if (workspaceButton) {
            event.preventDefault();
            switchWorkspace(workspaceButton.getAttribute('data-workspace-target'), workspaceButton, 'push');
            return;
        }

        var openButton = event.target.closest('[data-open]');
        if (openButton) {
            event.preventDefault();
            openModal(document.getElementById(openButton.getAttribute('data-open')));
            return;
        }

        var closeButton = event.target.closest('[data-close]');
        if (closeButton) {
            event.preventDefault();
            closeModal(closeButton.closest('.modal'));
            return;
        }

        if (event.target.classList.contains('modal')) {
            closeModal(event.target);
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        if (event.defaultPrevented) {
            return;
        }

        event.preventDefault();
        submitAjaxForm(form, event.submitter || form.querySelector('button[type="submit"], input[type="submit"]'));
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('.modal.show').forEach(function (modal) {
                closeModal(modal);
            });
        }
    });

    window.addEventListener('popstate', function () {
        var url = new URL(window.location.href);
        switchWorkspace(url.searchParams.get('workspace') || 'ringkasan', null, 'replace');
    });

    window.addEventListener('resize', function () {
        syncActionGroupMode();
        syncBoardPanelHeights();
    });

    window.history.replaceState({ workspace: new URL(window.location.href).searchParams.get('workspace') || 'ringkasan' }, '', window.location.href);
    initInterface();
</script>
</body>
</html>
