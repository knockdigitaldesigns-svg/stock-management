<?php

function otpNowTimestamp() {
    return time();
}

function generateSecureOtp() {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function otpEncryptionKey() {
    $configured = getenv('OTP_ENCRYPTION_KEY');
    if (is_string($configured) && trim($configured) !== '') {
        return hash('sha256', trim($configured), true);
    }
    return hash('sha256', 'stock-management-otp-server-secret-v1', true);
}

function encryptOtpValue($otp) {
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt((string) $otp, 'aes-256-gcm', otpEncryptionKey(), OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) {
        return null;
    }
    return base64_encode($ciphertext) . ':' . base64_encode($nonce) . ':' . base64_encode($tag);
}

function decryptOtpValue($encrypted) {
    $parts = explode(':', (string) $encrypted);
    if (count($parts) !== 3) {
        return null;
    }
    $ciphertext = base64_decode($parts[0], true);
    $nonce = base64_decode($parts[1], true);
    $tag = base64_decode($parts[2], true);
    if ($ciphertext === false || $nonce === false || $tag === false) {
        return null;
    }
    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', otpEncryptionKey(), OPENSSL_RAW_DATA, $nonce, $tag);
    return $plain === false ? null : $plain;
}

function pendingLoginToken() {
    return bin2hex(random_bytes(32));
}

function isOtpExemptRoleName($roleName) {
    $normalized = strtolower(trim((string) $roleName));
    $normalized = preg_replace('/[^a-z0-9]/', '', $normalized);
    return in_array($normalized, ['dealer', 'technician'], true);
}

