<?php
// ---- BASIC SETUP ---- //
function loadEnv($path) {
    if (!file_exists($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv("$name=$value");
        $_ENV[$name] = $value;
    }
}
loadEnv(__DIR__ . '/.env');

if (session_status() === PHP_SESSION_NONE) session_start();

$env = getenv('ENVIRONMENT') ?: 'UAT';
$apiKey = getenv('API_KEY');
$getOffersUrl = $env === 'PROD' ? getenv('PROD_GET_OFFERS_URL') : getenv('UAT_GET_OFFERS_URL');

// ---- API CALL ---- //
function makeApiRequest($url) {
    global $apiKey;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['apikey: ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'response' => json_decode($response, true)];
}

$leadId = $_GET['leadId'] ?? $_SESSION['leadId'] ?? null;
$customerName = $_SESSION['customerName'] ?? 'Customer';
if (!$leadId) header('Location: index.php');

$result = makeApiRequest($getOffersUrl . $leadId);
if ($result['code'] === 200 && ($result['response']['success'] ?? false)) {
    $offers = $result['response']['offers'] ?? [];
} else {
    $error = $result['response']['message'] ?? 'Failed to fetch offers';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your Loan Offers</title>
<style>
    :root {
        --primary: #4f46e5;
        --secondary: #6366f1;
        --text-dark: #1f2937;
        --text-light: #6b7280;
        --bg-light: #f9fafb;
        --success: #10b981;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: 'Inter', sans-serif;
        background: var(--bg-light);
        color: var(--text-dark);
        min-height: 100vh;
    }

    header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 2rem;
        background: white;
        box-shadow: 0 1px 6px rgba(0,0,0,0.1);
        position: sticky;
        top: 0;
        z-index: 10;
    }
    .logo {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .logo img {
        height: 40px;
    }
    .back-btn {
        text-decoration: none;
        color: white;
        background: var(--primary);
        padding: 0.6rem 1.2rem;
        border-radius: 8px;
        font-weight: 500;
        transition: 0.3s;
    }
    .back-btn:hover {
        background: var(--secondary);
    }
    .back-btn .back-text {
        display: inline;
    }

    main {
        max-width: 1400px;
        margin: 2rem auto;
        padding: 0 1rem;
    }
    .title {
        text-align: center;
        margin-bottom: 2rem;
    }
    .title h1 {
        font-size: 1.8rem;
        font-weight: 700;
    }
    .success-card {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        text-align: center;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        margin-bottom: 1.5rem;
    }
    .success-card h2 {
        color: var(--success);
        margin-bottom: 0.5rem;
    }
    .lead-info {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 1rem;
        margin-bottom: 2rem;
    }
    .lead-box {
        background: white;
        border-radius: 10px;
        padding: 1rem 1.5rem;
        text-align: center;
        min-width: 150px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.1);
    }
    .lead-box span {
        display: block;
        font-size: 0.8rem;
        color: var(--text-light);
        margin-bottom: 5px;
    }
    .lead-box strong {
        font-size: 1rem;
    }

    .offers {
        display: grid;
        gap: 1.5rem;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    }
    .offer-card {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 1px 8px rgba(0,0,0,0.1);
        transition: transform 0.3s;
    }
    .offer-card:hover {
        transform: translateY(-4px);
    }
    .offer-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }
    .lender-logo {
        height: 40px;
        max-width: 120px;
        object-fit: contain;
    }
    .status {
        padding: 4px 10px;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    .status-approved { background: #dcfce7; color: #166534; }
    .status-pending { background: #fef9c3; color: #854d0e; }
    .status-started { background: #dbeafe; color: #1e3a8a; }

    .offer-details {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.8rem;
    }
    .detail {
        background: var(--bg-light);
        border-radius: 8px;
        padding: 0.8rem;
    }
    .detail span {
        font-size: 0.75rem;
        color: var(--text-light);
        display: block;
    }
    .detail strong {
        font-size: 1rem;
    }
    .offer-link {
        display: block;
        text-align: center;
        background: var(--primary);
        color: white;
        text-decoration: none;
        padding: 0.8rem;
        border-radius: 8px;
        margin-top: 1rem;
        font-weight: 600;
    }
    .offer-link:hover {
        background: var(--secondary);
    }

    .no-offers {
        text-align: center;
        background: white;
        padding: 2rem;
        border-radius: 12px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }
    .no-offers h3 { margin-bottom: 0.5rem; }

    @media (max-width: 600px) {
        .offer-details { grid-template-columns: 1fr; }
        .title h1 { font-size: 1.4rem; }
        header {
            padding: 1rem;
        }
        .logo img {
            height: 32px;
        }
        .back-btn {
            padding: 0.6rem;
            min-width: 40px;
            text-align: center;
        }
        .back-btn .back-text {
            display: none;
        }
    }
</style>
</head>
<body>
<header>
    <div class="logo">
        <img src="https://creditlinks.in/static/logo.svg" alt="SwitchMyLoan Logo">
    </div>
    <a href="index.php" class="back-btn">← <span class="back-text">Back to Application</span></a>
</header>

<main>
    <div class="title">
        <h1>🎉 Your Loan Offers</h1>
        <p>Hi <strong><?= htmlspecialchars($customerName) ?></strong>, here are your personalized offers</p>
    </div>

    <div class="success-card">
        <h2>✅ Application Successful</h2>
        <p>Your application has been processed successfully!</p>
    </div>

    <div class="lead-info">
        <div class="lead-box"><span>Lead ID</span><strong><?= htmlspecialchars(substr($leadId, 0, 10)) ?>...</strong></div>
        <div class="lead-box"><span>Total Offers</span><strong><?= count($offers ?? []) ?></strong></div>
        <div class="lead-box"><span>Status</span><strong style="color:var(--success)">Active</strong></div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="no-offers">
            <h3>❌ <?= htmlspecialchars($error) ?></h3>
        </div>
    <?php elseif (!empty($offers)): ?>
        <div class="offers">
            <?php foreach ($offers as $offer): 
                $status = strtolower($offer['status'] ?? 'pending');
                $statusClass = strpos($status,'approved')!==false?'status-approved':(strpos($status,'started')!==false?'status-started':'status-pending');
            ?>
            <div class="offer-card">
                <div class="offer-header">
                    <?php if (!empty($offer['lenderLogo'])): ?>
                        <img src="<?= htmlspecialchars($offer['lenderLogo']) ?>" alt="Lender Logo" class="lender-logo">
                    <?php endif; ?>
                    <div class="status <?= $statusClass ?>"><?= htmlspecialchars($offer['status'] ?? 'Pending') ?></div>
                </div>
                <h3><?= htmlspecialchars($offer['lenderName'] ?? '') ?></h3>
                <div class="offer-details">
                    <?php if(!empty($offer['offerAmountUpTo'])): ?>
                        <div class="detail"><span>Amount</span><strong>₹<?= number_format($offer['offerAmountUpTo']) ?></strong></div>
                    <?php endif; ?>
                    <?php if(!empty($offer['offerTenure'])): ?>
                        <div class="detail"><span>Tenure</span><strong><?= htmlspecialchars($offer['offerTenure']) ?></strong></div>
                    <?php endif; ?>
                    <?php if(!empty($offer['offerInterestRate'])): ?>
                        <div class="detail"><span>Interest</span><strong><?= htmlspecialchars($offer['offerInterestRate']) ?></strong></div>
                    <?php endif; ?>
                    <?php if(!empty($offer['offerProcessingFees'])): ?>
                        <div class="detail"><span>Fees</span><strong><?= htmlspecialchars($offer['offerProcessingFees']) ?></strong></div>
                    <?php endif; ?>
                </div>
                <?php if(!empty($offer['offerLink'])): ?>
                    <a href="<?= htmlspecialchars($offer['offerLink']) ?>" class="offer-link" target="_blank">Continue Application →</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="no-offers">
            <h3>No Offers Found</h3>
            <p>We couldn't find any active offers right now. Please check again later.</p>
            <a href="index.php" class="offer-link" style="display:inline-block;margin-top:1rem;">Apply Again</a>
        </div>
    <?php endif; ?>
</main>
</body>
</html>