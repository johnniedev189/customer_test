<?php
// fetch_store_warehouses.php
// Fetch warehouses from SAP B1 Service Layer and store into local MySQL table `warehouses`.
// Usage: run in browser or CLI. Make sure PHP cURL and PDO MySQL extensions are enabled.

// ---------------- CONFIG ----------------
$SAP_BASE_URL = 'https://b1su0210.cloudtaktiks.com:50000/b1s/v1'; // e.g. https://b1server:50000/b1s/v1
$SAP_USER     = 'CLOUDTAKTIKS\\CTC100041.4'; // or simple username depending on your setup
$SAP_PASS     = 'A2r@h@R001';
$SAP_COMPANYDB = 'TESTI_MULT_310825'; // if required by your setup

$DB_HOST = 'localhost';
$DB_NAME = 'customer_test';   // change to your DB (customer_test or myapp_db)
$DB_USER = 'root';
$DB_PASS = '';

// Timeout & misc
$CURL_TIMEOUT = 500;
// -----------------------------------------

header('Content-Type: application/json');

try {
    // 1) Login to SAP Service Layer
    $loginUrl = rtrim($SAP_BASE_URL, '/') . '/Login';
    $loginPayload = [
        'CompanyDB' => $SAP_COMPANYDB,
        'UserName'  => $SAP_USER,
        'Password'  => $SAP_PASS
    ];

    $ch = curl_init($loginUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, $CURL_TIMEOUT);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // set to true in production with proper certs

    $loginResp = curl_exec($ch);
    if ($loginResp === false) {
        throw new Exception('SAP login cURL error: ' . curl_error($ch));
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception("SAP login failed (HTTP {$httpCode}): {$loginResp}");
    }

    $loginJson = json_decode($loginResp, true);
    if (!$loginJson || !isset($loginJson['SessionId'])) {
        // Some SAP SL deployments don't return SessionId but set cookies. We'll capture cookies instead.
        // Grab cookies from curl response headers (cookiejar approach would be cleaner).
    }

    // Get cookies from curl handle for subsequent requests
    $cookies = [];
    // Instead of retrieving cookies from the response, re-login while capturing cookiejar.
    curl_close($ch);

    $cookieFile = sys_get_temp_dir() . '/sap_sl_cookie_' . uniqid() . '.txt';
    $ch = curl_init($loginUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, $CURL_TIMEOUT);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $loginResp = curl_exec($ch);
    if ($loginResp === false) {
        throw new Exception('SAP login (cookie) cURL error: ' . curl_error($ch));
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception("SAP login (cookie) failed (HTTP {$httpCode}): {$loginResp}");
    }
    curl_close($ch);

    // 2) Fetch Warehouses
    // We'll fetch WarehouseCode, WarehouseName, BusinessPlaceID, Inactive
$filter = rawurlencode("Inactive eq 'N'");
$owhsUrl = rtrim($SAP_BASE_URL, '/') . "/Warehouses?\$select=WarehouseCode,WarehouseName,BusinessPlaceID,Inactive&\$filter={$filter}&\$orderby=WarehouseCode%20asc";

$ch = curl_init($owhsUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, $CURL_TIMEOUT);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$owhsResp = curl_exec($ch);
if ($owhsResp === false) {
    throw new Exception('Fetching Warehouses error: ' . curl_error($ch));
}
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($httpCode < 200 || $httpCode >= 300) {
    throw new Exception("Fetching Warehouses failed (HTTP {$httpCode}): {$owhsResp}");
}
curl_close($ch);

$owhsJson = json_decode($owhsResp, true);
if (!isset($owhsJson['value'])) {
    throw new Exception('Unexpected Warehouses response structure: ' . $owhsResp);
}
$warehouses = $owhsJson['value'];

    // 3) Fetch BusinessPlaces (BPL names) so we can map BPLID -> BPLName
    $obplUrl = rtrim($SAP_BASE_URL, '/') . "/BusinessPlaces?\$select=BPLID,BPLName";
    $ch = curl_init($obplUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $CURL_TIMEOUT);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $obplResp = curl_exec($ch);
    if ($obplResp === false) {
        throw new Exception('Fetching BusinessPlaces error: ' . curl_error($ch));
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception("Fetching BusinessPlaces failed (HTTP {$httpCode}): {$obplResp}");
    }
    curl_close($ch);

    $obplJson = json_decode($obplResp, true);
    $bplMap = [];
    if (isset($obplJson['value'])) {
        foreach ($obplJson['value'] as $b) {
            $id = isset($b['BPLID']) ? (string)$b['BPLID'] : null;
            $name = isset($b['BPLName']) ? $b['BPLName'] : null;
            if ($id !== null) $bplMap[$id] = $name;
        }
    }

    // 4) Connect to local MySQL and ensure table exists
    $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $createSql = <<<SQL
CREATE TABLE IF NOT EXISTS warehouses (
  whs_code VARCHAR(50) NOT NULL PRIMARY KEY,
  whs_name VARCHAR(255) DEFAULT NULL,
  bpl_id VARCHAR(50) DEFAULT NULL,
  bpl_name VARCHAR(255) DEFAULT NULL,
  inactive CHAR(1) DEFAULT 'N',
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $pdo->exec($createSql);

    // 5) Upsert each warehouse
    $upsertSql = <<<SQL
INSERT INTO warehouses (whs_code, whs_name, bpl_id, bpl_name, inactive, updated_at)
VALUES (:whs_code, :whs_name, :bpl_id, :bpl_name, :inactive, :updated_at)
ON DUPLICATE KEY UPDATE
  whs_name = VALUES(whs_name),
  bpl_id = VALUES(bpl_id),
  bpl_name = VALUES(bpl_name),
  inactive = VALUES(inactive),
  updated_at = VALUES(updated_at)
SQL;
    $stmt = $pdo->prepare($upsertSql);

    $now = date('Y-m-d H:i:s');
    $inserted = 0;
    foreach ($warehouses as $w) {
        $whs_code = isset($w['WarehouseCode']) ? (string)$w['WarehouseCode'] : null;
        if (!$whs_code) continue;
        $whs_name = isset($w['WarehouseName']) ? $w['WarehouseName'] : null;
        $bpl_id = isset($w['BusinessPlaceID']) ? (string)$w['BusinessPlaceID'] : null;
        $bpl_name = ($bpl_id && isset($bplMap[$bpl_id])) ? $bplMap[$bpl_id] : null;
        $inactive = isset($w['Inactive']) ? $w['Inactive'] : 'N';

        $stmt->execute([
            ':whs_code'  => $whs_code,
            ':whs_name'  => $whs_name,
            ':bpl_id'    => $bpl_id,
            ':bpl_name'  => $bpl_name,
            ':inactive'  => $inactive,
            ':updated_at'=> $now
        ]);
        $inserted++;
    }

    // 6) Logout from Service Layer (cleanup cookie)
    $logoutUrl = rtrim($SAP_BASE_URL, '/') . '/Logout';
    $ch = curl_init($logoutUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $CURL_TIMEOUT);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
    @unlink($cookieFile);

    echo json_encode([
        'success' => true,
        'message' => "Warehouses fetched and stored/updated successfully.",
        'fetched_count' => count($warehouses),
        'stored_count' => $inserted
    ], JSON_PRETTY_PRINT);
} catch (Exception $ex) {
    // attempt logout / cleanup if cookie file exists
    if (isset($cookieFile) && file_exists($cookieFile)) {
        @unlink($cookieFile);
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $ex->getMessage()
    ], JSON_PRETTY_PRINT);
}
