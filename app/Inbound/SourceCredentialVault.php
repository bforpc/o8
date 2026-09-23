<?php
declare(strict_types=1);
namespace O8\Inbound;

/** Authenticated source-secret encryption bound to this installation and source owner. */
final class SourceCredentialVault
{
    private string $key;
    private string $keyId;

    public function __construct(array $identity)
    {
        $material=hex2bin((string)($identity['key']??''));
        $this->keyId=(string)($identity['id']??'');
        if ($material===false || strlen($material)!==32 || !preg_match('/^[a-f0-9-]{36}$/D',$this->keyId)) throw new \RuntimeException('Installationsschlüssel für Quellen ist ungültig.');
        $this->key=sodium_crypto_generichash('o8-source-credentials-v1',$material,SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        sodium_memzero($material);
    }

    public function keyId(): string { return $this->keyId; }

    public function encrypt(string $secret,int $tenant,int $owner,string $kind): string
    {
        if ($secret==='' || strlen($secret)>8192 || str_contains($secret,"\0")) throw new \InvalidArgumentException('Passwort oder Token ist ungültig.');
        $nonce=random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher=sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($secret,$this->aad($tenant,$owner,$kind),$nonce,$this->key);
        return $nonce.$cipher;
    }

    public function decrypt(string $ciphertext,string $keyId,int $version,int $tenant,int $owner,string $kind): string
    {
        if ($version!==1 || !hash_equals($this->keyId,$keyId) || strlen($ciphertext)<=SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) throw new \RuntimeException('Quellen-Zugangsdaten können nicht entschlüsselt werden.');
        $nonce=substr($ciphertext,0,SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $plain=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($ciphertext,SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),$this->aad($tenant,$owner,$kind),$nonce,$this->key);
        if ($plain===false) throw new \RuntimeException('Quellen-Zugangsdaten sind beschädigt oder gehören zu einem anderen Kontext.');
        return $plain;
    }

    private function aad(int $tenant,int $owner,string $kind): string
    {
        return "o8\0source\0$tenant\0$owner\0$kind\0v1";
    }
}
