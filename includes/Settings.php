<?php
namespace ParcelBackups;
if (!defined('ABSPATH')) { exit; }

final class Settings {
    public const OPTION = 'parcel_settings';
    public static function defaults(): array {
        return ['chat_id'=>'','thread_id'=>0,'api_mode'=>'cloud','api_url'=>'https://api.telegram.org',
            'proxy_url'=>'','proxy_username'=>'','dns_servers'=>'','doh_url'=>'','schedule'=>'daily',
            'schedule_time'=>'03:00','schedule_day'=>0,'backup_type'=>'full','retention_count'=>3,
            'retention_days'=>14,'quota_mb'=>10240,'reserve_mb'=>512,'max_backup_mb'=>5120,
            'part_mb'=>40,'excludes'=>"wp-content/cache\nwp-content/upgrade\n.git\nnode_modules",
            'delete_on_uninstall'=>false,'consent'=>false];
    }
    public static function install(): void { add_option(self::OPTION, self::defaults(), '', false); }
    public static function get(): array {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $out = array_intersect_key(array_merge(self::defaults(), $stored), self::defaults());
        foreach (['token','password','proxy_password'] as $key) { $out['has_'.$key] = !empty($stored['_'.$key]); }
        return $out;
    }
    public static function public_data(): array { return self::get(); }
    public static function secret(string $key): string {
        if (!in_array($key, ['token','password','proxy_password'], true)) { throw new \InvalidArgumentException('Unknown secret.'); }
        $stored = get_option(self::OPTION, []);
        return empty($stored['_'.$key]) ? '' : self::decrypt($stored['_'.$key]);
    }
    private static function encryption_key(): string { return hash('sha256', wp_salt('auth') . wp_salt('secure_auth') . 'parcel-v1', true); }
    public static function encrypt(string $value): string {
        if (!function_exists('openssl_encrypt')) { throw new \RuntimeException('The PHP OpenSSL extension is required.'); }
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($value, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag, 'parcel-settings-v1');
        if ($cipher === false) { throw new \RuntimeException('Could not encrypt settings.'); }
        return 'v1:' . base64_encode($iv.$tag.$cipher);
    }
    public static function decrypt(string $value): string {
        $raw = strpos($value,'v1:') === 0 ? base64_decode(substr($value,3),true) : false;
        if ($raw === false || strlen($raw)<28) { throw new \RuntimeException('Stored secrets are corrupted. Enter them again.'); }
        $plain = openssl_decrypt(substr($raw,28),'aes-256-gcm',self::encryption_key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'parcel-settings-v1');
        if ($plain === false) { throw new \RuntimeException('The WordPress salts have changed. Enter the bot token and archive password again. Older archives still require their original password.'); }
        return $plain;
    }
    public static function save(array $input): array {
        $old = get_option(self::OPTION, []);
        $out = array_merge(self::defaults(), is_array($old) ? $old : []);
        foreach (self::defaults() as $key=>$default) {
            if (!array_key_exists($key,$input)) { continue; }
            if (is_bool($default)) { $out[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN); }
            elseif (is_int($default)) {
                if (filter_var($input[$key], FILTER_VALIDATE_INT) === false) { throw new \InvalidArgumentException('An integer is required: '.$key); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is data for JSON or CLI; the UI escapes at its output boundary.
                $out[$key] = (int)$input[$key];
            } else {
                if (!is_string($input[$key])) { throw new \InvalidArgumentException('Invalid value: '.$key); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is data for JSON or CLI; the UI escapes at its output boundary.
                $out[$key] = trim($input[$key]);
            }
        }
        foreach (['api_mode'=>['cloud','local'],'schedule'=>['off','hourly','daily','weekly'],'backup_type'=>['full','database','files']] as $key=>$allowed) {
            if (!in_array($out[$key],$allowed,true)) { throw new \InvalidArgumentException('Invalid value: '.$key); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is data for JSON or CLI; the UI escapes at its output boundary.
        }
        if ($out['chat_id'] !== '' && !preg_match('/^(?:-?[0-9]{1,20}|@[a-zA-Z][a-zA-Z0-9_]{4,63})$/D',$out['chat_id'])) { throw new \InvalidArgumentException('Chat ID must be a numeric ID or a channel @username.'); }
        if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$out['schedule_time'])) { throw new \InvalidArgumentException('Time must use the HH:MM format.'); }
        $ranges=['thread_id'=>[0,2147483647],'schedule_day'=>[0,6],'retention_count'=>[1,100],'retention_days'=>[1,3650],
            'quota_mb'=>[128,10485760],'reserve_mb'=>[64,1048576],'max_backup_mb'=>[16,10485760],'part_mb'=>[2,$out['api_mode']==='local'?1900:45]];
        foreach ($ranges as $key=>[$min,$max]) { if ($out[$key]<$min || $out[$key]>$max) { throw new \InvalidArgumentException("$key: must be between $min and $max."); } } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is data for JSON or CLI; the UI escapes at its output boundary.
        if ($out['max_backup_mb']>$out['quota_mb']) { throw new \InvalidArgumentException('The limit for one backup cannot exceed the total storage quota.'); }
        if ($out['api_mode']==='cloud') { $out['api_url']='https://api.telegram.org'; }
        else { $out['api_url']=self::url($out['api_url'],['https','http'], 'Bot API URL'); }
        if ($out['proxy_url']!=='') { $out['proxy_url']=self::url($out['proxy_url'],['http','https','socks5','socks5h'],'Proxy'); }
        if ($out['doh_url']!=='') { $out['doh_url']=self::url($out['doh_url'],['https'],'DNS-over-HTTPS'); }
        if ($out['dns_servers']!=='' && $out['doh_url']!=='') { throw new \InvalidArgumentException('Choose either custom DNS servers or DNS-over-HTTPS.'); }
        if ($out['dns_servers']!=='') {
            $servers=preg_split('/[\s,]+/', $out['dns_servers'], -1, PREG_SPLIT_NO_EMPTY);
            if (count($servers)>4) { throw new \InvalidArgumentException('Specify no more than four DNS servers.'); }
            foreach ($servers as $server) {
                $address=$server; $port=null;
                if (preg_match('/^\[([^\]]+)\](?::([0-9]{1,5}))?$/D',$server,$match)) { $address=$match[1]; $port=$match[2]??null; }
                elseif (preg_match('/^([0-9.]+):([0-9]{1,5})$/D',$server,$match)) { $address=$match[1]; $port=$match[2]; }
                if (!filter_var($address,FILTER_VALIDATE_IP) || ($port!==null && ((int)$port<1 || (int)$port>65535))) { throw new \InvalidArgumentException('DNS: comma-separated IP addresses with optional ports. Enclose IPv6 addresses in brackets when specifying a port.'); }
            }
            $out['dns_servers']=implode(',',$servers);
        }
        if (strlen($out['proxy_username'])>128 || preg_match('/[\x00-\x1f]/',$out['proxy_username'])) { throw new \InvalidArgumentException('Invalid proxy username.'); }
        $lines=preg_split('/\r?\n/',$out['excludes']); $safe=[];
        if (count($lines)>100) { throw new \InvalidArgumentException('Specify no more than 100 exclusions.'); }
        foreach ($lines as $line) {
            $line=trim(str_replace('\\','/',$line)," \t/");
            if ($line==='') { continue; }
            if (strlen($line)>250 || preg_match('#(^|/)\.\.(/|$)|[\x00-\x1f]#',$line) || strpos($line,':')!==false) { throw new \InvalidArgumentException('Exclusions must be relative paths without "..".'); }
            $safe[]=$line;
        }
        $out['excludes']=implode("\n",array_unique($safe));
        foreach (['token','password','proxy_password'] as $key) {
            if (!empty($input['clear_'.$key])) { unset($out['_'.$key]); }
            if (isset($input[$key]) && $input[$key] !== '') {
                if (!is_string($input[$key]) || strlen($input[$key])>512) { throw new \InvalidArgumentException('The secret is invalid or too long.'); }
                $value=$key==='password'?$input[$key]:trim($input[$key]);
                if ($key==='token' && !preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{20,200}$/D',$value)) { throw new \InvalidArgumentException('A bot token looks like 123456789:ABC… Get yours from @BotFather.'); }
                if ($key==='password' && strlen($value)<12) { throw new \InvalidArgumentException('The archive password must contain at least 12 characters. Keep it separately from the website.'); }
                $out['_'.$key]=self::encrypt($value);
            }
        }
        update_option(self::OPTION,$out,false);
        delete_option('parcel_connection');
        Scheduler::reschedule();
        return self::public_data();
    }
    private static function url(string $value, array $schemes, string $label): string {
        $p=wp_parse_url($value);
        if (!$p || empty($p['host']) || empty($p['scheme']) || !in_array(strtolower($p['scheme']),$schemes,true) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || preg_match('/[\s\x00-\x1f]/',$value)) {
            throw new \InvalidArgumentException($label.': enter a complete URL without a username or password.'); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception is data for JSON or CLI; the UI escapes at its output boundary.
        }
        if ($label==='Bot API URL' && (isset($p['query']) || (!empty($p['path']) && $p['path']!=='/'))) { throw new \InvalidArgumentException('Bot API: specify only the scheme, host and port, without a path.'); }
        if ($label==='Proxy' && (isset($p['query']) || (!empty($p['path']) && $p['path']!=='/'))) { throw new \InvalidArgumentException('Proxy: specify only the scheme, host and port.'); }
        return rtrim($value,'/');
    }
}
