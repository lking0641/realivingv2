<?php
// spinwheel_mark_claimed.php
session_start();
include $includes ['connection'];
include $includes ['checkrole'];
require_role(['admin1','admin2','admin3','admin4','admin5','admin6','superadmin','sales','designer']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = strtoupper(trim($_POST['claim_token']));
    $ref   = trim($_POST['redirect_ref'] ?? $token);
    $token_esc = $conn->real_escape_string($token);

    $reg = $conn->query("SELECT id, is_claimed FROM spinwheel_registrations WHERE claim_token = '$token_esc' LIMIT 1")->fetch_assoc();

    if ($reg && !$reg['is_claimed']) {
        $conn->query("UPDATE spinwheel_registrations SET is_claimed=1, claimed_at=NOW() WHERE id={$reg['id']}");
    }

   header("Location: spinwheel_claim_scanner.php?ref=" . urlencode($ref) . "&claimed=1");
    exit();
}

header("Location: /");
exit();