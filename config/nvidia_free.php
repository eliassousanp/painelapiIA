<?php
// IDs vêm da API autenticada; a classificação vem do catálogo público oficial.
function isNvidiaHostedProvider(array $provider): bool {
    $host = strtolower((string)parse_url((string)($provider['base_url'] ?? ''), PHP_URL_HOST));
    return $host === 'integrate.api.nvidia.com';
}

function nvidiaCatalogKey(string $publisher, string $name): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower($publisher)) . '/' . preg_replace('/[^a-z0-9]/', '', strtolower($name));
}

function nvidiaFreeKeysFromPages(callable $fetchPage): array {
    $keys=[];$resourceIds=[];$page=0;$expected=null;$seen=0;
    do {
        $data=$fetchPage($page);
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) throw new RuntimeException('Catálogo NVIDIA indisponível ou inválido.');
        $group=null;foreach($data['results'] as $g) if (($g['groupValue'] ?? '') === 'ENDPOINT') {$group=$g;break;}
        if (!$group || !isset($group['resources']) || !is_array($group['resources']) || !isset($data['resultTotal'])) throw new RuntimeException('Catálogo NVIDIA incompleto.');
        $total=(int)$data['resultTotal'];if ($expected===null)$expected=$total;elseif($expected!==$total)throw new RuntimeException('O catálogo NVIDIA mudou durante a consulta; tente novamente.');
        foreach($group['resources'] as $item) {
            $resourceId=(string)($item['resourceId'] ?? '');
            if($resourceId==='' || isset($resourceIds[$resourceId]))throw new RuntimeException('Catálogo NVIDIA incompleto ou com itens duplicados.');
            $resourceIds[$resourceId]=true;
            $free=false;$publishers=[];
            foreach(($item['labels'] ?? []) as $label) {
                if(in_array('nim_type_preview',$label['unresolvedValues'] ?? [],true) || in_array('Free Endpoint',$label['values'] ?? [],true)) $free=true;
                if(($label['key'] ?? '')==='publisher') $publishers=$label['unresolvedValues'] ?? $label['values'] ?? [];
            }
            if($free && !empty($item['name']))foreach($publishers as $publisher) $keys[nvidiaCatalogKey((string)$publisher,(string)$item['name'])]=true;
        }
        $count=count($group['resources']);$seen+=$count;$page++;
        if($seen<$expected && !$count)throw new RuntimeException('O catálogo NVIDIA retornou uma página vazia.');
        if($page>100)throw new RuntimeException('O catálogo NVIDIA excedeu o limite de páginas.');
    } while($seen<$expected);
    if(!$keys)throw new RuntimeException('Nenhum endpoint gratuito foi confirmado no catálogo NVIDIA.');
    return $keys;
}

function fetchNvidiaFreeKeys(): array {
    return nvidiaFreeKeysFromPages(function(int $page): array {
        $query=json_encode(['filters'=>[['field'=>'label','value'=>'nim_type_preview']],'page'=>$page,'pageSize'=>100,'query'=>'*']);
        $url='https://api.ngc.nvidia.com/v2/search/catalog/resources/ENDPOINT?q='.rawurlencode($query);
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json']]);
        $body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        $data=is_string($body)?json_decode($body,true):null;
        if($status!==200 || !is_array($data))throw new RuntimeException('Não foi possível consultar o catálogo público da NVIDIA.');
        return $data;
    });
}

function filterNvidiaFreeModels(array $models,array $keys): array {
    return array_values(array_filter($models,function($item) use($keys) {
        $id=(string)($item['upstream'] ?? '');$parts=explode('/',$id);
        return count($parts)===2 && isset($keys[nvidiaCatalogKey($parts[0],$parts[1])]);
    }));
}

// Atualiza somente a classificação; não apaga modelos ou configurações existentes.
function saveNvidiaFreeModels(PDO $db,int $providerId,array $models): void {
    if(!$models)throw new RuntimeException('Nenhum modelo da chave correspondeu ao catálogo gratuito NVIDIA; a lista anterior foi preservada.');
    $db->beginTransaction();
    try {
        $clear=$db->prepare('UPDATE provider_models SET free_catalog=0 WHERE provider_id=?');$clear->execute([$providerId]);
        $insert=$db->prepare('INSERT INTO provider_models (provider_id,upstream_model,name,free_catalog) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE name=VALUES(name),free_catalog=1');
        foreach($models as $m)$insert->execute([$providerId,$m['upstream'],$m['name']]);
        $db->commit();
    } catch(Throwable $e) {$db->rollBack();throw $e;}
}
