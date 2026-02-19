<?php
require_once 'config.php';

$current_date = date('Y-m-d');
$search = isset($_GET['search']) ? $_GET['search'] : '';
$client_type = isset($_GET['client_type']) ? $_GET['client_type'] : '';

// Get expired contracts
$query = "SELECT 
            c.*, 
            cl.company_name, 
            cl.classification,
            DATEDIFF('$current_date', c.contract_end) as days_expired
          FROM rental_contracts c
          JOIN rental_clients cl ON c.client_id = cl.id
          WHERE c.contract_end IS NOT NULL 
          AND c.contract_end < '$current_date'
          AND c.status = 'ACTIVE'";

$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (c.contract_number LIKE ? OR cl.company_name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if (!empty($client_type)) {
    $query .= " AND cl.classification = ?";
    $params[] = $client_type;
    $types .= "s";
}

$query .= " ORDER BY c.contract_end DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$contracts = $stmt->get_result();

// Get summary stats
$total_expired = $contracts->num_rows;
$expired_this_month = $conn->query("
    SELECT COUNT(*) as count 
    FROM rental_contracts 
    WHERE contract_end IS NOT NULL 
    AND contract_end < '$current_date'
    AND MONTH(contract_end) = MONTH('$current_date')
    AND YEAR(contract_end) = YEAR('$current_date')
    AND status = 'ACTIVE'
")->fetch_assoc()['count'];

$expired_this_year = $conn->query("
    SELECT COUNT(*) as count 
    FROM rental_contracts 
    WHERE contract_end IS NOT NULL 
    AND contract_end < '$current_date'
    AND YEAR(contract_end) = YEAR('$current_date')
    AND status = 'ACTIVE'
")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expired Contracts - CPI Rental</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        
        .header {
            background: white;
            padding: 25px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        h1 { color: #2c3e50; display: flex; align-items: center; gap: 10px; }
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid;
        }
        .card-red { border-left-color: #e74c3c; }
        .card-orange { border-left-color: #e67e22; }
        .card-purple { border-left-color: #9b59b6; }
        
        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .filter-form {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #34495e;
            font-size: 13px;
        }
        
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        
        .table-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            text-align: left;
            padding: 15px 10px;
            color: #7f8c8d;
            font-weight: 600;
            font-size: 13px;
            border-bottom: 2px solid #f0f2f5;
        }
        
        td {
            padding: 15px 10px;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .badge-expired {
            background: #f8d7da;
            color: #721c24;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .days-expired {
            font-weight: bold;
            color: #e74c3c;
        }
        
        .contract-number {
            font-family: monospace;
            font-weight: 600;
            color: #2980b9;
            text-decoration: none;
        }
        .contract-number:hover {
            text-decoration: underline;
        }
        
        .back-btn {
            background: #95a5a6;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .back-btn:hover { background: #7f8c8d; }
        
        .renew-btn {
            background: #27ae60;
            color: white;
            padding: 6px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 12px;
        }
        .renew-btn:hover { background: #229954; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                ⚠️ Expired Contracts
                <span style="font-size: 16px; background: #f8d7da; padding: 5px 15px; border-radius: 25px; color: #721c24;">
                    <?php echo $total_expired; ?> Contracts
                </span>
            </h1>
            <div>
                <a href="r-dashboard.php" class="back-btn">← Back to Dashboard</a>
                <a href="r-expiring_contracts.php" class="btn btn-primary">View Expiring</a>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card card-red">
                <div style="font-size: 14px; color: #7f8c8d;">Total Expired</div>
                <div style="font-size: 36px; font-weight: bold; color: #e74c3c;"><?php echo $total_expired; ?></div>
                <div style="font-size: 12px; color: #95a5a6;">Active contracts past end date</div>
            </div>
            <div class="summary-card card-orange">
                <div style="font-size: 14px; color: #7f8c8d;">Expired This Month</div>
                <div style="font-size: 36px; font-weight: bold; color: #e67e22;"><?php echo $expired_this_month; ?></div>
                <div style="font-size: 12px; color: #95a5a6;"><?php echo date('F Y'); ?></div>
            </div>
            <div class="summary-card card-purple">
                <div style="font-size: 14px; color: #7f8c8d;">Expired This Year</div>
                <div style="font-size: 36px; font-weight: bold; color: #9b59b6;"><?php echo $expired_this_year; ?></div>
                <div style="font-size: 12px; color: #95a5a6;"><?php echo date('Y'); ?></div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <label>Search</label>
                    <input type="text" name="search" placeholder="Contract # or Client name" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-group">
                    <label>Client Type</label>
                    <select name="client_type">
                        <option value="">All Types</option>
                        <option value="GOVERNMENT" <?php echo $client_type == 'GOVERNMENT' ? 'selected' : ''; ?>>GOVERNMENT</option>
                        <option value="PRIVATE" <?php echo $client_type == 'PRIVATE' ? 'selected' : ''; ?>>PRIVATE</option>
                    </select>
                </div>
                <div class="filter-group" style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="r-expired_contracts.php" class="btn" style="background: #95a5a6; color: white;">Clear</a>
                </div>
            </form>
        </div>

        <!-- Contracts Table -->
        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Contract #</th>
                        <th>Client</th>
                        <th>Type</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Days Expired</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($contracts->num_rows > 0): ?>
                        <?php while($contract = $contracts->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <a href="r-edit_contract.php?id=<?php echo $contract['id']; ?>" class="contract-number">
                                        <?php echo htmlspecialchars($contract['contract_number']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($contract['company_name']); ?></td>
                                <td>
                                    <span style="color: <?php echo $contract['classification'] == 'GOVERNMENT' ? '#27ae60' : '#3498db'; ?>;">
                                        <?php echo $contract['classification']; ?>
                                    </span>
                                </td>
                                <td><?php echo $contract['contract_start'] ? date('M d, Y', strtotime($contract['contract_start'])) : '—'; ?></td>
                                <td><strong><?php echo date('M d, Y', strtotime($contract['contract_end'])); ?></strong></td>
                                <td>
                                    <span class="days-expired">
                                        <?php echo $contract['days_expired']; ?> days
                                    </span>
                                </td>
                                <td>
                                    <span class="badge-expired">Expired</span>
                                </td>
                                <td>
                                    <a href="r-edit_contract.php?id=<?php echo $contract['id']; ?>" class="renew-btn">
                                        Renew Contract
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px;">
                                <span style="font-size: 48px;">✅</span>
                                <h3 style="color: #2c3e50; margin-top: 20px;">No Expired Contracts</h3>
                                <p style="color: #7f8c8d;">All active contracts are within their valid period.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>