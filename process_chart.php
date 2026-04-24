<?php
header('Content-Type: application/json');

/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/
$location = trim($_GET['location'] ?? '');
$parts = array_map('trim', explode(',', $location));
$state = $parts[1] ?? $parts[0] ?? '';

$year     = $_GET['year'] ?? '';
$month    = $_GET['month'] ?? '';
$day      = $_GET['day'] ?? '';
$hour     = $_GET['hour'] ?? '';
$minutes  = $_GET['minutes'] ?? '';
$house_system = $_GET['house_system'] ?? 'P';
$full_name = $_GET['full_name'] ?? 'User';
$gender = $_GET['gender'] ?? 'male';
$language = $_GET['language'] ?? 'en';

if (!$state || !$year || !$month || !$day) {
    echo json_encode(['error' => 'Required fields missing'], JSON_PRETTY_PRINT);
    exit;
}

/*
|--------------------------------------------------------------------------
| API KEYS
|--------------------------------------------------------------------------
*/
$bg_api_key = '264fa2e3-ac96-4e0f-b864-fea07186cbe6';
$divine_api_key = '14ee6b5a4b383bf53f86cdcb59edaeb8';
$divine_bearer_token = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczovL2RpdmluZWFwaS5jb20vc2lnbnVwIiwiaWF0IjoxNzc2MDcxNjA4LCJuYmYiOjE3NzYwNzE2MDgsImp0aSI6ImU2WWoydTNlc0dUVkpUZjMiLCJzdWIiOiI0OTc4IiwicHJ2IjoiZTZlNjRiYjBiNjEyNmQ3M2M2Yjk3YWZjM2I0NjRkOTg1ZjQ2YzlkNyJ9.t_M-B8D-tboYJ2GEfT4bjTe3ATlLNv0GK2-US_lrvvg';
$google_api_key = 'AIzaSyBH5t_bIpTznpCj-zYUU1klq3n9ZhG_FR8';

/*
|--------------------------------------------------------------------------
| FUNCTION: CURL REQUEST
|--------------------------------------------------------------------------
*/
function makeRequest($url, $method = 'GET', $postData = null, $bearerToken = null) {
    $ch = curl_init();
    
    $headers = ['Accept: application/json'];
    if ($bearerToken) {
        $headers[] = "Authorization: Bearer $bearerToken";
    }
    
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ];
    
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        if ($postData) {
            $options[CURLOPT_POSTFIELDS] = http_build_query($postData);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $options[CURLOPT_HTTPHEADER] = $headers;
        }
    }
    
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($error) {
        return ['error' => $error, 'http_code' => $httpCode];
    }
    
    return json_decode($response, true);
}

/*
|--------------------------------------------------------------------------
| STEP 1: GOOGLE MAPS GEOCODING (LAT / LNG)
|--------------------------------------------------------------------------
*/
$geocode_url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($state) . "&key={$google_api_key}";
$geoData = makeRequest($geocode_url);

if (empty($geoData['results'][0])) {
    echo json_encode(['error' => 'Google Geocoding failed', 'response' => $geoData], JSON_PRETTY_PRINT);
    exit;
}

$latitude = $geoData['results'][0]['geometry']['location']['lat'];
$longitude = $geoData['results'][0]['geometry']['location']['lng'];
$formatted_address = $geoData['results'][0]['formatted_address'];

// Extract city from address components
$city = '';
foreach ($geoData['results'][0]['address_components'] as $component) {
    if (in_array('locality', $component['types'])) {
        $city = $component['long_name'];
        break;
    }
}
if (empty($city)) {
    foreach ($geoData['results'][0]['address_components'] as $component) {
        if (in_array('administrative_area_level_1', $component['types'])) {
            $city = $component['long_name'];
            break;
        }
    }
}

/*
|--------------------------------------------------------------------------
| STEP 2: GOOGLE TIMEZONE API
|--------------------------------------------------------------------------
*/
$timestamp = time();
$timezone_url = "https://maps.googleapis.com/maps/api/timezone/json?location={$latitude},{$longitude}&timestamp={$timestamp}&key={$google_api_key}";
$tzData = makeRequest($timezone_url);

$timezone = $tzData['timeZoneId'] ?? 'Europe/London';
$rawOffset = $tzData['rawOffset'] ?? 0;
$tzone = $rawOffset / 3600;

/*
|--------------------------------------------------------------------------
| STEP 3: BODYGRAPH LOCATION API
|--------------------------------------------------------------------------
*/
$bg_url = "https://api.bodygraphchart.com/v210502/locations?api_key={$bg_api_key}&query=" . urlencode($city ?: $state);
$bgData = makeRequest($bg_url);

if (empty($bgData) || isset($bgData['error'])) {
    $bgData = [['value' => $formatted_address, 'timezone' => $timezone]];
}

$fullAddress = $bgData[0]['value'] ?? $formatted_address;
$bgTimezone = $bgData[0]['timezone'] ?? $timezone;

/*
|--------------------------------------------------------------------------
| STEP 4: BODYGRAPH HD API
|--------------------------------------------------------------------------
*/
$hour_input = max(0, min(23, intval($hour)));
$minutes_input = max(0, min(59, intval($minutes)));
$birth_time = sprintf('%02d:%02d', $hour_input, $minutes_input);
$birth_date_time_for_api = "$year-$month-$day $birth_time";

$hd_url = 'https://api.bodygraphchart.com/v221006/hd-data?api_key='
    . urlencode($bg_api_key)
    . '&date=' . urlencode($birth_date_time_for_api)
    . '&timezone=' . urlencode($bgTimezone);

$hd_response = makeRequest($hd_url);

/*
|--------------------------------------------------------------------------
| STEP 5: DIVINE API PARAMS
|--------------------------------------------------------------------------
*/
$divineParams = [
    'api_key' => $divine_api_key,
    'full_name' => $full_name,
    'day' => (int)$day,
    'month' => (int)$month,
    'year' => (int)$year,
    'hour' => (int)$hour,
    'min' => (int)$minutes,
    'sec' => 0,
    'gender' => $gender,
    'place' => $city ?: $state,
    'lat' => (float)$latitude,
    'lon' => (float)$longitude,
    'tzone' => (float)$tzone,
    'lan' => $language,
    'house_system' => $house_system
];

/*
|--------------------------------------------------------------------------
| STEP 6: DIVINE API REQUESTS (ALL ENDPOINTS)
|--------------------------------------------------------------------------
*/

// 1. Moon Phases
$moonPhases = makeRequest(
    'https://astroapi-4.divineapi.com/western-api/v2/moon-phases',
    'POST',
    $divineParams,
    $divine_bearer_token
);

// 2. Aspect Table
$aspectParams = array_merge($divineParams, [
    'aspect_orbs_type' => 'FIXED',
    'aspect_orbs_value' => '5_30',
    'aspects_type' => 'ALL'
]);
$aspectTable = makeRequest(
    'https://astroapi-8.divineapi.com/western-api/v2/aspect-table',
    'POST',
    $aspectParams,
    $divine_bearer_token
);

// 3. House Cusps
$houseParams = array_merge($divineParams, ['with_rulers' => 1]);
$houseCusps = makeRequest(
    'https://astroapi-4.divineapi.com/western-api/v1/house-cusps',
    'POST',
    $houseParams,
    $divine_bearer_token
);

// 4. Planetary Positions
$planetaryPositions = makeRequest(
    'https://astroapi-4.divineapi.com/western-api/v1/planetary-positions',
    'POST',
    $divineParams,
    $divine_bearer_token
);

// 5. Natal Astrology
$natalParams = array_merge($divineParams, ['node_type' => 'meannode']);
$natalAstrology = makeRequest(
    'https://astroapi-4.divineapi.com/western-api/v1/natal',
    'POST',
    $natalParams,
    $divine_bearer_token
);

/*
|--------------------------------------------------------------------------
| FINAL RESPONSE - DIRECT, NO FORMATTING
|--------------------------------------------------------------------------
*/
$result = [
    'geocoding' => [
        'google_maps_response' => $geoData,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'formatted_address' => $formatted_address,
        'city' => $city
    ],
    'timezone' => [
        'google_timezone_response' => $tzData,
        'timezone_id' => $timezone,
        'offset_hours' => $tzone
    ],
    'bodygraph_location' => $bgData,
    'bodygraph_hd' => $hd_response,
    'divineapi' => [
        'moon_phases' => $moonPhases,
        'aspect_table' => $aspectTable,
        'house_cusps' => $houseCusps,
        'planetary_positions' => $planetaryPositions,
        'natal_astrology' => $natalAstrology
    ],
    'request_params' => [
        'datetime' => $birth_date_time_for_api,
        'house_system' => $house_system,
        'language' => $language,
        'full_name' => $full_name,
        'gender' => $gender
    ]
];

echo json_encode($result, JSON_PRETTY_PRINT);