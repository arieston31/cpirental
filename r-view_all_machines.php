<?php
require_once 'config.php';

$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$zone = isset($_GET['zone']) ? intval($_GET['zone']) : 0;

$query = "SELECT cm.*, c.contract_number, cl.company_name, cl.classification 
          FROM rental_contract_machines cm
          JOIN rental_contracts c ON cm.contract_id = c.id
          JOIN rental_clients cl ON c.client_id = cl.id
          WHERE 1=1";

$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (cm.machine_number LIKE ? OR cm.machine_serial_number LIKE ? OR cl.company_name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if (!empty($status)) {
    $query .= " AND cm.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($zone > 0) {
    $query .= " AND cm.zone_number = ?";
    $params[] = $zone;
    $types .= "i";
}

$query .= " ORDER BY cm.datecreated DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$machines = $stmt->get_result();

// Get zones for filter
$zones = $conn->query("SELECT * FROM rental_zoning_zone ORDER BY zone_number");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Machines - CPI Rental</title>
    <style>
        /* Main Styles */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f4f6f9; 
            padding: 20px; 
        }
        .container { 
            max-width: 1400px; 
            margin: 0 auto; 
        }
        
        /* Header */
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
        .header h1 { 
            color: #2c3e50; 
            font-size: 28px; 
            display: flex; 
            align-items: center; 
            gap: 10px; 
        }
        
        /* Filter Section */
        .filter-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
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
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        .filter-group input:focus,
        .filter-group select:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 3px rgba(52,152,219,0.1);
        }
        
        /* Buttons */
        .btn {
            display: inline-block;
            padding: 12px 25px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52,152,219,0.3);
        }
        .btn-success { background: #27ae60; }
        .btn-success:hover { background: #229954; }
        .btn-warning { background: #f39c12; }
        .btn-warning:hover { background: #e67e22; }
        .btn-secondary { background: #95a5a6; }
        .btn-secondary:hover { background: #7f8c8d; }
        
        /* Machine Grid */
        .machine-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); 
            gap: 25px; 
            margin-top: 20px; 
        }
        
        /* Machine Card */
        .machine-card { 
            background: white; 
            border-radius: 15px; 
            overflow: hidden; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
            transition: all 0.3s ease;
            border: 1px solid #ecf0f1;
        }
        .machine-card:hover { 
            transform: translateY(-5px); 
            box-shadow: 0 10px 25px rgba(0,0,0,0.15); 
            border-color: #3498db;
        }
        
        /* Machine Header */
        .machine-header { 
            background: linear-gradient(135deg, #34495e, #2c3e50); 
            color: white; 
            padding: 18px 20px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
        }
        .machine-header strong { 
            font-size: 16px; 
        }
        .machine-type {
            background: rgba(255,255,255,0.2);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        /* Machine Body */
        .machine-body { 
            padding: 20px; 
        }
        .machine-body p { 
            margin: 10px 0; 
            color: #2c3e50; 
            display: flex;
            align-items: baseline;
        }
        .machine-body strong { 
            min-width: 90px; 
            color: #7f8c8d; 
            font-size: 13px;
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        
        /* Zone Badge */
        .zone-badge {
            display: inline-block;
            padding: 4px 12px;
            background: #e3f2fd;
            color: #1976d2;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        /* Department Badge */
        .dept-badge {
            background: #fff8e1;
            color: #856404;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        /* DR/POS Badge */
        .drpos-badge {
            background: #e67e22;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            margin-right: 8px;
        }
        
        /* DR/POS Button */
        .drpos-btn {
            background: #e67e22;
            color: white;
            border: none;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-left: 8px;
        }
        .drpos-btn:hover {
            background: #d35400;
            transform: translateY(-2px);
        }
        
        /* Action Link */
        .action-link {
            display: inline-block;
            margin-top: 15px;
            padding: 8px 20px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .action-link:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52,152,219,0.3);
        }
        
        /* PDF Modal Styles - CRITICAL */
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
            padding: 8px 20px;
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
        
        #drposViewer {
            width: 100%;
            height: 100%;
            border: none;
            background: white;
        }
        
        /* No Results */
        .no-results {
            grid-column: 1/-1;
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .no-results h3 {
            color: #2c3e50;
            margin-bottom: 15px;
        }
        .no-results p {
            color: #7f8c8d;
            margin-bottom: 20px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .filter-form {
                flex-direction: column;
            }
            .filter-group {
                width: 100%;
            }
            .machine-grid {
                grid-template-columns: 1fr;
            }
            .pdf-modal-header {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            .machine-body p {
                flex-direction: column;
            }
            .machine-body strong {
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>
                🖨️ All Machines
                <span style="font-size: 16px; background: #e3f2fd; padding: 5px 15px; border-radius: 25px; color: #1976d2;">
                    <?php 
                    $total = $conn->query("SELECT COUNT(*) as count FROM rental_contract_machines WHERE status = 'ACTIVE'")->fetch_assoc()['count'];
                    echo $total . ' Active';
                    ?>
                </span>
            </h1>
            <div>
                <a href="r-dashboard.php" class="btn btn-secondary">← Dashboard</a>
                <a href="r-view_contracts.php" class="btn">📋 Contracts</a>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <label>🔍 Search</label>
                    <input type="text" name="search" placeholder="Machine #, Serial #, or Client..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-group">
                    <label>📊 Status</label>
                    <select name="status">
                        <option value="">All Status</option>
                        <option value="ACTIVE" <?php echo $status == 'ACTIVE' ? 'selected' : ''; ?>>ACTIVE</option>
                        <option value="INACTIVE" <?php echo $status == 'INACTIVE' ? 'selected' : ''; ?>>INACTIVE</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>📍 Zone</label>
                    <select name="zone">
                        <option value="">All Zones</option>
                        <?php 
                        $zones->data_seek(0); // Reset pointer
                        while($z = $zones->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $z['zone_number']; ?>" <?php echo $zone == $z['zone_number'] ? 'selected' : ''; ?>>
                                Zone <?php echo $z['zone_number']; ?> - <?php echo htmlspecialchars($z['area_center']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-group" style="display: flex; gap: 10px; align-items: center;">
                    <button type="submit" class="btn">Apply Filters</button>
                    <a href="r-view_all_machines.php" class="btn btn-secondary">Clear</a>
                </div>
            </form>
        </div>
        
        <!-- Machines Grid -->
        <div class="machine-grid">
            <?php if ($machines->num_rows > 0): ?>
                <?php while($machine = $machines->fetch_assoc()): ?>
                    <div class="machine-card">
                        <div class="machine-header">
                            <div>
                                <strong>Machine #<?php echo htmlspecialchars($machine['machine_number']); ?></strong>
                            </div>
                            <div>
                                <span class="machine-type">
                                    <?php echo $machine['machine_type']; ?>
                                </span>
                                <span class="status-badge status-<?php echo strtolower($machine['status']); ?>" style="margin-left: 8px;">
                                    <?php echo $machine['status']; ?>
                                </span>
                            </div>
                        </div>
                        <div class="machine-body">
                            <p>
                                <strong>🏢 Client:</strong> 
                                <span style="font-weight: 600; color: <?php echo $machine['classification'] == 'GOVERNMENT' ? '#27ae60' : '#3498db'; ?>;">
                                    <?php echo htmlspecialchars($machine['company_name']); ?>
                                </span>
                            </p>
                            
                            <p>
                                <strong>📋 Contract:</strong> 
                                <span style="font-family: monospace; color: #2980b9;">
                                    <?php echo htmlspecialchars($machine['contract_number']); ?>
                                </span>
                            </p>
                            
                            <?php if ($machine['department']): ?>
                                <p>
                                    <strong>🏢 Dept:</strong>
                                    <span class="dept-badge">
                                        <?php echo htmlspecialchars($machine['department']); ?>
                                    </span>
                                </p>
                            <?php endif; ?>
                            
                            <p>
                                <strong>🔢 Serial:</strong> 
                                <?php echo htmlspecialchars($machine['machine_serial_number']); ?>
                            </p>
                            
                            <p>
                                <strong>📍 Location:</strong> 
                                <?php echo htmlspecialchars($machine['barangay'] . ', ' . $machine['city']); ?>
                            </p>
                            
                            <p>
                                <strong>📍 Zone:</strong>
                                <span class="zone-badge">
                                    Zone <?php echo $machine['zone_number']; ?> - <?php echo htmlspecialchars($machine['area_center']); ?>
                                </span>
                            </p>
                            
                            <p>
                                <strong>📅 Reading:</strong> 
                                Day <?php echo $machine['reading_date']; ?>
                                <?php if ($machine['reading_date_remarks']): ?>
                                    <span style="margin-left: 8px; padding: 2px 8px; border-radius: 12px; font-size: 10px; 
                                          background: <?php echo $machine['reading_date_remarks'] == 'aligned reading date' ? '#d4edda' : '#fff3cd'; ?>; 
                                          color: <?php echo $machine['reading_date_remarks'] == 'aligned reading date' ? '#155724' : '#856404'; ?>;">
                                        <?php echo $machine['reading_date_remarks'] == 'aligned reading date' ? '✓ Aligned' : '⚠️ Misaligned'; ?>
                                    </span>
                                <?php endif; ?>
                            </p>
                            
                            <?php if (!empty($machine['dr_pos_files'])): 
                                $drpos_files = explode(',', $machine['dr_pos_files']);
                                $drpos_count = count($drpos_files);
                            ?>
                                <p style="display: flex; align-items: center; flex-wrap: wrap; gap: 8px;">
                                    <strong>🧾 DR/POS:</strong>
                                    <span class="drpos-badge">
                                        <?php echo $drpos_count; ?> file(s)
                                    </span>
                                    <?php if ($drpos_count == 1): ?>
                                        <button onclick="openDRPOSFile('<?php echo $drpos_files[0]; ?>', 'Machine #<?php echo htmlspecialchars($machine['machine_number']); ?>')" 
                                                class="drpos-btn">
                                            👁️ View
                                        </button>
                                    <?php else: ?>
                                        <button onclick="showDRPOSFileList(<?php echo htmlspecialchars(json_encode($drpos_files)); ?>, 'Machine #<?php echo htmlspecialchars($machine['machine_number']); ?>')" 
                                                class="drpos-btn">
                                            📑 View All (<?php echo $drpos_count; ?>)
                                        </button>
                                    <?php endif; ?>
                                    <a href="r-upload_drpos.php?machine_id=<?php echo $machine['id']; ?>" 
                                       class="drpos-btn" style="background: #27ae60; text-decoration: none;">
                                        ➕ Add
                                    </a>
                                </p>
                            <?php else: ?>
                                <p style="display: flex; align-items: center; gap: 8px;">
                                    <strong>🧾 DR/POS:</strong>
                                    <span style="color: #7f8c8d;">No receipts</span>
                                    <a href="r-upload_drpos.php?machine_id=<?php echo $machine['id']; ?>" 
                                       class="drpos-btn" style="background: #27ae60; text-decoration: none;">
                                        ➕ Add Receipt
                                    </a>
                                </p>
                            <?php endif; ?>
                            
                            <a href="r-edit_machine.php?id=<?php echo $machine['id']; ?>" class="action-link">
                                ✏️ Edit Machine
                            </a>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="no-results">
                    <span style="font-size: 64px;">🔍</span>
                    <h3>No Machines Found</h3>
                    <p>Try adjusting your filters or add a new machine to a contract.</p>
                    <a href="r-view_contracts.php" class="btn">View Contracts</a>
                    <a href="r-view_all_machines.php" class="btn btn-secondary">Clear Filters</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // Open DR/POS file in modal
    function openDRPOSFile(filePath, machineNumber) {
        // Check if file exists
        fetch(filePath, { method: 'HEAD' })
            .then(response => {
                if (response.ok) {
                    // Create modal if it doesn't exist
                    let modal = document.getElementById('drposModal');
                    if (!modal) {
                        modal = document.createElement('div');
                        modal.id = 'drposModal';
                        modal.className = 'pdf-modal';
                        modal.innerHTML = `
                            <div class="pdf-modal-content">
                                <div class="pdf-modal-header">
                                    <div class="pdf-modal-title">
                                        <span style="font-size: 24px;">🧾</span>
                                        <span id="drposModalTitle">DR/POS Receipt</span>
                                    </div>
                                    <div style="display: flex; gap: 10px;">
                                        <button id="downloadDrposBtn" class="pdf-modal-close" style="background: #27ae60;">
                                            ⬇️ Download
                                        </button>
                                        <button onclick="closeDRPOSModal()" class="pdf-modal-close">
                                            ✕ Close
                                        </button>
                                    </div>
                                </div>
                                <iframe id="drposViewer" style="width: 100%; height: 100%; border: none;"></iframe>
                            </div>
                        `;
                        document.body.appendChild(modal);
                    }
                    
                    const viewer = document.getElementById('drposViewer');
                    const title = document.getElementById('drposModalTitle');
                    const downloadBtn = document.getElementById('downloadDrposBtn');
                    
                    viewer.src = filePath;
                    title.textContent = `DR/POS Receipt - ${machineNumber}`;
                    downloadBtn.onclick = function() {
                        window.open(filePath, '_blank');
                    };
                    
                    modal.style.display = 'block';
                    document.body.style.overflow = 'hidden';
                } else {
                    alert('DR/POS file not found. It may have been moved or deleted.');
                }
            })
            .catch(error => {
                console.error('Error loading file:', error);
                alert('Error loading DR/POS file. Please try again.');
            });
    }

    // Show DR/POS file list for multiple files
    function showDRPOSFileList(files, machineNumber) {
        // Create modal if it doesn't exist
        let modal = document.getElementById('drposListModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'drposListModal';
            modal.className = 'pdf-modal';
            modal.style.background = 'rgba(0,0,0,0.8)';
            modal.innerHTML = `
                <div class="pdf-modal-content" style="display: flex; justify-content: center; align-items: center;">
                    <div style="background: white; border-radius: 15px; width: 90%; max-width: 600px; max-height: 80%; overflow-y: auto; padding: 30px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <h3 style="color: #2c3e50; display: flex; align-items: center; gap: 10px;">
                                <span style="font-size: 24px;">🧾</span>
                                <span id="drposListTitle">DR/POS Receipts</span>
                            </h3>
                            <button onclick="closeDRPOSListModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #7f8c8d;">✕</button>
                        </div>
                        <div id="drposListContainer" style="display: flex; flex-direction: column; gap: 10px;"></div>
                        <div style="margin-top: 20px; text-align: right;">
                            <button onclick="closeDRPOSListModal()" class="btn" style="background: #95a5a6;">Close</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }
        
        const title = document.getElementById('drposListTitle');
        const container = document.getElementById('drposListContainer');
        
        title.textContent = `DR/POS Receipts - ${machineNumber}`;
        container.innerHTML = '';
        
        files.forEach((file, index) => {
            const fileName = file.split('/').pop() || `Receipt ${index + 1}`;
            const fileItem = document.createElement('div');
            fileItem.style.cssText = `
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 15px;
                background: #f8f9fa;
                border-radius: 8px;
                border-left: 4px solid #e67e22;
                transition: all 0.3s ease;
            `;
            fileItem.onmouseover = function() {
                this.style.transform = 'translateX(5px)';
                this.style.boxShadow = '0 2px 10px rgba(230, 126, 34, 0.2)';
            };
            fileItem.onmouseout = function() {
                this.style.transform = 'translateX(0)';
                this.style.boxShadow = 'none';
            };
            fileItem.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 20px; color: #e67e22;">🧾</span>
                    <div>
                        <div style="font-weight: 600; color: #2c3e50;">${fileName}</div>
                        <div style="font-size: 11px; color: #7f8c8d;">DR/POS Receipt ${index + 1}</div>
                    </div>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button onclick="openDRPOSFile('${file}', '${machineNumber}')" 
                            style="background: #e67e22; color: white; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer; font-size: 12px; font-weight: 600; transition: all 0.3s;">
                        👁️ View
                    </button>
                    <a href="${file}" download 
                       style="background: #27ae60; color: white; text-decoration: none; padding: 8px 15px; border-radius: 5px; font-size: 12px; font-weight: 600; transition: all 0.3s;">
                        ⬇️ Download
                    </a>
                </div>
            `;
            container.appendChild(fileItem);
        });
        
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    function closeDRPOSModal() {
        const modal = document.getElementById('drposModal');
        const viewer = document.getElementById('drposViewer');
        if (modal) {
            modal.style.display = 'none';
            if (viewer) viewer.src = '';
            document.body.style.overflow = 'auto';
        }
    }

    function closeDRPOSListModal() {
        const modal = document.getElementById('drposListModal');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }
    }

    // Close modals with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeDRPOSModal();
            closeDRPOSListModal();
        }
    });
    </script>
</body>
</html>