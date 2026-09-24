<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if (PHP_SAPI!=='cli' || !preg_match('#^/tmp/o8-m2-test\.[A-Za-z0-9]+/db\.sock$#D',$argv[1]??'')) exit(2);
if (!preg_match('/^administration_test_[a-f0-9]{8}$/D',$argv[3]??'')) exit(2);
$db=new PDO('mysql:unix_socket='.$argv[1].';dbname='.$argv[3].';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$s=$db->prepare('SELECT a.*,u.*,a.auth_version AS account_auth_version FROM users u JOIN accounts a ON a.id=u.account_id WHERE u.id=?'); $s->execute([(int)$argv[2]]); $actor=new O8\Auth\Actor('tenant',$s->fetch());
try {
    (new O8\Admin\Administration($db))->updateUser($actor,$actor->id(),(int)$actor->row['auth_version'],'Concurrent admin','user',true);
    echo 'changed';
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(),'letzte aktive Administrator')) throw $e;
    echo 'blocked';
}
