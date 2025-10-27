<?php
set_time_limit(300);

// Local MySQL database setup
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'customer_test';

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if not exists and select it
$conn->query("CREATE DATABASE IF NOT EXISTS `$DB_NAME`");
$conn->select_db($DB_NAME);

// Ensure table exists (mirror of sync script)
$table_sql = "CREATE TABLE IF NOT EXISTS business_partners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    card_code VARCHAR(50),
    card_name VARCHAR(255),
    phone VARCHAR(50),
    city VARCHAR(100),
    county VARCHAR(100),
    email VARCHAR(255),
    credit_limit DECIMAL(15,2),
    current_balance DECIMAL(15,2),
    address TEXT,
    contact TEXT
)";
$conn->query($table_sql);

// Fetch rows from local table
$rows = [];
$result = $conn->query("SELECT card_code, card_name, phone, city, county, email, credit_limit, current_balance, address, contact FROM business_partners ORDER BY card_name ASC");
if ($result) {
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
    $result->free();
}

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
$status = isset($_GET['status']) ? $_GET['status'] : '';
$message = isset($_GET['message']) ? $_GET['message'] : '';
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Business Partners (Local)</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom SAP Fiori CSS -->
    <link rel="stylesheet" href="sap-fiori.css">
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-2 d-none d-md-block sidebar">
                <div class="position-sticky">
                    <h5 class="mt-3">Navigation</h5>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="business_partners.php"><i class="fas fa-users"></i> Business Partners</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="create_business_partner.php"><i class="fas fa-user-plus"></i> Create BP</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="customers.php"><i class="fas fa-user-friends"></i> Customers</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="price_list.php"><i class="fas fa-tags"></i> Price Lists</a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-10 ms-sm-auto px-md-4">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item active" aria-current="page">Business Partners</li>
                    </ol>
                </nav>
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Business Partners</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a class="btn btn-sm btn-outline-primary" href="sync_business_partners.php" title="Run sync to refresh local data"><i class="fas fa-sync"></i> Sync</a>
                            <a class="btn btn-sm btn-outline-success" href="create_business_partner.php" title="Create a new Business Partner"><i class="fas fa-plus"></i> Add</a>
                        </div>
                    </div>
                </div>

                <small class="text-muted">This page displays data from the local `business_partners` table.</small>

                <?php if ($status || $message): ?>
                    <div class="alert alert-<?= $status === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show mt-3" role="alert">
                        <?= h($message ?: ($status === 'success' ? 'Operation completed successfully.' : '')) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card mt-3">
                    <div class="card-body">
                        <?php if (count($rows) === 0): ?>
                            <p class="text-center text-muted">No data in local table. Click "Sync from Service Layer" to load.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th scope="col">#</th>
                                            <th scope="col">CardCode</th>
                                            <th scope="col">CardName</th>
                                            <th scope="col">Phone</th>
                                            <th scope="col">City</th>
                                            <th scope="col">County</th>
                                            <th scope="col">Email</th>
                                            <th scope="col" class="text-end">CreditLimit</th>
                                            <th scope="col" class="text-end">CurrentBalance</th>
                                            <th scope="col">Primary Address</th>
                                            <th scope="col">Primary Contact</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($rows as $i => $r): ?>
                                        <tr>
                                            <th scope="row"><?= ($i+1) ?></th>
                                            <td><?= h($r['card_code']) ?></td>
                                            <td><?= h($r['card_name']) ?></td>
                                            <td><?= h($r['phone']) ?></td>
                                            <td><?= h($r['city']) ?></td>
                                            <td><?= h($r['county']) ?></td>
                                            <td><?= h($r['email']) ?></td>
                                            <td class="text-end"><?= $r['credit_limit'] !== null ? number_format((float)$r['credit_limit'], 2) : '' ?></td>
                                            <td class="text-end"><?= $r['current_balance'] !== null ? number_format((float)$r['current_balance'], 2) : '' ?></td>
                                            <td><?= nl2br(h($r['address'])) ?></td>
                                            <td><?= nl2br(h($r['contact'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

<?php $conn->close(); ?>
</body>
</html>


