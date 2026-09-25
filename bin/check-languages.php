<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
$directory=dirname(__DIR__).'/lang';
$languages=O8\Core\Languages::available($directory);
if (!isset($languages['de'],$languages['en'])) { fwrite(STDERR,"Required base languages de and en must be valid JSON language files.\n"); exit(1); }
$flatten=static function(array $input,string $prefix='') use (&$flatten): array {
    $result=[];
    foreach ($input as $key=>$value) { $path=$prefix===''?(string)$key:$prefix.'.'.$key; if (is_array($value)) $result+=$flatten($value,$path); elseif (is_string($value)) $result[$path]=$value; }
    return $result;
};
$base=$flatten($languages['de']['messages']); $failed=false;
foreach (['en'] as $code) {
    $other=$flatten($languages[$code]['messages']);
    $missing=array_diff_key($base,$other); $extra=array_diff_key($other,$base);
    foreach ($missing as $key=>$value) { fwrite(STDERR,"$code missing key: $key\n"); $failed=true; }
    foreach ($extra as $key=>$value) { fwrite(STDERR,"$code extra key: $key\n"); $failed=true; }
    foreach ($base as $key=>$value) {
        if (!array_key_exists($key,$other)) continue;
        preg_match_all('/\{([a-zA-Z][a-zA-Z0-9_]*)\}/',$value,$deVars); preg_match_all('/\{([a-zA-Z][a-zA-Z0-9_]*)\}/',$other[$key],$enVars);
        $deNames=array_values(array_unique($deVars[1])); $enNames=array_values(array_unique($enVars[1])); sort($deNames); sort($enNames);
        if ($deNames!==$enNames) { fwrite(STDERR,"$code placeholder mismatch: $key\n"); $failed=true; }
    }
}
$root=dirname(__DIR__);
$sourceFiles=[];
foreach ([$root.'/app',$root.'/public/assets/js'] as $sourceRoot) {
    if (!is_dir($sourceRoot)) continue;
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot,FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) if ($file->isFile() && preg_match('/\.(?:php|js)$/D',$file->getFilename())) $sourceFiles[]=$file->getPathname();
}
foreach ($sourceFiles as $file) {
    $contents=(string)file_get_contents($file);
    if (!preg_match_all('/\b(tr|searchTr|t|o8Translate)\(\s*[\'\"]([A-Za-z][A-Za-z0-9_-]*(?:\.[A-Za-z][A-Za-z0-9_-]*)*)[\'\"]/', $contents, $references,PREG_SET_ORDER)) continue;
    foreach ($references as $reference) {
        $name=$reference[1]; $key=$reference[2];
        $candidates=match($name) {'searchTr'=>['search.'.$key],'o8Translate'=>[$key],default=>[$key,'workspace.'.$key,'client.'.$key,'common.'.$key,'dialogs.'.$key]};
        $valid=false;
        foreach ($candidates as $candidate) {
            $value=$languages['de']['messages'];
            foreach (explode('.',$candidate) as $part) $value=is_array($value)?($value[$part]??null):null;
            if (is_string($value) || (is_array($value) && is_string($value['one']??null) && is_string($value['other']??null))) { $valid=true; break; }
        }
        if (!$valid) { fwrite(STDERR,substr($file,strlen($root)+1).": missing translation key for $name('$key')\n"); $failed=true; }
    }
}
foreach ($languages as $code=>$language) printf("Valid language: %s (%s)\n",$code,$language['name']);
if ($failed) exit(1);
echo "Base language keys and placeholders match.\n";
