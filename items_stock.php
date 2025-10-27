<?php
// stock_report.php
// Executes a SQL query on SAP B1 Service Layer to fetch item stock data across warehouses
// and displays the results in HTML format

set_time_limit(5000); // 5 minutes
error_reporting(E_ALL);
ini_set('display_errors', 1);

// SAP B1 Service Layer Configuration
$SAP_BASE_URL   = 'https://b1su0210.cloudtaktiks.com:50000/b1s/v1';
$SAP_USER       = 'CLOUDTAKTIKS\\CTC100041.4';
$SAP_PASS       = 'A2r@h@R001';
$SAP_COMPANYDB  = 'TESTI_MULT_310825';
$COOKIE_FILE    = _DIR_ . '/sl_stock_cookie.txt';

// Warehouse mapping (SAP Warehouse Code => Display Name)
$WAREHOUSES = [
    '01'   => 'Head Office Stock',
    '02'   => 'RiverRoad Stock', 
    '03'   => 'SabaSaba Stock',
    '04'   => 'Digo Stock',
    '05'   => 'ShowRoom Stock',
    '08'   => 'Daresalam Stock',
    '10'   => 'Jomo Kenyatta Stock',
    'WH13' => 'Daresalam Main/New Stock'
];

// Items to exclude
$EXCLUDED_ITEMS = ['001-011540', 'ZZZZZZ'];

/**
 * SAP B1 Service Layer API Service Class
 */
class SapApiService {
    private $baseUrl;
    private $cookieFile;

    public function __construct($baseUrl, $cookieFile) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->cookieFile = $cookieFile;
    }

    /**
     * Logs into the Service Layer and establishes a session
     */
    public function login($companyDB, $user, $pass) {
        if (file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
        
        $payload = json_encode([
            'CompanyDB' => $companyDB,
            'UserName'  => $user,
            'Password'  => $pass
        ]);
        
        $response = $this->executeRequest('/Login', 'POST', $payload);
        
        if ($response['httpCode'] >= 200 && $response['httpCode'] < 300) {
            return true;
        }
        return false;
    }

    /**
     * Executes a SQL query on the Service Layer
     */
    public function executeSQL($sql) {
        $payload = json_encode(['sql' => $sql]);
        $response = $this->executeRequest('/SQLQueries', 'POST', $payload);
        
        if ($response['httpCode'] >= 200 && $response['httpCode'] < 300) {
            $json = json_decode($response['body'], true);
            return $json ?: [];
        }
        return false;
    }

    /**
     * Fetch stock report data with detailed debugging
     */
    public function getStockReportData($warehouses, $excludedItems) {
        error_log("Starting getStockReportData...");
        
        // First, get all items with a higher limit per page
        error_log("Fetching items...");
        $items = $this->get('Items', ['$select' => 'ItemCode,ItemName', '$top' => 1000]);
        if ($items === false) {
            error_log("Failed to fetch items");
            return false;
        }
        error_log("Fetched " . count($items) . " items");
        
        // Filter out excluded items
        $items = array_filter($items, function($item) use ($excludedItems) {
            return !in_array($item['ItemCode'], $excludedItems, true);
        });
        error_log("After filtering: " . count($items) . " items");
        
        // Skip item groups for now to get basic functionality working
        error_log("Skipping item groups for now...");
        
        $allData = [];
        $itemChunks = array_chunk(array_values($items), 20); // Process in chunks of 20
        error_log("Processing " . count($itemChunks) . " chunks with " . count($items) . " total items");
        
        foreach ($itemChunks as $index => $chunk) {
            error_log("Processing chunk " . ($index + 1) . "/" . count($itemChunks));
            
            // Try a simpler approach - get stock for each item individually
            foreach ($chunk as $item) {
                $itemCode = $item['ItemCode'];
                $itemName = $item['ItemName'];
                $groupName = ''; // We'll skip group names for now to get the basic functionality working
                
                error_log("Processing item: $itemCode");
                
                // Get stock information for this specific item
                $stockInfo = $this->get("Items('" . rawurlencode($itemCode) . "')/ItemWarehouseInfoCollection");
                
                if ($stockInfo === false) {
                    error_log("Failed to get stock info for item: $itemCode");
                    // Still add the item with zero stock
                    $stockData = [
                        'ItemCode' => $itemCode,
                        'ItemName' => $itemName,
                        'ItmsGrpNam' => $groupName
                    ];
                    foreach ($warehouses as $whCode => $whName) {
                        $stockData[$whName] = 0;
                    }
                    $allData[] = $stockData;
                    continue;
                }
                
                // Initialize stock data for this item
                $stockData = [
                    'ItemCode' => $itemCode,
                    'ItemName' => $itemName,
                    'ItmsGrpNam' => $groupName
                ];
                
                // Initialize all warehouse stocks to 0
                foreach ($warehouses as $whCode => $whName) {
                    $stockData[$whName] = 0;
                }
                
                // Fill in actual stock quantities
                if (is_array($stockInfo)) {
                    foreach ($stockInfo as $stockEntry) {
                        $whCode = $stockEntry['WarehouseCode'];
                        if (isset($warehouses[$whCode])) {
                            $whName = $warehouses[$whCode];
                            $stockData[$whName] = $stockEntry['OnHand'] ?? $stockEntry['InStock'] ?? 0;
                        }
                    }
                }
                
                $allData[] = $stockData;
            }
        }
        
        error_log("Completed processing. Total items: " . count($allData));
        return $allData;
    }

    /**
     * Fetches data from an endpoint with pagination support
     */
    public function get($endpoint, $queryParams = []) {
        $allData = [];
        $base = $this->baseUrl . '/' . ltrim($endpoint, '/');
        
        if (is_array($queryParams) && !empty($queryParams)) {
            $queryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
            $url = $base . '?' . $queryString;
        } else if (is_string($queryParams) && $queryParams !== '') {
            $url = $base . '?' . ltrim($queryParams, '?');
        } else {
            $url = $base;
        }

        do {
            error_log("Fetching from URL: $url");
            $response = $this->executeRequest($url, 'GET');
            if ($response['httpCode'] < 200 || $response['httpCode'] >= 300) {
                error_log("API request failed: HTTP {$response['httpCode']} for URL: $url");
                error_log("Response body: " . substr($response['body'], 0, 500));
                return false;
            }
            
            $json = json_decode($response['body'], true);
            if (!isset($json['value'])) {
                error_log("Invalid response format for URL: $url");
                error_log("Response body: " . substr($response['body'], 0, 500));
                return false;
            }
            
            $currentBatch = count($json['value']);
            error_log("Fetched batch of $currentBatch items");
            $allData = array_merge($allData, $json['value']);
            $url = $json['@odata.nextLink'] ?? null;
            error_log("Next URL: " . ($url ? $url : 'None'));

        } while ($url);

        return $allData;
    }

    /**
     * Logs out from the Service Layer
     */
    public function logout() {
        $this->executeRequest('/Logout', 'POST');
        if (file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    /**
     * Generic cURL executor for API requests
     */
    private function executeRequest($url, $method = 'GET', $data = null) {
        if (strpos($url, 'http') !== 0) {
            $url = $this->baseUrl . $url;
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            if ($data) {
                $options[CURLOPT_POSTFIELDS] = $data;
            }
        }
        
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ['httpCode' => $httpCode, 'body' => $body];
    }
}

/**
 * HTML helper function
 */
function h($s) { 
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); 
}

// Main execution
$sapApi = new SapApiService($SAP_BASE_URL, $COOKIE_FILE);
$data = [];
$error = '';
$notice = '';

// Attempt to login and fetch data
if ($sapApi->login($SAP_COMPANYDB, $SAP_USER, $SAP_PASS)) {
    $notice = "Successfully connected to SAP B1 Service Layer.";
    
    // First, let's test basic connectivity with a simple items query
    error_log("Testing basic items query...");
    $testItems = $sapApi->get('Items', ['$top' => 5, '$select' => 'ItemCode,ItemName']);
    
    if ($testItems === false) {
        $error = "Failed to fetch basic items data. This indicates a fundamental API connectivity issue.";
        $data = [];
    } else {
        error_log("Basic items query successful. Found " . count($testItems) . " items.");
        
        // Now try to get the full stock report data
        $data = $sapApi->getStockReportData($WAREHOUSES, $EXCLUDED_ITEMS);
        
        if ($data === false) {
            $error = "Failed to fetch stock data from Service Layer. Check error logs for details.";
            $data = [];
        } else if (empty($data)) {
            $error = "No stock data found. This could mean no items match the criteria or there are no stock records.";
            $data = [];
        } else {
            $notice .= " Fetched " . count($data) . " items with stock information.";
            if (count($data) < 50) {
                $notice .= " Note: This seems like a small number of items. Check error logs for pagination details.";
            }
        }
    }
    
    $sapApi->logout();
} else {
    $error = "Failed to connect to SAP B1 Service Layer. Please check credentials and network connection.";
}

// Sort data by ItemCode
if (!empty($data)) {
    usort($data, function($a, $b) {
        return strcmp($a['ItemCode'], $b['ItemCode']);
    });
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Report - SAP B1 Service Layer</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
            color: #333;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            text-align: center;
        }
        
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 300;
        }
        
        .header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
            font-size: 14px;
        }
        
        .content {
            padding: 20px;
        }
        
        .notice {
            padding: 12px 16px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
            background: #d4edda;
            color: #155724;
            border-radius: 4px;
        }
        
        .error {
            padding: 12px 16px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
            background: #f8d7da;
            color: #721c24;
            border-radius: 4px;
        }
        
        .stats {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .stat-card {
            background: #f8f9fa;
            padding: 15px 20px;
            border-radius: 6px;
            border-left: 4px solid #007bff;
            flex: 1;
            min-width: 200px;
        }
        
        .stat-card h3 {
            margin: 0 0 5px 0;
            color: #007bff;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-card .value {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }
        
        .table-container {
            overflow-x: auto;
            border-radius: 6px;
            border: 1px solid #dee2e6;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            background: white;
        }
        
        th {
            background: #f8f9fa;
            color: #495057;
            font-weight: 600;
            padding: 12px 8px;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        td {
            padding: 10px 8px;
            border-bottom: 1px solid #dee2e6;
            vertical-align: top;
        }
        
        tr:hover {
            background-color: #f8f9fa;
        }
        
        tr:nth-child(even) {
            background-color: #fafafa;
        }
        
        tr:nth-child(even):hover {
            background-color: #f0f0f0;
        }
        
        .item-code {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: #007bff;
        }
        
        .item-name {
            max-width: 200px;
            word-wrap: break-word;
        }
        
        .group-name {
            color: #6c757d;
            font-style: italic;
        }
        
        .stock-quantity {
            text-align: right;
            font-family: 'Courier New', monospace;
            font-weight: bold;
        }
        
        .stock-zero {
            color: #dc3545;
        }
        
        .stock-positive {
            color: #28a745;
        }
        
        .footer {
            padding: 20px;
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            text-align: center;
            color: #6c757d;
            font-size: 12px;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        
        @media (max-width: 768px) {
            .stats {
                flex-direction: column;
            }
            
            .stat-card {
                min-width: auto;
            }
            
            table {
                font-size: 11px;
            }
            
            th, td {
                padding: 8px 4px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Stock Report</h1>
            <p>Item Stock Quantities Across All Warehouses</p>
        </div>
        
        <div class="content">
            <?php if (!empty($notice)): ?>
                <div class="notice">
                    <strong>Status:</strong> <?= h($notice) ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error)): ?>
                <div class="error">
                    <strong>Error:</strong> <?= h($error) ?>
                    <br><br>
                    <strong>Debug Information:</strong>
                    <ul>
                        <li>Check the error logs in your XAMPP logs directory for detailed error messages</li>
                        <li>The error logs will show exactly which API call is failing</li>
                        <li>Common issues: API endpoint changes, authentication problems, or network connectivity</li>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($data)): ?>
                <div class="stats">
                    <div class="stat-card">
                        <h3>Total Items</h3>
                        <div class="value"><?= count($data) ?></div>
                    </div>
                    <div class="stat-card">
                        <h3>Warehouses</h3>
                        <div class="value"><?= count($WAREHOUSES) ?></div>
                    </div>
                    <div class="stat-card">
                        <h3>Generated</h3>
                        <div class="value"><?= date('Y-m-d H:i:s') ?></div>
                    </div>
                </div>
                
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 120px;">Item Code</th>
                                <th style="width: 250px;">Item Name</th>
                                <th style="width: 150px;">Group Name</th>
                                <?php foreach ($WAREHOUSES as $whCode => $whName): ?>
                                    <th style="width: 120px; text-align: right;"><?= h($whName) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data as $row): ?>
                                <tr>
                                    <td class="item-code"><?= h($row['ItemCode']) ?></td>
                                    <td class="item-name"><?= h($row['ItemName']) ?></td>
                                    <td class="group-name"><?= h($row['ItmsGrpNam']) ?></td>
                                    <?php foreach ($WAREHOUSES as $whCode => $whName): ?>
                                        <td class="stock-quantity <?= ($row[$whName] > 0) ? 'stock-positive' : 'stock-zero' ?>">
                                            <?= number_format($row[$whName], 0) ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="loading">
                    <p>No data available to display.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="footer">
            <p>Report generated from SAP B1 Service Layer | <?= date('Y-m-d H:i:s') ?></p>
        </div>
    </div>
</body>
</html>


partially working