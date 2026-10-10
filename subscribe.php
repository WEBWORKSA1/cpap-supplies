<?php
// CPAP.Supplies Email Subscription Handler
// Deploy to: public_html/subscribe.php on Hostinger

// Config
$admin_email = 'admin@cpap.supplies';
$from_email = 'noreply@cpap.supplies';
$site_name = 'CPAP.Supplies';
$subscriber_file = __DIR__ . '/subscribers/subscribers.csv';
$rate_limit_dir = __DIR__ . '/subscribers/rate_limits/';

// CORS headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://cpap.supplies');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Rate limiting (5 per hour per IP)
$ip = $_SERVER['REMOTE_ADDR'];
$rate_file = $rate_limit_dir . md5($ip) . '.txt';
if (!is_dir($rate_limit_dir)) mkdir($rate_limit_dir, 0755, true);

if (file_exists($rate_file)) {
    $data = json_decode(file_get_contents($rate_file), true);
    if ($data['hour'] === date('YmdH') && $data['count'] >= 5) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again later.']);
        exit;
    }
    if ($data['hour'] !== date('YmdH')) {
        $data = ['hour' => date('YmdH'), 'count' => 0];
    }
} else {
    $data = ['hour' => date('YmdH'), 'count' => 0];
}
$data['count']++;
file_put_contents($rate_file, json_encode($data));

// Get and validate email
$input = json_decode(file_get_contents('php://input'), true);
$email = isset($input['email']) ? trim(strtolower($input['email'])) : '';
$source = isset($input['source']) ? trim($input['source']) : 'website';

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

// Sanitize source (allow only alphanumeric, hyphens, underscores)
$source = preg_replace('/[^a-zA-Z0-9_-]/', '', substr($source, 0, 50));

// Create subscribers directory if needed
$dir = dirname($subscriber_file);
if (!is_dir($dir)) mkdir($dir, 0755, true);

// Check for duplicates
$is_duplicate = false;
if (file_exists($subscriber_file)) {
    $handle = fopen($subscriber_file, 'r');
    if ($handle) {
        while (($row = fgetcsv($handle)) !== false) {
            if (isset($row[0]) && strtolower(trim($row[0])) === $email) {
                $is_duplicate = true;
                break;
            }
        }
        fclose($handle);
    }
}

if ($is_duplicate) {
    echo json_encode(['success' => true, 'duplicate' => true, 'message' => "You're already subscribed! Check your inbox for our latest guides."]);
    exit;
}

// Save subscriber
$handle = fopen($subscriber_file, 'a');
if (!$handle) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again later.']);
    exit;
}
fputcsv($handle, [$email, date('Y-m-d H:i:s'), $source, $ip]);
fclose($handle);

// Send welcome email
$welcome_subject = "Welcome to CPAP.Supplies!";
$welcome_body = "Hi there!\n\nWelcome to CPAP.Supplies — your trusted source for CPAP guides, product reviews, and supply reminders.\n\nHere's what you can expect:\n- Expert CPAP troubleshooting guides\n- Honest product reviews and recommendations\n- Timely reminders to replace your CPAP supplies\n- Exclusive tips to improve your sleep\n\nStart here:\n-> Why Is My CPAP Mask Leaking? https://cpap.supplies/blog/cpap-mask-leaking\n-> CPAP Supply Replacement Calculator: https://cpap.supplies/#calculator\n\nSleep well,\nThe CPAP.Supplies Team\n\n---\nYou're receiving this because you signed up at cpap.supplies.\nTo unsubscribe, reply with 'unsubscribe'.";

$welcome_headers = "From: $site_name <$from_email>\r\nReply-To: $admin_email\r\nContent-Type: text/plain; charset=UTF-8";
@mail($email, $welcome_subject, $welcome_body, $welcome_headers);

// Notify admin
$admin_subject = "New Subscriber: $email";
$admin_body = "New subscriber!\n\nEmail: $email\nSource: $source\nDate: " . date('Y-m-d H:i:s') . "\nIP: $ip";
$admin_headers = "From: $site_name <$from_email>\r\nReply-To: $from_email";
@mail($admin_email, $admin_subject, $admin_body, $admin_headers);

echo json_encode(['success' => true, 'message' => "You're in! Check your inbox for a welcome email with our best CPAP guides."]);
