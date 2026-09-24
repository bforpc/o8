<?php
declare(strict_types=1);
require __DIR__ . '/../../app/foundation.php';
if (!$db) { http_response_code(503); echo json_encode(['success'=>false,'error'=>'Datenbank nicht verbunden']); exit; }
if (!$actor || $actor->kind!=='tenant' || $actor->row['role']!=='admin') { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Keine Berechtigung']); exit; }

header('Content-Type: application/json; charset=utf-8');

try {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'booking_summary') {
        $filter = [
            'dateFrom' => $_GET['dateFrom'] ?? '',
            'dateTo' => $_GET['dateTo'] ?? '',
            'accounts' => array_map('intval', $_GET['accounts'] ?? []),
            'documentTypes' => $_GET['documentTypes'] ?? [],
            'tag' => $_GET['tag'] ?? null,
            'notTag' => $_GET['notTag'] ?? null,
            'owner' => $_GET['owner'] ?? null,
        ];
        
        $evaluation = new O8\Documents\Evaluation($db);
        $result = $evaluation->bookingSummary($actor, $filter);
        
        echo json_encode(['success' => true, 'data' => $result]);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Unbekannte Aktion']);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}