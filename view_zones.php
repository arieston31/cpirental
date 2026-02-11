<?php
require_once 'config.php';

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$min_machines = isset($_GET['min_machines']) ? intval($_GET['min_machines']) : 0;

// Get all zones with statistics
$query = "SELECT 
            z.*,
            COUNT(DISTINCT cm.id) as machine_count,
            COUNT(DISTINCT c.id) as contract_count,
            COUNT(DISTINCT CASE WHEN cm.reading_date_remarks = 'aligned reading date' THEN cm.id END) as aligned_count,
            COUNT(DISTINCT CASE WHEN cm.reading_date_remarks = 'mis-aligned reading date' THEN cm.id END) as misaligned_count,
            COUNT(DISTINCT cl.id) as client_count
          FROM zoning_zone z
          LEFT JOIN contract_machines cm ON z.id = cm.zone_id AND cm.status = 'ACTIVE'
          LEFT JOIN contracts c ON cm.contract_id = c.id AND c.status = 'ACTIVE'
          LEFT JOIN clients cl ON c.client_id = cl.id AND cl.status = 'ACTIVE'
          WHERE 1=1";

$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (z.area_center LIKE ? OR z.zone_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if ($min_machines > 0) {
    $query .= " HAVING machine_count >= ?";
    $params[] = $min_machines;
    $types .= "i";
}

$query .= " GROUP BY z.id ORDER BY z.zone_number ASC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$zones = $stmt->get_result();

// Get overall statistics
$total_zones = $conn->query("SELECT COUNT(*) as count FROM zoning_zone")->fetch_assoc()['count'];
$total_machines_in_zones = $conn->query("
    SELECT COUNT(*) as count 
    FROM contract_machines 
    WHERE zone_id IS NOT NULL AND status = 'ACTIVE'
")->fetch_assoc()['count'];
$total_aligned = $conn->query("
    SELECT COUNT(*) as count 
    FROM contract_machines 
    WHERE reading_date_remarks = 'aligned reading date' AND status = 'ACTIVE'
")->fetch_assoc()['count'];
$total_misaligned = $conn->query("
    SELECT COUNT(*) as count 
    FROM contract_machines 
    WHERE reading_date_remarks = 'mis-aligned reading date' AND status = 'ACTIVE'
")->fetch_assoc()['count'];

// Get zone with highest machine count
$top_zone = $conn->query("
    SELECT z.zone_number, z.area_center, COUNT(cm.id) as machine_count
    FROM zoning_zone z
    LEFT JOIN contract_machines cm ON z.id = cm.zone_id AND cm.status = 'ACTIVE'
    GROUP BY z.id
    ORDER BY machine_count DESC
    LIMIT 1
")->fetch_assoc();

// Get all zones for map data (JSON)
$map_zones = $conn->query("SELECT * FROM zoning_zone WHERE latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY zone_number");
$map_data = [];
while($zone = $map_zones->fetch_assoc()) {
    $zone['machine_count'] = $conn->query("
        SELECT COUNT(*) as count 
        FROM contract_machines 
        WHERE zone_id = {$zone['id']} AND status = 'ACTIVE'
    ")->fetch_assoc()['count'];
    $map_data[] = $zone;
}
$map_data_json = json_encode($map_data);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zone Management - CPI Rental</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-fullscreen/dist/leaflet.fullscreen.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-fullscreen/dist/Leaflet.fullscreen.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f0f2f5; 
            padding: 20px;
        }
        .container { max-width: auto; margin: 0 auto; }
        
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
        
        /* Summary Cards */
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
            position: relative;
            overflow: hidden;
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
        .card-orange::before { background: #e67e22; }
        .card-purple::before { background: #9b59b6; }
        
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
        
        /* Map Section */
        .map-section {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .map-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f2f5;
        }
        .map-title {
            color: #2c3e50;
            font-size: 18px;
            font-weight: 600;
        }
        .map-controls {
            display: flex;
            gap: 10px;
        }
        .btn-map {
            background: #3498db;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: background 0.3s;
        }
        .btn-map:hover {
            background: #2980b9;
        }
        .btn-map-fullscreen {
            background: #2c3e50;
        }
        .btn-map-fullscreen:hover {
            background: #34495e;
        }
        #zoneMap {
            height: 450px;
            width: 100%;
            border-radius: 10px;
            z-index: 1;
        }
        
        /* Fullscreen Modal */
        .modal {
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
        .modal-content {
            position: relative;
            width: 100%;
            height: 100%;
            padding: 20px;
        }
        .modal-header {
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
        .modal-title {
            font-size: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .modal-close {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: background 0.3s;
        }
        .modal-close:hover {
            background: #c0392b;
        }
        #fullscreenMap {
            width: 100%;
            height: 100%;
        }
        
        /* Filter Section */
        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
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
            padding: 10px 25px;
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
        
        /* Zones Grid */
        .zones-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .zone-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            border-left: 5px solid;
        }
        .zone-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .zone-header {
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            position: relative;
        }
        .zone-header.zone-1 { background: linear-gradient(135deg, #3498db, #2980b9); }
        .zone-header.zone-2 { background: linear-gradient(135deg, #2ecc71, #27ae60); }
        .zone-header.zone-3 { background: linear-gradient(135deg, #f1c40f, #f39c12); }
        .zone-header.zone-4 { background: linear-gradient(135deg, #e67e22, #d35400); }
        .zone-header.zone-5 { background: linear-gradient(135deg, #e74c3c, #c0392b); }
        .zone-header.zone-6 { background: linear-gradient(135deg, #9b59b6, #8e44ad); }
        .zone-header.zone-7 { background: linear-gradient(135deg, #1abc9c, #16a085); }
        .zone-header.zone-8 { background: linear-gradient(135deg, #34495e, #2c3e50); }
        .zone-header.zone-9 { background: linear-gradient(135deg, #95a5a6, #7f8c8d); }
        .zone-header.zone-10 { background: linear-gradient(135deg, #d35400, #e67e22); }
        .zone-header.zone-11 { background: linear-gradient(135deg, #27ae60, #229954); }
        .zone-header.zone-12 { background: linear-gradient(135deg, #2980b9, #3498db); }
        
        .zone-number {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .zone-area {
            font-size: 16px;
            opacity: 0.9;
        }
        .zone-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.2);
            padding: 5px 15px;
            border-radius: 25px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .zone-body {
            padding: 20px;
        }
        
        .stat-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #ecf0f1;
        }
        .stat-label {
            color: #7f8c8d;
            font-size: 14px;
        }
        .stat-value {
            font-weight: 600;
            color: #2c3e50;
            font-size: 16px;
        }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #ecf0f1;
            border-radius: 4px;
            margin: 10px 0;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: #27ae60;
            border-radius: 4px;
            transition: width 0.3s;
        }
        .progress-fill.warning { background: #f39c12; }
        .progress-fill.danger { background: #e74c3c; }
        
        .coordinate-info {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 8px;
            margin-top: 15px;
            font-size: 13px;
            color: #34495e;
        }
        
        .machine-distribution {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .machine-stat {
            flex: 1;
            text-align: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .machine-stat .count {
            font-size: 20px;
            font-weight: bold;
            color: #2c3e50;
        }
        .machine-stat .label {
            font-size: 11px;
            color: #7f8c8d;
            margin-top: 3px;
        }
        
        .aligned-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
        }
        .aligned { background: #d4edda; color: #155724; }
        .misaligned { background: #fff3cd; color: #856404; }
        
        /* Reading Date Info */
        .reading-date-info {
            background: #e3f2fd;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        .reading-date-value {
            font-size: 24px;
            font-weight: bold;
            color: #1976d2;
            text-align: center;
        }
        
        /* Zone Legend */
        .zone-legend {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-top: 30px;
        }
        .legend-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .legend-marker {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .zones-grid { grid-template-columns: 1fr; }
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            .modal-header { flex-direction: column; gap: 10px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>
                🗺️ Zone Management
                <span style="font-size: 16px; background: #e3f2fd; padding: 5px 15px; border-radius: 25px; color: #1976d2;">
                    <?php echo $total_zones; ?> Total Zones
                </span>
            </h1>
            <div>
                <a href="dashboard.php" class="btn btn-primary">📊 Dashboard</a>
                <a href="view_contracts.php" class="btn btn-success">📋 Contracts</a>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-grid">
            <div class="summary-card card-blue">
                <div class="card-title">Total Zones</div>
                <div class="card-number"><?php echo $total_zones; ?></div>
                <div class="card-sub">Metro Manila Coverage</div>
                <div class="card-icon">📍</div>
            </div>
            <div class="summary-card card-green">
                <div class="card-title">Active Machines</div>
                <div class="card-number"><?php echo $total_machines_in_zones; ?></div>
                <div class="card-sub">Across all zones</div>
                <div class="card-icon">🖨️</div>
            </div>
            <div class="summary-card card-purple">
                <div class="card-title">Alignment Rate</div>
                <div class="card-number">
                    <?php echo $total_machines_in_zones > 0 ? round(($total_aligned / $total_machines_in_zones) * 100, 1) : 0; ?>%
                </div>
                <div class="card-sub">
                    <span style="color: #27ae60;">✓ <?php echo $total_aligned; ?> Aligned</span> | 
                    <span style="color: #e67e22;">⚠️ <?php echo $total_misaligned; ?> Misaligned</span>
                </div>
                <div class="card-icon">📊</div>
            </div>
            <div class="summary-card card-orange">
                <div class="card-title">Top Zone</div>
                <div class="card-number">Zone <?php echo $top_zone['zone_number'] ?? 'N/A'; ?></div>
                <div class="card-sub"><?php echo $top_zone['machine_count'] ?? 0; ?> Machines</div>
                <div class="card-icon">🏆</div>
            </div>
        </div>

        <!-- Map Section with Fullscreen Button -->
        <div class="map-section">
            <div class="map-header">
                <span class="map-title">📍 Zone Map - Metro Manila</span>
                <div class="map-controls">
                    <button onclick="openFullscreenMap()" class="btn-map btn-map-fullscreen">
                        <span style="font-size: 16px;">⛶</span> View Fullscreen Map
                    </button>
                </div>
            </div>
            <div id="zoneMap"></div>
            <div style="margin-top: 15px; display: flex; gap: 20px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 5px;">
                    <span style="display: inline-block; width: 12px; height: 12px; background: #3498db; border-radius: 50%;"></span>
                    <span style="font-size: 13px;">Zone Centers</span>
                </div>
                <div style="display: flex; align-items: center; gap: 5px;">
                    <span style="font-size: 13px;"><strong>Click on markers</strong> to view zone details</span>
                </div>
            </div>
        </div>

        <!-- Fullscreen Map Modal -->
        <div id="fullscreenModal" class="modal">
            <div class="modal-header">
                <div class="modal-title">
                    <span style="font-size: 24px;">🗺️</span> Metro Manila Zone Map - Fullscreen View
                </div>
                <button onclick="closeFullscreenMap()" class="modal-close">
                    <span style="font-size: 16px;">✕</span> Close Map
                </button>
            </div>
            <div class="modal-content">
                <div id="fullscreenMap"></div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <div style="margin-bottom: 15px;">
                <span class="map-title">🔍 Filter Zones</span>
            </div>
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <label>Search Zone</label>
                    <input type="text" name="search" placeholder="Zone number or area..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-group">
                    <label>Minimum Machines</label>
                    <select name="min_machines">
                        <option value="0">All</option>
                        <option value="1" <?php echo $min_machines == 1 ? 'selected' : ''; ?>>1+ Machines</option>
                        <option value="5" <?php echo $min_machines == 5 ? 'selected' : ''; ?>>5+ Machines</option>
                        <option value="10" <?php echo $min_machines == 10 ? 'selected' : ''; ?>>10+ Machines</option>
                        <option value="20" <?php echo $min_machines == 20 ? 'selected' : ''; ?>>20+ Machines</option>
                    </select>
                </div>
                <div class="filter-group" style="display: flex; gap: 10px; align-items: center;">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="view_zones.php" class="btn" style="background: #95a5a6; color: white;">Clear</a>
                </div>
            </form>
        </div>

        <!-- Zones Grid -->
        <div class="zones-grid">
            <?php if ($zones->num_rows > 0): ?>
                <?php while($zone = $zones->fetch_assoc()): 
                    $alignment_rate = $zone['machine_count'] > 0 ? round(($zone['aligned_count'] / $zone['machine_count']) * 100, 1) : 0;
                    $zone_class = 'zone-' . min($zone['zone_number'], 12);
                ?>
                    <div class="zone-card">
                        <div class="zone-header <?php echo $zone_class; ?>">
                            <div class="zone-number">Zone <?php echo $zone['zone_number']; ?></div>
                            <div class="zone-area"><?php echo htmlspecialchars($zone['area_center']); ?></div>
                            <div class="zone-badge">
                                Reading Day: <?php echo $zone['reading_date']; ?>
                            </div>
                        </div>
                        
                        <div class="zone-body">
                            <!-- Machine Statistics -->
                            <div class="machine-distribution">
                                <div class="machine-stat">
                                    <div class="count"><?php echo $zone['machine_count']; ?></div>
                                    <div class="label">Total Machines</div>
                                </div>
                                <div class="machine-stat">
                                    <div class="count" style="color: #27ae60;"><?php echo $zone['aligned_count']; ?></div>
                                    <div class="label">
                                        <span class="aligned-badge aligned">Aligned</span>
                                    </div>
                                </div>
                                <div class="machine-stat">
                                    <div class="count" style="color: #e67e22;"><?php echo $zone['misaligned_count']; ?></div>
                                    <div class="label">
                                        <span class="aligned-badge misaligned">Misaligned</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Alignment Progress Bar -->
                            <div style="margin-top: 15px;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                    <span style="font-size: 13px; color: #7f8c8d;">Alignment Rate</span>
                                    <span style="font-weight: 600; color: <?php echo $alignment_rate >= 80 ? '#27ae60' : ($alignment_rate >= 50 ? '#f39c12' : '#e74c3c'); ?>;">
                                        <?php echo $alignment_rate; ?>%
                                    </span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill <?php echo $alignment_rate >= 80 ? '' : ($alignment_rate >= 50 ? 'warning' : 'danger'); ?>" 
                                         style="width: <?php echo $alignment_rate; ?>%;"></div>
                                </div>
                            </div>

                            <!-- Statistics -->
                            <div style="margin-top: 20px;">
                                <div class="stat-row">
                                    <span class="stat-label">📋 Active Contracts</span>
                                    <span class="stat-value"><?php echo $zone['contract_count']; ?></span>
                                </div>
                                <div class="stat-row">
                                    <span class="stat-label">🏢 Active Clients</span>
                                    <span class="stat-value"><?php echo $zone['client_count']; ?></span>
                                </div>
                                <div class="stat-row">
                                    <span class="stat-label">📊 Machines per Contract</span>
                                    <span class="stat-value">
                                        <?php echo $zone['contract_count'] > 0 ? round($zone['machine_count'] / $zone['contract_count'], 1) : 0; ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Reading Date Information -->
                            <div class="reading-date-info">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <span style="font-weight: 600; color: #2c3e50;">📅 Reading Schedule</span>
                                        <div style="font-size: 12px; color: #7f8c8d; margin-top: 5px;">
                                            Fixed reading date for this zone
                                        </div>
                                    </div>
                                    <div class="reading-date-value">
                                        Day <?php echo $zone['reading_date']; ?>
                                    </div>
                                </div>
                                <div style="margin-top: 10px; font-size: 13px; color: #34495e;">
                                    <strong>Formula:</strong> Zone <?php echo $zone['zone_number']; ?> + 2 = Day <?php echo $zone['reading_date']; ?>
                                </div>
                            </div>

                            <!-- Coordinates -->
                            <div class="coordinate-info">
                                <div style="display: flex; gap: 15px;">
                                    <div>
                                        <span style="font-weight: 600;">Latitude:</span><br>
                                        <span style="font-family: monospace;"><?php echo $zone['latitude']; ?></span>
                                    </div>
                                    <div>
                                        <span style="font-weight: 600;">Longitude:</span><br>
                                        <span style="font-family: monospace;"><?php echo $zone['longitude']; ?></span>
                                    </div>
                                </div>
                                <?php if($zone['latitude'] && $zone['longitude']): ?>
                                <div style="margin-top: 10px; text-align: right;">
                                    <button onclick="centerMapOnZone(<?php echo $zone['latitude']; ?>, <?php echo $zone['longitude']; ?>, <?php echo $zone['zone_number']; ?>)" 
                                            class="btn-map" style="padding: 5px 10px; font-size: 12px;">
                                        📍 Show on Map
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Action Buttons -->
                            <div style="display: flex; gap: 10px; margin-top: 20px;">
                                <a href="view_machines.php?zone=<?php echo $zone['zone_number']; ?>" 
                                   style="flex: 1; background: #3498db; color: white; text-align: center; padding: 10px; border-radius: 8px; text-decoration: none; font-size: 13px; font-weight: 600;">
                                    View Machines
                                </a>
                                <a href="edit_zone.php?id=<?php echo $zone['id']; ?>" 
                                   style="flex: 1; background: #f39c12; color: white; text-align: center; padding: 10px; border-radius: 8px; text-decoration: none; font-size: 13px; font-weight: 600;">
                                    Edit Zone
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="grid-column: 1/-1; text-align: center; padding: 60px; background: white; border-radius: 15px;">
                    <span style="font-size: 48px;">🗺️</span>
                    <h3 style="color: #2c3e50; margin-top: 20px; margin-bottom: 10px;">No Zones Found</h3>
                    <p style="color: #7f8c8d; margin-bottom: 20px;">Try adjusting your filters or search criteria.</p>
                    <a href="view_zones.php" class="btn btn-primary">Clear Filters</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Zone Legend -->
        <div class="zone-legend">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <span class="map-title">📋 Zone Reading Schedule Summary</span>
                <span style="color: #7f8c8d;">All zones follow the formula: Zone Number + 2 = Reading Day</span>
            </div>
            <div class="legend-grid">
                <?php 
                $legend_zones = $conn->query("SELECT * FROM zoning_zone ORDER BY zone_number");
                while($legend = $legend_zones->fetch_assoc()): 
                ?>
                    <div class="legend-item">
                        <div class="legend-marker" style="background: linear-gradient(135deg, #3498db, #2980b9);">
                            <?php echo $legend['zone_number']; ?>
                        </div>
                        <div>
                            <div style="font-weight: 600; font-size: 13px;"><?php echo htmlspecialchars(substr($legend['area_center'], 0, 25)); ?></div>
                            <div style="font-size: 11px; color: #7f8c8d;">Reading: Day <?php echo $legend['reading_date']; ?></div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
    </div>

    <script>
        // Zone data from PHP
        const zoneData = <?php echo $map_data_json; ?>;
        let mainMap;
        let fullscreenMap;
        let mainMapMarkers = [];
        let fullscreenMarkers = [];

        // Initialize main map
        document.addEventListener('DOMContentLoaded', function() {
            initMainMap();
        });

        function initMainMap() {
            // Center on Metro Manila
            mainMap = L.map('zoneMap').setView([14.5995, 120.9842], 11);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(mainMap);

            // Add fullscreen control to main map
            L.control.fullscreen({
                position: 'topright',
                title: 'Toggle Fullscreen',
                titleCancel: 'Exit Fullscreen',
                forceSeparateButton: true,
                forcePseudoFullscreen: true
            }).addTo(mainMap);

            // Add zone markers to main map
            addMarkersToMap(mainMap, mainMapMarkers);
        }

        function initFullscreenMap() {
            if (!fullscreenMap) {
                // Center on Metro Manila with wider view
                fullscreenMap = L.map('fullscreenMap').setView([14.5995, 120.9842], 10);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap contributors'
                }).addTo(fullscreenMap);

                // Add fullscreen control
                L.control.fullscreen({
                    position: 'topright',
                    title: 'Toggle Fullscreen',
                    titleCancel: 'Exit Fullscreen'
                }).addTo(fullscreenMap);

                // Add scale control
                L.control.scale({
                    imperial: false,
                    metric: true,
                    position: 'bottomright'
                }).addTo(fullscreenMap);

                // Add zone markers to fullscreen map
                addMarkersToMap(fullscreenMap, fullscreenMarkers);
                
                // Force a resize after map is added to modal
                setTimeout(() => {
                    fullscreenMap.invalidateSize();
                }, 100);
            }
        }

        function addMarkersToMap(map, markerArray) {
            zoneData.forEach(zone => {
                // Create custom icon with zone number
                const icon = L.divIcon({
                    className: 'custom-div-icon',
                    html: `<div style="background: linear-gradient(135deg, #3498db, #2980b9); 
                                 width: 36px; height: 36px; border-radius: 50%; 
                                 border: 3px solid white; box-shadow: 0 2px 10px rgba(0,0,0,0.3);
                                 display: flex; align-items: center; justify-content: center; 
                                 color: white; font-weight: bold; font-size: 14px;">
                            ${zone.zone_number}
                          </div>`,
                    iconSize: [36, 36],
                    iconAnchor: [18, 18],
                    popupAnchor: [0, -18]
                });

                const marker = L.marker([zone.latitude, zone.longitude], { icon }).addTo(map);
                
                // Create popup content
                const popupContent = `
                    <div style="min-width: 250px; padding: 5px;">
                        <h3 style="margin: 0 0 15px 0; color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px;">
                            Zone ${zone.zone_number}
                        </h3>
                        <p style="margin: 10px 0; font-weight: 600; font-size: 16px; color: #2980b9;">
                            ${zone.area_center}
                        </p>
                        <div style="background: #e3f2fd; padding: 12px; border-radius: 8px; margin: 15px 0;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-weight: 600;">📅 Reading Date:</span>
                                <span style="font-size: 20px; font-weight: bold; color: #1976d2;">Day ${zone.reading_date}</span>
                            </div>
                            <div style="margin-top: 8px; font-size: 12px; color: #34495e;">
                                Zone ${zone.zone_number} + 2 = Day ${zone.reading_date}
                            </div>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin: 15px 0;">
                            <div style="text-align: center; flex: 1;">
                                <div style="font-size: 24px; font-weight: bold; color: #2c3e50;">${zone.machine_count || 0}</div>
                                <div style="font-size: 11px; color: #7f8c8d;">Machines</div>
                            </div>
                            <div style="text-align: center; flex: 1;">
                                <div style="font-size: 24px; font-weight: bold; color: #27ae60;">${zone.reading_date}</div>
                                <div style="font-size: 11px; color: #7f8c8d;">Reading Day</div>
                            </div>
                        </div>
                        <div style="background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 15px 0;">
                            <span style="font-weight: 600;">📍 Coordinates:</span><br>
                            <span style="font-family: monospace; font-size: 12px;">
                                ${zone.latitude}, ${zone.longitude}
                            </span>
                        </div>
                        <a href="view_machines.php?zone=${zone.zone_number}" 
                           style="display: block; text-align: center; margin-top: 15px; 
                                  padding: 12px; background: #3498db; color: white; 
                                  text-decoration: none; border-radius: 8px; 
                                  font-weight: 600; transition: background 0.3s;">
                            View Machines in Zone ${zone.zone_number}
                        </a>
                    </div>
                `;
                
                marker.bindPopup(popupContent, {
                    maxWidth: 300,
                    minWidth: 250
                });
                
                markerArray.push(marker);
            });
        }

        function openFullscreenMap() {
            const modal = document.getElementById('fullscreenModal');
            modal.style.display = 'block';
            
            // Initialize fullscreen map if not already initialized
            initFullscreenMap();
            
            // Prevent body scrolling
            document.body.style.overflow = 'hidden';
            
            // Invalidate map size to ensure proper rendering
            setTimeout(() => {
                if (fullscreenMap) {
                    fullscreenMap.invalidateSize();
                }
            }, 200);
        }

        function closeFullscreenMap() {
            const modal = document.getElementById('fullscreenModal');
            modal.style.display = 'none';
            
            // Restore body scrolling
            document.body.style.overflow = 'auto';
        }

        function centerMapOnZone(lat, lng, zoneNumber) {
            // First, open fullscreen map
            openFullscreenMap();
            
            // Wait for map to initialize and then center on zone
            setTimeout(() => {
                if (fullscreenMap) {
                    fullscreenMap.setView([lat, lng], 14);
                    
                    // Find and open the popup for this zone
                    setTimeout(() => {
                        fullscreenMarkers.forEach(marker => {
                            const markerLatLng = marker.getLatLng();
                            if (Math.abs(markerLatLng.lat - lat) < 0.001 && 
                                Math.abs(markerLatLng.lng - lng) < 0.001) {
                                marker.openPopup();
                            }
                        });
                    }, 300);
                }
            }, 500);
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeFullscreenMap();
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (mainMap) {
                mainMap.invalidateSize();
            }
            if (fullscreenMap && document.getElementById('fullscreenModal').style.display === 'block') {
                fullscreenMap.invalidateSize();
            }
        });
    </script>
</body>
</html>