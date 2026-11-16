<?php
namespace GlpiPlugin\Telegram;

use RuntimeException;

final class AgentRegistry
{
    private const DEFAULT_KEY = 'ad_default_agent_id';
    private const LEGACY_ID = 'SVRSNGPRTG-LAB';

    public static function migrateLegacyAgent(): bool
    {
        global $DB;
        $q = $DB->doQuery("SELECT config_value FROM glpi_plugin_telegram_configs WHERE config_key = 'ad_agent_hmac_secret' LIMIT 1");
        $row = $q ? $DB->fetchAssoc($q) : false;
        if (!$row || empty($row['config_value'])) return true;
        $id = self::LEGACY_ID;
        $key = $DB->escape('ad_agent_hmac_' . hash('sha256', $id));
        $cipher = $DB->escape((string)$row['config_value']);
        if ($DB->doQuery("INSERT INTO glpi_plugin_telegram_configs (config_key,config_value) VALUES ('{$key}','{$cipher}') ON DUPLICATE KEY UPDATE config_value=IF(config_value IS NULL OR config_value='',VALUES(config_value),config_value)") === false) return false;
        if ($DB->doQuery("INSERT INTO glpi_plugin_telegram_ad_agents (agent_id,name,domain_controller,status,is_active) VALUES ('{$id}','Agente AD laboratorio','SVRDC01.Songa.local','pending',1) ON DUPLICATE KEY UPDATE agent_id=VALUES(agent_id)") === false) return false;
        $d=$DB->doQuery("SELECT id FROM glpi_plugin_telegram_configs WHERE config_key='".self::DEFAULT_KEY."' LIMIT 1");
        if (!$d || $DB->numrows($d)===0) $DB->doQuery("INSERT INTO glpi_plugin_telegram_configs (config_key,config_value) VALUES ('".self::DEFAULT_KEY."','{$id}')");
        $DB->doQuery("UPDATE glpi_plugin_telegram_ad_requests SET target_agent_id=COALESCE(agent_id,'{$id}') WHERE target_agent_id IS NULL AND status IN ('queued','claimed')");
        return true;
    }

    public static function register(string $id,string $name,string $dc): string
    {
        global $DB;
        $id=trim($id);$name=trim($name);$dc=trim($dc);
        if(!preg_match('/^[A-Za-z0-9._-]{1,64}$/',$id)||$name===''||strlen($name)>120||!preg_match('/^[A-Za-z0-9.-]{1,253}$/',$dc)) throw new RuntimeException('Identificador, nombre o controlador de dominio no válido.');
        $sid=$DB->escape($id);$sn=$DB->escape($name);$sdc=$DB->escape($dc);
        if($DB->doQuery("INSERT INTO glpi_plugin_telegram_ad_agents (agent_id,name,domain_controller,status,is_active) VALUES ('{$sid}','{$sn}','{$sdc}','pending',1)")===false) throw new RuntimeException('No se pudo registrar el agente; verifique que el identificador no exista.');
        try{$secret=SecretStore::generateAgentSecretFor($id);}catch(\Throwable $e){$DB->doQuery("DELETE FROM glpi_plugin_telegram_ad_agents WHERE agent_id='{$sid}'");throw $e;}
        if(self::getDefaultAgentId(false)===null) self::setDefault($id);
        return $secret;
    }

    public static function getApprovedSecret(string $id): ?string
    {
        global $DB;
        if(!preg_match('/^[A-Za-z0-9._-]{1,64}$/',$id))return null;
        $sid=$DB->escape($id);$q=$DB->doQuery("SELECT agent_id FROM glpi_plugin_telegram_ad_agents WHERE agent_id='{$sid}' AND status='approved' AND is_active=1 LIMIT 1");
        if(!$q||!$DB->fetchAssoc($q))return null;
        return SecretStore::getAgentSecretFor($id);
    }

    public static function approve(string $id): void
    {
        global $DB;$sid=$DB->escape($id);
        if($DB->doQuery("UPDATE glpi_plugin_telegram_ad_agents SET status='approved',is_active=1 WHERE agent_id='{$sid}' AND status IN ('pending','approved')")===false||$DB->affectedRows()!==1)throw new RuntimeException('No se pudo aprobar el agente.');
    }

    public static function revoke(string $id): void
    {
        global $DB;$sid=$DB->escape($id);
        if($DB->doQuery("UPDATE glpi_plugin_telegram_ad_agents SET status='revoked',is_active=0 WHERE agent_id='{$sid}' AND status<>'revoked'")===false||$DB->affectedRows()!==1)throw new RuntimeException('No se pudo revocar el agente.');
    }

    public static function setDefault(string $id): void
    {
        global $DB;
        if(!preg_match('/^[A-Za-z0-9._-]{1,64}$/',$id))throw new RuntimeException('Identificador no válido.');
        $sid=$DB->escape($id);$q=$DB->doQuery("SELECT agent_id FROM glpi_plugin_telegram_ad_agents WHERE agent_id='{$sid}' AND status<>'revoked' AND is_active=1 LIMIT 1");
        if(!$q||!$DB->fetchAssoc($q))throw new RuntimeException('El agente no existe o está revocado.');
        $v=$DB->escape($id);
        if($DB->doQuery("INSERT INTO glpi_plugin_telegram_configs (config_key,config_value) VALUES ('".self::DEFAULT_KEY."','{$v}') ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)")===false)throw new RuntimeException('No se pudo configurar el agente predeterminado.');
    }

    public static function getDefaultAgentId(bool $requireApproved=true): ?string
    {
        global $DB;$q=$DB->doQuery("SELECT c.config_value,a.status,a.is_active FROM glpi_plugin_telegram_configs c LEFT JOIN glpi_plugin_telegram_ad_agents a ON a.agent_id=c.config_value WHERE c.config_key='".self::DEFAULT_KEY."' LIMIT 1");
        $r=$q?$DB->fetchAssoc($q):false;
        if(!$r||empty($r['config_value'])||(int)($r['is_active']??0)!==1||($r['status']??'')==='revoked'||($requireApproved&&($r['status']??'')!=='approved'))return null;
        return (string)$r['config_value'];
    }

    public static function listAgents(): array
    {
        global $DB;$q=$DB->doQuery("SELECT agent_id,name,domain_controller,status,is_active,date_creation FROM glpi_plugin_telegram_ad_agents ORDER BY date_creation,agent_id");
        $rows=[];if($q)while($r=$DB->fetchAssoc($q))$rows[]=$r;return $rows;
    }
}
