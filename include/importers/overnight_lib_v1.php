<?php
// include/importers/overnight_lib.php
declare(strict_types=1);

/**
 * Yönyliraportin yhteinen kirjasto:
 * - CSV-luku (autodetect erotin), merkistön muunto
 * - Otsikoiden normalisointi: tukee englanti + suomi variantit
 * - Päivä- ja aikaparserit: Y-m-d, d.m.Y, d F Y (fi/en), US "m.d.Y h:i:s A", 24:00 -> 23:59:59
 * - Desimaalipilkku -> piste
 * - Import overnightreport-tauluun (ON DUPLICATE KEY UPDATE)
 */

//////////////////// DB ////////////////////
function ovn_pdo(): PDO
{
    if (function_exists('getDbConnection')) {
        try {
            return getDbConnection('eveo');
        } catch (Throwable $e) {
        }
        try {
            return getDbConnection('th_eveo');
        } catch (Throwable $e) {
        }
        try {
            return getDbConnection();
        } catch (Throwable $e) {
        }
    }
    if (function_exists('db')) {
        $pdo = db();
        if ($pdo instanceof PDO)
            return $pdo;
    }
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO)
        return $GLOBALS['pdo'];

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

//////////////////// Apuja ////////////////////
function ovn_detect_delimiter_from_line(string $line): string
{
    $counts = [
        ';' => substr_count($line, ';'),
        ',' => substr_count($line, ','),
        "\t" => substr_count($line, "\t"),
        '|' => substr_count($line, '|'),
    ];
    arsort($counts);
    return array_key_first($counts) ?? ',';
}
function ovn_label_for_delim(string $d): string
{
    return match ($d) {
        ';' => 'puolipiste (;)',
        ',' => 'pilkku (,)',
        "\t" => 'sarkain (TAB)',
        '|' => 'pystyviiva (|)',
        default => 'tuntematon',
    };
}
function ovn_safe_label_delim(?string $d): string
{
    return $d === null ? 'automaattinen' : ovn_label_for_delim($d);
}
function ovn_convert_encoding(string $s, string $encoding): string
{
    $enc = strtoupper(trim($encoding));
    if ($enc === 'UTF-8')
        return $s;
    $out = @iconv($enc, 'UTF-8//IGNORE', $s);
    return $out !== false ? $out : $s;
}
function ovn_is_header_row(array $row): bool
{
    $joined = strtolower(implode(' ', array_map('strval', $row)));
    if ($joined === '')
        return false;
    foreach (['report', 'date', 'program', 'start', 'end', 'time', 'ohjelma', 'päivä', 'alku', 'loppu'] as $h) {
        if (str_contains($joined, $h))
            return true;
    }
    return false;
}

// header-normalisoinnin helper suomea varten
function ovn_strip_invisibles(string $s): string
{
    // Strip BOMs and general Unicode "format" chars from the beginning of the cell
    // (covers FEFF, ZWSP, LRM/RLM, etc.)
    $s = preg_replace('/^\xEF\xBB\xBF|\xFE\xFF|\xFF\xFE|\x00\x00\xFE\xFF|\xFF\xFE\x00\x00/u', '', $s);
    // Also remove other Cf characters anywhere in the token
    $s = preg_replace('/\p{Cf}+/u', '', $s);
    return $s;
}

function ovn_simple_normalize(string $s): string
{
    $s = ovn_strip_invisibles($s);
    $s = trim($s);
    if ($s === '')
        return '';

    // lowercase in UTF-8
    $s = mb_strtolower($s, 'UTF-8');

    // deterministic transliteration for FI characters (no iconv needed)
    $s = strtr($s, [
        'ä' => 'a',
        'ö' => 'o',
        'å' => 'a',
        'õ' => 'o',
        'š' => 's',
        'ž' => 'z',
    ]);

    // collapse non-alnum to underscores
    $s = preg_replace('/[^a-z0-9]+/u', '_', $s);
    return trim($s, '_');
}


/** Palauttaa normalisoidut kenttäavaimet (report_date, program, start_time, end_time, channel, avg_viewers, share_pct, over60_on_channel, …) */
function ovn_normalize_header(array $hdr): array
{
    $norm = [];
    foreach ($hdr as $hRaw) {
        $h = ovn_simple_normalize((string) $hRaw);

        // FI/EN synonymit
        $map = [
            // Päivä
            'paivamaara' => 'report_date',
            'paiva' => 'report_date',
            'date' => 'report_date',
            'report_date' => 'report_date',

            // Ohjelma
            'ohjelma' => 'program',
            'program' => 'program',
            'programme' => 'program',

            // Alku
            'alku' => 'start_time',
            'start' => 'start_time',
            'start_time' => 'start_time',

            // Loppu
            'paattymisaika' => 'end_time',
            'paattymis' => 'end_time',
            'loppu' => 'end_time',
            'end' => 'end_time',
            'end_time' => 'end_time',

            // Kanava
            'kanava' => 'channel',
            'channel' => 'channel',

            // Metrikat (eri nimillä)
            'yli_60s_kanavalla' => 'over60_on_channel',
            'yli_60' => 'over60_on_channel',
            'over_60' => 'over60_on_channel',
            'over60' => 'over60_on_channel',
            'over60_on_channel' => 'over60_on_channel',

            'keskikatsojamaara' => 'avg_viewers',
            'avg_viewers' => 'avg_viewers',
            'avg' => 'avg_viewers',
            'average' => 'avg_viewers',

            'katsojaosuus' => 'share_pct',
            'share' => 'share_pct',
            'share_pct' => 'share_pct',
        ];

        $norm[] = $map[$h] ?? $h;
    }
    return $norm;
}

function ovn_parse_finnish_date_like(string $s): ?string
{
    $t = trim($s);
    if ($t === '')
        return null;

    // Mahd. "maanantai, 10 marraskuu 2025" -> pudota viikonpäivä
    if (str_contains($t, ',')) {
        $parts = explode(',', $t, 2);
        $t = trim($parts[1]);
    }

    // “10 marraskuu 2025” -> käännä kk englanniksi
    $fi = ['tammikuu', 'helmikuu', 'maaliskuu', 'huhtikuu', 'toukokuu', 'kesäkuu', 'heinäkuu', 'elokuu', 'syyskuu', 'lokakuu', 'marraskuu', 'joulukuu'];
    $en = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $t2 = str_ireplace($fi, $en, $t);

    // d F Y tai d. F Y
    $dt = DateTime::createFromFormat('d F Y', $t2);
    if (!$dt)
        $dt = DateTime::createFromFormat('d. F Y', $t2);
    if ($dt)
        return $dt->format('Y-m-d');

    // yleiset fallbackit
    foreach (['d.m.Y', 'Y-m-d', 'm/d/Y', 'd/m/Y'] as $f) {
        $dt = DateTime::createFromFormat($f, $t);
        if ($dt)
            return $dt->format('Y-m-d');
    }
    $ts = strtotime($t);
    return $ts ? date('Y-m-d', $ts) : null;
}

function ovn_parse_date(string $s, string $pref): ?string
{
    $t = trim($s);
    if ($t === '')
        return null;
    $dt = DateTime::createFromFormat($pref, $t);
    if ($dt)
        return $dt->format('Y-m-d');
    // kokeile “fi-päivä”
    $fi = ovn_parse_finnish_date_like($t);
    if ($fi)
        return $fi;
    // fallbackit
    foreach (['Y-m-d', 'd.m.Y', 'm/d/Y', 'd/m/Y'] as $f) {
        $dt = DateTime::createFromFormat($f, $t);
        if ($dt)
            return $dt->format('Y-m-d');
    }
    $ts = strtotime($t);
    return $ts ? date('Y-m-d', $ts) : null;
}

function ovn_to_int($s): ?int
{
    if ($s === null)
        return null;
    $t = trim((string) $s);
    if ($t === '')
        return null;
    $t = preg_replace('/\D+/', '', $t);
    return ($t === '') ? null : (int) $t;
}
function ovn_to_decimal($s): ?string
{
    if ($s === null)
        return null;
    $t = trim((string) $s);
    if ($t === '')
        return null;
    $t = str_replace(["\xc2\xa0", ' '], '', $t);
    $t = str_replace(',', '.', $t);
    if (!is_numeric($t))
        return null;
    return number_format((float) $t, 2, '.', '');
}

//////////////////// CSV-luku ////////////////////
function ovn_read_csv_rows(string $path, ?string $delimiter, string $encoding, bool $hasHeader, int $maxRowsForPreview = 3): array
{
    $fh = fopen($path, 'rb');
    if (!$fh)
        throw new RuntimeException('CSV-tiedostoa ei saatu auki.');
    $first = fgets($fh);
    if ($first === false) {
        fclose($fh);
        return ['header_raw' => [], 'rows' => [], 'delimiter' => ($delimiter ?: ',')];
    }
    $autoDelim = $delimiter ?? ovn_detect_delimiter_from_line($first);
    rewind($fh);

    $header_raw = [];
    $rows = [];
    $rowIdx = 0;

    while (($r = fgetcsv($fh, 0, $autoDelim)) !== false) {
        $r = array_map(fn($v) => ovn_convert_encoding((string) $v, $encoding), $r);
        if ($rowIdx === 0 && $hasHeader && ovn_is_header_row($r)) {
            $header_raw = $r;
        } else {
            $rows[] = $r;
        }
        $rowIdx++;
    }
    fclose($fh);

    return ['header_raw' => $header_raw, 'rows' => $rows, 'delimiter' => $autoDelim];
}
// TRUE if the string clearly contains a clock time portion
function ovn_has_time_component(string $s): bool
{
    // 12h or 24h, with optional seconds; AM/PM allowed
    return (bool) preg_match('~\b(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(AM|PM)?\b~i', $s);
}

// Parse 12h or 24h time (optionally embedded in a datetime); return H:i:s or null
function ovn_extract_time(string $s): ?string
{
    $s = trim($s);
    if ($s === '')
        return null;

    // Handle 24:00 (normalize to 23:59:59)
    if (preg_match('~\b24:00(?::00)?\b~', $s))
        return '23:59:59';

    // Try to extract time fragment
    if (!preg_match('~\b(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(AM|PM)?\b~i', $s, $m)) {
        return null; // no explicit time -> do NOT coerce to midnight
    }
    $hh = (int) $m[1];
    $mm = (int) $m[2];
    $ss = isset($m[3]) ? (int) $m[3] : 0;
    $ampm = isset($m[4]) ? strtoupper($m[4]) : '';

    if ($ampm === 'AM' || $ampm === 'PM') {
        $hh = $hh % 12;
        if ($ampm === 'PM')
            $hh += 12;
    }
    $hh = max(0, min(23, $hh));
    $mm = max(0, min(59, $mm));
    $ss = max(0, min(59, $ss));
    return sprintf('%02d:%02d:%02d', $hh, $mm, $ss);
}

//////////////////// Normalisointi ////////////////////
function ovn_normalize_row(array $row, array $headerNorm, string $dateFormat): array
{
    $out = [];
    foreach ($headerNorm as $i => $key) {
        $val = $row[$i] ?? null;

        switch ($key) {
            case 'report_date':
                // sallitaan sekä preferoitu formaatti että “fi-päivä”
                $out['report_date'] = $val === null ? null : ovn_parse_date((string) $val, $dateFormat);
                break;

            case 'start_time':
            case 'end_time': {
                $raw = $val === null ? '' : (string) $val;

                // Poimi vain kellonaika (12/24h, tukee myös AM/PM)
                $time = ovn_extract_time($raw);

                // Yhdistä päivämäärään, jos saatavilla
                $date = $out['report_date'] ?? null;
                if ($time !== null && $date !== null) {
                    // Lopullinen muoto: YYYY-MM-DD HH:MM:SS
                    $out[$key] = $date . ' ' . $time;
                } else {
                    // jos jompi kumpi puuttuu, jätetään nulliksi -> rivi skipataan myöhemmin
                    $out[$key] = null;
                }

                // (Valinnainen) jos report_date ei ollut erillisessä sarakkeessa ja
                // päivämäärä on upotettu tähän kenttään muodossa m.d.Y tai Y-m-d,
                // voit vielä yrittää muodostaa sen tästä:
                if ($date === null && $raw !== '') {
                    if (preg_match('~\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b~', $raw, $m)) {
                        // HUOM: tämä on US-mallinen m.d.Y – vaihdetaan oikeaan järjestykseen
                        $ymd = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[1], (int) $m[2]);
                        if ($time !== null)
                            $out[$key] = $ymd . ' ' . $time;
                        $out['report_date'] = $ymd;
                    } elseif (preg_match('~\b(\d{4})-(\d{2})-(\d{2})\b~', $raw, $m)) {
                        $ymd = sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
                        if ($time !== null)
                            $out[$key] = $ymd . ' ' . $time;
                        $out['report_date'] = $ymd;
                    }
                }

                break;
            }

            case 'avg_viewers':
                $out['avg_viewers'] = ovn_to_int($val);
                break;

            case 'over60_on_channel':
                $out['over60_on_channel'] = ovn_to_int($val);
                break;

            case 'share_pct':
                $out['share_pct'] = ovn_to_decimal($val);
                break;

            case 'program':
            case 'channel':
                $out[$key] = $val === null ? null : trim((string) $val);
                break;

            default:
                // muut sarakkeet: yritä numero, muuten string/null
                if ($val === null) {
                    $out[$key] = null;
                    break;
                }
                $v = trim((string) $val);
                if ($v === '') {
                    $out[$key] = null;
                } elseif (is_numeric(str_replace(',', '.', $v))) {
                    $out[$key] = (float) str_replace(',', '.', $v);
                } else {
                    $out[$key] = $v;
                }
        }
    }
    return $out;
}

function ovn_fetch_table_columns(PDO $pdo): array
{
    $cols = [];
    foreach ($pdo->query("DESCRIBE overnightreport") as $row)
        $cols[] = $row['Field'];
    return $cols;
}

//////////////////// PREVIEW ////////////////////
function ovn_preview(string $tmpPath, ?string $delimiter, string $encoding, bool $hasHeader, string $dateFormat, int $previewRows = 3): array
{
    $data = ovn_read_csv_rows($tmpPath, $delimiter, $encoding, $hasHeader, $previewRows);
    $header_raw = $data['header_raw'];
    $rows = $data['rows'];
    $usedDelim = $data['delimiter'];
    $header_norm = $header_raw ? ovn_normalize_header($header_raw) : [];

    $rows_norm = [];
    if ($header_norm) {
        foreach (array_slice($rows, 0, $previewRows) as $r) {
            $rows_norm[] = ovn_normalize_row($r, $header_norm, $dateFormat);
        }
    }

    return [
        'ok' => true,
        'mode' => 'preview',
        'delimiter' => $delimiter,
        'delimiter_label' => ovn_safe_label_delim($delimiter),
        'detected_label' => ovn_label_for_delim($usedDelim),
        'header_raw' => $header_raw,
        'header_norm' => $header_norm,
        'rows' => array_slice($rows, 0, $previewRows),
        'rows_norm' => $rows_norm,
    ];
}

//////////////////// IMPORT ////////////////////
function ovn_import(string $tmpPath, ?string $delimiter, string $encoding, bool $hasHeader, string $dateFormat, ?string $forceChannel = null, bool $truncate = false): array
{
    $pdo = ovn_pdo();
    if ($truncate)
        $pdo->exec("TRUNCATE TABLE overnightreport");

    $data = ovn_read_csv_rows($tmpPath, $delimiter, $encoding, $hasHeader, 0);
    $header_raw = $data['header_raw'];
    $rows = $data['rows'];
    $usedDelim = $data['delimiter'];

    // Päätä odotettu sarakemäärä (headerista, muuten riveistä)
    $expectedCols = 0;
    if (!empty($header_raw)) {
        $expectedCols = count($header_raw);
    } else {
        // Etsi tiedoston MAX-sarakemäärä (turvallinen 1000 riviin asti; muuta tarvittaessa)
        $maxScan = min(1000, count($rows));
        for ($i = 0; $i < $maxScan; $i++) {
            $c = count($rows[$i]);
            if ($c > $expectedCols)
                $expectedCols = $c;
        }
    }

    // Varmista, että header_norm on saman mittainen kuin expectedCols
    if (!empty($header_norm) && $expectedCols > count($header_norm)) {
        for ($i = count($header_norm); $i < $expectedCols; $i++) {
            $header_norm[$i] = 'col_' . ($i + 1);
        }
    }

    $header_norm = $header_raw ? ovn_normalize_header($header_raw) : [];
    if (!$header_norm) {
        // fallback: oletusjärjestys
        $cnt = count($rows) ? count($rows[0]) : 0;
        for ($i = 0; $i < $cnt; $i++)
            $header_norm[] = "col_" . ($i + 1);
        if (isset($header_norm[0]))
            $header_norm[0] = 'report_date';
        if (isset($header_norm[1]))
            $header_norm[1] = 'program';
        if (isset($header_norm[2]))
            $header_norm[2] = 'start_time';
        if (isset($header_norm[3]))
            $header_norm[3] = 'end_time';
    }

    $tableCols = ovn_fetch_table_columns($pdo);

    // Perusavaimet
    $baseKeys = ['report_date', 'program', 'start_time', 'end_time'];
    $allKeys = array_values(array_unique(array_merge($baseKeys, $header_norm)));
    if ($forceChannel !== null && !in_array('channel', $allKeys, true))
        $allKeys[] = 'channel';

    // Rajaa vain taulussa oleviin
    $insKeys = array_values(array_filter($allKeys, fn($k) => in_array($k, $tableCols, true)));

    foreach (['report_date', 'program', 'start_time', 'end_time'] as $req) {
        if (!in_array($req, $insKeys, true)) {
            throw new RuntimeException("overnightreport-taulussa pitää olla sarake: {$req}");
        }
    }

    $colsSql = implode(',', array_map(fn($k) => "`$k`", $insKeys));
    $valsSql = implode(',', array_map(fn($k) => ":$k", $insKeys));
    $updateSql = implode(',', array_map(function ($k) {
        return "`$k` = VALUES(`$k`)";
    }, array_filter($insKeys, fn($k) => !in_array($k, ['report_date', 'program', 'start_time', 'end_time'], true))));

    $sql = "INSERT INTO `overnightreport` ($colsSql) VALUES ($valsSql)"
        . ($updateSql ? " ON DUPLICATE KEY UPDATE $updateSql" : "");

    $stmt = $pdo->prepare($sql);

    // add counters
    $created = 0;     // new inserts
    $updated = 0;     // updated existing rows (values changed)
    $unchanged = 0;   // duplicate key, no actual change

    $inserted = 0;
    $skipped = 0;
    $errors = [];
    $preview = [];

    $expectedColsForFile = 0;
    if (!empty($header_raw)) {
        $expectedColsForFile = count($header_raw);
    } elseif (!empty($rows)) {
        $expectedColsForFile = count($rows[0]);
    }

    $pdo->beginTransaction();
    try {
        foreach ($rows as $idx => $r) {
            $actualCols = count($r);
            if ($expectedColsForFile > 0 && $actualCols < $expectedColsForFile) {
                $skipped++;
                $errors[] = "Rivi " . ($idx + 1) . ": liian vähän sarakkeita ($actualCols, odotettiin $expectedColsForFile)";
                continue;
            }

            $expectedCols = count($header_raw) ?: count($header_norm);
            $actualCols = count($r);
            if ($expectedCols > 0 && $actualCols < $expectedCols) {
                $skipped++;
                $errors[] = "Rivi " . ($idx + 1) . ": liian vähän sarakkeita ($actualCols, odotettiin $expectedCols)";
                continue;
            }
            // Pakota rivin pituus samaksi: pad/truncate
            if ($expectedCols > 0) {
                if (count($r) < $expectedCols) {
                    $r = array_pad($r, $expectedCols, null);   // täydennä puuttuvat sarakkeet
                } elseif (count($r) > $expectedCols) {
                    $r = array_slice($r, 0, $expectedCols);    // typistä ylimääräiset
                }
            }

            $norm = ovn_normalize_row($r, $header_norm, $dateFormat);
            if ($forceChannel !== null)
                $norm['channel'] = $forceChannel;

            $bind = [];
            foreach ($insKeys as $k)
                $bind[":$k"] = $norm[$k] ?? null;

            if (
                empty($bind[':report_date']) || empty($bind[':program']) ||
                empty($bind[':start_time']) || empty($bind[':end_time'])
            ) {
                $skipped++;
                $errors[] = "Rivi " . ($idx + 1) . ": puuttuva avainkenttä (report_date/program/start_time/end_time)";
                continue;
            }

            try {
                // Tarkista onko rivi jo olemassa
                $checkStmt = $pdo->prepare("
        SELECT * FROM overnightreport
        WHERE report_date = :report_date
          AND program = :program
          AND start_time = :start_time
          AND end_time = :end_time
        LIMIT 1
    ");
                $checkStmt->execute([
                    ':report_date' => $norm['report_date'],
                    ':program' => $norm['program'],
                    ':start_time' => $norm['start_time'],
                    ':end_time' => $norm['end_time']
                ]);

                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if ($existing === false) {
                    // Ei löydy → uusi
                    $stmt->execute($bind);
                    $created++;
                } else {
                    // Löytyy → vertaillaan kentät (vain ne, jotka oikeasti on tulossa bindissä)
                    $isChanged = false;
                    foreach ($insKeys as $k) {
                        $newVal = $norm[$k] ?? null;
                        $oldVal = $existing[$k] ?? null;
                        if ($newVal != $oldVal) { // löyhä vertailu riittää tässä
                            $isChanged = true;
                            break;
                        }
                    }

                    if ($isChanged) {
                        $stmt->execute($bind);
                        $updated++;
                    } else {
                        $unchanged++;
                    }
                }

                if (count($preview) < 5)
                    $preview[] = array_intersect_key($norm, array_flip($insKeys));
            } catch (Throwable $e) {
                $skipped++;
                $errors[] = "Rivi " . ($idx + 1) . ": " . $e->getMessage();
            }

        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // keep 'inserted' for backwards-compat with the UI, but make it mean "created+updated"
    $inserted = $created + $updated;

    return [
        'total' => count($rows),
        'inserted' => $inserted,   // created + updated (for current UI label)
        'created' => $created,
        'updated' => $updated,
        'unchanged' => $unchanged,  // useful to display if you want
        'skipped' => $skipped,    // missing keys or SQL errors
        'errors' => $errors,
        'preview' => $preview,
        'delimiter_label' => ovn_label_for_delim($usedDelim),
    ];

}
