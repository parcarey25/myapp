<?php
// wallet_helpers.php
require_once 'db.php';  // adjust if your db.php path is different

function find_user_by_rfid(string $rfid_uid): ?array {
    global $conn;
    $sql = "SELECT id, full_name, email, wallet_balance FROM users WHERE rfid_uid = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $rfid_uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

function add_wallet_transaction(int $user_id, float $amount, string $type, string $description = ''): bool {
    global $conn;
    $sql = "INSERT INTO wallet_transactions (user_id, amount, type, description) VALUES (?,?,?,?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('idss', $user_id, $amount, $type, $description);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function update_wallet_balance(int $user_id, float $delta): bool {
    global $conn;
    $sql = "UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('di', $delta, $user_id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}