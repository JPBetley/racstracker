<?php

namespace App\LastWar\Game\Gsl;

use App\LastWar\Game\Exceptions\GameClientException;

/**
 * The gate server's request envelope.
 *
 * A random 20-character salt is RSA (PKCS#1 v1.5) encrypted with the key from
 * check-version, and the form is AES-256-ECB encrypted with the 32 ASCII characters
 * of md5(salt) as the key. Replies carrying a `bin` field use the same AES key.
 */
final class GslCrypto
{
    private const string SALT_ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-/=<>?{}[]';

    private ?string $salt = null;

    public function __construct(private readonly string $publicKeyPem) {}

    /**
     * Turn the base64 DER SubjectPublicKeyInfo from check-version into a PEM key.
     */
    public static function pemFromDer(string $base64Der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(preg_replace('/\s+/', '', $base64Der), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    /**
     * Encrypt a request form.
     *
     * @return array{uuid: string, data: string}
     */
    public function encryptRequest(string $form, ?string $salt = null): array
    {
        $this->salt = $salt ?? self::randomSalt();

        if (! openssl_public_encrypt($this->salt, $encryptedSalt, $this->publicKeyPem, OPENSSL_PKCS1_PADDING)) {
            throw new GameClientException('Could not RSA-encrypt the gate server salt; the public key may be invalid.');
        }

        return [
            'uuid' => self::base64UrlEncode($encryptedSalt),
            'data' => self::base64UrlEncode($this->aes(true, $form)),
        ];
    }

    /**
     * Decrypt a reply's `bin` field with the key from the last request.
     */
    public function decryptResponse(string $bin): string
    {
        if ($this->salt === null) {
            throw new GameClientException('Cannot decrypt a gate server reply before encrypting a request.');
        }

        return $this->aes(false, self::base64UrlDecode($bin));
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'), true)
            ?: throw new GameClientException('Gate server reply is not valid base64.');
    }

    private function aes(bool $encrypt, string $data): string
    {
        $key = md5($this->salt);

        $result = $encrypt
            ? openssl_encrypt($data, 'aes-256-ecb', $key, OPENSSL_RAW_DATA)
            : openssl_decrypt($data, 'aes-256-ecb', $key, OPENSSL_RAW_DATA);

        if ($result === false) {
            throw new GameClientException('AES '.($encrypt ? 'encryption' : 'decryption').' of the gate server envelope failed.');
        }

        return $result;
    }

    private static function randomSalt(): string
    {
        $alphabet = self::SALT_ALPHABET;
        $salt = '';

        for ($i = 0; $i < 20; $i++) {
            $salt .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $salt;
    }
}
