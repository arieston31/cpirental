<?php
require_once 'config.php';
session_start();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_contract'])) {
    $conn->begin_transaction();
    
    try {
        // Get client classification
        $client_id = intval($_POST['client_id']);
        $client_query = $conn->query("SELECT classification FROM rental_clients WHERE id = $client_id");
        $client_data = $client_query->fetch_assoc();
        $classification = $client_data['classification'];
        
        // Get current year
        $year = date('Y');
        
        // Get prefix based on classification
        $prefix = ($classification == 'GOVERNMENT') ? 'G' : 'P';
        
        // --- FIXED: Get the sequence number for this classification AND year ---
        $seq_query = $conn->query("
            SELECT COUNT(*) as count 
            FROM rental_contracts 
            WHERE contract_number LIKE 'RCN-{$year}-{$prefix}%'
            AND YEAR(datecreated) = {$year}
        ");
        
        if (!$seq_query) {
            throw new Exception("Error counting contracts: " . $conn->error);
        }
        
        $seq_data = $seq_query->fetch_assoc();
        $sequence = str_pad($seq_data['count'] + 1, 3, '0', STR_PAD_LEFT);
        
        // --- FIXED: Get the overall contract count for the last 6 digits ---
        $total_query = $conn->query("SELECT COUNT(*) as total FROM rental_contracts");
        $total_data = $total_query->fetch_assoc();
        $overall_sequence = str_pad($total_data['total'] + 1, 6, '0', STR_PAD_LEFT);
        
        // Generate contract number
        $contract_number = "RCN-{$year}-{$prefix}{$sequence}-{$overall_sequence}";
        
        // Debug log
        error_log("Generated Contract Number: $contract_number for $classification client");
        
        // Get contract dates
        $contract_start = !empty($_POST['contract_start']) ? "'" . $conn->real_escape_string($_POST['contract_start']) . "'" : 'NULL';
        $contract_end = !empty($_POST['contract_end']) ? "'" . $conn->real_escape_string($_POST['contract_end']) . "'" : 'NULL';
        
        // Insert contract
        $has_colored = $_POST['has_colored_machines'];
        $color_rate = ($has_colored == 'YES' && !empty($_POST['color_rate'])) ? floatval($_POST['color_rate']) : 'NULL';
        $excess_colorrate = ($has_colored == 'YES' && !empty($_POST['excess_colorrate'])) ? floatval($_POST['excess_colorrate']) : 'NULL';
        $mincopies_color = ($has_colored == 'YES' && !empty($_POST['mincopies_color'])) ? intval($_POST['mincopies_color']) : 'NULL';
        $collection_date = ($classification == 'GOVERNMENT' && !empty($_POST['collection_date'])) ? intval($_POST['collection_date']) : 'NULL';
        // Get minimum monthly charge
        $minimum_monthly_charge = !empty($_POST['minimum_monthly_charge']) ? floatval($_POST['minimum_monthly_charge']) : 'NULL';

        // In the INSERT statement, add minimum_monthly_charge field
        $contract_sql = "INSERT INTO rental_contracts (
            contract_number, contract_start, contract_end, client_id, type_of_contract, has_colored_machines,
            mono_rate, color_rate, excess_monorate, excess_colorrate,
            mincopies_mono, mincopies_color, spoilage, minimum_monthly_charge, collection_processing_period,
            collection_date, vatable, status, datecreated, createdby
        ) VALUES (
            '$contract_number', $contract_start, $contract_end, $client_id, '{$_POST['type_of_contract']}', '$has_colored',
            '{$_POST['mono_rate']}', $color_rate, '{$_POST['excess_monorate']}', $excess_colorrate,
            '{$_POST['mincopies_mono']}', $mincopies_color, '{$_POST['spoilage']}', $minimum_monthly_charge, '{$_POST['collection_processing_period']}',
            $collection_date, '{$_POST['vatable']}', 'ACTIVE', NOW(), NULL
        )";
        
        if (!$conn->query($contract_sql)) {
            throw new Exception("Error inserting contract: " . $conn->error);
        }
        
        $contract_id = $conn->insert_id;
        
        // Handle file upload
        if (!empty($_FILES['contract_files']['name'][0])) {
            $upload_dir = 'uploads/contracts/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $uploaded_files = [];
            foreach ($_FILES['contract_files']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['contract_files']['error'][$key] == 0) {
                    // Validate file type
                    $file_type = strtolower(pathinfo($_FILES['contract_files']['name'][$key], PATHINFO_EXTENSION));
                    if ($file_type != 'pdf') {
                        throw new Exception("Only PDF files are allowed.");
                    }
                    
                    // Generate unique filename
                    $file_name = time() . '_' . uniqid() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "_", $_FILES['contract_files']['name'][$key]);
                    $file_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($tmp_name, $file_path)) {
                        $uploaded_files[] = $file_path;
                    }
                }
            }
            
            if (!empty($uploaded_files)) {
                $conn->query("UPDATE rental_contracts SET contract_file = '" . implode(',', $uploaded_files) . "' WHERE id = $contract_id");
            }
        }
        
        $conn->commit();
        
        // Redirect to add machine details
        header("Location: r-add_contract_machines.php?contract_id=$contract_id&type=" . urlencode($_POST['type_of_contract']) . "&client_id=$client_id");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error: " . $e->getMessage();
        error_log("Contract creation error: " . $e->getMessage());
    }
}

// Get client data for search
$clients = [];
if (isset($_GET['search'])) {
    $search = $conn->real_escape_string($_GET['search']);
    $result = $conn->query("
        SELECT id, company_name, main_signatory, classification 
        FROM rental_clients  
        WHERE company_name LIKE '%$search%' OR main_signatory LIKE '%$search%'
        LIMIT 10
    ");
    while ($row = $result->fetch_assoc()) {
        $clients[] = $row;
    }
    header('Content-Type: application/json');
    echo json_encode($clients);
    exit;
}

// Function to get next contract number via AJAX
function getNextContractNumber($conn, $classification, $year = null) {
    if (!$year) $year = date('Y');
    $prefix = ($classification == 'GOVERNMENT') ? 'G' : 'P';
    
    $seq_query = $conn->query("
        SELECT COUNT(*) as count 
        FROM rental_contracts 
        WHERE contract_number LIKE 'RCN-{$year}-{$prefix}%'
        AND YEAR(datecreated) = {$year}
    ");
    $seq_data = $seq_query->fetch_assoc();
    $sequence = str_pad($seq_data['count'] + 1, 3, '0', STR_PAD_LEFT);
    
    $total_query = $conn->query("SELECT COUNT(*) as total FROM rental_contracts");
    $total_data = $total_query->fetch_assoc();
    $overall_sequence = str_pad($total_data['total'] + 1, 6, '0', STR_PAD_LEFT);
    
    return "RCN-{$year}-{$prefix}{$sequence}-{$overall_sequence}";
}

// Handle AJAX request for contract number
if (isset($_GET['get_contract_number']) && isset($_GET['classification'])) {
    $classification = $_GET['classification'];
    $year = isset($_GET['year']) ? $_GET['year'] : date('Y');
    $contract_number = getNextContractNumber($conn, $classification, $year);
    header('Content-Type: application/json');
    echo json_encode(['contract_number' => $contract_number]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Contract</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f4f6f9; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h2 { color: #2c3e50; margin-bottom: 30px; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #34495e; }
        input[type="text"], input[type="number"], input[type="date"], select, textarea {
            width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px;
        }
        input[type="text"]:focus, input[type="number"]:focus, input[type="date"]:focus, select:focus {
            border-color: #3498db; outline: none; box-shadow: 0 0 5px rgba(52,152,219,0.3);
        }
        .client-search { position: relative; }
        .search-results {
            position: absolute; width: 100%; max-height: 200px; overflow-y: auto;
            background: white; border: 1px solid #ddd; border-top: none; border-radius: 0 0 5px 5px;
            display: none; z-index: 1000;
        }
        .search-item {
            padding: 10px; cursor: pointer; border-bottom: 1px solid #eee;
        }
        .search-item:hover { background: #f8f9fa; }
        .btn-submit {
            background: #3498db; color: white; padding: 12px 30px; border: none;
            border-radius: 5px; font-size: 16px; cursor: pointer; transition: background 0.3s;
        }
        .btn-submit:hover { background: #2980b9; }
        .hidden-field { display: none; }
        .error { color: #e74c3c; font-size: 13px; margin-top: 5px; }
        .success { color: #27ae60; text-align: center; padding: 10px; background: #d4edda; border-radius: 5px; }
        .required:after { content: " *"; color: #e74c3c; }
        .info-text { font-size: 12px; color: #7f8c8d; margin-top: 5px; }
        .date-range {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #3498db;
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
        .contract-number-preview {
            background: #e8f5e9;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #27ae60;
            margin-bottom: 20px;
        }
        .contract-number-preview label {
            color: #27ae60;
            margin-bottom: 5px;
        }
        .contract-number-preview input {
            background: white;
            font-family: monospace;
            font-size: 16px;
            font-weight: bold;
            color: #2c3e50;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Add New Contract</h2>
        
        <?php if (isset($error)): ?>
            <div class="error" style="background: #f8d7da; padding: 10px; margin-bottom: 20px;"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST" action="" enctype="multipart/form-data" id="contractForm">
            <!-- Client Search -->
            <div class="form-group">
                <label class="required">Search Client</label>
                <div class="client-search">
                    <input type="text" id="clientSearch" placeholder="Type at least 2 characters to search..." autocomplete="off">
                    <input type="hidden" name="client_id" id="client_id" required>
                    <div id="searchResults" class="search-results"></div>
                </div>
                <div id="selectedClientInfo" style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 5px; display: none;"></div>
            </div>
            
            <!-- Contract Number (Auto-generated) - FIXED: Dynamic preview -->
            <div class="contract-number-preview">
                <label>📋 Contract Number (Auto-generated)</label>
                <input type="text" id="contract_number" value="RCN-<?php echo date('Y'); ?>-G001-000001" readonly style="background: #f8f9fa; font-weight: bold;">
                <div class="info-text">Contract number will be generated based on client type and year</div>
            </div>
            
            <!-- Contract Date Range -->
            <div class="date-range">
                <h3 style="color: #2c3e50; margin-bottom: 15px; font-size: 16px;">📅 Contract Period</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Contract Start Date</label>
                        <input type="date" name="contract_start" id="contract_start" required onchange="validateContractDates()">
                    </div>
                    <div class="form-group">
                        <label class="required">Contract End Date</label>
                        <input type="date" name="contract_end" id="contract_end" required onchange="validateContractDates()">
                    </div>
                </div>
                <div id="dateError" style="color: #e74c3c; font-size: 13px; margin-top: 5px; display: none;"></div>
                <div id="contractDuration" style="font-size: 13px; color: #27ae60; margin-top: 5px; display: none;"></div>
            </div>
            
            <!-- Type of Contract -->
            <div class="form-group">
                <label class="required">Type of Contract</label>
                <select name="type_of_contract" id="type_of_contract" required>
                    <option value="">Select Type</option>
                    <option value="UMBRELLA">UMBRELLA</option>
                    <option value="SINGLE CONTRACT">SINGLE CONTRACT</option>
                </select>
            </div>
            
            <!-- Colored Machines Question -->
            <div class="form-group">
                <label class="required">Does it have colored machines?</label>
                <select name="has_colored_machines" id="has_colored_machines" required>
                    <option value="">Select</option>
                    <option value="YES">YES</option>
                    <option value="NO">NO</option>
                </select>
            </div>
            
            <!-- Rates and Minimum Copies -->
            <div class="form-row">
                <div class="form-group">
                    <label class="required">Mono Rate (₱)</label>
                    <input type="number" step="0.01" name="mono_rate" required>
                </div>
                <div class="form-group">
                    <label class="required">Excess Mono Rate (₱)</label>
                    <input type="number" step="0.01" name="excess_monorate" required>
                </div>
            </div>
            
            <div id="color_rate_group" class="hidden-field">
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Color Rate (₱)</label>
                        <input type="number" step="0.01" name="color_rate">
                    </div>
                    <div class="form-group">
                        <label class="required">Excess Color Rate (₱)</label>
                        <input type="number" step="0.01" name="excess_colorrate">
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="required">Minimum Copies (Mono)</label>
                    <input type="number" name="mincopies_mono" required>
                </div>
                <div id="mincopies_color_group" class="hidden-field" style="flex: 1;">
                    <label class="required">Minimum Copies (Color)</label>
                    <input type="number" name="mincopies_color">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="required">Spoilage (%)</label>
                    <input type="number" step="0.01" name="spoilage" required>
                </div>
                <div class="form-group">
                    <label class="required">Vatable</label>
                    <select name="vatable" required>
                        <option value="">Select</option>
                        <option value="YES">YES</option>
                        <option value="NO">NO</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="required">Spoilage (%)</label>
                    <input type="number" step="0.01" name="spoilage" required>
                </div>
                <div class="form-group">
                    <label>Minimum Monthly Charge (₱)</label>
                    <input type="number" step="0.01" name="minimum_monthly_charge" placeholder="0.00">
                    <div class="info-text">Optional minimum monthly billing amount</div>
                </div>
                <div class="form-group">
                    <label class="required">Vatable</label>
                    <select name="vatable" required>
                        <option value="">Select</option>
                        <option value="YES">YES</option>
                        <option value="NO">NO</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="required">Collection Processing Period (Days)</label>
                    <input type="number" name="collection_processing_period" id="collection_processing_period" required>
                </div>
                <div id="collection_date_group" class="hidden-field">
                    <label class="required">Collection Date</label>
                    <input type="number" name="collection_date" id="collection_date" min="1" max="31">
                    <div class="info-text">Enter day of month (1-31)</div>
                </div>
            </div>
            
            <!-- Contract File Upload -->
            <div class="form-group">
                <label>Upload Contract (PDF) - Optional</label>
                <input type="file" name="contract_files[]" accept=".pdf" multiple>
                <div class="info-text">You can select multiple PDF files (Max 10MB each)</div>
            </div>
            
            <div style="text-align: center; margin-top: 30px;">
                <button type="submit" name="submit_contract" class="btn-submit">Create Contract & Add Machines</button>
            </div>
        </form>
    </div>
    
    <script>
        // Client search functionality
        let searchTimeout;
        const clientSearch = document.getElementById('clientSearch');
        const searchResults = document.getElementById('searchResults');
        
        clientSearch.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const searchTerm = this.value;
            
            if (searchTerm.length >= 2) {
                searchTimeout = setTimeout(() => {
                    fetch(`r-add_contracts.php?search=${encodeURIComponent(searchTerm)}`)
                        .then(response => response.json())
                        .then(data => {
                            searchResults.innerHTML = '';
                            if (data.length > 0) {
                                searchResults.style.display = 'block';
                                data.forEach(client => {
                                    const div = document.createElement('div');
                                    div.className = 'search-item';
                                    div.innerHTML = `<strong>${client.company_name}</strong><br>
                                                    <small>Signatory: ${client.main_signatory} | ${client.classification}</small>`;
                                    div.onclick = () => selectClient(client);
                                    searchResults.appendChild(div);
                                });
                            } else {
                                searchResults.style.display = 'none';
                            }
                        });
                }, 300);
            } else {
                searchResults.style.display = 'none';
            }
        });
        
        // FIXED: Select client function with proper contract number generation
        function selectClient(client) {
            document.getElementById('client_id').value = client.id;
            document.getElementById('clientSearch').value = `${client.company_name} - ${client.main_signatory}`;
            document.getElementById('selectedClientInfo').style.display = 'block';
            document.getElementById('selectedClientInfo').innerHTML = `
                <strong>Selected Client:</strong><br>
                Company: ${client.company_name}<br>
                Signatory: ${client.main_signatory}<br>
                Classification: ${client.classification}
            `;
            searchResults.style.display = 'none';
            
            // Show/hide collection date based on classification
            const collectionDateGroup = document.getElementById('collection_date_group');
            if (client.classification === 'GOVERNMENT') {
                collectionDateGroup.style.display = 'block';
                document.getElementById('collection_date').required = true;
            } else {
                collectionDateGroup.style.display = 'none';
                document.getElementById('collection_date').required = false;
            }
            
            // FIXED: Get actual next contract number from server
            const year = new Date().getFullYear();
            const prefix = client.classification === 'GOVERNMENT' ? 'G' : 'P';
            
            fetch(`r-add_contracts.php?get_contract_number=1&classification=${client.classification}&year=${year}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('contract_number').value = data.contract_number;
                })
                .catch(error => {
                    console.error('Error fetching contract number:', error);
                    // Fallback preview
                    document.getElementById('contract_number').value = `RCN-${year}-${prefix}001-000001`;
                });
        }
        
        // Close search results when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.client-search')) {
                searchResults.style.display = 'none';
            }
        });
        
        // Toggle color-related fields based on has_colored_machines selection
        document.getElementById('has_colored_machines').addEventListener('change', function() {
            const showFields = this.value === 'YES';
            
            document.getElementById('color_rate_group').style.display = showFields ? 'block' : 'none';
            document.getElementById('mincopies_color_group').style.display = showFields ? 'block' : 'none';
            
            document.querySelector('input[name="color_rate"]').required = showFields;
            document.querySelector('input[name="excess_colorrate"]').required = showFields;
            document.querySelector('input[name="mincopies_color"]').required = showFields;
        });
        
        // Validate contract dates
        function validateContractDates() {
            const startDate = document.getElementById('contract_start').value;
            const endDate = document.getElementById('contract_end').value;
            const dateError = document.getElementById('dateError');
            const contractDuration = document.getElementById('contractDuration');
            
            if (startDate && endDate) {
                const start = new Date(startDate);
                const end = new Date(endDate);
                
                if (end < start) {
                    dateError.style.display = 'block';
                    dateError.innerHTML = '⚠️ Contract end date cannot be before start date';
                    contractDuration.style.display = 'none';
                    document.querySelector('button[type="submit"]').disabled = true;
                } else {
                    dateError.style.display = 'none';
                    
                    // Calculate duration
                    const diffTime = Math.abs(end - start);
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                    const diffMonths = Math.floor(diffDays / 30);
                    const remainingDays = diffDays % 30;
                    
                    let durationText = '';
                    if (diffMonths > 0) {
                        durationText += `${diffMonths} month${diffMonths > 1 ? 's' : ''}`;
                    }
                    if (remainingDays > 0) {
                        durationText += `${diffMonths > 0 ? ' and ' : ''}${remainingDays} day${remainingDays > 1 ? 's' : ''}`;
                    }
                    
                    contractDuration.style.display = 'block';
                    contractDuration.innerHTML = `✅ Contract duration: ${durationText}`;
                    document.querySelector('button[type="submit"]').disabled = false;
                }
            }
        }
        
        // Initialize hidden fields
        document.getElementById('color_rate_group').style.display = 'none';
        document.getElementById('mincopies_color_group').style.display = 'none';
        document.getElementById('collection_date_group').style.display = 'none';
        
        // File upload validation
        document.querySelector('input[name="contract_files[]"]').addEventListener('change', function(e) {
            const files = e.target.files;
            for (let i = 0; i < files.length; i++) {
                if (files[i].type !== 'application/pdf') {
                    alert(`File "${files[i].name}" is not a PDF. Only PDF files are allowed.`);
                    this.value = '';
                    return;
                }
                if (files[i].size > 10 * 1024 * 1024) {
                    alert(`File "${files[i].name}" exceeds the 10MB size limit.`);
                    this.value = '';
                    return;
                }
            }
        });
    </script>
</body>
</html>