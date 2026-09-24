<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use O8\Inbound\AiSidecar;

$checks=0;
function check(bool $condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException('FAIL '.$message); ++$checks; echo "PASS $message\n"; }
$directory=sys_get_temp_dir().'/o8-ai-sidecar.'.bin2hex(random_bytes(6));
if (!mkdir($directory,0700)) throw new RuntimeException('Temporary directory unavailable.');
try {
    $path=$directory.'/document.json';
    file_put_contents($path,json_encode(['dokument'=>['titel'=>'Test','tags'=>['Rechnung','Rechnungen',' rechnung ','Steuer',42]]],JSON_THROW_ON_ERROR));
    $result=(new AiSidecar())->inspect($path,[['id'=>7,'name'=>'Rechnung'],['id'=>9,'name'=>'Steuer']]);
    check($result['present'] && $result['valid'],'valid sidecar is accepted');
    check(array_column($result['matchedTags'],'id')===[7,9],'only exact existing catalogue tags are matched once');
    check($result['ignoredTags']===['Rechnungen'],'similar AI wording is ignored instead of creating a tag');
    check(count($result['data'])===1,'complete AI object is retained for later validated processing');
    $memory=(new AiSidecar())->inspectText('{"dokument":{"tags":["Rechnung","Neue KI-Schreibweise"]}}',[['id'=>7,'name'=>'Rechnung']]);
    check(array_column($memory['matchedTags'],'id')===[7] && $memory['ignoredTags']===['Neue KI-Schreibweise'],'fresh AI responses use the same exact catalogue matching as sidecar files');
    file_put_contents($path,'{"broken":');
    $invalid=(new AiSidecar())->inspect($path,[]);
    check($invalid['present'] && !$invalid['valid'] && $invalid['data']===null,'invalid JSON is rejected without partial data');
    check((new AiSidecar())->inspect(null,[])['present']===false,'missing sidecar remains an allowed manual-import case');
} finally {
    if (is_file($path??'')) unlink($path);
    rmdir($directory);
}
echo "$checks AI sidecar checks passed.\n";
