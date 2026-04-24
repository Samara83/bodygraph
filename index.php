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

/*
|--------------------------------------------------------------------------
| STEP 2: GET TIMEZONE
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
| STEP 3: NORMALIZE TIME
|--------------------------------------------------------------------------
*/
$hour_input = max(0, min(23, intval($hour)));
$minutes_input = max(0, min(59, intval($minutes)));
$birth_time = sprintf('%02d:%02d', $hour_input, $minutes_input);
$birth_date_time = "$year-$month-$day $birth_time";

/*
|--------------------------------------------------------------------------
| STEP 4: BODYGRAPH HD API (HUMAN DESIGN)
|--------------------------------------------------------------------------
*/
$bg_url = "https://api.bodygraphchart.com/v210502/locations?api_key={$bg_api_key}&query=" . urlencode($city ?: $state);
$bgData = makeRequest($bg_url);

if (empty($bgData) || isset($bgData['error'])) {
    $bgData = [['value' => $city ?: $state, 'timezone' => $timezone]];
}

$bgTimezone = $bgData[0]['timezone'] ?? $timezone;

$hd_url = 'https://api.bodygraphchart.com/v221006/hd-data?api_key='
    . urlencode($bg_api_key)
    . '&date=' . urlencode($birth_date_time)
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
    'hour' => (int)$hour_input,
    'min' => (int)$minutes_input,
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
| STEP 6: DIVINE API REQUESTS
|--------------------------------------------------------------------------
*/

// House Cusps (for Ascendant & MC)
$houseParams = array_merge($divineParams, ['with_rulers' => 1]);
$houseCusps = makeRequest(
    'https://astroapi-4.divineapi.com/western-api/v1/house-cusps',
    'POST',
    $houseParams,
    $divine_bearer_token
);

// Planetary Positions
$planetaryPositions = makeRequest(
    'https://astroapi-4.divineapi.com/western-api/v1/planetary-positions',
    'POST',
    $divineParams,
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

/**
 * Convert decimal degree to Degree:Minute:Second format
 */
function decimalToDMS($decimalDegree) {
    $degrees = floor($decimalDegree);
    $minutesDecimal = ($decimalDegree - $degrees) * 60;
    $minutes = floor($minutesDecimal);
    $seconds = round(($minutesDecimal - $minutes) * 60);
    
    // Fix seconds rounding
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

/**
 * Get degree within sign (0-30) from full degree
 */
function getDegreeInSign($fullDegree) {
    return fmod($fullDegree, 30);
}

/**
 * Get sign name from full degree
 */
function getSignFromDegree($fullDegree) {
    $signs = ['Aries', 'Taurus', 'Gemini', 'Cancer', 'Leo', 'Virgo', 
              'Libra', 'Scorpio', 'Sagittarius', 'Capricorn', 'Aquarius', 'Pisces'];
    
    $signIndex = floor($fullDegree / 30);
    return $signs[$signIndex % 12];
}

/**
 * Get house number from planetary position using house cusps
 */
function getHouseNumber($planetDegree, $houseCuspsData) {
    if (!$houseCuspsData || !isset($houseCuspsData['data']['houses'])) {
        return null;
    }
    
    $houses = $houseCuspsData['data']['houses'];
    $planetNorm = $planetDegree;
    
    // Sort houses by house number
    usort($houses, function($a, $b) {
        return $a['house'] - $b['house'];
    });
    
    for ($i = 0; $i < count($houses); $i++) {
        $currentHouse = $houses[$i];
        $nextHouse = $houses[($i + 1) % count($houses)];
        
        $cuspStart = $currentHouse['full_degree'];
        $cuspEnd = $nextHouse['full_degree'];
        
        // Handle wrap around 0 degree
        if ($cuspEnd < $cuspStart) {
            if ($planetNorm >= $cuspStart || $planetNorm < $cuspEnd) {
                return $currentHouse['house'];
            }
        } else {
            if ($planetNorm >= $cuspStart && $planetNorm < $cuspEnd) {
                return $currentHouse['house'];
            }
        }
    }
    
    return null;
}

/*
|--------------------------------------------------------------------------
| STEP 8: FORMAT ASTROLOGY DATA
|--------------------------------------------------------------------------
*/

// House system mapping
$houseSystemMap = [
    'P' => 'Placidus', 'K' => 'Koch', 'O' => 'Porphyry',
    'R' => 'Regiomontanus', 'C' => 'Campanus', 'E' => 'Equal',
    'V' => 'Whole Sign', 'W' => 'Whole Sign', 'B' => 'Alcabitius',
    'M' => 'Morinus'
];
$house_system_name = $houseSystemMap[$house_system] ?? 'Placidus';

// Extract Ascendant and Midheaven from house cusps
$ascendant = null;
$midheaven = null;

if (isset($houseCusps['success']) && $houseCusps['success'] == 1 && isset($houseCusps['data']['houses'])) {
    foreach ($houseCusps['data']['houses'] as $house) {
        if ($house['house'] == 1) {
            $ascendant = [
                'sign' => getSignFromDegree($house['full_degree']),
                'degree' => getDegreeInSign($house['full_degree']),
                'dms' => decimalToDMS($house['full_degree'])
            ];
        }
        if ($house['house'] == 10) {
            $midheaven = [
                'sign' => getSignFromDegree($house['full_degree']),
                'degree' => getDegreeInSign($house['full_degree']),
                'dms' => decimalToDMS($house['full_degree'])
            ];
        }
    }
}

// Extract planets with correct houses
$planets = [];
$planetNameMap = [
    'Sun' => 'Sun', 'Moon' => 'Moon', 'Mercury' => 'Mercury',
    'Venus' => 'Venus', 'Mars' => 'Mars', 'Jupiter' => 'Jupiter',
    'Saturn' => 'Saturn', 'Uranus' => 'Uranus', 'Neptune' => 'Neptune',
    'Pluto' => 'Pluto', 'Chiron' => 'Chiron', 'Lilith' => 'Lilith',
    'North node' => 'North Node', 'South node' => 'South Node'
];

// First, get house cusps as reference if planetary positions don't have house info
$houseCuspsReference = null;
if (isset($houseCusps['success']) && $houseCusps['success'] == 1 && isset($houseCusps['data']['houses'])) {
    $houseCuspsReference = $houseCusps;
}

if (isset($planetaryPositions['success']) && $planetaryPositions['success'] == 1 && isset($planetaryPositions['data'])) {
    foreach ($planetaryPositions['data'] as $planet) {
        $planetName = $planet['name'];
        
        // Skip Ascendant, MC for planets list
        if (in_array($planetName, ['Ascendant', 'MC', 'Part of fortune'])) {
            continue;
        }
        
        $displayName = $planetNameMap[$planetName] ?? $planetName;
        $fullDegree = $planet['full_degree'];
        
        // Get house number - use pre-calculated house from API if available
        $houseNumber = null;
        
        // Method 1: Check if API provided house directly (in full_degree field format)
        if (isset($planet['house']) && $planet['house'] > 0) {
            $houseNumber = $planet['house'];
        }
        
        // Method 2: Calculate from house cusps
        if ($houseNumber === null && $houseCuspsReference) {
            $houseNumber = getHouseNumber($fullDegree, $houseCuspsReference);
        }
        
        $planets[] = [
            'planet' => $displayName,
            'sign' => getSignFromDegree($fullDegree),
            'degree' => round(getDegreeInSign($fullDegree), 6),
            'dms' => decimalToDMS($fullDegree),
            'house' => $houseNumber
        ];
    }
}

// Sort planets by house number
usort($planets, function($a, $b) {
    return ($a['house'] ?? 99) - ($b['house'] ?? 99);
});

// Extract aspects
$aspects = [];
if (isset($aspectTable['status']) && $aspectTable['status'] == 'success' && isset($aspectTable['data'])) {
    foreach ($aspectTable['data'] as $aspect) {
        $aspects[] = [
            'point_a' => $aspect['planetOne'],
            'aspect_type' => strtolower($aspect['aspect']),
            'point_b' => $aspect['planetTwo'],
            'orb' => round($aspect['orb'], 2),
            'motion' => 'separating'
        ];
    }
}

/*
|--------------------------------------------------------------------------
| STEP 9: FORMAT HUMAN DESIGN DATA
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
    
    if (isset($hdData['Properties'])) {
        $props = $hdData['Properties'];
        $formatted['type'] = $props['Type']['option'] ?? '';
        $formatted['strategy'] = $props['Strategy']['option'] ?? '';
        $formatted['authority'] = $props['InnerAuthority']['option'] ?? '';
        $formatted['profile'] = $props['Profile']['option'] ?? '';
        $formatted['definition'] = $props['Definition']['option'] ?? '';
        $formatted['incarnation_cross'] = $props['IncarnationCross']['option'] ?? '';
    }
    
    if (isset($hdData['DefinedCenters'])) {
        $formatted['centers']['defined'] = $hdData['DefinedCenters'];
    }
    
    if (isset($hdData['OpenCenters'])) {
        $formatted['centers']['open'] = $hdData['OpenCenters'];
    }
    
    if (isset($hdData['Channels'])) {
        $formatted['channels'] = $hdData['Channels'];
    }
    
    if (isset($hdData['Gates'])) {
        foreach ($hdData['Gates'] as $gate) {
            if (is_numeric($gate)) {
                $formatted['gates'][] = (int)$gate;
            }
        }
    }
    
    if (isset($hdData['Personality'])) {
        if (isset($hdData['Personality']['Sun'])) {
            $formatted['personality']['sun']['gate'] = (int)($hdData['Personality']['Sun']['Gate'] ?? 0);
            $formatted['personality']['sun']['line'] = (int)($hdData['Personality']['Sun']['Line'] ?? 0);
        }
        if (isset($hdData['Personality']['Earth'])) {
            $formatted['personality']['earth']['gate'] = (int)($hdData['Personality']['Earth']['Gate'] ?? 0);
            $formatted['personality']['earth']['line'] = (int)($hdData['Personality']['Earth']['Line'] ?? 0);
        }
    }
    
    if (isset($hdData['Design'])) {
        if (isset($hdData['Design']['Sun'])) {
            $formatted['design']['sun']['gate'] = (int)($hdData['Design']['Sun']['Gate'] ?? 0);
            $formatted['design']['sun']['line'] = (int)($hdData['Design']['Sun']['Line'] ?? 0);
        }
        if (isset($hdData['Design']['Earth'])) {
            $formatted['design']['earth']['gate'] = (int)($hdData['Design']['Earth']['Gate'] ?? 0);
            $formatted['design']['earth']['line'] = (int)($hdData['Design']['Earth']['Line'] ?? 0);
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
            'name' => $house_system_name
        ],
        'ascendant' => $ascendant,
        'midheaven' => $midheaven,
        'planets' => $planets,
        'aspects' => $aspects
    ],
    'human_design' => $formattedHD
];

echo json_encode($result, JSON_PRETTY_PRINT);
?>