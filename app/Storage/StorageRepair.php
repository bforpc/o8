<?php
declare(strict_types=1);
namespace O8\Storage;

use O8\Auth\{Access,Actor};

/** Mandanten-Admin kann Storage-Identität bei Pfadänderung neu prüfen lassen. */
final class StorageRepair
{
    public function __construct(private \PDO $db, private string $root) {}

    /**
     * Prüft und repariert die Storage-Identität für den aktuellen Mandanten.
     * Der Mandanten-Admin kann dies verwenden, wenn sich der Storage-Pfad außerhalb bewegt hat.
     */
    public function repair(Actor $actor): array
    {
        (new Access($this->db))->tenant($actor);
        $actor->requireAdmin();
        
        $location = (new Storage($this->db, $this->root))->location($actor);
        if (!$location) {
            return ['success' => false, 'error' => 'Keine Storage-Zuordnung gefunden. Bitte den Betreiber kontaktieren.'];
        }

        $base = rtrim(trim($location['root_path']), '/');
        $uuid = $location['public_id'];
        $target = $base . '/' . $uuid;
        $marker = $target . '/.o8-storage';

        // Basispfad prüfen
        if (!is_dir($base) || !is_readable($base) || !is_executable($base)) {
            return ['success' => false, 'error' => 'Storage-Basispfad nicht verfügbar. Pfad: ' . $base];
        }

        // Zielverzeichnis prüfen
        if (!is_dir($target) || !is_readable($target) || !is_executable($target)) {
            return ['success' => false, 'error' => 'Mandantenverzeichnis nicht verfügbar. Pfad: ' . $target];
        }

        // Symlinks prüfen
        if (is_link($base) || is_link($target) || is_link($marker)) {
            return ['success' => false, 'error' => 'Storage-Pfade dürfen keine Symlinks enthalten.'];
        }

        // Device/Inode ermitteln
        clearstatcache(true);
        $b = stat($base);
        $t = stat($target);
        if (!$b || !$t) {
            return ['success' => false, 'error' => 'Kann Storage-Statistik nicht lesen.'];
        }

        // Marker-Datei prüfen/erstellen
        $markerExists = is_file($marker);
        $markerContent = $markerExists ? @file_get_contents($marker) : false;
        
        if (!$markerExists || !is_string($markerContent) || strlen(trim($markerContent)) < 32) {
            // Marker neu erstellen
            $newMarker = bin2hex(random_bytes(32));
            if (@file_put_contents($marker, $newMarker, LOCK_EX) === false) {
                return ['success' => false, 'error' => 'Kann Storage-Markierung nicht schreiben.'];
            }
            @chmod($marker, 0640);
            $markerContent = $newMarker;
        } else {
            $newMarker = trim($markerContent);
        }

        // Identität aktualisieren
        $identity = [
            'base_dev' => $b['dev'],
            'base_ino' => $b['ino'],
            'tenant_dev' => $t['dev'],
            'tenant_ino' => $t['ino'],
            'marker' => $newMarker,
        ];

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE storage_locations SET identity_json=? WHERE tenant_id=? AND storage_key='main'");
            $stmt->execute([json_encode($identity, JSON_THROW_ON_ERROR), $actor->tenantId()]);
            
            $stmt = $this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id,details_json) VALUES (?,?,?,'storage','repair',?)");
            $stmt->execute([$actor->tenantId(), $actor->id(), 'storage.repaired', json_encode(['root_path' => $base], JSON_THROW_ON_ERROR)]);
            
            $this->db->commit();
            
            return [
                'success' => true, 
                'message' => 'Storage-Identität erfolgreich aktualisiert.',
                'details' => [
                    'base' => $base,
                    'target' => $target,
                    'base_dev' => $b['dev'],
                    'base_ino' => $b['ino'],
                    'tenant_dev' => $t['dev'],
                    'tenant_ino' => $t['ino'],
                ]
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => 'Datenbankfehler: ' . $e->getMessage()];
        }
    }

    /**
     * Prüft den aktuellen Storage-Status ohne Änderungen.
     */
    public function check(Actor $actor): array
    {
        (new Access($this->db))->tenant($actor);
        
        $location = (new Storage($this->db, $this->root))->location($actor);
        if (!$location) {
            return ['status' => 'missing', 'error' => 'Keine Storage-Zuordnung.'];
        }

        $base = rtrim(trim($location['root_path']), '/');
        $uuid = $location['public_id'];
        $target = $base . '/' . $uuid;
        $marker = $target . '/.o8-storage';
        $saved = json_decode($location['identity_json'] ?? 'null', true);

        $issues = [];
        
        if (!is_dir($base)) {
            $issues[] = 'Basispfad existiert nicht: ' . $base;
        }
        if (!is_dir($target)) {
            $issues[] = 'Mandantenverzeichnis existiert nicht: ' . $target;
        }
        if (is_link($base) || is_link($target)) {
            $issues[] = 'Symlinks erkannt (unsicher).';
        }
        if (!$saved) {
            $issues[] = 'identity_json fehlt in der Datenbank.';
        } elseif (is_file($marker)) {
            $markerContent = trim(@file_get_contents($marker) ?? '');
            if (!hash_equals($saved['marker'] ?? '', $markerContent)) {
                $issues[] = 'Marker-Datei stimmt nicht mit gespeicherter Identität überein.';
            }
        } else {
            $issues[] = 'Marker-Datei fehlt: ' . $marker;
        }

        if ($issues) {
            return ['status' => 'broken', 'issues' => $issues];
        }

        try {
            (new Storage($this->db, $this->root))->paths($location);
        } catch (\RuntimeException $e) {
            return ['status' => 'broken', 'issues' => [$e->getMessage()]];
        }

        return ['status' => 'ok', 'root_path' => $base, 'target' => $target];
    }
}
