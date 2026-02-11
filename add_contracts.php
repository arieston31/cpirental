<?php
require_once 'config.php'; // Database connection

$message = '';
$message_type = ''; // 'success' or 'error'

// Auto-generate contract number
function generateContractNumber($conn) {
    $current_year = date('Y');
    
    // Get the next sequential number for contracts (regardless of year)
    $count_sql = "SELECT COUNT(*) as total FROM contracts";
    $count_result = $conn->query($count_sql);
    $total_contracts = $count_result->fetch_assoc()['total'] + 1;
    $sequential_number = str_pad($total_contracts, 6, '0', STR_PAD_LEFT);
    
    // We'll update the G001/P001 part after client selection
    $base_number = "RCN-{$current_year}-XXXX-{$sequential_number}";
    
    return $base_number;
}

// Initialize variables
$contract_number = generateContractNumber($conn);
$selected_client_id = '';
$selected_client_name = '';
$client_classification = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle AJAX requests
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'search_clients':
                $search_term = trim($_POST['search'] ?? '');
                if (strlen($search_term) >= 2) {
                    $sql = "SELECT id, company_name, classification FROM clients 
                            WHERE (company_name LIKE ? OR main_signatory LIKE ?) 
                            AND status = 'ACTIVE' 
                            LIMIT 10";
                    $stmt = $conn->prepare($sql);
                    $search_param = "%{$search_term}%";
                    $stmt->bind_param("ss", $search_param, $search_param);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $clients = [];
                    while ($row = $result->fetch_assoc()) {
                        $clients[] = $row;
                    }
                    echo json_encode($clients);
                } else {
                    echo json_encode([]);
                }
                exit();
                
            case 'check_zone':
                $barangay = trim($_POST['barangay'] ?? '');
                $city = trim($_POST['city'] ?? '');
                
                // This should connect to your existing zoning logic
                // For now, returning sample data
                $zoning_data = [
                    'zoning_area' => 'ZONE-' . strtoupper(substr($city, 0, 3)),
                    'reading_schedule' => 'Every 15th of the month',
                    'collection_date' => date('Y-m-d', strtotime('+5 days'))
                ];
                echo json_encode($zoning_data);
                exit();
                
            case 'get_contract_prefix':
                $client_id = (int)($_POST['client_id'] ?? 0);
                if ($client_id > 0) {
                    $sql = "SELECT classification FROM clients WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $client_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        $classification = $row['classification'];
                        $prefix = $classification == 'GOVERNMENT' ? 'G' : 'P';
                        
                        // Get count of contracts for this classification in current year
                        $current_year = date('Y');
                        $count_sql = "SELECT COUNT(*) as count FROM contracts 
                                     WHERE client_classification = ? 
                                     AND YEAR(date_created) = ?";
                        $count_stmt = $conn->prepare($count_sql);
                        $count_stmt->bind_param("si", $classification, $current_year);
                        $count_stmt->execute();
                        $count_result = $count_stmt->get_result();
                        $count = $count_result->fetch_assoc()['count'] + 1;
                        
                        $classification_number = str_pad($count, 3, '0', STR_PAD_LEFT);
                        echo json_encode([
                            'prefix' => $prefix,
                            'classification_number' => $classification_number,
                            'classification' => $classification
                        ]);
                    }
                }
                exit();
        }
    }
    
    // Handle form submission
    $client_id = (int)($_POST['client_id'] ?? 0);
    $contract_type = $_POST['contract_type'] ?? '';
    $mono_rate = $_POST['mono_rate'] ?? '';
    $color_rate = $_POST['color_rate'] ?? '';
    $excess_mono_rate = $_POST['excess_mono_rate'] ?? '';
    $excess_color_rate = $_POST['excess_color_rate'] ?? '';
    $min_copies_mono = $_POST['min_copies_mono'] ?? '';
    $min_copies_color = $_POST['min_copies_color'] ?? '';
    $spoilage = $_POST['spoilage'] ?? '';
    $vatable = $_POST['vatable'] ?? '';
    $barangay = $_POST['barangay'] ?? '';
    $city = $_POST['city'] ?? '';
    $zoning_area = $_POST['zoning_area'] ?? '';
    $reading_schedule = $_POST['reading_schedule'] ?? '';
    $collection_date = $_POST['collection_date'] ?? '';
    $machine_count = (int)($_POST['machine_count'] ?? 1);
    
    // Validation
    $errors = [];
    
    if ($client_id <= 0) {
        $errors[] = "Please select a valid client.";
    }
    
    if (empty($contract_type)) {
        $errors[] = "Contract type is required.";
    }
    
    if (empty($mono_rate) || !is_numeric($mono_rate)) {
        $errors[] = "Valid mono rate is required.";
    }
    
    if (empty($excess_mono_rate) || !is_numeric($excess_mono_rate)) {
        $errors[] = "Valid excess mono rate is required.";
    }
    
    if (empty($min_copies_mono) || !is_numeric($min_copies_mono)) {
        $errors[] = "Valid minimum mono copies is required.";
    }
    
    if (empty($spoilage) || !is_numeric($spoilage) || $spoilage < 0 || $spoilage > 100) {
        $errors[] = "Valid spoilage percentage (0-100) is required.";
    }
    
    if (empty($vatable)) {
        $errors[] = "VAT status is required.";
    }
    
    // Validate machines
    $machines = [];
    for ($i = 1; $i <= $machine_count; $i++) {
        $machine_type = $_POST["machine_type_{$i}"] ?? '';
        $brand = $_POST["brand_{$i}"] ?? '';
        $model = $_POST["model_{$i}"] ?? '';
        $serial_number = $_POST["serial_number_{$i}"] ?? '';
        $meter_start = $_POST["meter_start_{$i}"] ?? 0;
        $machine_number = $_POST["machine_number_{$i}"] ?? '';
        
        if (empty($machine_type) || empty($serial_number)) {
            $errors[] = "Machine type and serial number are required for all machines.";
            break;
        }
        
        $machines[] = [
            'machine_type' => $machine_type,
            'brand' => $brand,
            'model' => $model,
            'serial_number' => $serial_number,
            'meter_start' => (int)$meter_start,
            'machine_number' => $machine_number
        ];
    }
    
    // File upload
    $contract_file = '';
    if (isset($_FILES['contract_file']) && $_FILES['contract_file']['error'] == 0) {
        $allowed_types = ['application/pdf'];
        $file_type = $_FILES['contract_file']['type'];
        $file_size = $_FILES['contract_file']['size'];
        
        if (in_array($file_type, $allowed_types) && $file_size <= 5 * 1024 * 1024) { // 5MB max
            $upload_dir = 'uploads/contracts/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = pathinfo($_FILES['contract_file']['name'], PATHINFO_EXTENSION);
            $file_name = 'contract_' . time() . '_' . uniqid() . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['contract_file']['tmp_name'], $file_path)) {
                $contract_file = $file_path;
            }
        }
    }
    
    // If no errors, insert into database
    if (empty($errors)) {
        try {
            // Begin transaction
            $conn->begin_transaction();
            
            // Get client info
            $client_sql = "SELECT company_name, classification FROM clients WHERE id = ?";
            $client_stmt = $conn->prepare($client_sql);
            $client_stmt->bind_param("i", $client_id);
            $client_stmt->execute();
            $client_result = $client_stmt->get_result();
            $client_info = $client_result->fetch_assoc();
            
            // Generate final contract number
            $current_year = date('Y');
            $classification = $client_info['classification'];
            $prefix = $classification == 'GOVERNMENT' ? 'G' : 'P';
            
            // Get count for this classification in current year
            $count_sql = "SELECT COUNT(*) as count FROM contracts 
                         WHERE client_classification = ? 
                         AND YEAR(date_created) = ?";
            $count_stmt = $conn->prepare($count_sql);
            $count_stmt->bind_param("si", $classification, $current_year);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            $count = $count_result->fetch_assoc()['count'] + 1;
            $classification_number = str_pad($count, 3, '0', STR_PAD_LEFT);
            
            // Get total contract count
            $total_sql = "SELECT COUNT(*) as total FROM contracts";
            $total_result = $conn->query($total_sql);
            $total_contracts = $total_result->fetch_assoc()['total'] + 1;
            $sequential_number = str_pad($total_contracts, 6, '0', STR_PAD_LEFT);
            
            $final_contract_number = "RCN-{$current_year}-{$prefix}{$classification_number}-{$sequential_number}";
            
            // Insert contract
            $contract_sql = "INSERT INTO contracts (
                contract_number, client_id, client_name, client_classification,
                contract_type, mono_rate, color_rate, excess_mono_rate, excess_color_rate,
                min_copies_mono, min_copies_color, spoilage, vatable, contract_file,
                zoning_area, reading_date_schedule, collection_date,
                barangay, city, status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', NULL)";
            
            $contract_stmt = $conn->prepare($contract_sql);
            $contract_stmt->bind_param(
                "sissdddddiisdssssss",
                $final_contract_number,
                $client_id,
                $client_info['company_name'],
                $classification,
                $contract_type,
                $mono_rate,
                $color_rate,
                $excess_mono_rate,
                $excess_color_rate,
                $min_copies_mono,
                $min_copies_color,
                $spoilage,
                $vatable,
                $contract_file,
                $zoning_area,
                $reading_schedule,
                $collection_date,
                $barangay,
                $city
            );
            
            if ($contract_stmt->execute()) {
                $contract_id = $conn->insert_id;
                
                // Insert machines
                foreach ($machines as $machine) {
                    $machine_sql = "INSERT INTO contract_machines (
                        contract_id, machine_type, brand, model, serial_number,
                        meter_start, machine_number, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVE')";
                    
                    $machine_stmt = $conn->prepare($machine_sql);
                    $machine_stmt->bind_param(
                        "issssis",
                        $contract_id,
                        $machine['machine_type'],
                        $machine['brand'],
                        $machine['model'],
                        $machine['serial_number'],
                        $machine['meter_start'],
                        $machine['machine_number']
                    );
                    $machine_stmt->execute();
                    $machine_stmt->close();
                }
                
                $conn->commit();
                $message = "Contract created successfully! Contract Number: {$final_contract_number}";
                $message_type = "success";
                
                // Clear form (optional)
                $contract_number = generateContractNumber($conn);
                $selected_client_id = '';
                $selected_client_name = '';
                $client_classification = '';
                
            } else {
                throw new Exception("Error creating contract: " . $conn->error);
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    } else {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Contract - Rental System</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
            padding: 20px;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .header-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            transition: background-color 0.3s;
        }
        
        .btn:hover {
            background-color: #2980b9;
        }
        
        .btn-dashboard::before {
            content: "🏠";
            margin-right: 8px;
        }
        
        .btn-view::before {
            content: "📄";
            margin-right: 8px;
        }
        
        .btn-add-machine {
            background-color: #27ae60;
            margin-top: 10px;
        }
        
        .btn-add-machine:hover {
            background-color: #219653;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            padding: 30px;
        }
        
        h1 {
            color: #2c3e50;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #3498db;
        }
        
        .contract-number {
            background-color: #2c3e50;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 25px;
            text-align: center;
        }
        
        .form-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .section-title {
            color: #2c3e50;
            font-size: 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title::before {
            content: "📋";
            font-size: 24px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .required::after {
            content: " *";
            color: #e74c3c;
        }
        
        .search-container {
            position: relative;
        }
        
        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background-color: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }
        
        .search-result-item {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f8f9fa;
        }
        
        .search-result-item:hover {
            background-color: #f8f9fa;
        }
        
        .client-info-box {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #3498db;
            margin-top: 10px;
            display: none;
        }
        
        .classification-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .classification-gov {
            background-color: #cce5ff;
            color: #004085;
        }
        
        .classification-pvt {
            background-color: #d4edda;
            color: #155724;
        }
        
        select, input[type="text"], input[type="number"], input[type="date"], input[type="file"], textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border 0.3s;
        }
        
        select:focus, input:focus, textarea:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }
        
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }
        
        .zoning-info {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            margin-top: 15px;
            display: none;
        }
        
        .zoning-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }
        
        .machine-section {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #27ae60;
        }
        
        .machine-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .machine-title {
            color: #2c3e50;
            font-weight: 600;
            font-size: 18px;
        }
        
        .message {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 25px;
            font-weight: 500;
        }
        
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .machine-type-selector {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .machine-type-option {
            flex: 1;
            text-align: center;
            padding: 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .machine-type-option.selected {
            border-color: #3498db;
            background-color: #e3f2fd;
        }
        
        .machine-count-input {
            max-width: 200px;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }
        
        .btn-submit {
            background-color: #27ae60;
            flex: 2;
        }
        
        .btn-submit:hover {
            background-color: #219653;
        }
        
        .btn-cancel {
            background-color: #6c757d;
            flex: 1;
        }
        
        .btn-cancel:hover {
            background-color: #545b62;
        }
        
        @media (max-width: 768px) {
            .form-row, .machine-type-selector {
                flex-direction: column;
            }
            
            .header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .header-buttons {
                justify-content: center;
            }
            
            .zoning-details {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Create New Contract</h1>
            <div class="header-buttons">
                <a href="dashboard.php" class="btn btn-dashboard">Dashboard</a>
                <a href="view_clients.php" class="btn btn-view">View Clients</a>
            </div>
        </div>
        
        <div class="contract-number" id="contractNumberDisplay">
            <?php echo htmlspecialchars($contract_number); ?>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" enctype="multipart/form-data" id="contractForm">
            <!-- Section 1: Client Selection -->
            <div class="form-section">
                <h2 class="section-title">Client Information</h2>
                
                <div class="form-group">
                    <label for="client_search" class="required">Search Client</label>
                    <div class="search-container">
                        <input type="text" id="client_search" name="client_search" 
                               placeholder="Type at least 2 characters to search clients..." 
                               autocomplete="off">
                        <div class="search-results" id="clientResults"></div>
                    </div>
                    <input type="hidden" id="client_id" name="client_id" value="<?php echo $selected_client_id; ?>">
                    
                    <div class="client-info-box" id="clientInfoBox">
                        <div id="clientInfoContent"></div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Contract Details -->
            <div class="form-section">
                <h2 class="section-title">Contract Details</h2>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="contract_type" class="required">Type of Contract</label>
                        <select id="contract_type" name="contract_type" required>
                            <option value="">-- Select Contract Type --</option>
                            <option value="UMBRELLA" <?php echo ($contract_type ?? '') == 'UMBRELLA' ? 'selected' : ''; ?>>UMBRELLA</option>
                            <option value="SINGLE CONTRACT" <?php echo ($contract_type ?? '') == 'SINGLE CONTRACT' ? 'selected' : ''; ?>>SINGLE CONTRACT</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="vatable" class="required">VAT Status</label>
                        <select id="vatable" name="vatable" required>
                            <option value="">-- Select VAT Status --</option>
                            <option value="YES" <?php echo ($vatable ?? '') == 'YES' ? 'selected' : ''; ?>>YES - With VAT</option>
                            <option value="NO" <?php echo ($vatable ?? '') == 'NO' ? 'selected' : ''; ?>>NO - Non-VAT</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="barangay">Barangay</label>
                        <input type="text" id="barangay" name="barangay" 
                               value="<?php echo htmlspecialchars($barangay ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="city">City</label>
                        <input type="text" id="city" name="city" 
                               value="<?php echo htmlspecialchars($city ?? ''); ?>">
                    </div>
                </div>
                
                <div class="zoning-info" id="zoningInfo">
                    <strong>Zoning Information:</strong>
                    <div class="zoning-details">
                        <div>
                            <label>Zoning Area:</label>
                            <div id="zoningArea"></div>
                        </div>
                        <div>
                            <label>Reading Schedule:</label>
                            <div id="readingSchedule"></div>
                        </div>
                        <div>
                            <label>Collection Date:</label>
                            <div id="collectionDate"></div>
                        </div>
                    </div>
                    <input type="hidden" id="zoning_area" name="zoning_area">
                    <input type="hidden" id="reading_schedule" name="reading_schedule">
                    <input type="hidden" id="collection_date" name="collection_date">
                </div>
            </div>
            
            <!-- Section 3: Rate Information -->
            <div class="form-section">
                <h2 class="section-title">Rate Information</h2>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="mono_rate" class="required">Mono Rate (₱)</label>
                        <input type="number" id="mono_rate" name="mono_rate" 
                               step="0.01" min="0" required
                               value="<?php echo htmlspecialchars($mono_rate ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="color_rate">Color Rate (₱)</label>
                        <input type="number" id="color_rate" name="color_rate" 
                               step="0.01" min="0"
                               value="<?php echo htmlspecialchars($color_rate ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="excess_mono_rate" class="required">Excess Mono Rate (₱)</label>
                        <input type="number" id="excess_mono_rate" name="excess_mono_rate" 
                               step="0.01" min="0" required
                               value="<?php echo htmlspecialchars($excess_mono_rate ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="excess_color_rate">Excess Color Rate (₱)</label>
                        <input type="number" id="excess_color_rate" name="excess_color_rate" 
                               step="0.01" min="0"
                               value="<?php echo htmlspecialchars($excess_color_rate ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="min_copies_mono" class="required">Minimum Mono Copies</label>
                        <input type="number" id="min_copies_mono" name="min_copies_mono" 
                               min="0" required
                               value="<?php echo htmlspecialchars($min_copies_mono ?? '0'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="min_copies_color">Minimum Color Copies</label>
                        <input type="number" id="min_copies_color" name="min_copies_color" 
                               min="0"
                               value="<?php echo htmlspecialchars($min_copies_color ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="spoilage" class="required">Spoilage (%)</label>
                        <input type="number" id="spoilage" name="spoilage" 
                               step="0.01" min="0" max="100" required
                               value="<?php echo htmlspecialchars($spoilage ?? '0'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="contract_file">Upload Contract (PDF)</label>
                        <input type="file" id="contract_file" name="contract_file" 
                               accept=".pdf">
                    </div>
                </div>
            </div>
            
            <!-- Section 4: Machine Information -->
            <div class="form-section">
                <h2 class="section-title">Machine Information</h2>
                
                <div class="machine-type-selector">
                    <div class="machine-type-option <?php echo ($_POST['machine_type'] ?? 'single') == 'single' ? 'selected' : ''; ?>" 
                         data-type="single" onclick="selectMachineType('single')">
                        <h3>Single Machine</h3>
                        <p>Add one machine</p>
                    </div>
                    <div class="machine-type-option <?php echo ($_POST['machine_type'] ?? '') == 'multiple' ? 'selected' : ''; ?>" 
                         data-type="multiple" onclick="selectMachineType('multiple')">
                        <h3>Multiple Machines</h3>
                        <p>Add multiple machines at once</p>
                    </div>
                </div>
                
                <input type="hidden" id="machine_type" name="machine_type" value="<?php echo $_POST['machine_type'] ?? 'single'; ?>">
                
                <div class="form-group machine-count-input" id="machineCountContainer" 
                     style="display: <?php echo ($_POST['machine_type'] ?? 'single') == 'multiple' ? 'block' : 'none'; ?>;">
                    <label for="machine_count">How many machines?</label>
                    <input type="number" id="machine_count" name="machine_count" 
                           min="1" max="20" value="<?php echo $_POST['machine_count'] ?? 1; ?>"
                           onchange="generateMachineForms()">
                </div>
                
                <div id="machineFormsContainer">
                    <!-- Machine forms will be generated here -->
                    <?php 
                    $machine_count = $_POST['machine_count'] ?? 1;
                    for ($i = 1; $i <= $machine_count; $i++): 
                    ?>
                        <div class="machine-section" id="machineSection_<?php echo $i; ?>">
                            <div class="machine-header">
                                <div class="machine-title">Machine #<?php echo $i; ?></div>
                                <?php if ($i > 1): ?>
                                    <button type="button" class="btn" onclick="removeMachine(<?php echo $i; ?>)" 
                                            style="background-color: #e74c3c; padding: 5px 10px; font-size: 12px;">
                                        Remove
                                    </button>
                                <?php endif; ?>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="machine_type_<?php echo $i; ?>" class="required">Machine Type</label>
                                    <select id="machine_type_<?php echo $i; ?>" name="machine_type_<?php echo $i; ?>" required>
                                        <option value="">-- Select Machine Type --</option>
                                        <option value="COPIER" <?php echo ($_POST["machine_type_{$i}"] ?? '') == 'COPIER' ? 'selected' : ''; ?>>Copier</option>
                                        <option value="PRINTER" <?php echo ($_POST["printer_type_{$i}"] ?? '') == 'PRINTER' ? 'selected' : ''; ?>>Printer</option>
                                        <option value="SCANNER" <?php echo ($_POST["scanner_type_{$i}"] ?? '') == 'SCANNER' ? 'selected' : ''; ?>>Scanner</option>
                                        <option value="FAX" <?php echo ($_POST["fax_type_{$i}"] ?? '') == 'FAX' ? 'selected' : ''; ?>>Fax Machine</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="brand_<?php echo $i; ?>">Brand</label>
                                    <input type="text" id="brand_<?php echo $i; ?>" name="brand_<?php echo $i; ?>" 
                                           value="<?php echo htmlspecialchars($_POST["brand_{$i}"] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="model_<?php echo $i; ?>">Model</label>
                                    <input type="text" id="model_<?php echo $i; ?>" name="model_<?php echo $i; ?>" 
                                           value="<?php echo htmlspecialchars($_POST["model_{$i}"] ?? ''); ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="serial_number_<?php echo $i; ?>" class="required">Serial Number</label>
                                    <input type="text" id="serial_number_<?php echo $i; ?>" name="serial_number_<?php echo $i; ?>" 
                                           required value="<?php echo htmlspecialchars($_POST["serial_number_{$i}"] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="meter_start_<?php echo $i; ?>" class="required">Meter Start</label>
                                    <input type="number" id="meter_start_<?php echo $i; ?>" name="meter_start_<?php echo $i; ?>" 
                                           min="0" required value="<?php echo $_POST["meter_start_{$i}"] ?? 0; ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="machine_number_<?php echo $i; ?>">Machine Number</label>
                                    <input type="text" id="machine_number_<?php echo $i; ?>" name="machine_number_<?php echo $i; ?>" 
                                           value="<?php echo htmlspecialchars($_POST["machine_number_{$i}"] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
                
                <button type="button" class="btn btn-add-machine" onclick="addMachineForm()" 
                        style="display: <?php echo ($_POST['machine_type'] ?? 'single') == 'multiple' ? 'block' : 'none'; ?>;">
                    ➕ Add Another Machine
                </button>
            </div>
            
            <div class="form-actions">
                <a href="dashboard.php" class="btn btn-cancel">Cancel</a>
                <button type="submit" class="btn btn-submit">Create Contract</button>
            </div>
        </form>
    </div>
    
    <script>
        // Client search functionality
        let searchTimeout;
        document.getElementById('client_search').addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            const searchTerm = e.target.value.trim();
            
            if (searchTerm.length < 2) {
                document.getElementById('clientResults').innerHTML = '';
                document.getElementById('clientResults').style.display = 'none';
                return;
            }
            
            searchTimeout = setTimeout(() => {
                searchClients(searchTerm);
            }, 300);
        });
        
        function searchClients(searchTerm) {
            const formData = new FormData();
            formData.append('action', 'search_clients');
            formData.append('search', searchTerm);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(clients => {
                const resultsDiv = document.getElementById('clientResults');
                resultsDiv.innerHTML = '';
                
                if (clients.length > 0) {
                    clients.forEach(client => {
                        const div = document.createElement('div');
                        div.className = 'search-result-item';
                        div.innerHTML = `
                            <strong>${escapeHtml(client.company_name)}</strong><br>
                            <small>${escapeHtml(client.classification)}</small>
                        `;
                        div.onclick = () => selectClient(client.id, client.company_name, client.classification);
                        resultsDiv.appendChild(div);
                    });
                    resultsDiv.style.display = 'block';
                } else {
                    resultsDiv.style.display = 'none';
                }
            });
        }
        
        function selectClient(clientId, clientName, classification) {
            document.getElementById('client_id').value = clientId;
            document.getElementById('client_search').value = clientName;
            document.getElementById('clientResults').style.display = 'none';
            
            // Show client info
            const infoBox = document.getElementById('clientInfoBox');
            const infoContent = document.getElementById('clientInfoContent');
            infoContent.innerHTML = `
                <strong>Selected Client:</strong> ${escapeHtml(clientName)}<br>
                <span class="classification-badge classification-${classification === 'GOVERNMENT' ? 'gov' : 'pvt'}">
                    ${escapeHtml(classification)}
                </span>
            `;
            infoBox.style.display = 'block';
            
            // Update contract number with classification
            updateContractNumber(clientId);
        }
        
        function updateContractNumber(clientId) {
            const formData = new FormData();
            formData.append('action', 'get_contract_prefix');
            formData.append('client_id', clientId);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.prefix) {
                    const currentDisplay = document.getElementById('contractNumberDisplay').textContent;
                    const parts = currentDisplay.split('-');
                    if (parts.length === 4) {
                        parts[2] = data.prefix + data.classification_number;
                        document.getElementById('contractNumberDisplay').textContent = parts.join('-');
                    }
                }
            });
        }
        
        // Zoning system
        let zoningTimeout;
        document.getElementById('barangay').addEventListener('input', checkZoning);
        document.getElementById('city').addEventListener('input', checkZoning);
        
        function checkZoning() {
            clearTimeout(zoningTimeout);
            const barangay = document.getElementById('barangay').value.trim();
            const city = document.getElementById('city').value.trim();
            
            if (barangay && city) {
                zoningTimeout = setTimeout(() => {
                    getZoningInfo(barangay, city);
                }, 500);
            }
        }
        
        function getZoningInfo(barangay, city) {
            const formData = new FormData();
            formData.append('action', 'check_zone');
            formData.append('barangay', barangay);
            formData.append('city', city);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.zoning_area) {
                    document.getElementById('zoningArea').textContent = data.zoning_area;
                    document.getElementById('readingSchedule').textContent = data.reading_schedule;
                    document.getElementById('collectionDate').textContent = data.collection_date;
                    
                    document.getElementById('zoning_area').value = data.zoning_area;
                    document.getElementById('reading_schedule').value = data.reading_schedule;
                    document.getElementById('collection_date').value = data.collection_date;
                    
                    document.getElementById('zoningInfo').style.display = 'block';
                }
            });
        }
        
        // Machine form management
        let machineCounter = <?php echo $machine_count; ?>;
        
        function selectMachineType(type) {
            document.getElementById('machine_type').value = type;
            document.querySelectorAll('.machine-type-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
            
            const countContainer = document.getElementById('machineCountContainer');
            const addButton = document.querySelector('.btn-add-machine');
            
            if (type === 'multiple') {
                countContainer.style.display = 'block';
                addButton.style.display = 'block';
                generateMachineForms();
            } else {
                countContainer.style.display = 'none';
                addButton.style.display = 'none';
                // Show only one machine form
                document.getElementById('machine_count').value = 1;
                generateMachineForms();
            }
        }
        
        function generateMachineForms() {
            const count = parseInt(document.getElementById('machine_count').value) || 1;
            const container = document.getElementById('machineFormsContainer');
            container.innerHTML = '';
            
            for (let i = 1; i <= count; i++) {
                addMachineForm(i);
            }
            machineCounter = count;
        }
        
        function addMachineForm(index = null) {
            if (!index) {
                machineCounter++;
                index = machineCounter;
                document.getElementById('machine_count').value = machineCounter;
            }
            
            const container = document.getElementById('machineFormsContainer');
            const machineHtml = `
                <div class="machine-section" id="machineSection_${index}">
                    <div class="machine-header">
                        <div class="machine-title">Machine #${index}</div>
                        ${index > 1 ? `<button type="button" class="btn" onclick="removeMachine(${index})" 
                                style="background-color: #e74c3c; padding: 5px 10px; font-size: 12px;">
                            Remove
                        </button>` : ''}
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="machine_type_${index}" class="required">Machine Type</label>
                            <select id="machine_type_${index}" name="machine_type_${index}" required>
                                <option value="">-- Select Machine Type --</option>
                                <option value="COPIER">Copier</option>
                                <option value="PRINTER">Printer</option>
                                <option value="SCANNER">Scanner</option>
                                <option value="FAX">Fax Machine</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="brand_${index}">Brand</label>
                            <input type="text" id="brand_${index}" name="brand_${index}">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="model_${index}">Model</label>
                            <input type="text" id="model_${index}" name="model_${index}">
                        </div>
                        
                        <div class="form-group">
                            <label for="serial_number_${index}" class="required">Serial Number</label>
                            <input type="text" id="serial_number_${index}" name="serial_number_${index}" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="meter_start_${index}" class="required">Meter Start</label>
                            <input type="number" id="meter_start_${index}" name="meter_start_${index}" min="0" required value="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="machine_number_${index}">Machine Number</label>
                            <input type="text" id="machine_number_${index}" name="machine_number_${index}">
                        </div>
                    </div>
                </div>
            `;
            
            container.insertAdjacentHTML('beforeend', machineHtml);
        }
        
        function removeMachine(index) {
            if (machineCounter > 1) {
                document.getElementById(`machineSection_${index}`).remove();
                machineCounter--;
                document.getElementById('machine_count').value = machineCounter;
                
                // Renumber remaining machines
                const sections = document.querySelectorAll('.machine-section');
                sections.forEach((section, i) => {
                    const newIndex = i + 1;
                    section.id = `machineSection_${newIndex}`;
                    section.querySelector('.machine-title').textContent = `Machine #${newIndex}`;
                    
                    // Update all input names and IDs
                    section.querySelectorAll('[name^="machine_type_"], [name^="brand_"], [name^="model_"], [name^="serial_number_"], [name^="meter_start_"], [name^="machine_number_"]').forEach(input => {
                        const oldName = input.name;
                        const prefix = oldName.split('_')[0];
                        input.name = `${prefix}_${newIndex}`;
                        input.id = `${prefix}_${newIndex}`;
                    });
                    
                    // Update remove button
                    const removeBtn = section.querySelector('button[onclick^="removeMachine"]');
                    if (removeBtn && newIndex > 1) {
                        removeBtn.onclick = () => removeMachine(newIndex);
                    } else if (removeBtn && newIndex === 1) {
                        removeBtn.remove();
                    }
                });
            }
        }
        
        // Form validation
        document.getElementById('contractForm').addEventListener('submit', function(e) {
            let isValid = true;
            const requiredFields = this.querySelectorAll('[required]');
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.style.borderColor = '#e74c3c';
                } else {
                    field.style.borderColor = '#ddd';
                }
            });
            
            // Check if client is selected
            if (!document.getElementById('client_id').value) {
                alert('Please select a client from the search results.');
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields marked with *.');
            }
        });
        
        // Helper function
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Initialize machine type
        document.addEventListener('DOMContentLoaded', function() {
            const initialType = document.getElementById('machine_type').value || 'single';
            selectMachineType(initialType);
        });
    </script>
</body>
</html>