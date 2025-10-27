<?php
// SAP B1 Service Layer stock import script
// - Logs in to Service Layer
// - Fetches item stock by warehouse via OData ($expand ItemWarehouseInfoCollection)
// - Outputs to console (JSON) and writes CSV file `stock_export.csv`

// Lift PHP execution/resource limits for long-running fetches
@ini_set('max_execution_time', '0'); // unlimited
@ini_set('memory_limit', '1024M');   // adjust as needed
@ini_set('default_socket_timeout', '3000');
@set_time_limit(0);
@ignore_user_abort(true);

// Configuration (provided credentials)
$SAP_BASE_URL   = 'https://b1su0210.cloudtaktiks.com:50000/b1s/v1';
$SAP_USER       = 'CLOUDTAKTIKS\\CTC100041.4';
$SAP_PASS       = 'A2r@h@R001';
$SAP_COMPANYDB  = 'TESTI_MULT_310825';

// Tweakables
$REQUEST_TIMEOUT_SECONDS = 3000;
$RESULTS_PER_PAGE = 10000; // Adjust if your SL allows higher
$CSV_OUTPUT_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'stock_export.csv';

// MySQL configuration (XAMPP defaults)
$DB_HOST = '127.0.0.1';
$DB_NAME = 'customer_test';
$DB_USER = 'root';
$DB_PASS = '';

function httpRequest($method, $url, $headers = [], $body = null, $timeoutSeconds = 3000, $cookies = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
    curl_setopt($ch, CURLOPT_HEADER, true);

    // Many SL deployments on :50000 use self-signed certs; disable verify if needed
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    if (!empty($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    if (!empty($cookies)) {
        $cookieHeader = [];
        foreach ($cookies as $k => $v) {
            $cookieHeader[] = $k . '=' . $v;
        }
        $headers[] = 'Cookie: ' . implode('; ', $cookieHeader);
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $rawHeaders = '';
    $bodyOut = $response;
    if ($response !== false && $headerSize > 0) {
        $rawHeaders = substr($response, 0, $headerSize);
        $bodyOut = substr($response, $headerSize);
    }
    $err = curl_error($ch);
    curl_close($ch);

    return [$httpCode, $bodyOut, $err, $rawHeaders];
}

function sapLogin($baseUrl, $username, $password, $companyDb, $timeoutSeconds) {
    $loginUrl = rtrim($baseUrl, '/') . '/Login';
    $payload = json_encode([
        'CompanyDB' => $companyDb,
        'UserName'  => $username,
        'Password'  => $password,
    ]);

    [$code, $resp, $err, $rawHeaders] = httpRequest(
        'POST',
        $loginUrl,
        [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        $payload,
        $timeoutSeconds
    );

    if ($err) {
        throw new RuntimeException('Login cURL error: ' . $err);
    }

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('Login failed. HTTP ' . $code . ' Response: ' . $resp);
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        throw new RuntimeException('Login response not JSON: ' . $resp);
    }

    // Service Layer returns SessionId and may set ROUTEID and B1SESSION cookie via headers. We will rely on body SessionId.
    if (empty($data['SessionId'])) {
        throw new RuntimeException('Login response missing SessionId: ' . $resp);
    }

    // Cookies for subsequent calls
    $cookies = [
        'B1SESSION' => $data['SessionId'],
    ];

    // Parse Set-Cookie for ROUTEID (load balancer affinity) if present
    if (is_string($rawHeaders) && $rawHeaders !== '') {
        $lines = preg_split('/\r?\n/', $rawHeaders);
        foreach ($lines as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $cookieStr = trim(substr($line, strlen('Set-Cookie:')));
                $parts = explode(';', $cookieStr);
                if (!empty($parts[0])) {
                    $kv = explode('=', trim($parts[0]), 2);
                    if (count($kv) === 2) {
                        $ck = trim($kv[0]);
                        $cv = $kv[1];
                        if (strcasecmp($ck, 'ROUTEID') === 0) {
                            $cookies['ROUTEID'] = $cv;
                        }
                        if (strcasecmp($ck, 'B1SESSION') === 0) {
                            // Prefer server-issued cookie value if present
                            $cookies['B1SESSION'] = $cv;
                        }
                    }
                }
            }
        }
    }

    // Some landscapes require fixed ROUTEID affinity; attempt to detect it by calling WhoAmI once, else user may set manually.
    // We skip detection here for simplicity; SL often works without explicit ROUTEID if same node handles session.

    return $cookies;
}

function sapLogout($baseUrl, $timeoutSeconds, $cookies) {
    $logoutUrl = rtrim($baseUrl, '/') . '/Logout';
    httpRequest('POST', $logoutUrl, ['Accept: application/json'], null, $timeoutSeconds, $cookies);
}

function buildItemsStockUrl($baseUrl, $top, $skip = 0) {
    $root = rtrim($baseUrl, '/');

    // We want: Items?$select=ItemCode,ItemName,ItemWarehouseInfoCollection&$top=..&$skip=..
    // ItemWarehouseInfoCollection is a complex collection on Item, not a navigable entity in some SL versions;
    // therefore do NOT use $expand. We'll parse the inline collection.
    $select = '$select=ItemCode,ItemName,ItemWarehouseInfoCollection';
    $paging = '$top=' . urlencode((string)$top) . ($skip > 0 ? '&$skip=' . urlencode((string)$skip) : '');
    $params = $select . '&' . $paging;
    return $root . '/Items?' . $params;
}

function fetchAllItemStocks($baseUrl, $timeoutSeconds, $cookies, $pageSize) {
    $allRows = [];
    $nextUrl = buildItemsStockUrl($baseUrl, $pageSize, 0);

    while ($nextUrl !== null) {
        [$code, $resp, $err] = [null, null, null];
        $headers = [
            'Accept: application/json',
        ];
        // Some tenants require X-SAP-LogonToken header instead of cookies, but usually cookies suffice.
        // We'll keep using cookies, but ensure ROUTEID/B1SESSION are sent.
        [$code, $resp, $err] = httpRequest('GET', $nextUrl, $headers, null, $timeoutSeconds, $cookies);
        if ($err) {
            throw new RuntimeException('Items request cURL error: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('Items request failed. HTTP ' . $code . ' Response: ' . $resp);
        }

        $data = json_decode($resp, true);
        if (!is_array($data)) {
            throw new RuntimeException('Items response not JSON: ' . $resp);
        }

        if (!isset($data['value']) || !is_array($data['value'])) {
            throw new RuntimeException('Items response missing value array: ' . $resp);
        }

        foreach ($data['value'] as $item) {
            $itemCode = isset($item['ItemCode']) ? (string)$item['ItemCode'] : '';
            $itemName = isset($item['ItemName']) ? (string)$item['ItemName'] : '';

            if (isset($item['ItemWarehouseInfoCollection']) && is_array($item['ItemWarehouseInfoCollection'])) {
                foreach ($item['ItemWarehouseInfoCollection'] as $wh) {
                    $warehouseCode = isset($wh['WarehouseCode']) ? (string)$wh['WarehouseCode'] : '';
                    // Prefer InStock; fall back to OnHand if not present
                    $inStock = null;
                    if (array_key_exists('InStock', $wh)) {
                        $inStock = $wh['InStock'];
                    } elseif (array_key_exists('OnHand', $wh)) {
                        $inStock = $wh['OnHand'];
                    }

                    $allRows[] = [
                        'ItemCode' => $itemCode,
                        'ItemName' => $itemName,
                        'WarehouseCode' => $warehouseCode,
                        'Quantity' => is_null($inStock) ? null : (float)$inStock,
                    ];
                }
            } else {
                // No warehouse info; still record a row with null warehouse
                $allRows[] = [
                    'ItemCode' => $itemCode,
                    'ItemName' => $itemName,
                    'WarehouseCode' => null,
                    'Quantity' => null,
                ];
            }
        }

        // Handle pagination
        $nextUrl = null;
        foreach (["@odata.nextLink", "odata.nextLink", "nextLink"] as $k) {
            if (!empty($data[$k]) && is_string($data[$k])) {
                // SL returns absolute or relative link; normalize to absolute
                $candidate = $data[$k];
                if (stripos($candidate, 'http') === 0) {
                    $nextUrl = $candidate;
                } else {
                    $nextUrl = rtrim($baseUrl, '/') . '/' . ltrim($candidate, '/');
                }
                break;
            }
        }
    }

    return $allRows;
}

function writeCsv($filepath, $rows) {
    $fp = fopen($filepath, 'w');
    if ($fp === false) {
        throw new RuntimeException('Unable to open CSV for writing: ' . $filepath);
    }
    // Header
    fputcsv($fp, ['ItemCode', 'ItemName', 'WarehouseCode', 'Quantity']);
    foreach ($rows as $r) {
        fputcsv($fp, [
            $r['ItemCode'],
            $r['ItemName'],
            $r['WarehouseCode'],
            isset($r['Quantity']) && $r['Quantity'] !== null ? $r['Quantity'] : '',
        ]);
    }
    fclose($fp);
}

function getPdo($host, $db, $user, $pass) {
    $dsn = 'mysql:host=' . $host . ';dbname=' . $db . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    return new PDO($dsn, $user, $pass, $options);
}

function ensureWarehouseStockTable(PDO $pdo) {
    $sql = 'CREATE TABLE IF NOT EXISTS `warehouse_stock` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `item_code` VARCHAR(100) NOT NULL,
        `item_name` VARCHAR(255) NOT NULL,
        `whs_code` VARCHAR(80) NULL,
        `quantity` DECIMAL(18,4) NULL,
        `fetched_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_item_whs` (`item_code`,`whs_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $pdo->exec($sql);
}

function upsertWarehouseStock(PDO $pdo, $rows) {
    if (empty($rows)) {
        return 0;
    }
    $sql = 'INSERT INTO `warehouse_stock` (`item_code`,`item_name`,`whs_code`,`quantity`,`fetched_at`)
        VALUES (:item_code,:item_name,:whs_code,:quantity,:fetched_at)
        ON DUPLICATE KEY UPDATE
        `item_name`=VALUES(`item_name`),
        `quantity`=VALUES(`quantity`),
        `fetched_at`=VALUES(`fetched_at`)';
    $stmt = $pdo->prepare($sql);
    $now = date('Y-m-d H:i:s');
    $affected = 0;
    foreach ($rows as $r) {
        $stmt->execute([
            ':item_code' => $r['ItemCode'],
            ':item_name' => $r['ItemName'],
            ':whs_code' => $r['WarehouseCode'],
            ':quantity' => $r['Quantity'],
            ':fetched_at' => $now,
        ]);
        $affected += $stmt->rowCount();
    }
    return $affected;
}

function ensureWarehouseColumnsMap(PDO $pdo) {
    $sql = 'CREATE TABLE IF NOT EXISTS `warehouse_columns_map` (
        `slot` TINYINT UNSIGNED NOT NULL,
        `warehouse_code` VARCHAR(80) NOT NULL,
        PRIMARY KEY (`slot`),
        UNIQUE KEY `uniq_whs_code` (`warehouse_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $pdo->exec($sql);
}

function ensureWarehouseStockWide(PDO $pdo, $maxSlots) {
    $columns = [];
    for ($i = 1; $i <= $maxSlots; $i++) {
        $columns[] = '`wh' . $i . '` DECIMAL(18,4) NULL';
    }
    $colsSql = implode(",\n        ", $columns);
    $sql = 'CREATE TABLE IF NOT EXISTS `warehouse_stock_wide` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `item_code` VARCHAR(100) NOT NULL,
        `item_name` VARCHAR(255) NOT NULL,
        ' . $colsSql . ',
        `fetched_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_item_code` (`item_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $pdo->exec($sql);
}

function loadWarehouseMap(PDO $pdo) {
    $stmt = $pdo->query('SELECT `slot`, `warehouse_code` FROM `warehouse_columns_map` ORDER BY `slot`');
    $map = [];
    while ($row = $stmt->fetch()) {
        $map[$row['warehouse_code']] = (int)$row['slot'];
    }
    return $map;
}

function assignWarehouseSlots(PDO $pdo, $warehouseCodes, $maxSlots) {
    $map = loadWarehouseMap($pdo);
    $usedSlots = $map ? max($map) : 0;
    foreach ($warehouseCodes as $code) {
        if ($code === null || $code === '') {
            continue;
        }
        if (!isset($map[$code])) {
            if ($usedSlots >= $maxSlots) {
                continue; // skip extra warehouses beyond configured slots
            }
            $usedSlots++;
            $slot = $usedSlots;
            $ins = $pdo->prepare('INSERT IGNORE INTO `warehouse_columns_map` (`slot`,`warehouse_code`) VALUES (:slot,:code)');
            $ins->execute([':slot' => $slot, ':code' => $code]);
            $map[$code] = $slot;
        }
    }
    return $map;
}

function upsertWarehouseStockWide(PDO $pdo, $rows, $map, $maxSlots) {
    if (empty($rows)) {
        return 0;
    }
    // Aggregate per item
    $byItem = [];
    foreach ($rows as $r) {
        $code = $r['ItemCode'];
        if (!isset($byItem[$code])) {
            $byItem[$code] = [
                'ItemCode' => $code,
                'ItemName' => $r['ItemName'],
                'slots' => [],
            ];
        }
        $wh = $r['WarehouseCode'];
        if ($wh !== null && isset($map[$wh])) {
            $slot = $map[$wh];
            if ($slot >= 1 && $slot <= $maxSlots) {
                $byItem[$code]['slots'][$slot] = $r['Quantity'];
            }
        }
    }

    // Build dynamic SQL
    $cols = ['`item_code`','`item_name`'];
    for ($i = 1; $i <= $maxSlots; $i++) { $cols[] = '`wh' . $i . '`'; }
    $cols[] = '`fetched_at`';
    $colsSql = implode(',', $cols);

    $placeholders = [':item_code', ':item_name'];
    for ($i = 1; $i <= $maxSlots; $i++) { $placeholders[] = ':wh' . $i; }
    $placeholders[] = ':fetched_at';
    $phSql = implode(',', $placeholders);

    $updates = ['`item_name`=VALUES(`item_name`)'];
    for ($i = 1; $i <= $maxSlots; $i++) { $updates[] = '`wh' . $i . '`=VALUES(`wh' . $i . '`)'; }
    $updates[] = '`fetched_at`=VALUES(`fetched_at`)';
    $updSql = implode(',', $updates);

    $sql = 'INSERT INTO `warehouse_stock_wide` (' . $colsSql . ') VALUES (' . $phSql . ') ON DUPLICATE KEY UPDATE ' . $updSql;
    $stmt = $pdo->prepare($sql);

    $now = date('Y-m-d H:i:s');
    $affected = 0;
    foreach ($byItem as $item) {
        $params = [
            ':item_code' => $item['ItemCode'],
            ':item_name' => $item['ItemName'],
            ':fetched_at' => $now,
        ];
        for ($i = 1; $i <= $maxSlots; $i++) {
            $params[':wh' . $i] = array_key_exists($i, $item['slots']) ? $item['slots'][$i] : null;
        }
        $stmt->execute($params);
        $affected += $stmt->rowCount();
    }
    return $affected;
}

// Main
try {
    $cookies = sapLogin($SAP_BASE_URL, $SAP_USER, $SAP_PASS, $SAP_COMPANYDB, $REQUEST_TIMEOUT_SECONDS);
    $rows = fetchAllItemStocks($SAP_BASE_URL, $REQUEST_TIMEOUT_SECONDS, $cookies, $RESULTS_PER_PAGE);

    writeCsv($CSV_OUTPUT_FILE, $rows);

    // Persist to MySQL
    $pdo = getPdo($DB_HOST, $DB_NAME, $DB_USER, $DB_PASS);
    ensureWarehouseStockTable($pdo);
    $dbAffected = upsertWarehouseStock($pdo, $rows);

    // Pivot into wide table with wh1..wh20 mapping
    $MAX_WAREHOUSE_SLOTS = 20;
    ensureWarehouseColumnsMap($pdo);
    ensureWarehouseStockWide($pdo, $MAX_WAREHOUSE_SLOTS);
    // Discover all warehouses present in this fetch
    $presentWhs = [];
    foreach ($rows as $r) {
        if (!empty($r['WarehouseCode'])) { $presentWhs[$r['WarehouseCode']] = true; }
    }
    $map = assignWarehouseSlots($pdo, array_keys($presentWhs), $MAX_WAREHOUSE_SLOTS);
    $dbWideAffected = upsertWarehouseStockWide($pdo, $rows, $map, $MAX_WAREHOUSE_SLOTS);

    // Print JSON to console for quick inspection
    header('Content-Type: application/json');
    echo json_encode([
        'count' => count($rows),
        'csv' => basename($CSV_OUTPUT_FILE),
		'db' => [
			'database' => $DB_NAME,
			'table' => 'warehouse_stock',
			'affected' => isset($dbAffected) ? $dbAffected : 0,
		],
        'rows' => $rows,
        'wide' => [
            'table' => 'warehouse_stock_wide',
            'affected' => isset($dbWideAffected) ? $dbWideAffected : 0,
            'slots' => $map,
        ],
    ], JSON_PRETTY_PRINT);

    // Best-effort logout
    sapLogout($SAP_BASE_URL, $REQUEST_TIMEOUT_SECONDS, $cookies);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}

?>


