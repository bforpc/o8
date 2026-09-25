<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use O8\Core\Languages;

$available=Languages::available(dirname(__DIR__).'/lang');
if (!isset($available['de'],$available['en'])) throw new RuntimeException('Base language files are not available.');
if (Languages::selected($available,'missing')!=='de') throw new RuntimeException('The default language fallback is not German.');
if (Languages::text($available,'en','common.save')!=='Save') throw new RuntimeException('English lookup failed.');
$singular=Languages::display($available,'en','Tag „Steuer“ ist 1 Dokument zugeordnet. Beim Löschen wird dieses Tag von allen 1 Dokument entfernt. Fortfahren?');
$plural=Languages::display($available,'en','Tag „Steuer“ ist 2 Dokumenten zugeordnet. Beim Löschen wird dieses Tag von allen 2 Dokumenten entfernt. Fortfahren?');
if (!str_contains($singular,'assigned to 1 document.') || !str_contains($plural,'assigned to 2 documents.')) throw new RuntimeException('Named placeholders or plural source translation failed.');

$directory=sys_get_temp_dir().'/o8-language-test-'.bin2hex(random_bytes(8));
if (!mkdir($directory,0700)) throw new RuntimeException('Unable to create isolated language discovery fixture.');
try {
    copy(dirname(__DIR__).'/lang/de.json',$directory.'/de.json');
    file_put_contents($directory.'/zz.json',json_encode(['meta'=>['code'=>'zz','name'=>'Temporary test language'],'messages'=>['common'=>['save'=>'Temporary save']]],JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/bad.json','{"meta":{"code":"wrong","name":"Invalid"},"messages":{"x":"y"}}');
    $discovered=Languages::available($directory);
    if (!isset($discovered['zz']) || isset($discovered['bad'])) throw new RuntimeException('Language discovery accepted or rejected an unexpected file.');
    if (Languages::text($discovered,'zz','common.save')!=='Temporary save' || Languages::text($discovered,'zz','navigation.documents')!=='Dokumente') throw new RuntimeException('Additional language or German fallback failed.');
} finally {
    foreach (glob($directory.'/*')?:[] as $file) if (is_file($file) && !is_link($file)) unlink($file);
    rmdir($directory);
}
echo "Language lookup, German fallback, interpolation, plural forms and automatic discovery passed.\n";
