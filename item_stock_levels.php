<?php
set_time_limit(1800);
@ini_set('max_execution_time', '1800');

// SAP B1 Service Layer config (same as other files)
$SL_URL     = 'https://b1su0210.cloudtaktiks.com:50000/b1s/v1';
$USERNAME   = 'CLOUDTAKTIKS\\CTC100041.4';
$PASSWORD   = 'A2r@h@R001';
$COMPANYDB  = 'TESTI_MULT_310825';
$COOKIEFILE = __DIR__ . '/sl_cookie.txt';

// MySQL database config
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'customer_test';

// Database connection
$db_conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($db_conn->connect_error) {
    error_log("DEBUG: Database connection failed: " . $db_conn->connect_error);
    die("Database connection failed: " . $db_conn->connect_error);
} else {
    error_log("DEBUG: Database connected successfully");
}

// Create table if not exists
$table_sql = "CREATE TABLE IF NOT EXISTS items_stock (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(50) UNIQUE,
    item_name VARCHAR(255),
    itms_grp_nam VARCHAR(100),
    head_office_stock INT DEFAULT 0,
    river_road_stock INT DEFAULT 0,
    saba_saba_stock INT DEFAULT 0,
    digo_stock INT DEFAULT 0,
    show_room_stock INT DEFAULT 0,
    daresalam_stock INT DEFAULT 0,
    jomo_kenyatta_stock INT DEFAULT 0,
    daresalam_main_stock INT DEFAULT 0,
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
if ($db_conn->query($table_sql) === TRUE) {
    error_log("DEBUG: Table item_stock_levels created or already exists");
} else {
    error_log("DEBUG: Error creating table: " . $db_conn->error);
}

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function sl_login($slUrl, $username, $password, $companyDB, $cookieFile) {
    $loginUrl = rtrim($slUrl, '/') . '/Login';
    $payload = json_encode([
        'UserName'  => $username,
        'Password'  => $password,
        'CompanyDB' => $companyDB
    ]);

    $ch = curl_init($loginUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 20
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $http < 200 || $http >= 300) return false;
    $json = json_decode($resp, true);
    return $json ?: false;
}

// Fetch item groups for mapping
function sl_get_item_groups($slUrl, $cookieFile) {
    $filter = '';
    $select = '$select=Number,Name';
    $top    = 20;
    $skip   = 0;
    $all    = [];

    do {
        $url = rtrim($slUrl, '/') . '/ItemGroups?$filter=' . rawurlencode($filter) . '&' . $select . '&$count=true&$top=' . $top . '&$skip=' . $skip;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 30
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        error_log("DEBUG: ItemGroups URL: $url");
        error_log("DEBUG: HTTP Code: $http");
        error_log("DEBUG: Response: $resp");

        if ($resp === false || $http < 200 || $http >= 300) return ['value' => [], 'error' => 'HTTP ' . $http . ' - Response: ' . $resp];
        $json = json_decode($resp, true);
        if (!$json || !isset($json['value'])) return ['value' => []];
        $items = $json['value'];
        $all = array_merge($all, $items);
        $skip += $top;
    } while (count($items) > 0);

    error_log("DEBUG: Total item groups fetched: " . count($all));

    return ['value' => $all];
}

// Fetch items with filters
function sl_get_items($slUrl, $cookieFile) {
    $filter = "ItemCode ne '001-011540' and ItemCode ne 'ZZZZZZ'";
    $select = '$select=ItemCode,ItemName,ItemsGroupCode,Valid';
    $top    = 20;
    $skip   = 0;
    $all    = [];

    do {
        $url = rtrim($slUrl, '/') . '/Items?$filter=' . rawurlencode($filter) . '&' . $select . '&$count=true&$top=' . $top . '&$skip=' . $skip;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 30
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        error_log("DEBUG: Items URL: $url");
        error_log("DEBUG: HTTP Code: $http");
        error_log("DEBUG: Response: $resp");

        if ($resp === false || $http < 200 || $http >= 300) return ['value' => [], 'error' => 'HTTP ' . $http . ' - Response: ' . $resp];
        $json = json_decode($resp, true);
        if (!$json || !isset($json['value'])) return ['value' => []];
        $items = $json['value'];
        $all = array_merge($all, $items);
        $skip += $top;
    } while (count($items) > 0);

    error_log("DEBUG: Total items fetched: " . count($all));

    return ['value' => $all];
}

// Fetch warehouse info for a specific item
function sl_get_item_warehouse_info($slUrl, $cookieFile, $itemCode) {
    $url = rtrim($slUrl, '/') . "/Items('" . rawurlencode($itemCode) . "')/ItemWarehouseInfoCollection";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 30
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("DEBUG: Warehouse URL for $itemCode: $url");
    error_log("DEBUG: HTTP Code: $http");

    if ($resp === false || $http < 200 || $http >= 300) return [];
    $json = json_decode($resp, true);
    return $json ?: [];
}

$ok = sl_login($SL_URL, $USERNAME, $PASSWORD, $COMPANYDB, $COOKIEFILE);
if ($ok === false) {
    $items = [];
    $error = 'Service Layer login failed';
} else {
    // Fetch item groups first
    $groups_res = sl_get_item_groups($SL_URL, $COOKIEFILE);
    $groups = $groups_res['value'] ?? [];
    $groups_map = [];
    foreach ($groups as $g) {
        $groups_map[$g['Number']] = $g['Name'];
    }

    // Fetch items
    $res = sl_get_items($SL_URL, $COOKIEFILE);
    $raw_items = $res['value'] ?? [];
    $error = $res['error'] ?? '';

    // Process items: filter active items and fetch warehouse info for each item
    $items = [];
    foreach ($raw_items as $item) {
        // Apply client-side filter for valid items (equivalent to validFor = 'Y')
        $isValid = isset($item['Valid']) && strtoupper($item['Valid']) === 'TYES';
        if (!$isValid) continue;

        $item_code = $item['ItemCode'];
        $item_name = $item['ItemName'];
        $group_code = $item['ItemsGroupCode'];
        $group_name = $groups_map[$group_code] ?? '';

        $stock = [
            'item_code' => $item_code,
            'item_name' => $item_name,
            'itms_grp_nam' => $group_name,
            'head_office_stock' => 0,
            'river_road_stock' => 0,
            'saba_saba_stock' => 0,
            'digo_stock' => 0,
            'show_room_stock' => 0,
            'daresalam_stock' => 0,
            'jomo_kenyatta_stock' => 0,
            'daresalam_main_stock' => 0
        ];

        $warehouse_map = [
            '01' => 'head_office_stock',
            '02' => 'river_road_stock',
            '03' => 'saba_saba_stock',
            '04' => 'digo_stock',
            '05' => 'show_room_stock',
            '08' => 'daresalam_stock',
            '10' => 'jomo_kenyatta_stock',
            'WH13' => 'daresalam_main_stock'
        ];

        // Fetch warehouse info for this specific item
        $wh_info = sl_get_item_warehouse_info($SL_URL, $COOKIEFILE, $item_code);
        if (is_array($wh_info)) {
            foreach ($wh_info as $wh) {
                if (is_array($wh) && isset($wh['WarehouseCode'])) {
                    $wh_code = $wh['WarehouseCode'];
                    $on_hand = intval($wh['OnHand'] ?? 0);
                    if (isset($warehouse_map[$wh_code])) {
                        $stock[$warehouse_map[$wh_code]] = $on_hand;
                    }
                }
            }
        }

        $items[] = $stock;
    }

    // Insert fetched data into database
    if (!empty($items)) {
        $insert_count = 0;
        foreach ($items as $item) {
            $item_code = $db_conn->real_escape_string($item['item_code']);
            $item_name = $db_conn->real_escape_string($item['item_name']);
            $itms_grp_nam = $db_conn->real_escape_string($item['itms_grp_nam']);
            $head_office_stock = intval($item['head_office_stock']);
            $river_road_stock = intval($item['river_road_stock']);
            $saba_saba_stock = intval($item['saba_saba_stock']);
            $digo_stock = intval($item['digo_stock']);
            $show_room_stock = intval($item['show_room_stock']);
            $daresalam_stock = intval($item['daresalam_stock']);
            $jomo_kenyatta_stock = intval($item['jomo_kenyatta_stock']);
            $daresalam_main_stock = intval($item['daresalam_main_stock']);

            $insert_sql = "INSERT INTO items_stock (item_code, item_name, itms_grp_nam, head_office_stock, river_road_stock, saba_saba_stock, digo_stock, show_room_stock, daresalam_stock, jomo_kenyatta_stock, daresalam_main_stock)
                           VALUES ('$item_code', '$item_name', '$itms_grp_nam', $head_office_stock, $river_road_stock, $saba_saba_stock, $digo_stock, $show_room_stock, $daresalam_stock, $jomo_kenyatta_stock, $daresalam_main_stock)
                           ON DUPLICATE KEY UPDATE item_name='$item_name', itms_grp_nam='$itms_grp_nam', head_office_stock=$head_office_stock, river_road_stock=$river_road_stock, saba_saba_stock=$saba_saba_stock, digo_stock=$digo_stock, show_room_stock=$show_room_stock, daresalam_stock=$daresalam_stock, jomo_kenyatta_stock=$jomo_kenyatta_stock, daresalam_main_stock=$daresalam_main_stock, fetched_at=CURRENT_TIMESTAMP";
            if ($db_conn->query($insert_sql) === TRUE) {
                $insert_count++;
            } else {
                error_log("DEBUG: Error inserting item $item_code: " . $db_conn->error);
            }
        }
        error_log("DEBUG: Inserted/updated $insert_count items into database");
    } else {
        error_log("DEBUG: No items to insert");
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Item Stock Levels</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 18px; }
        table { border-collapse: collapse; width: 100%; font-size:14px; }
        th, td { border: 1px solid #ddd; padding: 8px; vertical-align:top; }
        th { background: #f2f2f2; text-align: left; }
        tr:nth-child(even){background-color: #fafafa;}
        .notice { padding:10px; margin:12px 0; border-left:4px solid #dc3545; background:#fff7f7; }
    </style>
</head>
<body>
    <h2>Item Stock Levels</h2>
    <?php if (!empty($error)): ?>
        <div class="notice"><?= h($error) ?></div>
    <?php endif; ?>
    <?php if (count($items) === 0): ?>
        <p>No records found.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Item Code</th>
                    <th>Item Name</th>
                    <th>Item Group</th>
                    <th>Head Office</th>
                    <th>River Road</th>
                    <th>Saba Saba</th>
                    <th>Digo</th>
                    <th>Show Room</th>
                    <th>Daresalam</th>
                    <th>Jomo Kenyatta</th>
                    <th>Daresalam Main</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $i => $r): ?>
                <tr>
                    <td><?= ($i+1) ?></td>
                    <td><?= h($r['item_code']) ?></td>
                    <td><?= h($r['item_name']) ?></td>
                    <td><?= h($r['itms_grp_nam']) ?></td>
                    <td><?= number_format($r['head_office_stock']) ?></td>
                    <td><?= number_format($r['river_road_stock']) ?></td>
                    <td><?= number_format($r['saba_saba_stock']) ?></td>
                    <td><?= number_format($r['digo_stock']) ?></td>
                    <td><?= number_format($r['show_room_stock']) ?></td>
                    <td><?= number_format($r['daresalam_stock']) ?></td>
                    <td><?= number_format($r['jomo_kenyatta_stock']) ?></td>
                    <td><?= number_format($r['daresalam_main_stock']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>