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
if (empty($city)) {
    $city = $state;
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
| STEP 4: BODYGRAPH HD API (HUMAN DESIGN) - FIXED PARSING
|--------------------------------------------------------------------------
*/
// Get location timezone from BodyGraph
$bg_url = "https://api.bodygraphchart.com/v210502/locations?api_key={$bg_api_key}&query=" . urlencode($city ?: $state);
$bgData = makeRequest($bg_url);

$bgTimezone = $timezone;
if (!empty($bgData) && !isset($bgData['error']) && isset($bgData[0]['timezone'])) {
    $bgTimezone = $bgData[0]['timezone'];
}

// Get HD data
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
    
    $signIndex = floor($fullDegree / 30);
    return $signs[$signIndex % 12];
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

if (isset($houseCusps['success']) && $houseCusps['success'] == 1 && isset($houseCusps['data']['houses'])) {
    foreach ($houseCusps['data']['houses'] as $house) {
        if ($house['house'] == 1) {
            $ascendant = [
                'sign' => getSignFromDegree((float)$house['full_degree']),
                'degree' => round(getDegreeInSign((float)$house['full_degree']), 6),
                'dms' => decimalToDMS((float)$house['full_degree'])
            ];
        }
        if ($house['house'] == 10) {
            $midheaven = [
                'sign' => getSignFromDegree((float)$house['full_degree']),
                'degree' => round(getDegreeInSign((float)$house['full_degree']), 6),
                'dms' => decimalToDMS((float)$house['full_degree'])
            ];
        }
    }
}

$planets = [];
$planetNameMap = [
    'Sun' => 'Sun', 'Moon' => 'Moon', 'Mercury' => 'Mercury',
    'Venus' => 'Venus', 'Mars' => 'Mars', 'Jupiter' => 'Jupiter',
    'Saturn' => 'Saturn', 'Uranus' => 'Uranus', 'Neptune' => 'Neptune',
    'Pluto' => 'Pluto', 'Chiron' => 'Chiron', 'Lilith' => 'Lilith',
    'North node' => 'North Node', 'South node' => 'South Node'
];

if (isset($planetaryPositions['success']) && $planetaryPositions['success'] == 1 && isset($planetaryPositions['data'])) {
    foreach ($planetaryPositions['data'] as $planet) {
        $planetName = $planet['name'];
        
        if (in_array($planetName, ['Ascendant', 'MC', 'Part of fortune'])) {
            continue;
        }
        
        $displayName = $planetNameMap[$planetName] ?? $planetName;
        $fullDegree = (float)$planet['full_degree'];
        $houseNumber = isset($planet['house']) && $planet['house'] > 0 ? (int)$planet['house'] : null;
        
        $planets[] = [
            'planet' => $displayName,
            'sign' => getSignFromDegree($fullDegree),
            'degree' => round(getDegreeInSign($fullDegree), 6),
            'dms' => decimalToDMS($fullDegree),
            'house' => $houseNumber
        ];
    }
}

usort($planets, function($a, $b) {
    return ($a['house'] ?? 99) - ($b['house'] ?? 99);
});

$aspects = [];
if (isset($aspectTable['status']) && $aspectTable['status'] == 'success' && isset($aspectTable['data'])) {
    foreach ($aspectTable['data'] as $aspect) {
        $aspects[] = [
            'point_a' => $aspect['planetOne'],
            'aspect_type' => strtolower($aspect['aspect']),
            'point_b' => $aspect['planetTwo'],
            'orb' => round((float)$aspect['orb'], 2),
            'motion' => 'separating'
        ];
    }
}

/*
|--------------------------------------------------------------------------
| STEP 9: FORMAT HUMAN DESIGN DATA - FIXED PARSING
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
        
        // Type
        if (isset($props['Type'])) {
            if (is_array($props['Type'])) {
                $formatted['type'] = $props['Type']['option'] ?? $props['Type']['name'] ?? '';
            } else {
                $formatted['type'] = $props['Type'];
            }
        }
        
        // Strategy
        if (isset($props['Strategy'])) {
            if (is_array($props['Strategy'])) {
                $formatted['strategy'] = $props['Strategy']['option'] ?? $props['Strategy']['name'] ?? '';
            } else {
                $formatted['strategy'] = $props['Strategy'];
            }
        }
        
        // Authority
        if (isset($props['InnerAuthority'])) {
            if (is_array($props['InnerAuthority'])) {
                $formatted['authority'] = $props['InnerAuthority']['option'] ?? $props['InnerAuthority']['name'] ?? '';
            } else {
                $formatted['authority'] = $props['InnerAuthority'];
            }
        }
        
        // Profile
        if (isset($props['Profile'])) {
            if (is_array($props['Profile'])) {
                $formatted['profile'] = $props['Profile']['option'] ?? $props['Profile']['name'] ?? '';
            } else {
                $formatted['profile'] = $props['Profile'];
            }
        }
        
        // Definition
        if (isset($props['Definition'])) {
            if (is_array($props['Definition'])) {
                $formatted['definition'] = $props['Definition']['option'] ?? $props['Definition']['name'] ?? '';
            } else {
                $formatted['definition'] = $props['Definition'];
            }
        }
        
        // Incarnation Cross
        if (isset($props['IncarnationCross'])) {
            if (is_array($props['IncarnationCross'])) {
                $formatted['incarnation_cross'] = $props['IncarnationCross']['option'] ?? $props['IncarnationCross']['name'] ?? '';
            } else {
                $formatted['incarnation_cross'] = $props['IncarnationCross'];
            }
        }
    }
    
    // Parse Defined Centers
    if (isset($hdData['DefinedCenters'])) {
        if (is_array($hdData['DefinedCenters'])) {
            $formatted['centers']['defined'] = $hdData['DefinedCenters'];
        }
    }
    
    // Parse Open Centers
    if (isset($hdData['OpenCenters'])) {
        if (is_array($hdData['OpenCenters'])) {
            $formatted['centers']['open'] = $hdData['OpenCenters'];
        }
    }
    
    // Parse Channels
    if (isset($hdData['Channels'])) {
        if (is_array($hdData['Channels'])) {
            $formatted['channels'] = $hdData['Channels'];
        }
    }
    
    // Parse Gates
    if (isset($hdData['Gates'])) {
        if (is_array($hdData['Gates'])) {
            foreach ($hdData['Gates'] as $gate) {
                if (is_numeric($gate)) {
                    $formatted['gates'][] = (int)$gate;
                } elseif (is_array($gate) && isset($gate['id'])) {
                    $formatted['gates'][] = (int)$gate['id'];
                }
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

echo json_encode($result, JSON_PRETTY_PRINT);
?>