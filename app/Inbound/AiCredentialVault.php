<?php
declare(strict_types=1);
namespace O8\Inbound;

/** Authenticated encryption for the tenant-wide, write-only external AI token. */
final class AiCredentialVault
{
    private string $key;
    private string $keyId;

    public function __construct(array $identity)
    {
        $material=hex2bin((string)($identity['key']??''));
        $this->keyId=(string)($identity['id']??'');
        if ($material===false || strlen($material)!==32 || !preg_match('/^[a-f0-9-]{36}$/D',$this->keyId)) throw new \RuntimeException('Installationsschlüssel für KI-Zugang ist ungültig.');
        $this->key=sodium_crypto_generichash('o8-ai-credentials-v1',$material,SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        sodium_memzero($material);
    }

    public function keyId(): string { return $this->keyId; }
    public function encrypt(string $secret,int $tenant): string
    {
        if ($secret==='' || strlen($secret)>8192 || str_contains($secret,"\0")) throw new \InvalidArgumentException('KI-API-Token ist ungültig.');
        $nonce=random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return $nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($secret,$this->aad($tenant),$nonce,$this->key);
    }
    public function decrypt(string $ciphertext,string $keyId,int $version,int $tenant): string
    {
        if ($version!==1 || !hash_equals($this->keyId,$keyId) || strlen($ciphertext)<=SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) throw new \RuntimeException('KI-Zugangsdaten können nicht entschlüsselt werden.');
        $nonce=substr($ciphertext,0,SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $plain=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($ciphertext,SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),$this->aad($tenant),$nonce,$this->key);
        if ($plain===false) throw new \RuntimeException('KI-Zugangsdaten sind beschädigt oder gehören zu einem anderen Mandanten.');
        return $plain;
    }
    private function aad(int $tenant): string { return "o8\0ai\0$tenant\0v1"; }
}
