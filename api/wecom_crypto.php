<?php
/**
 * api/wecom_crypto.php
 *
 * v3.4 — 企业微信 AES 加解密 + 签名工具函数（共用）
 *
 * 之前散在 api/wecom.php 里，wecom_kf.php 也要用，单独抽出。
 * 包含：decrypt / encryptReply / sha1Sort / extractMsg 等
 *
 * 用法：
 *   require_once __DIR__ . '/wecom_crypto.php';
 *   $plaintext = decrypt($encryptedBase64, $aesKey);
 */

declare(strict_types=1);


// ══════════════════════════════════════════
//  加密/解密（企业微信 AES-256-CBC）
// ══════════════════════════════════════════

function decrypt(string $encryptedBase64, string $aesKey): ?string {
    $key = base64_decode($aesKey . '=');
    $iv = substr($key, 0, 16);
    $encrypted = base64_decode($encryptedBase64);
    if ($encrypted === false) return null;
    $decrypted = @openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    if ($decrypted === false) return null;
    return pkcs7Unpad($decrypted);
}

function pkcs7Unpad(string $text): string {
    $pad = ord(substr($text, -1));
    if ($pad < 1 || $pad > 32) $pad = 0;
    $len = strlen($text);
    if ($pad >= $len) return $text;
    return substr($text, 0, $len - $pad);
}

function encryptReply(string $plaintext, string $aesKey, string $corpId): ?string {
    $key = base64_decode($aesKey . '=');
    $iv = substr($key, 0, 16);
    $random = random_bytes(16);
    $msgLen = pack('N', strlen($plaintext));
    $data = $random . $msgLen . $plaintext . $corpId;
    $padded = pkcs7Pad($data);
    $encrypted = @openssl_encrypt($padded, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    return $encrypted === false ? null : base64_encode($encrypted);
}

function pkcs7Pad(string $data): string {
    $blockSize = 32;
    $pad = $blockSize - (strlen($data) % $blockSize);
    return $data . str_repeat(chr($pad), $pad);
}

function extractMsg(string $plaintext, string $corpId): ?string {
    $content = substr($plaintext, 16);
    if (strlen($content) < 4) return null;
    $len = unpack('N', substr($content, 0, 4))[1];
    $msg = substr($content, 4, $len);
    $actualCorpId = substr($content, 4 + $len);
    if ($actualCorpId !== $corpId) return null;
    return $msg;
}

// ══════════════════════════════════════════
//  签名
// ══════════════════════════════════════════

function sha1Sort(...$parts): string
{
    sort($parts, SORT_STRING);
    return sha1(implode($parts));
}
