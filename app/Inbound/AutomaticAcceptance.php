<?php
declare(strict_types=1);
namespace O8\Inbound;

use O8\Auth\{Access,Actor};

/** One bounded, opt-in acceptance per source worker tick. No external AI calls here. */
final class AutomaticAcceptance
{
    public function __construct(private \PDO $db,private string $root) {}

    public function runNext(): ?array
    {
        $name='o8.accept.'.substr(hash('sha256',(string)$this->db->query('SELECT DATABASE()')->fetchColumn()),0,40);
        $lock=$this->db->prepare('SELECT GET_LOCK(?,0)'); $lock->execute([$name]);
        if ((int)$lock->fetchColumn()!==1) return null;
        try {
            $s=$this->db->query("SELECT i.id,i.tenant_id,i.revision,si.source_id,s.revision AS source_revision,s.config_json,u.*,a.auth_version AS account_auth_version,a.must_change_password,i.id AS inbound_id,i.revision AS inbound_revision
                FROM inbound_items i JOIN source_items si ON si.tenant_id=i.tenant_id AND si.id=i.source_item_id
                JOIN import_sources s ON s.tenant_id=si.tenant_id AND s.id=si.source_id AND s.owner_id=i.owner_id
                JOIN users u ON u.tenant_id=i.tenant_id AND u.id=i.owner_id JOIN accounts a ON a.id=u.account_id
                JOIN tenants t ON t.id=i.tenant_id
                WHERE i.state='pending' AND i.inventory_status='pending' AND s.enabled=1 AND s.kind IN ('imap','webdav')
                AND JSON_EXTRACT(s.config_json,'$.acceptance.enabled')=true
                AND u.active=1 AND a.active=1 AND a.must_change_password=0 AND t.active=1
                AND i.ai_status NOT IN ('queued','running')
                AND NOT EXISTS (SELECT 1 FROM inbound_ai_job_items j WHERE j.tenant_id=i.tenant_id AND j.inbound_item_id=i.id AND j.status IN ('queued','running'))
                AND (i.acceptance_attempted_at IS NULL OR i.updated_at>i.acceptance_attempted_at OR i.acceptance_attempted_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))
                ORDER BY i.acceptance_attempted_at,i.id LIMIT 1");
            $row=$s->fetch(); if (!$row) return null;
            $actor=new Actor('tenant',$row); $id=(int)$row['inbound_id']; $revision=(int)$row['inbound_revision'];
            $policy=json_decode($row['config_json'],true,32,JSON_THROW_ON_ERROR)['acceptance'];
            $accept=new InboundAcceptance($this->db,$this->root);
            try {
                $proposal=$accept->proposal($actor,$id);
                if (!$proposal['batchEligible']) {
                    $message=mb_substr(implode(' ',$proposal['batchReasons']),0,1000);
                    $this->blocked($actor,$id,$revision,$message);
                    return ['id'=>$id,'status'=>'blocked','message'=>$message];
                }
                $document=$accept->acceptBatchItem($actor,$id,$revision,$proposal['proposalToken'],$policy,function() use($actor,$row,$id):void {
                    $s=$this->db->prepare('SELECT revision,enabled,config_json FROM import_sources WHERE tenant_id=? AND owner_id=? AND id=? FOR UPDATE');
                    $s->execute([$actor->tenantId(),$actor->id(),$row['source_id']]); $current=$s->fetch();
                    if (!$current || !$current['enabled'] || (int)$current['revision']!==(int)$row['source_revision'] || $current['config_json']!==$row['config_json']) throw new \RuntimeException('Quellenkonfiguration wurde geändert.');
                    $s=$this->db->prepare("UPDATE inbound_items SET acceptance_status='accepted',acceptance_message=NULL,acceptance_attempted_at=UTC_TIMESTAMP() WHERE tenant_id=? AND id=?"); $s->execute([$actor->tenantId(),$id]);
                    $this->audit($actor,$id,'inbound.auto_accepted');
                });
                return ['id'=>$id,'status'=>'accepted','documentId'=>$document];
            } catch (\Throwable $error) {
                // Never persist credentials, paths or raw exception/driver messages.
                $message='Automatische Übernahme fehlgeschlagen. Bitte Zielordner, Tags, Berechtigungen und Datei prüfen; manuelle Übernahme bleibt möglich.';
                $this->blocked($actor,$id,$revision,$message);
                return ['id'=>$id,'status'=>'blocked','message'=>$message];
            }
        } finally { $s=$this->db->prepare('SELECT RELEASE_LOCK(?)'); $s->execute([$name]); }
    }

    private function blocked(Actor $actor,int $id,int $revision,string $message): void
    {
        $this->db->beginTransaction();
        try {
            (new Access($this->db))->tenant($actor);
            $s=$this->db->prepare("UPDATE inbound_items SET acceptance_status='blocked',acceptance_message=?,acceptance_attempted_at=UTC_TIMESTAMP(),updated_at=updated_at WHERE tenant_id=? AND owner_id=? AND id=? AND revision=? AND state='pending'");
            $s->execute([$message,$actor->tenantId(),$actor->id(),$id,$revision]);
            if ($s->rowCount()) $this->audit($actor,$id,'inbound.auto_blocked',$message);
            $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }
    private function audit(Actor $actor,int $id,string $action,?string $message=null): void
    {
        $s=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id,details_json) VALUES (?,?,?,'inbound_item',?,?)");
        $s->execute([$actor->tenantId(),$actor->id(),$action,(string)$id,$message===null?null:json_encode(['message'=>$message],JSON_THROW_ON_ERROR)]);
    }
}
