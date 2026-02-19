<?php
require_once 'config.php';
session_start();

// Get current year for comparison
$current_year = date('Y');
$current_month = date('m');
$current_date = date('Y-m-d');

// ============== SUMMARY STATISTICS ==============
// Total Contracts
$total_contracts = $conn->query("SELECT COUNT(*) as count FROM rental_contracts")->fetch_assoc()['count'];

// Active Contracts
$active_contracts = $conn->query("SELECT COUNT(*) as count FROM rental_contracts WHERE status = 'ACTIVE'")->fetch_assoc()['count'];

// Total Machines
$total_machines = $conn->query("SELECT COUNT(*) as count FROM rental_contract_machines")->fetch_assoc()['count'];

// Active Machines
$active_machines = $conn->query("SELECT COUNT(*) as count FROM rental_contract_machines WHERE status = 'ACTIVE'")->fetch_assoc()['count'];

// Total Clients
$total_clients = $conn->query("SELECT COUNT(*) as count FROM rental_clients")->fetch_assoc()['count'];

// Active Clients
$active_clients = $conn->query("SELECT COUNT(*) as count FROM rental_clients WHERE status = 'ACTIVE'")->fetch_assoc()['count'];

// ============== CONTRACT EXPIRATION STATISTICS ==============
// Expiring Soon (next 60 days)
$expiring_soon = $conn->query("
    SELECT COUNT(*) as count 
    FROM rental_contracts 
    WHERE contract_end IS NOT NULL 
    AND contract_end BETWEEN '$current_date' AND DATE_ADD('$current_date', INTERVAL 60 DAY)
    AND status = 'ACTIVE'
")->fetch_assoc()['count'];

// Expired Contracts
$expired_contracts = $conn->query("
    SELECT COUNT(*) as count 
    FROM rental_contracts 
    WHERE contract_end IS NOT NULL 
    AND contract_end < '$current_date'
    AND status = 'ACTIVE'
")->fetch_assoc()['count'];

// Active contracts with end dates this year
$ending_this_year = $conn->query("
    SELECT COUNT(*) as count 
    FROM rental_contracts 
    WHERE YEAR(contract_end) = $current_year
    AND status = 'ACTIVE'
")->fetch_assoc()['count'];

// ============== ZONE DISTRIBUTION FOR TABLE ==============
$zone_table_stats = $conn->query("
    SELECT 
        z.zone_number,
        z.area_center,
        z.reading_date,
        COUNT(cm.id) as machine_count,
        COUNT(DISTINCT cm.contract_id) as contract_count
    FROM rental_zoning_zone z
    LEFT JOIN rental_contract_machines cm ON z.id = cm.zone_id AND cm.status = 'ACTIVE'
    GROUP BY z.id
    ORDER BY z.zone_number
");

// ============== CHARTS DATA ==============
// 1. Contracts by Classification (Government vs Private)
$classification_stats = $conn->query("
    SELECT 
        cl.classification,
        COUNT(c.id) as contract_count
    FROM rental_clients cl
    LEFT JOIN rental_contracts c ON cl.id = c.client_id
    GROUP BY cl.classification
");

$classification_labels = [];
$classification_data = [];
while($row = $classification_stats->fetch_assoc()) {
    $classification_labels[] = $row['classification'];
    $classification_data[] = $row['contract_count'];
}

// 2. Contracts by Status
$status_stats = $conn->query("
    SELECT 
        status,
        COUNT(*) as count
    FROM rental_contracts
    GROUP BY status
");

$status_labels = [];
$status_data = [];
$status_colors = [];
while($row = $status_stats->fetch_assoc()) {
    $status_labels[] = $row['status'];
    $status_data[] = $row['count'];
    switch($row['status']) {
        case 'ACTIVE': $status_colors[] = '#27ae60'; break;
        case 'INACTIVE': $status_colors[] = '#95a5a6'; break;
        case 'SUSPENDED': $status_colors[] = '#e67e22'; break;
        default: $status_colors[] = '#3498db';
    }
}

// 3. Machines by Type
$machine_type_stats = $conn->query("
    SELECT 
        machine_type,
        COUNT(*) as count
    FROM rental_contract_machines
    WHERE status = 'ACTIVE'
    GROUP BY machine_type
");

$machine_type_labels = [];
$machine_type_data = [];
while($row = $machine_type_stats->fetch_assoc()) {
    $machine_type_labels[] = $row['machine_type'];
    $machine_type_data[] = $row['count'];
}

// 4. Contracts by Month (Current Year)
$monthly_contracts = $conn->query("
    SELECT 
        MONTH(datecreated) as month,
        COUNT(*) as count
    FROM rental_contracts
    WHERE YEAR(datecreated) = $current_year
    GROUP BY MONTH(datecreated)
    ORDER BY month
");

$months = [];
$monthly_data = [];
for($i = 1; $i <= 12; $i++) {
    $months[] = date('M', mktime(0, 0, 0, $i, 1));
    $monthly_data[$i] = 0;
}

while($row = $monthly_contracts->fetch_assoc()) {
    $monthly_data[$row['month']] = $row['count'];
}

// 5. Zone Distribution Chart
$zone_distribution = $conn->query("
    SELECT 
        z.zone_number,
        z.area_center,
        COUNT(cm.id) as machine_count
    FROM rental_zoning_zone z
    LEFT JOIN rental_contract_machines cm ON z.id = cm.zone_id AND cm.status = 'ACTIVE'
    GROUP BY z.id
    ORDER BY z.zone_number
");

$zone_labels = [];
$zone_data = [];
while($row = $zone_distribution->fetch_assoc()) {
    $zone_labels[] = "Zone {$row['zone_number']}";
    $zone_data[] = $row['machine_count'];
}

// 6. Top Clients by Machine Count
$top_clients = $conn->query("
    SELECT 
        cl.id,
        cl.company_name,
        cl.classification,
        COUNT(cm.id) as machine_count,
        COUNT(DISTINCT c.id) as contract_count
    FROM rental_clients cl
    JOIN rental_contracts c ON cl.id = c.client_id AND c.status = 'ACTIVE'
    JOIN rental_contract_machines cm ON c.id = cm.contract_id AND cm.status = 'ACTIVE'
    GROUP BY cl.id
    ORDER BY machine_count DESC
    LIMIT 5
");

// ============== RECENT ACTIVITIES ==============
$recent_contracts = $conn->query("
    SELECT 
        'contract' as type,
        c.id,
        c.contract_number,
        c.status,
        c.contract_end,
        cl.company_name,
        c.datecreated as date
    FROM rental_contracts c
    JOIN rental_clients cl ON c.client_id = cl.id
    ORDER BY c.datecreated DESC
    LIMIT 5
");

$recent_machines = $conn->query("
    SELECT 
        'machine' as type,
        cm.id,
        cm.machine_number,
        cm.machine_type,
        cm.department,
        cm.status,
        cl.company_name,
        cm.datecreated as date
    FROM rental_contract_machines cm
    JOIN rental_contracts c ON cm.contract_id = c.id
    JOIN rental_clients cl ON c.client_id = cl.id
    ORDER BY cm.datecreated DESC
    LIMIT 5
");

// ============== ALERTS ==============
// Contracts with misaligned reading dates
$misaligned_machines = $conn->query("
    SELECT 
        COUNT(*) as count
    FROM rental_contract_machines
    WHERE reading_date_remarks = 'mis-aligned reading date'
    AND status = 'ACTIVE'
")->fetch_assoc()['count'];

// Machines with no zone assigned
$no_zone_machines = $conn->query("
    SELECT 
        COUNT(*) as count
    FROM rental_contract_machines
    WHERE zone_id IS NULL AND status = 'ACTIVE'
")->fetch_assoc()['count'];

// Contracts with missing collection date
$missing_collection = $conn->query("
    SELECT 
        COUNT(*) as count
    FROM rental_contracts
    WHERE (collection_date IS NULL OR collection_date = 0)
    AND status = 'ACTIVE'
")->fetch_assoc()['count'];

// Suspended contracts
$suspended_contracts = $conn->query("
    SELECT 
        COUNT(*) as count
    FROM rental_contracts
    WHERE status = 'SUSPENDED'
")->fetch_assoc()['count'];

// Contracts ending today
$ending_today = $conn->query("
    SELECT 
        COUNT(*) as count
    FROM rental_contracts
    WHERE contract_end = '$current_date'
    AND status = 'ACTIVE'
")->fetch_assoc()['count'];

// Contracts with no machines
$contracts_no_machines = $conn->query("
    SELECT 
        COUNT(DISTINCT c.id) as count
    FROM rental_contracts c
    LEFT JOIN rental_contract_machines cm ON c.id = cm.contract_id
    WHERE cm.id IS NULL AND c.status = 'ACTIVE'
")->fetch_assoc()['count'];

// ============== EXPIRING THIS MONTH AND NEXT MONTH ==============
$expiring_this_month = $conn->query("
    SELECT 
        COUNT(*) as count
    FROM rental_contracts
    WHERE contract_end IS NOT NULL
    AND MONTH(contract_end) = $current_month
    AND YEAR(contract_end) = $current_year
    AND contract_end >= '$current_date'
    AND status = 'ACTIVE'
")->fetch_assoc()['count'];

$expiring_next_month = $conn->query("
    SELECT 
        COUNT(*) as count
    FROM rental_contracts
    WHERE contract_end IS NOT NULL
    AND MONTH(contract_end) = ($current_month + 1)
    AND YEAR(contract_end) = $current_year
    AND status = 'ACTIVE'
")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - CPI Rental Management</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f0f2f5; 
            padding: 20px;
        }
        .container { max-width: auto; margin: 0 auto; }
        
        /* Header Styles */
        .header {
            background: white;
            padding: 25px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .header h1 {
            color: #2c3e50;
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .date-badge {
            background: #e3f2fd;
            color: #1976d2;
            padding: 8px 15px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: normal;
        }
        
        /* Summary Cards - Clickable */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .summary-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            position: relative;
            overflow: hidden;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
        }
        .card-blue::before { background: #3498db; }
        .card-green::before { background: #27ae60; }
        .card-purple::before { background: #9b59b6; }
        .card-yellow::before { background: #f1c40f; }
        .card-red::before { background: #e74c3c; }
        
        .card-title {
            color: #7f8c8d;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }
        .card-number {
            font-size: 36px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .card-sub {
            color: #95a5a6;
            font-size: 13px;
        }
        .card-icon {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 48px;
            color: rgba(0,0,0,0.05);
        }
        
        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        .chart-card {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f2f5;
        }
        .chart-title {
            color: #2c3e50;
            font-size: 18px;
            font-weight: 600;
        }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        /* Two Column Layout */
        .two-column {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        /* Three Column Layout */
        .three-column {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
            margin-bottom: 30px;
        }
        
        /* Tables */
        .table-card {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            height: fit-content;
        }
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f2f5;
        }
        .table-title {
            color: #2c3e50;
            font-size: 18px;
            font-weight: 600;
        }
        .view-all {
            color: #3498db;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        .view-all:hover {
            text-decoration: underline;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            text-align: left;
            padding: 12px 10px;
            color: #7f8c8d;
            font-weight: 600;
            font-size: 13px;
            border-bottom: 2px solid #f0f2f5;
        }
        td {
            padding: 12px 10px;
            border-bottom: 1px solid #ecf0f1;
            color: #2c3e50;
        }
        tr:hover td {
            background: #f8f9fa;
        }
        
        /* Status Badges */
        .badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-inactive { background: #f8d7da; color: #721c24; }
        .badge-suspended { background: #fff3cd; color: #856404; }
        .badge-aligned { background: #d4edda; color: #155724; }
        .badge-misaligned { background: #fff3cd; color: #856404; }
        .badge-expiring { background: #fff3cd; color: #856404; }
        .badge-expired { background: #f8d7da; color: #721c24; }
        
        /* Alert Cards */
        .alerts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .alert-card {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.3s;
        }
        .alert-card:hover {
            transform: translateY(-3px);
        }
        .alert-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .alert-warning { background: #fff3cd; color: #856404; }
        .alert-danger { background: #f8d7da; color: #721c24; }
        .alert-info { background: #d1ecf1; color: #0c5460; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-content h4 {
            font-size: 14px;
            color: #7f8c8d;
            margin-bottom: 5px;
        }
        .alert-content p {
            font-size: 20px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        /* Quick Actions */
        .quick-actions {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #229954; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-warning:hover { background: #e67e22; }
        .btn-info { background: #00bcd4; color: white; }
        .btn-info:hover { background: #00a5bb; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        
        /* Zone Badge */
        .zone-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #e3f2fd;
            color: #1976d2;
        }
        
        /* Progress Bar */
        .progress {
            width: 100%;
            height: 8px;
            background: #ecf0f1;
            border-radius: 4px;
            margin-top: 10px;
            overflow: hidden;
        }
        .progress-bar {
            height: 100%;
            background: #27ae60;
            border-radius: 4px;
            transition: width 0.3s;
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .two-column, .three-column {
                grid-template-columns: 1fr;
            }
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 768px) {
            .summary-grid { grid-template-columns: 1fr; }
            .header { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>
                📊 CPI Rental Management Dashboard
                <span class="date-badge"><?php echo date('F d, Y'); ?></span>
            </h1>
            <div class="quick-actions" style="margin-bottom: 0; padding: 0; background: transparent;">
                <a href="r-add_contracts.php" class="btn btn-success">➕ New Contract</a>
                <a href="r-view_contracts.php" class="btn btn-primary">📋 View Contracts</a>
                <a href="r-calendar.php" class="btn" style="background: #9b59b6; color: white;">📅 Calendar</a>
                <a href="r-view_zones.php" class="btn btn-info">🗺️ Zone Map</a>
            </div>
        </div>

        <!-- Summary Cards - Clickable -->
        <div class="summary-grid">
            <a href="r-view_contracts.php" class="summary-card card-blue">
                <div class="card-title">Total Contracts</div>
                <div class="card-number"><?php echo $total_contracts; ?></div>
                <div class="card-sub"><?php echo $active_contracts; ?> Active</div>
                <div class="card-icon">📄</div>
            </a>
            
            <a href="r-view_all_machines.php" class="summary-card card-green">
                <div class="card-title">Total Machines</div>
                <div class="card-number"><?php echo $total_machines; ?></div>
                <div class="card-sub"><?php echo $active_machines; ?> Active</div>
                <div class="card-icon">🖨️</div>
            </a>
            
            <a href="r-view_clients.php" class="summary-card card-purple">
                <div class="card-title">Total Clients</div>
                <div class="card-number"><?php echo $total_clients; ?></div>
                <div class="card-sub"><?php echo $active_clients; ?> Active</div>
                <div class="card-icon">🏢</div>
            </a>
            
            <a href="r-expiring_contracts.php" class="summary-card card-yellow">
                <div class="card-title">Expiring Soon</div>
                <div class="card-number"><?php echo $expiring_soon; ?></div>
                <div class="card-sub">Next 60 days</div>
                <div class="card-icon">⏰</div>
            </a>
            
            <a href="r-expired_contracts.php" class="summary-card card-red">
                <div class="card-title">Expired</div>
                <div class="card-number"><?php echo $expired_contracts; ?></div>
                <div class="card-sub">Contracts expired</div>
                <div class="card-icon">⚠️</div>
            </a>
        </div>

        <!-- Alerts Section -->
        <div class="alerts-grid">
            <div class="alert-card">
                <div class="alert-icon alert-warning">⚠️</div>
                <div class="alert-content">
                    <h4>Misaligned Reading Dates</h4>
                    <p><?php echo $misaligned_machines; ?> Machines</p>
                </div>
            </div>
            <div class="alert-card">
                <div class="alert-icon alert-danger">❌</div>
                <div class="alert-content">
                    <h4>No Zone Assigned</h4>
                    <p><?php echo $no_zone_machines; ?> Machines</p>
                </div>
            </div>
            <div class="alert-card">
                <div class="alert-icon alert-warning">📅</div>
                <div class="alert-content">
                    <h4>Missing Collection Date</h4>
                    <p><?php echo $missing_collection; ?> Contracts</p>
                </div>
            </div>
            <div class="alert-card">
                <div class="alert-icon alert-info">🔄</div>
                <div class="alert-content">
                    <h4>Suspended Contracts</h4>
                    <p><?php echo $suspended_contracts; ?> Contracts</p>
                </div>
            </div>
            <div class="alert-card">
                <div class="alert-icon alert-danger">⏰</div>
                <div class="alert-content">
                    <h4>Ending Today</h4>
                    <p><?php echo $ending_today; ?> Contracts</p>
                </div>
            </div>
            <div class="alert-card">
                <div class="alert-icon alert-warning">🖨️</div>
                <div class="alert-content">
                    <h4>Contracts w/o Machines</h4>
                    <p><?php echo $contracts_no_machines; ?> Contracts</p>
                </div>
            </div>
        </div>

        <!-- Expiration Summary Cards -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px;">
            <div style="background: white; padding: 20px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-left: 4px solid #f39c12;">
                <div style="font-size: 14px; color: #7f8c8d;">Expiring This Month</div>
                <div style="font-size: 32px; font-weight: bold; color: #e67e22;"><?php echo $expiring_this_month; ?></div>
                <div style="font-size: 12px; color: #95a5a6;"><?php echo date('F Y'); ?></div>
            </div>
            <div style="background: white; padding: 20px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-left: 4px solid #3498db;">
                <div style="font-size: 14px; color: #7f8c8d;">Expiring Next Month</div>
                <div style="font-size: 32px; font-weight: bold; color: #2980b9;"><?php echo $expiring_next_month; ?></div>
                <div style="font-size: 12px; color: #95a5a6;"><?php echo date('F Y', strtotime('+1 month')); ?></div>
            </div>
            <div style="background: white; padding: 20px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-left: 4px solid #9b59b6;">
                <div style="font-size: 14px; color: #7f8c8d;">Ending This Year</div>
                <div style="font-size: 32px; font-weight: bold; color: #8e44ad;"><?php echo $ending_this_year; ?></div>
                <div style="font-size: 12px; color: #95a5a6;"><?php echo $current_year; ?></div>
            </div>
            <div style="background: white; padding: 20px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-left: 4px solid #27ae60;">
                <div style="font-size: 14px; color: #7f8c8d;">Avg. Contract Length</div>
                <div style="font-size: 32px; font-weight: bold; color: #229954;">
                    <?php 
                    $avg_length = $conn->query("
                        SELECT AVG(DATEDIFF(contract_end, contract_start)) as avg_days 
                        FROM rental_contracts 
                        WHERE contract_end IS NOT NULL AND contract_start IS NOT NULL
                    ")->fetch_assoc()['avg_days'];
                    echo $avg_length ? round($avg_length) . ' days' : '—';
                    ?>
                </div>
                <div style="font-size: 12px; color: #95a5a6;">Average contract duration</div>
            </div>
        </div>

        <!-- Charts Row 1 -->
        <div class="charts-grid">
            <!-- Contracts by Classification -->
            <div class="chart-card">
                <div class="chart-header">
                    <span class="chart-title">Contracts by Client Type</span>
                    <span style="color: #7f8c8d;">Current</span>
                </div>
                <div class="chart-container">
                    <canvas id="classificationChart"></canvas>
                </div>
            </div>
            
            <!-- Contracts by Status -->
            <div class="chart-card">
                <div class="chart-header">
                    <span class="chart-title">Contract Status Distribution</span>
                    <span style="color: #7f8c8d;">Active vs Inactive</span>
                </div>
                <div class="chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Charts Row 2 -->
        <div class="charts-grid">
            <!-- Machines by Type -->
            <div class="chart-card">
                <div class="chart-header">
                    <span class="chart-title">Machines by Type</span>
                    <span style="color: #7f8c8d;">Active Machines</span>
                </div>
                <div class="chart-container">
                    <canvas id="machineTypeChart"></canvas>
                </div>
            </div>
            
            <!-- Monthly Contracts -->
            <div class="chart-card">
                <div class="chart-header">
                    <span class="chart-title">Monthly Contracts (<?php echo $current_year; ?>)</span>
                    <span style="color: #7f8c8d;"><?php echo array_sum($monthly_data); ?> Total</span>
                </div>
                <div class="chart-container">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Two Column Layout for Tables -->
        <div class="two-column">
            <!-- Top Clients -->
            <div class="table-card">
                <div class="table-header">
                    <span class="table-title">🏆 Top Clients by Machine Count</span>
                    <a href="r-view_clients.php" class="view-all">View All →</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Type</th>
                            <th>Contracts</th>
                            <th>Machines</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($top_clients->num_rows > 0): ?>
                            <?php while($client = $top_clients->fetch_assoc()): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?php echo htmlspecialchars(substr($client['company_name'], 0, 25)) . (strlen($client['company_name']) > 25 ? '...' : ''); ?></td>
                                    <td>
                                        <span style="color: <?php echo $client['classification'] == 'GOVERNMENT' ? '#27ae60' : '#3498db'; ?>; font-weight: 600;">
                                            <?php echo $client['classification']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $client['contract_count']; ?></td>
                                    <td><strong><?php echo $client['machine_count']; ?></strong></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: #7f8c8d;">No active clients with machines</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Machine Distribution by Zone - REPLACED Departments -->
            <div class="table-card">
                <div class="table-header">
                    <span class="table-title">📍 Machine Distribution by Zone</span>
                    <a href="r-view_zones.php" class="view-all">View Map →</a>
                </div>
                <div style="max-height: 350px; overflow-y: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Zone</th>
                                <th>Area Center</th>
                                <th>Reading Day</th>
                                <th>Machines</th>
                                <th>Contracts</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($zone_table_stats->num_rows > 0): ?>
                                <?php while($zone = $zone_table_stats->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <span class="zone-badge">
                                                Zone <?php echo $zone['zone_number']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars(substr($zone['area_center'], 0, 25)); ?></td>
                                        <td><strong>Day <?php echo $zone['reading_date']; ?></strong></td>
                                        <td>
                                            <span style="font-weight: 600; color: #9b59b6;"><?php echo $zone['machine_count']; ?></span>
                                        </td>
                                        <td><?php echo $zone['contract_count']; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 30px;">
                                        <span style="font-size: 24px;">📍</span>
                                        <p style="color: #7f8c8d; margin-top: 10px;">No zone data available</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ecf0f1; text-align: center;">
                    <a href="r-view_zones.php" style="color: #3498db; text-decoration: none; font-weight: 600;">
                        View Detailed Zone Map →
                    </a>
                </div>
            </div>
        </div>

        <!-- Zone Distribution Chart - Moved here -->
        <div class="chart-card" style="margin-bottom: 30px;">
            <div class="chart-header">
                <span class="chart-title">📊 Zone Distribution Chart</span>
                <span style="color: #7f8c8d;">Active Machines by Zone</span>
            </div>
            <div class="chart-container" style="height: 300px;">
                <canvas id="zoneChart"></canvas>
            </div>
        </div>

        <!-- Recent Activities -->
        <div class="two-column" style="margin-top: 0;">
            <!-- Recent Contracts -->
            <div class="table-card">
                <div class="table-header">
                    <span class="table-title">📄 Recent Contracts</span>
                    <a href="r-view_contracts.php" class="view-all">View All →</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Contract #</th>
                            <th>Client</th>
                            <th>End Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($contract = $recent_contracts->fetch_assoc()): ?>
                            <tr>
                                <td style="font-family: monospace; font-weight: 600; color: #2980b9;">
                                    <?php echo htmlspecialchars($contract['contract_number']); ?>
                                </td>
                                <td><?php echo htmlspecialchars(substr($contract['company_name'], 0, 20)); ?></td>
                                <td>
                                    <?php if ($contract['contract_end']): ?>
                                        <?php 
                                        $end_date = new DateTime($contract['contract_end']);
                                        $today = new DateTime();
                                        $days_left = $today->diff($end_date)->days;
                                        ?>
                                        <span style="font-size: 12px;">
                                            <?php echo date('M d, Y', strtotime($contract['contract_end'])); ?>
                                            <?php if ($end_date > $today): ?>
                                                <br><small style="color: <?php echo $days_left <= 60 ? '#e67e22' : '#27ae60'; ?>;">
                                                    <?php echo $days_left; ?> days left
                                                    <?php if ($days_left <= 60): ?>
                                                        ⚠️ Expiring soon
                                                    <?php endif; ?>
                                                </small>
                                            <?php else: ?>
                                                <br><small style="color: #e74c3c;">Expired</small>
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #7f8c8d;">Not set</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo strtolower($contract['status']); ?>">
                                        <?php echo $contract['status']; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent Machines -->
            <div class="table-card">
                <div class="table-header">
                    <span class="table-title">🖨️ Recent Machines</span>
                    <a href="r-view_all_machines.php" class="view-all">View All →</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Machine #</th>
                            <th>Client</th>
                            <th>Department</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($machine = $recent_machines->fetch_assoc()): ?>
                            <tr>
                                <td style="font-weight: 600;"><?php echo htmlspecialchars($machine['machine_number']); ?></td>
                                <td><?php echo htmlspecialchars(substr($machine['company_name'], 0, 20)); ?></td>
                                <td>
                                    <?php if ($machine['department']): ?>
                                        <span style="background: #fff8e1; color: #856404; padding: 3px 10px; border-radius: 20px; font-size: 11px;">
                                            <?php echo htmlspecialchars(substr($machine['department'], 0, 15)); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #7f8c8d;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo strtolower($machine['status']); ?>">
                                        <?php echo $machine['status']; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Quick Stats Row -->
        <div class="three-column" style="margin-top: 20px;">
            <!-- Zone Quick Stats -->
            <div class="table-card">
                <div class="table-header">
                    <span class="table-title">📍 Zone Reading Dates</span>
                    <a href="r-view_zones.php" class="view-all">View Map →</a>
                </div>
                <div style="max-height: 250px; overflow-y: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Zone</th>
                                <th>Reading Day</th>
                                <th>Machines</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $zone_stats = $conn->query("
                                SELECT z.zone_number, z.area_center, z.reading_date,
                                       COUNT(cm.id) as machine_count
                                FROM rental_zoning_zone z
                                LEFT JOIN rental_contract_machines cm ON z.id = cm.zone_id AND cm.status = 'ACTIVE'
                                GROUP BY z.id
                                ORDER BY z.zone_number
                                LIMIT 6
                            ");
                            while($zone = $zone_stats->fetch_assoc()): 
                            ?>
                                <tr>
                                    <td><strong>Zone <?php echo $zone['zone_number']; ?></strong></td>
                                    <td>Day <?php echo $zone['reading_date']; ?></td>
                                    <td><?php echo $zone['machine_count']; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- System Health -->
            <div class="table-card">
                <div class="table-header">
                    <span class="table-title">⚡ System Health</span>
                </div>
                <div style="padding: 15px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                        <span style="color: #7f8c8d;">Contract Utilization:</span>
                        <span style="font-weight: 600; color: <?php echo ($active_contracts/$total_contracts*100) > 70 ? '#27ae60' : '#e67e22'; ?>;">
                            <?php echo $total_contracts > 0 ? round(($active_contracts/$total_contracts)*100, 1) : 0; ?>%
                        </span>
                    </div>
                    <div class="progress">
                        <div class="progress-bar" style="width: <?php echo $total_contracts > 0 ? round(($active_contracts/$total_contracts)*100, 1) : 0; ?>%;"></div>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; margin: 20px 0 15px;">
                        <span style="color: #7f8c8d;">Machine Utilization:</span>
                        <span style="font-weight: 600; color: <?php echo ($active_machines/$total_machines*100) > 70 ? '#27ae60' : '#e67e22'; ?>;">
                            <?php echo $total_machines > 0 ? round(($active_machines/$total_machines)*100, 1) : 0; ?>%
                        </span>
                    </div>
                    <div class="progress">
                        <div class="progress-bar" style="width: <?php echo $total_machines > 0 ? round(($active_machines/$total_machines)*100, 1) : 0; ?>%;"></div>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; margin: 20px 0 15px;">
                        <span style="color: #7f8c8d;">Alignment Rate:</span>
                        <span style="font-weight: 600; color: <?php echo ($total_machines - $misaligned_machines)/$total_machines*100 > 80 ? '#27ae60' : '#e67e22'; ?>;">
                            <?php echo $total_machines > 0 ? round((($total_machines - $misaligned_machines)/$total_machines)*100, 1) : 0; ?>%
                        </span>
                    </div>
                    <div class="progress">
                        <div class="progress-bar" style="width: <?php echo $total_machines > 0 ? round((($total_machines - $misaligned_machines)/$total_machines)*100, 1) : 0; ?>%;"></div>
                    </div>
                    
                    <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                        <div style="display: flex; justify-content: space-between;">
                            <span style="font-weight: 600;">Contracts ending <?php echo $current_year; ?>:</span>
                            <span style="font-weight: bold; color: #e67e22;"><?php echo $ending_this_year; ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 10px;">
                            <span style="font-weight: 600;">Expiring in 60 days:</span>
                            <span style="font-weight: bold; color: #e67e22;"><?php echo $expiring_soon; ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="table-card">
                <div class="table-header">
                    <span class="table-title">🔗 Quick Links</span>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding: 10px;">
                    <a href="r-add_contracts.php" style="background: #e3f2fd; color: #1976d2; padding: 15px; text-align: center; text-decoration: none; border-radius: 8px; font-weight: 600; transition: transform 0.3s;">
                        ➕ New Contract
                    </a>
                    <a href="r-view_contracts.php" style="background: #e8f5e9; color: #2e7d32; padding: 15px; text-align: center; text-decoration: none; border-radius: 8px; font-weight: 600; transition: transform 0.3s;">
                        📋 All Contracts
                    </a>
                    <a href="r-calendar.php" style="background: #fff3cd; color: #856404; padding: 15px; text-align: center; text-decoration: none; border-radius: 8px; font-weight: 600; transition: transform 0.3s;">
                        📅 Calendar
                    </a>
                    <a href="r-expiring_contracts.php" style="background: #fff3cd; color: #856404; padding: 15px; text-align: center; text-decoration: none; border-radius: 8px; font-weight: 600; transition: transform 0.3s;">
                        ⏰ Expiring Soon
                    </a>
                    <a href="r-expired_contracts.php" style="background: #f8d7da; color: #721c24; padding: 15px; text-align: center; text-decoration: none; border-radius: 8px; font-weight: 600; transition: transform 0.3s;">
                        ⚠️ Expired
                    </a>
                    <a href="r-view_zones.php" style="background: #fff3e0; color: #e65100; padding: 15px; text-align: center; text-decoration: none; border-radius: 8px; font-weight: 600; transition: transform 0.3s;">
                        🗺️ Zone Map
                    </a>
                    <a href="r-view_all_machines.php" style="background: #f3e5f5; color: #6a1b9a; padding: 15px; text-align: center; text-decoration: none; border-radius: 8px; font-weight: 600; transition: transform 0.3s;">
                        🖨️ All Machines
                    </a>
                    <a href="r-view_clients.php" style="background: #ffebee; color: #c62828; padding: 15px; text-align: center; text-decoration: none; border-radius: 8px; font-weight: 600; transition: transform 0.3s;">
                        🏢 Clients
                    </a>
                </div>
                
                <!-- Today's Summary -->
                <div style="margin-top: 20px; padding: 15px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px; color: white;">
                    <h4 style="margin-bottom: 10px; font-size: 14px;">📅 Today's Summary</h4>
                    <div style="display: flex; justify-content: space-between;">
                        <div>
                            <span style="font-size: 12px; opacity: 0.9;">Contracts ending:</span>
                            <span style="font-size: 18px; font-weight: bold; display: block;"><?php echo $ending_today; ?></span>
                        </div>
                        <div>
                            <span style="font-size: 12px; opacity: 0.9;">Active contracts:</span>
                            <span style="font-size: 18px; font-weight: bold; display: block;"><?php echo $active_contracts; ?></span>
                        </div>
                        <div>
                            <span style="font-size: 12px; opacity: 0.9;">Active machines:</span>
                            <span style="font-size: 18px; font-weight: bold; display: block;"><?php echo $active_machines; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    

    <script>
        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // 1. Classification Chart
            new Chart(document.getElementById('classificationChart'), {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($classification_labels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($classification_data); ?>,
                        backgroundColor: ['#27ae60', '#3498db'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { font: { size: 12 } }
                        }
                    }
                }
            });

            // 2. Status Chart
            new Chart(document.getElementById('statusChart'), {
                type: 'pie',
                data: {
                    labels: <?php echo json_encode($status_labels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($status_data); ?>,
                        backgroundColor: <?php echo json_encode($status_colors); ?>,
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { font: { size: 12 } }
                        }
                    }
                }
            });

            // 3. Machine Type Chart
            new Chart(document.getElementById('machineTypeChart'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($machine_type_labels); ?>,
                    datasets: [{
                        label: 'Number of Machines',
                        data: <?php echo json_encode($machine_type_data); ?>,
                        backgroundColor: ['#7f8c8d', '#e67e22'],
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: '#ecf0f1' }
                        }
                    }
                }
            });

            // 4. Monthly Contracts Chart
            new Chart(document.getElementById('monthlyChart'), {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($months); ?>,
                    datasets: [{
                        label: 'Contracts',
                        data: <?php echo json_encode(array_values($monthly_data)); ?>,
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        borderWidth: 3,
                        pointBackgroundColor: '#2980b9',
                        pointBorderColor: 'white',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: '#ecf0f1' }
                        }
                    }
                }
            });

            // 5. Zone Distribution Chart
            new Chart(document.getElementById('zoneChart'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($zone_labels); ?>,
                    datasets: [{
                        label: 'Active Machines',
                        data: <?php echo json_encode($zone_data); ?>,
                        backgroundColor: '#9b59b6',
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: '#ecf0f1' }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>