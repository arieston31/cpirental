<?php
require_once 'config.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Function to convert number to ordinal (1st, 2nd, 3rd, etc.)
function getOrdinal($number) {
    if ($number % 100 >= 11 && $number % 100 <= 13) {
        return $number . 'th';
    }
    
    switch ($number % 10) {
        case 1: return $number . 'st';
        case 2: return $number . 'nd';
        case 3: return $number . 'rd';
        default: return $number . 'th';
    }
}

// Get all zones for map and reference
$zones_query = $conn->query("SELECT * FROM rental_zoning_zone WHERE latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY zone_number");
if (!$zones_query) {
    die("Error loading zones: " . $conn->error);
}
$zones = [];
while ($zone = $zones_query->fetch_assoc()) {
    $zones[] = $zone;
}

// Get distinct cities FROM rental_zone_coordinates for dropdown
$cities_query = $conn->query("SELECT DISTINCT city FROM rental_zone_coordinates WHERE city IS NOT NULL AND city != '' ORDER BY city");
$cities = [];
while ($city_row = $cities_query->fetch_assoc()) {
    $cities[] = $city_row['city'];
}

// Handle AJAX request for barangay suggestions
if (isset($_GET['get_barangays'])) {
    header('Content-Type: application/json');
    
    $city = trim($_GET['city'] ?? '');
    $search = trim($_GET['search'] ?? '');
    
    if (empty($city)) {
        echo json_encode(['success' => false, 'error' => 'City is required']);
        exit;
    }
    
    $query = "SELECT barangay FROM rental_zone_coordinates WHERE LOWER(city) = LOWER(?)";
    $params = [$city];
    $types = "s";
    
    if (!empty($search)) {
        $query .= " AND LOWER(barangay) LIKE LOWER(?)";
        $params[] = "%$search%";
        $types .= "s";
    }
    
    $query .= " ORDER BY barangay LIMIT 20";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
        exit;
    }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $barangays = [];
    while ($row = $result->fetch_assoc()) {
        $barangays[] = $row['barangay'];
    }
    
    echo json_encode(['success' => true, 'barangays' => $barangays]);
    exit;
}

// Handle AJAX request for zone checking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_zone'])) {
    header('Content-Type: application/json');
    
    $barangay = trim($_POST['barangay'] ?? '');
    $city = trim($_POST['city'] ?? '');
    
    if (empty($barangay) || empty($city)) {
        echo json_encode(['success' => false, 'error' => 'Both barangay and city are required.']);
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
    
    // Check if barangay exists in zone_coordinates for the selected city
    $stmt = $conn->prepare("
        SELECT latitude, longitude 
        FROM rental_zone_coordinates 
        WHERE LOWER(barangay) = LOWER(?) AND LOWER(city) = LOWER(?)
        LIMIT 1
    ");
    
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
        exit;
    }
    
    $stmt->bind_param("ss", $barangay, $city);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Barangay not found in database for this city
        echo json_encode([
            'success' => false,
            'error' => 'barangay_not_found',
            'message' => "This barangay was not in database for {$city}. Please tell Aries to update the database.",
            'barangay' => $barangay,
            'city' => $city
        ]);
        exit;
    }
    
    // Get the coordinates of the searched barangay
    $location = $result->fetch_assoc();
    $lat = floatval($location['latitude']);
    $lng = floatval($location['longitude']);
    
    // Find nearest zone using distance calculation
    $zone_stmt = $conn->prepare("
        SELECT id, zone_number, area_center, reading_date, latitude, longitude,
            (6371 * acos(cos(radians(?)) * cos(radians(latitude)) 
            * cos(radians(longitude) - radians(?)) 
            + sin(radians(?)) * sin(radians(latitude)))) AS distance
        FROM rental_zoning_zone
        WHERE latitude IS NOT NULL AND longitude IS NOT NULL
        ORDER BY distance ASC 
        LIMIT 1
    ");
    
    if (!$zone_stmt) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
        exit;
    }
    
    $zone_stmt->bind_param("ddd", $lat, $lng, $lat);
    $zone_stmt->execute();
    $zone_result = $zone_stmt->get_result();
    
    if ($zone_result->num_rows > 0) {
        $zone = $zone_result->fetch_assoc();
        $distance = calculateDistance($lat, $lng, $zone['latitude'], $zone['longitude']);
        
        echo json_encode([
            'success' => true,
            'zone_number' => $zone['zone_number'],
            'area_center' => $zone['area_center'],
            'reading_date' => $zone['reading_date'],
            'reading_date_ordinal' => getOrdinal($zone['reading_date']),
            'latitude' => $lat,
            'longitude' => $lng,
            'zone_lat' => $zone['latitude'],
            'zone_lon' => $zone['longitude'],
            'distance_km' => round($distance, 2),
            'distance_m' => round($distance * 1000, 0),
            'barangay' => $barangay,
            'city' => $city,
            'message' => "Zone determined based on barangay location"
        ]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => 'No zones found in the database.']);
        exit;
    }
}

// Handle AJAX request for all zones (for map initialization)
if (isset($_GET['get_zones'])) {
    header('Content-Type: application/json');
    
    $zones_data = [];
    $zones_query = $conn->query("
        SELECT z.*, COUNT(cm.id) as machine_count 
        FROM rental_zoning_zone z
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
    <title>Zone Locator - Find Zone by Barangay</title>
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
        
        .locator-panel {
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
            position: relative;
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
        
        select, input[type="text"] {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.3s;
            background: white;
        }
        
        select:focus, input[type="text"]:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 3px rgba(52,152,219,0.1);
        }
        
        select:disabled {
            background: #f5f5f5;
            cursor: not-allowed;
        }
        
        .autocomplete-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 250px;
            overflow-y: auto;
            background: white;
            border: 2px solid #3498db;
            border-top: none;
            border-radius: 0 0 8px 8px;
            z-index: 1000;
            display: none;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .autocomplete-item {
            padding: 10px 15px;
            cursor: pointer;
            transition: background 0.2s;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .autocomplete-item:hover {
            background: #e3f2fd;
        }
        
        .autocomplete-item:last-child {
            border-bottom: none;
        }
        
        .no-suggestions {
            padding: 15px;
            color: #7f8c8d;
            text-align: center;
            font-style: italic;
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
        
        .btn-warning {
            background: #e67e22;
            color: white;
        }
        
        .btn-warning:hover {
            background: #d35400;
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
        
        .result-warning {
            border-left-color: #e67e22;
        }
        
        .zone-result-box {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .zone-badge {
            display: inline-block;
            padding: 15px 40px;
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            border-radius: 50px;
            font-weight: bold;
            font-size: 36px;
            margin-bottom: 15px;
            box-shadow: 0 4px 10px rgba(52,152,219,0.3);
        }
        
        .reading-date-box {
            display: inline-block;
            padding: 12px 30px;
            background: #e8f5e9;
            border-radius: 50px;
            font-size: 20px;
            font-weight: 600;
            color: #2e7d32;
            margin-top: 10px;
            border: 2px solid #a5d6a7;
        }
        
        .reading-date-box span {
            font-weight: 700;
            color: #1b5e20;
        }
        
        .location-info {
            margin: 20px 0;
            padding: 15px;
            background: #f1f9f9;
            border-radius: 8px;
            font-size: 16px;
            color: #00bcd4;
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
        
        .dot-blue { 
            background: #3498db; 
            border: 2px solid white; 
            box-shadow: 0 0 0 2px #3498db; 
        }
        
        .dot-red { 
            background: #e74c3c; 
            border: 2px solid white; 
            box-shadow: 0 0 0 2px #e74c3c; 
        }
        
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
        
        .missing-data-box {
            background: #fff3cd;
            border-left: 4px solid #e67e22;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            text-align: center;
        }
        
        .city-badge {
            background: #e3f2fd;
            color: #1976d2;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin: 0 5px;
        }
        
        .hint-text {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .formula-box {
            background: #e8f5e9;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            font-size: 14px;
            color: #2e7d32;
            margin-top: 15px;
            border-left: 4px solid #4caf50;
        }
        
        @media (max-width: 768px) {
            .main-content {
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
                🗺️ Zone Locator
                <span style="font-size: 16px; background: #e3f2fd; padding: 5px 15px; border-radius: 25px; color: #1976d2;">
                    Find Zone by Barangay
                </span>
            </h1>
            <div>
                <!-- <a href="r-dashboard.php" class="btn btn-secondary">← Dashboard</a>
                <a href="r-view_zones.php" class="btn btn-primary">View Zones</a> -->
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Locator Panel -->
            <div class="locator-panel">
                <h2 style="color: #2c3e50; margin-bottom: 25px; display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 24px;">🔍</span>
                    Enter Barangay & City
                </h2>
                
                <form id="zoneLocatorForm">
                    <div class="form-group">
                        <label class="required">City / Municipality</label>
                        <select id="city" name="city" required>
                            <option value="">-- Select a city --</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?php echo htmlspecialchars($city); ?>"><?php echo htmlspecialchars($city); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group" style="position: relative;">
                        <label class="required">Barangay</label>
                        <input type="text" id="barangay" name="barangay" 
                               placeholder="Start typing to search barangay..." 
                               autocomplete="off"
                               disabled
                               required>
                        <div id="barangaySuggestions" class="autocomplete-suggestions"></div>
                        <div class="hint-text">
                            <span>🔍</span> Type at least 2 characters to see suggestions
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" id="checkBtn">
                        <span id="btnText">📍 Find Zone</span>
                        <span id="btnLoader" class="loading" style="display: none;"></span>
                    </button>
                </form>
                
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
                    <button onclick="centerMapOnManila()" class="btn btn-secondary" style="padding: 8px 15px;">
                        🏙️ Reset
                    </button>
                </div>
                
                <div id="zoneMap"></div>
                
                <div class="legend">
                    <div class="legend-item">
                        <div class="legend-dot dot-blue"></div>
                        <span>Zone Centers (<?php echo count($zones); ?> zones)</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-dot dot-red"></div>
                        <span>Searched Barangay</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize map
        let map;
        let zoneMarkers = [];
        let searchMarker = null;
        let searchLatLng = null;
        let searchTimeout;
        
        // Zone data from PHP
        const zones = <?php echo json_encode($zones); ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            initMap();
            
            // Form submission handler
            document.getElementById('zoneLocatorForm').addEventListener('submit', function(e) {
                e.preventDefault();
                checkZone();
            });
            
            // City change handler
            document.getElementById('city').addEventListener('change', function() {
                const barangayInput = document.getElementById('barangay');
                const city = this.value;
                
                if (city) {
                    barangayInput.disabled = false;
                    barangayInput.value = '';
                    barangayInput.focus();
                    document.getElementById('barangaySuggestions').style.display = 'none';
                } else {
                    barangayInput.disabled = true;
                    barangayInput.value = '';
                }
            });
            
            // Barangay input handler for autocomplete
            document.getElementById('barangay').addEventListener('input', function() {
                const searchTerm = this.value.trim();
                const city = document.getElementById('city').value;
                
                if (!city) {
                    alert('Please select a city first');
                    this.value = '';
                    return;
                }
                
                clearTimeout(searchTimeout);
                
                if (searchTerm.length >= 2) {
                    searchTimeout = setTimeout(() => {
                        fetchBarangaySuggestions(searchTerm, city);
                    }, 300);
                } else {
                    document.getElementById('barangaySuggestions').style.display = 'none';
                }
            });
            
            // Close suggestions when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#barangay') && !e.target.closest('#barangaySuggestions')) {
                    document.getElementById('barangaySuggestions').style.display = 'none';
                }
            });
            
            // Handle keyboard navigation in suggestions
            document.getElementById('barangay').addEventListener('keydown', function(e) {
                const suggestions = document.getElementById('barangaySuggestions');
                const items = suggestions.querySelectorAll('.autocomplete-item');
                
                if (items.length === 0) return;
                
                const active = suggestions.querySelector('.autocomplete-item.active');
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (!active) {
                        items[0].classList.add('active');
                    } else {
                        const next = active.nextElementSibling;
                        if (next && next.classList.contains('autocomplete-item')) {
                            active.classList.remove('active');
                            next.classList.add('active');
                        }
                    }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (!active) {
                        items[items.length - 1].classList.add('active');
                    } else {
                        const prev = active.previousElementSibling;
                        if (prev && prev.classList.contains('autocomplete-item')) {
                            active.classList.remove('active');
                            prev.classList.add('active');
                        }
                    }
                } else if (e.key === 'Enter' && active) {
                    e.preventDefault();
                    selectBarangay(active.textContent);
                } else if (e.key === 'Escape') {
                    suggestions.style.display = 'none';
                }
            });
        });
        
        function fetchBarangaySuggestions(search, city) {
            fetch(`zone_locator.php?get_barangays=1&city=${encodeURIComponent(city)}&search=${encodeURIComponent(search)}`)
                .then(response => response.json())
                .then(data => {
                    const suggestionsDiv = document.getElementById('barangaySuggestions');
                    
                    if (data.success && data.barangays.length > 0) {
                        let html = '';
                        data.barangays.forEach(barangay => {
                            html += `<div class="autocomplete-item" onclick="selectBarangay('${barangay.replace(/'/g, "\\'")}')">${barangay}</div>`;
                        });
                        suggestionsDiv.innerHTML = html;
                        suggestionsDiv.style.display = 'block';
                    } else {
                        suggestionsDiv.innerHTML = '<div class="no-suggestions">No matching barangay found</div>';
                        suggestionsDiv.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error fetching suggestions:', error);
                });
        }
        
        function selectBarangay(barangay) {
            document.getElementById('barangay').value = barangay;
            document.getElementById('barangaySuggestions').style.display = 'none';
        }
        
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
                        </div>
                    `);
                    
                    zoneMarkers.push(marker);
                }
            });
        }
        
        function checkZone() {
            const barangay = document.getElementById('barangay').value.trim();
            const city = document.getElementById('city').value;
            
            if (!city) {
                alert('Please select a city');
                return;
            }
            
            if (!barangay) {
                alert('Please enter a barangay');
                return;
            }
            
            // Show loading state
            document.getElementById('btnText').style.display = 'none';
            document.getElementById('btnLoader').style.display = 'inline-block';
            document.getElementById('checkBtn').disabled = true;
            
            const formData = new FormData();
            formData.append('barangay', barangay);
            formData.append('city', city);
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
                } else if (data.error === 'barangay_not_found') {
                    displayMissingData(data);
                } else {
                    displayError(data.error || 'An error occurred');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('btnText').style.display = 'inline';
                document.getElementById('btnLoader').style.display = 'none';
                document.getElementById('checkBtn').disabled = false;
                displayError('Network error. Please try again.');
            });
        }
        
        function displayResult(data) {
            const resultCard = document.getElementById('resultCard');
            const resultBody = document.getElementById('resultBody');
            const resultTitle = document.getElementById('resultTitle');
            
            resultCard.style.display = 'block';
            resultBody.className = 'result-body result-success';
            resultTitle.innerHTML = '✅ Zone Found!';
            
            // Get ordinal reading date from data
            const readingDateOrdinal = data.reading_date_ordinal;
            
            resultBody.innerHTML = `
                <div class="zone-result-box">
                    <div class="zone-badge">ZONE ${data.zone_number}</div>
                    <div style="margin: 15px 0;">
                        <span class="city-badge">${data.barangay}, ${data.city}</span>
                    </div>
                    <div class="reading-date-box">
                        📅 <span>${readingDateOrdinal}</span> day of the month
                    </div>
                </div>
                
                <div class="location-info">
                    <strong>📍 ${data.area_center}</strong> - Zone Center<br>
                    <span style="font-size: 14px;">Distance: ${data.distance_km} km (${data.distance_m} meters)</span>
                </div>
                
                
            `;
        }
        
        function displayMissingData(data) {
            const resultCard = document.getElementById('resultCard');
            const resultBody = document.getElementById('resultBody');
            const resultTitle = document.getElementById('resultTitle');
            
            resultCard.style.display = 'block';
            resultBody.className = 'result-body result-warning';
            resultTitle.innerHTML = '⚠️ Barangay Not Found';
            
            resultBody.innerHTML = `
                <div class="missing-data-box">
                    <h3 style="color: #856404; margin-bottom: 15px;">📍 "${data.barangay}, ${data.city}"</h3>
                    <p style="font-size: 16px; color: #856404; margin-bottom: 10px;">This barangay was not in the database for ${data.city}.</p>
                    <p style="font-size: 18px; font-weight: 600; color: #d35400; margin: 20px 0;">Please tell Aries to update the database.</p>
                    <button onclick="copyUpdateRequest('${data.barangay}', '${data.city}')" class="btn btn-warning">
                        📋 Copy Update Request
                    </button>
                </div>
                <div style="margin-top: 20px; text-align: center; color: #7f8c8d; font-size: 13px;">
                    <p>Try searching for a nearby barangay or use the zone map above.</p>
                </div>
            `;
        }
        
        function displayError(message) {
            const resultCard = document.getElementById('resultCard');
            const resultBody = document.getElementById('resultBody');
            const resultTitle = document.getElementById('resultTitle');
            
            resultCard.style.display = 'block';
            resultBody.className = 'result-body result-error';
            resultTitle.innerHTML = '❌ Error';
            
            resultBody.innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <span style="font-size: 48px;">⚠️</span>
                    <h3 style="color: #2c3e50; margin: 15px 0;">${message}</h3>
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
                }
                
                // Center map on search location
                map.setView(searchLatLng, 13);
                
                // Open popup for search location
                searchMarker.bindPopup(`
                    <div style="min-width: 200px;">
                        <h3 style="margin: 0 0 10px 0; color: #e74c3c;">${data.barangay}</h3>
                        <p style="margin: 5px 0;"><strong>City:</strong> ${data.city}</p>
                        <p style="margin: 5px 0;"><strong>Assigned Zone:</strong> Zone ${data.zone_number}</p>
                        <p style="margin: 5px 0;"><strong>Reading Date:</strong> ${data.reading_date_ordinal} day of the month</p>
                    </div>
                `).openPopup();
            }
        }
        
        function centerMapOnManila() {
            map.setView([14.5995, 120.9842], 11);
        }
        
        function copyUpdateRequest(barangay, city) {
            const message = `Please add coordinates for: ${barangay}, ${city} to zone_coordinates table.`;
            navigator.clipboard.writeText(message).then(() => {
                alert('Update request copied to clipboard!');
            });
        }
    </script>
</body>
</html>