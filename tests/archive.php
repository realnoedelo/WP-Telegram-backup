<?php
namespace ParcelBackups {
    if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
    define('ABSPATH', __DIR__);
    final class Storage { public static function ensure_space(int $n, string $id = ''): void {} }
    require dirname(__DIR__) . '/includes/Archive.php';
}
namespace {
    define('PARCEL_RESTORE_LIBRARY', true);
    require dirname(__DIR__) . '/tools/restore.php';
    use ParcelBackups\Archive;
    $base = sys_get_temp_dir() . '/parcel-archive-test-' . bin2hex(random_bytes(5)); mkdir($base, 0700);
    $password = 'correct horse 🔒 vault password'; $passed = 0;
    function check($ok, $message) { if (!$ok) { throw new \RuntimeException('ASSERT: ' . $message); } }
    function rejected(callable $fn, string $message) { global $passed; try { $fn(); } catch (\Throwable $e) { $passed++; echo "PASS $message: {$e->getMessage()}\n"; return; } throw new \RuntimeException('Expected rejection: ' . $message); }
    function copydir(string $a, string $b): void { mkdir($b, 0700); foreach (glob($a . '/*') as $f) { if (is_file($f)) { copy($f, $b . '/' . basename($f)); } } }
    function writer(string $dir, array &$state, string $type, array $meta = [], string $data = ''): void { global $password; Archive::append($dir, $state, $password, 1200000, 100000000, $type, $meta, $data); }
    function makeFile(string $dir, array &$state, int $entry, string $path, string $content): void {
        writer($dir, $state, 'file_begin', ['entry'=>$entry,'path'=>$path,'size'=>strlen($content),'mtime'=>1700000000,'mode'=>0644]);
        $chain = str_repeat('0',64);
        for ($i=0; $i<strlen($content); $i+=Archive::CHUNK) { $chunk = substr($content,$i,Archive::CHUNK); writer($dir,$state,'data',['entry'=>$entry,'offset'=>$i],$chunk); $chain=Archive::chain($chain,$chunk); }
        writer($dir,$state,'file_end',['entry'=>$entry,'bytes'=>strlen($content),'chain_sha256'=>$chain]);
    }
    $input = $base . '/input'; mkdir($input,0700); $s=Archive::initial('test-vault');
    writer($input,$s,'start',['id'=>'test-vault','type'=>'full']);
    writer($input,$s,'directory',['path'=>'files/папка','mode'=>0755,'mtime'=>1700000000]);
    $binary=random_bytes(3000123); $sql="CREATE TABLE x (b BLOB);\nINSERT INTO x VALUES (FROM_BASE64('AAH/'));\n";
    makeFile($input,$s,1,'database.sql',$sql);
    makeFile($input,$s,2,'files/папка/binary.bin',$binary);
    makeFile($input,$s,3,'files/empty.txt','');
    Archive::seal($input,$s,$password,1200000,100000000,['files'=>3,'directories'=>1,'bytes'=>strlen($sql)+strlen($binary)]);
    check(count($s['parts'])>=3,'multipart split');
    foreach($s['parts'] as $part) { check($part['bytes']<=1200000,'size cap'); check(hash_file('sha256',$input.'/'.$part['name'])===$part['sha256'],'hash'); }
    $report=(new ParcelRestore())->run($input,$base.'/restored',$password);
    check($report['verified']&&$report['files']===3,'report'); check(file_get_contents($base.'/restored/files/папка/binary.bin')===$binary,'binary fidelity');
    check(file_get_contents($base.'/restored/database.sql')===$sql,'SQL fidelity'); check(filesize($base.'/restored/files/empty.txt')===0,'zero file');
    check(!file_exists($base.'/restored/PARCEL-INCOMPLETE.txt'),'completion marker'); $passed++; echo "PASS multipart binary Unicode and empty-file roundtrip\n";
    rejected(fn()=>(new ParcelRestore())->run($input,$base.'/wrong','wrong password'),'wrong password');
    check(file_exists($base.'/wrong/PARCEL-INCOMPLETE.txt'),'failure marker preserved');
    rejected(fn()=>(new ParcelRestore())->run($input,$base.'/restored',$password),'existing nonempty destination');
    copydir($input,$base.'/corrupt'); $last=end($s['parts'])['name']; $p=$base.'/corrupt/'.$last; $v=file_get_contents($p); $v[strlen($v)-1]=chr(ord($v[strlen($v)-1])^1); file_put_contents($p,$v);
    rejected(fn()=>(new ParcelRestore())->run($base.'/corrupt',$base.'/corrupt-out',$password),'ciphertext corruption');
    copydir($input,$base.'/missing'); unlink($base.'/missing/'.$last);
    rejected(fn()=>(new ParcelRestore())->run($base.'/missing',$base.'/missing-out',$password),'missing last part');
    copydir($input,$base.'/gap'); unlink($base.'/gap/part-00002.pvlt');
    rejected(fn()=>(new ParcelRestore())->run($base.'/gap',$base.'/gap-out',$password),'missing middle part');
    copydir($input,$base.'/truncated'); $p=$base.'/truncated/'.$last; $h=fopen($p,'c+b'); ftruncate($h,filesize($p)-7); fclose($h);
    rejected(fn()=>(new ParcelRestore())->run($base.'/truncated',$base.'/truncated-out',$password),'truncated record');
    copydir($input,$base.'/trailing'); file_put_contents($base.'/trailing/'.$last,'extra',FILE_APPEND);
    rejected(fn()=>(new ParcelRestore())->run($base.'/trailing',$base.'/trailing-out',$password),'data after completion');
    copydir($input,$base.'/header'); $p=$base.'/header/part-00001.pvlt'; $v=file_get_contents($p); $v=str_replace('test-vault','fest-vault',$v); file_put_contents($p,$v);
    rejected(fn()=>(new ParcelRestore())->run($base.'/header',$base.'/header-out',$password),'altered header');
    $dir=$base.'/traversal';mkdir($dir,0700);$t=Archive::initial('bad');writer($dir,$t,'start',['id'=>'bad']);makeFile($dir,$t,1,'files/../../escape','danger');Archive::seal($dir,$t,$password,1200000,100000000,['files'=>1,'directories'=>0,'bytes'=>6]);
    rejected(fn()=>(new ParcelRestore())->run($dir,$base.'/traversal-out',$password),'authenticated traversal path');
    check(!file_exists($base.'/escape'),'no escaped write');
    $dir=$base.'/resume'; mkdir($dir,0700);$r=Archive::initial('resume');writer($dir,$r,'start',['id'=>'resume']); $checkpoint=$r;
    makeFile($dir,$r,1,'files/uncommitted.bin',random_bytes(2100000)); check(count(glob($dir.'/part-*.pvlt'))>1,'uncommitted new part');
    Archive::recover($dir,$checkpoint); $r=$checkpoint; check(count(glob($dir.'/part-*.pvlt'))===1,'orphan removed');
    makeFile($dir,$r,1,'files/committed.txt','checkpoint preserved'); Archive::seal($dir,$r,$password,1200000,100000000,['files'=>1,'directories'=>0,'bytes'=>20]);
    (new ParcelRestore())->run($dir,$base.'/resume-out',$password);check(file_get_contents($base.'/resume-out/files/committed.txt')==='checkpoint preserved','resume fidelity');$passed++;echo "PASS crash recovery truncates checkpoint and removes orphan part\n";
    $dir=$base.'/short';mkdir($dir,0700);$r=Archive::initial('short');writer($dir,$r,'start',['id'=>'short']);$h=fopen($dir.'/part-00001.pvlt','c+b');ftruncate($h,8);fclose($h);
    rejected(fn()=>Archive::recover($dir,$r),'truncated active checkpoint');
    rejected(fn()=>Archive::append($input,$s,$password,1200000,100000000,'data',[],'x'),'sealed writer cannot append');
    $dir=$base.'/limit';mkdir($dir,0700);$r=Archive::initial('limit');
    rejected(fn()=>Archive::append($dir,$r,$password,1200000,100,'start',[]),'hard archive budget');
    if (in_array('--large', $argv, true)) {
        echo "LARGE creating and streaming a source larger than 2 GiB...\n";
        $dir=$base.'/large'; mkdir($dir,0700); $source=$base.'/source-large.bin'; $length=2147483648+12345;
        $h=fopen($source,'xb'); check(ftruncate($h,$length),'create sparse source'); fseek($h,2147483648-7); fwrite($h,'crossing-two-gibibytes'); fclose($h);
        $r=Archive::initial('large-source'); Archive::append($dir,$r,$password,4194304,100000000,'start',['id'=>'large-source']);
        Archive::append($dir,$r,$password,4194304,100000000,'file_begin',['entry'=>1,'path'=>'files/large.bin','size'=>$length,'mtime'=>1700000000,'mode'=>0600]);
        $chain=str_repeat('0',64);$offset=0;$h=fopen($source,'rb');
        while($offset<$length) {
            check(fseek($h,$offset)===0,'64-bit seek'); $chunk=fread($h,min(Archive::CHUNK,$length-$offset)); check($chunk!==false&&$chunk!=='','read source');
            Archive::append($dir,$r,$password,4194304,100000000,'data',['entry'=>1,'offset'=>$offset],$chunk);
            $chain=Archive::chain($chain,$chunk);$offset+=strlen($chunk);
        }
        fclose($h);
        Archive::append($dir,$r,$password,4194304,100000000,'file_end',['entry'=>1,'bytes'=>$length,'chain_sha256'=>$chain]);
        Archive::seal($dir,$r,$password,4194304,100000000,['files'=>1,'directories'=>0,'bytes'=>$length]);
        echo "LARGE encrypted source complete; restoring and comparing SHA-256...\n";
        $report=(new ParcelRestore())->run($dir,$base.'/large-out',$password);
        check($report['bytes']===$length,'64-bit restored byte count');
        check(filesize($base.'/large-out/files/large.bin')===$length,'large output size');
        check(hash_file('sha256',$source)===hash_file('sha256',$base.'/large-out/files/large.bin'),'large source whole-file SHA-256');
        unlink($source);unlink($base.'/large-out/files/large.bin');
        $passed++;echo "PASS actual >2GiB file streaming, multipart and complete restoration\n";
    }
    echo "TOTAL $passed checks passed. Fixtures: $base\n";
}
