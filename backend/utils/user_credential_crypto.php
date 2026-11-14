<?php

function getUserCredentialEncryptionKey(): string
{
    $keyFile = __DIR__ . '/../config/eagletrazer-credential-key.php';

    if (!file_exists($keyFile)) {
        throw new RuntimeException('KEY FILE DOES NOT EXIST: ' . $keyFile);
    }

    $configuredKey = require $keyFile;

    if (!is_string($configuredKey)) {
        throw new RuntimeException('KEY FILE DID NOT RETURN A STRING');
    }

    if (strlen($configuredKey) !== 64) {
        throw new RuntimeException(
            'KEY LENGTH IS ' . strlen($configuredKey) . ', EXPECTED 64'
        );
    }

    if (!preg_match('/\A[0-9a-fA-F]{64}\z/', $configuredKey)) {
        throw new RuntimeException('KEY FORMAT IS INVALID');
    }

    $key = hex2bin($configuredKey);

    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('KEY CONVERSION FAILED');
    }

    return $key;
}

function encryptUserCredential(string $password, int $userId): array
{
    $key = getUserCredentialEncryptionKey();
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $password,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        'eagletrazer:user-credential:v1:' . $userId,
        16
    );

    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Unable to encrypt user credential.');
    }

    return [
        'ciphertext' => base64_encode($ciphertext),
        'nonce' => base64_encode($nonce),
        'tag' => base64_encode($tag)
    ];
}

function decryptUserCredential(string $ciphertext, string $nonce, string $tag, int $userId): string
{
    $decodedCiphertext = base64_decode($ciphertext, true);
    $decodedNonce = base64_decode($nonce, true);
    $decodedTag = base64_decode($tag, true);
    if ($decodedCiphertext === false || $decodedNonce === false || $decodedTag === false
        || strlen($decodedNonce) !== 12 || strlen($decodedTag) !== 16) {
        throw new RuntimeException('Stored user credential is invalid.');
    }

    $password = openssl_decrypt(
        $decodedCiphertext,
        'aes-256-gcm',
        getUserCredentialEncryptionKey(),
        OPENSSL_RAW_DATA,
        $decodedNonce,
        $decodedTag,
        'eagletrazer:user-credential:v1:' . $userId
    );
    if ($password === false) {
        throw new RuntimeException('Unable to decrypt user credential.');
    }

    return $password;
}
?>
