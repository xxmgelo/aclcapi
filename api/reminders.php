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
$subject = trim((string)($data["subject"] ?? ""));
$message = trim((string)($data["message"] ?? ""));
$html = trim((string)($data["html"] ?? ""));
$due_label = trim((string)($data["due_label"] ?? ""));
$due_amount = parse_money($data["due_amount"] ?? 0);
$financials = normalized_student_financials($data);
$program_hint = trim((string)($data["Program"] ?? ""));

$resolved_student = find_student_for_mail_reminder($db, $student_id, $program_hint);
if (!$resolved_student) {
    respond(["error" => "Student record not found for reminder"], 404);
}
$student_name = trim((string)($resolved_student["name"] ?? "")) ?: $student_name;
$gmail = trim((string)($resolved_student["gmail"] ?? "")) ?: $gmail;
$source_table = trim((string)($resolved_student["source_table"] ?? ""));
$can_remind_flag = (int)($resolved_student["can_remind"] ?? 0);

if ($student_name === "" || $student_id === "") {
    respond(["error" => "Student name and ID are required"], 422);
}

if ($gmail === "" || !filter_var($gmail, FILTER_VALIDATE_EMAIL)) {
    respond(["error" => "A valid Gmail address is required"], 422);
}

$students_using_same_gmail = find_students_by_gmail_reminder($db, $gmail);
if (count($students_using_same_gmail) > 1) {
    $preview = array_slice($students_using_same_gmail, 0, 4);
    $details = implode(", ", array_map(function ($row) {
        return trim((string)$row["name"]) . " (" . trim((string)$row["student_id"]) . ")";
    }, $preview));

    respond([
        "error" => "This Gmail is linked to multiple student records. Update student Gmail entries before sending reminders.",
        "details" => $details,
    ], 422);
}

if ($can_remind_flag !== 1) {
    respond([
        "error" => "Reminder can be sent only once after each saved payment. Save a payment first to enable reminder again."
    ], 422);
}

if ($financials["total_balance"] <= 0) {
    respond(["error" => "This student no longer has an outstanding balance"], 422);
}

if ($subject === "") {
    $subject = "Tuition Fee Payment Reminder - ACLC College of Manila";
}

if ($message === "") {
    $resolved_due_label = $due_label !== "" ? $due_label : "Payment";
    $resolved_due_amount = $due_amount > 0 ? $due_amount : (float)$financials["total_balance"];
    $message = "Good day,\n\nThis is to inform you that your {$resolved_due_label} tuition fee payment remains outstanding.\n\n" .
        "The remaining balance for this payment stage is PHP " . number_format($resolved_due_amount, 2) . ".\n\n" .
        "Please settle this amount at your earliest convenience to avoid delays in your academic transactions.\n\n" .
        "If payment has already been made, please disregard this message and coordinate with the accounting office for verification.\n\n" .
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

function value_or_default($array, $key, $default = "")
{
    return isset($array[$key]) ? $array[$key] : $default;
}

function find_student_for_mail_reminder($db, $student_id, $program_hint = "")
{
    $stmt = $db->prepare(
        "SELECT 'bse_students' AS source_table, student_id, name, program, gmail, can_remind FROM bse_students WHERE student_id = ?
         UNION ALL
         SELECT 'bsis_students' AS source_table, student_id, name, program, gmail, can_remind FROM bsis_students WHERE student_id = ?"
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

function set_can_remind_flag_after_send($db, $source_table, $student_id, $value)
{
    if (!in_array($source_table, ["bse_students", "bsis_students"], true)) {
        return false;
    }

    $stmt = $db->prepare("UPDATE {$source_table} SET can_remind = ? WHERE student_id = ?");
    if (!$stmt) {
        return false;
    }

    $flag = (int)$value;
    $stmt->bind_param("is", $flag, $student_id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function find_students_by_gmail_reminder($db, $gmail)
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

function build_reminder_html($student_name, $student_id, $message, $financials)
{
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

function inline_notification_assets_reminder($mail, $html)
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

        try {
            $cid = preg_replace('/[^a-z0-9_.-]/i', '_', $file_name);
            $mail->addStringEmbeddedImage($binary, $cid, $file_name, 'base64', $mime);
            $pattern = '/src=(["\'])[^"\']*' . preg_quote($file_name, '/') . '[^"\']*\1/i';
            $html = preg_replace($pattern, 'src="cid:' . $cid . '"', $html);
        } catch (Exception $e) {
            $data_uri = "data:" . $mime . ";base64," . base64_encode($binary);
            $pattern = '/src=(["\'])[^"\']*' . preg_quote($file_name, '/') . '[^"\']*\1/i';
            $html = preg_replace($pattern, 'src="' . $data_uri . '"', $html);
        }
    }

    return $html;
}

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->CharSet = "UTF-8";
    $mail->Encoding = "base64";
    $mail->Host = value_or_default($mailConfig, "host", "smtp.gmail.com");
    $mail->SMTPAuth = true;
    $mail->Username = trim((string)value_or_default($mailConfig, "username"));
    $mail->Password = preg_replace('/\s+/', '', (string)value_or_default($mailConfig, "app_password"));
    $mail->Port = (int)value_or_default($mailConfig, "port", 587);

    $encryption = strtolower((string)value_or_default($mailConfig, "encryption", "tls"));
    if ($encryption === "ssl") {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }

    $fromEmail = trim((string)value_or_default($mailConfig, "from_email", $mail->Username));
    $fromName = trim((string)value_or_default($mailConfig, "from_name", "ACLC Fee Management System"));

    if ($mail->Username === "" || $mail->Password === "" || $fromEmail === "") {
        respond(["error" => "Mail configuration is incomplete. Fill in api/mail_config.php first."], 500);
    }

    $mail->setFrom($fromEmail, $fromName);

    $replyToEmail = value_or_default($mailConfig, "reply_to_email");
    if ($replyToEmail !== "") {
        $mail->addReplyTo(
            $replyToEmail,
            value_or_default($mailConfig, "reply_to_name", $fromName)
        );
    }

    $mail->addAddress($gmail, $student_name);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $resolved_html = $html !== ""
        ? $html
        : build_reminder_html($student_name, $student_id, $message, $financials);
    $mail->Body = inline_notification_assets_reminder($mail, $resolved_html);
    $mail->AltBody = $message;

    $mail->send();
    set_can_remind_flag_after_send($db, $source_table, $student_id, 0);

    respond([
        "sent" => true,
        "message" => "Reminder email sent successfully",
        "recipient" => $gmail,
        "student_id" => $student_id,
        "can_remind" => false,
    ], 200);
} catch (Exception $exception) {
    respond([
        "error" => "Reminder email failed to send",
        "details" => $exception->getMessage(),
    ], 500);
}
