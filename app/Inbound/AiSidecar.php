<?php
declare(strict_types=1);
namespace O8\Inbound;

/** Reads untrusted AI sidecars without creating or changing catalogue values. */
final class AiSidecar
{
    public const MAX_BYTES=1048576;

    public function inspect(?string $path,array $catalogue): array
    {
        $empty=['present'=>false,'valid'=>false,'data'=>null,'matchedTags'=>[],'ignoredTags'=>[],'error'=>null];
        if ($path===null || !is_file($path)) return $empty;
        if (is_link($path) || !is_readable($path)) return [...$empty,'present'=>true,'error'=>'AI-JSON ist nicht sicher lesbar.'];
        $size=filesize($path);
        if ($size===false || $size<2 || $size>self::MAX_BYTES) return [...$empty,'present'=>true,'error'=>'AI-JSON ist leer oder größer als 1 MiB.'];
        return $this->inspectText((string)file_get_contents($path),$catalogue);
    }

    public function inspectText(string $json,array $catalogue): array
    {
        $empty=['present'=>true,'valid'=>false,'data'=>null,'matchedTags'=>[],'ignoredTags'=>[],'error'=>null];
        if (strlen($json)<2 || strlen($json)>self::MAX_BYTES) return [...$empty,'error'=>'AI-JSON ist leer oder größer als 1 MiB.'];
        try {
            $data=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [...$empty,'present'=>true,'error'=>'AI-JSON ist ungültig.'];
        }
        if (!is_array($data) || array_is_list($data)) return [...$empty,'present'=>true,'error'=>'AI-JSON muss ein Objekt enthalten.'];

        $known=[];
        foreach ($catalogue as $tag) {
            if (!is_array($tag) || !isset($tag['id'],$tag['name'])) continue;
            $key=$this->normalize((string)$tag['name']);
            if ($key!=='') $known[$key]=['id'=>(int)$tag['id'],'name'=>(string)$tag['name']];
        }
        $suggested=$data['dokument']['tags']??[];
        if (is_string($suggested)) $suggested=[$suggested];
        if (!is_array($suggested)) $suggested=[];
        $matched=[]; $ignored=[]; $seen=[];
        foreach ($suggested as $name) {
            if (!is_string($name)) continue;
            $name=trim($name); $key=$this->normalize($name);
            if ($key==='' || isset($seen[$key])) continue;
            $seen[$key]=true;
            if (isset($known[$key])) $matched[]=$known[$key];
            else $ignored[]=$name;
        }
        return ['present'=>true,'valid'=>true,'data'=>$data,'matchedTags'=>$matched,'ignoredTags'=>$ignored,'error'=>null];
    }

    private function normalize(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
