<?php
/**
 * CreditLinks Partner API Integration
 * Loan Application Form Handler
 */

// Configuration
define('API_KEY', '4827f87b-0e70-45ac-b822-92e7b4d6a291'); // Replace with your actual API key
define('ENVIRONMENT', 'UAT'); // Change to 'PROD' for production

// API URLs
$apiUrls = [
    'UAT' => [
        'dedupe' => 'https://loannet.in:8000/api/partner/dedupe',
        'create_lead' => 'https://loannet.in:8000/api/v2/partner/create-lead',
        'get_offers' => 'https://loannet.in:8000/api/partner/get-offers/',
        'get_summary' => 'https://loannet.in:8000/api/partner/get-summary/',
        'gold_loans' => 'https://loannet.in:8000/api/v2/partner/gold-loans',
        'housing_loan' => 'https://loannet.in:8000/api/v2/partner/housing-loan'
    ],
    'PROD' => [
        'dedupe' => 'https://l.creditlinks.in:8000/api/partner/dedupe',
        'create_lead' => 'https://l.creditlinks.in:8000/api/v2/partner/create-lead',
        'get_offers' => 'https://l.creditlinks.in:8000/api/partner/get-offers/',
        'get_summary' => 'https://l.creditlinks.in:8000/api/partner/get-summary/',
        'gold_loans' => 'https://l.creditlinks.in:8000/api/v2/partner/gold-loans',
        'housing_loan' => 'https://l.creditlinks.in:8000/api/v2/partner/housing-loan'
    ]
];

/**
 * Get client IP address with auto-detection fallback
 */
function getClientIP() {
    // First, try to get IP from server variables
    $ipSources = [
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];
    
    foreach ($ipSources as $source) {
        if (!empty($_SERVER[$source])) {
            $ip = $_SERVER[$source];
            // Handle multiple IPs (take the first one)
            if (strpos($ip, ',') !== false) {
                $ipList = explode(',', $ip);
                foreach ($ipList as $singleIp) {
                    $singleIp = trim($singleIp);
                    // Validate and return first valid public IP
                    if (filter_var($singleIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        // Skip localhost and private IPs
                        if (!in_array($singleIp, ['127.0.0.1', '0.0.0.0', '::1']) && 
                            !filter_var($singleIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
                            return $singleIp;
                        }
                    }
                }
            } else {
                $ip = trim($ip);
                // Validate IP format
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    // Skip localhost
                    if (!in_array($ip, ['127.0.0.1', '0.0.0.0', '::1'])) {
                        // Check if it's a public IP
                        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
                            return $ip;
                        }
                    }
                }
            }
        }
    }
    
    // If no valid IP from server, try external IP detection services
    $externalIP = getExternalIP();
    if ($externalIP) {
        return $externalIP;
    }
    
    // Last resort fallback
    return '8.8.8.8';
}

/**
 * Get external IP using third-party services
 */
function getExternalIP() {
    // List of reliable IP detection services
    $services = [
        'https://api.ipify.org',
        'https://icanhazip.com',
        'https://ipinfo.io/ip',
        'https://checkip.amazonaws.com'
    ];
    
    foreach ($services as $service) {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $service);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3); // 3 seconds timeout
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $ip = trim(curl_exec($ch));
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            // Validate the returned IP
            if ($httpCode === 200 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                // Make sure it's not a private or localhost IP
                if (!in_array($ip, ['127.0.0.1', '0.0.0.0', '::1']) && 
                    !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
                    return $ip;
                }
            }
        } catch (Exception $e) {
            // Continue to next service if this one fails
            continue;
        }
    }
    
    return null;
}

/**
 * Make API request to CreditLinks
 */
function makeApiRequest($url, $method = 'POST', $data = null) {
    $headers = [
        'apikey: ' . API_KEY,
        'Content-Type: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For development
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // Log for debugging (remove in production)
    if (ENVIRONMENT === 'UAT') {
        error_log("API Request to: " . $url);
        error_log("Request Data: " . json_encode($data));
        error_log("Response Code: " . $httpCode);
        error_log("Response Body: " . $response);
        if ($curlError) {
            error_log("CURL Error: " . $curlError);
        }
    }
    
    return [
        'code' => $httpCode,
        'response' => json_decode($response, true),
        'raw_response' => $response,
        'curl_error' => $curlError
    ];
}

/**
 * Check if customer already exists (Dedupe API)
 */
function checkDedupe($mobileNumber) {
    global $apiUrls;
    $url = $apiUrls[ENVIRONMENT]['dedupe'];
    $data = ['mobileNumber' => $mobileNumber];
    return makeApiRequest($url, 'POST', $data);
}

/**
 * Create Personal Loan Lead
 */
function createLead($formData) {
    global $apiUrls;
    $url = $apiUrls[ENVIRONMENT]['create_lead'];
    
    // Use manual IP if provided in UAT, otherwise use detected IP
    $clientIP = getClientIP();
    if (ENVIRONMENT === 'UAT' && !empty($formData['manual_ip'])) {
        if (filter_var($formData['manual_ip'], FILTER_VALIDATE_IP)) {
            $clientIP = $formData['manual_ip'];
        }
    }
    
    $payload = [
        'mobileNumber' => $formData['mobileNumber'],
        'firstName' => $formData['firstName'],
        'lastName' => $formData['lastName'],
        'pan' => strtoupper($formData['pan']),
        'dob' => $formData['dob'],
        'email' => $formData['email'],
        'pincode' => $formData['pincode'],
        'monthlyIncome' => (int)$formData['monthlyIncome'],
        'consumerConsentDate' => date('Y-m-d H:i:s'),
        'consumerConsentIp' => $clientIP,
        'employmentStatus' => (int)$formData['employmentStatus']
    ];
    
    // Add credit score class if provided
    if (!empty($formData['creditScoreClass'])) {
        $payload['creditScoreClass'] = (int)$formData['creditScoreClass'];
    }
    
    // Add UTM parameters if provided
    $utmParams = ['utm_id', 'utm_campaign', 'utm_source', 'utm_medium', 'utm_content', 'utm_term', 'pid', 'sub_id1', 'sub_id2', 'sub_id3'];
    foreach ($utmParams as $param) {
        if (!empty($formData[$param])) {
            $payload[$param] = $formData[$param];
        }
    }
    
    // Add waitForAllOffers if provided
    if (isset($formData['waitForAllOffers'])) {
        $payload['waitForAllOffers'] = (int)$formData['waitForAllOffers'];
    }
    
    // Employment specific fields
    if ($formData['employmentStatus'] == 1) {
        // Salaried
        $payload['employerName'] = $formData['employerName'];
        $payload['officePincode'] = $formData['officePincode'];
    } elseif ($formData['employmentStatus'] == 2) {
        // Self-employed
        $payload['businessRegistrationType'] = (int)$formData['businessRegistrationType'];
        
        if ($formData['businessRegistrationType'] != 8) {
            $payload['residenceType'] = (int)$formData['residenceType'];
            $payload['businessCurrentTurnover'] = (int)$formData['businessCurrentTurnover'];
            $payload['businessYears'] = (int)$formData['businessYears'];
            $payload['businessAccount'] = (int)$formData['businessAccount'];
        }
    }
    
    return makeApiRequest($url, 'POST', $payload);
}

/**
 * Create Gold Loan Lead
 */
function createGoldLoan($formData) {
    global $apiUrls;
    $url = $apiUrls[ENVIRONMENT]['gold_loans'];
    
    // Use manual IP if provided in UAT, otherwise use detected IP
    $clientIP = getClientIP();
    if (ENVIRONMENT === 'UAT' && !empty($formData['manual_ip'])) {
        if (filter_var($formData['manual_ip'], FILTER_VALIDATE_IP)) {
            $clientIP = $formData['manual_ip'];
        }
    }
    
    $payload = [
        'mobileNumber' => $formData['mobileNumber'],
        'firstName' => $formData['firstName'],
        'lastName' => $formData['lastName'],
        'pan' => strtoupper($formData['pan']),
        'email' => $formData['email'],
        'pincode' => $formData['pincode'],
        'loanAmount' => (int)$formData['loanAmount'],
        'consumerConsentDate' => date('Y-m-d H:i:s'),
        'consumerConsentIp' => $clientIP
    ];
    
    // Add UTM parameters if provided
    $utmParams = ['utm_id', 'utm_campaign', 'utm_source', 'utm_medium', 'utm_content', 'utm_term', 'pid', 'sub_id1', 'sub_id2', 'sub_id3'];
    foreach ($utmParams as $param) {
        if (!empty($formData[$param])) {
            $payload[$param] = $formData[$param];
        }
    }
    
    return makeApiRequest($url, 'POST', $payload);
}

/**
 * Create Housing Loan Lead
 */
function createHousingLoan($formData) {
    global $apiUrls;
    $url = $apiUrls[ENVIRONMENT]['housing_loan'];
    
    // Use manual IP if provided in UAT, otherwise use detected IP
    $clientIP = getClientIP();
    if (ENVIRONMENT === 'UAT' && !empty($formData['manual_ip'])) {
        if (filter_var($formData['manual_ip'], FILTER_VALIDATE_IP)) {
            $clientIP = $formData['manual_ip'];
        }
    }
    
    $payload = [
        'mobileNumber' => $formData['mobileNumber'],
        'firstName' => $formData['firstName'],
        'lastName' => $formData['lastName'],
        'pan' => strtoupper($formData['pan']),
        'dob' => $formData['dob'],
        'email' => $formData['email'],
        'pincode' => $formData['pincode'],
        'monthlyIncome' => (int)$formData['monthlyIncome'],
        'housingLoanAmount' => (int)$formData['housingLoanAmount'],
        'propertyType' => $formData['propertyType'],
        'consumerConsentDate' => date('Y-m-d H:i:s'),
        'consumerConsentIp' => $clientIP
    ];
    
    // Add UTM parameters if provided
    $utmParams = ['utm_id', 'utm_campaign', 'utm_source', 'utm_medium', 'utm_content', 'utm_term', 'pid', 'sub_id1', 'sub_id2', 'sub_id3'];
    foreach ($utmParams as $param) {
        if (!empty($formData[$param])) {
            $payload[$param] = $formData[$param];
        }
    }
    
    return makeApiRequest($url, 'POST', $payload);
}

/**
 * Get loan offers for a lead
 */
function getOffers($leadId) {
    global $apiUrls;
    $url = $apiUrls[ENVIRONMENT]['get_offers'] . $leadId;
    return makeApiRequest($url, 'GET');
}

/**
 * Get summary for a lead
 */
function getSummary($leadId) {
    global $apiUrls;
    $url = $apiUrls[ENVIRONMENT]['get_summary'] . $leadId;
    return makeApiRequest($url, 'GET');
}

// Handle form submission
$response = null;
$error = null;
$showOffers = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $loanType = $_POST['loanType'] ?? 'personal';
        
        // Basic validation
        if (empty($_POST['mobileNumber']) || empty($_POST['firstName']) || empty($_POST['lastName'])) {
            throw new Exception('Please fill all required fields');
        }
        
        // Validate mobile number
        if (!preg_match('/^[0-9]{10}$/', $_POST['mobileNumber'])) {
            throw new Exception('Mobile number must be exactly 10 digits');
        }
        
        // Validate PAN format
        if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', strtoupper($_POST['pan']))) {
            throw new Exception('Invalid PAN format. Use format: ABCDE1234F');
        }
        
        // Validate pincode
        if (!preg_match('/^[0-9]{6}$/', $_POST['pincode'])) {
            throw new Exception('Pincode must be exactly 6 digits');
        }
        
        // Convert date format from dd/mm/yyyy to YYYY-MM-DD if needed
        if (!empty($_POST['dob'])) {
            $dob = $_POST['dob'];
            // Check if date is in dd/mm/yyyy format
            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dob, $matches)) {
                $_POST['dob'] = $matches[3] . '-' . $matches[2] . '-' . $matches[1];
            }
            // Validate date format YYYY-MM-DD
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['dob'])) {
                throw new Exception('Invalid date format. Use YYYY-MM-DD or dd/mm/yyyy');
            }
        }
        
        // Validate employment status for personal loans
        if ($loanType === 'personal') {
            if (empty($_POST['employmentStatus'])) {
                throw new Exception('Employment status is required');
            }
            
            // Validate salaried specific fields
            if ($_POST['employmentStatus'] == 1) {
                if (empty($_POST['employerName'])) {
                    throw new Exception('Employer name is required for salaried employees');
                }
                if (empty($_POST['officePincode']) || !preg_match('/^[0-9]{6}$/', $_POST['officePincode'])) {
                    throw new Exception('Valid office pincode is required for salaried employees');
                }
            }
            
            // Validate self-employed specific fields
            if ($_POST['employmentStatus'] == 2) {
                if (empty($_POST['businessRegistrationType'])) {
                    throw new Exception('Business registration type is required for self-employed');
                }
                
                // If business registration is not "No business proof" (8)
                if ($_POST['businessRegistrationType'] != 8) {
                    if (empty($_POST['residenceType'])) {
                        throw new Exception('Residence type is required');
                    }
                    if (empty($_POST['businessCurrentTurnover'])) {
                        throw new Exception('Business turnover is required');
                    }
                    if (empty($_POST['businessYears'])) {
                        throw new Exception('Years in business is required');
                    }
                    if (empty($_POST['businessAccount'])) {
                        throw new Exception('Business account information is required');
                    }
                }
            }
        }
        
        // Process based on loan type
        switch ($loanType) {
            case 'personal':
                $result = createLead($_POST);
                break;
            case 'gold':
                $result = createGoldLoan($_POST);
                break;
            case 'housing':
                $result = createHousingLoan($_POST);
                break;
            default:
                throw new Exception('Invalid loan type');
        }
        
        if ($result['response']['success'] === 'true' || $result['response']['success'] === true) {
            $response = $result['response'];
            
            // For personal loans, fetch offers using the leadId
            if ($loanType === 'personal' && !empty($response['leadId'])) {
                // Wait a moment for offers to be prepared
                sleep(2);
                $offersResult = getOffers($response['leadId']);
                
                if (!empty($offersResult['response']['offers'])) {
                    $response['offers'] = $offersResult['response']['offers'];
                }
            }
            
            // Redirect to offers page if we have offers
            if (!empty($response['offers']) && count($response['offers']) > 0) {
                $showOffers = true;
            }
        } else {
            $error = $result['response']['message'] ?? 'Application failed';
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CreditLinks Loan Application</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
        }
        
        .container {
            display: flex;
            min-height: 100vh;
        }
        
        .left-panel {
            flex: 0 0 35%;
            background: linear-gradient(180deg, #1e6df7 0%, #e91e63 100%);
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 60px 40px;
            color: white;
        }
        
        .logo {
            position: absolute;
            top: 40px;
            left: 40px;
            font-size: 32px;
            font-weight: bold;
        }
        
        .logo-switch {
            color: #e91e63;
        }
        
        .logo-myloan {
            color: white;
        }
        
        .hero-content {
            text-align: center;
            z-index: 2;
        }
        
        .hero-content h1 {
            font-size: 42px;
            font-weight: 700;
            margin-bottom: 20px;
            line-height: 1.2;
        }
        
        .hero-content p {
            font-size: 18px;
            line-height: 1.6;
            opacity: 0.95;
        }
        
        .hero-image {
            margin-top: 40px;
            font-size: 120px;
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        
        .right-panel {
            flex: 1;
            background: #fafafa;
            overflow-y: auto;
        }
        
        .form-content {
            max-width: 800px;
            padding: 60px 80px;
            background: white;
            margin: 0;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }
        
        .form-group label .required {
            color: #e91e63;
            margin-left: 2px;
        }
        
        .form-control {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.3s;
            background: #f9fafb;
            color: #1f2937;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #1e6df7;
            background: white;
            box-shadow: 0 0 0 3px rgba(30, 109, 247, 0.1);
        }
        
        .form-control::placeholder {
            color: #9ca3af;
        }
        
        .helper-text {
            display: block;
            margin-top: 6px;
            font-size: 12px;
            color: #6b7280;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        
        .btn {
            background: linear-gradient(135deg, #1e6df7 0%, #e91e63 100%);
            color: white;
            padding: 16px 32px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s, box-shadow 0.2s;
            margin-top: 20px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(30, 109, 247, 0.3);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .loan-type-selector {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 32px;
        }
        
        .loan-type-btn {
            padding: 20px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            background: white;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s;
            position: relative;
        }
        
        .loan-type-btn:hover {
            border-color: #1e6df7;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .loan-type-btn.active {
            border-color: #1e6df7;
            background: linear-gradient(135deg, rgba(30, 109, 247, 0.05) 0%, rgba(233, 30, 99, 0.05) 100%);
        }
        
        .loan-type-btn h3 {
            font-size: 18px;
            margin-bottom: 8px;
            color: #1f2937;
        }
        
        .loan-type-btn p {
            font-size: 13px;
            color: #6b7280;
        }
        
        .loan-type-icon {
            font-size: 32px;
            margin-bottom: 8px;
        }
        
        .conditional-field {
            display: none;
        }
        
        .conditional-field.show {
            display: block;
        }
        
        .verify-btn {
            padding: 10px 24px;
            background: white;
            color: #1e6df7;
            border: 2px solid #1e6df7;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-left: 10px;
        }
        
        .verify-btn:hover {
            background: #1e6df7;
            color: white;
        }
        
        .input-with-button {
            display: flex;
            align-items: center;
        }
        
        .input-with-button .form-control {
            flex: 1;
        }
        
        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 20px;
            background: #f0f9ff;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .checkbox-group input[type="checkbox"] {
            margin-top: 4px;
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .checkbox-group label {
            margin: 0;
            font-size: 13px;
            line-height: 1.6;
            color: #374151;
        }
        
        .alert {
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            border-left: 4px solid;
        }
        
        .alert-success {
            background: #d1fae5;
            border-color: #10b981;
            color: #065f46;
        }
        
        .alert-error {
            background: #fee2e2;
            border-color: #ef4444;
            color: #991b1b;
        }
        
        .alert h3 {
            margin-bottom: 10px;
            font-size: 18px;
        }
        
        .offers-container {
            margin-top: 24px;
        }
        
        .offer-card {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 16px;
            background: white;
            transition: all 0.3s;
        }
        
        .offer-card:hover {
            border-color: #1e6df7;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .offer-card img {
            max-width: 120px;
            margin-bottom: 16px;
        }
        
        .offer-card h4 {
            font-size: 18px;
            margin-bottom: 12px;
            color: #1f2937;
        }
        
        .offer-link {
            display: inline-block;
            margin-top: 12px;
            padding: 12px 24px;
            background: linear-gradient(135deg, #1e6df7 0%, #e91e63 100%);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .offer-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(30, 109, 247, 0.3);
        }
        
        @media (max-width: 1024px) {
            .container {
                flex-direction: column;
            }
            
            .left-panel {
                flex: 0 0 auto;
                min-height: 400px;
            }
            
            .form-content {
                padding: 40px;
            }
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .loan-type-selector {
                grid-template-columns: 1fr;
            }
            
            .form-content {
                padding: 30px 20px;
            }
            
            .left-panel {
                padding: 40px 20px;
            }
            
            .hero-content h1 {
                font-size: 32px;
            }
            
            .logo {
                font-size: 24px;
                top: 20px;
                left: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="left-panel">
            <div class="logo">
                <span class="logo-switch">Switch</span><span class="logo-myloan">MyLoan.in</span>
            </div>
            <div class="hero-content">
                <h1>Take a New Loan</h1>
                <p>Want us to get you the best loan from the sea of options available?</p>
                <div class="hero-image">🎉</div>
            </div>
        </div>
        
        <div class="right-panel">
            <div class="form-content">
            <?php if ($response): ?>
                <div class="alert alert-success">
                    <h3>✓ Application Successful!</h3>
                    <p><strong>Lead ID:</strong> <?php echo htmlspecialchars($response['leadId']); ?></p>
                    <p><?php echo htmlspecialchars($response['message']); ?></p>
                    
                    <?php if (!empty($response['offers'])): ?>
                        <div class="offers-container">
                            <h3>Available Offers:</h3>
                            <?php foreach ($response['offers'] as $offer): ?>
                                <div class="offer-card">
                                    <?php if (!empty($offer['lenderLogo'])): ?>
                                        <img src="<?php echo htmlspecialchars($offer['lenderLogo']); ?>" alt="<?php echo htmlspecialchars($offer['lenderName']); ?>">
                                    <?php endif; ?>
                                    <h4><?php echo htmlspecialchars($offer['lenderName']); ?></h4>
                                    <?php if (!empty($offer['offerAmountUpTo'])): ?>
                                        <p><strong>Amount:</strong> Up to ₹<?php echo number_format($offer['offerAmountUpTo']); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($offer['offerLink'])): ?>
                                        <a href="<?php echo htmlspecialchars($offer['offerLink']); ?>" class="offer-link" target="_blank">Continue Application →</a>
                                    <?php endif; ?>
                                    <?php if (!empty($offer['message'])): ?>
                                        <p><?php echo htmlspecialchars($offer['message']); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="loanForm">
                <div class="loan-type-selector">
                    <div class="loan-type-btn active" data-type="personal">
                        <div class="loan-type-icon">💼</div>
                        <h3>Personal Loan</h3>
                        <p>Quick approval</p>
                    </div>
                    <div class="loan-type-btn" data-type="gold">
                        <div class="loan-type-icon">💰</div>
                        <h3>Gold Loan</h3>
                        <p>Against gold</p>
                    </div>
                    <div class="loan-type-btn" data-type="housing">
                        <div class="loan-type-icon">🏠</div>
                        <h3>Housing Loan</h3>
                        <p>Home financing</p>
                    </div>
                </div>
                
                <input type="hidden" name="loanType" id="loanType" value="personal">
                
                <!-- Common Fields -->
                <div class="form-row">
                    <div class="form-group">
                        <label>First Name <span class="required">*</span></label>
                        <input type="text" name="firstName" class="form-control" placeholder="vakil" required>
                        <span class="helper-text">First name should be same as PAN card</span>
                    </div>
                    <div class="form-group">
                        <label>Last Name <span class="required">*</span></label>
                        <input type="text" name="lastName" class="form-control" placeholder="rathod" required>
                        <span class="helper-text">Last name should be same as PAN card</span>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Mobile No <span class="required">*</span></label>
                        <div class="input-with-button">
                            <input type="tel" name="mobileNumber" class="form-control" pattern="[0-9]{10}" placeholder="7738285764" required>
                            <button type="button" class="verify-btn">Verify</button>
                        </div>
                        <span class="helper-text">Mobile number must be linked PAN card</span>
                    </div>
                    <div class="form-group">
                        <label>Email ID <span class="required">*</span></label>
                        <input type="email" name="email" class="form-control" placeholder="vakilbetter6762@gmail.com" required>
                        <span class="helper-text">Email address must be linked PAN card</span>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>PAN No <span class="required">*</span></label>
                        <input type="text" name="pan" class="form-control" pattern="[A-Z]{5}[0-9]{4}[A-Z]{1}" placeholder="Eg. ABCEF0000X" required>
                        <span class="helper-text">Please enter PAN number in Capital latter <strong>Eg. ABCEF0000X</strong></span>
                    </div>
                    <div class="form-group">
                        <label>Date of Birth <span class="required">*</span></label>
                        <input type="text" name="dob" class="form-control" placeholder="dd/mm/yyyy">
                        <span class="helper-text">Date of Birth should be same as PAN Card</span>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Profile <span class="required">*</span></label>
                        <select name="employmentStatus" id="employmentStatus" class="form-control">
                            <option value="">Select</option>
                            <option value="1">Salaried</option>
                            <option value="2">Self Employed</option>
                        </select>
                        <span class="helper-text">Select your profile</span>
                    </div>
                    <div class="form-group conditional-field personal-fields housing-fields show">
                        <label>Gender <span class="required">*</span></label>
                        <select name="gender" class="form-control">
                            <option value="">Select</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                        <span class="helper-text">Select gender</span>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group conditional-field personal-fields housing-fields show">
                        <label>Monthly Income <span class="required">*</span></label>
                        <input type="number" name="monthlyIncome" class="form-control" placeholder="Eg. 50000" required>
                        <span class="helper-text">Monthly income should be same as payslip / other income certificate</span>
                    </div>
                    <div class="form-group">
                        <label>Pin Code <span class="required">*</span></label>
                        <input type="text" name="pincode" class="form-control" pattern="[0-9]{6}" placeholder="400051" required>
                        <span class="helper-text">Enter Postal code <strong>Eg. 400101</strong></span>
                    </div>
                </div>
                
                <!-- Credit Score Class (Optional) -->
                <div class="conditional-field personal-fields show">
                    <div class="form-group">
                        <label>Credit Score Class (Optional)</label>
                        <select name="creditScoreClass" class="form-control">
                            <option value="">Select (if known)</option>
                            <option value="1">Prime (≥730)</option>
                            <option value="2">Near Prime (680-729)</option>
                            <option value="3">Sub-Prime (≤679)</option>
                        </select>
                        <span class="helper-text">Select your credit score range if you know it</span>
                    </div>
                </div>
                
                <!-- Salaried Fields -->
                <div id="salariedFields" class="conditional-field">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Employer Name <span class="required">*</span></label>
                            <input type="text" name="employerName" class="form-control" placeholder="Company Name">
                            <span class="helper-text">Enter your current employer name</span>
                        </div>
                        <div class="form-group">
                            <label>Office Pincode <span class="required">*</span></label>
                            <input type="text" name="officePincode" class="form-control" pattern="[0-9]{6}" placeholder="110001">
                            <span class="helper-text">Office location pincode (6 digits)</span>
                        </div>
                    </div>
                </div>
                
                <!-- Self Employed Fields -->
                <div id="selfEmployedFields" class="conditional-field">
                    <div class="form-group">
                        <label>Business Registration Type <span class="required">*</span></label>
                        <select name="businessRegistrationType" id="businessRegistrationType" class="form-control">
                            <option value="">Select</option>
                            <option value="1">GST</option>
                            <option value="2">Shop & Establishment</option>
                            <option value="3">Municipal Corporation/Mahanagr Palika Gramapanchayat</option>
                            <option value="4">Palika Gramapanchayat</option>
                            <option value="5">Udyog Aadhar</option>
                            <option value="6">Drugs License/Food and Drugs Control Certificate</option>
                            <option value="7">Other</option>
                            <option value="8">No Business Proof</option>
                        </select>
                        <span class="helper-text">Select your business registration type</span>
                    </div>
                    
                    <div id="businessDetailsFields" class="conditional-field">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Residence Type <span class="required">*</span></label>
                                <select name="residenceType" class="form-control">
                                    <option value="">Select</option>
                                    <option value="1">Rented</option>
                                    <option value="2">Owned</option>
                                </select>
                                <span class="helper-text">Your current residence type</span>
                            </div>
                            <div class="form-group">
                                <label>Business Current Turnover <span class="required">*</span></label>
                                <select name="businessCurrentTurnover" class="form-control">
                                    <option value="">Select</option>
                                    <option value="1">Up to 6 lacs</option>
                                    <option value="2">6-12 lacs</option>
                                    <option value="3">12-20 lacs</option>
                                    <option value="4">Above 20 lacs</option>
                                </select>
                                <span class="helper-text">Annual business turnover</span>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Years in Current Business <span class="required">*</span></label>
                                <select name="businessYears" class="form-control">
                                    <option value="">Select</option>
                                    <option value="1">Less than 1 year</option>
                                    <option value="2">1-2 years</option>
                                    <option value="3">More than 2 years</option>
                                </select>
                                <span class="helper-text">Years of business operation</span>
                            </div>
                            <div class="form-group">
                                <label>Current Account in Business Name? <span class="required">*</span></label>
                                <select name="businessAccount" class="form-control">
                                    <option value="">Select</option>
                                    <option value="1">Yes</option>
                                    <option value="2">No</option>
                                </select>
                                <span class="helper-text">Do you have a business current account?</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Gold Loan Specific Fields -->
                <div class="conditional-field gold-fields">
                    <div class="form-group">
                        <label>Loan Amount Required <span class="required">*</span></label>
                        <input type="number" name="loanAmount" class="form-control" placeholder="100000" required>
                        <span class="helper-text">Enter the loan amount you need</span>
                    </div>
                </div>
                
                <!-- Housing Loan Specific Fields -->
                <div class="conditional-field housing-fields">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Housing Loan Amount <span class="required">*</span></label>
                            <input type="number" name="housingLoanAmount" class="form-control" placeholder="1000000" required>
                            <span class="helper-text">Loan amount required for property</span>
                        </div>
                        <div class="form-group">
                            <label>Property Type <span class="required">*</span></label>
                            <input type="text" name="propertyType" class="form-control" placeholder="House" required>
                            <span class="helper-text">e.g., House, Apartment, Villa</span>
                        </div>
                    </div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="consent" required>
                    <label for="consent">
                        You hereby consent to SwitchMyLoan being appointed as your authorised representative to receive your Credit Information from Experian/CIBIL/EQUIFAX/CRIF for the purpose of Credit Assessment of the End User to Advise him on the best loan offers (End Use Purpose) or expiry of 6 months from the date the consent is collected; whichever is earlier. You hereby also agree that we would share this consent with our Lending Partners to Process your loan application.
                    </label>
                </div>
                
                <?php if (ENVIRONMENT === 'UAT'): ?>
                <div class="form-group" style="background: #d1f2eb; padding: 15px; border-radius: 8px; border: 1px solid #1abc9c;">
                    <label style="color: #0e6655; font-weight: bold;">✓ Auto IP Detection Enabled</label>
                    <div style="background: white; padding: 12px; border-radius: 6px; margin-top: 10px;">
                        <span class="helper-text" style="color: #0e6655; font-size: 14px;">
                            <strong>Detected IP:</strong> <code style="background: #f8f9fa; padding: 4px 8px; border-radius: 4px; color: #e74c3c; font-weight: bold;"><?php echo getClientIP(); ?></code><br><br>
                            <strong>Detection Method:</strong><br>
                            • Checks server variables (REMOTE_ADDR, X-FORWARDED-FOR, etc.)<br>
                            • Falls back to external IP services (ipify.org, icanhazip.com)<br>
                            • Automatically excludes localhost/private IPs<br>
                            • Uses 8.8.8.8 only if all detection fails
                        </span>
                    </div>
                    <input type="text" name="manual_ip" class="form-control" placeholder="Optional: Override with custom IP" pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$" style="margin-top: 10px;">
                    <span class="helper-text" style="color: #0e6655; font-size: 12px;">
                        Leave empty to use auto-detected IP or enter custom IP for testing
                    </span>
                </div>
                <?php endif; ?>
                
                <button type="submit" class="btn">Submit Application</button>
            </form>
        </div>
    </div>
</div>
    
    <script>
        // Loan type selector
        document.querySelectorAll('.loan-type-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.loan-type-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const type = this.dataset.type;
                document.getElementById('loanType').value = type;
                
                // Hide all conditional fields
                document.querySelectorAll('.conditional-field').forEach(field => {
                    field.classList.remove('show');
                });
                
                // Show relevant fields
                document.querySelectorAll(`.${type}-fields`).forEach(field => {
                    field.classList.add('show');
                });
            });
        });
        
        // Employment status change
        document.getElementById('employmentStatus').addEventListener('change', function() {
            document.getElementById('salariedFields').classList.remove('show');
            document.getElementById('selfEmployedFields').classList.remove('show');
            
            if (this.value == '1') {
                document.getElementById('salariedFields').classList.add('show');
            } else if (this.value == '2') {
                document.getElementById('selfEmployedFields').classList.add('show');
            }
        });
        
        // Business registration type change
        document.getElementById('businessRegistrationType').addEventListener('change', function() {
            const businessDetails = document.getElementById('businessDetailsFields');
            if (this.value != '8' && this.value != '') {
                businessDetails.classList.add('show');
            } else {
                businessDetails.classList.remove('show');
            }
        });
    </script>
</body>
</html>