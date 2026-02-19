<?php
require_once 'config.php';

// Get all zones for map and reference
$zones_query = $conn->query("SELECT * FROM rental_zoning_zones WHERE latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY zone_number");
$zones = [];
while ($zone = $zones_query->fetch_assoc()) {
    $zones[] = $zone;
}

// Handle AJAX request for zone checking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_zone'])) {
    header('Content-Type: application/json');
    
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');
    
    if (empty($latitude) || empty($longitude)) {
        echo json_encode(['success' => false, 'error' => 'Both latitude and longitude are required.']);
        exit;
    }
    
    // Validate coordinates
    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        echo json_encode(['success' => false, 'error' => 'Invalid coordinates. Please enter valid numbers.']);
        exit;
    }
    
    $latitude = floatval($latitude);
    $longitude = floatval($longitude);
    
    // Validate range
    if ($latitude < 14.0 || $latitude > 15.0 || $longitude < 120.8 || $longitude > 121.2) {
        echo json_encode([
            'success' => false, 
            'error' => 'Coordinates are outside Metro Manila range.',
            'hint' => 'Metro Manila is approximately between Lat: 14.2-14.8, Long: 120.9-121.1'
        ]);
        exit;
    }
    
    // Function to calculate distance between two points (Haversine formula)
    function calculateDistance($lat1, $lon1, $lat2, $lon2) {
        $earthRadius = 6371;
        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);
        $latDelta = $lat2 - $lat1;
        $lonDelta = $lon2 - $lon1;
        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + cos($lat1) * cos($lat2) * pow(sin($lonDelta / 2), 2)));
        return $angle * $earthRadius;
    }
    
    // Find nearest zone using distance calculation
    $stmt = $conn->prepare("
        SELECT id, zone_number, area_center, reading_date, latitude, longitude,
            (6371 * acos(cos(radians(?)) * cos(radians(latitude)) 
            * cos(radians(longitude) - radians(?)) 
            + sin(radians(?)) * sin(radians(latitude)))) AS distance
        FROM rental_zoning_zones 
        WHERE latitude IS NOT NULL AND longitude IS NOT NULL
        ORDER BY distance ASC 
        LIMIT 1
    ");
    
    if ($stmt) {
        $stmt->bind_param("ddd", $latitude, $longitude, $latitude);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $zone = $result->fetch_assoc();
            $distance = calculateDistance($latitude, $longitude, $zone['latitude'], $zone['longitude']);
            
            // Get all zones with distances for comparison
            $all_zones_query = $conn->prepare("
                SELECT zone_number, area_center, latitude, longitude,
                    (6371 * acos(cos(radians(?)) * cos(radians(latitude)) 
                    * cos(radians(longitude) - radians(?)) 
                    + sin(radians(?)) * sin(radians(latitude)))) AS distance
                FROM rental_zoning_zones 
                WHERE latitude IS NOT NULL AND longitude IS NOT NULL
                ORDER BY distance ASC
                LIMIT 3
            ");
            
            $all_zones_query->bind_param("ddd", $latitude, $longitude, $latitude);
            $all_zones_query->execute();
            $nearby_zones = $all_zones_query->get_result()->fetch_all(MYSQLI_ASSOC);
            
            echo json_encode([
                'success' => true,
                'zone_id' => $zone['id'],
                'zone_number' => $zone['zone_number'],
                'area_center' => $zone['area_center'],
                'reading_date' => $zone['reading_date'],
                'latitude' => $latitude,
                'longitude' => $longitude,
                'zone_lat' => $zone['latitude'],
                'zone_lon' => $zone['longitude'],
                'distance_km' => round($distance, 2),
                'distance_m' => round($distance * 1000, 0),
                'nearby_zones' => $nearby_zones,
                'message' => "Zone determined based on geographic proximity"
            ]);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'error' => 'Could not determine zone for these coordinates.']);
    exit;
}

// Handle AJAX request for reverse geocoding
if (isset($_POST['reverse_geocode'])) {
    header('Content-Type: application/json');
    
    $latitude = floatval($_POST['latitude'] ?? 0);
    $longitude = floatval($_POST['longitude'] ?? 0);
    
    $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$latitude}&lon={$longitude}&zoom=18&addressdetails=1";
    $options = ['http' => ['header' => "User-Agent: ZoneChecker/1.0\r\n"]];
    $context = stream_context_create($options);
    
    try {
        $response = @file_get_contents($url, false, $context);
        if ($response !== FALSE) {
            $data = json_decode($response, true);
            echo json_encode([
                'success' => true,
                'display_name' => $data['display_name'] ?? 'Unknown location',
                'road' => $data['address']['road'] ?? '',
                'suburb' => $data['address']['suburb'] ?? $data['address']['neighbourhood'] ?? '',
                'city' => $data['address']['city'] ?? $data['address']['town'] ?? $data['address']['municipality'] ?? '',
                'barangay' => $data['address']['barangay'] ?? $data['address']['suburb'] ?? ''
            ]);
        } else {
            echo json_encode(['success' => false]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false]);
    }
    exit;
}

// Handle AJAX request for all zones (for map initialization)
if (isset($_GET['get_zones'])) {
    header('Content-Type: application/json');
    
    $zones_data = [];
    $zones_query = $conn->query("
        SELECT z.*, COUNT(cm.id) as machine_count 
        FROM rental_zoning_zones z
        LEFT JOIN rental_contract_machines cm ON z.id = cm.zone_id AND cm.status = 'ACTIVE'
        GROUP BY z.id
        ORDER BY z.zone_number
    ");
    
    while ($zone = $zones_query->fetch_assoc()) {
        $zones_data[] = $zone;
    }
    
    echo json_encode(['success' => true, 'zones' => $zones_data]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zone Checker by Coordinates - CPI Rental</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f4f6f9; 
            padding: 20px;
        }
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
        }
        
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
        h1 { 
            color: #2c3e50; 
            display: flex; 
            align-items: center; 
            gap: 10px; 
        }
        
        .main-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }
        
        .checker-panel {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .map-panel {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #34495e;
        }
        
        .required:after {
            content: " *";
            color: #e74c3c;
        }
        
        .coordinate-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        input[type="text"], input[type="number"] {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.3s;
            font-family: monospace;
        }
        
        input[type="text"]:focus, input[type="number"]:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 3px rgba(52,152,219,0.1);
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
            width: 100%;
            justify-content: center;
        }
        
        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52,152,219,0.3);
        }
        
        .btn-secondary {
            background: #95a5a6;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #7f8c8d;
        }
        
        .btn-success {
            background: #27ae60;
            color: white;
        }
        
        .btn-success:hover {
            background: #229954;
        }
        
        .result-card {
            margin-top: 25px;
            border-radius: 10px;
            overflow: hidden;
            display: none;
        }
        
        .result-header {
            background: #2c3e50;
            color: white;
            padding: 15px;
            font-weight: 600;
        }
        
        .result-body {
            background: #f8f9fa;
            padding: 20px;
            border-left: 4px solid;
        }
        
        .result-success {
            border-left-color: #27ae60;
        }
        
        .result-error {
            border-left-color: #e74c3c;
        }
        
        .zone-badge {
            display: inline-block;
            padding: 8px 20px;
            background: #3498db;
            color: white;
            border-radius: 30px;
            font-weight: bold;
            font-size: 24px;
            margin-bottom: 15px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 15px;
        }
        
        .info-item {
            background: white;
            padding: 12px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .info-label {
            font-size: 12px;
            color: #7f8c8d;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #2c3e50;
            font-family: monospace;
        }
        
        .distance-badge {
            background: #e67e22;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            display: inline-block;
        }
        
        #zoneMap {
            height: 450px;
            width: 100%;
            border-radius: 10px;
            z-index: 1;
        }
        
        .legend {
            display: flex;
            gap: 20px;
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            flex-wrap: wrap;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }
        
        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        
        .dot-blue { background: #3498db; border: 2px solid white; box-shadow: 0 0 0 2px #3498db; }
        .dot-red { background: #e74c3c; border: 2px solid white; box-shadow: 0 0 0 2px #e74c3c; }
        .dot-green { background: #27ae60; border: 2px solid white; box-shadow: 0 0 0 2px #27ae60; }
        
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .coordinate-hint {
            background: #e3f2fd;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #1976d2;
            border-left: 4px solid #2196F3;
        }
        
        .map-click-hint {
            background: #fff8e1;
            padding: 10px;
            border-radius: 6px;
            margin-top: 10px;
            font-size: 12px;
            color: #856404;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .nearby-zones {
            margin-top: 20px;
            padding: 15px;
            background: #e8f5e9;
            border-radius: 8px;
        }
        
        .nearby-zone-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            background: white;
            border-radius: 6px;
            margin-bottom: 5px;
        }
        
        .reverse-geo-info {
            background: #f1f9f9;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            border-left: 4px solid #00bcd4;
            font-size: 13px;
        }
        
        @media (max-width: 768px) {
            .main-content {
                grid-template-columns: 1fr;
            }
            .coordinate-inputs {
                grid-template-columns: 1fr;
            }
            .info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>
                🗺️ Zone Checker by Coordinates
                <span style="font-size: 16px; background: #e3f2fd; padding: 5px 15px; border-radius: 25px; color: #1976d2;">
                    Enter Latitude & Longitude
                </span>
            </h1>
            <div>
                <a href="r-dashboard.php" class="btn btn-secondary">← Dashboard</a>
                <a href="r-view_zones.php" class="btn btn-primary">View Zones</a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Checker Panel -->
            <div class="checker-panel">
                <h2 style="color: #2c3e50; margin-bottom: 25px; display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 24px;">📍</span>
                    Check Coordinates
                </h2>
                
                <div class="coordinate-hint">
                    <strong>📍 Metro Manila Range:</strong> 
                    Latitude: 14.2 - 14.8, Longitude: 120.9 - 121.1
                </div>
                
                <form id="zoneCheckForm">
                    <div class="coordinate-inputs">
                        <div class="form-group">
                            <label class="required">Latitude</label>
                            <input type="number" id="latitude" name="latitude" 
                                   step="any" placeholder="e.g., 14.5995" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="required">Longitude</label>
                            <input type="number" id="longitude" name="longitude" 
                                   step="any" placeholder="e.g., 120.9842" 
                                   required>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary" id="checkBtn" style="flex: 2;">
                            <span id="btnText">📍 Find Zone</span>
                            <span id="btnLoader" class="loading" style="display: none;"></span>
                        </button>
                        <button type="button" class="btn btn-success" id="getCurrentLocationBtn" style="flex: 1;">
                            📱 Use My Location
                        </button>
                    </div>
                </form>
                
                <div class="map-click-hint">
                    <span style="font-size: 18px;">🖱️</span>
                    <span><strong>Tip:</strong> Click anywhere on the map to get coordinates and zone assignment</span>
                </div>
                
                <!-- Result Card -->
                <div id="resultCard" class="result-card">
                    <div class="result-header" id="resultHeader">
                        <span id="resultTitle">Zone Assignment Result</span>
                    </div>
                    <div class="result-body" id="resultBody">
                        <div id="resultContent"></div>
                    </div>
                </div>
            </div>
            
            <!-- Map Panel -->
            <div class="map-panel">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="color: #2c3e50; display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 20px;">📍</span>
                        Zone Map - Metro Manila
                    </h3>
                    <div>
                        <button onclick="centerMapOnManila()" class="btn btn-secondary" style="padding: 8px 15px;">
                            🏙️ Reset
                        </button>
                    </div>
                </div>
                
                <div id="zoneMap"></div>
                <div id="clickCoordinates" style="margin-top: 10px; font-size: 12px; color: #7f8c8d; text-align: center;">
                    Click on the map to get coordinates
                </div>
                
                <div class="legend">
                    <div class="legend-item">
                        <div class="legend-dot dot-blue"></div>
                        <span>Zone Centers (<?php echo count($zones); ?> zones)</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-dot dot-red"></div>
                        <span>Current Search</span>
                    </div>
                    <div class="legend-item">
                        <div style="background: #3498db; color: white; padding: 2px 10px; border-radius: 12px;">Zone #</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Zone Information Table -->
        <div style="background: white; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <h3 style="color: #2c3e50; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 20px;">📋</span>
                Zone Reference Guide
            </h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #34495e; color: white;">
                            <th style="padding: 12px;">Zone</th>
                            <th style="padding: 12px;">Area Center</th>
                            <th style="padding: 12px;">Reading Date</th>
                            <th style="padding: 12px;">Coordinates</th>
                            <th style="padding: 12px;">Active Machines</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $zone_stats = $conn->query("
                            SELECT z.*, COUNT(cm.id) as machine_count 
                            FROM rental_zoning_zones z
                            LEFT JOIN rental_contract_machines cm ON z.id = cm.zone_id AND cm.status = 'ACTIVE'
                            GROUP BY z.id
                            ORDER BY z.zone_number
                        ");
                        while($zone = $zone_stats->fetch_assoc()): 
                        ?>
                        <tr style="border-bottom: 1px solid #ecf0f1;">
                            <td style="padding: 12px;">
                                <span style="background: #3498db; color: white; padding: 5px 12px; border-radius: 20px; font-weight: bold;">
                                    Zone <?php echo $zone['zone_number']; ?>
                                </span>
                            </td>
                            <td style="padding: 12px; font-weight: 600;"><?php echo htmlspecialchars($zone['area_center']); ?></td>
                            <td style="padding: 12px;">
                                <span style="background: #e3f2fd; padding: 4px 10px; border-radius: 15px;">
                                    Day <?php echo $zone['reading_date']; ?>
                                </span>
                            </td>
                            <td style="padding: 12px; font-family: monospace; font-size: 12px;">
                                <?php echo $zone['latitude']; ?>, <?php echo $zone['longitude']; ?>
                            </td>
                            <td style="padding: 12px;">
                                <span style="background: #27ae60; color: white; padding: 4px 10px; border-radius: 15px;">
                                    <?php echo $zone['machine_count']; ?> machines
                                </span>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Initialize map
        let map;
        let zoneMarkers = [];
        let searchMarker = null;
        let searchLatLng = null;
        
        // Zone data from PHP
        const zones = <?php echo json_encode($zones); ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            initMap();
            
            // Form submission handler
            document.getElementById('zoneCheckForm').addEventListener('submit', function(e) {
                e.preventDefault();
                checkZone();
            });
            
            // Get current location button
            document.getElementById('getCurrentLocationBtn').addEventListener('click', function() {
                getCurrentLocation();
            });
        });
        
        function initMap() {
            // Center on Metro Manila
            map = L.map('zoneMap').setView([14.5995, 120.9842], 11);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);
            
            // Add scale control
            L.control.scale({
                imperial: false,
                metric: true,
                position: 'bottomright'
            }).addTo(map);
            
            // Add click handler to map
            map.on('click', function(e) {
                const lat = e.latlng.lat.toFixed(6);
                const lng = e.latlng.lng.toFixed(6);
                
                document.getElementById('latitude').value = lat;
                document.getElementById('longitude').value = lng;
                
                document.getElementById('clickCoordinates').innerHTML = 
                    `📍 Selected coordinates: ${lat}, ${lng}`;
                
                checkZone();
            });
            
            // Add zone markers
            addZoneMarkers();
        }
        
        function addZoneMarkers() {
            zones.forEach(zone => {
                if (zone.latitude && zone.longitude) {
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
                    
                    // Popup content
                    marker.bindPopup(`
                        <div style="min-width: 200px;">
                            <h3 style="margin: 0 0 10px 0; color: #2c3e50;">Zone ${zone.zone_number}</h3>
                            <p style="margin: 5px 0; font-weight: 600; color: #2980b9;">${zone.area_center}</p>
                            <hr style="margin: 10px 0; border: none; border-top: 1px solid #ecf0f1;">
                            <p style="margin: 5px 0;"><strong>📅 Reading Date:</strong> Day ${zone.reading_date}</p>
                            <p style="margin: 5px 0;"><strong>📍 Coordinates:</strong><br>
                            <span style="font-family: monospace;">${zone.latitude}, ${zone.longitude}</span></p>
                            <p style="margin: 5px 0;"><strong>Formula:</strong> Zone + 2 = Day ${zone.reading_date}</p>
                        </div>
                    `);
                    
                    zoneMarkers.push(marker);
                }
            });
        }
        
        function checkZone() {
            const latitude = document.getElementById('latitude').value.trim();
            const longitude = document.getElementById('longitude').value.trim();
            
            if (!latitude || !longitude) {
                alert('Please enter both Latitude and Longitude');
                return;
            }
            
            // Show loading state
            document.getElementById('btnText').style.display = 'none';
            document.getElementById('btnLoader').style.display = 'inline-block';
            document.getElementById('checkBtn').disabled = true;
            
            const formData = new FormData();
            formData.append('latitude', latitude);
            formData.append('longitude', longitude);
            formData.append('check_zone', '1');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                // Hide loading state
                document.getElementById('btnText').style.display = 'inline';
                document.getElementById('btnLoader').style.display = 'none';
                document.getElementById('checkBtn').disabled = false;
                
                if (data.success) {
                    displayResult(data);
                    updateMapWithSearch(data);
                    getReverseGeocode(latitude, longitude);
                } else {
                    displayError(data.error, data.hint);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('btnText').style.display = 'inline';
                document.getElementById('btnLoader').style.display = 'none';
                document.getElementById('checkBtn').disabled = false;
                displayError('An error occurred. Please try again.');
            });
        }
        
        function getReverseGeocode(lat, lng) {
            const formData = new FormData();
            formData.append('latitude', lat);
            formData.append('longitude', lng);
            formData.append('reverse_geocode', '1');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayReverseGeocode(data);
                }
            })
            .catch(error => console.error('Reverse geocode error:', error));
        }
        
        function displayReverseGeocode(data) {
            const resultBody = document.getElementById('resultBody');
            
            const geoDiv = document.createElement('div');
            geoDiv.className = 'reverse-geo-info';
            geoDiv.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                    <span style="font-size: 18px;">📍</span>
                    <span style="font-weight: 600;">Approximate Location:</span>
                </div>
                <div style="font-size: 13px; color: #2c3e50;">
                    ${data.display_name || 'Unknown location'}
                </div>
                ${data.barangay ? `<div style="margin-top: 5px; font-size: 12px; color: #00bcd4;"><strong>Barangay:</strong> ${data.barangay}</div>` : ''}
                ${data.city ? `<div style="font-size: 12px; color: #00bcd4;"><strong>City:</strong> ${data.city}</div>` : ''}
            `;
            
            // Remove existing reverse geocode if any
            const existingGeo = resultBody.querySelector('.reverse-geo-info');
            if (existingGeo) {
                existingGeo.remove();
            }
            
            resultBody.appendChild(geoDiv);
        }
        
        function displayResult(data) {
            const resultCard = document.getElementById('resultCard');
            const resultBody = document.getElementById('resultBody');
            const resultTitle = document.getElementById('resultTitle');
            
            resultCard.style.display = 'block';
            resultBody.className = 'result-body result-success';
            resultTitle.innerHTML = '✅ Zone Found!';
            
            // Calculate reading date formula
            const recommendedDate = parseInt(data.zone_number) + 2;
            
            // Build nearby zones HTML
            let nearbyHtml = '';
            if (data.nearby_zones && data.nearby_zones.length > 1) {
                nearbyHtml = '<div class="nearby-zones"><strong>📍 Nearby Zones:</strong>';
                data.nearby_zones.slice(1).forEach((zone, index) => {
                    const distKm = parseFloat(zone.distance).toFixed(2);
                    nearbyHtml += `
                        <div class="nearby-zone-item">
                            <span style="font-weight: 600;">Zone ${zone.zone_number}</span>
                            <span style="color: #7f8c8d;">${zone.area_center}</span>
                            <span class="distance-badge" style="background: #95a5a6;">${distKm} km</span>
                        </div>
                    `;
                });
                nearbyHtml += '</div>';
            }
            
            resultBody.innerHTML = `
                <div style="text-align: center; margin-bottom: 20px;">
                    <span class="zone-badge">ZONE ${data.zone_number}</span>
                    <div style="margin-top: 10px;">
                        <span style="background: #e67e22; color: white; padding: 5px 15px; border-radius: 20px; font-size: 14px;">
                            ${data.distance_m} meters from zone center
                        </span>
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <div style="background: #e8f5e9; padding: 15px; border-radius: 8px; text-align: center;">
                        <span style="font-size: 18px; font-weight: 600; color: #2c3e50;">${data.area_center}</span>
                    </div>
                </div>
                
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Reading Date</div>
                        <div class="info-value">Day ${data.reading_date}</div>
                        <div style="font-size: 11px; color: #7f8c8d;">Zone + 2 = Day ${recommendedDate}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Distance</div>
                        <div><span class="distance-badge">${data.distance_km} km</span></div>
                        <div style="font-size: 11px; color: #7f8c8d;">${data.distance_m} meters</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Your Coordinates</div>
                        <div style="font-family: monospace; font-size: 13px;">
                            ${data.latitude}, ${data.longitude}
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Zone Center</div>
                        <div style="font-family: monospace; font-size: 13px;">
                            ${data.zone_lat}, ${data.zone_lon}
                        </div>
                    </div>
                </div>
                
                ${nearbyHtml}
            `;
        }
        
        function displayError(message, hint) {
            const resultCard = document.getElementById('resultCard');
            const resultBody = document.getElementById('resultBody');
            const resultTitle = document.getElementById('resultTitle');
            
            resultCard.style.display = 'block';
            resultBody.className = 'result-body result-error';
            resultTitle.innerHTML = '❌ Zone Not Found';
            
            let hintHtml = '';
            if (hint) {
                hintHtml = `<p style="font-size: 13px; color: #e67e22; margin-top: 10px;">💡 ${hint}</p>`;
            }
            
            resultBody.innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <span style="font-size: 48px;">🔍</span>
                    <h3 style="color: #2c3e50; margin: 15px 0;">Could Not Determine Zone</h3>
                    <p style="color: #7f8c8d; margin-bottom: 15px;">${message}</p>
                    ${hintHtml}
                    <p style="font-size: 13px; color: #95a5a6; margin-top: 15px;">
                        Try coordinates within Metro Manila range or click on the map.
                    </p>
                </div>
            `;
        }
        
        function updateMapWithSearch(data) {
            // Remove existing search marker
            if (searchMarker) {
                map.removeLayer(searchMarker);
            }
            
            if (data.latitude && data.longitude) {
                searchLatLng = [data.latitude, data.longitude];
                
                // Create red marker for search location
                const searchIcon = L.divIcon({
                    className: 'custom-div-icon',
                    html: `<div style="background: #e74c3c; 
                                 width: 40px; height: 40px; border-radius: 50%; 
                                 border: 3px solid white; box-shadow: 0 2px 10px rgba(0,0,0,0.3);
                                 display: flex; align-items: center; justify-content: center; 
                                 color: white; font-weight: bold; font-size: 16px;">
                            📍
                          </div>`,
                    iconSize: [40, 40],
                    iconAnchor: [20, 20],
                    popupAnchor: [0, -20]
                });
                
                searchMarker = L.marker(searchLatLng, { icon: searchIcon }).addTo(map);
                
                // Draw line between search location and zone center
                if (data.zone_lat && data.zone_lon) {
                    const zoneLatLng = [data.zone_lat, data.zone_lon];
                    
                    // Remove existing line if any
                    if (window.distanceLine) {
                        map.removeLayer(window.distanceLine);
                    }
                    
                    // Draw line
                    window.distanceLine = L.polyline([searchLatLng, zoneLatLng], {
                        color: '#e67e22',
                        weight: 3,
                        opacity: 0.7,
                        dashArray: '5, 10'
                    }).addTo(map);
                    
                    // Add midpoint label
                    const midPoint = [
                        (searchLatLng[0] + zoneLatLng[0]) / 2,
                        (searchLatLng[1] + zoneLatLng[1]) / 2
                    ];
                    
                    if (window.distanceLabel) {
                        map.removeLayer(window.distanceLabel);
                    }
                    
                    window.distanceLabel = L.marker(midPoint, {
                        icon: L.divIcon({
                            className: 'distance-label',
                            html: `<div style="background: #e67e22; color: white; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; white-space: nowrap;">
                                    ${data.distance_km} km
                                   </div>`,
                            iconSize: [60, 25],
                            iconAnchor: [30, 12]
                        })
                    }).addTo(map);
                }
                
                // Center map on search location
                map.setView(searchLatLng, 13);
                
                // Open popup for search location
                searchMarker.bindPopup(`
                    <div style="min-width: 200px;">
                        <h3 style="margin: 0 0 10px 0; color: #e74c3c;">📍 Search Location</h3>
                        <p style="margin: 5px 0;"><strong>Coordinates:</strong><br>
                        <span style="font-family: monospace;">${data.latitude}, ${data.longitude}</span></p>
                        <hr style="margin: 10px 0; border: none; border-top: 1px solid #ecf0f1;">
                        <p style="margin: 5px 0;"><strong>Assigned Zone:</strong> Zone ${data.zone_number}</p>
                        <p style="margin: 5px 0;"><strong>Area Center:</strong> ${data.area_center}</p>
                        <p style="margin: 5px 0;"><strong>Distance:</strong> ${data.distance_km} km</p>
                        <p style="margin: 5px 0;"><strong>Reading Date:</strong> Day ${data.reading_date}</p>
                    </div>
                `).openPopup();
            }
        }
        
        function centerMapOnManila() {
            map.setView([14.5995, 120.9842], 11);
        }
        
        function getCurrentLocation() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const lat = position.coords.latitude.toFixed(6);
                        const lng = position.coords.longitude.toFixed(6);
                        
                        document.getElementById('latitude').value = lat;
                        document.getElementById('longitude').value = lng;
                        
                        document.getElementById('clickCoordinates').innerHTML = 
                            `📍 Current location: ${lat}, ${lng}`;
                        
                        checkZone();
                    },
                    function(error) {
                        let errorMessage = 'Unable to get your location.';
                        switch(error.code) {
                            case error.PERMISSION_DENIED:
                                errorMessage = 'Location permission denied. Please enable location access.';
                                break;
                            case error.POSITION_UNAVAILABLE:
                                errorMessage = 'Location information is unavailable.';
                                break;
                            case error.TIMEOUT:
                                errorMessage = 'Location request timed out.';
                                break;
                        }
                        alert(errorMessage);
                    }
                );
            } else {
                alert('Geolocation is not supported by your browser.');
            }
        }
    </script>
</body>
</html>