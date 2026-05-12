<?php
/*
|--------------------------------------------------------------------------
| IOS + OPENAI + RAILWAY STABILITY FIX - FINAL VERSION
|--------------------------------------------------------------------------
*/

// Clear any existing output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Set error reporting for production
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Extended timeouts for mobile/streaming
ini_set('max_execution_time', 300);
ini_set('default_socket_timeout', 300);
ini_set('memory_limit', '512M');
set_time_limit(300);

// Disable ignore_user_abort for better mobile handling
ignore_user_abort(false);

// Flush immediately
ob_implicit_flush(true);

/*
|--------------------------------------------------------------------------
| HEADERS - OPTIMIZED FOR MOBILE/STREAMING
|--------------------------------------------------------------------------
*/

// Clear existing headers
header_remove();

// JSON Response
header('Content-Type: application/json; charset=utf-8');

// CORS Headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Mobile/Safari specific headers
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Connection: close');

// Disable buffering for streaming
header('X-Accel-Buffering: no');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer-when-downgrade');

/*
|--------------------------------------------------------------------------
| INPUT VALIDATION
|--------------------------------------------------------------------------
*/

$location = trim($_GET['location'] ?? '');
if (empty($location)) {
    http_response_code(400);
    echo json_encode(['error' => 'Location is required'], JSON_PRETTY_PRINT);
    exit;
}

$parts = array_map('trim', explode(',', $location));
$state = $parts[1] ?? $parts[0] ?? '';

$year = isset($_GET['year']) ? filter_var($_GET['year'], FILTER_VALIDATE_INT) : null;
$month = isset($_GET['month']) ? filter_var($_GET['month'], FILTER_VALIDATE_INT) : null;
$day = isset($_GET['day']) ? filter_var($_GET['day'], FILTER_VALIDATE_INT) : null;
$hour = isset($_GET['hour']) ? filter_var($_GET['hour'], FILTER_VALIDATE_INT) : 12;
$minutes = isset($_GET['minutes']) ? filter_var($_GET['minutes'], FILTER_VALIDATE_INT) : 0;
$house_system = $_GET['house_system'] ?? 'P';
$full_name = $_GET['full_name'] ?? 'User';
$gender = $_GET['gender'] ?? 'male';
$language = $_GET['language'] ?? 'en';
$debug = filter_var($_GET['debug'] ?? false, FILTER_VALIDATE_BOOLEAN);

// Validate required fields
if (!$state || !$year || !$month || !$day) {
    http_response_code(400);
    echo json_encode(['error' => 'Required fields missing: state, year, month, day are required'], JSON_PRETTY_PRINT);
    exit;
}

// Validate date ranges
if ($year < 1900 || $year > 2100 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date provided'], JSON_PRETTY_PRINT);
    exit;
}

// Validate house system
$validHouseSystems = ['P', 'K', 'O', 'R', 'C', 'A', 'E', 'V', 'W', 'N', 'B', 'M'];
if (!in_array($house_system, $validHouseSystems)) {
    $house_system = 'P';
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
| FUNCTION: IMPROVED CURL WITH RETRY LOGIC
|--------------------------------------------------------------------------
*/
function makeRequest($url, $method = 'GET', $postData = null, $bearerToken = null, $maxRetries = 2) {
    $retryCount = 0;
    $lastError = null;
    
    while ($retryCount <= $maxRetries) {
        $ch = curl_init();
        
        $headers = ['Accept: application/json', 'User-Agent: AstroAPI/1.0'];
        if ($bearerToken) {
            $headers[] = "Authorization: Bearer $bearerToken";
        }
        
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 30,
        ];
        
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            if ($postData) {
                $options[CURLOPT_POSTFIELDS] = http_build_query($postData);
            }
        }
        
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if (!$error && $httpCode === 200) {
            $decoded = json_decode($response, true);
            if ($decoded !== null) {
                return $decoded;
            }
        }
        
        $lastError = $error ?: "HTTP $httpCode";
        $retryCount++;
        
        if ($retryCount <= $maxRetries) {
            usleep(500000); // 0.5 second delay before retry
        }
    }
    
    return ['error' => $lastError ?: 'Request failed after ' . ($maxRetries + 1) . ' attempts'];
}

/*
|--------------------------------------------------------------------------
| STEP 1: GOOGLE MAPS GEOCODING
|--------------------------------------------------------------------------
*/
$geocode_url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($location) . "&key={$google_api_key}";
$geoData = makeRequest($geocode_url);

if (empty($geoData['results'][0])) {
    http_response_code(404);
    echo json_encode(['error' => 'Location not found. Please check the location name.'], JSON_PRETTY_PRINT);
    exit;
}

$latitude = $geoData['results'][0]['geometry']['location']['lat'];
$longitude = $geoData['results'][0]['geometry']['location']['lng'];

// Extract city name
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
if (empty($city)) {
    $city = $state;
}

/*
|--------------------------------------------------------------------------
| STEP 2: TIMEZONE DETECTION
|--------------------------------------------------------------------------
*/
$timestamp = strtotime("$year-$month-$day $hour:$minutes:00");
if ($timestamp === false || $timestamp <= 0) {
    $timestamp = time();
}

$timezone_url = "https://maps.googleapis.com/maps/api/timezone/json?location={$latitude},{$longitude}&timestamp={$timestamp}&key={$google_api_key}";
$tzData = makeRequest($timezone_url);

$timezone = $tzData['timeZoneId'] ?? 'UTC';
$rawOffset = $tzData['rawOffset'] ?? 0;
$dstOffset = $tzData['dstOffset'] ?? 0;
$tzone = ($rawOffset + $dstOffset) / 3600;

/*
|--------------------------------------------------------------------------
| STEP 3: NORMALIZE TIME
|--------------------------------------------------------------------------
*/
$hour_input = max(0, min(23, intval($hour)));
$minutes_input = max(0, min(59, intval($minutes)));
$seconds_input = 0;
$birth_time = sprintf('%02d:%02d', $hour_input, $minutes_input);
$birth_date_time = "$year-$month-$day $birth_time";

/*
|--------------------------------------------------------------------------
| STEP 4: HUMAN DESIGN API (with fallback)
|--------------------------------------------------------------------------
*/
$hd_response = ['error' => 'Service temporarily unavailable'];

try {
    $bg_url = "https://api.bodygraphchart.com/v210502/locations?api_key={$bg_api_key}&query=" . urlencode($city ?: $state);
    $bgData = makeRequest($bg_url);
    
    $bgTimezone = $timezone;
    if (!empty($bgData) && !isset($bgData['error']) && isset($bgData[0]['timezone'])) {
        $bgTimezone = $bgData[0]['timezone'];
    }
    
    $hd_url = 'https://api.bodygraphchart.com/v221006/hd-data?api_key='
        . urlencode($bg_api_key)
        . '&date=' . urlencode($birth_date_time)
        . '&timezone=' . urlencode($bgTimezone);
    
    $hd_response = makeRequest($hd_url);
} catch (Exception $e) {
    // Keep default error response
}

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
    'hour' => (int)$hour_input,
    'min' => (int)$minutes_input,
    'sec' => $seconds_input,
    'gender' => $gender,
    'place' => $city ?: $state,
    'lat' => (float)$latitude,
    'lon' => (float)$longitude,
    'tzone' => (float)$tzone,
    'lan' => $language,
    'house_system' => $house_system,
    'node_type' => 'meannode'
];

/*
|--------------------------------------------------------------------------
| STEP 6: EXECUTE DIVINE API REQUESTS
|--------------------------------------------------------------------------
*/

// House Cusps
$houseParams = array_merge($divineParams, ['with_rulers' => 1]);
$houseCusps = makeRequest(
    'https://astroapi-4.divineapi.com/western-api/v1/house-cusps',
    'POST',
    $houseParams,
    $divine_bearer_token
);

// Planetary Positions
$extendedParams = array_merge($divineParams, [
    'with_house' => 1,
    'with_retrograde' => 1,
    'with_full_degree' => 1
]);
$planetaryPositions = makeRequest(
    'https://astroapi-4.divineapi.com/western-api/v1/planetary-positions',
    'POST',
    $extendedParams,
    $divine_bearer_token
);

// Aspect Table
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

// Check if critical data is available
if ((empty($houseCusps['success']) || $houseCusps['success'] != 1) && 
    (empty($planetaryPositions['success']) || $planetaryPositions['success'] != 1)) {
    http_response_code(503);
    echo json_encode(['error' => 'Astrology service temporarily unavailable. Please try again later.'], JSON_PRETTY_PRINT);
    exit;
}

/*
|--------------------------------------------------------------------------
| STEP 7: HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function decimalToDMS($decimalDegree) {
    $degrees = floor($decimalDegree);
    $minutesDecimal = ($decimalDegree - $degrees) * 60;
    $minutes = floor($minutesDecimal);
    $seconds = round(($minutesDecimal - $minutes) * 60);
    
    if ($seconds == 60) {
        $seconds = 0;
        $minutes++;
    }
    if ($minutes == 60) {
        $minutes = 0;
        $degrees++;
    }
    
    return sprintf("%d°%02d'%02d\"", $degrees, $minutes, $seconds);
}

function getDegreeInSign($fullDegree) {
    return fmod($fullDegree, 30);
}

function getSignFromDegree($fullDegree) {
    $signs = ['Aries', 'Taurus', 'Gemini', 'Cancer', 'Leo', 'Virgo', 
              'Libra', 'Scorpio', 'Sagittarius', 'Capricorn', 'Aquarius', 'Pisces'];
    
    $normalizedDegree = fmod($fullDegree, 360);
    if ($normalizedDegree < 0) $normalizedDegree += 360;
    
    $signIndex = floor($normalizedDegree / 30);
    return $signs[$signIndex % 12];
}

function getHouseNumberFromDegree($degree, $houseCuspsData) {
    if (!$houseCuspsData || !isset($houseCuspsData['data']['houses'])) {
        return null;
    }
    
    $normalizedDegree = fmod($degree, 360);
    if ($normalizedDegree < 0) $normalizedDegree += 360;
    
    $houses = $houseCuspsData['data']['houses'];
    
    $cuspDegrees = [];
    foreach ($houses as $house) {
        $cuspDegree = fmod((float)$house['full_degree'], 360);
        if ($cuspDegree < 0) $cuspDegree += 360;
        $cuspDegrees[] = [
            'house' => $house['house'],
            'degree' => $cuspDegree
        ];
    }
    
    usort($cuspDegrees, function($a, $b) {
        return $a['degree'] - $b['degree'];
    });
    
    for ($i = 0; $i < count($cuspDegrees); $i++) {
        $start = $cuspDegrees[$i]['degree'];
        $end = $cuspDegrees[($i + 1) % count($cuspDegrees)]['degree'];
        
        if ($start > $end) {
            if ($normalizedDegree >= $start || $normalizedDegree < $end) {
                return $cuspDegrees[$i]['house'];
            }
        } else {
            if ($normalizedDegree >= $start && $normalizedDegree < $end) {
                return $cuspDegrees[$i]['house'];
            }
        }
    }
    
    return null;
}

function getAccurateHouseWithCuspTolerance($degree, $houseCuspsData, $tolerance = 1.2) {
    if (!$houseCuspsData || !isset($houseCuspsData['data']['houses'])) {
        return null;
    }
    
    $normalizedDegree = fmod($degree, 360);
    if ($normalizedDegree < 0) $normalizedDegree += 360;
    
    $houses = $houseCuspsData['data']['houses'];
    
    foreach ($houses as $house) {
        $cuspDegree = fmod((float)$house['full_degree'], 360);
        if ($cuspDegree < 0) $cuspDegree += 360;
        
        $diff = min(
            abs($normalizedDegree - $cuspDegree),
            360 - abs($normalizedDegree - $cuspDegree)
        );
        
        if ($diff <= $tolerance) {
            return ($house['house'] % 12) + 1;
        }
    }
    
    return getHouseNumberFromDegree($degree, $houseCuspsData);
}

function findNearestCusp($degree, $houseCuspsData) {
    if (!$houseCuspsData || !isset($houseCuspsData['data']['houses'])) {
        return null;
    }
    
    $normalizedDegree = fmod($degree, 360);
    if ($normalizedDegree < 0) $normalizedDegree += 360;
    
    $nearest = null;
    $minDiff = 360;
    
    foreach ($houseCuspsData['data']['houses'] as $house) {
        $cuspDegree = fmod((float)$house['full_degree'], 360);
        if ($cuspDegree < 0) $cuspDegree += 360;
        
        $diff = min(abs($normalizedDegree - $cuspDegree), 360 - abs($normalizedDegree - $cuspDegree));
        
        if ($diff < $minDiff) {
            $minDiff = $diff;
            $nearest = [
                'house' => $house['house'],
                'cusp_degree' => $cuspDegree,
                'distance' => round($diff, 4)
            ];
        }
    }
    
    return $nearest;
}

function isDayChart($sunLongitude, $ascLongitude) {
    $sunRelative = fmod(($sunLongitude - $ascLongitude + 360), 360);
    $sunHouse = floor($sunRelative / 30);
    return ($sunHouse >= 6);
}

function calculatePartOfFortune($sunLong, $moonLong, $ascLong, $isDayChart) {
    if ($isDayChart) {
        $pof = $ascLong + $moonLong - $sunLong;
    } else {
        $pof = $ascLong + $sunLong - $moonLong;
    }
    
    $pof = fmod($pof, 360);
    if ($pof < 0) $pof += 360;
    
    return $pof;
}

function formatHumanDesignData($hdData) {
    $formatted = [
        'type' => '',
        'strategy' => '',
        'authority' => '',
        'profile' => '',
        'definition' => '',
        'incarnation_cross' => '',
        'centers' => ['defined' => [], 'open' => []],
        'channels' => [],
        'gates' => [],
        'personality' => [
            'sun' => ['gate' => 0, 'line' => 0],
            'earth' => ['gate' => 0, 'line' => 0]
        ],
        'design' => [
            'sun' => ['gate' => 0, 'line' => 0],
            'earth' => ['gate' => 0, 'line' => 0]
        ]
    ];
    
    if (!$hdData || isset($hdData['error'])) {
        return $formatted;
    }
    
    if (isset($hdData['Properties'])) {
        $props = $hdData['Properties'];
        
        $formatted['type'] = is_array($props['Type'] ?? null) ? ($props['Type']['option'] ?? $props['Type']['name'] ?? '') : ($props['Type'] ?? '');
        $formatted['strategy'] = is_array($props['Strategy'] ?? null) ? ($props['Strategy']['option'] ?? $props['Strategy']['name'] ?? '') : ($props['Strategy'] ?? '');
        $formatted['authority'] = is_array($props['InnerAuthority'] ?? null) ? ($props['InnerAuthority']['option'] ?? $props['InnerAuthority']['name'] ?? '') : ($props['InnerAuthority'] ?? '');
        $formatted['profile'] = is_array($props['Profile'] ?? null) ? ($props['Profile']['option'] ?? $props['Profile']['name'] ?? '') : ($props['Profile'] ?? '');
        $formatted['definition'] = is_array($props['Definition'] ?? null) ? ($props['Definition']['option'] ?? $props['Definition']['name'] ?? '') : ($props['Definition'] ?? '');
        $formatted['incarnation_cross'] = is_array($props['IncarnationCross'] ?? null) ? ($props['IncarnationCross']['option'] ?? $props['IncarnationCross']['name'] ?? '') : ($props['IncarnationCross'] ?? '');
    }
    
    if (isset($hdData['DefinedCenters']) && is_array($hdData['DefinedCenters'])) {
        $formatted['centers']['defined'] = $hdData['DefinedCenters'];
    }
    if (isset($hdData['OpenCenters']) && is_array($hdData['OpenCenters'])) {
        $formatted['centers']['open'] = $hdData['OpenCenters'];
    }
    
    if (isset($hdData['Channels']) && is_array($hdData['Channels'])) {
        $formatted['channels'] = $hdData['Channels'];
    }
    
    if (isset($hdData['Gates']) && is_array($hdData['Gates'])) {
        foreach ($hdData['Gates'] as $gate) {
            if (is_numeric($gate)) {
                $formatted['gates'][] = (int)$gate;
            } elseif (is_array($gate) && isset($gate['id'])) {
                $formatted['gates'][] = (int)$gate['id'];
            }
        }
    }
    
    if (isset($hdData['Personality'])) {
        if (isset($hdData['Personality']['Sun'])) {
            $sun = $hdData['Personality']['Sun'];
            $formatted['personality']['sun']['gate'] = (int)($sun['Gate'] ?? $sun['gate'] ?? 0);
            $formatted['personality']['sun']['line'] = (int)($sun['Line'] ?? $sun['line'] ?? 0);
        }
        if (isset($hdData['Personality']['Earth'])) {
            $earth = $hdData['Personality']['Earth'];
            $formatted['personality']['earth']['gate'] = (int)($earth['Gate'] ?? $earth['gate'] ?? 0);
            $formatted['personality']['earth']['line'] = (int)($earth['Line'] ?? $earth['line'] ?? 0);
        }
    }
    
    if (isset($hdData['Design'])) {
        if (isset($hdData['Design']['Sun'])) {
            $sun = $hdData['Design']['Sun'];
            $formatted['design']['sun']['gate'] = (int)($sun['Gate'] ?? $sun['gate'] ?? 0);
            $formatted['design']['sun']['line'] = (int)($sun['Line'] ?? $sun['line'] ?? 0);
        }
        if (isset($hdData['Design']['Earth'])) {
            $earth = $hdData['Design']['Earth'];
            $formatted['design']['earth']['gate'] = (int)($earth['Gate'] ?? $earth['gate'] ?? 0);
            $formatted['design']['earth']['line'] = (int)($earth['Line'] ?? $earth['line'] ?? 0);
        }
    }
    
    return $formatted;
}

/*
|--------------------------------------------------------------------------
| STEP 8: FORMAT ASTROLOGY DATA
|--------------------------------------------------------------------------
*/

$houseSystemMap = [
    'P' => 'Placidus', 'K' => 'Koch', 'O' => 'Porphyry',
    'R' => 'Regiomontanus', 'C' => 'Campanus', 'A' => 'Equal',
    'E' => 'Equal', 'V' => 'Whole Sign', 'W' => 'Whole Sign',
    'N' => 'Whole Sign', 'B' => 'Alcabitius', 'M' => 'Morinus'
];
$house_system_name = $houseSystemMap[$house_system] ?? 'Placidus';

$ascendant = null;
$midheaven = null;
$ascLongitude = 0;

if (isset($houseCusps['success']) && $houseCusps['success'] == 1 && isset($houseCusps['data']['houses'])) {
    foreach ($houseCusps['data']['houses'] as $house) {
        if ($house['house'] == 1) {
            $ascLongitude = (float)$house['full_degree'];
            $ascendant = [
                'sign' => getSignFromDegree($ascLongitude),
                'degree' => round(getDegreeInSign($ascLongitude), 4),
                'dms' => decimalToDMS($ascLongitude),
                'full_degree' => $ascLongitude
            ];
        }
        if ($house['house'] == 10) {
            $mcLongitude = (float)$house['full_degree'];
            $midheaven = [
                'sign' => getSignFromDegree($mcLongitude),
                'degree' => round(getDegreeInSign($mcLongitude), 4),
                'dms' => decimalToDMS($mcLongitude),
                'full_degree' => $mcLongitude
            ];
        }
    }
}

$planetNameMap = [
    'Sun' => 'Sun', 'Moon' => 'Moon', 'Mercury' => 'Mercury',
    'Venus' => 'Venus', 'Mars' => 'Mars', 'Jupiter' => 'Jupiter',
    'Saturn' => 'Saturn', 'Uranus' => 'Uranus', 'Neptune' => 'Neptune',
    'Pluto' => 'Pluto', 'Chiron' => 'Chiron', 'Lilith' => 'Lilith',
    'North node' => 'North Node', 'South node' => 'South Node',
    'True north node' => 'North Node', 'Mean north node' => 'North Node',
    'Ceres' => 'Ceres', 'Pallas' => 'Pallas', 'Juno' => 'Juno',
    'Vesta' => 'Vesta', 'Part of fortune' => 'Part of Fortune',
    'Fortune' => 'Part of Fortune'
];

$planets = [];
$sunLongitude = null;
$moonLongitude = null;

if (isset($planetaryPositions['success']) && $planetaryPositions['success'] == 1 && isset($planetaryPositions['data'])) {
    foreach ($planetaryPositions['data'] as $planet) {
        $planetName = $planet['name'];
        
        if (in_array($planetName, ['Ascendant', 'MC'])) {
            continue;
        }
        
        $displayName = $planetNameMap[$planetName] ?? $planetName;
        $fullDegree = (float)$planet['full_degree'];
        
        if ($planetName == 'Sun') $sunLongitude = $fullDegree;
        if ($planetName == 'Moon') $moonLongitude = $fullDegree;
        
        $houseNumber = getAccurateHouseWithCuspTolerance($fullDegree, $houseCusps, 1.2);
        
        $planetData = [
            'planet' => $displayName,
            'sign' => getSignFromDegree($fullDegree),
            'degree' => round(getDegreeInSign($fullDegree), 4),
            'dms' => decimalToDMS($fullDegree),
            'house' => $houseNumber,
            'retrograde' => isset($planet['is_retrograde']) ? (bool)$planet['is_retrograde'] : false,
            'full_degree' => $fullDegree
        ];
        
        $exists = false;
        foreach ($planets as $existing) {
            if ($existing['planet'] === $displayName) {
                $exists = true;
                break;
            }
        }
        
        if (!$exists) {
            $planets[] = $planetData;
        }
    }
}

$pofFound = false;
foreach ($planets as $planet) {
    if ($planet['planet'] === 'Part of Fortune') {
        $pofFound = true;
        break;
    }
}

if (!$pofFound && $sunLongitude !== null && $moonLongitude !== null && $ascLongitude !== null) {
    $isDay = isDayChart($sunLongitude, $ascLongitude);
    $pofLongitude = calculatePartOfFortune($sunLongitude, $moonLongitude, $ascLongitude, $isDay);
    $pofHouse = getAccurateHouseWithCuspTolerance($pofLongitude, $houseCusps, 1.2);
    
    $planets[] = [
        'planet' => 'Part of Fortune',
        'sign' => getSignFromDegree($pofLongitude),
        'degree' => round(getDegreeInSign($pofLongitude), 4),
        'dms' => decimalToDMS($pofLongitude),
        'house' => $pofHouse,
        'retrograde' => false,
        'full_degree' => $pofLongitude,
        'calculated' => true
    ];
}

usort($planets, function($a, $b) {
    $houseA = $a['house'] ?? 99;
    $houseB = $b['house'] ?? 99;
    return $houseA - $houseB;
});

$aspects = [];
if (isset($aspectTable['status']) && $aspectTable['status'] == 'success' && isset($aspectTable['data'])) {
    foreach ($aspectTable['data'] as $aspect) {
        $aspects[] = [
            'point_a' => $aspect['planetOne'],
            'aspect_type' => strtolower($aspect['aspect']),
            'point_b' => $aspect['planetTwo'],
            'orb' => round((float)$aspect['orb'], 2),
            'motion' => isset($aspect['motion']) ? $aspect['motion'] : 'separating'
        ];
    }
}

$formattedHD = formatHumanDesignData($hd_response);

/*
|--------------------------------------------------------------------------
| FINAL RESPONSE
|--------------------------------------------------------------------------
*/
$result = [
    'success' => true,
    'astrology' => [
        'house_system' => [
            'name' => $house_system_name,
            'code' => $house_system
        ],
        'ascendant' => $ascendant,
        'midheaven' => $midheaven,
        'planets' => $planets,
        'aspects' => $aspects
    ],
    'human_design' => $formattedHD
];

if ($debug) {
    $result['debug'] = [
        'timezone_info' => [
            'offset_hours' => $tzone,
            'timezone_name' => $timezone,
            'raw_offset' => $rawOffset,
            'dst_offset' => $dstOffset
        ],
        'birth_info' => [
            'local_time' => "$year-$month-$day $hour:$minutes",
            'city' => $city,
            'state' => $state,
            'coordinates' => ['lat' => $latitude, 'lon' => $longitude]
        ],
        'api_responses' => [
            'house_cusps_success' => $houseCusps['success'] ?? false,
            'planetary_positions_success' => $planetaryPositions['success'] ?? false,
            'aspect_table_success' => $aspectTable['status'] ?? false
        ]
    ];
}

// Output final JSON
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
?>
