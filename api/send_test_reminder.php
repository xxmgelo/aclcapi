<?php
// Temporary test endpoint to send a canned reminder using the updated reminder UI.
require __DIR__ . "/cors.php";
require __DIR__ . "/db.php";
require __DIR__ . "/helpers.php";

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

$mail_config_file = __DIR__ . "/mail_config.php";
if (!file_exists($mail_config_file)) {
    http_response_code(500);
    echo json_encode(["error" => "Missing mail_config.php"]); exit;
}

$mailConfig = require $mail_config_file;
$phpMailerBase = __DIR__ . "/phpmailer/src";
require_once $phpMailerBase . "/Exception.php";
require_once $phpMailerBase . "/PHPMailer.php";
require_once $phpMailerBase . "/SMTP.php";

// Read optional ?to= parameter
$to = trim((string)($_GET['to'] ?? ''));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["error" => "Provide recipient via ?to=you@example.com"]);
    exit;
}

// Build canned data
$student_name = 'Test Student';
$student_id = 'TST-0001';
$financials = ['total_balance' => 960.00];
$message = "If payment has already been made, please disregard this message and coordinate with the accounting office for verification.";

function build_reminder_html_for_test($student_name, $student_id, $message, $financials)
{
    // reuse the existing function from reminders.php by copying the same HTML structure
    $paragraphs = array_filter(array_map("trim", preg_split("/\r\n|\r|\n/", $message)));
    $htmlParagraphs = array_map(function ($line) {
        return "<p style=\"margin:0 0 12px;\">" . nl2br(htmlspecialchars($line, ENT_QUOTES, "UTF-8")) . "</p>";
    }, $paragraphs);

    return "
        <div style=\"font-family:Arial,Helvetica,sans-serif;background:#f7fafc;padding:24px;\">
            <div style=\"max-width:640px;margin:0 auto;background:#ffffff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;\">
                <div style=\"padding:24px;background:linear-gradient(135deg,#155EEF,#0F766E);color:#ffffff;text-align:center;\">
                    <img src=\"aclclogo.png\" alt=\"ACLC\" style=\"height:40px;margin:0 auto 8px;display:block;\" />
                    <h2 style=\"margin:0 0 8px;\">Payment Reminder</h2>
                    <p style=\"margin:0;font-size:14px;opacity:0.92;\">ACLC Fee Management System</p>
                </div>
                <div style=\"padding:24px;color:#0f172a;\">
                    <div style=\"margin:0 0 18px;padding:14px;border-radius:10px;background:#eef6ff;border:1px solid #bfd9ff;\">
                        <p style=\"margin:0 0 8px;\"><strong>Student Name:</strong> " . htmlspecialchars($student_name, ENT_QUOTES, "UTF-8") . "</p>
                        <p style=\"margin:0 0 8px;\"><strong>Student ID:</strong> " . htmlspecialchars($student_id, ENT_QUOTES, "UTF-8") . "</p>
                        <p style=\"margin:0;\"><strong>Outstanding Balance:</strong> PHP " . number_format((float)$financials["total_balance"], 2) . "</p>
                    </div>
                    " . implode("", $htmlParagraphs) . "
                    <p style=\"margin:0 0 12px;\">Thank you.</p>
                </div>
                <div style=\"padding:18px 24px 28px;color:#6b7280;text-align:center;border-top:1px solid #e6eef8;\">
                    <img src=\"receivednotif.png\" alt=\"ACLC\" style=\"height:28px;margin:0 auto 8px;display:block;\" />
                    <strong style=\"display:block;color:#0f172a;margin-bottom:6px;\">ACLC Fee Management System</strong>
                    <div style=\"font-size:12px;opacity:0.8;\">This is a system-generated notification. For payment verification, please contact the accounting office.</div>
                </div>
            </div>
        </div>
    ";
}

$html = build_reminder_html_for_test($student_name, $student_id, $message, $financials);

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->CharSet = "UTF-8";
    $mail->Encoding = "base64";
    $mail->Host = $mailConfig['host'] ?? 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = trim((string)($mailConfig['username'] ?? ''));
    $mail->Password = preg_replace('/\s+/', '', (string)($mailConfig['app_password'] ?? ''));
    $mail->Port = (int)($mailConfig['port'] ?? 587);
    $encryption = strtolower((string)($mailConfig['encryption'] ?? 'tls'));
    if ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }

    $fromEmail = trim((string)($mailConfig['from_email'] ?? $mail->Username));
    $fromName = trim((string)($mailConfig['from_name'] ?? 'ACLC Fee Management System'));
    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($to, $student_name);
    $mail->isHTML(true);
    $mail->Subject = 'Test: Payment Reminder - ACLC Fee Management System';

    // inline assets
    $asset_dir = __DIR__ . "/email_assets";
    $assets = ['aclclogo.png', 'remindernotif.png', 'receivednotif.png'];
    foreach ($assets as $fn) {
        $p = $asset_dir . '/' . $fn;
        if (file_exists($p)) {
            $bin = file_get_contents($p);
            $mime = 'image/png';
            $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
            if ($ext === 'jpg' || $ext === 'jpeg') $mime = 'image/jpeg';
            elseif ($ext === 'gif') $mime = 'image/gif';
            $cid = preg_replace('/[^a-z0-9_.-]/i', '_', $fn);
            $mail->addStringEmbeddedImage($bin, $cid, $fn, 'base64', $mime);
            $html = preg_replace('/src=("|\')' . preg_quote($fn, '/') . '[^"\']*("|\')/i', 'src="cid:' . $cid . '"', $html);
        }
    }

    $mail->Body = $html;
    $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
    $mail->send();

    echo json_encode(["sent" => true, "recipient" => $to]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "send failed", "details" => $e->getMessage()]);
}
