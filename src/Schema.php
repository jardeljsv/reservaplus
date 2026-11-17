<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/** Additive workflow schema. Existing 0.6 requests are never rewritten here. */
final class Schema
{
    public const VERSION = 2;
    private static ?bool $ready = null;

    public static function definitions(): array
    {
        $id = 'INT UNSIGNED NOT NULL AUTO_INCREMENT';
        $uint = 'INT UNSIGNED NOT NULL DEFAULT 0';
        $date = 'DATETIME DEFAULT NULL';
        $text = 'LONGTEXT DEFAULT NULL';
        $timestamps = ['date_creation' => $date, 'date_mod' => $date];
        return [
            'approvaldefaults' => [
                'columns' => ['id'=>$id,'entities_id'=>$uint,'kind'=>"VARCHAR(24) NOT NULL",'groups_json'=>$text,'revision'=>'INT UNSIGNED NOT NULL DEFAULT 1','users_id_actor'=>$uint] + $timestamps,
                'keys' => ['PRIMARY KEY (`id`)','UNIQUE KEY `entity_kind` (`entities_id`,`kind`)'],
            ],
            'approvaldefaultchanges' => [
                'columns' => ['id'=>$id,'approvaldefaults_id'=>$uint,'entities_id'=>$uint,'kind'=>"VARCHAR(24) NOT NULL",'users_id_actor'=>$uint,'revision'=>$uint,'before_json'=>$text,'after_json'=>$text,'date_creation'=>$date],
                'keys' => ['PRIMARY KEY (`id`)','KEY `default_revision` (`approvaldefaults_id`,`revision`)'],
            ],
            'sectors' => [
                'columns' => ['id'=>$id,'name'=>"VARCHAR(255) NOT NULL",'code'=>"VARCHAR(64) NOT NULL",'entities_id'=>$uint,'parent_id'=>$uint,'is_active'=>'TINYINT NOT NULL DEFAULT 1'] + $timestamps,
                'keys' => ['PRIMARY KEY (`id`)','UNIQUE KEY `entity_code` (`entities_id`,`code`)','KEY `entity` (`entities_id`)'],
            ],
            'resources' => [
                'columns' => [
                    'id'=>$id,'name'=>"VARCHAR(255) NOT NULL",'entities_id'=>$uint,'is_recursive'=>'TINYINT NOT NULL DEFAULT 0','is_deleted'=>'TINYINT NOT NULL DEFAULT 0','locations_id'=>$uint,'states_id'=>$uint,'comment'=>$text,
                    'kind'=>"VARCHAR(24) NOT NULL DEFAULT 'equipment'",'publication'=>"VARCHAR(24) NOT NULL DEFAULT 'draft'",'operational_state'=>"VARCHAR(24) NOT NULL DEFAULT 'available'",'config_revision'=>'INT UNSIGNED NOT NULL DEFAULT 1',
                    'sectors_id'=>$uint,'groups_id_ticket'=>$uint,'groups_id_manage'=>$uint,'groups_id_approve'=>$uint,'custodian_users_id'=>$uint,'default_assignee_users_id'=>$uint,
                    'native_itemtype'=>'VARCHAR(255) DEFAULT NULL','native_items_id'=>'INT UNSIGNED DEFAULT NULL','reservationitems_id'=>$uint,
                    'serial'=>"VARCHAR(255) NOT NULL DEFAULT ''",'inventory_number'=>"VARCHAR(255) NOT NULL DEFAULT ''",'manufacturer'=>"VARCHAR(255) NOT NULL DEFAULT ''",'model'=>"VARCHAR(255) NOT NULL DEFAULT ''",
                    'building'=>"VARCHAR(255) NOT NULL DEFAULT ''",'floor'=>"VARCHAR(64) NOT NULL DEFAULT ''",'room_number'=>"VARCHAR(64) NOT NULL DEFAULT ''",'wing'=>"VARCHAR(255) NOT NULL DEFAULT ''",'address'=>$text,
                    'latitude'=>'DECIMAL(10,7) DEFAULT NULL','longitude'=>'DECIMAL(10,7) DEFAULT NULL','capacity'=>'INT UNSIGNED DEFAULT NULL','area_m2'=>'DECIMAL(12,2) DEFAULT NULL',
                    'directions'=>$text,'features'=>$text,'accessories_json'=>$text,'policy_json'=>$text,
                ] + $timestamps,
                'keys'=>['PRIMARY KEY (`id`)','UNIQUE KEY `native_identity` (`native_itemtype`,`native_items_id`)','KEY `entity_publication` (`entities_id`,`publication`)','KEY `manager` (`groups_id_manage`)','KEY `calendar_identity` (`reservationitems_id`)'],
            ],
            'resourcechanges'=>[
                'columns'=>['id'=>$id,'resources_id'=>$uint,'users_id_actor'=>$uint,'revision'=>$uint,'before_json'=>$text,'after_json'=>$text,'date_creation'=>$date],
                'keys'=>['PRIMARY KEY (`id`)','KEY `resource_revision` (`resources_id`,`revision`)'],
            ],
            'bookings'=>[
                'columns'=>['id'=>$id,'entities_id'=>$uint,'users_id_requester'=>$uint,'users_id_borrower'=>$uint,'tickets_id'=>'INT UNSIGNED DEFAULT NULL','begin'=>$date,'end'=>$date,'status'=>"VARCHAR(32) NOT NULL DEFAULT 'submitted'",'revision'=>'INT UNSIGNED NOT NULL DEFAULT 1','scope_revision'=>'INT UNSIGNED NOT NULL DEFAULT 1','approved_revision'=>$uint,'people_count'=>'INT UNSIGNED NOT NULL DEFAULT 1','purpose'=>$text,'borrower_snapshot_json'=>$text,'policy_snapshot_json'=>$text,'operation_key'=>'CHAR(64) NOT NULL','integration_status'=>"VARCHAR(32) NOT NULL DEFAULT 'synced'",'integration_error'=>$text,'hold_expires_at'=>$date] + $timestamps,
                'keys'=>['PRIMARY KEY (`id`)','UNIQUE KEY `ticket` (`tickets_id`)','UNIQUE KEY `operation_key` (`operation_key`)','KEY `entity_status` (`entities_id`,`status`)','KEY `borrower` (`users_id_borrower`)'],
            ],
            'bookinglines'=>[
                'columns'=>['id'=>$id,'bookings_id'=>$uint,'resources_id'=>$uint,'reservationitems_id'=>$uint,'reservations_id'=>'INT UNSIGNED DEFAULT NULL','state'=>"VARCHAR(32) NOT NULL DEFAULT 'requested'",'calendar_mode'=>"VARCHAR(32) NOT NULL DEFAULT 'plugin_only'",'snapshot_json'=>$text,'accessories_json'=>$text,'issued_at'=>$date,'received_at'=>$date,'inspection_at'=>$date,'inspector_users_id'=>$uint,'inspection_json'=>$text],
                'keys'=>['PRIMARY KEY (`id`)','UNIQUE KEY `booking_resource` (`bookings_id`,`resources_id`)','KEY `resource_state` (`resources_id`,`state`)','KEY `native_reservation` (`reservations_id`)'],
            ],
            'bookingevents'=>[
                'columns'=>['id'=>$id,'bookings_id'=>$uint,'lines_id'=>$uint,'users_id_actor'=>$uint,'type'=>'VARCHAR(64) NOT NULL','occurred_at'=>$date,'recorded_at'=>$date,'payload_json'=>$text],
                'keys'=>['PRIMARY KEY (`id`)','KEY `booking_event` (`bookings_id`,`id`)'],
            ],
            'bookingcommands'=>[
                'columns'=>['id'=>$id,'bookings_id'=>$uint,'users_id_actor'=>$uint,'operation_key'=>'CHAR(64) NOT NULL','action'=>'VARCHAR(32) NOT NULL','result_json'=>$text,'date_creation'=>$date],
                'keys'=>['PRIMARY KEY (`id`)','UNIQUE KEY `operation_key` (`operation_key`)'],
            ],
            'bookingapprovals'=>[
                'columns'=>['id'=>$id,'bookings_id'=>$uint,'lines_id'=>$uint,'ticketvalidations_id'=>'INT UNSIGNED DEFAULT NULL','target_itemtype'=>'VARCHAR(255) NOT NULL','target_id'=>$uint,'status'=>"VARCHAR(32) NOT NULL DEFAULT 'pending'",'users_id_decider'=>$uint,'date_mod'=>$date],
                'keys'=>['PRIMARY KEY (`id`)','UNIQUE KEY `target` (`bookings_id`,`lines_id`,`target_itemtype`,`target_id`)'],
            ],
            'bookingoutbox'=>[
                'columns'=>['id'=>$id,'bookings_id'=>$uint,'event_key'=>'CHAR(64) NOT NULL','kind'=>'VARCHAR(32) NOT NULL','payload_json'=>$text,'status'=>"VARCHAR(16) NOT NULL DEFAULT 'pending'",'attempts'=>$uint,'last_error'=>$text,'next_attempt_at'=>$date] + $timestamps,
                'keys'=>['PRIMARY KEY (`id`)','UNIQUE KEY `event_key` (`event_key`)','KEY `pending` (`status`,`next_attempt_at`)'],
            ],
            'bookingapprovalhistory'=>[
                'columns'=>['id'=>$id,'bookings_id'=>$uint,'lines_id'=>$uint,'ticketvalidations_id'=>'INT UNSIGNED NOT NULL','status'=>'VARCHAR(32) NOT NULL','users_id_decider'=>$uint,'date_mod'=>$date],
                'keys'=>['PRIMARY KEY (`id`)','UNIQUE KEY `native_validation` (`ticketvalidations_id`)','KEY `booking_history` (`bookings_id`,`id`)'],
            ],
            'agreements'=>[
                'columns'=>['id'=>$id,'bookings_id'=>$uint,'entities_id'=>$uint,'revision'=>$uint,'purpose'=>'VARCHAR(32) NOT NULL','status'=>'VARCHAR(32) NOT NULL','source_path'=>'VARCHAR(255) NOT NULL','source_sha256'=>'CHAR(64) NOT NULL','final_path'=>'VARCHAR(255) DEFAULT NULL','final_sha256'=>'CHAR(64) DEFAULT NULL','evidence_path'=>'VARCHAR(255) DEFAULT NULL','evidence_sha256'=>'CHAR(64) DEFAULT NULL','snapshot_json'=>$text,'signers_json'=>$text,'consent_text'=>'TEXT NOT NULL','consent_version'=>'VARCHAR(32) NOT NULL','template_version'=>'VARCHAR(32) NOT NULL','verification_token'=>'CHAR(64) NOT NULL','source_documents_id'=>$uint,'final_documents_id'=>$uint,'attachment_status'=>"VARCHAR(32) NOT NULL DEFAULT 'pending'",'last_error'=>$text,'completed_at'=>$date] + $timestamps,
                'keys'=>['PRIMARY KEY (`id`)','UNIQUE KEY `verification_token` (`verification_token`)','KEY `booking_purpose_revision` (`bookings_id`,`purpose`,`revision`)'],
            ],
            'acceptances'=>[
                'columns'=>['id'=>$id,'agreements_id'=>$uint,'users_id'=>$uint,'roles_json'=>'TEXT NOT NULL','consent_text'=>'TEXT NOT NULL','consent_sha256'=>'CHAR(64) NOT NULL','source_sha256'=>'CHAR(64) NOT NULL','booking_revision'=>$uint,'auth_method'=>'VARCHAR(64) NOT NULL','identity_json'=>$text,'accepted_at'=>$date,'event_utc'=>'VARCHAR(32) NOT NULL','operation_key'=>'VARCHAR(128) NOT NULL'],
                'keys'=>['PRIMARY KEY (`id`)','UNIQUE KEY `signer` (`agreements_id`,`users_id`)','UNIQUE KEY `operation_key` (`agreements_id`,`operation_key`)'],
            ],
            'signing_challenges'=>[
                'columns'=>['id'=>$id,'agreements_id'=>$uint,'users_id'=>$uint,'challenge_hash'=>'CHAR(64) NOT NULL','session_hash'=>'CHAR(64) NOT NULL','expires_at'=>$date,'consumed_at'=>$date,'source_sha256'=>'CHAR(64) NOT NULL','revision'=>$uint,'consent_sha256'=>'CHAR(64) NOT NULL','date_creation'=>$date],
                'keys'=>['PRIMARY KEY (`id`)','UNIQUE KEY `challenge_hash` (`challenge_hash`)','KEY `signer_expiry` (`users_id`,`expires_at`)'],
            ],
        ];
    }

    public static function install(): bool
    {
        global $DB;
        self::$ready=null;
        foreach (self::definitions() as $suffix=>$definition) {
            $table='glpi_plugin_reservaplus_'.$suffix;
            $parts=[];
            foreach ($definition['columns'] as $column=>$sql) {
                $parts[]='`'.$column.'` '.$sql;
            }
            $query='CREATE TABLE IF NOT EXISTS `'.$table.'` ('.implode(',',array_merge($parts,$definition['keys'])).') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            if (!$DB->doQuery($query)) {
                return false;
            }
            // Reruns add missing columns only; no data deletion or narrowing conversion.
            foreach ($definition['columns'] as $column=>$sql) {
                if (!$DB->fieldExists($table,$column) && !$DB->doQuery('ALTER TABLE `'.$table.'` ADD COLUMN `'.$column.'` '.$sql)) {
                    return false;
                }
            }
        }
        return true;
    }

    public static function ready(): bool
    {
        global $DB;
        if (self::$ready!==null) { return self::$ready; }
        foreach (array_keys(self::definitions()) as $suffix) {
            if (!$DB->tableExists('glpi_plugin_reservaplus_'.$suffix)) {
                return self::$ready=false;
            }
        }
        return self::$ready=true;
    }
}
