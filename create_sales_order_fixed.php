<?php
session_start();

// ----------------- NAMESPACES FOR LIBRARIES -----------------
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// ----------------- INCLUDE LIBRARIES -----------------
require __DIR__ . '/lib/PHPMailer/src/Exception.php';
require __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/src/SMTP.php';
require __DIR__ . '/lib/fpdf.php';

// ----------------- Custom PDF Class with Header/Footer -----------------
class PDF extends FPDF {
    private $companyInfo;

    function __construct($orientation='P', $unit='mm', $size='A4', $companyInfo = []) {
        parent::__construct($orientation, $unit, $size);
        $this->companyInfo = $companyInfo;
    }

    function Header() {
        // Logo at top left - commented out
        // if (!empty($this->companyInfo['logo']) && file_exists($this->companyInfo['logo'])) {
        //     $this->Image($this->companyInfo['logo'], 10, 6, 30);
        // }
        // Company info at right - commented out
        // $this->SetFont('Helvetica', '', 14);
        // $this->Cell(120);
        // $this->Cell(60, 7, $this->companyInfo['name'] ?? 'Your Company', 0, 1, 'R');
        // $this->SetFont('Helvetica', '', 9);
        // $this->Cell(120);
        // $this->MultiCell(60, 4, $this->companyInfo['address'] ?? 'Company Address', 0, 'R');
        // $this->Ln(10);

        // Title at top - positioned at top with larger font
        $this->SetY(10);
        $this->SetFillColor(0, 128, 128); // Teal
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 26);
        $this->Cell(0, 15, 'SALES ORDER', 0, 1, 'C', true);
        $this->SetTextColor(0);
        $this->Ln(5);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Helvetica', '', 8);
        $this->Cell(0, 5, 'Thank you for your business!', 0, 1, 'C');
        $this->Cell(0, 5, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
}

// ----------------- Configuration -----------------
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'customer_test';

// SAP B1 Service Layer config
$SAP_SERVICE_LAYER_URL = 'https://b1su0210.cloudtaktiks.com:50000/b1s/v1';
$SAP_COMPANY_DB = 'TESTI_MULT_310825';
$SAP_USERNAME = 'CLOUDTAKTIKS\\CTC100041.4';
$SAP_PASSWORD = 'A2r@h@R001';
$SAP_DEFAULT_WAREHOUSE = 'HEADOFF'; // Default warehouse fallback - using Head Office as default
$SAP_DEFAULT_SALES_EMPLOYEE_CODE = null;
$SAP_DEFAULT_BPL_ID = null;

// Simple cache settings
$CACHE_DIR = __DIR__ . '/cache';
$CACHE_TTL = 300;

if (!is_dir($CACHE_DIR)) {
    @mkdir($CACHE_DIR, 0755, true);
}

// SMTP Email Configuration
$SMTP_HOST = 'mail.techbizinfotech.com';
$SMTP_USERNAME = 'sameerm@techbizinfotech.com';
$SMTP_PASSWORD = 'Change@1234';
$SMTP_PORT = 465;
$SMTP_FROM_EMAIL = 'johnw@techbizinfotech.com';
$SMTP_FROM_NAME = ' Company Sales';
$SMTP_DEBUG = false;
$SMTP_TIMEOUT = 30;
$SMTP_MAX_RETRIES = 3;
$SMTP_ALLOW_SELF_SIGNED = false;

$ORDER_NOTIFICATION_EMAILS = ['johnw@techbizinfotech.com'];
$ORDER_CC_EMAILS = ['sameerm@techbizinfotech.com'];

$COMPANY_INFO = [
    'name' => 'Multitools.',
    'address' => "123 Business Rd.\nSuite 100\nCity, State 12345\nPhone: (123) 456-7890",
    'logo' => 'techbizlogo.jpeg'
];
// ----------------- PDF Generation Function (Actual Implementation) -----------------
function generate_sales_order_pdf($order_details, $company_info, $db_conn) {
    $pdf = new PDF('P', 'mm', 'A4', $company_info);
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 12);

    // Order details below title
    $pdf->SetTextColor(0); // Reset to black
    $pdf->SetFont('Helvetica', '', 12);
    $pdf->Cell(0, 7, 'Order ID: ' . $order_details['sales_order_id'], 0, 1, 'L');
    $pdf->Cell(0, 7, 'Date: ' . date("F j, Y H:i:s", strtotime($order_details['document_date'])), 0, 1, 'L');
    $pdf->Ln(10);

    // Customer Info
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetFillColor(240, 240, 240); // Soft gray
    $pdf->Cell(0, 8, 'CUSTOMER INFORMATION:', 0, 1, 'L', true);
    $pdf->SetFont('Helvetica', '', 11);
    $pdf->SetFillColor(255); // Reset
    $customerInfo = "Customer Name: " . $order_details['customer_name'] . "\nCustomer Code: " . $order_details['card_code'];
    $refNo = isset($order_details['customer_ref_no']) && !empty(trim($order_details['customer_ref_no'])) ? trim($order_details['customer_ref_no']) : 'N/A';
    $customerInfo .= "\nCustomer Ref No.: " . $refNo;
    $pdf->MultiCell(0, 6, $customerInfo);
    $pdf->Ln(10);

    // Table Header - Colorful and evenly spaced
    $pdf->SetFillColor(0, 128, 128); // Teal
    $pdf->SetTextColor(255, 255, 255); // White
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->SetFont('Helvetica', 'B', 10);
    $colWidths = [25, 80, 15, 30, 35]; // Even spacing
    $pdf->Cell($colWidths[0], 10, 'Item Code', 1, 0, 'C', true);
    $pdf->Cell($colWidths[1], 10, 'Description', 1, 0, 'C', true);
    $pdf->Cell($colWidths[2], 10, 'Qty', 1, 0, 'C', true);
    $pdf->Cell($colWidths[3], 10, 'Gross Price', 1, 0, 'C', true);
    $pdf->Cell($colWidths[4], 10, 'Gross Total', 1, 1, 'C', true);

    // Table Rows
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(0); // Black
    $pdf->SetFillColor(255); // White
    $subtotal = 0;
    foreach ($order_details['lines'] as $line) {
        $itemName = 'N/A';
        $stmt = $db_conn->prepare("SELECT item_name FROM items WHERE item_code = ?");
        $stmt->bind_param("s", $line['ItemCode']);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) $itemName = $row['item_name'];
        }
        $stmt->close();

        $price = isset($line['Price']) ? floatval($line['Price']) : 0;
        $qty = floatval($line['Quantity']);
        $lineTotal = $price * $qty;
        $subtotal += $lineTotal;

        // Item Code
        $pdf->Cell($colWidths[0], 7, $line['ItemCode'], 1, 0, 'L');
        // Description with MultiCell to handle long names
        $startY = $pdf->GetY();
        $startX = $pdf->GetX();
        $pdf->MultiCell($colWidths[1], 6, $itemName ?: 'N/A', 1, 'L');
        $endY = $pdf->GetY();
        $rowHeight = $endY - $startY;
        // Qty
        $pdf->SetXY($startX + $colWidths[1], $startY);
        $pdf->Cell($colWidths[2], $rowHeight, $qty, 1, 0, 'C');
        // Unit Price
        $pdf->Cell($colWidths[3], $rowHeight, number_format($price, 2) . ' KES', 1, 0, 'R');
        // Line Total
        $pdf->Cell($colWidths[4], $rowHeight, number_format($lineTotal, 2) . ' KES', 1, 1, 'R');
    }

    // Totals Section - Remove Subtotal, make colorful
    $pdf->Ln(10);
    $pdf->SetFillColor(0, 150, 0); // Green accent
    $pdf->SetTextColor(255, 255, 255); // White
    $pdf->SetFont('Helvetica', 'B', 14);
    $totalWidth = array_sum($colWidths);
    $spacerWidth = $totalWidth - 65; // 30 + 35 for TOTAL and amount
    $pdf->Cell($spacerWidth, 12, '', 0); // Spacer
    $pdf->Cell(30, 12, 'TOTAL', 1, 0, 'C', true);
    $pdf->Cell(35, 12, number_format($subtotal, 2) . ' KES', 1, 1, 'R', true);

    return $pdf->Output('S'); // 'S' returns the PDF as a string
}


// ----------------- Email Sending Function (Actual Implementation) -----------------
function send_order_email($recipient_email, $subject, $body, $pdf_attachment_string, $filename, $cc_emails = []) {
    global $SMTP_HOST, $SMTP_USERNAME, $SMTP_PASSWORD, $SMTP_PORT, $SMTP_FROM_EMAIL, $SMTP_FROM_NAME, $SMTP_DEBUG, $SMTP_TIMEOUT, $SMTP_MAX_RETRIES, $SMTP_ALLOW_SELF_SIGNED;

    $attempt = 0;
    $lastError = '';

    while ($attempt < max(1, (int)$SMTP_MAX_RETRIES)) {
        $attempt++;

        $mail = new PHPMailer(true);
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host         = $SMTP_HOST;
            $mail->SMTPAuth     = true;
            $mail->Username     = $SMTP_USERNAME;
            $mail->Password     = $SMTP_PASSWORD;
            $mail->SMTPSecure   = PHPMailer::ENCRYPTION_SMTPS; // implicit TLS for port 465
            $mail->Port         = $SMTP_PORT;
            $mail->CharSet      = 'UTF-8';
            $mail->Timeout      = (int)$SMTP_TIMEOUT;
            $mail->SMTPKeepAlive = false;
            $mail->Hostname     = gethostname() ?: 'localhost';

            // Debugging
            $mail->SMTPDebug    = $SMTP_DEBUG ? SMTP::DEBUG_SERVER : SMTP::DEBUG_OFF;
            $mail->Debugoutput  = function ($str) { error_log('[SMTP DEBUG] ' . trim($str)); };

            // TLS/SSL options
            if ($SMTP_ALLOW_SELF_SIGNED) {
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ];
            }

            // Recipients
            $mail->setFrom($SMTP_FROM_EMAIL, $SMTP_FROM_NAME);
            $mail->addAddress($recipient_email);
            
            // Add CC recipients if provided
            if (!empty($cc_emails) && is_array($cc_emails)) {
                foreach ($cc_emails as $cc_email) {
                    if (filter_var($cc_email, FILTER_VALIDATE_EMAIL)) {
                        $mail->addCC($cc_email);
                    }
                }
            }

            // Attachments
            if ($pdf_attachment_string !== null && $filename) {
                $mail->addStringAttachment($pdf_attachment_string, $filename);
            }

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = 'New sales order notification. The PDF confirmation is attached.';

            $mail->send();
            return true;
        } catch (Exception $e) {
            // Collect detailed diagnostics
            $smtpErr = '';
            if (method_exists($mail, 'getSMTPInstance') && $mail->getSMTPInstance()) {
                $smtpErrArr = $mail->getSMTPInstance()->getError();
                if (is_array($smtpErrArr) && (!empty($smtpErrArr['error']) || !empty($smtpErrArr['detail']))) {
                    $smtpErr = ' SMTP detail=' . json_encode($smtpErrArr);
                }
            }
            $lastError = 'Attempt ' . $attempt . ' failed: ' . $mail->ErrorInfo . ($smtpErr ?: '') . ' Exception=' . $e->getMessage();
            error_log('PHPMailer send error: ' . $lastError);

            // Exponential backoff before retrying (only if more attempts left)
            if ($attempt < (int)$SMTP_MAX_RETRIES) {
                $sleep = min(8, 1 << ($attempt - 1));
                sleep($sleep);
                continue;
            }
            return false;
        }
    }

    // Should not reach here; return false if we somehow do
    if ($lastError) {
        error_log('PHPMailer final error: ' . $lastError);
    }
    return false;
}
// ----------------- Error Parsing Function -----------------
function parse_sap_error($sap_err) {
    // Extract the response part from the error string (after 'HTTP XXX ')
    if (preg_match('/HTTP \d+ (.+)/', $sap_err, $matches)) {
        $response = $matches[1];
        $decoded = json_decode($response, true);
        if ($decoded && isset($decoded['error']['message'])) {
            $message = $decoded['error']['message'];
            // Check for warehouse-related errors
            if (stripos($message, 'Whse') !== false || stripos($message, 'warehouse') !== false || stripos($message, 'required') !== false) {
                return "Oops, please choose a warehouse for each item so we know where to pull stock from.";
            }
        }
    }
    // Fallback for other errors
    return "An error occurred while creating the order. Please try again or contact support.";
}

// ----------------- SAP Service Layer helpers -----------------
function sap_sl_login($baseUrl, $companyDb, $username, $password, &$cookies, &$error) {
    $cookies = '';
    $error = '';
    $url = rtrim($baseUrl, '/') . '/Login';
    $payload = json_encode([
        'CompanyDB' => $companyDb,
        'UserName' => $username,
        'Password' => $password
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ],
        CURLOPT_HEADER => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        $error = 'Curl error: ' . curl_error($ch);
        curl_close($ch);
        return false;
    }
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode < 200 || $httpCode >= 300) {
        $error = 'Login failed: HTTP ' . $httpCode . ' ' . $body;
        return false;
    }
    $cookieMatches = [];
    preg_match_all('/^Set-Cookie:\s*([^;]+);/mi', $headers, $cookieMatches);
    if (!empty($cookieMatches[1])) {
        $cookies = implode('; ', $cookieMatches[1]);
    }
    return true;
}

function sap_sl_request($baseUrl, $method, $path, $payloadArrayOrNull, $cookies, &$error, &$decodedResponse) {
    $error = '';
    $decodedResponse = null;
    if (preg_match('/^https?:\/\//i', $path)) {
        $url = $path;
    } else {
        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if (!empty($cookies)) { $headers[] = 'Cookie: ' . $cookies; }
    $payload = $payloadArrayOrNull !== null ? json_encode($payloadArrayOrNull) : null;
    $opts = [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ];
    if ($payload !== null) { $opts[CURLOPT_POSTFIELDS] = $payload; }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    if ($response === false) {
        $error = 'Curl error: ' . curl_error($ch);
        curl_close($ch);
        return false;
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode < 200 || $httpCode >= 300) {
        $error = 'HTTP ' . $httpCode . ' ' . $response;
        error_log("[sap_sl_request] HTTP error. Code: $httpCode, Raw Response: '$response', Full Error: '$error'");
        return false;
    }
    $decoded = json_decode($response, true);
    $decodedResponse = $decoded !== null ? $decoded : $response;
    return true;
}

function sap_sl_logout($baseUrl, $cookies) {
    $err = '';
    $resp = null;
    @sap_sl_request($baseUrl, 'POST', '/Logout', null, $cookies, $err, $resp);
}

function sap_post_sales_order($baseUrl, $companyDb, $username, $password, $cardCode, $lines, $salesEmployeeCode, $bplId, &$docNum, &$error, $numAtCard = null, $docDate = null, $dueDate = null, $taxDate = null) {
    global $SAP_DEFAULT_WAREHOUSE, $SAP_DEFAULT_BPL_ID;
    $docNum = null;
    $error = '';
    $cookies = '';
    if (!sap_sl_login($baseUrl, $companyDb, $username, $password, $cookies, $error)) {
      error_log('Login failed: ' . $error);  
      return false;
    }
    $today = date('Y-m-d');
    $docDate = $docDate ?: $today;
    $dueDate = $dueDate ?: $today;
    $taxDate = $taxDate ?: $today;
    $payload = [
        'CardCode' => $cardCode,
        'DocumentLines' => array_map(function($l) use ($SAP_DEFAULT_WAREHOUSE) {
            $line = [
                'ItemCode' => (string)$l['ItemCode'],
                'Quantity' => (float)$l['Quantity'],
                'WarehouseCode' => !empty($l['WarehouseCode']) ? (string)$l['WarehouseCode'] : (string)$SAP_DEFAULT_WAREHOUSE
            ];
            if (isset($l['Price']) && $l['Price'] > 0) {
                $line['Price'] = (float)$l['Price'];
            }
            if (!empty($l['BPL_IDAssignedToInvoice'])) {
                $line['BPL_IDAssignedToInvoice'] = (int)$l['BPL_IDAssignedToInvoice'];
            }

            return $line;
        }, $lines),
        'DocDate' => $docDate,
        'DocDueDate' => $dueDate,
        'TaxDate' => $taxDate,
    ];
    if ($numAtCard !== null) { $payload['NumAtCard'] = (string)$numAtCard; }
    if (!empty($salesEmployeeCode)) { $payload['SalesPersonCode'] = (int)$salesEmployeeCode; }
    if (!empty($bplId)) { $payload['BPL_IDAssignedToInvoice'] = (int)$bplId; }
    error_log("[sap_post_sales_order] Sending payload to /Orders: " . json_encode($payload));
    $resp = null;
    $ok = sap_sl_request($baseUrl, 'POST', '/Orders', $payload, $cookies, $error, $resp);
    sap_sl_logout($baseUrl, $cookies);
    if (!$ok) { return false; }
    if (is_array($resp)) {
        if (isset($resp['DocNum'])) { $docNum = $resp['DocNum']; }
        elseif (isset($resp['DocEntry'])) { $docNum = $resp['DocEntry']; }
    }
    return true;
}

// ----------------- Price fetching functions -----------------
function get_item_price_from_db($db_conn, $item_code, $price_list_code = 1) {
    $item_code_safe = $db_conn->real_escape_string($item_code);
    $price_list_safe = intval($price_list_code);
    $query = "SELECT price, currency FROM item_prices
              WHERE item_code = '$item_code_safe' AND price_list_code = $price_list_safe
              LIMIT 1";
    $result = $db_conn->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        $result->free();
        return [
            'price' => floatval($row['price']),
            'currency' => $row['currency']
        ];
    }
    return null;
}

function get_customer_price_list($db_conn, $card_code) {
    $card_code_safe = $db_conn->real_escape_string($card_code);
    $query = "SELECT price_list FROM active_customers WHERE card_code = '$card_code_safe' LIMIT 1";
    $result = $db_conn->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        $result->free();
        return intval($row['price_list']);
    }
    return 1;
}

function get_credit_balance($db_conn, $card_code) {
    $card_code_safe = $db_conn->real_escape_string($card_code);
    $query = "SELECT credit_limit, balance FROM active_customers WHERE card_code = '$card_code_safe' LIMIT 1";
    $result = $db_conn->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        $result->free();
        return [
            'credit_limit' => floatval($row['credit_limit']),
            'balance' => floatval($row['balance'])
        ];
    }
    return [
        'credit_limit' => 0,
        'balance' => 0
    ];
}

// ----------------- AJAX Endpoint for Price Fetching -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_item_price') {
    header('Content-Type: application/json');
    $db_conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    if ($db_conn->connect_error) {
        echo json_encode(['error' => 'Database connection failed']);
        exit;
    }
    $item_code = $_POST['item_code'] ?? '';
    $card_code = $_POST['card_code'] ?? '';
    $price_list_code = $_POST['price_list_code'] ?? null;
    if (!$item_code) {
        echo json_encode(['error' => 'Item code required']);
        exit;
    }
    if (!$price_list_code && $card_code) {
        $price_list_code = get_customer_price_list($db_conn, $card_code);
    } else if (!$price_list_code) {
        $price_list_code = 11;
    }
    $pl_name = null;
    $pls = $db_conn->query("SELECT price_list_name FROM price_lists WHERE price_list_code = " . intval($price_list_code) . " LIMIT 1");
    if ($pls && $plrow = $pls->fetch_assoc()) { $pl_name = $plrow['price_list_name']; $pls->free(); }
    $price_info = get_item_price_from_db($db_conn, $item_code, $price_list_code);
    if ($price_info) {
        echo json_encode([
            'success' => true,
            'price' => $price_info['price'],
            'currency' => $price_info['currency'] ?: 'KES',
            'price_list_code' => $price_list_code,
            'price_list_name' => $pl_name
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'price' => 0,
            'currency' => 'KES',
            'price_list_code' => $price_list_code,
            'price_list_name' => $pl_name
        ]);
    }
    $db_conn->close();
    exit;
}

// ----------------- AJAX Endpoint for Stock Fetching -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_item_stock') {
    header('Content-Type: application/json');
    $item_code = $_POST['item_code'] ?? '';
    $warehouse = $_POST['warehouse'] ?? '';
    $result = [];
    $db_conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    if ($db_conn->connect_error) {
        echo json_encode(['error' => 'Database connection failed']);
        exit;
    }
    $item_code_safe = $db_conn->real_escape_string($item_code);
    $allowed_cols = [];
    $cols_sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='" . $db_conn->real_escape_string($DB_NAME) . "' AND TABLE_NAME='warehouse_stock_wide'";
    if ($cres = $db_conn->query($cols_sql)) {
        while ($c = $cres->fetch_assoc()) {
            $cn = $c['COLUMN_NAME'];
            if (!in_array($cn, ['id','item_code','item_name','fetched_at'])) {
                $allowed_cols[] = $cn;
            }
        }
        $cres->free();
    }
    if (!in_array($warehouse, $allowed_cols)) {
        $warehouse = $allowed_cols[0] ?? 'Head_office';
    }
    $col = '`' . $db_conn->real_escape_string($warehouse) . '`';
    $query = "SELECT $col AS stock_val FROM warehouse_stock_wide WHERE item_code = '$item_code_safe' LIMIT 1";
    $res = $db_conn->query($query);
    if ($res && $row = $res->fetch_assoc()) {
        $result[$warehouse] = floatval($row['stock_val']);
    } else {
        $result[$warehouse] = 0;
    }
    $db_conn->close();
    echo json_encode(['stock' => $result]);
    exit;
}

// ----------------- Database connection -----------------
$db_conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($db_conn->connect_error) {
    die("Database connection failed: " . $db_conn->connect_error);
}

// Create warehouse table if not exists
$warehouse_table_sql = "CREATE TABLE IF NOT EXISTS warehouses (
    whs_code VARCHAR(50) NOT NULL PRIMARY KEY,
    whs_name VARCHAR(255) DEFAULT NULL
)";
$db_conn->query($warehouse_table_sql);

// Create Sales_order table
$table_sql = "CREATE TABLE IF NOT EXISTS sales_order (
    sales_order_id INT NOT NULL,
    cust VARCHAR(255),
    card_code VARCHAR(50),
    item_code VARCHAR(50),
    quantity INT,
    price DECIMAL(15,4) DEFAULT 0,
    line_total DECIMAL(15,4) DEFAULT 0,
    currency VARCHAR(10) DEFAULT 'KES',
    posting_date DATE,
    delivery_date DATE,
    document_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    warehouse_code VARCHAR(50) DEFAULT NULL
)";
$db_conn->query($table_sql);

// Flash message
$message = null;
$sap_error = null;
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}
if (isset($_SESSION['sap_error'])) {
    $sap_error = $_SESSION['sap_error'];
    unset($_SESSION['sap_error']);
}

// ----------------- Handle form submission -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    error_log('POST data: ' . json_encode($_POST));
    error_log('Request Method: ' . $_SERVER['REQUEST_METHOD']);
    
    $card_code = $db_conn->real_escape_string($_POST['card_code'] ?? '');
    error_log('Card Code: ' . $card_code);
    
    $customer_ref_no = isset($_POST['customer_ref_no']) ? trim($_POST['customer_ref_no']) : null;
    if ($customer_ref_no !== null && $customer_ref_no !== '') {
        $customer_ref_no = $db_conn->real_escape_string($customer_ref_no);
    } else {
        $customer_ref_no = null;
    }
  
    
    $item_codes = $_POST['item_code'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['price'] ?? [];
    $warehouse_code = $_POST['warehouse_code'] ?? $SAP_DEFAULT_WAREHOUSE; // Single warehouse for entire order
    
    error_log('Item Codes: ' . json_encode($item_codes));
    error_log('Quantities: ' . json_encode($quantities));
    error_log('Prices: ' . json_encode($prices));
    error_log('Warehouses: ' . json_encode($warehouses));
    $posting_date = $_POST['posting_date'] ?? date('Y-m-d');
    $delivery_date = $_POST['delivery_date'] ?? date('Y-m-d', strtotime('+1 day'));
    $document_date = $_POST['document_date'] ?? date('Y-m-d');
    if (!is_array($item_codes)) { $item_codes = [$item_codes]; }
    if (!is_array($quantities)) { $quantities = [$quantities]; }
    if (!is_array($prices)) { $prices = [$prices]; }
    $successful_inserts = 0;
    $errors = [];
    if ($card_code && count($item_codes) > 0) {
        $cust_name = '';
        $customer_price_list = get_customer_price_list($db_conn, $card_code);
        if ($resName = $db_conn->query("SELECT card_name FROM active_customers WHERE card_code='" . $db_conn->real_escape_string($card_code) . "' LIMIT 1")) {
            if ($rowName = $resName->fetch_assoc()) { $cust_name = $rowName['card_name']; }
            $resName->free();
        }
        $sap_lines = [];
        $header_bpl_id = $SAP_DEFAULT_BPL_ID;
        $valid_items_found = false;
        // Get BPL_ID for the global warehouse
        $wh_query = "SELECT bpl_id FROM warehouses WHERE whs_code = '" . $db_conn->real_escape_string($warehouse_code) . "' LIMIT 1";
        if ($wh_res = $db_conn->query($wh_query)) {
            if ($wh_row = $wh_res->fetch_assoc()) {
                $header_bpl_id = $wh_row['bpl_id'];
            }
            $wh_res->free();
        }
        for ($index = 0; $index < count($item_codes); $index++) {
            $code_raw = $item_codes[$index] ?? '';
            $code = trim((string)$code_raw);
            if ($code === '') continue;
            $qty_raw = $quantities[$index] ?? 0;
            $qty_val = intval(preg_replace('/[^\d\-\.]/', '', (string)$qty_raw));
            $price_raw = $prices[$index] ?? 0;
            $price_val = floatval(str_replace(',', '', (string)$price_raw));
            if ($qty_val > 0) {
                if (!$valid_items_found) {
                    $valid_items_found = true;
                }
                if ($price_val <= 0) {
                    $price_info = get_item_price_from_db($db_conn, $code, $customer_price_list);
                    $price_val = $price_info ? $price_info['price'] : 0;
                }
                $sap_lines[] = [
                    'ItemCode' => $code,
                    'Quantity' => $qty_val,
                    'WarehouseCode' => $warehouse_code,
                    'Price' => ($price_val > 0 ? (float)$price_val : null),
                    'BPL_IDAssignedToInvoice' => $header_bpl_id
                ];
            }
        }
        if (!$valid_items_found) {
            $_SESSION['message'] = "Please add at least one valid item with quantity > 0.";
            header('Location: ' . $_SERVER['PHP_SELF'], true, 303);
            exit();
        }
        // Post to SAP first
        $sap_err = '';
        $sap_docnum = null;
        if (empty($warehouse_code)) {
            $_SESSION['sap_error'] = "Please select a warehouse for the order.";
            header('Location: ' . $_SERVER['PHP_SELF'], true, 303);
            exit();
        }
        if (!sap_post_sales_order($SAP_SERVICE_LAYER_URL, $SAP_COMPANY_DB, $SAP_USERNAME, $SAP_PASSWORD, $card_code, $sap_lines, $SAP_DEFAULT_SALES_EMPLOYEE_CODE, $header_bpl_id, $sap_docnum, $sap_err, $customer_ref_no, $document_date, $delivery_date, $posting_date)) {
            $friendly_error = parse_sap_error($sap_err);
            $_SESSION['sap_error'] = $friendly_error;
            $_SESSION['sap_raw_error'] = $sap_err; // Keep raw for debugging
            header('Location: ' . $_SERVER['PHP_SELF'], true, 303);
            exit();
        }
        $sales_order_id = $sap_docnum;
        // Check uniqueness
        $check_query = "SELECT COUNT(*) as cnt FROM sales_order WHERE sales_order_id = $sales_order_id";
        $check_result = $db_conn->query($check_query);
        $check_row = $check_result->fetch_assoc();
        if ($check_row['cnt'] > 0) {
            $_SESSION['message'] = "Error: Sales order ID $sales_order_id already exists in local database.";
            header('Location: ' . $_SERVER['PHP_SELF'], true, 303);
            exit();
        }
        $check_result->free();
        // Begin transaction for local inserts
        $db_conn->begin_transaction();
        $successful_inserts = 0;
        $errors = [];
        $total_amount = 0;
        // Insert into local DB
        for ($index = 0; $index < count($item_codes); $index++) {
            $code_raw = $item_codes[$index] ?? '';
            $code = trim((string)$code_raw);
            if ($code === '') continue;
            $qty_raw = $quantities[$index] ?? 0;
            $qty_val = intval(preg_replace('/[^\d\-\.]/', '', (string)$qty_raw));
            $price_raw = $prices[$index] ?? 0;
            $price_val = floatval(str_replace(',', '', (string)$price_raw));
            if ($qty_val > 0) {
                if ($price_val <= 0) {
                    $price_info = get_item_price_from_db($db_conn, $code, $customer_price_list);
                    $price_val = $price_info ? $price_info['price'] : 0;
                }
                $line_total = $price_val * $qty_val;
                $total_amount += $line_total;
                $insert_sql = "INSERT INTO sales_order (sales_order_id, cust, card_code, item_code, quantity, posting_date, price, line_total, currency, delivery_date, document_date, warehouse_code, bpl_id) VALUES ($sales_order_id, '" . $db_conn->real_escape_string($cust_name) . "', '$card_code', '$code', $qty_val, '$posting_date', $price_val, $line_total, 'KES', '$delivery_date', '$document_date', " . ($warehouse_code ? "'$warehouse_code'" : 'NULL') . ", '$header_bpl_id')";
                if ($db_conn->query($insert_sql) === TRUE) {
                    $successful_inserts++;
                } else {
                    $errors[] = "Error inserting item $code: " . $db_conn->error;
                }
            }
        }
        if ($successful_inserts > 0 && empty($errors)) {
            $db_conn->commit();
            $message = "Sales order #$sales_order_id created ($successful_inserts item(s), Total: " . number_format($total_amount, 2) . " KES). Posted to SAP (DocNum: $sales_order_id).";
        } elseif ($successful_inserts > 0 && !empty($errors)) {
            $db_conn->rollback();
            $message = "Sales order #$sales_order_id posted to SAP, but local insert failed ($successful_inserts item(s)). " . implode(' ', $errors);
        } else {
            $db_conn->rollback();
            $message = "Failed to create sales order. SAP posted but local inserts failed.";
        }
        // Send email and PDF
        $db_conn_post = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
        if (!$db_conn_post->connect_error) {
            $order_data_for_email = [
                'sales_order_id' => $sales_order_id,
                'customer_name' => $cust_name,
                'card_code' => $card_code,
                'document_date' => $document_date,
                'customer_ref_no' => $customer_ref_no,
                'lines' => $sap_lines,
            ];
            $pdf_content_string = generate_sales_order_pdf($order_data_for_email, $COMPANY_INFO, $db_conn_post);
            $pdf_filename = "SalesOrder_" . $sales_order_id . ".pdf";
            $email_subject = "New Sales Order #{$sales_order_id} — {$cust_name} ({$card_code})";
            $email_body = "A new sales order has been created.<br><br>" .
                          "Customer: <strong>" . htmlspecialchars($cust_name) . "</strong> (" . htmlspecialchars($card_code) . ")<br>" .
                          "Order Total: <strong>" . number_format($total_amount, 2) . " KES</strong><br>" .
                          "Document Date: " . htmlspecialchars($document_date) . "<br><br>" .
                          "The PDF confirmation is attached.";
            $sent_any = false;
            if (!empty($ORDER_NOTIFICATION_EMAILS) && is_array($ORDER_NOTIFICATION_EMAILS)) {
                foreach ($ORDER_NOTIFICATION_EMAILS as $rcpt) {
                    if (filter_var($rcpt, FILTER_VALIDATE_EMAIL)) {
                        if (send_order_email($rcpt, $email_subject, $email_body, $pdf_content_string, $pdf_filename, $ORDER_CC_EMAILS)) {
                            $sent_any = true;
                        }
                    }
                }
            }
            if ($sent_any) {
                $message .= " Notification email sent to company.";
            } else {
                $message .= " Failed to send company notification email.";
            }
            $db_conn_post->close();
        }
    } else {
        $message = "Please select a customer and add items.";
    }
    $_SESSION['message'] = $message;
    header('Location: ' . $_SERVER['PHP_SELF'], true, 303);
    exit();

}

// ----------------- Fetch data from DB -----------------
$customers = [];
$items = [];
$price_lists = [];
$warehouses = [];
$sap_warehouses = [];

$result = $db_conn->query("SELECT c.card_code, c.card_name, c.credit_limit, c.balance, c.price_list AS price_list_code, pl.price_list_name FROM active_customers c LEFT JOIN price_lists pl ON pl.price_list_code = c.price_list ORDER BY c.card_name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $customers[] = [
            'card_code' => $row['card_code'],
            'card_name' => $row['card_name'],
            'credit_limit' => $row['credit_limit'],
            'balance' => $row['balance'],
            'price_list_code' => $row['price_list_code'],
            'price_list_name' => $row['price_list_name']
        ];
    }
    $result->free();
}

$result = $db_conn->query("SELECT item_code, item_name FROM items ORDER BY item_name");
if ($result) {
    while ($row = $result->fetch_assoc()) { $items[] = ['item_code'=>$row['item_code'], 'item_name'=>$row['item_name']]; }
    $result->free();
}

$result = $db_conn->query("SELECT price_list_code, price_list_name FROM price_lists ORDER BY price_list_code");
if ($result) {
    while ($row = $result->fetch_assoc()) { $price_lists[] = ['code'=>$row['price_list_code'], 'name'=>$row['price_list_name']]; }
    $result->free();
}

$wcols_sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='" . $db_conn->real_escape_string($DB_NAME) . "' AND TABLE_NAME='warehouse_stock_wide' ORDER BY ORDINAL_POSITION";
if ($wres = $db_conn->query($wcols_sql)) {
    while ($c = $wres->fetch_assoc()) {
        $cn = $c['COLUMN_NAME'];
        if (!in_array($cn, ['id','item_code','item_name','fetched_at'])) {
            $warehouses[] = $cn;
        }
    }
    $wres->free();
}

// Fetch SAP warehouses
$result = $db_conn->query("SELECT whs_code, whs_name, bpl_id FROM warehouses ORDER BY whs_code");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sap_warehouses[] = [
            'whs_code' => $row['whs_code'],
            'whs_name' => $row['whs_name'] ?: $row['whs_code'],
            'bpl_id' => $row['bpl_id']
        ];
    }
    $result->free();
}

$db_conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Create Sales Order — SalesFlow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
      :root {
        --accent: #2b8cff;
        --muted: #8a94a6;
        --panel: #f7f9fb;
        --card: #ffffff;
        --sidebar: #fbfdff;
      }
      html, body { height: 100%; margin: 0; font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; }
      body { background: #f1f4f8; color: #1f2d3d; }
      .app {
        display: flex;
        min-height: 100vh;
        gap: 20px;
        padding: 20px;
        box-sizing: border-box;
        align-items: stretch;
      }
      .sidebar {
        width: 240px;
        background: var(--sidebar);
        border-radius: 10px;
        border: 1px solid rgba(29,41,60,0.05);
        box-shadow: 0 4px 10px rgba(16,24,36,0.03);
        padding: 18px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
      }
      .main {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 18px;
      }
      .topbar {
        background: var(--card);
        border-radius: 10px;
        padding: 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border: 1px solid rgba(29,41,60,0.03);
      }
      .title h2 { margin: 0; font-size: 20px; }
      .title p { margin: 0; color: var(--muted); font-size: 13px; }
      .content {
        display: flex;
        gap: 20px;
        align-items: flex-start;
      }
      .panel {
        background: var(--card);
        border-radius: 12px;
        padding: 20px;
        border: 1px solid rgba(29,41,60,0.03);
        box-shadow: 0 8px 20px rgba(13,20,30,0.03);
      }
      .main-panel { flex: 1; min-width: 0; }
      .right-panel { width: 360px; }
      .section-title { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
      .section-title h5 { margin: 0; font-size: 16px; }
      .small-muted { color: var(--muted); font-size: 13px; }
      .customer-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; align-items: end; }
      @media (max-width: 768px) {
        .customer-grid { grid-template-columns: 1fr; }
      }
      .form-control, .form-select { border-radius: 8px; padding: 10px 12px; }
      .items-table thead th { background: #f6f8fb; border-bottom: 1px solid rgba(29,41,60,0.04); font-weight: 600; }
      .items-table td { vertical-align: middle; }
      .items-table .btn-remove { color: #e55353; border: 0; background: transparent; }
      .items-table input, .items-table select { border-radius: 8px; padding: 8px; border: 1px solid #e9eef6; }
      .summary { background: #fbfdff; border-radius: 10px; padding: 18px; border: 1px solid rgba(29,41,60,0.03); }
      .summary .total { font-size: 18px; font-weight: 700; color: var(--accent); }
      .summary .muted-row { color: var(--muted); display: flex; justify-content: space-between; padding: 6px 0; }
      @media (max-width: 1100px) {
        .customer-grid { grid-template-columns: 1fr 1fr; }
        .right-panel { width: 320px; }
      }
      @media (max-width: 880px) {
        .app { flex-direction: column; padding: 12px; }
        .sidebar { width: 100%; flex-direction: row; gap: 12px; align-items: center; padding: 10px; }
        .main { width: 100%; }
        .content { flex-direction: column; }
        .right-panel { width: 100%; }
      }
    </style>
</head>
<body>
  <div class="app container-fluid">
    <main class="main">
      <div class="topbar panel d-flex align-items-center justify-content-between">
        <div class="title">
          <h2>Create Sales Order</h2>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          <div>
            <label class="form-label small-muted mb-0">Branch (Stock)</label>
            <select id="warehouse-select" name="default_warehouse" class="form-select form-select-sm">
              <?php foreach(($warehouses ?: ['Head_office']) as $wh): ?>
                <option value="<?php echo htmlspecialchars($wh); ?>"><?php echo htmlspecialchars($wh); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <?php if (isset($message)): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
          <?php echo htmlspecialchars($message); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      <?php if (isset($sap_error)): ?>
        <div id="sap-error-alert" class="alert alert-danger alert-dismissible fade show" role="alert">
          <?php echo htmlspecialchars($sap_error); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      <form method="post" id="orderForm" class="content" novalidate>
        <div class="main-panel panel">
          <div class="mb-4">
            <div class="section-title">
              <h5>Customer Information</h5>
            </div>
            <div class="customer-grid">
              <div>
                <label class="form-label small-muted">Customer</label>
                <div class="input-group">
                  <input id="customer-select" name="card_code" class="form-control" list="customer-list" placeholder="Type customer name or code" autocomplete="off">
                  <datalist id="customer-list">
                    <?php foreach($customers as $c): ?>
                      <option value="<?php echo htmlspecialchars($c['card_code']); ?>">
                        <?php echo htmlspecialchars($c['card_name'] . " — " . $c['card_code']); ?>
                      </option>
                    <?php endforeach; ?>
                  </datalist>
                </div>
                <div id="customer-display">
                  <div id="customer-name" style="font-weight:bold"></div>
                  <div id="customer-credit" style="font-weight:bold"></div>
                  <div id="customer-price-list" class="small-muted"></div>
                </div>
              </div>
              <div>
                <label class="form-label small-muted">Customer Ref No</label>
                <input type="text" name="customer_ref_no" id="customer-ref-no" class="form-control" placeholder="Optional reference number" maxlength="50">
              </div>
              <div>
                <label for="warehouse_code" class="form-label small-muted">Warehouse (Branch)</label>
                <select class="form-select" id="warehouse_code" name="warehouse_code" required>
                  <option value="">Select Warehouse</option>
                  <?php foreach ($sap_warehouses as $warehouse): ?>
                    <option value="<?= htmlspecialchars($warehouse['whs_code']) ?>" <?= ($warehouse['whs_code'] === $SAP_DEFAULT_WAREHOUSE) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($warehouse['whs_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
          <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div class="section-title">
                <h5>Order Items</h5>
              </div>
              <div>
                <button type="button" id="addItemBtn" class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg"></i> Add Item</button>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table items-table table-align-middle">
                <thead>
                  <tr>
                    <th style="width:160px">Item Code</th>
                    <th>Item Name</th>
                    <th style="width:100px">Quantity</th>
                    <th style="width:140px">Gross Price</th>
                    <th style="width:140px">Gross Total</th>
                    <th style="width:120px">Stock on hand</th>
                    <th style="width:60px"></th>
                  </tr>
                </thead>
                <tbody id="itemsBody"></tbody>
              </table>
            </div>
          </div>
        </div>
        <aside class="right-panel panel d-flex flex-column gap-3" style="width:360px; min-width:280px;">
          <div>
            <div class="section-title"><h5>Dates</h5></div>
            <div>
              <div class="mb-2 small-muted">Posting Date</div>
              <input type="date" name="posting_date" class="form-control mb-2" value="<?php echo date('Y-m-d'); ?>">
              <div class="mb-2 small-muted">Document Date</div>
              <input type="date" name="document_date" class="form-control mb-2" value="<?php echo date('Y-m-d'); ?>">
              <div class="mb-2 small-muted">Delivery Date</div>
              <input type="date" name="delivery_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+1 days')); ?>">
            </div>
          </div>
          <div>
            <div class="section-title"><h5>Order Summary</h5></div>
            <div class="summary">
              <div class="muted-row"><div>Subtotal</div><div id="subtotal">0.00 KES</div></div>
              <hr>
              <div class="d-flex justify-content-between align-items-center mb-3">
                <div>Total</div>
                <div class="total" id="grandtotal">0.00 KES</div>
              </div>
              <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary" id="createOrderBtn"><i class="bi bi-send"></i> Create Order</button>
                <button type="button" class="btn btn-outline-secondary" id="previewOrderBtn"><i class="bi bi-eye"></i> Preview Order</button>
              </div>
            </div>
          </div>
        </aside>
      </form>
    </main>
  </div>
  <div id="processingOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center;">
    <div class="text-center text-white">
      <div class="spinner-border spinner-border-lg mb-3" style="width:3rem;height:3rem;color:white" role="status"></div>
      <div style="font-weight:600">Creating Sales Order…</div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.js"></script>
  <script>
    const customers = <?php echo json_encode($customers); ?> || [];
    const items = <?php echo json_encode($items); ?> || [];
    const priceLists = <?php echo json_encode($price_lists); ?> || [];
    const warehouses = <?php echo json_encode($warehouses); ?> || ['Head_office'];
    const sapWarehouses = <?php echo json_encode($sap_warehouses); ?> || [];
    <?php if (isset($sap_error)): ?>
    // Show SAP error with SweetAlert2
    Swal.fire({
      icon: 'error',
      title: 'Order Creation Failed',
      text: '<?php echo addslashes($sap_error); ?>',
      confirmButtonColor: '#dc3545',
      confirmButtonText: 'OK',
      showClass: {
        popup: 'animate__animated animate__fadeInDown'
      },
      hideClass: {
        popup: 'animate__animated animate__fadeOutUp'
      }
    });
    <?php endif; ?>
    const itemsBody = document.getElementById('itemsBody');
    const addItemBtn = document.getElementById('addItemBtn');
    const subtotalEl = document.getElementById('subtotal');
    const grandtotalEl = document.getElementById('grandtotal');
    const createOrderBtn = document.getElementById('createOrderBtn');
    const processingOverlay = document.getElementById('processingOverlay');
    const customerSelect = document.getElementById('customer-select');
    const warehouseSelect = document.getElementById('warehouse-select');
    function generateOptionsList() {
      const list = {};
      items.forEach(it => {
        list[it.item_code] = it.item_name;
      });
      return list;
    }
    const itemsMap = generateOptionsList();
    function addItemRow(pref = {}) {
      const row = document.createElement('tr');
      const code = pref.code || '';
      const name = pref.name || '';
      const qty = pref.qty || 1;
      const price = (pref.price !== undefined) ? parseFloat(pref.price).toFixed(2) : '';
      const defaultWarehouse = '<?php echo htmlspecialchars($SAP_DEFAULT_WAREHOUSE); ?>';
      row.innerHTML = `
        <td>
          <input list="productCodes" class="form-control item-code" name="item_code[]" placeholder="Item code" value="${escapeHtml(code)}">
        </td>
        <td>
          <input class="form-control item-name" name="item_name[]" placeholder="Item name" value="${escapeHtml(name)}">
        </td>
        <td>
          <input class="form-control text-center item-qty" type="number" name="quantity[]" min="1" value="${qty}">
        </td>
        <td>
          <input class="form-control text-end item-price" type="number" step="0.01" name="price[]" value="${price}" readonly>
        </td>
        <td>
          <input class="form-control text-end item-total" readonly value="0.00">
        </td>
        <td class="item-stock"></td>
        <td class="text-center">
          <button type="button" class="btn-remove" title="Remove row"><i class="bi bi-trash3-fill"></i></button>
        </td>
      `;
      itemsBody.appendChild(row);
      const codeInput = row.querySelector('.item-code');
      const nameInput = row.querySelector('.item-name');
      const qtyInput = row.querySelector('.item-qty');
      const priceInput = row.querySelector('.item-price');
      const totalInput = row.querySelector('.item-total');
      const removeBtn = row.querySelector('.btn-remove');
      function computeLine() {
        const q = parseFloat(qtyInput.value) || 0;
        const p = parseFloat(priceInput.value) || 0;
        const total = q * p;
        totalInput.value = numberWithCommas(total.toFixed(2));
        computeTotals();
      }
      async function fetchPriceForCode(codeVal) {
        if (!codeVal) {
          priceInput.value = '';
          computeLine();
          return;
        }
        priceInput.value = '...';
        priceInput.style.background = '#fff7e6';
        try {
          const form = new FormData();
          form.append('action','get_item_price');
          form.append('item_code', codeVal);
          if (customerSelect.value) form.append('card_code', customerSelect.value);
          const res = await fetch('', {method:'POST', body: form});
          const data = await res.json();
          if (data && data.success && parseFloat(data.price)>0) {
            priceInput.value = parseFloat(data.price).toFixed(2);
            priceInput.style.background = '';
          } else {
            priceInput.value = parseFloat(0).toFixed(2);
            priceInput.style.background = '';
          }
        } catch(err) {
          console.error('price fetch error', err);
          priceInput.value = parseFloat(0).toFixed(2);
          priceInput.style.background = '';
        } finally {
          computeLine();
        }
      }
      async function fetchStockForCode(codeVal, row) {
        if (!codeVal) return;
        const stockCell = row.querySelector('.item-stock');
        if (stockCell) {
          stockCell.textContent = '...';
          try {
            const form = new FormData();
            form.append('action', 'get_item_stock');
            form.append('item_code', codeVal);
            const globalWarehouse = document.getElementById('warehouse_code').value;
            if (globalWarehouse) form.append('warehouse', globalWarehouse);
            const res = await fetch('', {method:'POST', body: form});
            const data = await res.json();
            if (data && data.stock) {
              const val = data.stock[globalWarehouse];
              stockCell.textContent = (typeof val !== 'undefined') ? val : '0';
            } else {
              stockCell.textContent = '0';
            }
          } catch {
            stockCell.textContent = 'Err';
          }
        }
      }
      codeInput.addEventListener('change', (e) => {
        const val = e.target.value.trim();
        if (val === '') {
          // Clear related fields when item code is empty
          nameInput.value = '';
          row.querySelector('.item-price').value = '';
          row.querySelector('.item-stock').textContent = '';
          return;
        }

        if (itemsMap[val]) {
          nameInput.value = itemsMap[val];
        }
        fetchPriceForCode(val);
        fetchStockForCode(val, row);
      });
      nameInput.addEventListener('input', (e) => {
        const val = e.target.value.trim().toLowerCase();
        if (!val) return;
        const match = items.find(it => it.item_name.toLowerCase() === val);
        if (match) {
          codeInput.value = match.item_code;
          fetchPriceForCode(match.item_code);
          fetchStockForCode(match.item_code, row);
        }
      });
      qtyInput.addEventListener('input', computeLine);
      removeBtn.addEventListener('click', () => {
        row.remove();
        computeTotals();
      });
      if (code) fetchPriceForCode(code);
      else if (price !== '') computeLine();
      computeLine();
      return row;
    }
    function computeTotals() {
      let subtotal = 0;
      let totalQty = 0;
      document.querySelectorAll('#itemsBody tr').forEach(r => {
        const qty = parseFloat(r.querySelector('.item-qty').value) || 0;
        const line = parseFloat(r.querySelector('.item-total').value.replace(/,/g, '')) || 0;
        totalQty += qty;
        subtotal += line;
      });
      const grand = subtotal;
      subtotalEl.textContent = numberWithCommas(subtotal.toFixed(2)) + ' KES';
      grandtotalEl.textContent = numberWithCommas(grand.toFixed(2)) + ' KES';
    }
    function numberWithCommas(x) { return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ","); }
    function escapeHtml(text) { return (text+'').replace(/[&<>"']/g, function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; }); }
    addItemBtn.addEventListener('click', () => addItemRow());
    addItemRow(); addItemRow(); addItemRow(); addItemRow(); addItemRow();
    document.getElementById('orderForm').addEventListener('submit', (e) => {
      if (!customerSelect.value) {
        e.preventDefault();
        Swal.fire({
          icon: 'warning',
          title: 'Customer Required',
          text: 'Please select a customer before creating the order.',
          confirmButtonColor: '#2b8cff'
        });
        return;
      }
      const warehouseCode = document.getElementById('warehouse_code').value;
      if (!warehouseCode) {
        e.preventDefault();
        Swal.fire({
          icon: 'warning',
          title: 'Warehouse Required',
          text: 'Please select a warehouse for the order.',
          confirmButtonColor: '#2b8cff'
        });
        return;
      }
      const rows = Array.from(document.querySelectorAll('#itemsBody tr'));
      console.log('Found ' + rows.length + ' item rows');

      let hasValid = false;
      for (const row of rows) {
        const code = row.querySelector('.item-code')?.value.trim() || '';
        const qty = parseFloat(row.querySelector('.item-qty')?.value) || 0;
        console.log('Checking row - Code:', code, 'Quantity:', qty);
        if (code && qty > 0) {
          hasValid = true;
          break;
        }
      }

      if (!hasValid) {
        e.preventDefault();
        Swal.fire({
          icon: 'warning',
          title: 'No Valid Items',
          text: 'Please add at least one item with quantity > 0.',
          confirmButtonColor: '#2b8cff'
        });
        return;
      }
      const refNo = document.getElementById('customer-ref-no').value.trim();
      if (refNo && refNo.length > 50) {
        e.preventDefault();
        Swal.fire({
          icon: 'warning',
          title: 'Invalid Reference',
          text: 'Customer Reference Number must be 50 characters or less.',
          confirmButtonColor: '#2b8cff'
        });
        return;
      }
      processingOverlay.style.display = 'flex';
    });
    document.getElementById('previewOrderBtn').addEventListener('click', () => {
      if (!customerSelect.value.trim()) {
        Swal.fire({
          icon: 'warning',
          title: 'Customer Required',
          text: 'Please select a customer before previewing the order.',
          confirmButtonColor: '#2b8cff'
        });
        return;
      }
      const rows = document.querySelectorAll('#itemsBody tr');
      const validItems = Array.from(rows).filter(r => {
        const code = r.querySelector('.item-code').value.trim();
        const qty = parseFloat(r.querySelector('.item-qty').value) || 0;
        return code && qty > 0;
      });
      if (validItems.length === 0) {
        Swal.fire({
          icon: 'warning',
          title: 'No Valid Items',
          text: 'Please add at least one item with quantity > 0.',
          confirmButtonColor: '#2b8cff'
        });
        return;
      }
      const customerVal = customerSelect.value.trim();
      let customerName = '';
      let customerCode = '';
      const found = customers.find(c => c.card_code === customerVal);
      if (found) {
        customerName = found.card_name;
        customerCode = found.card_code;
      }
      const postingDate = document.querySelector('input[name="posting_date"]').value;
      const documentDate = document.querySelector('input[name="document_date"]').value;
      const deliveryDate = document.querySelector('input[name="delivery_date"]').value;
      const customerRefNo = document.getElementById('customer-ref-no').value.trim();
      const globalWarehouse = document.getElementById('warehouse_code').value;
      const whName = sapWarehouses.find(wh => wh.whs_code === globalWarehouse)?.whs_name || globalWarehouse || 'Not set';
      let itemsHtml = '';
      let totalAmount = 0;
      validItems.forEach(r => {
        const code = r.querySelector('.item-code').value;
        const name = r.querySelector('.item-name').value;
        const qty = parseFloat(r.querySelector('.item-qty').value) || 0;
        const priceStr = r.querySelector('.item-price').value;
        const price = (priceStr && !isNaN(parseFloat(priceStr))) ? parseFloat(priceStr) : 0;
        const lineTotal = qty * price;
        totalAmount += lineTotal;
        itemsHtml += `
          <div class="d-flex justify-content-between align-items-center mb-2 p-2 border rounded">
            <div class="flex-grow-1">
              <div class="fw-bold">${escapeHtml(code)} - ${escapeHtml(name)}</div>
              <small class="text-muted">Warehouse: ${escapeHtml(whName)}</small>
            </div>
            <div class="text-end">
              <span class="badge bg-primary me-2">${qty}</span>
              <span class="text-muted">${numberWithCommas(price.toFixed(2))} KES</span>
              <div class="fw-bold text-primary">${numberWithCommas(lineTotal.toFixed(2))} KES</div>
            </div>
          </div>
        `;
      });
      const formatDate = (dateStr) => {
        if (!dateStr) return 'Not set';
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
      };
      const modalHtml = `
        <div class="text-center mb-4">
          <i class="bi bi-receipt-cutoff" style="font-size: 3rem; color: #2b8cff;"></i>
          <h4 class="mt-2">Order Preview</h4>
        </div>
        <div class="mb-4">
          <h6 class="text-muted mb-3"><i class="bi bi-person-circle me-2"></i>Customer Information</h6>
          <div class="p-3 bg-light rounded">
            <div class="row">
              <div class="col-sm-6">
                <strong>Name:</strong> ${escapeHtml(customerName)}
              </div>
              <div class="col-sm-6">
                <strong>Code:</strong> ${escapeHtml(customerCode)}
              </div>
            </div>
            ${customerRefNo ? `<div class="mt-2"><strong>Reference:</strong> ${escapeHtml(customerRefNo)}</div>` : ''}
          </div>
        </div>
        <div class="mb-4">
          <h6 class="text-muted mb-3"><i class="bi bi-calendar-event me-2"></i>Dates</h6>
          <div class="row g-2">
            <div class="col-md-4">
              <div class="p-2 bg-light rounded text-center">
                <small class="text-muted d-block">Posting Date</small>
                <strong>${formatDate(postingDate)}</strong>
              </div>
            </div>
            <div class="col-md-4">
              <div class="p-2 bg-light rounded text-center">
                <small class="text-muted d-block">Document Date</small>
                <strong>${formatDate(documentDate)}</strong>
              </div>
            </div>
            <div class="col-md-4">
              <div class="p-2 bg-light rounded text-center">
                <small class="text-muted d-block">Delivery Date</small>
                <strong>${formatDate(deliveryDate)}</strong>
              </div>
            </div>
          </div>
        </div>
        <div class="mb-4">
          <h6 class="text-muted mb-3"><i class="bi bi-cart me-2"></i>Order Items</h6>
          <div style="max-height: 300px; overflow-y: auto;">
            ${itemsHtml}
          </div>
        </div>
        <div class="text-center p-3 bg-primary text-white rounded">
          <h5 class="mb-0">Total Amount: ${numberWithCommas(totalAmount.toFixed(2))} KES</h5>
        </div>
      `;
      Swal.fire({
        html: modalHtml,
        showConfirmButton: true,
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-check-circle me-2"></i>Looks Good!',
        cancelButtonText: '<i class="bi bi-pencil me-2"></i>Edit Order',
        confirmButtonColor: '#2b8cff',
        cancelButtonColor: '#6c757d',
        width: '600px',
        customClass: {
          popup: 'animated fadeInDown',
          confirmButton: 'btn btn-primary me-2',
          cancelButton: 'btn btn-outline-secondary'
        },
        buttonsStyling: false
      }).then((result) => {
        if (result.isConfirmed) {
          document.getElementById('createOrderBtn').click();
        }
      });
    });
    (function createDatalist(){
      const dl = document.createElement('datalist');
      dl.id = 'productCodes';
      for (const it of items) {
        const opt = document.createElement('option');
        opt.value = it.item_code;
        opt.innerText = it.item_name;
        dl.appendChild(opt);
      }
      document.body.appendChild(dl);
    })();
    customerSelect.addEventListener('change', () => {
      document.querySelectorAll('#itemsBody tr .item-code').forEach(input => {
        input.dispatchEvent(new Event('change'));
      });
    });
    document.getElementById('warehouse_code').addEventListener('change', () => {
      document.querySelectorAll('#itemsBody tr .item-code').forEach(input => {
        if (input.value.trim()) {
          const row = input.closest('tr');
          fetchStockForCode(input.value.trim(), row);
        }
      });
    });
    function updateCustomerDisplay() {
      const val = customerSelect.value.trim();
      const nameDiv = document.getElementById('customer-name');
      const creditDiv = document.getElementById('customer-credit');
      const plDiv = document.getElementById('customer-price-list');
      const warehouseSelect = document.getElementById('warehouse_code');
      if (!val) {
        nameDiv.textContent = '';
        creditDiv.textContent = '';
        if (plDiv) plDiv.textContent = '';
        warehouseSelect.value = '<?php echo htmlspecialchars($SAP_DEFAULT_WAREHOUSE); ?>';
        return;
      }
      let found = customers.find(c => c.card_code === val);
      if (!found) {
        found = customers.find(c => c.card_name.toLowerCase() === val.toLowerCase());
      }
      if (found) {
        nameDiv.textContent = `${found.card_name} — ${found.card_code}`;
        creditDiv.innerHTML = `Credit Limit: ${numberWithCommas(found.credit_limit)} KES<br>Balance: ${numberWithCommas(found.balance)} KES`;
        if (plDiv) {
          const plName = found.price_list_name ? ` (${found.price_list_name})` : '';
          plDiv.textContent = `Price List: ${found.price_list_code ?? ''}${plName}`.trim();
        }
        customerSelect.value = found.card_code;
        // Auto-select warehouse based on customer (if available, else default)
        if (found.default_warehouse) {
          warehouseSelect.value = found.default_warehouse;
        } else {
          warehouseSelect.value = '<?php echo htmlspecialchars($SAP_DEFAULT_WAREHOUSE); ?>';
        }
        // Trigger warehouse change to update stock
        warehouseSelect.dispatchEvent(new Event('change'));
      } else {
        nameDiv.textContent = '';
        creditDiv.textContent = '';
        if (plDiv) plDiv.textContent = '';
        warehouseSelect.value = '<?php echo htmlspecialchars($SAP_DEFAULT_WAREHOUSE); ?>';
      }
    }
    customerSelect.addEventListener('change', updateCustomerDisplay);
    customerSelect.addEventListener('blur', updateCustomerDisplay);
    computeTotals();
  </script>
</body>
</html>