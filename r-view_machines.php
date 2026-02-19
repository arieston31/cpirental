<?php
require_once 'config.php';

$contract_id = isset($_GET['contract_id']) ? intval($_GET['contract_id']) : 0;
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

if (!$contract_id) {
    die("Contract ID is required.");
}

// Get contract info
$contract_query = $conn->query("
    SELECT c.*, cl.company_name, cl.classification 
    FROM rental_contracts c
    JOIN rental_clients cl ON c.client_id = cl.id
    WHERE c.id = $contract_id
");
$contract = $contract_query->fetch_assoc();

if (!$contract) {
    die("Contract not found.");
}

// Build machines query
$query = "SELECT * FROM rental_contract_machines WHERE contract_id = $contract_id";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (machine_serial_number LIKE ? OR machine_number LIKE ? OR barangay LIKE ? OR city LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $types .= "ssss";
}

if (!empty($status)) {
    $query .= " AND status = ?";
    $params[] = $status;
    $types .= "s";
}

$query .= " ORDER BY datecreated DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$machines = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Machines - <?php echo htmlspecialchars($contract['contract_number']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f4f6f9; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; margin-bottom: 5px; }
        h2 { color: #34495e; font-size: 18px; margin-bottom: 10px; }
        .btn {
            display: inline-block; padding: 10px 20px; background: #3498db; color: white;
            text-decoration: none; border-radius: 5px; transition: background 0.3s;
            border: none; cursor: pointer; font-size: 14px;
        }
        .btn:hover { background: #2980b9; }
        .btn-success { background: #27ae60; }
        .btn-success:hover { background: #229954; }
        .btn-warning { background: #f39c12; }
        .btn-warning:hover { background: #e67e22; }
        
        .contract-info {
            background: #e3f2fd; padding: 15px; border-radius: 8px; margin-bottom: 20px;
            display: flex; justify-content: space-between; align-items: center;
        }
        
        .filter-section {
            background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;
            display: flex; gap: 15px; align-items: flex-end;
        }
        
        .machine-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 20px; margin-top: 20px;
        }
        
        .machine-card {
            background: white; border-radius: 10px; overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1); transition: transform 0.3s;
        }
        .machine-card:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.15); }
        
        .machine-header {
            background: #34495e; color: white; padding: 15px;
            display: flex; justify-content: space-between; align-items: center;
        }
        .machine-type {
            background: rgba(255,255,255,0.2); padding: 5px 10px; border-radius: 20px;
            font-size: 12px; font-weight: bold;
        }
        .machine-body { padding: 20px; }
        .info-row {
            display: flex; margin-bottom: 10px; border-bottom: 1px solid #ecf0f1; padding-bottom: 5px;
        }
        .info-label {
            font-weight: bold; width: 120px; color: #7f8c8d;
        }
        .info-value { flex: 1; color: #2c3e50; }
        
        .zone-badge {
            background: #e8f5e9; color: #2e7d32; padding: 3px 8px;
            border-radius: 15px; font-size: 12px; display: inline-block;
        }
        
        .reading-date-aligned {
            background: #d4edda; color: #155724; padding: 3px 8px;
            border-radius: 15px; font-size: 12px; display: inline-block;
        }
        .reading-date-misaligned {
            background: #fff3cd; color: #856404; padding: 3px 8px;
            border-radius: 15px; font-size: 12px; display: inline-block;
        }
        
        .status-badge {
            display: inline-block; padding: 3px 8px; border-radius: 15px;
            font-size: 12px; font-weight: bold;
        }
        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        
        .action-buttons { 
            display: flex; gap: 5px; margin-top: 15px;
            border-top: 1px solid #ecf0f1; padding-top: 15px;
        }
        .action-btn {
            padding: 8px 15px; border-radius: 4px; text-decoration: none;
            color: white; font-size: 13px; text-align: center; flex: 1;
        }
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

        /* DR/POS File Item Styles */
        .drpos-file-item {
            transition: all 0.3s ease;
        }

        .drpos-file-item:hover {
            transform: translateX(5px);
            box-shadow: 0 2px 10px rgba(230, 126, 34, 0.2);
        }

        /* Adjust the action buttons for better mobile view */
        @media (max-width: 768px) {
            .pdf-modal-header {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            
            .info-row {
                flex-direction: column;
            }
            
            .info-label {
                width: 100%;
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1>Machines - <?php echo htmlspecialchars($contract['contract_number']); ?></h1>
                    <h2><?php echo htmlspecialchars($contract['company_name']); ?> (<?php echo $contract['classification']; ?>)</h2>
                </div>
                <div>
                    <a href="r-add_contract_machines.php?contract_id=<?php echo $contract_id; ?>&type=<?php echo $contract['type_of_contract']; ?>&client_id=<?php echo $contract['client_id']; ?>" class="btn btn-success">+ Add Machine</a>
                    <a href="r-edit_contract.php?id=<?php echo $contract_id; ?>" class="btn">Edit Contract</a>
                    <a href="r-view_contracts.php" class="btn" style="background: #95a5a6;">Back to Contracts</a>
                </div>
            </div>
        </div>

        <!-- Contract Summary -->
        <div class="contract-info">
            <div>
                <strong>Contract Type:</strong> <?php echo $contract['type_of_contract']; ?> |
                <strong>Colored Machines:</strong> <?php echo $contract['has_colored_machines']; ?> |
                <strong>Collection Period:</strong> <?php echo $contract['collection_processing_period']; ?> days |
                <strong>Collection Date:</strong> Day <?php echo $contract['collection_date'] ?: 'TBD'; ?>
            </div>
            <div>
                <span class="status-badge status-<?php echo strtolower($contract['status']); ?>">
                    <?php echo $contract['status']; ?>
                </span>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" style="display: flex; gap: 15px; width: 100%;">
                <input type="hidden" name="contract_id" value="<?php echo $contract_id; ?>">
                <div style="flex: 1;">
                    <input type="text" name="search" placeholder="Search by serial #, machine #, or location..." 
                           value="<?php echo htmlspecialchars($search); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div>
                    <select name="status" style="padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="">All Status</option>
                        <option value="ACTIVE" <?php echo $status == 'ACTIVE' ? 'selected' : ''; ?>>ACTIVE</option>
                        <option value="INACTIVE" <?php echo $status == 'INACTIVE' ? 'selected' : ''; ?>>INACTIVE</option>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn">Filter</button>
                    <a href="r-view_machines.php?contract_id=<?php echo $contract_id; ?>" class="btn" style="background: #95a5a6;">Clear</a>
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
                                <span class="status-badge status-<?php echo strtolower($machine['status']); ?>" style="margin-left: 5px;">
                                    <?php echo $machine['status']; ?>
                                </span>
                            </div>
                        </div>
                        <div class="machine-body">
                            <div class="info-row">
                                <span class="info-label">Model/Brand:</span>
                                <span class="info-value">
                                    <?php echo htmlspecialchars($machine['machine_model']); ?> / 
                                    <?php echo htmlspecialchars($machine['machine_brand']); ?>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Serial Number:</span>
                                <span class="info-value"><?php echo htmlspecialchars($machine['machine_serial_number']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Meter Start:</span>
                                <span class="info-value">
                                    Mono: <?php echo number_format($machine['mono_meter_start']); ?>
                                    <?php if ($machine['machine_type'] == 'COLOR'): ?>
                                        | Color: <?php echo number_format($machine['color_meter_start']); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Installation:</span>
                                <span class="info-value">
                                    <?php echo htmlspecialchars($machine['building_number'] . ' ' . $machine['street_name']); ?><br>
                                    <?php echo htmlspecialchars($machine['barangay'] . ', ' . $machine['city']); ?>
                                </span>
                            </div>
                            <?php if ($machine['department']): ?>
                                <div class="info-row">
                                    <span class="info-label">Department:</span>
                                    <span class="info-value">
                                        <span style="background: #fff8e1; padding: 3px 8px; border-radius: 15px; font-size: 12px; color: #856404;">
                                            🏢 <?php echo htmlspecialchars($machine['department']); ?>
                                        </span>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="info-row">
                                <span class="info-label">Zone:</span>
                                <span class="info-value">
                                    <span class="zone-badge">
                                        Zone <?php echo $machine['zone_number']; ?> - <?php echo htmlspecialchars($machine['area_center']); ?>
                                    </span>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Reading Date:</span>
                                <span class="info-value">
                                    <strong>Day <?php echo $machine['reading_date']; ?></strong>
                                    <?php if ($machine['reading_date_remarks']): ?>
                                        <span class="reading-date-<?php echo str_replace(' ', '-', $machine['reading_date_remarks']); ?>">
                                            (<?php echo $machine['reading_date_remarks']; ?>)
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php if ($machine['comments']): ?>
                                <div class="info-row">
                                    <span class="info-label">Comments:</span>
                                    <span class="info-value" style="color: #7f8c8d;">
                                        <?php echo htmlspecialchars($machine['comments']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($machine['dr_pos_files'])): 
                                $drpos_files = explode(',', $machine['dr_pos_files']);
                                $drpos_count = count($drpos_files);
                            ?>
                                <div class="info-row">
                                    <span class="info-label">🧾 DR/POS:</span>
                                    <span class="info-value">
                                        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 10px;">
                                            <span style="background: #e67e22; color: white; padding: 3px 10px; border-radius: 15px; font-size: 11px; font-weight: 600;">
                                                <?php echo $drpos_count; ?> file(s)
                                            </span>
                                            <?php if ($drpos_count == 1): ?>
                                                <button onclick="openDRPOSFile('<?php echo $drpos_files[0]; ?>', 'Machine #<?php echo htmlspecialchars($machine['machine_number']); ?>')" 
                                                        style="background: #e67e22; color: white; border: none; padding: 4px 12px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: 600;">
                                                    👁️ View Receipt
                                                </button>
                                            <?php else: ?>
                                                <button onclick="showDRPOSFileList(<?php echo htmlspecialchars(json_encode($drpos_files)); ?>, 'Machine #<?php echo htmlspecialchars($machine['machine_number']); ?>')" 
                                                        style="background: #e67e22; color: white; border: none; padding: 4px 12px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: 600;">
                                                    📑 View Receipts (<?php echo $drpos_count; ?>)
                                                </button>
                                            <?php endif; ?>
                                            <a href="r-upload_drpos.php?machine_id=<?php echo $machine['id']; ?>" 
                                            style="background: #27ae60; color: white; text-decoration: none; padding: 4px 12px; border-radius: 4px; font-size: 11px; font-weight: 600;">
                                                ➕ Add
                                            </a>
                                        </div>
                                    </span>
                                </div>
                            <?php else: ?>
                                <div class="info-row">
                                    <span class="info-label">🧾 DR/POS:</span>
                                    <span class="info-value">
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <span style="color: #7f8c8d; font-size: 12px;">No receipts</span>
                                            <a href="r-upload_drpos.php?machine_id=<?php echo $machine['id']; ?>" 
                                            style="background: #27ae60; color: white; text-decoration: none; padding: 4px 12px; border-radius: 4px; font-size: 11px; font-weight: 600;">
                                                ➕ Add Receipt
                                            </a>
                                        </div>
                                    </span>
                                </div>
                            <?php endif; ?>
                            
                            <div class="action-buttons">
                                <a href="r-edit_machine.php?id=<?php echo $machine['id']; ?>" class="action-btn" style="background: #f39c12;">Edit</a>
                                <?php if ($machine['status'] == 'ACTIVE'): ?>
                                    <a href="#" onclick="updateMachineStatus(<?php echo $machine['id']; ?>, 'INACTIVE')" class="action-btn" style="background: #95a5a6;">Deactivate</a>
                                <?php else: ?>
                                    <a href="#" onclick="updateMachineStatus(<?php echo $machine['id']; ?>, 'ACTIVE')" class="action-btn" style="background: #27ae60;">Activate</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="grid-column: 1/-1; text-align: center; padding: 60px; background: white; border-radius: 10px;">
                    <h3 style="color: #7f8c8d; margin-bottom: 10px;">No Machines Found</h3>
                    <p>This contract doesn't have any machines yet.</p>
                    <a href="r-add_contract_machines.php?contract_id=<?php echo $contract_id; ?>&type=<?php echo $contract['type_of_contract']; ?>&client_id=<?php echo $contract['client_id']; ?>" class="btn btn-success" style="margin-top: 20px;">
                        + Add First Machine
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function updateMachineStatus(machineId, newStatus) {
        if (confirm(`Are you sure you want to ${newStatus === 'ACTIVE' ? 'activate' : 'deactivate'} this machine?`)) {
            fetch('r-update_machine_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: machineId, status: newStatus })
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
    </script>
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
            `;
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
                            style="background: #e67e22; color: white; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer; font-size: 12px;">
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