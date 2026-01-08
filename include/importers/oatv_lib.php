<?php
// include/importers/oatv_lib.php
declare(strict_types=1);

// ------------- DB-yhteys -----------------
function oatv_pdo(): PDO {
    // 1) Jos config/db tarjoaa helperin
    if (function_exists('getDbConnection')) {
        try { return getDbConnection('eveo'); } catch (Throwable $e) {}
        try { return getDbConnection('th_eveo'); } catch (Throwable $e) {}
        try { return getDbConnection(); } catch (Throwable $e) {}
    }
    if (function_exists('db')) { $pdo = db(); if ($pdo instanceof PDO) return $pdo; }
    if (function_exists('getPDO')) { $pdo = getPDO(); if ($pdo instanceof PDO) return $pdo; }
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];

    // 2) Config.php-konstantit ENSISIJAISESTI
    if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER')) {
        $host = DB_HOST;
        $name = DB_NAME;
        $user = DB_USER;
        $pass = defined('DB_PASS') ? DB_PASS : '';
        $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
        $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES '{$charset}' COLLATE 'utf8mb4_unicode_ci'",
        ]);
    }

    // 3) ENV-fallback vain jos mikään yllä ei toteudu
    $host = getenv('EVEO_DB_HOST') ?: 'localhost';
    $name = getenv('EVEO_DB_NAME') ?: 'fissifi_eveo';
    $user = getenv('EVEO_DB_USER') ?: 'root';
    $pass = getenv('EVEO_DB_PASS') ?: '';
    $charset = 'utf8mb4';
    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES '{$charset}' COLLATE 'utf8mb4_unicode_ci'",
    ]);
}


// ------------- Taulun varmistus ----------
function oatv_ensure_table(PDO $pdo): void {
    $sql = <<<SQL
    CREATE TABLE IF NOT EXISTS `oatv` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `channel`  VARCHAR(64)   NOT NULL,
      `artist`   VARCHAR(255)  NULL,
      `title`    VARCHAR(255)  NULL,
      `date`     DATE          NULL,
      `time`     TIME          NULL,
      `type`     VARCHAR(64)   NULL,
      `duration` TIME          NULL,
      `hour`     TINYINT UNSIGNED NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_date_time` (`date`,`time`),
      KEY `idx_channel_date` (`channel`,`date`),
      KEY `idx_hour` (`hour`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    SQL;
    $pdo->exec($sql);
}

// ------------- Apurit (alias, parserit) ----------
function oatv_norm_col(string $s): string { return strtolower(trim($s)); }
function oatv_strip_bom(string $s): string { return strncmp($s, "\xEF\xBB\xBF", 3) === 0 ? substr($s, 3) : $s; }
function oatv_alias_col(string $norm): string {
    static $ALIASES = [
        'kanava'=>'channel','esittäjä'=>'artist','artistti'=>'artist','kappale'=>'title','nimi'=>'title',
        'päivä'=>'date','paiva'=>'date','päivämäärä'=>'date','paivamaara'=>'date','aika'=>'time',
        'tyyppi'=>'type','kesto'=>'duration','tunti'=>'hour',
    ];
    return $ALIASES[$norm] ?? $norm;
}
function oatv_detect_delimiter_from_line(string $line): string {
    $c = [';'=>substr_count($line,';'), ','=>substr_count($line,','), "\t"=>substr_count($line,"\t"), '|'=>substr_count($line,'|')];
    arsort($c); $best = array_key_first($c);
    return $best ?: ',';
}
function oatv_label_for_delim(string $d): string {
    return match ($d) {
        ';' => 'puolipiste (;)', ',' => 'pilkku (,)', "\t" => 'sarkain (TAB)', '|' => 'pystyviiva (|)',
        default => 'tuntematon',
    };
}

function oatv_parse_date(?string $s): ?string {
    if ($s === null) return null; $t = trim($s); if ($t==='') return null;
    if (preg_match('~^(\d{4})-(\d{2})-(\d{2})$~',$t)) return $t;
    if (preg_match('~^(\d{1,2})[\.](\d{1,2})[\.]?(\d{2,4})$~',$t,$m)) {
        $d=(int)$m[1]; $M=(int)$m[2]; $y=(int)$m[3]; if ($y<100) $y+=2000;
        return sprintf('%04d-%02d-%02d',$y,$M,$d);
    }
    if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{2,4})$~',$t,$m)) {
        $d=(int)$m[1]; $M=(int)$m[2]; $y=(int)$m[3]; if ($y<100) $y+=2000;
        return sprintf('%04d-%02d-%02d',$y,$M,$d);
    }
    $ts = strtotime($t);
    return $ts ? date('Y-m-d',$ts) : null;
}
function oatv_parse_time(?string $s): ?string {
    if ($s===null) return null; $t=trim($s); if ($t==='') return null;
    if (preg_match('~^(\d{1,2}):(\d{2})(?::(\d{2}))?$~',$t,$m)) {
        $h=(int)$m[1]; $i=(int)$m[2]; $sec=isset($m[3])?(int)$m[3]:0;
        return sprintf('%02d:%02d:%02d',$h,$i,$sec);
    }
    if (preg_match('~^(\d{1,2})(\d{2})$~',$t,$m)) {
        return sprintf('%02d:%02d:%02d',(int)$m[1],(int)$m[2],0);
    }
    return null;
}
function oatv_parse_duration(?string $s): ?string {
    if ($s===null) return null; $t=trim($s); if ($t==='') return null;
    if (ctype_digit($t)) {
        $sec=(int)$t; $h=intdiv($sec,3600); $sec-=$h*3600; $m=intdiv($sec,60); $sec-=$m*60;
        return sprintf('%02d:%02d:%02d',$h,$m,$sec);
    }
    if (preg_match('~^(\d+):(\d{2})(?::(\d{2}))?$~',$t,$m)) {
        $a=(int)$m[1]; $b=(int)$m[2]; $c=isset($m[3])?(int)$m[3]:0;
        if (!isset($m[3])) { $h=intdiv($a,60); $min=$a%60; return sprintf('%02d:%02d:%02d',$h,$min,$b); }
        return sprintf('%02d:%02d:%02d',$a,$b,$c);
    }
    return null;
}
function oatv_derive_hour(?string $time, ?string $hour): ?int {
    $hour = trim((string)($hour ?? ''));
    if ($hour!=='' && ctype_digit($hour)) { $h=(int)$hour; return ($h>=0 && $h<=23)?$h:null; }
    if ($time && preg_match('~^(\d{2}):~',$time,$m)) return (int)$m[1];
    return null;
}

function oatv_read_header_preview(string $filepath, ?string $chosenDelim=null, int $previewRows=3): array {
    $fh=fopen($filepath,'r'); if(!$fh) throw new RuntimeException('Tiedostoa ei voitu avata');
    $firstLine=''; while(!feof($fh)&&$firstLine===''){ $firstLine=(string)fgets($fh); $firstLine=trim($firstLine,"\r\n"); }
    if ($firstLine==='') throw new RuntimeException('CSV on tyhjä');
    $detected=oatv_detect_delimiter_from_line($firstLine);
    $delimiter=$chosenDelim ?: $detected;

    rewind($fh);
    $header=fgetcsv($fh,0,$delimiter,'"',"\\"); if($header===false) throw new RuntimeException('Otsikkoa ei voitu lukea');

    $mapIdxToNorm=[]; $normHeader=[];
    foreach($header as $i=>$name){
        $nm=oatv_strip_bom((string)$name);
        $norm=oatv_alias_col(oatv_norm_col($nm));
        $mapIdxToNorm[$i]=$norm; $normHeader[]=$norm;
    }
    $rows=[]; $count=0;
    while($count<$previewRows && ($row=fgetcsv($fh,0,$delimiter,'"',"\\"))!==false){ $rows[]=$row; $count++; }
    fclose($fh);

    return [
        'delimiter'=>$delimiter, 'detected'=>$detected,
        'header_raw'=>$header, 'header_norm'=>$normHeader,
        'mapIdxToNorm'=>$mapIdxToNorm, 'preview_rows'=>$rows,
    ];
}

function oatv_read_rows_assoc(string $filepath, ?string $chosenDelim=null): array {
    $EXPECTED=['channel','artist','title','date','time','type','duration','hour'];

    $fh=fopen($filepath,'r'); if(!$fh) throw new RuntimeException('Tiedostoa ei voitu avata');
    $firstLine=''; while(!feof($fh)&&$firstLine===''){ $firstLine=(string)fgets($fh); $firstLine=trim($firstLine,"\r\n"); }
    if ($firstLine==='') throw new RuntimeException('CSV on tyhjä');
    $detected=oatv_detect_delimiter_from_line($firstLine);
    $delimiter=$chosenDelim ?: $detected;

    rewind($fh);
    $header=fgetcsv($fh,0,$delimiter,'"',"\\"); if($header===false) throw new RuntimeException('Otsikkoa ei voitu lukea');

    $map=[]; foreach($header as $i=>$name){ $nm=oatv_strip_bom((string)$name); $col=oatv_alias_col(oatv_norm_col($nm)); $map[$i]=$col; }

    $rows=[];
    while(($row=fgetcsv($fh,0,$delimiter,'"',"\\"))!==false){
        $assoc=[];
        foreach($row as $i=>$val){ $col=$map[$i]??null; if($col && in_array($col,$EXPECTED,true)){ $assoc[$col]=$val; } }
        if (!array_filter($assoc, fn($v)=>trim((string)$v)!=='')) continue;
        $rows[]=$assoc;
    }
    fclose($fh);
    return [$rows,$map,$delimiter];
}

function oatv_import_file(string $filepath, bool $truncate, ?string $chosenDelim=null, string $forceChannel=''): array {
    $EXPECTED=['channel','artist','title','date','time','type','duration','hour'];
    $pdo=oatv_pdo(); oatv_ensure_table($pdo);
    if($truncate){ $pdo->exec('TRUNCATE TABLE `oatv`'); }
    [$rows,$map] = oatv_read_rows_assoc($filepath,$chosenDelim);

    $ins=$pdo->prepare('INSERT INTO `oatv` (`channel`,`artist`,`title`,`date`,`time`,`type`,`duration`,`hour`) VALUES (?,?,?,?,?,?,?,?)');

    $inserted=0; $skipped=0; $errors=[]; $preview=[];
    $pdo->beginTransaction();
    try {
        foreach($rows as $idx=>$r){
            $channel=trim((string)($r['channel']??'')); if($channel==='' && $forceChannel!==''){ $channel=$forceChannel; }
            $artist=trim((string)($r['artist']??'')); $title=trim((string)($r['title']??''));
            $date=oatv_parse_date($r['date']??null); $time=oatv_parse_time($r['time']??null);
            $type=trim((string)($r['type']??'')); $duration=oatv_parse_duration($r['duration']??null);
            $hour=oatv_derive_hour($time,$r['hour']??null);

            if ($channel===''){ $skipped++; $errors[]="Rivi ".($idx+2).": 'channel' puuttuu."; continue; }
            if ($title==='' && $artist===''){ $skipped++; $errors[]="Rivi ".($idx+2).": puuttuu (title ja/ja artist)."; continue; }

            try{
                $ins->execute([$channel,$artist?:null,$title?:null,$date,$time,$type?:null,$duration,$hour]);
                $inserted++; if ($inserted<=50){ $preview[] = compact('channel','artist','title','date','time','type','duration','hour'); }
            }catch(Throwable $te){
                $skipped++; $errors[]="Rivi ".($idx+2).": ".$te->getMessage();
            }
        }
        $pdo->commit();
    } catch(Throwable $e) {
        $pdo->rollBack(); throw $e;
    }

    return [
        'total'=>count($rows), 'inserted'=>$inserted, 'skipped'=>$skipped,
        'errors'=>$errors, 'preview'=>$preview
    ];
}

function oatv_safe_label_delim(?string $d): string {
    return $d===null ? 'automaattinen' : oatv_label_for_delim($d);
}
