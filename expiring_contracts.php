<?php
require_once 'config.php';

$current_date = date('Y-m-d');
$search = isset($_GET['search']) ? $_GET['search'] : '';
$client_type = isset($_GET['client_type']) ? $_GET['client_type'] : '';

// Get expiring contracts (next 60 days)
$query = "SELECT 
            c.*, 
            cl.company_name, 
            cl.classification,
            DATEDIFF(c.contract_end, '$current_date') as days_left
          FROM contracts c
          JOIN clients cl ON c.client_id = cl.id
          WHERE c.contract_end IS NOT NULL 
          AND c.contract_end BETWEEN '$current_date' AND DATE_ADD('$current_date', INTERVAL 60 DAY)
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

$query .= " ORDER BY c.contract_end ASC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$contracts = $stmt->get_result();

// Get summary stats
$total_expiring = $contracts->num_rows;
$expiring_this_week = $conn->query("
    SELECT COUNT(*) as count 
    FROM contracts 
    WHERE contract_end IS NOT NULL 
    AND contract_end BETWEEN '$current_date' AND DATE_ADD('$current_date', INTERVAL 7 DAY)
    AND status = 'ACTIVE'
")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expiring Contracts - CPI Rental</title>
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
        .card-yellow { border-left-color: #f39c12; }
        .card-orange { border-left-color: #e67e22; }
        .card-blue { border-left-color: #3498db; }
        
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
        .btn-warning { background: #f39c12; color: white; }
        .btn-warning:hover { background: #e67e22; }
        
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
        
        .badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-critical { background: #f8d7da; color: #721c24; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-normal { background: #d4edda; color: #155724; }
        
        .days-left {
            font-weight: bold;
            font-size: 16px;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                ⏰ Expiring Contracts (60 Days)
                <span style="font-size: 16px; background: #fff3cd; padding: 5px 15px; border-radius: 25px; color: #856404;">
                    <?php echo $total_expiring; ?> Contracts
                </span>
            </h1>
            <div>
                <a href="dashboard.php" class="back-btn">← Back to Dashboard</a>
                <a href="expired_contracts.php" class="btn btn-warning">View Expired</a>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card card-yellow">
                <div style="font-size: 14px; color: #7f8c8d;">Expiring This Week</div>
                <div style="font-size: 36px; font-weight: bold; color: #e67e22;"><?php echo $expiring_this_week; ?></div>
                <div style="font-size: 12px; color: #95a5a6;">Next 7 days</div>
            </div>
            <div class="summary-card card-orange">
                <div style="font-size: 14px; color: #7f8c8d;">Expiring in 30 Days</div>
                <div style="font-size: 36px; font-weight: bold; color: #e67e22;">
                    <?php 
                    $expiring_30 = $conn->query("
                        SELECT COUNT(*) as count 
                        FROM contracts 
                        WHERE contract_end IS NOT NULL 
                        AND contract_end BETWEEN '$current_date' AND DATE_ADD('$current_date', INTERVAL 30 DAY)
                        AND status = 'ACTIVE'
                    ")->fetch_assoc()['count'];
                    echo $expiring_30;
                    ?>
                </div>
                <div style="font-size: 12px; color: #95a5a6;">Next 30 days</div>
            </div>
            <div class="summary-card card-blue">
                <div style="font-size: 14px; color: #7f8c8d;">Average Days Left</div>
                <div style="font-size: 36px; font-weight: bold; color: #3498db;">
                    <?php 
                    $avg_days = $conn->query("
                        SELECT AVG(DATEDIFF(contract_end, '$current_date')) as avg_days
                        FROM contracts
                        WHERE contract_end IS NOT NULL
                        AND contract_end >= '$current_date'
                        AND status = 'ACTIVE'
                    ")->fetch_assoc()['avg_days'];
                    echo $avg_days ? round($avg_days) : '0';
                    ?>
                </div>
                <div style="font-size: 12px; color: #95a5a6;">Days remaining</div>
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
                    <a href="expiring_contracts.php" class="btn" style="background: #95a5a6; color: white;">Clear</a>
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
                        <th>Days Left</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($contracts->num_rows > 0): ?>
                        <?php while($contract = $contracts->fetch_assoc()): 
                            $days_left = $contract['days_left'];
                            $badge_class = 'badge-normal';
                            $badge_text = 'Normal';
                            
                            if ($days_left <= 7) {
                                $badge_class = 'badge-critical';
                                $badge_text = 'Critical';
                            } elseif ($days_left <= 30) {
                                $badge_class = 'badge-warning';
                                $badge_text = 'Warning';
                            }
                        ?>
                            <tr>
                                <td>
                                    <a href="edit_contract.php?id=<?php echo $contract['id']; ?>" class="contract-number">
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
                                    <span class="days-left" style="color: <?php echo $days_left <= 7 ? '#e74c3c' : ($days_left <= 30 ? '#e67e22' : '#27ae60'); ?>;">
                                        <?php echo $days_left; ?> days
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <?php echo $badge_text; ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="edit_contract.php?id=<?php echo $contract['id']; ?>" style="color: #3498db; text-decoration: none;">Renew</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px;">
                                <span style="font-size: 48px;">🎉</span>
                                <h3 style="color: #2c3e50; margin-top: 20px;">No Expiring Contracts</h3>
                                <p style="color: #7f8c8d;">No contracts are expiring in the next 60 days.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>