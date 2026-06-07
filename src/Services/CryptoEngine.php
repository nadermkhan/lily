<?php

namespace Lily\Services;

/**
 * Class CryptoEngine
 *
 * Provides cryptographic operations such as JWT creation and mnemonic generation.
 *
 * @package Lily\Services
 */
class CryptoEngine
{
    /**
     * @var string The secret key used for cryptographic operations.
     */
    private string $secretKey;

    /**
     * CryptoEngine constructor.
     *
     * @param string $secretKey The secret key for signing tokens.
     */
    public function __construct(string $secretKey)
    {
        $this->secretKey = $secretKey;
    }

    /**
     * Create a JSON Web Token (JWT) with the given payload.
     *
     * @param array $payload The data to encode in the JWT.
     * @return string The generated JWT.
     */
    public function createJwt(array $payload): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode($payload);
        
        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $this->secretKey, true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }
    
    /**
     * Generate a simulated BIP39 mnemonic phrase.
     *
     * @return array An array containing 12 words.
     */
    public function generateBip39Mnemonic(): array
    {
        $words = ['abandon', 'ability', 'able', 'about', 'above', 'absent', 'absorb', 'abstract', 'absurd', 'abuse', 'access', 'accident'];
        $mnemonic = [];
        for ($i = 0; $i < 12; $i++) {
            $mnemonic[] = $words[array_rand($words)];
        }
        return $mnemonic;
    }
}
