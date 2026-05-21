<?php
header('Access-Control-Allow-Origin: *');
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
$debug = $_GET['debug'] ?? false;

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
    ];
    
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        if ($postData) {
            $options[CURLOPT_POSTFIELDS] = http_build_query($postData);
        }
    }
    
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => $error];
    }
    
    return json_decode($response, true);
}

/*
|--------------------------------------------------------------------------
| STEP 1: GOOGLE MAPS GEOCODING (LAT / LNG)
|--------------------------------------------------------------------------
*/
$geocode_url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($location) . "&key={$google_api_key}";
$geoData = makeRequest($geocode_url);

if (empty($geoData['results'][0])) {
    echo json_encode(['error' => 'Google Geocoding failed'], JSON_PRETTY_PRINT);
    exit;
}

$latitude = $geoData['results'][0]['geometry']['location']['lat'];
$longitude = $geoData['results'][0]['geometry']['location']['lng'];

// Extract city
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
| STEP 2: GET ACCURATE TIMEZONE
|--------------------------------------------------------------------------
*/
$timestamp = strtotime("$year-$month-$day $hour:$minutes:00");
$timezone_url = "https://maps.googleapis.com/maps/api/timezone/json?location={$latitude},{$longitude}&timestamp={$timestamp}&key={$google_api_key}";
$tzData = makeRequest($timezone_url);

$timezone = $tzData['timeZoneId'] ?? 'Europe/London';
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
| STEP 4: BODYGRAPH HD API (HUMAN DESIGN)
|--------------------------------------------------------------------------
*/
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

/*
|--------------------------------------------------------------------------
| STEP 5: DIVINE API BASE PARAMS
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
| STEP 6: DIVINE API REQUESTS
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

// Planetary Positions - Extended for all bodies
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

// FIXED: Correct house calculation with proper wrap-around handling
function getHouseNumberFromDegree($degree, $houseCuspsData) {
    if (!$houseCuspsData || !isset($houseCuspsData['data']['houses'])) {
        return null;
    }
    
    $normalizedDegree = fmod($degree, 360);
    if ($normalizedDegree < 0) $normalizedDegree += 360;
    
    $houses = $houseCuspsData['data']['houses'];
    
    // Get all cusp degrees and sort them
    $cuspDegrees = [];
    foreach ($houses as $house) {
        $cuspDegree = fmod((float)$house['full_degree'], 360);
        if ($cuspDegree < 0) $cuspDegree += 360;
        $cuspDegrees[] = [
            'house' => $house['house'],
            'degree' => $cuspDegree
        ];
    }
    
    // Sort by degree
    usort($cuspDegrees, function($a, $b) {
        return $a['degree'] - $b['degree'];
    });
    
    // Find which house the degree falls into
    for ($i = 0; $i < count($cuspDegrees); $i++) {
        $start = $cuspDegrees[$i]['degree'];
        $end = $cuspDegrees[($i + 1) % count($cuspDegrees)]['degree'];
        
        if ($start > $end) { // Wrap-around case (crossing 0°)
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

// NEW: Handle cusp boundaries with tolerance
function getAccurateHouseWithCuspTolerance($degree, $houseCuspsData, $tolerance = 1.2) {
    if (!$houseCuspsData || !isset($houseCuspsData['data']['houses'])) {
        return null;
    }
    
    $normalizedDegree = fmod($degree, 360);
    if ($normalizedDegree < 0) $normalizedDegree += 360;
    
    $houses = $houseCuspsData['data']['houses'];
    
    // First check if planet is within tolerance of any cusp
    foreach ($houses as $house) {
        $cuspDegree = fmod((float)$house['full_degree'], 360);
        if ($cuspDegree < 0) $cuspDegree += 360;
        
        $diff = min(
            abs($normalizedDegree - $cuspDegree),
            360 - abs($normalizedDegree - $cuspDegree)
        );
        
        if ($diff <= $tolerance) {
            // Planet is within tolerance of a cusp - assign to next house
            return ($house['house'] % 12) + 1;
        }
    }
    
    // Otherwise use standard calculation
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

/*
|--------------------------------------------------------------------------
| STEP 8: CHECK IF DAY OR NIGHT CHART
|--------------------------------------------------------------------------
*/
function isDayChart($sunLongitude, $ascLongitude) {
    // Calculate Sun's house position
    $sunRelative = fmod(($sunLongitude - $ascLongitude + 360), 360);
    $sunHouse = floor($sunRelative / 30);
    // Houses 7-12 (index 6-11) are above horizon (day chart)
    return ($sunHouse >= 6);
}

/*
|--------------------------------------------------------------------------
| STEP 9: CALCULATE PART OF FORTUNE
|--------------------------------------------------------------------------
*/
function calculatePartOfFortune($sunLong, $moonLong, $ascLong, $isDayChart) {
    // Day chart (Sun above horizon): Fortune = Asc + Moon - Sun
    // Night chart (Sun below horizon): Fortune = Asc + Sun - Moon
    
    if ($isDayChart) {
        $pof = $ascLong + $moonLong - $sunLong;
    } else {
        $pof = $ascLong + $sunLong - $moonLong;
    }
    
    $pof = fmod($pof, 360);
    if ($pof < 0) $pof += 360;
    
    return $pof;
}

/*
|--------------------------------------------------------------------------
| STEP 10: FORMAT ASTROLOGY DATA - FULL EXTRACTION
|--------------------------------------------------------------------------
*/

$houseSystemMap = [
    'P' => 'Placidus', 'K' => 'Koch', 'O' => 'Porphyry',
    'R' => 'Regiomontanus', 'C' => 'Campanus', 'A' => 'Equal',
    'E' => 'Equal', 'V' => 'Whole Sign', 'W' => 'Whole Sign',
    'N' => 'Whole Sign', 'B' => 'Alcabitius', 'M' => 'Morinus'
];
$house_system_name = $houseSystemMap[$house_system] ?? 'Placidus';

// Extract Ascendant and Midheaven
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

// Complete planet name mapping
$planetNameMap = [
    'Sun' => 'Sun',
    'Moon' => 'Moon',
    'Mercury' => 'Mercury',
    'Venus' => 'Venus',
    'Mars' => 'Mars',
    'Jupiter' => 'Jupiter',
    'Saturn' => 'Saturn',
    'Uranus' => 'Uranus',
    'Neptune' => 'Neptune',
    'Pluto' => 'Pluto',
    'Chiron' => 'Chiron',
    'Lilith' => 'Lilith',
    'North node' => 'North Node',
    'South node' => 'South Node',
    'True north node' => 'North Node',
    'Mean north node' => 'North Node',
    'Ceres' => 'Ceres',
    'Pallas' => 'Pallas',
    'Juno' => 'Juno',
    'Vesta' => 'Vesta',
    'Part of fortune' => 'Part of Fortune',
    'Fortune' => 'Part of Fortune'
];

// Extract planetary data with corrected house assignment
$planets = [];
$sunLongitude = null;
$moonLongitude = null;

if (isset($planetaryPositions['success']) && $planetaryPositions['success'] == 1 && isset($planetaryPositions['data'])) {
    foreach ($planetaryPositions['data'] as $planet) {
        $planetName = $planet['name'];
        
        // Skip Ascendant and MC as they're handled separately
        if (in_array($planetName, ['Ascendant', 'MC'])) {
            continue;
        }
        
        $displayName = $planetNameMap[$planetName] ?? $planetName;
        $fullDegree = (float)$planet['full_degree'];
        
        // Store Sun and Moon for Part of Fortune
        if ($planetName == 'Sun') {
            $sunLongitude = $fullDegree;
        }
        if ($planetName == 'Moon') {
            $moonLongitude = $fullDegree;
        }
        
        // USE CORRECTED HOUSE ASSIGNMENT with cusp tolerance
        $houseNumber = getAccurateHouseWithCuspTolerance($fullDegree, $houseCusps, 1.2);
        
        // Get API house for debugging comparison
        $apiHouse = $planet['house'] ?? null;
        
        $planetData = [
            'planet' => $displayName,
            'sign' => getSignFromDegree($fullDegree),
            'degree' => round(getDegreeInSign($fullDegree), 4),
            'dms' => decimalToDMS($fullDegree),
            'house' => $houseNumber,
            'retrograde' => isset($planet['is_retrograde']) ? (bool)$planet['is_retrograde'] : false,
            'full_degree' => $fullDegree
        ];
        
        // Add debugging info if enabled
        if ($debug && $apiHouse !== null && $houseNumber != $apiHouse) {
            $planetData['debug_house_correction'] = [
                'api_house' => $apiHouse,
                'corrected_house' => $houseNumber,
                'reason' => 'cusp_boundary_correction'
            ];
        }
        
        // Avoid duplicates
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

// Calculate Part of Fortune if missing
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

// Sort planets by house number
usort($planets, function($a, $b) {
    $houseA = $a['house'] ?? 99;
    $houseB = $b['house'] ?? 99;
    return $houseA - $houseB;
});

// Extract aspects
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

/*
|--------------------------------------------------------------------------
| STEP 11: FORMAT HUMAN DESIGN DATA
|--------------------------------------------------------------------------
*/
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
    
    // Parse Properties from BodyGraph response
    if (isset($hdData['Properties'])) {
        $props = $hdData['Properties'];
        
        $formatted['type'] = is_array($props['Type'] ?? null) ? ($props['Type']['option'] ?? $props['Type']['name'] ?? '') : ($props['Type'] ?? '');
        $formatted['strategy'] = is_array($props['Strategy'] ?? null) ? ($props['Strategy']['option'] ?? $props['Strategy']['name'] ?? '') : ($props['Strategy'] ?? '');
        $formatted['authority'] = is_array($props['InnerAuthority'] ?? null) ? ($props['InnerAuthority']['option'] ?? $props['InnerAuthority']['name'] ?? '') : ($props['InnerAuthority'] ?? '');
        $formatted['profile'] = is_array($props['Profile'] ?? null) ? ($props['Profile']['option'] ?? $props['Profile']['name'] ?? '') : ($props['Profile'] ?? '');
        $formatted['definition'] = is_array($props['Definition'] ?? null) ? ($props['Definition']['option'] ?? $props['Definition']['name'] ?? '') : ($props['Definition'] ?? '');
        $formatted['incarnation_cross'] = is_array($props['IncarnationCross'] ?? null) ? ($props['IncarnationCross']['option'] ?? $props['IncarnationCross']['name'] ?? '') : ($props['IncarnationCross'] ?? '');
    }
    
    // Parse Centers
    if (isset($hdData['DefinedCenters']) && is_array($hdData['DefinedCenters'])) {
        $formatted['centers']['defined'] = $hdData['DefinedCenters'];
    }
    if (isset($hdData['OpenCenters']) && is_array($hdData['OpenCenters'])) {
        $formatted['centers']['open'] = $hdData['OpenCenters'];
    }
    
    // Parse Channels
    if (isset($hdData['Channels']) && is_array($hdData['Channels'])) {
        $formatted['channels'] = $hdData['Channels'];
    }
    
    // Parse Gates
    if (isset($hdData['Gates']) && is_array($hdData['Gates'])) {
        foreach ($hdData['Gates'] as $gate) {
            if (is_numeric($gate)) {
                $formatted['gates'][] = (int)$gate;
            } elseif (is_array($gate) && isset($gate['id'])) {
                $formatted['gates'][] = (int)$gate['id'];
            }
        }
    }
    
    // Parse Personality Sun & Earth
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
    
    // Parse Design Sun & Earth
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

$formattedHD = formatHumanDesignData($hd_response);

/*
|--------------------------------------------------------------------------
| FINAL RESPONSE
|--------------------------------------------------------------------------
*/
$result = [
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

// Add debug information if requested
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
        'house_cusps' => [],
        'api_responses' => [
            'house_cusps_success' => $houseCusps['success'] ?? false,
            'planetary_positions_success' => $planetaryPositions['success'] ?? false,
            'aspect_table_success' => $aspectTable['status'] ?? false
        ]
    ];
    
    // Add house cusp data for debugging
    if (isset($houseCusps['data']['houses'])) {
        foreach ($houseCusps['data']['houses'] as $house) {
            $result['debug']['house_cusps'][] = [
                'house' => $house['house'],
                'degree' => (float)$house['full_degree'],
                'sign' => getSignFromDegree((float)$house['full_degree']),
                'dms' => decimalToDMS((float)$house['full_degree'])
            ];
        }
    }
    
    // Add planet cusp distance info
    $result['debug']['cusp_distances'] = [];
    foreach ($planets as $planet) {
        $nearestCusp = findNearestCusp($planet['full_degree'], $houseCusps);
        if ($nearestCusp && $nearestCusp['distance'] <= 1.5) {
            $result['debug']['cusp_distances'][] = [
                'planet' => $planet['planet'],
                'degree' => $planet['full_degree'],
                'nearest_house_cusp' => $nearestCusp['house'],
                'distance_degrees' => $nearestCusp['distance'],
                'assigned_house' => $planet['house']
            ];
        }
    }
}

echo json_encode($result, JSON_PRETTY_PRINT);
?>
