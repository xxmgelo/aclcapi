<?php

require __DIR__ . "/cors.php";
require __DIR__ . "/db.php";
require __DIR__ . "/helpers.php";

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

$method = $_SERVER["REQUEST_METHOD"];
$db = db();

if ($method !== "POST") {
    respond(["error" => "Method not allowed"], 405);
}

$data = read_json();
if (!$data) {
    respond(["error" => "Payload required"], 400);
}

$student_name = trim((string)($data["Name"] ?? ""));
$student_id = trim((string)($data["StudentID"] ?? ""));
$gmail = trim((string)($data["Gmail"] ?? ""));
$official_receipt = trim((string)($data["official_receipt"] ?? ""));
$subject = trim((string)($data["subject"] ?? ""));
$message = trim((string)($data["message"] ?? ""));
$html = trim((string)($data["html"] ?? ""));
$payment_mode = strtolower(trim((string)($data["PaymentMode"] ?? "installment")));
$payment_mode_label = $payment_mode === "full" ? "Full Payment" : "Installment";
$amount_applied = parse_money($data["amount_applied"] ?? 0);
$amount_requested = parse_money($data["amount_requested"] ?? $amount_applied);
$financials = normalized_student_financials($data);
$program_hint = trim((string)($data["Program"] ?? ""));
$outstanding_after = parse_money($data["outstanding_after"] ?? $financials["total_balance"]);
$outstanding_before = parse_money($data["outstanding_before"] ?? ($outstanding_after + $amount_applied));
$stage_label = trim((string)($data["stage_label"] ?? ""));
$stage_amount_paid = parse_money($data["stage_amount_paid"] ?? $amount_applied);
$stage_amount_remaining = parse_money($data["stage_amount_remaining"] ?? $outstanding_after);
$resolved_stage = $stage_label !== "" ? $stage_label : $payment_mode_label;
$stage_guidance = $stage_amount_remaining <= 0
    ? "This payment completes the required amount for this stage."
    : "Please ensure that the remaining balance is settled within the required period.";

$resolved_student = find_student_for_mail_receipt($db, $student_id, $program_hint);
if ($resolved_student) {
    $student_name = trim((string)($resolved_student["name"] ?? "")) ?: $student_name;
    $gmail = trim((string)($resolved_student["gmail"] ?? "")) ?: $gmail;
}

if ($student_name === "" || $student_id === "") {
    respond(["error" => "Student name and ID are required"], 422);
}

if ($gmail === "" || !filter_var($gmail, FILTER_VALIDATE_EMAIL)) {
    respond(["error" => "A valid Gmail address is required"], 422);
}

$students_using_same_gmail = find_students_by_gmail_receipt($db, $gmail);
if (count($students_using_same_gmail) > 1) {
    $preview = array_slice($students_using_same_gmail, 0, 4);
    $details = implode(", ", array_map(function ($row) {
        return trim((string)$row["name"]) . " (" . trim((string)$row["student_id"]) . ")";
    }, $preview));

    respond([
        "error" => "This Gmail is linked to multiple student records. Update student Gmail entries before sending receipts.",
        "details" => $details,
    ], 422);
}

if ($amount_applied <= 0) {
    respond(["error" => "A valid payment amount is required"], 422);
}

if ($subject === "") {
    $subject = "Payment Confirmation - ACLC Fee Management System";
}

if ($message === "") {
    $message = "Good day,\n\n" .
        "This is to confirm that your recent tuition fee payment has been successfully recorded.\n\n" .
        "Payment Details:\n" .
        "Stage: {$resolved_stage}\n" .
        "Amount Paid: PHP " . number_format((float)$stage_amount_paid, 2) . "\n" .
        "Remaining Balance: PHP " . number_format((float)$stage_amount_remaining, 2) . "\n\n" .
        "Your payment has been applied to the specified installment stage.\n\n" .
        "{$stage_guidance}\n\n" .
        "If you have any questions or concerns regarding your account, please contact the accounting office.\n\n" .
        "Thank you.\nACLC Fee Management System";
}

$mail_config_file = __DIR__ . "/mail_config.php";
if (!file_exists($mail_config_file)) {
    respond([
        "error" => "Missing mail configuration. Create api/mail_config.php from api/mail_config.example.php first."
    ], 500);
}

$mailConfig = require $mail_config_file;

$phpMailerBase = __DIR__ . "/phpmailer/src";
$phpMailerFiles = [
    $phpMailerBase . "/Exception.php",
    $phpMailerBase . "/PHPMailer.php",
    $phpMailerBase . "/SMTP.php",
];

foreach ($phpMailerFiles as $file) {
    if (!file_exists($file)) {
        respond([
            "error" => "PHPMailer is missing. Download PHPMailer and place its src folder in api/phpmailer/src."
        ], 500);
    }
}

require_once $phpMailerBase . "/Exception.php";
require_once $phpMailerBase . "/PHPMailer.php";
require_once $phpMailerBase . "/SMTP.php";

function value_or_default_receipt($array, $key, $default = "")
{
    return isset($array[$key]) ? $array[$key] : $default;
}

function find_student_for_mail_receipt($db, $student_id, $program_hint = "")
{
    $stmt = $db->prepare(
        "SELECT student_id, name, program, gmail FROM bse_students WHERE student_id = ?
         UNION ALL
         SELECT student_id, name, program, gmail FROM bsis_students WHERE student_id = ?"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("ss", $student_id, $student_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    if (count($rows) === 0) {
        return null;
    }

    if ($program_hint !== "") {
        $normalized_hint = strtolower($program_hint);
        foreach ($rows as $row) {
            if (strpos(strtolower((string)$row["program"]), $normalized_hint) !== false) {
                return $row;
            }
        }
    }

    return $rows[0];
}

function find_students_by_gmail_receipt($db, $gmail)
{
    $stmt = $db->prepare(
        "SELECT student_id, name, program, gmail FROM bse_students WHERE LOWER(TRIM(gmail)) = LOWER(TRIM(?))
         UNION ALL
         SELECT student_id, name, program, gmail FROM bsis_students WHERE LOWER(TRIM(gmail)) = LOWER(TRIM(?))"
    );

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("ss", $gmail, $gmail);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function build_payment_receipt_html(
    $student_name,
    $student_id,
    $stage_label,
    $stage_amount_paid,
    $stage_amount_remaining,
    $stage_guidance
) {
    return "
        <div style=\"font-family:Arial,Helvetica,sans-serif;background:#f7fafc;padding:24px;\">
            <div style=\"max-width:640px;margin:0 auto;background:#ffffff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;\">
                <div style=\"padding:24px;background:linear-gradient(135deg,#007877,#155EEF);color:#ffffff;\">
                    <h2 style=\"margin:0 0 8px;\">Payment Received</h2>
                    <p style=\"margin:0;font-size:14px;opacity:0.92;\">ACLC Fee Management System</p>
                </div>
                <div style=\"padding:24px;color:#0f172a;\">
                    <p style=\"margin:0 0 16px;\"><strong>Student Name:</strong> " . htmlspecialchars($student_name, ENT_QUOTES, "UTF-8") . "</p>
                    <p style=\"margin:0 0 16px;\"><strong>Student ID:</strong> " . htmlspecialchars($student_id, ENT_QUOTES, "UTF-8") . "</p>
                    <div style=\"margin:0 0 18px;padding:14px;border-radius:10px;background:#eef6ff;border:1px solid #bfd9ff;\">
                        <p style=\"margin:0 0 8px;\"><strong>Stage:</strong> " . htmlspecialchars($stage_label, ENT_QUOTES, "UTF-8") . "</p>
                        <p style=\"margin:0 0 8px;\"><strong>Amount Paid:</strong> PHP " . number_format((float)$stage_amount_paid, 2) . "</p>
                        <p style=\"margin:0;\"><strong>Remaining Balance:</strong> PHP " . number_format((float)$stage_amount_remaining, 2) . "</p>
                    </div>
                    <p style=\"margin:0 0 12px;\">Good day,</p>
                    <p style=\"margin:0 0 12px;\">This is to confirm that your recent tuition fee payment has been successfully recorded.</p>
                    <p style=\"margin:0 0 12px;\">Your payment has been applied to the specified installment stage.</p>
                    <p style=\"margin:0 0 12px;\">" . htmlspecialchars($stage_guidance, ENT_QUOTES, "UTF-8") . "</p>
                    <p style=\"margin:0 0 12px;\">If you have any questions or concerns regarding your account, please contact the accounting office.</p>
                    <p style=\"margin:0;\">Thank you.<br>ACLC Fee Management System</p>
                </div>
            </div>
        </div>
    ";
}

function inline_notification_assets_receipt($html)
{
    $asset_dir = __DIR__ . "/email_assets";
    $assets = ["aclclogo.png", "remindernotif.png", "receivednotif.png"];

    foreach ($assets as $file_name) {
        if (stripos($html, $file_name) === false) {
            continue;
        }

        $path = $asset_dir . "/" . $file_name;
        if (!file_exists($path)) {
            continue;
        }

        $binary = @file_get_contents($path);
        if ($binary === false) {
            continue;
        }

        $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $mime = "image/png";
        if ($extension === "jpg" || $extension === "jpeg") {
            $mime = "image/jpeg";
        } elseif ($extension === "gif") {
            $mime = "image/gif";
        } elseif ($extension === "webp") {
            $mime = "image/webp";
        }

        $data_uri = "data:" . $mime . ";base64," . base64_encode($binary);
        $pattern = '/src=(["\'])[^"\']*' . preg_quote($file_name, '/') . '[^"\']*\1/i';
        $html = preg_replace($pattern, 'src="' . $data_uri . '"', $html);
    }

    return $html;
}

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->CharSet = "UTF-8";
    $mail->Encoding = "base64";
    $mail->Host = value_or_default_receipt($mailConfig, "host", "smtp.gmail.com");
    $mail->SMTPAuth = true;
    $mail->Username = trim((string)value_or_default_receipt($mailConfig, "username"));
    $mail->Password = preg_replace('/\s+/', '', (string)value_or_default_receipt($mailConfig, "app_password"));
    $mail->Port = (int)value_or_default_receipt($mailConfig, "port", 587);

    $encryption = strtolower((string)value_or_default_receipt($mailConfig, "encryption", "tls"));
    if ($encryption === "ssl") {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }

    $fromEmail = trim((string)value_or_default_receipt($mailConfig, "from_email", $mail->Username));
    $fromName = trim((string)value_or_default_receipt($mailConfig, "from_name", "ACLC Fee Management System"));

    if ($mail->Username === "" || $mail->Password === "" || $fromEmail === "") {
        respond(["error" => "Mail configuration is incomplete. Fill in api/mail_config.php first."], 500);
    }

    $mail->setFrom($fromEmail, $fromName);

    $replyToEmail = value_or_default_receipt($mailConfig, "reply_to_email");
    if ($replyToEmail !== "") {
        $mail->addReplyTo(
            $replyToEmail,
            value_or_default_receipt($mailConfig, "reply_to_name", $fromName)
        );
    }

    $mail->addAddress($gmail, $student_name);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $resolved_html = $html !== ""
        ? $html
        : build_payment_receipt_html(
            $student_name,
            $student_id,
            $resolved_stage,
            $stage_amount_paid,
            $stage_amount_remaining,
            $stage_guidance
        );
    $mail->Body = inline_notification_assets_receipt($resolved_html);
    $mail->AltBody = $message;

    $mail->send();

    respond([
        "sent" => true,
        "message" => "Payment receipt email sent successfully",
        "recipient" => $gmail,
    ], 200);
} catch (Exception $exception) {
    respond([
        "error" => "Payment receipt email failed to send",
        "details" => $exception->getMessage(),
    ], 500);
}
