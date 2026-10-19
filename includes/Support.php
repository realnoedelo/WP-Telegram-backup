<?php
namespace ParcelBackups;
if (!defined('ABSPATH')) { exit; }
final class Support {
    public static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
    public static function redact(string $text): string {
        $text=preg_replace('~(?:bot)?[0-9]{5,20}:[A-Za-z0-9_-]{20,200}~','[token hidden]',$text);
        $text=preg_replace('~(https?|socks5h?)://[^\s/@]+:[^\s/@]+@~','$1://[credentials hidden]@',$text);
        return substr((string)$text,0,2000);
    }
    public static function log(string $id,string $level,string $message,array $context=[]): void {
        try {
            $path=Storage::valid_id($id)?Storage::job_dir($id).'/events.jsonl':Storage::root().'/events.jsonl';
            $clean=[];
            foreach ($context as $key=>$value) {
                if (preg_match('/token|password|secret|authorization/i',(string)$key)) { $clean[$key]='[hidden]'; }
                elseif (is_scalar($value)||$value===null) { $clean[$key]=self::redact((string)$value); }
            }
            $line=wp_json_encode(['time'=>self::now(),'level'=>sanitize_key($level),'message'=>self::redact($message),'context'=>$clean],JSON_UNESCAPED_UNICODE)."\n";
            if (is_file($path) && filesize($path)>1048576) {
                $lines=file($path,FILE_IGNORE_NEW_LINES) ?: [];
                file_put_contents($path,implode("\n",array_slice($lines,-200))."\n",LOCK_EX);
            }
            file_put_contents($path,$line,FILE_APPEND|LOCK_EX); @chmod($path,0600);
        } catch (\Throwable $e) { /* Logging must not expose sensitive paths to PHP logs or abort recovery. */ }
    }
    public static function logs(string $id=''): array {
        $path=$id!==''?Storage::job_dir($id).'/events.jsonl':Storage::root().'/events.jsonl';
        if (!is_file($path)) { return []; }
        $lines=file($path,FILE_IGNORE_NEW_LINES) ?: [];
        return array_values(array_filter(array_map(static fn($l)=>json_decode($l,true),array_slice($lines,-200)),'is_array'));
    }
    public static function requirements(): array {
        $items=[];
        $add=static function($id,$label,$ok,$detail) use (&$items) { $items[]=['id'=>$id,'label'=>$label,'ok'=>$ok,'detail'=>$detail]; };
        $add('php','PHP 8.1+, 64 bit',PHP_VERSION_ID>=80100 && PHP_INT_SIZE>=8,PHP_VERSION);
        $add('openssl','OpenSSL encryption',function_exists('openssl_encrypt') && in_array('aes-256-gcm',openssl_get_cipher_methods(),true),'AES-256-GCM');
        $add('curl','cURL streaming uploads',extension_loaded('curl'),'Files are streamed from disk without loading the whole file into memory.');
        $add('zlib','zlib compression',function_exists('gzdeflate'),'Compression in small blocks.');
        $add('site','Single-site WordPress',!is_multisite(),'Multisite is not supported in version 1.0.');
        $root=realpath(ABSPATH); $content=realpath(WP_CONTENT_DIR);
        $add('paths','wp-content within the site root',$root && $content && strpos($content,$root.DIRECTORY_SEPARATOR)===0,'An external or symlinked wp-content directory requires a separate backup.');
        try { $u=Storage::usage(); $add('storage','Private storage',true,$u['path']); }
        catch (\Throwable $e) { $add('storage','Private storage',false,self::redact($e->getMessage())); }
        return $items;
    }
}
