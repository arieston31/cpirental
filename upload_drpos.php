<?php
require_once 'config.php';
session_start();

$machine_id = isset($_GET['machine_id']) ? intval($_GET['machine_id']) : 0;

if (!$machine_id) {
    die("Machine ID is required.");
}

// Get machine info
$machine_query = $conn->query("
    SELECT cm.*, c.contract_number, cl.company_name 
    FROM contract_machines cm
    JOIN contracts c ON cm.contract_id = c.id
    JOIN clients cl ON cm.client_id = cl.id
    WHERE cm.id = $machine_id
");
$machine = $machine_query->fetch_assoc();

if (!$machine) {
    die("Machine not found.");
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['dr_pos_files'])) {
    $upload_dir = 'uploads/dr_pos/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $uploaded_files = [];
    $existing_files = !empty($machine['dr_pos_files']) ? explode(',', $machine['dr_pos_files']) : [];
    
    // Handle multiple file upload
    foreach ($_FILES['dr_pos_files']['tmp_name'] as $key => $tmp_name) {
        if ($_FILES['dr_pos_files']['error'][$key] == 0) {
            $file_name = time() . '_' . uniqid() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "_", $_FILES['dr_pos_files']['name'][$key]);
            $file_path = $upload_dir . $file_name;
            
            // Check file type
            $file_type = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            if ($file_type != 'pdf') {
                $error = "Only PDF files are allowed.";
                continue;
            }
            
            // Check file size (max 10MB)
            if ($_FILES['dr_pos_files']['size'][$key] > 10 * 1024 * 1024) {
                $error = "File size must be less than 10MB.";
                continue;
            }
            
            if (move_uploaded_file($tmp_name, $file_path)) {
                $uploaded_files[] = $file_path;
            }
        }
    }
    
    if (!empty($uploaded_files)) {
        // Merge existing files with new files
        $all_files = array_merge($existing_files, $uploaded_files);
        $file_list = implode(',', $all_files);
        $file_count = count($all_files);
        
        $update_sql = "UPDATE contract_machines SET 
                       dr_pos_files = '$file_list',
                       dr_pos_file_count = $file_count 
                       WHERE id = $machine_id";
        
        if ($conn->query($update_sql)) {
            $success = count($uploaded_files) . " DR/POS receipt(s) uploaded successfully!";
            // Refresh machine data
            $machine_query = $conn->query("
                SELECT cm.*, c.contract_number, cl.company_name 
                FROM contract_machines cm
                JOIN contracts c ON cm.contract_id = c.id
                JOIN clients cl ON cm.client_id = cl.id
                WHERE cm.id = $machine_id
            ");
            $machine = $machine_query->fetch_assoc();
        } else {
            $error = "Error updating database: " . $conn->error;
        }
    } else {
        $error = $error ?? "No files were uploaded.";
    }
}

// Get existing files
$existing_files = !empty($machine['dr_pos_files']) ? explode(',', $machine['dr_pos_files']) : [];
$file_count = count($existing_files);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload DR/POS Receipts - Machine #<?php echo htmlspecialchars($machine['machine_number']); ?></title>
    <style>
        /* Copy styles from upload_contract_file.php and adapt for DR/POS */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f4f6f9;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        h2 {
            color: #34495e;
            font-size: 18px;
            margin: 20px 0 10px;
            border-bottom: 1px solid #bdc3c7;
            padding-bottom: 8px;
        }
        .machine-info {
            background: #fff8e1;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 30px;
            border-left: 4px solid #e67e22;
        }
        .upload-area {
            border: 2px dashed #e67e22;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            background: #fff8e1;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 30px;
        }
        .upload-area:hover {
            border-color: #d35400;
            background: #ffecb3;
        }
        .upload-area.dragover {
            border-color: #27ae60;
            background: #d4edda;
        }
        .upload-icon {
            font-size: 48px;
            color: #e67e22;
            margin-bottom: 10px;
        }
        .upload-text {
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .upload-subtext {
            font-size: 14px;
            color: #7f8c8d;
        }
        .file-list {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            background: white;
            border-radius: 6px;
            margin-bottom: 8px;
            border-left: 4px solid #e67e22;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .file-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .file-icon {
            font-size: 24px;
            color: #e67e22;
        }
        .file-name {
            font-weight: 600;
            color: #2c3e50;
        }
        .file-size {
            font-size: 11px;
            color: #7f8c8d;
            margin-left: 10px;
        }
        .file-actions {
            display: flex;
            gap: 8px;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary { background: #e67e22; color: white; }
        .btn-primary:hover { background: #d35400; }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #229954; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-secondary { background: #95a5a6; color: white; }
        .btn-secondary:hover { background: #7f8c8d; }
        .message {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .selected-files {
            margin-top: 20px;
            padding: 15px;
            background: #e8f5e9;
            border-radius: 6px;
            display: none;
        }
        .selected-files.show {
            display: block;
        }
        .progress {
            width: 100%;
            height: 4px;
            background: #ecf0f1;
            border-radius: 2px;
            margin-top: 10px;
            overflow: hidden;
            display: none;
        }
        .progress-bar {
            width: 0%;
            height: 100%;
            background: #27ae60;
            transition: width 0.3s;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        .file-count-badge {
            display: inline-block;
            background: #e67e22;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            🧾 Upload DR/POS Receipts
            <span class="file-count-badge"><?php echo $file_count; ?> file(s)</span>
        </h1>
        
        <div class="machine-info">
            <strong>Contract:</strong> <?php echo htmlspecialchars($machine['contract_number']); ?><br>
            <strong>Client:</strong> <?php echo htmlspecialchars($machine['company_name']); ?><br>
            <strong>Machine #:</strong> <?php echo htmlspecialchars($machine['machine_number']); ?> | 
            <strong>Serial:</strong> <?php echo htmlspecialchars($machine['machine_serial_number']); ?><br>
            <strong>Location:</strong> <?php echo htmlspecialchars($machine['barangay'] . ', ' . $machine['city']); ?>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="message success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="message error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Existing Files -->
        <?php if (!empty($existing_files)): ?>
            <h2>📑 Existing DR/POS Receipts</h2>
            <div class="file-list">
                <?php foreach ($existing_files as $index => $file): 
                    $file_name = basename($file);
                    $file_size = file_exists($file) ? round(filesize($file) / 1024, 1) . ' KB' : 'Unknown';
                ?>
                    <div class="file-item">
                        <div class="file-info">
                            <span class="file-icon">🧾</span>
                            <div>
                                <span class="file-name"><?php echo htmlspecialchars($file_name); ?></span>
                                <span class="file-size"><?php echo $file_size; ?></span>
                            </div>
                        </div>
                        <div class="file-actions">
                            <button onclick="window.open('<?php echo $file; ?>', '_blank')" class="btn" style="padding: 6px 12px; font-size: 12px; background: #e67e22;">
                                👁️ View
                            </button>
                            <a href="<?php echo $file; ?>" download class="btn" style="padding: 6px 12px; font-size: 12px; background: #27ae60;">
                                ⬇️ Download
                            </a>
                            <a href="delete_drpos.php?machine_id=<?php echo $machine_id; ?>&file=<?php echo urlencode($file); ?>" 
                               class="btn" style="padding: 6px 12px; font-size: 12px; background: #e74c3c;"
                               onclick="return confirm('Are you sure you want to delete this receipt?')">
                                🗑️ Delete
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Upload Form -->
        <form id="uploadForm" method="POST" enctype="multipart/form-data">
            <h2>➕ Add New Receipts</h2>
            
            <div id="uploadArea" class="upload-area">
                <div class="upload-icon">🧾</div>
                <div class="upload-text">Click or drag PDF files to upload</div>
                <div class="upload-subtext">Supported format: PDF only (Max 10MB per file)</div>
                <input type="file" name="dr_pos_files[]" id="fileInput" accept=".pdf" multiple style="display: none;">
            </div>
            
            <div id="selectedFiles" class="selected-files">
                <h4 style="margin-bottom: 10px; color: #2c3e50;">📋 Files Ready to Upload:</h4>
                <div id="fileList"></div>
            </div>
            
            <div class="progress" id="progressContainer">
                <div class="progress-bar" id="progressBar"></div>
            </div>
            
            <div class="action-buttons">
                <a href="view_machines.php?contract_id=<?php echo $machine['contract_id']; ?>" class="btn btn-secondary">← Back to Machines</a>
                <button type="submit" class="btn btn-success" id="uploadBtn">📤 Upload Receipts</button>
            </div>
        </form>
    </div>
    
    <script>
        // Upload area click handler
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('fileInput');
        const selectedFilesDiv = document.getElementById('selectedFiles');
        const fileListDiv = document.getElementById('fileList');
        const uploadForm = document.getElementById('uploadForm');
        const progressContainer = document.getElementById('progressContainer');
        const progressBar = document.getElementById('progressBar');
        const uploadBtn = document.getElementById('uploadBtn');
        
        uploadArea.addEventListener('click', function() {
            fileInput.click();
        });
        
        uploadArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });
        
        uploadArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
        });
        
        uploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            fileInput.files = e.dataTransfer.files;
            handleFileSelect(e.dataTransfer.files);
        });
        
        fileInput.addEventListener('change', function(e) {
            handleFileSelect(this.files);
        });
        
        function handleFileSelect(files) {
            if (files.length > 0) {
                let html = '';
                let validCount = 0;
                
                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    
                    // Validate file type
                    if (file.type !== 'application/pdf') {
                        alert(`File "${file.name}" is not a PDF. Only PDF files are allowed.`);
                        continue;
                    }
                    
                    // Validate file size
                    if (file.size > 10 * 1024 * 1024) {
                        alert(`File "${file.name}" exceeds the 10MB size limit.`);
                        continue;
                    }
                    
                    validCount++;
                    const fileSize = (file.size / 1024).toFixed(1) + ' KB';
                    html += `
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px; background: white; border-radius: 4px; margin-bottom: 5px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span style="color: #e67e22;">🧾</span>
                                <span style="font-weight: 500;">${file.name}</span>
                                <span style="font-size: 11px; color: #7f8c8d;">${fileSize}</span>
                            </div>
                            <span style="color: #27ae60;">✓ Ready</span>
                        </div>
                    `;
                }
                
                if (validCount > 0) {
                    fileListDiv.innerHTML = html;
                    selectedFilesDiv.classList.add('show');
                } else {
                    selectedFilesDiv.classList.remove('show');
                }
            } else {
                selectedFilesDiv.classList.remove('show');
            }
        }
        
        uploadForm.addEventListener('submit', function(e) {
            const files = fileInput.files;
            if (files.length === 0) {
                e.preventDefault();
                alert('Please select at least one file to upload.');
                return;
            }
            
            // Show progress bar
            progressContainer.style.display = 'block';
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '⏳ Uploading...';
            
            let progress = 0;
            const interval = setInterval(() => {
                progress += 10;
                progressBar.style.width = progress + '%';
                if (progress >= 90) {
                    clearInterval(interval);
                }
            }, 200);
        });
    </script>
</body>
</html>