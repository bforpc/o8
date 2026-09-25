<?php
declare(strict_types=1);
namespace O8\Core;

final class Languages
{
    /** @return array<string,array{code:string,name:string,messages:array}> */
    public static function available(string $directory): array
    {
        if (is_link($directory) || !is_dir($directory) || ($realDirectory=realpath($directory))===false) return [];
        $languages=[];
        foreach (glob($realDirectory.'/*.json')?:[] as $path) {
            $code=basename($path,'.json');
            if (!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$code) || is_link($path) || !is_file($path)
                || ($realPath=realpath($path))===false || dirname($realPath)!==$realDirectory) continue;
            try { $data=json_decode((string)@file_get_contents($realPath),true,64,JSON_THROW_ON_ERROR); }
            catch (\Throwable) { continue; }
            if (!is_array($data) || !is_array($data['meta']??null) || !is_array($data['messages']??null)
                || ($data['meta']['code']??null)!==$code || !is_string($data['meta']['name']??null)
                || trim($data['meta']['name'])==='' || !self::messageTreeValid($data['messages'])) continue;
            $languages[$code]=['code'=>$code,'name'=>$data['meta']['name'],'messages'=>$data['messages']];
        }
        ksort($languages,SORT_STRING);
        return $languages;
    }

    public static function selected(array $available, ?string $candidate): string
    {
        if ($candidate!==null && isset($available[$candidate])) return $candidate;
        return isset($available['de'])?'de':(array_key_first($available)??'de');
    }

    public static function text(array $available,string $language,string $key,array $values=[]): string
    {
        $value=self::at(self::messages($available,$language),$key);
        if (!is_string($value) && !is_array($value)) $value=self::at(self::messages($available,'de'),$key);
        if (is_array($value)) $value=((int)($values['count']??0)===1)?($value['one']??null):($value['other']??null);
        if (!is_string($value)) return $language==='de'?'Text nicht verfügbar':'Text not available';
        foreach ($values as $name=>$replacement) $value=str_replace('{'.$name.'}',(string)$replacement,$value);
        return $value;
    }

    /** @return array<string,mixed> */
    public static function messages(array $available,string $language): array
    {
        return $available[$language]['messages']??$available['de']['messages']??[];
    }

    public static function bundle(array $available,string $language): array
    {
        return ['language'=>$language,'messages'=>self::messages($available,$language),'de'=>self::messages($available,'de')];
    }

    public static function display(array $available,string $language,string $source): string
    {
        $found=self::findSource(self::messages($available,'de'),$source);
        if ($found===null) return $source;
        [$path,$variables]=$found;
        $value=self::at(self::messages($available,$language),$path);
        if (is_array($value)) $value=((int)($variables['count']??0)===1)?($value['one']??null):($value['other']??null);
        if (!is_string($value)) return $source;
        foreach ($variables as $name=>$replacement) $value=str_replace('{'.$name.'}',(string)$replacement,$value);
        return $value;
    }

    private static function at(array $messages,string|array $path): mixed
    {
        foreach (is_array($path)?$path:explode('.',$path) as $part) {
            if (!is_array($messages) || !array_key_exists($part,$messages)) return null;
            $messages=$messages[$part];
        }
        return $messages;
    }

    private static function findSource(array $messages,string $source,array $path=[]): ?array
    {
        $best=null;
        foreach ($messages as $key=>$value) {
            if (is_string($value)) {
                if ($value===$source) return [[...$path,(string)$key],[],PHP_INT_MAX];
                if (str_contains($value,'{')) {
                    $names=[]; $pattern=''; $offset=0;
                    if (preg_match_all('/\{([a-zA-Z][a-zA-Z0-9_]*)\}/',$value,$matches,PREG_OFFSET_CAPTURE)) {
                        foreach ($matches[0] as $i=>$capture) {
                            $pattern.=preg_quote(substr($value,$offset,$capture[1]-$offset),'/');
                            $name=$matches[1][$i][0];
                            $pattern.=in_array($name,$names,true)?'(?P='.$name.')':'(?P<'.$name.'>.*?)';
                            if (!in_array($name,$names,true)) $names[]=$name;
                            $offset=$capture[1]+strlen($capture[0]);
                        }
                        $pattern.=preg_quote(substr($value,$offset),'/');
                        if (preg_match('/^'.$pattern.'$/uD',$source,$captured)) {
                            $variables=[]; foreach ($names as $name) $variables[$name]=$captured[$name];
                            $literalLength=strlen(preg_replace('/\{[a-zA-Z][a-zA-Z0-9_]*\}/','',$value)??'');
                            if ($best===null || $literalLength>$best[2]) $best=[[...$path,(string)$key],$variables,$literalLength];
                        }
                    }
                }
            } elseif (is_array($value) && ($found=self::findSource($value,$source,[...$path,(string)$key]))!==null && ($best===null || $found[2]>$best[2])) $best=$found;
        }
        return $best;
    }

    private static function messageTreeValid(array $messages): bool
    {
        if ($messages===[]) return false;
        foreach ($messages as $key=>$value) {
            if (!is_string($key) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D',$key)) return false;
            if (is_string($value)) { if ($value==='') return false; continue; }
            if (!is_array($value) || $value===[] || !self::messageTreeValid($value)) return false;
            if (isset($value['one']) || isset($value['other'])) if (!is_string($value['one']??null) || !is_string($value['other']??null)) return false;
        }
        return true;
    }
}
