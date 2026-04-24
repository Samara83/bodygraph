<?php
header('Content-Type: application/json');

/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/
$location = trim($_GET['location'] ?? ''); // e.g., "Black River Falls, Wisconsin, USA"
$parts = array_map('trim', explode(',', $location));

// Only take the state (second part)
$state = $parts[1] ?? '';

$year     = $_GET['year'] ?? '';
$month    = $_GET['month'] ?? '';
$day      = $_GET['day'] ?? '';
$hour     = $_GET['hour'] ?? '';
$minutes  = $_GET['minutes'] ?? '';

if (!$state || !$year || !$month || !$day) {
    echo json_encode(['error' => 'Required fields missing'], JSON_PRETTY_PRINT);
    exit;
}

/*
|--------------------------------------------------------------------------
| STEP 1: BODYGRAPH LOCATION API
|--------------------------------------------------------------------------
*/
$api_key = '264fa2e3-ac96-4e0f-b864-fea07186cbe6';
$bg_url = "https://api.bodygraphchart.com/v210502/locations?api_key={$api_key}&query=" . urlencode($state);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $bg_url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);
$bg_response = curl_exec($ch);
// echo "BodyGraph API response: $bg_response\n"; // Debug log - COMMENTED
$bg_error = curl_error($ch);
curl_close($ch);

if ($bg_error || !$bg_response) {
    echo json_encode(['error' => 'BodyGraph API failed'], JSON_PRETTY_PRINT);
    exit;
}

$bgData = json_decode($bg_response, true);
if (!isset($bgData[0]['value'])) {
    echo json_encode(['error' => 'Invalid BodyGraph response'], JSON_PRETTY_PRINT);
    exit;
}

$fullAddress = $bgData[0]['value'];
$timezone    = $bgData[0]['timezone'] ?? '';

/*
|--------------------------------------------------------------------------
| STEP 2: GOOGLE GEOCODING (LAT / LNG)
|--------------------------------------------------------------------------
*/

$google_api_key = 'AIzaSyBH5t_bIpTznpCj-zYUU1klq3n9ZhG_FR8';

$google_url = "https://maps.googleapis.com/maps/api/geocode/json?address=" 
    . urlencode($location) 
    . "&key=" . urlencode($google_api_key);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $google_url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);

$geo_response = curl_exec($ch);
// echo "Google Geocoding response: $geo_response\n"; // Debug log - COMMENTED
$geo_error = curl_error($ch);
curl_close($ch);

if ($geo_error || !$geo_response) {
    echo json_encode(['error' => 'Google Geocoding failed'], JSON_PRETTY_PRINT);
    exit;
}

$geoData = json_decode($geo_response, true);

// Validate response
if (!isset($geoData['results'][0]['geometry']['location'])) {
    echo json_encode([
        'error' => 'Lat/Lng not found',
        'google_response' => $geoData
    ], JSON_PRETTY_PRINT);
    exit;
}

// Extract lat/lng
$latitude  = $geoData['results'][0]['geometry']['location']['lat'];
$longitude = $geoData['results'][0]['geometry']['location']['lng'];
$house_system = $_GET['house_system'] ?? 'P';

/*
|--------------------------------------------------------------------------
| NORMALIZE TIME
|--------------------------------------------------------------------------
*/
$hour_input    = max(0, min(23, intval($hour)));
$minutes_input = max(0, min(59, intval($minutes)));

$birth_time = sprintf('%02d:%02d', $hour_input, $minutes_input);
$birth_date_time_for_api = "$year-$month-$day $birth_time"; // YYYY-MM-DD HH:MM

/*
|--------------------------------------------------------------------------
| BUILD API URLS
|--------------------------------------------------------------------------
*/
$hd_url = 'https://api.bodygraphchart.com/v221006/hd-data?api_key='
    . urlencode($api_key)
    . '&date=' . urlencode($birth_date_time_for_api)
    . '&timezone=' . urlencode($timezone);

$astro_url = 'https://api.bodygraphchart.com/v240815/astro-data?api_key='
    . urlencode($api_key)
    . '&date=' . urlencode($birth_date_time_for_api)
    . '&timezone=' . urlencode($timezone)
    . '&house_system=' . urlencode($house_system);

if ($latitude && $longitude) {
    $astro_url .= '&latitude=' . urlencode($latitude)
                . '&longitude=' . urlencode($longitude);
}
// House system mapping
$houseSystemMap = [
    'P' => 'Placidus',
    'K' => 'Koch',
    'O' => 'Porphyry',
    'R' => 'Regiomontanus',
    'C' => 'Campanus',
    'E' => 'Equal',
    'V' => 'Whole Sign',
    'W' => 'Whole Sign',
    'B' => 'Alcabitius',
    'M' => 'Morinus'
];

$house_system_name = $houseSystemMap[$house_system] ?? 'Placidus';

// Function to extract house number from house name
function extractHouseNumber($houseName) {
    $houseMap = [
        'First_House' => 1, 'Second_House' => 2, 'Third_House' => 3,
        'Fourth_House' => 4, 'Fifth_House' => 5, 'Sixth_House' => 6,
        'Seventh_House' => 7, 'Eighth_House' => 8, 'Ninth_House' => 9,
        'Tenth_House' => 10, 'Eleventh_House' => 11, 'Twelfth_House' => 12
    ];
    
    return $houseMap[$houseName] ?? null;
}

// Function to get sign from degree
function getSignFromDegree($degree) {
    $signs = ['Aries', 'Taurus', 'Gemini', 'Cancer', 'Leo', 'Virgo', 
              'Libra', 'Scorpio', 'Sagittarius', 'Capricorn', 'Aquarius', 'Pisces'];
    
    $signIndex = floor($degree / 30);
    $degreeInSign = fmod($degree, 30);
    
    return [
        'sign' => $signs[$signIndex],
        'degree' => round($degreeInSign, 6)
    ];
}

// =========== UPDATED: List of valid planets for aspect filtering ===========
$validPlanets = [
    'Sun', 'Moon', 'Mercury', 'Venus', 'Mars', 
    'Jupiter', 'Saturn', 'Uranus', 'Neptune', 'Pluto',
    'True_Node',  // North Node
    'Chiron',     // ADDED: Chiron
    'Mean_Lilith' // ADDED: Lilith (Black Moon)
];

// =========== UPDATED: List of entities to exclude from aspects ===========
$excludedEntities = [
    'First_House', 'Second_House', 'Third_House', 'Fourth_House',
    'Fifth_House', 'Sixth_House', 'Seventh_House', 'Eighth_House',
    'Ninth_House', 'Tenth_House', 'Eleventh_House', 'Twelfth_House',
    'Mean_Node',  // Still exclude Mean_Node (South Node)
    'Osculating_Lilith' // Keep Osculating Lilith excluded
    // NOTE: Chiron is NOT excluded so its aspects will show
];

// Function to check if an aspect should be included
function isValidAspect($planetA, $planetB, $validPlanets, $excludedEntities) {
    // Check if either planet is in excluded list
    if (in_array($planetA, $excludedEntities) || in_array($planetB, $excludedEntities)) {
        return false;
    }
    
    // Special handling for True_Node, Chiron, Mean_Lilith - allow aspects with planets
    $specialPoints = ['True_Node', 'Chiron', 'Mean_Lilith'];
    if (in_array($planetA, $specialPoints) || in_array($planetB, $specialPoints)) {
        // Special points can aspect any planet
        $otherPlanet = in_array($planetA, $specialPoints) ? $planetB : $planetA;
        $mainPlanets = array_diff($validPlanets, $specialPoints);
        return in_array($otherPlanet, $mainPlanets);
    }
    
    // For planet-to-planet aspects, both must be in valid planets
    return in_array($planetA, $validPlanets) && in_array($planetB, $validPlanets);
}

// Function to call API
function call_api($url) {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    curl_close($curl);
    
    if ($error) {
        return ['error' => $error];
    }
    
    return json_decode($response, true);
}

// Function to format astrology data - UPDATED TO INCLUDE NEW POINTS
function formatAstrologyData($astroData, $validPlanets, $excludedEntities) {
    $formatted = [
        'ascendant' => null,
        'midheaven' => null,
        'planets' => [],
        'aspects' => []
    ];
    
    if (!$astroData || isset($astroData['error'])) {
        return $formatted;
    }
    
    // Extract Ascendant from ASCMC array (index 0)
    if (isset($astroData['ASCMC'][0])) {
        $ascData = getSignFromDegree($astroData['ASCMC'][0]);
        $formatted['ascendant'] = [
            'sign' => $ascData['sign'],
            'degree' => $ascData['degree']
        ];
    }
    
    // Extract Midheaven from ASCMC array (index 1)
    if (isset($astroData['ASCMC'][1])) {
        $mcData = getSignFromDegree($astroData['ASCMC'][1]);
        $formatted['midheaven'] = [
            'sign' => $mcData['sign'],
            'degree' => $mcData['degree']
        ];
    }
    
    // Process planetary placements - INCLUDES NEW POINTS
    $allPointsToInclude = [
        'Sun', 'Moon', 'Mercury', 'Venus', 'Mars', 
        'Jupiter', 'Saturn', 'Uranus', 'Neptune', 'Pluto',
        'True_Node',  // North Node
        'Chiron',     // ADDED
        'Mean_Lilith' // ADDED
    ];
    
    if (isset($astroData['Planets'])) {
        foreach ($allPointsToInclude as $point) {
            if (isset($astroData['Planets'][$point])) {
                $pointData = $astroData['Planets'][$point];
                
                // Map point names for user-friendly display
                $displayNameMap = [
                    'True_Node' => 'North Node',
                    'Mean_Node' => 'South Node',
                    'Mean_Lilith' => 'Lilith',
                    'Chiron' => 'Chiron'
                ];
                
                $displayName = $displayNameMap[$point] ?? $point;
                
                $formatted['planets'][] = [
                    'planet' => $displayName,
                    'sign' => $pointData['sign'] ?? '',
                    'degree' => $pointData['position'] ?? 0,
                    'house' => extractHouseNumber($pointData['house'] ?? '')
                ];
            }
        }
    }
    
    // Process aspects - filter based on requirements
    if (isset($astroData['Aspects'])) {
        foreach ($astroData['Aspects'] as $aspect) {
            $planetA = $aspect['p1_name'] ?? '';
            $planetB = $aspect['p2_name'] ?? '';
            
            // Only include valid aspects (no houses, no mean node, etc.)
            if (isValidAspect($planetA, $planetB, $validPlanets, $excludedEntities)) {
                $orbit = $aspect['orbit'] ?? 0;
                $formatted['aspects'][] = [
                    'point_a' => $planetA,
                    'aspect_type' => strtolower($aspect['aspect'] ?? ''),
                    'point_b' => $planetB,
                    'orb' => abs($orbit),
                    'motion' => $orbit > 0 ? 'applying' : 'separating'
                ];
            }
        }
    }
    
    return $formatted;
}

// Function to format human design data
function formatHumanDesignData($hdData) {
    $formatted = [
        'type' => '',
        'strategy' => '',
        'authority' => '',
        'profile' => '',
        'definition' => '',
        'incarnation_cross' => '',
        'centers' => [
            'defined' => [],
            'open' => []
        ],
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
    
    // Extract basic info from Properties
    if (isset($hdData['Properties'])) {
        $props = $hdData['Properties'];
        
        $formatted['type'] = $props['Type']['option'] ?? '';
        $formatted['strategy'] = $props['Strategy']['option'] ?? '';
        $formatted['authority'] = $props['InnerAuthority']['option'] ?? '';
        $formatted['profile'] = $props['Profile']['option'] ?? '';
        $formatted['definition'] = $props['Definition']['option'] ?? '';
        $formatted['incarnation_cross'] = $props['IncarnationCross']['option'] ?? '';
    }
    
    // Extract defined centers
    if (isset($hdData['DefinedCenters'])) {
        foreach ($hdData['DefinedCenters'] as $center) {
            $formatted['centers']['defined'][] = $center;
        }
    } elseif (isset($hdData['Properties']['DefinedCenters']['list'])) {
        foreach ($hdData['Properties']['DefinedCenters']['list'] as $center) {
            $formatted['centers']['defined'][] = $center['option'] ?? $center['id'] ?? '';
        }
    }
    
    // Extract open centers
    if (isset($hdData['OpenCenters'])) {
        foreach ($hdData['OpenCenters'] as $center) {
            $formatted['centers']['open'][] = $center;
        }
    } elseif (isset($hdData['Properties']['OpenCenters']['list'])) {
        foreach ($hdData['Properties']['OpenCenters']['list'] as $center) {
            $formatted['centers']['open'][] = $center['option'] ?? $center['id'] ?? '';
        }
    }
    
    // Extract channels
    if (isset($hdData['Channels'])) {
        foreach ($hdData['Channels'] as $channel) {
            $formatted['channels'][] = $channel;
        }
    } elseif (isset($hdData['Properties']['Channels']['list'])) {
        foreach ($hdData['Properties']['Channels']['list'] as $channel) {
            $formatted['channels'][] = $channel['option'] ?? $channel['id'] ?? '';
        }
    }
    
    // Extract gates
    if (isset($hdData['Gates'])) {
        foreach ($hdData['Gates'] as $gate) {
            if (is_numeric($gate)) {
                $formatted['gates'][] = (int)$gate;
            }
        }
    } elseif (isset($hdData['Properties']['Gates']['list'])) {
        foreach ($hdData['Properties']['Gates']['list'] as $gate) {
            if (isset($gate['id']) && is_numeric($gate['id'])) {
                $formatted['gates'][] = (int)$gate['id'];
            } elseif (isset($gate['option']) && is_numeric($gate['option'])) {
                $formatted['gates'][] = (int)$gate['option'];
            }
        }
    }
    
    // Extract Personality data
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
    
    // Extract Design data
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

// Fetch data from APIs
$hd_response = call_api($hd_url);
// print_r($hd_response); - COMMENTED
$astro_response = call_api($astro_url);
// print_r($astro_response); - COMMENTED

// Check for API errors
$has_api_error = isset($hd_response['error']) || isset($astro_response['error']);

// Format the responses
$formattedAstro = formatAstrologyData($astro_response, $validPlanets, $excludedEntities);
$formattedHD = formatHumanDesignData($hd_response);

// Prepare final result according to schema                                                   
$result = [
    'astrology' => [
        'house_system' => [
            'name' => $house_system_name
        ],
        'ascendant' => $formattedAstro['ascendant'],
        'midheaven' => $formattedAstro['midheaven'],
        'planets' => $formattedAstro['planets'], // NOW INCLUDES CHIRON AND LILITH
        'aspects' => $formattedAstro['aspects']  // NOW INCLUDES ASPECTS WITH CHIRON/LILITH
    ],
    'human_design' => $formattedHD
];

// If there are API errors, include them in debug info
if ($has_api_error) {
    $result['debug'] = [
        'errors' => [
            'human_design' => $hd_response['error'] ?? null,
            'astrology' => $astro_response['error'] ?? null
        ]
    ];
}

// Return clean JSON response
echo json_encode($result, JSON_PRETTY_PRINT);
?>