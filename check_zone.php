<?php
require_once 'config.php';

// Function to calculate distance between two points (Haversine formula)
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371; // kilometers

    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);

    $latDelta = $lat2 - $lat1;
    $lonDelta = $lon2 - $lon1;

    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
        cos($lat1) * cos($lat2) * pow(sin($lonDelta / 2), 2)));
    
    return $angle * $earthRadius;
}

// Function to geocode address using OpenStreetMap Nominatim
function geocodeOSM($barangay, $city) {
    if (empty($city) || empty($barangay)) {
        return null;
    }
    
    // Build address query - prioritize barangay
    $query = $barangay . ', ' . $city . ', Philippines';
    
    $address = urlencode($query);
    $url = "https://nominatim.openstreetmap.org/search?format=json&q={$address}&limit=1&countrycodes=PH";
    
    // Add user agent as required by Nominatim's ToS
    $options = [
        'http' => [
            'header' => "User-Agent: ZoningSystem/1.0 (contact@yourdomain.com)\r\n"
        ]
    ];
    
    $context = stream_context_create($options);
    
    try {
        $response = @file_get_contents($url, false, $context);
        
        if ($response === FALSE) {
            error_log("OSM Geocoding failed for: $barangay, $city");
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (!empty($data) && isset($data[0]['lat']) && isset($data[0]['lon'])) {
            return [
                'latitude' => floatval($data[0]['lat']),
                'longitude' => floatval($data[0]['lon']),
                'display_name' => $data[0]['display_name'],
                'source' => 'osm'
            ];
        }
    } catch (Exception $e) {
        error_log("OSM Geocoding exception: " . $e->getMessage());
    }
    
    return null;
}

// Get city centroid (fallback when geocoding fails)
function getCityCentroid($city) {
    $cityCentroids = [
        // Match your actual zone areas
        'valenzuela' => ['lat' => 14.7011, 'lon' => 120.9830],
        'caloocan' => ['lat' => 14.7560, 'lon' => 120.9810],
        'quezon city' => ['lat' => 14.6483, 'lon' => 121.0499],
        'novaliches' => ['lat' => 14.7100, 'lon' => 121.0280],
        'diliman' => ['lat' => 14.6483, 'lon' => 121.0499],
        'cubao' => ['lat' => 14.6219, 'lon' => 121.0534],
        'mandaluyong' => ['lat' => 14.5832, 'lon' => 121.0409],
        'san juan' => ['lat' => 14.5832, 'lon' => 121.0409],
        'pasig' => ['lat' => 14.5829, 'lon' => 121.0614],
        'ortigas' => ['lat' => 14.5829, 'lon' => 121.0614],
        'makati' => ['lat' => 14.5540, 'lon' => 121.0240],
        'ayala' => ['lat' => 14.5540, 'lon' => 121.0240],
        'manila' => ['lat' => 14.5829, 'lon' => 120.9797],
        'pasay' => ['lat' => 14.5380, 'lon' => 121.0000],
        'taguig' => ['lat' => 14.5204, 'lon' => 121.0539],
        'bgc' => ['lat' => 14.5204, 'lon' => 121.0539],
        'parañaque' => ['lat' => 14.4667, 'lon' => 121.0167],
        'las piñas' => ['lat' => 14.4667, 'lon' => 121.0167],
        'marikina' => ['lat' => 14.6507, 'lon' => 121.1029],
        'muntinlupa' => ['lat' => 14.4138, 'lon' => 121.0452],
        'navotas' => ['lat' => 14.6667, 'lon' => 120.9500],
        'malabon' => ['lat' => 14.7525, 'lon' => 120.9822],
    ];
    
    $cityLower = strtolower(trim($city));
    
    foreach ($cityCentroids as $key => $coords) {
        if (strpos($cityLower, $key) !== false) {
            return [
                'latitude' => $coords['lat'],
                'longitude' => $coords['lon'],
                'source' => 'city_centroid'
            ];
        }
    }
    
    // Default to Manila centroid
    return [
        'latitude' => 14.5829,
        'longitude' => 120.9797,
        'source' => 'default_centroid'
    ];
}

// Function to get zone based on barangay (primary) and city
function getZoneFromLocation($barangay, $city, $conn) {
    $barangay = trim($barangay);
    $city = trim($city);
    
    if (empty($barangay)) {
        return ['error' => 'Barangay is required for accurate zoning'];
    }
    
    if (empty($city)) {
        return ['error' => 'City is required'];
    }
    
    // First, check our local cache for exact barangay match
    $stmt = $conn->prepare("
        SELECT bc.latitude, bc.longitude, bc.zone_id, z.zone_number, z.area_center, z.latitude as zone_lat, z.longitude as zone_lon
        FROM barangay_coordinates bc
        JOIN zoning_zone z ON bc.zone_id = z.id
        WHERE LOWER(bc.barangay) = LOWER(?) 
        AND LOWER(bc.city) = LOWER(?)
        AND bc.latitude IS NOT NULL 
        AND bc.longitude IS NOT NULL
        LIMIT 1
    ");
    
    if ($stmt) {
        $stmt->bind_param("ss", $barangay, $city);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $distance = calculateDistance($row['latitude'], $row['longitude'], $row['zone_lat'], $row['zone_lon']);
            
            return [
                'zone_id' => $row['zone_id'],
                'zone_number' => $row['zone_number'],
                'area_center' => $row['area_center'],
                'latitude' => $row['latitude'],
                'longitude' => $row['longitude'],
                'distance_km' => round($distance, 2),
                'source' => 'database_cache'
            ];
        }
    }
    
    // If not in cache, check for barangay only (in any city)
    $stmt = $conn->prepare("
        SELECT bc.latitude, bc.longitude, bc.zone_id, z.zone_number, z.area_center, bc.city as found_city
        FROM barangay_coordinates bc
        JOIN zoning_zone z ON bc.zone_id = z.id
        WHERE LOWER(bc.barangay) = LOWER(?)
        AND bc.latitude IS NOT NULL 
        AND bc.longitude IS NOT NULL
        ORDER BY bc.last_updated DESC
        LIMIT 1
    ");
    
    if ($stmt) {
        $stmt->bind_param("s", $barangay);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return [
                'zone_id' => $row['zone_id'],
                'zone_number' => $row['zone_number'],
                'area_center' => $row['area_center'],
                'latitude' => $row['latitude'],
                'longitude' => $row['longitude'],
                'source' => 'barangay_match',
                'note' => "Barangay found in database (originally from {$row['found_city']})"
            ];
        }
    }
    
    // If barangay not found in cache, try to geocode
    $coordinates = null;
    
    // Try OpenStreetMap with barangay + city
    $coordinates = geocodeOSM($barangay, $city);
    
    // If OSM fails, try with city only
    if (!$coordinates && !empty($city)) {
        $coordinates = geocodeOSM('', $city);
        
        if ($coordinates) {
            $coordinates['source'] = 'city_only_osm';
            $coordinates['note'] = 'Could not geocode barangay, using city center';
        }
    }
    
    // If OSM fails, use city centroid as fallback
    if (!$coordinates && !empty($city)) {
        $coordinates = getCityCentroid($city);
        if ($coordinates) {
            $coordinates['note'] = 'Using city centroid as fallback';
        }
    }
    
    if ($coordinates) {
        // Find nearest zone using distance calculation
        $stmt = $conn->prepare("
            SELECT id, zone_number, area_center, latitude, longitude,
                   (6371 * acos(cos(radians(?)) * cos(radians(latitude)) 
                   * cos(radians(longitude) - radians(?)) 
                   + sin(radians(?)) * sin(radians(latitude)))) AS distance
            FROM zoning_zone 
            WHERE latitude IS NOT NULL 
            AND longitude IS NOT NULL
            ORDER BY distance ASC 
            LIMIT 1
        ");
        
        if ($stmt) {
            $stmt->bind_param("ddd", 
                $coordinates['latitude'], 
                $coordinates['longitude'],
                $coordinates['latitude']
            );
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $zone = $result->fetch_assoc();
                
                // Cache this result for future use
                $insert_stmt = $conn->prepare("
                    INSERT INTO barangay_coordinates 
                    (barangay, city, latitude, longitude, zone_id) 
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        latitude = VALUES(latitude),
                        longitude = VALUES(longitude),
                        zone_id = VALUES(zone_id),
                        last_updated = CURRENT_TIMESTAMP
                ");
                
                if ($insert_stmt) {
                    $insert_stmt->bind_param("ssddi",
                        $barangay,
                        $city,
                        $coordinates['latitude'],
                        $coordinates['longitude'],
                        $zone['id']
                    );
                    $insert_stmt->execute();
                }
                
                return [
                    'zone_id' => $zone['id'],
                    'zone_number' => $zone['zone_number'],
                    'area_center' => $zone['area_center'],
                    'latitude' => $coordinates['latitude'],
                    'longitude' => $coordinates['longitude'],
                    'distance_km' => round($zone['distance'], 2),
                    'source' => $coordinates['source'],
                    'note' => $coordinates['note'] ?? null
                ];
            }
        }
    }
    
    // Final fallback: Use city-only matching
    return getZoneFromCityFallback($city, $conn);
}

// Simple city-based fallback
function getZoneFromCityFallback($city, $conn) {
    $city = strtolower(trim($city));
    
    // Match cities to your actual zone areas
    $city_zone_mapping = [
        // Zone 1: Valenzuela
        'valenzuela' => 1,
        
        // Zone 2: Caloocan
        'caloocan' => 2,
        
        // Zone 3: Quezon City North (Novaliches Area)
        'novaliches' => 3,
        
        // Zone 4: Quezon City Central (Diliman)
        'diliman' => 4,
        'up village' => 4,
        'up campus' => 4,
        
        // Zone 5: Quezon City South/East (Cubao)
        'cubao' => 5,
        'araneta' => 5,
        'santolan' => 5,
        'project' => 5,
        
        // Zone 6: Mandaluyong/San Juan
        'mandaluyong' => 6,
        'san juan' => 6,
        'greenhills' => 6,
        'ortigas ave' => 6,
        
        // Zone 7: Pasig City (Ortigas Center)
        'pasig' => 7,
        'ortigas' => 7,
        'ortigas center' => 7,
        'shaw' => 7,
        
        // Zone 8: Makati City (Ayala Center)
        'makati' => 8,
        'ayala' => 8,
        'ayala center' => 8,
        'salcedo' => 8,
        'legazpi' => 8,
        
        // Zone 9: Manila City (Rizal Park / City Hall)
        'manila' => 9,
        'ermita' => 9,
        'malate' => 9,
        'intramuros' => 9,
        'binondo' => 9,
        'quiapo' => 9,
        'sampaloc' => 9,
        
        // Zone 10: Pasay City (Mall of Asia / Bay City)
        'pasay' => 10,
        'moa' => 10,
        'mall of asia' => 10,
        'bay city' => 10,
        'baclaran' => 10,
        
        // Zone 11: Taguig City (Bonifacio Global City)
        'taguig' => 11,
        'bgc' => 11,
        'bonifacio global city' => 11,
        'global city' => 11,
        'fort bonifacio' => 11,
        
        // Zone 12: Parañaque / Las Piñas
        'parañaque' => 12,
        'paranaque' => 12,
        'las piñas' => 12,
        'las pinas' => 12,
        'bf homes' => 12,
        'alabang' => 12,
        
        // Additional cities
        'marikina' => 7,
        'muntinlupa' => 12,
        'navotas' => 1,
        'malabon' => 2,
    ];
    
    // Check for exact matches first
    foreach ($city_zone_mapping as $key => $zone_num) {
        if (strpos($city, $key) !== false) {
            $zone_info = getZoneInfoByNumber($zone_num, $conn);
            if ($zone_info) {
                return array_merge($zone_info, [
                    'source' => 'city_fallback',
                    'note' => 'Zone assigned based on city only (barangay not found)'
                ]);
            }
        }
    }
    
    // Check for "quezon city" specifically
    if (strpos($city, 'quezon') !== false) {
        $zone_info = getZoneInfoByNumber(4, $conn);
        if ($zone_info) {
            return array_merge($zone_info, [
                'source' => 'quezon_city_default',
                'note' => 'Quezon City defaulted to Zone 4 (Central)'
            ]);
        }
    }
    
    // Default to Zone 9 (Manila)
    $zone_info = getZoneInfoByNumber(9, $conn);
    if ($zone_info) {
        return array_merge($zone_info, [
            'source' => 'default_manila',
            'note' => 'Default to Manila (Zone 9)'
        ]);
    }
    
    // Hardcoded fallback
    return [
        'zone_id' => 9,
        'zone_number' => 9,
        'area_center' => 'Manila City (Rizal Park / City Hall)',
        'source' => 'hardcoded_default',
        'note' => 'Hardcoded fallback - check address accuracy'
    ];
}

// Helper function to get zone info by zone number
function getZoneInfoByNumber($zone_number, $conn) {
    $stmt = $conn->prepare("SELECT id, zone_number, area_center FROM zoning_zone WHERE zone_number = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $zone_number);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            return [
                'zone_id' => $row['id'],
                'zone_number' => $row['zone_number'],
                'area_center' => $row['area_center']
            ];
        }
    }
    return null;
}

// Function to get zone-based reading date
function getZoneReadingDate($zone_number) {
    // Zone 1 = 3rd, Zone 2 = 4th, Zone 3 = 5th, ... Zone 12 = 14th
    // Formula: reading_date = zone_number + 2
    return $zone_number + 2;
}

// Function to get ordinal suffix (1st, 2nd, 3rd, etc.)
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

// Handle form submission
$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $barangay = $_POST['barangay'] ?? '';
    $city = $_POST['city'] ?? '';
    
    // Validate inputs
    if (empty($barangay)) {
        $error = "Barangay is required for accurate zoning";
    } elseif (empty($city)) {
        $error = "City is required";
    } else {
        try {
            $zone_data = getZoneFromLocation($barangay, $city, $conn);
            
            if (isset($zone_data['error'])) {
                $error = $zone_data['error'];
            } elseif ($zone_data) {
                // Get zone-based reading date
                $reading_date = getZoneReadingDate($zone_data['zone_number']);
                $ordinal_date = getOrdinal($reading_date);
                
                $result = [
                    'success' => true,
                    'zone_data' => $zone_data,
                    'reading_date' => $reading_date,
                    'ordinal_date' => $ordinal_date,
                    'address' => [
                        'barangay' => $barangay,
                        'city' => $city
                    ]
                ];
            } else {
                $error = "Could not determine zone for the given address";
            }
        } catch (Exception $e) {
            $error = "Error processing request: " . $e->getMessage();
        }
    }
}

// Get all zones for reference
$zones_query = $conn->query("SELECT * FROM zoning_zone ORDER BY zone_number");
$all_zones = [];
while ($zone = $zones_query->fetch_assoc()) {
    $all_zones[] = $zone;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Zone by Address</title>
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            padding: 20px; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container { 
            max-width: 900px; 
            margin: 0 auto; 
            background: white; 
            padding: 40px; 
            border-radius: 15px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.2); 
        }
        h1 { 
            color: #333; 
            text-align: center; 
            margin-bottom: 10px; 
            border-bottom: none;
            padding-bottom: 0;
        }
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 1.1em;
        }
        .form-section {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            border: 1px solid #e9ecef;
        }
        .form-group { 
            margin-bottom: 20px; 
        }
        label { 
            display: block; 
            margin-bottom: 8px; 
            font-weight: 600; 
            color: #495057; 
        }
        .required-star {
            color: #dc3545;
        }
        input { 
            width: 100%; 
            padding: 14px; 
            border: 2px solid #dee2e6; 
            border-radius: 8px; 
            font-size: 16px; 
            box-sizing: border-box; 
            transition: border-color 0.3s;
        }
        input:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        button { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            color: white; 
            padding: 16px 32px; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            font-size: 18px; 
            font-weight: 600; 
            width: 100%; 
            transition: transform 0.2s, box-shadow 0.2s; 
        }
        button:hover { 
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        button:active {
            transform: translateY(0);
        }
        .result-container { 
            margin-top: 30px; 
            padding: 30px; 
            border-radius: 10px; 
            display: none; 
            animation: fadeIn 0.5s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .success { 
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); 
            border-left: 5px solid #28a745; 
        }
        .error { 
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%); 
            border-left: 5px solid #dc3545; 
            padding: 20px; 
            margin-top: 20px; 
            border-radius: 8px;
        }
        .zone-badge { 
            display: inline-block; 
            padding: 15px 30px; 
            background: linear-gradient(135deg, #2196F3 0%, #1976D2 100%); 
            color: white; 
            border-radius: 10px; 
            font-size: 32px; 
            font-weight: bold; 
            margin: 15px 0; 
            text-align: center;
            box-shadow: 0 5px 15px rgba(33, 150, 243, 0.3);
        }
        .info-box { 
            background: white; 
            padding: 20px; 
            border-radius: 8px; 
            margin: 20px 0; 
            border: 1px solid #dee2e6;
        }
        .info-label { 
            color: #6c757d; 
            font-weight: 600; 
            margin-right: 10px; 
            min-width: 120px;
            display: inline-block;
        }
        .info-value { 
            color: #212529; 
            font-weight: normal; 
        }
        .action-buttons { 
            display: flex; 
            gap: 15px; 
            margin-top: 30px; 
        }
        .btn-secondary { 
            background: linear-gradient(135deg, #28a745 0%, #218838 100%); 
            flex: 1; 
            text-align: center; 
            padding: 14px; 
            text-decoration: none; 
            border-radius: 8px;
            font-weight: 600;
            transition: transform 0.2s;
        }
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }
        .btn-tertiary { 
            background: linear-gradient(135deg, #ff9800 0%, #e68900 100%); 
            flex: 1; 
            text-align: center; 
            padding: 14px; 
            text-decoration: none; 
            border-radius: 8px;
            font-weight: 600;
            transition: transform 0.2s;
        }
        .btn-tertiary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 152, 0, 0.3);
        }
        .zones-reference { 
            margin-top: 40px; 
            background: #f8f9fa; 
            padding: 25px; 
            border-radius: 10px; 
            border: 1px solid #e9ecef;
        }
        .zones-reference h3 { 
            color: #495057; 
            margin-top: 0; 
            margin-bottom: 20px;
            text-align: center;
        }
        .zones-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); 
            gap: 15px; 
            margin-top: 15px; 
        }
        .zone-item { 
            background: white; 
            padding: 15px; 
            border-radius: 8px; 
            border: 1px solid #dee2e6;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .zone-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .zone-item .zone-num { 
            font-weight: bold; 
            color: #2196F3; 
            font-size: 1.2em;
            margin-bottom: 5px;
        }
        .zone-item .area-center { 
            color: #495057;
            font-size: 0.9em;
            margin-bottom: 5px;
            min-height: 40px;
        }
        .zone-item .reading-date { 
            color: #28a745; 
            font-size: 0.9em; 
            font-weight: 600;
        }
        .back-link { 
            margin-bottom: 20px; 
        }
        .back-link a { 
            color: #667eea; 
            text-decoration: none; 
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .back-link a:hover { 
            text-decoration: underline; 
        }
        .coordinates { 
            font-size: 0.9em; 
            color: #6c757d; 
            margin-top: 5px; 
            font-family: monospace;
        }
        .source-badge { 
            display: inline-block; 
            padding: 6px 12px; 
            background: #ffeb3b; 
            color: #333; 
            border-radius: 20px; 
            font-size: 0.8em; 
            margin-left: 10px; 
            font-weight: 600;
        }
        .note-box {
            background: #e8f4f8;
            padding: 10px 15px;
            border-radius: 6px;
            margin: 10px 0;
            border-left: 4px solid #17a2b8;
            font-size: 0.9em;
            color: #0c5460;
        }
        .schedule-box {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-left: 5px solid #2196F3;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .formula-box {
            background: #e8f5e9;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            border: 1px solid #c3e6cb;
        }
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }
        .loading-spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .hint-text {
            color: #6c757d;
            font-size: 0.9em;
            margin-top: 5px;
            display: block;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="back-link">
            <a href="dashboard.php">← Back to Dashboard</a>
        </div>
        
        <h1>📍 Zone Locator</h1>
        <p class="subtitle">
            Enter barangay and city for accurate service zone assignment
        </p>
        
        <div class="form-section">
            <form id="checkZoneForm" method="POST">
                <div class="form-group">
                    <label for="barangay">Barangay <span class="required-star">*</span></label>
                    <input type="text" id="barangay" name="barangay" required
                           placeholder="e.g., Barangay Addition Hills, Barangay Holy Spirit">
                    <span class="hint-text">Required for accurate zoning. Use full barangay name.</span>
                </div>
                
                <div class="form-group">
                    <label for="city">City/Municipality <span class="required-star">*</span></label>
                    <input type="text" id="city" name="city" required 
                           placeholder="e.g., Manila, Quezon City, Makati, Pasig, Taguig">
                    <span class="hint-text">Required. Use official city/municipality name.</span>
                </div>
                
                <button type="submit" id="submitBtn">
                    🔍 Check Zone Assignment
                </button>
            </form>
            
            <div class="loading" id="loading">
                <div class="loading-spinner"></div>
                <p>Calculating zone assignment...</p>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class="error">
                <strong>⚠️ Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($result): ?>
            <div id="resultContainer" class="result-container success" style="display: block;">
                <h2 style="color: #28a745; margin-top: 0;">✅ Zone Assignment Found!</h2>
                
                <div style="text-align: center;">
                    <div class="zone-badge">
                        Zone <?php echo $result['zone_data']['zone_number']; ?>
                    </div>
                </div>
                
                <?php if (isset($result['zone_data']['note'])): ?>
                    <div class="note-box">
                        <strong>Note:</strong> <?php echo htmlspecialchars($result['zone_data']['note']); ?>
                    </div>
                <?php endif; ?>
                
                <div class="info-box">
                    <p><span class="info-label">Service Area:</span> 
                       <span class="info-value"><?php echo htmlspecialchars($result['zone_data']['area_center']); ?></span></p>
                    
                    <p><span class="info-label">Address Checked:</span> 
                       <span class="info-value">
                           <strong><?php echo htmlspecialchars($result['address']['barangay']); ?></strong>, 
                           <strong><?php echo htmlspecialchars($result['address']['city']); ?></strong>
                       </span></p>
                    
                    <?php if (isset($result['zone_data']['latitude']) && isset($result['zone_data']['longitude'])): ?>
                        <p class="coordinates">
                            📍 GPS Coordinates: <?php echo number_format($result['zone_data']['latitude'], 6); ?>, 
                            <?php echo number_format($result['zone_data']['longitude'], 6); ?>
                        </p>
                    <?php endif; ?>
                    
                    <?php if (isset($result['zone_data']['distance_km'])): ?>
                        <p class="coordinates">
                            📏 Distance to zone center: <?php echo $result['zone_data']['distance_km']; ?> km
                        </p>
                    <?php endif; ?>
                    
                    <p><span class="info-label">Data Source:</span> 
                       <span class="source-badge"><?php echo ucfirst(str_replace('_', ' ', $result['zone_data']['source'])); ?></span></p>
                </div>
                
                <div class="schedule-box">
                    <h3 style="margin-top: 0; color: #1976d2;">📅 Service Schedule</h3>
                    <p style="font-size: 1.2em; margin: 10px 0;">
                        <span class="info-label">Reading Date:</span> 
                        <span style="color: #2196F3; font-weight: bold; font-size: 1.4em;">
                            <?php echo $result['ordinal_date']; ?> of every month
                        </span>
                    </p>
                    <p style="color: #666;">
                        <em>All machines in Zone <?php echo $result['zone_data']['zone_number']; ?> are serviced on the 
                        <?php echo $result['ordinal_date']; ?> day of each month</em>
                    </p>
                </div>
                
                <div class="action-buttons">
                    <a href="check_zone.php" class="btn-secondary">
                        🔄 Check Another Address
                    </a>
                    <a href="add_client.php" class="btn-tertiary">
                        ➕ Add Client with this Address
                    </a>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="zones-reference">
            <h3>📋 Metro Manila Zone Map</h3>
            <p style="text-align: center; color: #666; margin-bottom: 20px;">
                All zones follow fixed monthly reading schedules. Zone assignment is based on barangay.
            </p>
            
            <div class="zones-grid">
                <?php foreach ($all_zones as $zone): 
                    $reading_date = $zone['zone_number'] + 2;
                    $ordinal_date = getOrdinal($reading_date);
                ?>
                    <div class="zone-item">
                        <div class="zone-num">Zone <?php echo $zone['zone_number']; ?></div>
                        <div class="area-center"><?php echo htmlspecialchars($zone['area_center']); ?></div>
                        <div class="reading-date">📅 <?php echo $ordinal_date; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="formula-box">
                <strong>📝 Zone Schedule Formula:</strong> Reading Date = Zone Number + 2<br>
                <strong>📍 Zone Assignment Priority:</strong> Barangay → City → Nearest Zone Center<br>
                <small>Example: Zone 5 (Cubao) reads on the 7th day of each month (5 + 2 = 7)</small>
            </div>
        </div>
    </div>
    
    <script>
    document.getElementById('checkZoneForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const barangayInput = document.getElementById('barangay');
        const cityInput = document.getElementById('city');
        const submitBtn = document.getElementById('submitBtn');
        const loadingDiv = document.getElementById('loading');
        const resultContainer = document.getElementById('resultContainer');
        
        // Validate inputs
        if (!barangayInput.value.trim()) {
            alert('Please enter a barangay for accurate zoning');
            barangayInput.focus();
            return false;
        }
        
        if (!cityInput.value.trim()) {
            alert('Please enter a city/municipality');
            cityInput.focus();
            return false;
        }
        
        // Show loading, hide button
        submitBtn.style.display = 'none';
        loadingDiv.style.display = 'block';
        
        // Hide previous results
        if (resultContainer) {
            resultContainer.style.display = 'none';
        }
        
        // Submit form after a brief delay to show loading animation
        setTimeout(() => {
            this.submit();
        }, 500);
        
        return true;
    });
    
    // Auto-focus on barangay input if no result is shown
    <?php if (!$result && !$error): ?>
        document.getElementById('barangay').focus();
    <?php endif; ?>
    
    // Add autocomplete suggestions for cities
    const citySuggestions = [
        'Manila', 'Quezon City', 'Caloocan', 'Pasig', 'Valenzuela',
        'Makati', 'Taguig', 'Mandaluyong', 'San Juan', 'Pasay',
        'Parañaque', 'Las Piñas', 'Marikina', 'Muntinlupa', 'Navotas', 'Malabon'
    ];
    
    const cityInput = document.getElementById('city');
    cityInput.addEventListener('input', function() {
        const value = this.value.toLowerCase();
        if (value.length > 1) {
            // You could implement a dropdown here if needed
        }
    });
    </script>
</body>
</html>