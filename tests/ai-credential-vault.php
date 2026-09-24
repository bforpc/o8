<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use O8\Inbound\AiCredentialVault;

$checks=0; function check(bool $condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException('FAIL '.$message); ++$checks; echo "PASS $message\n"; }
$identity=['id'=>'01234567-89ab-4def-8123-456789abcdef','key'=>str_repeat('ab',32)]; $vault=new AiCredentialVault($identity); $cipher=$vault->encrypt('private-ai-token',12);
check(!str_contains($cipher,'private-ai-token'),'AI token ciphertext does not contain plaintext');
check($vault->decrypt($cipher,$identity['id'],1,12)==='private-ai-token','AI token decrypts only in its tenant-bound context');
try { $vault->decrypt($cipher,$identity['id'],1,13); check(false,'foreign tenant rejected'); } catch (RuntimeException) { check(true,'foreign tenant rejected'); }
try { $vault->decrypt($cipher,'ffffffff-ffff-4fff-8fff-ffffffffffff',1,12); check(false,'foreign installation rejected'); } catch (RuntimeException) { check(true,'foreign installation rejected'); }
echo "$checks AI credential checks passed.\n";
