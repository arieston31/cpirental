<?php
require_once 'config.php';

header('Content-Type: application/json');

$barangay = $_POST['barangay'] ?? '';
$city = $_POST['city'] ?? '';
$client_id = $_POST['client_id'] ?? 0;

// Include the same functions from add_machine.php
// We'll copy the essential functions here instead of including

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
    if (empty($city)) {
        return null;
    }
    
    // Build address query
    $query = '';
    if (!empty($barangay)) {
        $query .= $barangay . ', ';
    }
    $query .= $city . ', Philippines';
    
    $address = urlencode($query);
    $url = "https://nominatim.openstreetmap.org/search?format=json&q={$address}&limit=1&countrycodes=PH";
    
    // Add user agent as required by Nominatim's ToS
    $options = [
        'http' => [
            'header' => "User-Agent: ZoningSystem/1.0\r\n"
        ]
    ];
    
    $context = stream_context_create($options);
    
    try {
        $response = @file_get_contents($url, false, $context);
        
        if ($response === FALSE) {
            error_log("OSM Geocoding failed for: $barangay, $city - URL: $url");
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (!empty($data) && isset($data[0]['lat']) && isset($data[0]['lon'])) {
            error_log("OSM Geocoding success for: $barangay, $city - Lat: {$data[0]['lat']}, Lon: {$data[0]['lon']}");
            return [
                'latitude' => floatval($data[0]['lat']),
                'longitude' => floatval($data[0]['lon']),
                'display_name' => $data[0]['display_name'],
                'source' => 'osm'
            ];
        } else {
            error_log("OSM Geocoding no results for: $barangay, $city");
            return null;
        }
    } catch (Exception $e) {
        error_log("OSM Geocoding exception: " . $e->getMessage());
        return null;
    }
}

// Get city centroid (fallback when geocoding fails)
function getCityCentroid($city) {
    $cityCentroids = [
        'manila' => ['lat' => 14.5995, 'lon' => 120.9842],
        'quezon city' => ['lat' => 14.6760, 'lon' => 121.0437],
        'caloocan' => ['lat' => 14.6492, 'lon' => 120.9679],
        'pasig' => ['lat' => 14.5604, 'lon' => 121.0810],
        'mandaluyong' => ['lat' => 14.5794, 'lon' => 121.0359],
        'makati' => ['lat' => 14.5547, 'lon' => 121.0244],
        'taguig' => ['lat' => 14.5176, 'lon' => 121.0509],
        'pasay' => ['lat' => 14.5378, 'lon' => 121.0014],
        'parañaque' => ['lat' => 14.4793, 'lon' => 121.0198],
        'las piñas' => ['lat' => 14.4447, 'lon' => 120.9937],
        'valenzuela' => ['lat' => 14.7004, 'lon' => 120.9831],
        'san juan' => ['lat' => 14.6036, 'lon' => 121.0334],
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
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'source' => 'default_centroid'
    ];
}

// Function to get zone based on geographical location
function getZoneFromLocation($barangay, $city, $conn) {
    $barangay = trim($barangay);
    $city = trim($city);
    
    if (empty($city)) {
        return getZoneFromCityFallback($city, $conn);
    }
    
    // First, check our local cache
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
    
    // If not in cache, try to geocode
    $coordinates = null;
    
    if (!empty($barangay) || !empty($city)) {
        // Try OpenStreetMap
        $coordinates = geocodeOSM($barangay, $city);
        
        // If OSM fails, use city centroid as fallback
        if (!$coordinates) {
            $coordinates = getCityCentroid($city);
        }
    }
    
    if ($coordinates) {
        error_log("Found coordinates: Lat={$coordinates['latitude']}, Lon={$coordinates['longitude']}, Source={$coordinates['source']}");
        
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
                if (!empty($barangay)) {
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
                }
                
                return [
                    'zone_id' => $zone['id'],
                    'zone_number' => $zone['zone_number'],
                    'area_center' => $zone['area_center'],
                    'latitude' => $coordinates['latitude'],
                    'longitude' => $coordinates['longitude'],
                    'distance_km' => round($zone['distance'], 2),
                    'source' => $coordinates['source']
                ];
            } else {
                error_log("No zones found with coordinates in database");
            }
        } else {
            error_log("Prepare statement failed for zone distance calculation");
        }
    } else {
        error_log("Could not get coordinates for: $barangay, $city");
    }
    
    // Final fallback: Use city-only matching
    return getZoneFromCityFallback($city, $conn);
}

// Simple city-based fallback
function getZoneFromCityFallback($city, $conn) {
    $city = strtolower(trim($city));
    $zone_mapping = [
        'manila' => 1,
        'caloocan' => 2,
        'quezon city' => 3,
        'pasig' => 6,
        'mandaluyong' => 7,
        'san juan' => 7,
        'makati' => 8,
        'taguig' => 9,
        'parañaque' => 10,
        'las piñas' => 10,
        'pasay' => 11,
        'valenzuela' => 12
    ];
    
    foreach ($zone_mapping as $key => $zone_num) {
        if (strpos($city, $key) !== false) {
            $stmt = $conn->prepare("SELECT id, zone_number, area_center FROM zoning_zone WHERE zone_number = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $zone_num);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    return [
                        'zone_id' => $row['id'],
                        'zone_number' => $row['zone_number'],
                        'area_center' => $row['area_center'],
                        'source' => 'city_fallback'
                    ];
                }
            }
        }
    }
    
    // Default to Zone 1 (Manila)
    $stmt = $conn->prepare("SELECT id, zone_number, area_center FROM zoning_zone WHERE zone_number = 1 LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $default = $result->fetch_assoc();
        
        return [
            'zone_id' => $default['id'] ?? 1,
            'zone_number' => $default['zone_number'] ?? 1,
            'area_center' => $default['area_center'] ?? 'Manila',
            'source' => 'default'
        ];
    }
    
    return [
        'zone_id' => 1,
        'zone_number' => 1,
        'area_center' => 'Manila',
        'source' => 'hardcoded_default'
    ];
}

// Main execution
if (empty($city)) {
    echo json_encode(['success' => false, 'error' => 'City is required']);
    exit;
}

try {
    $zone_data = getZoneFromLocation($barangay, $city, $conn);
    
    if ($zone_data) {
        echo json_encode([
            'success' => true,
            'zone_id' => $zone_data['zone_id'],
            'zone_number' => $zone_data['zone_number'],
            'area_center' => $zone_data['area_center'],
            'latitude' => $zone_data['latitude'] ?? null,
            'longitude' => $zone_data['longitude'] ?? null,
            'distance_km' => $zone_data['distance_km'] ?? null,
            'source' => $zone_data['source']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not determine zone']);
    }
} catch (Exception $e) {
    error_log("Error in get_zone_from_location.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error occurred: ' . $e->getMessage()]);
}
?>