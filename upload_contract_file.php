<?php
require_once 'config.php';
session_start();

$contract_id = isset($_GET['contract_id']) ? intval($_GET['contract_id']) : 0;

if (!$contract_id) {
    die("Contract ID is required.");
}

// Get contract info
$contract_query = $conn->query("
    SELECT contract_number, company_name, contract_file 
    FROM contracts c
    JOIN clients cl ON c.client_id = cl.id
    WHERE c.id = $contract_id
");
$contract = $contract_query->fetch_assoc();

if (!$contract) {
    die("Contract not found.");
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['contract_files'])) {
    $upload_dir = 'uploads/contracts/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $uploaded_files = [];
    $existing_files = !empty($contract['contract_file']) ? explode(',', $contract['contract_file']) : [];
    
    // Handle multiple file upload
    foreach ($_FILES['contract_files']['tmp_name'] as $key => $tmp_name) {
        if ($_FILES['contract_files']['error'][$key] == 0) {
            $file_name = time() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "_", $_FILES['contract_files']['name'][$key]);
            $file_path = $upload_dir . $file_name;
            
            // Check file type
            $file_type = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            if ($file_type != 'pdf') {
                $error = "Only PDF files are allowed.";
                continue;
            }
            
            // Check file size (max 10MB)
            if ($_FILES['contract_files']['size'][$key] > 10 * 1024 * 1024) {
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
        
        $update_sql = "UPDATE contracts SET contract_file = '$file_list' WHERE id = $contract_id";
        if ($conn->query($update_sql)) {
            $success = count($uploaded_files) . " file(s) uploaded successfully!";
            // Refresh contract data
            $contract_query = $conn->query("
                SELECT contract_number, company_name, contract_file 
                FROM contracts c
                JOIN clients cl ON c.client_id = cl.id
                WHERE c.id = $contract_id
            ");
            $contract = $contract_query->fetch_assoc();
        } else {
            $error = "Error updating database: " . $conn->error;
        }
    } else {
        $error = $error ?? "No files were uploaded.";
    }
}

// Get existing files
$existing_files = !empty($contract['contract_file']) ? explode(',', $contract['contract_file']) : [];
$file_count = count($existing_files);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Contract Files - <?php echo htmlspecialchars($contract['contract_number']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
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
        
        h1 { color: #2c3e50; margin-bottom: 10px; display: flex; align-items: center; gap: 10px; }
        h2 { color: #34495e; font-size: 18px; margin: 20px 0 10px; border-bottom: 1px solid #bdc3c7; padding-bottom: 8px; }
        
        .contract-info {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 30px;
            border-left: 4px solid #3498db;
        }
        
        .upload-area {
            border: 2px dashed #3498db;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 30px;
        }
        
        .upload-area:hover {
            border-color: #27ae60;
            background: #e8f5e9;
        }
        
        .upload-area.dragover {
            border-color: #27ae60;
            background: #d4edda;
        }
        
        .upload-icon {
            font-size: 48px;
            color: #3498db;
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
            border-left: 4px solid #3498db;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .file-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .file-icon {
            font-size: 24px;
            color: #e74c3c;
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
        
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #229954; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-warning:hover { background: #e67e22; }
        .btn-secondary { background: #95a5a6; color: white; }
        .btn-secondary:hover { background: #7f8c8d; }
        
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
        
        @media (max-width: 768px) {
            .file-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .file-actions {
                width: 100%;
                justify-content: flex-end;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            📄 Upload Contract Files
            <span style="font-size: 14px; background: #e3f2fd; padding: 5px 15px; border-radius: 25px; color: #1976d2;">
                <?php echo $file_count; ?> file(s)
            </span>
        </h1>
        
        <div class="contract-info">
            <strong>Contract Number:</strong> <?php echo htmlspecialchars($contract['contract_number']); ?><br>
            <strong>Client:</strong> <?php echo htmlspecialchars($contract['company_name']); ?>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="message success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="message error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Existing Files -->
        <?php if (!empty($existing_files)): ?>
            <h2>📑 Existing Contract Files</h2>
            <div class="file-list">
                <?php foreach ($existing_files as $index => $file): 
                    $file_name = basename($file);
                    $file_size = file_exists($file) ? round(filesize($file) / 1024, 1) . ' KB' : 'Unknown';
                ?>
                    <div class="file-item">
                        <div class="file-info">
                            <span class="file-icon">📄</span>
                            <div>
                                <span class="file-name"><?php echo htmlspecialchars($file_name); ?></span>
                                <span class="file-size"><?php echo $file_size; ?></span>
                            </div>
                        </div>
                        <div class="file-actions">
                            <button onclick="window.open('<?php echo $file; ?>', '_blank')" class="btn" style="padding: 6px 12px; font-size: 12px; background: #3498db;">
                                👁️ View
                            </button>
                            <a href="<?php echo $file; ?>" download class="btn" style="padding: 6px 12px; font-size: 12px; background: #27ae60;">
                                ⬇️ Download
                            </a>
                            <a href="delete_contract_file.php?contract_id=<?php echo $contract_id; ?>&file=<?php echo urlencode($file); ?>" 
                               class="btn" style="padding: 6px 12px; font-size: 12px; background: #e74c3c;"
                               onclick="return confirm('Are you sure you want to delete this file?')">
                                🗑️ Delete
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Upload Form -->
        <form id="uploadForm" method="POST" enctype="multipart/form-data">
            <h2>➕ Add New Files</h2>
            
            <div id="uploadArea" class="upload-area">
                <div class="upload-icon">📄</div>
                <div class="upload-text">Click or drag files to upload</div>
                <div class="upload-subtext">Supported format: PDF (Max 10MB per file)</div>
                <input type="file" name="contract_files[]" id="fileInput" accept=".pdf" multiple style="display: none;">
            </div>
            
            <div id="selectedFiles" class="selected-files">
                <h4 style="margin-bottom: 10px; color: #2c3e50;">📋 Files Ready to Upload:</h4>
                <div id="fileList"></div>
            </div>
            
            <div class="progress" id="progressContainer">
                <div class="progress-bar" id="progressBar"></div>
            </div>
            
            <div class="action-buttons">
                <a href="view_contracts.php" class="btn btn-secondary">← Back to Contracts</a>
                <button type="submit" class="btn btn-success" id="uploadBtn">📤 Upload Files</button>
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
        
        // Drag and drop handlers
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
        
        // File select handler
        fileInput.addEventListener('change', function(e) {
            handleFileSelect(this.files);
        });
        
        function handleFileSelect(files) {
            if (files.length > 0) {
                let html = '';
                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    const fileSize = (file.size / 1024).toFixed(1) + ' KB';
                    html += `
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px; background: white; border-radius: 4px; margin-bottom: 5px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span style="color: #e74c3c;">📄</span>
                                <span style="font-weight: 500;">${file.name}</span>
                                <span style="font-size: 11px; color: #7f8c8d;">${fileSize}</span>
                            </div>
                            <span style="color: #27ae60;">✓ Ready</span>
                        </div>
                    `;
                }
                fileListDiv.innerHTML = html;
                selectedFilesDiv.classList.add('show');
            } else {
                selectedFilesDiv.classList.remove('show');
            }
        }
        
        // Form submit handler with progress
        uploadForm.addEventListener('submit', function(e) {
            const files = fileInput.files;
            if (files.length === 0) {
                e.preventDefault();
                alert('Please select at least one file to upload.');
                return;
            }
            
            // Check file types and sizes
            for (let i = 0; i < files.length; i++) {
                if (files[i].type !== 'application/pdf') {
                    e.preventDefault();
                    alert('Only PDF files are allowed.');
                    return;
                }
                if (files[i].size > 10 * 1024 * 1024) {
                    e.preventDefault();
                    alert(`File "${files[i].name}" exceeds the 10MB size limit.`);
                    return;
                }
            }
            
            // Show progress bar
            progressContainer.style.display = 'block';
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '⏳ Uploading...';
            
            // Simulate progress (actual progress would require AJAX)
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