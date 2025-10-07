<?php
/**
 * Loan Application Form Page
 * File: index.php
 */
// Load environment variables from .env file
function loadEnv($path) {
    if (!file_exists($path)) {
        die('.env file not found');
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
        }
    }
}
// Load .env file
loadEnv(__DIR__ . '/.env');
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Get environment variables
$environment = getenv('ENVIRONMENT') ?: 'UAT';
$apiKey = getenv('API_KEY');
// Database configuration from .env
$dbHost = getenv('DB_HOST') ?: '82.25.121.2';
$dbName = getenv('DB_NAME') ?: 'u527886566_vakilbetter676';
$dbUser = getenv('DB_USER') ?: 'u527886566_vakilbetter676';
$dbPass = getenv('DB_PASS') ?: 'VAKILr@6762';
// Get API URLs based on environment
if ($environment === 'PROD') {
    $createLeadUrl = getenv('PROD_CREATE_LEAD_URL');
    $getOffersUrl = getenv('PROD_GET_OFFERS_URL');
} else {
    $createLeadUrl = getenv('UAT_CREATE_LEAD_URL');
    $getOffersUrl = getenv('UAT_GET_OFFERS_URL');
}
/**
 * Database connection
 */
function getDBConnection() {
    global $dbHost, $dbName, $dbUser, $dbPass;
    try {
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        return null;
    }
}
/**
 * Save lead to database
 */
function saveLeadToDatabase($formData, $leadId, $apiResponse) {
    try {
        $pdo = getDBConnection();
        if (!$pdo) {
            return false;
        }
        $sql = "INSERT INTO leads (
            lead_id, first_name, last_name, mobile_number, email, pan, dob,
            pincode, monthly_income, loan_type, credit_score_class, employment_status,
            employer_name, office_pincode, business_registration_type,
            residence_type, business_turnover, business_years, business_account,
            consumer_consent_ip, api_response, created_at
        ) VALUES (
            :lead_id, :first_name, :last_name, :mobile_number, :email, :pan, :dob,
            :pincode, :monthly_income, :loan_type, :credit_score_class, :employment_status,
            :employer_name, :office_pincode, :business_registration_type,
            :residence_type, :business_turnover, :business_years, :business_account,
            :consumer_consent_ip, :api_response, NOW()
        )";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':lead_id' => $leadId,
            ':first_name' => $formData['firstName'],
            ':last_name' => $formData['lastName'],
            ':mobile_number' => $formData['mobileNumber'],
            ':email' => $formData['email'],
            ':pan' => strtoupper($formData['pan']),
            ':dob' => $formData['dob'],
            ':pincode' => $formData['pincode'],
            ':monthly_income' => $formData['monthlyIncome'],
            ':loan_type' => $formData['loanType'] ?? null,
            ':credit_score_class' => $formData['creditScoreClass'] ?? null,
            ':employment_status' => $formData['employmentStatus'],
            ':employer_name' => $formData['employerName'] ?? null,
            ':office_pincode' => $formData['officePincode'] ?? null,
            ':business_registration_type' => $formData['businessRegistrationType'] ?? null,
            ':residence_type' => $formData['residenceType'] ?? null,
            ':business_turnover' => $formData['businessCurrentTurnover'] ?? null,
            ':business_years' => $formData['businessYears'] ?? null,
            ':business_account' => $formData['businessAccount'] ?? null,
            ':consumer_consent_ip' => getClientIP(),
            ':api_response' => json_encode($apiResponse)
        ]);
        return true;
    } catch (PDOException $e) {
        error_log("Database save failed: " . $e->getMessage());
        return false;
    }
}
/**
 * Make API request
 */
function makeApiRequest($url, $method = 'POST', $data = null) {
    global $apiKey;
    $headers = [
        'apikey: ' . $apiKey,
        'Content-Type: application/json'
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
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
    return [
        'code' => $httpCode,
        'response' => json_decode($response, true),
        'raw_response' => $response,
        'curl_error' => $curlError
    ];
}
/**
 * Get client IP address
 */
function getClientIP() {
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
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                if (!in_array($ip, ['127.0.0.1', '0.0.0.0', '::1'])) {
                    return $ip;
                }
            }
        }
    }
    // Try external IP detection
    $services = ['https://api.ipify.org', 'https://icanhazip.com'];
    foreach ($services as $service) {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $service);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $ip = trim(curl_exec($ch));
            curl_close($ch);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        } catch (Exception $e) {
            continue;
        }
    }
    return '8.8.8.8';
}
/**
 * Create Lead API
 */
function createLead($formData) {
    global $createLeadUrl;
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
        'consumerConsentIp' => getClientIP(),
        'employmentStatus' => (int)$formData['employmentStatus']
    ];
    if (!empty($formData['creditScoreClass'])) {
        $payload['creditScoreClass'] = (int)$formData['creditScoreClass'];
    }
    if ($formData['employmentStatus'] == 1) {
        $payload['employerName'] = $formData['employerName'];
        $payload['officePincode'] = $formData['officePincode'];
    } elseif ($formData['employmentStatus'] == 2) {
        $payload['businessRegistrationType'] = (int)$formData['businessRegistrationType'];
        if ($formData['businessRegistrationType'] != 8) {
            $payload['residenceType'] = (int)$formData['residenceType'];
            $payload['businessCurrentTurnover'] = (int)$formData['businessCurrentTurnover'];
            $payload['businessYears'] = (int)$formData['businessYears'];
            $payload['businessAccount'] = (int)$formData['businessAccount'];
        }
    }
    return makeApiRequest($createLeadUrl, 'POST', $payload);
}
// Handle form submission
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        if (empty($_POST['mobileNumber']) || empty($_POST['firstName']) || empty($_POST['lastName'])) {
            throw new Exception('Please fill all required fields');
        }
        // Validate mobile number
        if (!preg_match('/^[0-9]{10}$/', $_POST['mobileNumber'])) {
            throw new Exception('Mobile number must be exactly 10 digits');
        }
        // Validate PAN
        if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', strtoupper($_POST['pan']))) {
            throw new Exception('Invalid PAN format. Use format: ABCDE1234F');
        }
        // Validate pincode
        if (!preg_match('/^[0-9]{6}$/', $_POST['pincode'])) {
            throw new Exception('Pincode must be exactly 6 digits');
        }
        // Validate employment status
        if (empty($_POST['employmentStatus'])) {
            throw new Exception('Employment status is required');
        }
        // Validate salaried fields
        if ($_POST['employmentStatus'] == 1) {
            if (empty($_POST['employerName'])) {
                throw new Exception('Employer name is required');
            }
            if (!preg_match('/^[0-9]{6}$/', $_POST['officePincode'])) {
                throw new Exception('Valid office pincode is required');
            }
        }
        // Validate self-employed fields
        if ($_POST['employmentStatus'] == 2) {
            if (empty($_POST['businessRegistrationType'])) {
                throw new Exception('Business registration type is required');
            }
            if ($_POST['businessRegistrationType'] != 8) {
                if (empty($_POST['residenceType']) || empty($_POST['businessCurrentTurnover']) ||
                    empty($_POST['businessYears']) || empty($_POST['businessAccount'])) {
                    throw new Exception('All business details are required');
                }
            }
        }
        // Create lead
        $result = createLead($_POST);
        if ($result['response']['success'] === 'true' || $result['response']['success'] === true) {
            $leadId = $result['response']['leadId'];
            // Save to database
            saveLeadToDatabase($_POST, $leadId, $result['response']);
            // Save to session
            $_SESSION['leadId'] = $leadId;
            $_SESSION['customerName'] = $_POST['firstName'] . ' ' . $_POST['lastName'];
            header('Location: offers.php?leadId=' . $leadId);
            exit();
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
    <title>Personal Loan Application - SwitchMyLoan</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
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
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
            padding: 60px 40px;
            color: white;
            position: relative;
        }
        .logo {
            position: absolute;
            top: 40px;
            left: 40px;
        }
        .logo img {
            max-width: 200px;
            height: auto;
        }
        .hero-content {
            text-align: left;
            width: 100%;
        }
        .hero-content h1 {
            font-size: 42px;
            font-weight: 700;
            margin-bottom: 20px;
            line-height: 1.2;
            text-align: left;
        }
        .hero-content p {
            font-size: 18px;
            line-height: 1.6;
            opacity: 0.95;
            text-align: left;
        }
        .hero-image {
            margin-top: 40px;
            text-align: left;
        }
        .hero-image img {
            width: 120px;
            animation: float 3s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }
        .right-panel {
            flex: 1;
            background: #fafafa;
            overflow-y: auto;
        }
        .form-content {
            padding: 60px 80px;
            background: white;
            margin: 0;
            min-height: 100vh;
        }
        .alert {
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            border-left: 4px solid;
        }
        .alert-error {
            background: #fee2e2;
            border-color: #ef4444;
            color: #991b1b;
        }
        .alert-error strong {
            font-weight: 700;
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
            text-align: left;
        }
        .required {
            color: #e91e63;
            margin-left: 2px;
        }
        .form-control {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 15px;
            background: #f9fafb;
            transition: all 0.3s;
            color: #1f2937;
            text-align: left;
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
            text-align: left;
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
            margin-top: 20px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(30, 109, 247, 0.3);
        }
        .btn:active {
            transform: translateY(0);
        }
        .conditional-field {
            display: none;
        }
        .conditional-field.show {
            display: block;
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
            text-align: left;
        }
        .error-message {
            color: #ef4444;
            font-size: 12px;
            margin-top: 5px;
            display: none;
        }
        .form-control.error {
            border-color: #ef4444;
            background: #fee2e2;
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
            .form-content {
                padding: 30px 20px;
            }
            .left-panel {
                padding: 40px 20px;
                align-items: center;
            }
            .hero-content {
                text-align: center;
            }
            .hero-content h1 {
                font-size: 32px;
                text-align: center;
            }
            .hero-content p {
                text-align: center;
            }
            .hero-image {
                text-align: center;
            }
            .logo {
                left: 50%;
                transform: translateX(-50%);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="left-panel">
            <div class="logo">
                <img src="logo.png" alt="Logo">
            </div>
            <div class="hero-content">
                <h1>Take a New Loan</h1>
                <p>Want us to get you the best personal loan from the sea of options available?</p>
                <div class="hero-image">
                    <img src="celebration.png" alt="Celebration">
                </div>
            </div>
        </div>
        <div class="right-panel">
            <div class="form-content">
                <?php if ($error): ?>
                <div class="alert alert-error">
                    <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>
                <form method="POST" id="loanForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label>First Name <span class="required">*</span></label>
                            <input type="text" name="firstName" class="form-control" placeholder="John" required>
                            <span class="helper-text">First name should be same as PAN card</span>
                            <div class="error-message"></div>
                        </div>
                        <div class="form-group">
                            <label>Last Name <span class="required">*</span></label>
                            <input type="text" name="lastName" class="form-control" placeholder="Doe" required>
                            <span class="helper-text">Last name should be same as PAN card</span>
                            <div class="error-message"></div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Mobile No <span class="required">*</span></label>
                            <input type="tel" name="mobileNumber" class="form-control" pattern="[0-9]{10}" placeholder="9999999999" required>
                            <span class="helper-text">Mobile number must be linked to PAN card</span>
                            <div class="error-message"></div>
                        </div>
                        <div class="form-group">
                            <label>Email ID <span class="required">*</span></label>
                            <input type="email" name="email" class="form-control" placeholder="john@example.com" required>
                            <span class="helper-text">Email address must be linked to PAN card</span>
                            <div class="error-message"></div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>PAN No <span class="required">*</span></label>
                            <input type="text" name="pan" class="form-control" pattern="[A-Z]{5}[0-9]{4}[A-Z]{1}" placeholder="ABCEF0000X" style="text-transform: uppercase;" required>
                            <span class="helper-text">Please enter PAN in capital letters <strong>Eg. ABCEF0000X</strong></span>
                            <div class="error-message"></div>
                        </div>
                        <div class="form-group">
                            <label>Date of Birth <span class="required">*</span></label>
                            <input type="date" name="dob" class="form-control" required>
                            <span class="helper-text">Date of Birth should be same as PAN Card</span>
                            <div class="error-message"></div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Profile <span class="required">*</span></label>
                            <select name="employmentStatus" id="employmentStatus" class="form-control" required>
                                <option value="">Select</option>
                                <option value="1">Salaried</option>
                                <option value="2">Self Employed</option>
                            </select>
                            <span class="helper-text">Select your employment profile</span>
                            <div class="error-message"></div>
                        </div>
                        <div class="form-group">
                            <label>Monthly Income <span class="required">*</span></label>
                            <input type="number" name="monthlyIncome" class="form-control" placeholder="50000" required>
                            <span class="helper-text">Monthly income as per payslip</span>
                            <div class="error-message"></div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Pin Code <span class="required">*</span></label>
                            <input type="text" name="pincode" class="form-control" pattern="[0-9]{6}" placeholder="110001" required>
                            <span class="helper-text">Enter Postal code <strong>Eg. 110001</strong></span>
                            <div class="error-message"></div>
                        </div>
                        <div class="form-group">
                            <label>Loan Type</label>
                            <select name="loanType" class="form-control">
                                <option value="">Select</option>
                                <option value="travel">Travel</option>
                                <option value="home_renovation">Home Renovation</option>
                                <option value="medical">Medical</option>
                                <option value="education">Education</option>
                                <option value="wedding">Wedding</option>
                                <option value="other">Other</option>
                            </select>
                            <span class="helper-text">Select the purpose of the loan</span>
                        </div>
                    </div>
                    <!-- Salaried Fields -->
                    <div id="salariedFields" class="conditional-field">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Employer Name <span class="required">*</span></label>
                                <input type="text" name="employerName" class="form-control" placeholder="Company Name">
                                <span class="helper-text">Enter your current employer name</span>
                                <div class="error-message"></div>
                            </div>
                            <div class="form-group">
                                <label>Office Pincode <span class="required">*</span></label>
                                <input type="text" name="officePincode" class="form-control" pattern="[0-9]{6}" placeholder="110001">
                                <span class="helper-text">Office location pincode (6 digits)</span>
                                <div class="error-message"></div>
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
                                <option value="3">Municipal Corporation</option>
                                <option value="4">Palika Gramapanchayat</option>
                                <option value="5">Udyog Aadhar</option>
                                <option value="6">Drugs License</option>
                                <option value="7">Other</option>
                                <option value="8">No Business Proof</option>
                            </select>
                            <span class="helper-text">Select your business registration type</span>
                            <div class="error-message"></div>
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
                                    <div class="error-message"></div>
                                </div>
                                <div class="form-group">
                                    <label>Business Turnover <span class="required">*</span></label>
                                    <select name="businessCurrentTurnover" class="form-control">
                                        <option value="">Select</option>
                                        <option value="1">Up to 6 lacs</option>
                                        <option value="2">6-12 lacs</option>
                                        <option value="3">12-20 lacs</option>
                                        <option value="4">Above 20 lacs</option>
                                    </select>
                                    <span class="helper-text">Annual business turnover</span>
                                    <div class="error-message"></div>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Years in Business <span class="required">*</span></label>
                                    <select name="businessYears" class="form-control">
                                        <option value="">Select</option>
                                        <option value="1">Less than 1 year</option>
                                        <option value="2">1-2 years</option>
                                        <option value="3">More than 2 years</option>
                                    </select>
                                    <span class="helper-text">Years of business operation</span>
                                    <div class="error-message"></div>
                                </div>
                                <div class="form-group">
                                    <label>Business Current Account? <span class="required">*</span></label>
                                    <select name="businessAccount" class="form-control">
                                        <option value="">Select</option>
                                        <option value="1">Yes</option>
                                        <option value="2">No</option>
                                    </select>
                                    <span class="helper-text">Do you have a business current account?</span>
                                    <div class="error-message"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" id="consent" required>
                        <label for="consent">
                            You hereby consent to SwitchMyLoan being appointed as your authorised representative to receive your Credit Information from Experian/CIBIL/EQUIFAX/CRIF for the purpose of Credit Assessment of the End User to Advise him on the best loan offers (End Use Purpose) or expiry of 6 months from the date the consent is collected; whichever is earlier.
                        </label>
                    </div>
                    <button type="submit" class="btn">Submit Application</button>
                </form>
            </div>
        </div>
    </div>
    <script>
        // Employment status change handler
        const employmentStatus = document.getElementById('employmentStatus');
        if (employmentStatus) {
            employmentStatus.addEventListener('change', function() {
                document.getElementById('salariedFields').classList.remove('show');
                document.getElementById('selfEmployedFields').classList.remove('show');
                if (this.value == '1') {
                    document.getElementById('salariedFields').classList.add('show');
                } else if (this.value == '2') {
                    document.getElementById('selfEmployedFields').classList.add('show');
                }
            });
        }
        // Business registration type change handler
        const businessReg = document.getElementById('businessRegistrationType');
        if (businessReg) {
            businessReg.addEventListener('change', function() {
                const details = document.getElementById('businessDetailsFields');
                if (this.value != '8' && this.value != '') {
                    details.classList.add('show');
                } else {
                    details.classList.remove('show');
                }
            });
        }
        // PAN uppercase converter
        const panInput = document.querySelector('input[name="pan"]');
        if (panInput) {
            panInput.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        }

        // Function to validate all fields
        function validateForm() {
            let isValid = true;
            const errorMessages = document.querySelectorAll('.error-message');

            // Clear previous error messages
            errorMessages.forEach(msg => msg.style.display = 'none');
            document.querySelectorAll('.form-control').forEach(input => input.classList.remove('error'));

            // Validate First Name
            const firstName = document.querySelector('input[name="firstName"]');
            if (!firstName.value.trim()) {
                showError(firstName, 'First name is required');
                isValid = false;
            }

            // Validate Last Name
            const lastName = document.querySelector('input[name="lastName"]');
            if (!lastName.value.trim()) {
                showError(lastName, 'Last name is required');
                isValid = false;
            }

            // Validate Mobile Number
            const mobileNumber = document.querySelector('input[name="mobileNumber"]');
            if (!mobileNumber.value.trim() || !/^[0-9]{10}$/.test(mobileNumber.value)) {
                showError(mobileNumber, 'Mobile number must be 10 digits');
                isValid = false;
            }

            // Validate Email
            const email = document.querySelector('input[name="email"]');
            if (!email.value.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
                showError(email, 'Valid email is required');
                isValid = false;
            }

            // Validate PAN
            const pan = document.querySelector('input[name="pan"]');
            if (!pan.value.trim() || !/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/.test(pan.value)) {
                showError(pan, 'Invalid PAN format. Use format: ABCDE1234F');
                isValid = false;
            }

            // Validate DOB (18+ years)
            const dob = document.querySelector('input[name="dob"]');
            if (!dob.value) {
                showError(dob, 'Date of birth is required');
                isValid = false;
            } else {
                const dobDate = new Date(dob.value);
                const today = new Date();
                const minAgeDate = new Date(today.getFullYear() - 18, today.getMonth(), today.getDate());
                if (dobDate > minAgeDate) {
                    showError(dob, 'You must be at least 18 years old');
                    isValid = false;
                }
            }

            // Validate Pincode
            const pincode = document.querySelector('input[name="pincode"]');
            if (!pincode.value.trim() || !/^[0-9]{6}$/.test(pincode.value)) {
                showError(pincode, 'Pincode must be 6 digits');
                isValid = false;
            }

            // Validate Employment Status
            const employmentStatus = document.querySelector('select[name="employmentStatus"]');
            if (!employmentStatus.value) {
                showError(employmentStatus, 'Employment status is required');
                isValid = false;
            }

            // Validate Monthly Income
            const monthlyIncome = document.querySelector('input[name="monthlyIncome"]');
            if (!monthlyIncome.value.trim() || isNaN(monthlyIncome.value) || monthlyIncome.value <= 0) {
                showError(monthlyIncome, 'Valid monthly income is required');
                isValid = false;
            }

            // Validate Salaried Fields (if applicable)
            if (employmentStatus.value === '1') {
                const employerName = document.querySelector('input[name="employerName"]');
                const officePincode = document.querySelector('input[name="officePincode"]');

                if (!employerName.value.trim()) {
                    showError(employerName, 'Employer name is required');
                    isValid = false;
                }

                if (!officePincode.value.trim() || !/^[0-9]{6}$/.test(officePincode.value)) {
                    showError(officePincode, 'Office pincode must be 6 digits');
                    isValid = false;
                }
            }

            // Validate Self-Employed Fields (if applicable)
            if (employmentStatus.value === '2') {
                const businessRegType = document.querySelector('select[name="businessRegistrationType"]');
                if (!businessRegType.value) {
                    showError(businessRegType, 'Business registration type is required');
                    isValid = false;
                }

                if (businessRegType.value !== '8') {
                    const residenceType = document.querySelector('select[name="residenceType"]');
                    const businessTurnover = document.querySelector('select[name="businessCurrentTurnover"]');
                    const businessYears = document.querySelector('select[name="businessYears"]');
                    const businessAccount = document.querySelector('select[name="businessAccount"]');

                    if (!residenceType.value) {
                        showError(residenceType, 'Residence type is required');
                        isValid = false;
                    }

                    if (!businessTurnover.value) {
                        showError(businessTurnover, 'Business turnover is required');
                        isValid = false;
                    }

                    if (!businessYears.value) {
                        showError(businessYears, 'Years in business is required');
                        isValid = false;
                    }

                    if (!businessAccount.value) {
                        showError(businessAccount, 'Business account status is required');
                        isValid = false;
                    }
                }
            }

            return isValid;
        }

        // Helper function to display error messages
        function showError(input, message) {
            const errorMessage = input.parentNode.querySelector('.error-message');
            errorMessage.textContent = message;
            input.classList.add('error');
            errorMessage.style.display = 'block';
        }

        // Set max date for DOB to 18 years ago
        const dobInput = document.querySelector('input[name="dob"]');
        if (dobInput) {
            const today = new Date();
            const maxDate = new Date(today.getFullYear() - 18, today.getMonth(), today.getDate()).toISOString().split('T')[0];
            dobInput.setAttribute('max', maxDate);
        }

        // Attach validation to form submission
        const loanForm = document.getElementById('loanForm');
        if (loanForm) {
            loanForm.addEventListener('submit', function(event) {
                if (!validateForm()) {
                    event.preventDefault();
                }
            });
        }
    </script>
</body>
</html>
