<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use O8\Auth\AuthService;

foreach (['abcdef', 'äöüabc', str_repeat('a',72)] as $password) {
    AuthService::password($password);
}
foreach (['', 'abcde', 'äöüab', 'owndms8', "abcdef\n", str_repeat('a',73), str_repeat('ä',37)] as $password) {
    try {
        AuthService::password($password);
    } catch (InvalidArgumentException) {
        continue;
    }
    throw new RuntimeException('Invalid password unexpectedly accepted.');
}
echo "PASS: minimum 6 characters, Unicode length, maximum 72 bytes, start password and control characters.\n";
