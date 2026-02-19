<?php
require_once 'config.php';
session_start();

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$classification = isset($_GET['classification']) ? $_GET['classification'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query with filters
$query = "SELECT c.*, cl.company_name, cl.classification as client_classification, 
                 (SELECT COUNT(*) FROM rental_contract_machines WHERE contract_id = c.id) as machine_count
          FROM rental_contracts c
          JOIN rental_clients cl ON c.client_id = cl.id
          WHERE 1=1";

$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (c.contract_number LIKE ? OR cl.company_name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if (!empty($status)) {
    $query .= " AND c.status = ?";
    $params[] = $status;
    $types .= "s";
}

if (!empty($classification)) {
    $query .= " AND cl.classification = ?";
    $params[] = $classification;
    $types .= "s";
}

if (!empty($date_from)) {
    $query .= " AND DATE(c.datecreated) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $query .= " AND DATE(c.datecreated) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$query .= " ORDER BY c.datecreated DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$contracts = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Contracts - CPI Rental Management</title>
    <!-- PDF.js Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f9; padding: 20px; }
        .container { max-width: auto; margin: 0 auto; }
        
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
        h1 { color: #2c3e50; margin-bottom: 10px; display: flex; align-items: center; gap: 10px; }
        
        .btn {
            display: inline-block; padding: 10px 20px; background: #3498db; color: white;
            text-decoration: none; border-radius: 5px; transition: background 0.3s;
            border: none; cursor: pointer; font-size: 14px; font-weight: 600;
        }
        .btn:hover { background: #2980b9; }
        .btn-success { background: #27ae60; }
        .btn-success:hover { background: #229954; }
        .btn-warning { background: #f39c12; }
        .btn-warning:hover { background: #e67e22; }
        .btn-danger { background: #e74c3c; }
        .btn-danger:hover { background: #c0392b; }
        .btn-info { background: #00bcd4; }
        .btn-info:hover { background: #00a5bb; }
        .btn-purple { background: #9b59b6; }
        .btn-purple:hover { background: #8e44ad; }
        
        .filter-section {
            background: white; padding: 20px; border-radius: 15px; margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .filter-form {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;
            align-items: flex-end;
        }
        
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-weight: 600; color: #34495e; margin-bottom: 5px; font-size: 13px; }
        .filter-group input, .filter-group select {
            padding: 10px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px;
        }
        
        .table-responsive { overflow-x: auto; }
        table {
            width: 100%; background: white; border-radius: 15px; overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-collapse: collapse;
        }
        th {
            background: #34495e; color: white; padding: 15px; text-align: left; font-weight: 600;
        }
        td {
            padding: 15px; border-bottom: 1px solid #ecf0f1; vertical-align: middle;
        }
        tr:hover td { background: #f8f9fa; }
        
        .status-badge {
            display: inline-block; padding: 5px 12px; border-radius: 20px;
            font-size: 12px; font-weight: 600; text-transform: uppercase;
        }
        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        .status-suspended { background: #fff3cd; color: #856404; }
        
        .contract-number {
            font-family: monospace; font-weight: 600; color: #2980b9;
        }
        
        .action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
        .action-btn {
            padding: 6px 12px; border-radius: 5px; text-decoration: none;
            color: white; font-size: 12px; font-weight: 600; transition: all 0.3s;
            display: inline-flex; align-items: center; gap: 5px;
        }
        
        .summary-cards {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px; margin-bottom: 25px;
        }
        .summary-card {
            background: white; padding: 20px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 5px solid;
        }
        .card-blue { border-left-color: #3498db; }
        .card-green { border-left-color: #27ae60; }
        .card-purple { border-left-color: #9b59b6; }
        .card-orange { border-left-color: #e67e22; }
        
        /* PDF Modal Styles */
        .pdf-modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.9);
            overflow: hidden;
        }
        
        .pdf-modal-content {
            position: relative;
            width: 100%;
            height: 100%;
            padding: 20px;
        }
        
        .pdf-modal-header {
            position: absolute;
            top: 20px;
            left: 20px;
            right: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            z-index: 10000;
            padding: 15px 25px;
            background: rgba(0,0,0,0.7);
            border-radius: 10px;
            backdrop-filter: blur(5px);
        }
        
        .pdf-modal-title {
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .pdf-modal-close {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.3s;
        }
        
        .pdf-modal-close:hover {
            background: #c0392b;
        }
        
        #pdfViewer {
            width: 100%;
            height: 100%;
            border: none;
            background: white;
        }
        
        .pdf-thumbnail {
            width: 40px;
            height: 40px;
            background: #e74c3c;
            color: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        
        .contract-file-info {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8f9fa;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
        }
        
        .file-count {
            background: #3498db;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .pagination {
            margin-top: 20px; display: flex; justify-content: center; gap: 10px;
        }
        .pagination a {
            padding: 8px 12px; background: white; border: 1px solid #ddd;
            text-decoration: none; color: #3498db; border-radius: 4px;
        }
        .pagination a.active { background: #3498db; color: white; border-color: #3498db; }
        
        .contract-period {
            font-size: 12px;
            line-height: 1.5;
        }
        
        .expiring-soon {
            color: #e67e22;
            font-weight: 600;
        }
        
        .expired {
            color: #e74c3c;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .filter-form { grid-template-columns: 1fr; }
            .action-buttons { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div style="display: flex; align-items: center; gap: 20px;">
                <h1>
                    📋 Contracts Management
                    <span style="font-size: 16px; background: #e3f2fd; padding: 5px 15px; border-radius: 25px; color: #1976d2;">
                        <?php 
                        $total = $conn->query("SELECT COUNT(*) as count FROM rental_contracts")->fetch_assoc()['count'];
                        echo $total . ' Total';
                        ?>
                    </span>
                </h1>
            </div>
            <div>
                <a href="r-add_contracts.php" class="btn btn-success">➕ New Contract</a>
                <a href="r-dashboard.php" class="btn btn-info">📊 Dashboard</a>
                <a href="r-calendar.php" class="btn btn-purple">📅 Calendar</a>
            </div>
        </div>

        <!-- Summary Cards -->
        <?php
        $active_contracts = $conn->query("SELECT COUNT(*) as count FROM rental_contracts WHERE status = 'ACTIVE'")->fetch_assoc()['count'];
        $umbrella_contracts = $conn->query("SELECT COUNT(*) as count FROM rental_contracts WHERE type_of_contract = 'UMBRELLA'")->fetch_assoc()['count'];
        $single_contracts = $conn->query("SELECT COUNT(*) as count FROM rental_contracts WHERE type_of_contract = 'SINGLE CONTRACT'")->fetch_assoc()['count'];
        $government_contracts = $conn->query("
            SELECT COUNT(*) as count FROM rental_contracts c 
            JOIN rental_clients cl ON c.client_id = cl.id 
            WHERE cl.classification = 'GOVERNMENT'
        ")->fetch_assoc()['count'];
        ?>
        <div class="summary-cards">
            <div class="summary-card card-green">
                <div style="font-size: 14px; color: #7f8c8d;">Active Contracts</div>
                <div style="font-size: 32px; font-weight: bold; color: #27ae60;"><?php echo $active_contracts; ?></div>
                <div style="font-size: 12px; color: #95a5a6;">Currently active</div>
            </div>
            <div class="summary-card card-blue">
                <div style="font-size: 14px; color: #7f8c8d;">Umbrella</div>
                <div style="font-size: 32px; font-weight: bold; color: #3498db;"><?php echo $umbrella_contracts; ?></div>
                <div style="font-size: 12px; color: #95a5a6;">Multi-machine contracts</div>
            </div>
            <div class="summary-card card-orange">
                <div style="font-size: 14px; color: #7f8c8d;">Single Contract</div>
                <div style="font-size: 32px; font-weight: bold; color: #e67e22;"><?php echo $single_contracts; ?></div>
                <div style="font-size: 12px; color: #95a5a6;">Single machine contracts</div>
            </div>
            <div class="summary-card card-purple">
                <div style="font-size: 14px; color: #7f8c8d;">Government</div>
                <div style="font-size: 32px; font-weight: bold; color: #9b59b6;"><?php echo $government_contracts; ?></div>
                <div style="font-size: 12px; color: #95a5a6;">Public sector</div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <label>Search</label>
                    <input type="text" name="search" placeholder="Contract # or Company" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Status</option>
                        <option value="ACTIVE" <?php echo $status == 'ACTIVE' ? 'selected' : ''; ?>>ACTIVE</option>
                        <option value="INACTIVE" <?php echo $status == 'INACTIVE' ? 'selected' : ''; ?>>INACTIVE</option>
                        <option value="SUSPENDED" <?php echo $status == 'SUSPENDED' ? 'selected' : ''; ?>>SUSPENDED</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Client Type</label>
                    <select name="classification">
                        <option value="">All Types</option>
                        <option value="GOVERNMENT" <?php echo $classification == 'GOVERNMENT' ? 'selected' : ''; ?>>GOVERNMENT</option>
                        <option value="PRIVATE" <?php echo $classification == 'PRIVATE' ? 'selected' : ''; ?>>PRIVATE</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Date From</label>
                    <input type="date" name="date_from" value="<?php echo $date_from; ?>">
                </div>
                <div class="filter-group">
                    <label>Date To</label>
                    <input type="date" name="date_to" value="<?php echo $date_to; ?>">
                </div>
                <div class="filter-group" style="display: flex; flex-direction: row; gap: 10px; align-items: center;">
                    <button type="submit" class="btn">Apply Filters</button>
                    <a href="r-view_contracts.php" class="btn" style="background: #95a5a6;">Clear</a>
                </div>
            </form>
        </div>

        <!-- Contracts Table -->
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Contract #</th>
                        <th>Client</th>
                        <th>Contract Period</th>
                        <th>Type</th>
                        <th>Contract Type</th>
                        <th>Machines</th>
                        <th>Rates</th>
                        <th>Min. Charge</th> 
                        <th>Collection</th>
                        <th>Contract File</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($contracts->num_rows > 0): ?>
                        <?php while($contract = $contracts->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <span class="contract-number"><?php echo htmlspecialchars($contract['contract_number']); ?></span>
                                    <div style="font-size: 11px; color: #7f8c8d;">
                                        Created: <?php echo date('M d, Y', strtotime($contract['datecreated'])); ?>
                                    </div>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($contract['company_name']); ?></strong><br>
                                    <span style="color: <?php echo $contract['client_classification'] == 'GOVERNMENT' ? '#27ae60' : '#3498db'; ?>; font-size: 11px; font-weight: 600;">
                                        <?php echo $contract['client_classification']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($contract['contract_start'] && $contract['contract_end']): ?>
                                        <div class="contract-period">
                                            <span style="font-size: 11px; display: block;">
                                                <strong>Start:</strong> <?php echo date('M d, Y', strtotime($contract['contract_start'])); ?>
                                            </span>
                                            <span style="font-size: 11px; display: block;">
                                                <strong>End:</strong> <?php echo date('M d, Y', strtotime($contract['contract_end'])); ?>
                                            </span>
                                            <?php 
                                            $today = new DateTime();
                                            $end = new DateTime($contract['contract_end']);
                                            if ($today < $end):
                                                $days_left = $today->diff($end)->days;
                                                echo '<span style="font-size: 11px; ' . ($days_left <= 60 ? 'color: #e67e22; font-weight: 600;' : 'color: #27ae60;') . '">';
                                                echo $days_left . ' days left';
                                                if ($days_left <= 60) echo ' ⚠️';
                                                echo '</span>';
                                            else:
                                                echo '<span style="font-size: 11px; color: #e74c3c; font-weight: 600;">Expired</span>';
                                            endif;
                                            ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #7f8c8d; font-size: 11px;">Not set</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-weight: 600; color: <?php echo $contract['type_of_contract'] == 'UMBRELLA' ? '#27ae60' : '#e67e22'; ?>;">
                                        <?php echo $contract['type_of_contract']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($contract['has_colored_machines'] == 'YES'): ?>
                                        <span style="color: #e67e22; font-weight: 600;">🎨 Colored</span>
                                    <?php else: ?>
                                        <span style="color: #7f8c8d; font-weight: 600;">⚫ Mono Only</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <a href="r-view_machines.php?contract_id=<?php echo $contract['id']; ?>" 
                                       style="text-decoration: none; display: inline-block;">
                                        <span style="color: black; padding: 5px 12px;  font-weight: 600; font-size: 12px;">
                                            <?php echo $contract['machine_count']; ?> Machine/s
                                        </span>
                                    </a>
                                </td>
                                <td>
                                    <div style="font-size: 12px;">
                                        <span style="font-weight: 600;">Mono:</span> ₱<?php echo number_format($contract['mono_rate'], 2); ?><br>
                                        <?php if ($contract['has_colored_machines'] == 'YES'): ?>
                                            <span style="font-weight: 600;">Color:</span> ₱<?php echo number_format($contract['color_rate'], 2); ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                
                                <td>
                                    <?php if (!empty($contract['minimum_monthly_charge']) && $contract['minimum_monthly_charge'] > 0): ?>
                                        <div style="background: #fff8e1; padding: 5px 10px; border-radius: 20px; text-align: center;">
                                            <span style="font-weight: 600; color: #e67e22;">₱<?php echo number_format($contract['minimum_monthly_charge'], 2); ?></span>
                                            <span style="display: block; font-size: 10px; color: #856404;">Minimum Monthly</span>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #7f8c8d; font-size: 12px;">Not set</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size: 12px;">
                                        <span style="font-weight: 600;">Period:</span> <?php echo $contract['collection_processing_period']; ?> days<br>
                                        <?php if ($contract['collection_date']): ?>
                                            <span style="font-weight: 600;">Day:</span> <?php echo $contract['collection_date']; ?>
                                        <?php else: ?>
                                            <span style="color: #7f8c8d;">TBD</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($contract['contract_file'])): 
                                        $files = explode(',', $contract['contract_file']);
                                        $file_count = count($files);
                                    ?>
                                        <div style="display: flex; flex-direction: column; gap: 5px;">
                                            <div class="contract-file-info">
                                                <span class="pdf-thumbnail">📄</span>
                                                <div>
                                                    <strong style="font-size: 12px;"><?php echo $file_count; ?> file(s)</strong>
                                                    <span class="file-count">PDF</span>
                                                </div>
                                            </div>
                                            <div style="display: flex; gap: 5px;">
                                                <?php if ($file_count == 1): ?>
                                                    <button onclick="openPDF('<?php echo $files[0]; ?>', '<?php echo htmlspecialchars($contract['contract_number']); ?>')" 
                                                            class="action-btn" style="background: #e74c3c; padding: 4px 10px; font-size: 11px;">
                                                        📄 View PDF
                                                    </button>
                                                <?php else: ?>
                                                    <button onclick="showFileList(<?php echo htmlspecialchars(json_encode($files)); ?>, '<?php echo htmlspecialchars($contract['contract_number']); ?>')" 
                                                            class="action-btn" style="background: #9b59b6; padding: 4px 10px; font-size: 11px;">
                                                        📑 View Files (<?php echo $file_count; ?>)
                                                    </button>
                                                <?php endif; ?>
                                                <a href="r-upload_contract_file.php?contract_id=<?php echo $contract['id']; ?>" 
                                                class="action-btn" style="background: #27ae60; padding: 4px 10px; font-size: 11px;">
                                                    ➕ Add
                                                </a>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div style="display: flex; flex-direction: column; align-items: center; gap: 5px;">
                                            <span style="color: #7f8c8d; font-size: 11px; margin-bottom: 5px;">No file</span>
                                            <a href="r-upload_contract_file.php?contract_id=<?php echo $contract['id']; ?>" 
                                            class="action-btn" style="background: #27ae60; padding: 6px 15px; font-size: 12px; text-decoration: none;">
                                                ➕ Add Contract File
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($contract['status']); ?>">
                                        <?php echo $contract['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="r-edit_contract.php?id=<?php echo $contract['id']; ?>" class="action-btn" style="background: #f39c12;">
                                            ✏️ Edit
                                        </a>
                                        <a href="r-view_machines.php?contract_id=<?php echo $contract['id']; ?>" class="action-btn" style="background: #3498db;">
                                            🖨️ Machines
                                        </a>
                                        <?php if ($contract['status'] == 'ACTIVE'): ?>
                                            <a href="#" onclick="updateStatus(<?php echo $contract['id']; ?>, 'INACTIVE')" class="action-btn" style="background: #95a5a6;">
                                                ⏸️ Deactivate
                                            </a>
                                        <?php else: ?>
                                            <a href="#" onclick="updateStatus(<?php echo $contract['id']; ?>, 'ACTIVE')" class="action-btn" style="background: #27ae60;">
                                                ▶️ Activate
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" style="text-align: center; padding: 60px;">
                                <span style="font-size: 48px;">📄</span>
                                <h3 style="color: #2c3e50; margin-top: 20px; margin-bottom: 10px;">No Contracts Found</h3>
                                <p style="color: #7f8c8d; margin-bottom: 20px;">Try adjusting your filters or create a new contract.</p>
                                <a href="r-add_contracts.php" class="btn btn-success">➕ New Contract</a>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- PDF Viewer Modal -->
    <div id="pdfModal" class="pdf-modal">
        <div class="pdf-modal-content">
            <div class="pdf-modal-header">
                <div class="pdf-modal-title">
                    <span style="font-size: 24px;">📄</span>
                    <span id="pdfModalTitle">Contract Document</span>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button id="downloadPdfBtn" class="pdf-modal-close" style="background: #27ae60;">
                        ⬇️ Download
                    </button>
                    <button onclick="closePDFModal()" class="pdf-modal-close">
                        ✕ Close
                    </button>
                </div>
            </div>
            <iframe id="pdfViewer" style="width: 100%; height: 100%; border: none;"></iframe>
        </div>
    </div>

    <!-- File List Modal for Multiple PDFs -->
    <div id="fileListModal" class="pdf-modal" style="background: rgba(0,0,0,0.8);">
        <div class="pdf-modal-content" style="display: flex; justify-content: center; align-items: center;">
            <div style="background: white; border-radius: 15px; width: 90%; max-width: 600px; max-height: 80%; overflow-y: auto; padding: 30px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="color: #2c3e50; display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 24px;">📑</span>
                        <span id="fileListTitle">Contract Files</span>
                    </h3>
                    <button onclick="closeFileListModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #7f8c8d;">✕</button>
                </div>
                <div id="fileListContainer" style="display: flex; flex-direction: column; gap: 10px;">
                    <!-- File list will be populated here -->
                </div>
                <div style="margin-top: 20px; text-align: right;">
                    <button onclick="closeFileListModal()" class="btn" style="background: #95a5a6;">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize PDF.js
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

        // Open PDF in modal
        function openPDF(filePath, contractNumber) {
            const modal = document.getElementById('pdfModal');
            const viewer = document.getElementById('pdfViewer');
            const title = document.getElementById('pdfModalTitle');
            const downloadBtn = document.getElementById('downloadPdfBtn');
            
            // Check if file exists
            fetch(filePath, { method: 'HEAD' })
                .then(response => {
                    if (response.ok) {
                        // Set iframe source to PDF
                        viewer.src = filePath;
                        title.textContent = `Contract: ${contractNumber}`;
                        downloadBtn.onclick = function() {
                            window.open(filePath, '_blank');
                        };
                        modal.style.display = 'block';
                        document.body.style.overflow = 'hidden';
                    } else {
                        alert('PDF file not found. It may have been moved or deleted.');
                    }
                })
                .catch(error => {
                    console.error('Error loading PDF:', error);
                    alert('Error loading PDF file. Please try again.');
                });
        }

        // Close PDF modal
        function closePDFModal() {
            const modal = document.getElementById('pdfModal');
            const viewer = document.getElementById('pdfViewer');
            modal.style.display = 'none';
            viewer.src = '';
            document.body.style.overflow = 'auto';
        }

        // Show file list for multiple PDFs
        function showFileList(files, contractNumber) {
            const modal = document.getElementById('fileListModal');
            const title = document.getElementById('fileListTitle');
            const container = document.getElementById('fileListContainer');
            
            title.textContent = `Contract: ${contractNumber}`;
            container.innerHTML = '';
            
            files.forEach((file, index) => {
                const fileName = file.split('/').pop() || `Contract File ${index + 1}`;
                const fileItem = document.createElement('div');
                fileItem.style.cssText = `
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 15px;
                    background: #f8f9fa;
                    border-radius: 8px;
                    border-left: 4px solid #3498db;
                `;
                fileItem.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 20px;">📄</span>
                        <div>
                            <div style="font-weight: 600; color: #2c3e50;">${fileName}</div>
                            <div style="font-size: 11px; color: #7f8c8d;">Contract Document ${index + 1}</div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button onclick="openPDF('${file}', '${contractNumber}')" 
                                style="background: #3498db; color: white; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer; font-size: 12px;">
                            👁️ View
                        </button>
                        <a href="${file}" download 
                           style="background: #27ae60; color: white; text-decoration: none; padding: 8px 15px; border-radius: 5px; font-size: 12px;">
                            ⬇️ Download
                        </a>
                    </div>
                `;
                container.appendChild(fileItem);
            });
            
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        // Close file list modal
        function closeFileListModal() {
            const modal = document.getElementById('fileListModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePDFModal();
                closeFileListModal();
            }
        });

        // Update contract status
        function updateStatus(contractId, newStatus) {
            if (confirm(`Are you sure you want to ${newStatus === 'ACTIVE' ? 'activate' : 'deactivate'} this contract?`)) {
                fetch('r-update_contract_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: contractId, status: newStatus })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Failed to update status: ' + data.error);
                    }
                })
                .catch(error => {
                    alert('Error: ' + error.message);
                });
            }
        }

        // Handle window resize
        window.addEventListener('resize', function() {
            const modal = document.getElementById('pdfModal');
            if (modal.style.display === 'block') {
                const viewer = document.getElementById('pdfViewer');
                // Refresh iframe on resize
                const currentSrc = viewer.src;
                viewer.src = '';
                setTimeout(() => { viewer.src = currentSrc; }, 50);
            }
        });
    </script>
</body>
</html>