<?php
// Varmista ettÃ¤ kirjasto tietÃ¤Ã¤ minne lokittaa:
if (!defined('OAS_ERROR_LOG_DIR')) {
    // include/importers/ -kansiosta kaksi pykÃ¤lÃ¤Ã¤ ylÃ¶spÃ¤in -> projektijuuri
    $fallback = realpath(__DIR__ . '/../../logs');
    if (!$fallback) {
        $fallback = __DIR__ . '/../../logs';
        @mkdir($fallback, 0775, true);
    }
    define('OAS_ERROR_LOG_DIR', $fallback);
}

// YhtenÃ¤inen oas_log()-apuri; ei riko nykyisiÃ¤ error_log()-kutsuja,
// mutta voit halutessa korvata ne oas_log():lla.
if (!function_exists('oas_log')) {
    function oas_log(string $msg): void
    {
        $file = rtrim(OAS_ERROR_LOG_DIR, '/\\') . '/oas_import_errors.log';
        // YritÃ¤ avata; jos ei onnistu, fallback perinteiseen error_logiin
        $prefix = '[OAS] ';
        if (is_dir(OAS_ERROR_LOG_DIR) && is_writable(OAS_ERROR_LOG_DIR)) {
            @file_put_contents($file, $prefix . $msg . PHP_EOL, FILE_APPEND);
        } else {
            @error_log($prefix . $msg);
        }
    }
}

// Kirjaa fataalit shutdownissa, jotta hiljaiset kuolemat nÃ¤kyvÃ¤t
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        oas_log('FATAL: ' . json_encode($e, JSON_UNESCAPED_UNICODE));
    }
});
// include/importers/oas_lib.php
// YhtenÃ¤inen OAS CSV -kirjasto (parsinta + normalisointi + tuonti) EVEOlle.

if (!defined('FISSI_EVEO')) {
    define('FISSI_EVEO', true);
}
// MissÃ¤ virheloki pidetÃ¤Ã¤n. Oletus: tÃ¤mÃ¤n tiedoston hakemisto (include/importers)
if (!defined('OAS_ERROR_LOG_DIR')) {
    define('OAS_ERROR_LOG_DIR', __DIR__);
}

/**
 * Muuttaa rivin luettavaan tekstiin virhelokia varten.
 */
function oas_row_to_string(array $row): string
{
    // Otetaan arvot jÃ¤rjestyksessÃ¤ ja muutetaan stringiksi
    $vals = array_map(
        static fn($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE),
        array_values($row)
    );

    // Esim: "1: foo | 2: bar | 3: baz"
    $parts = [];
    foreach ($vals as $i => $v) {
        $idx = $i + 1;
        $parts[] = "{$idx}: {$v}";
    }
    return implode(' | ', $parts);
}

function oas_autodetect_delimiter(string $line): string
{
    $c = substr_count($line, ',');
    $s = substr_count($line, ';');
    $t = substr_count($line, "\t");
    $p = substr_count($line, '|');
    $max = max($c, $s, $t, $p);
    if ($max === 0)
        return ',';
    if ($max === $p)
        return '|';
    if ($max === $s)
        return ';';
    if ($max === $t)
        return "\t";
    return ',';
}

function oas_is_header_row(array $row): bool
{
    $cells = array_map(static fn($v) => is_string($v) ? strtolower(trim($v)) : $v, $row);
    $hasAccount = in_array('account', $cells, true);
    $hasCampaign = in_array('campaign', $cells, true);
    $names = ['contract nr', 'contract_nr', 'date played', 'date_played', 'hours', 'block', 'code', 'commercial', 'length', 'edition'];
    $hit = 0;
    foreach ($names as $n) {
        if (in_array($n, $cells, true))
            $hit++;
    }
    return ($hasAccount && $hasCampaign) || $hit >= 3;
}

function oas_parse_date(?string $s, ?string $preferred = null): ?string
{
    if (!$s)
        return null;
    $s = trim($s);

    // Jos preferoitu muoto annettu, kokeile ensin sitÃ¤.
    if ($preferred) {
        $dt = DateTime::createFromFormat($preferred, $s);
        if ($dt && $dt->format($preferred) === $s) {
            return $dt->format('Y-m-d');
        }
    }

    // Yleiset fallbackit
    $candidates = ['Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y', 'Y.m.d', 'd-m-Y', 'Y/m/d'];
    foreach ($candidates as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt && $dt->format($fmt) === $s) {
            return $dt->format('Y-m-d');
        }
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
}

function oas_parse_length_to_seconds(?string $s): ?int
{
    if ($s === null)
        return null;
    $s = trim($s);
    if ($s === '')
        return null;
    if (ctype_digit($s))
        return (int) $s;
    if (preg_match('~^(?:(\d{1,2}):)?(\d{1,2}):(\d{2})$~', $s, $m))
        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
    if (preg_match('~^(\d{1,2}):(\d{2})$~', $s, $m))
        return (int) $m[1] * 60 + (int) $m[2];
    if (preg_match('~^(?:(\d+)m)?(?:(\d+)s(?:ec)?)?$~i', $s, $m)) {
        $mVal = (int) ($m[1] ?? 0);
        $sVal = (int) ($m[2] ?? 0);
        if ($mVal + $sVal > 0)
            return $mVal * 60 + $sVal;
    }
    return null;
}

function oas_split_campaign_contract(string $field): array
{
    $f = trim($field);
    if (preg_match('~^(.*?)(?:\s+|\s*-\s*|\s*#\s*)(\d{4,10})$~u', $f, $m))
        return [trim($m[1]), trim($m[2])];
    if (str_contains($f, ' / '))
        return array_map('trim', explode(' / ', $f, 2));
    if (str_contains($f, ' - '))
        return array_map('trim', explode(' - ', $f, 2));
    return [$f, null];
}

function oas_normalize_row(array $row, int $rowNum, array &$errors, ?string $preferredDateFmt): ?array
{
    $row = array_map(static fn($v) => is_string($v) ? trim($v) : $v, $row);
    $n = count($row);
    if ($n < 8) {
        $errors[] = sprintf(
            "Rivi %d: liian vÃ¤hÃ¤n sarakkeita (%d). Raaka: %s",
            $rowNum,
            count($row),
            oas_row_to_string($row)
        );

        return null;
    }

    $isHour = static function ($v): bool {
        if ($v === '' || $v === null)
            return false;
        if (!preg_match('~^\d{1,2}$~', (string) $v))
            return false;
        $iv = (int) $v;
        return $iv >= 0 && $iv <= 99;
    };

    // CASE B: "Contract puuttuu" â€“ teidÃ¤n CSV-variantti
    $dateAt2 = oas_parse_date($row[2] ?? null, $preferredDateFmt);
    if ($dateAt2 !== null && $isHour($row[3] ?? null)) {
        $edition = $row[8] ?? null;
        if ($n >= 9) {
            return [$row[0], $row[1], null, $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], $edition];
        } else {
            return [$row[0], $row[1], null, $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], null];
        }
    }

    // CASE A: "Normaali" â€“ contract kolmantena
    $dateAt3 = oas_parse_date($row[3] ?? null, $preferredDateFmt);
    if ($dateAt3 !== null && $isHour($row[4] ?? null)) {
        $edition = $row[9] ?? null;
        return [$row[0], $row[1], $row[2] ?? null, $row[3], $row[4], $row[5], $row[6], $row[7], $row[8] ?? null, $edition];
    }

    //$errors[] = "Rivi {$rowNum}: sarakejÃ¤rjestystÃ¤ ei voitu tulkita luotettavasti.";
    $errors[] = sprintf(
        "Rivi %d: sarakejÃ¤rjestystÃ¤ ei voitu tlkita luotettavasti. %d Raaka: %s",
        $rowNum,
        count($row),
        oas_row_to_string($row)
    );

    return null;
}

/**
 * Suorita OAS-tuonti.
 * Palauttaa: [
 *   'ok' => bool, 'inserted'=>int, 'skipped_headers'=>int, 'total'=>int,
 *   'errors'=>string[], 'preview'=>array<assoc>, 'delimiter'=>string
 * ]
 */
function oas_import(PDO $pdo, string $filepath, array $opts): array
{
    $encoding = $opts['encoding'] ?? 'UTF-8';
    $delimiter = $opts['delimiter'] ?? 'auto'; // 'auto', ',', ';', "\t", '|'
    $hasHeader = !empty($opts['has_header']);
    $dateFmt = $opts['date_format'] ?? null;
    $previewN = (int) ($opts['preview_rows'] ?? 50);

    $errors = [];
    $inserted = 0;
    $skipped_headers = 0;
    $duplicates = 0;
    $total = 0;
    $preview = [];
    $preview_raw = [];
    // Otsikon perusteella: sarakemÃ¤Ã¤rÃ¤ ja contract_nr-sarakkeen indeksi
    $headerColCount = null;
    $headerContractIdx = null;


    // Lue koko tiedosto valittuun merkistÃ¶Ã¶n (UTF-8 sisÃ¤isesti)
    $raw = @file_get_contents($filepath);
    if ($raw === false) {
        return ['ok' => false, 'errors' => ['CSV-tiedostoa ei voitu lukea.']];
    }
    if (strtoupper($encoding) !== 'UTF-8') {
        $raw = @iconv($encoding, 'UTF-8//TRANSLIT', $raw);
        if ($raw === false) {
            return ['ok' => false, 'errors' => ["MerkistÃ¶n muunto epÃ¤onnistui ($encoding â†’ UTF-8)."]];
        }
    }

    // Delimiterin automaattinen haku ensimmÃ¤isestÃ¤ ei-tyhjÃ¤stÃ¤ rivistÃ¤
    $detected = ',';
    $fh = fopen('php://memory', 'w+');
    fwrite($fh, $raw);
    rewind($fh);

    $firstLine = '';
    $pos = ftell($fh);
    while (!feof($fh)) {
        $probe = fgets($fh);
        if ($probe !== false && trim($probe) !== '') {
            $firstLine = $probe;
            break;
        }
    }
    fseek($fh, $pos);
    $detected = oas_autodetect_delimiter($firstLine);

    $delim = $delimiter === 'auto'
        ? $detected
        : ($delimiter === '\\t' ? "\t" : $delimiter);

    $pdo->beginTransaction();

    // INSERT IGNORE, jotta duplikaatit eivÃ¤t heitÃ¤ poikkeusta
    $sql = "INSERT IGNORE INTO oas
         (account,campaign,contract_nr,date_played,hours,block,code,commercial,length_sec,edition,raw_length)
        VALUES
        (COALESCE(:account,''),COALESCE(:campaign,''),COALESCE(:contract_nr,''),
        COALESCE(:date_played,''),COALESCE(:hours,''),COALESCE(:block,''),
        COALESCE(:code,''),COALESCE(:commercial,''),:length_sec,COALESCE(:edition,''),COALESCE(:raw_length,''))";

    $stmt = $pdo->prepare($sql);

    $rowNum = 0;
    $firstRowSkipped = false;

    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        $rowNum++;

        // Lippu: lisÃ¤ttiinkÃ¶ tÃ¤lle riville tyhjÃ¤ contract_nr header-heuristiikalla
        $contractPadded = false;

        // TyhjÃ¤ rivi pois
        $nonEmpty = array_filter($row, fn($v) => trim((string) $v) !== '');
        if (!$nonEmpty)
            continue;

        // Otsikkorivit pois
        if (($hasHeader && !$firstRowSkipped) || oas_is_header_row($row)) {

            // Talleta headerin sarakemÃ¤Ã¤rÃ¤ ja contract_nr-sarakkeen indeksi (jos lÃ¶ytyy)
            if ($headerColCount === null) {
                $headerColCount = count($row);
                $lower = array_map(
                    static fn($v) => is_string($v) ? strtolower(trim($v)) : $v,
                    $row
                );
                foreach ($lower as $idx => $c) {
                    if (in_array($c, ['contract nr', 'contract_nr'], true)) {
                        $headerContractIdx = $idx;
                        break;
                    }
                }
            }

            $firstRowSkipped = true;
            $skipped_headers++;
            continue;
        }

        // Jos headerissa on contract_nr ja tällä rivillä on yksi sarake vähemmän,
        // oletetaan että puuttuva sarake on contract_nr ja lisätään tyhjä arvo.
        // HUOM: Tarkistetaan myös tapaus jossa rivillä on sama määrä kenttiä kuin headerissa,
        // mutta contract_nr-indeksillä on päivämäärä (eli sarake on jätetty pois).
        $needsPadding = false;

        if ($headerColCount !== null && $headerContractIdx !== null) {
            // Tapaus 1: Yksi sarake vähemmän kuin headerissa
            if (count($row) === $headerColCount - 1) {
                $needsPadding = true;
            }
            // Tapaus 2: Sama määrä sarakkeita, mutta contract_nr-positio näyttää päivämäärältä
            // (eli contract_nr on jätetty pois ja muut kentät siirtyneet vasemmalle)
            elseif (count($row) === $headerColCount && $headerContractIdx < count($row)) {
                $valueAtContractPos = trim((string) ($row[$headerContractIdx] ?? ''));
                // Jos contract_nr-positiossa on päivämäärä (d.m.Y tai m/d/Y muoto), 
                // tämä on merkki että sarake puuttuu
                if (preg_match('~^\d{1,2}[./]\d{1,2}[./]\d{2,4}$~', $valueAtContractPos)) {
                    $needsPadding = true;
                }
            }
        }

        if ($needsPadding) {
            $fixed = [];
            $j = 0;
            for ($i = 0; $i < $headerColCount; $i++) {
                if ($i === $headerContractIdx) {
                    // <empty> contract_nr
                    $fixed[] = '';
                } else {
                    $fixed[] = $row[$j] ?? '';
                    $j++;
                }
            }
            $row = $fixed;
            $contractPadded = true;
        }


        // Ota talteen esikatseluun ensimmÃ¤iset N raakadata-riviÃ¤ (jo padattu versio)
        if (count($preview_raw) < $previewN) {
            $preview_raw[] = array_values($row);
        }

        // Normalisoi rivi
        $norm = oas_normalize_row($row, $rowNum, $errors, $dateFmt);
        if (!$norm)
            continue;

        [$account, $campaign, $contractNr, $date, $hours, $block, $code, $commercial, $lengthRaw, $edition] = $norm;
        $dateSql = oas_parse_date($date, $dateFmt);
        $lenSec = oas_parse_length_to_seconds($lengthRaw);
        $edition = ($edition === '') ? null : $edition;

        // Jos contract_nr tuli riviltÃ¤ itseltÃ¤Ã¤n (EI padattu header-heuristiikalla),
        // voidaan yrittÃ¤Ã¤ pÃ¤Ã¤tellÃ¤ sitÃ¤ campaignista vanhaan tapaan.
        if ((!$contractNr || $contractNr === '') && $campaign && !$contractPadded) {
            [$campaign2, $contract2] = oas_split_campaign_contract($campaign);
            if ($contract2 && !$contractNr) {
                $campaign = $campaign2;
                $contractNr = $contract2;
            }
        }


        $total++; // lasketaan mukaan vain kelvolliset datarivit

        // EsikatselunÃ¤yte normalisoiduista arvoista
        if (count($preview) < $previewN) {
            $preview[] = [
                'account' => $account,
                'campaign' => $campaign,
                'contract_nr' => $contractNr,
                'date_played' => $dateSql,
                'hours' => $hours,
                'block' => $block,
                'code' => $code,
                'commercial' => $commercial,
                'length_sec' => $lenSec,
                'edition' => $edition,
                'raw_length' => $lengthRaw
            ];
        }

        // --- normalisoi / validoi DB:hen menevÃ¤t arvot ---
        $account = nz_str($account);
        $campaign = nz_str($campaign);
        $contractNr = nz_str($contractNr);
        $hours = nz_time($hours);
        $block = nz_str($block);
        $code = nz_str($code);
        $commercial = nz_str($commercial);
        $edition = nz_str($edition);

        // date_played pitÃ¤Ã¤ olla parsittu
        $dateSql = oas_parse_date($date, $dateFmt) ?? '';

        // length_sec sallitaan 0 (ei NULL)
        $lenSec = (int) max(0, (int) ($lenSec ?? 0));

        // Pakolliset: jos jokin nÃ¤istÃ¤ puuttuu, SKIP
        $requiredMissing = (
            $account === '' ||
            $campaign === '' ||
            $dateSql === '' ||
            $hours === '' ||
            $block === '' ||
            $code === '' ||
            $commercial === '' ||
            $edition === ''
        );

        if ($requiredMissing) {
            $errors[] = sprintf(
                "Rivi %d: puuttuu pakollisia kenttiÃ¤ (Null-estetty uniikki indeksiin). %d Raaka: %s",
                $rowNum,
                count($row),
                oas_row_to_string($row)
            );
            continue;
        }

        try {
            $stmt->execute([
                ':account' => $account,
                ':campaign' => $campaign,
                ':contract_nr' => $contractNr,
                ':date_played' => $dateSql,
                ':hours' => $hours,
                ':block' => $block,
                ':code' => $code,
                ':commercial' => $commercial,
                ':length_sec' => $lenSec,       // int, ei NULL
                ':edition' => $edition,
                ':raw_length' => nz_str($lengthRaw),
            ]);

            if ($stmt->rowCount() === 1) {
                $inserted++;
            } else {
                $duplicates++;
                $errors[] = sprintf(
                    "Rivi %d: duplikaatti (ei lisÃ¤tty; sama rivi jo kannassa) %d. Raaka: %s",
                    $rowNum,
                    count($row),
                    oas_row_to_string($row)
                );
            }
        } catch (Throwable $e) {
            $errors[] = "Rivi {$rowNum}: " . $e->getMessage();
        }
    }

    fclose($fh);

    // Ã„LÃ„ rollaa takaisin yksittÃ¤isten rivivirheiden takia,
    // ne on jo ohitettu ja raportoitu $errors-taulukossa.
    try {
        $pdo->commit();
    } catch (Throwable $e) {
        // Jos commit oikeasti epÃ¤onnistuu, kerrotaan se UI:lle
        $errors[] = "Commit-virhe: " . $e->getMessage();
    }


    /**
     * --- Kirjoita virheet myÃ¶s lokiin ja KERRO UI:lle minne kirjoitettiin ---
     * 1) Ensisijainen: OAS_ERROR_LOG_DIR/oas_import_errors.log
     * 2) Fallback: <projektijuuri>/logs/oas_import_errors.log  (dirname(__DIR__, 2) . '/logs')
     * UI:n errors-taulukkoon lisÃ¤tÃ¤Ã¤n lopuksi rivi, josta nÃ¤et polun + tilan.
     */
    if (!empty($errors)) {
        $ts = date('Y-m-d H:i:s');
        $payload = "[$ts] " . count($errors) . " virhettÃ¤ OAS-tuonnissa:\n"
            . implode("\n", $errors) . "\n\n";

        // 1) Ensisijainen kohde
        $logDir1 = OAS_ERROR_LOG_DIR;
        $logFile1 = rtrim($logDir1, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'oas_import_errors.log';

        // Varmista hakemisto
        if (!is_dir($logDir1)) {
            @mkdir($logDir1, 0775, true);
        }

        $w1 = @file_put_contents($logFile1, $payload, FILE_APPEND);

        if ($w1 !== false) {
            $errors[] = "Virheet kirjattu tiedostoon: " . (realpath($logFile1) ?: $logFile1);
            $errors[] = "__DIR__ = " . (realpath(__DIR__) ?: __DIR__);
        } else {
            // 2) Fallback: projektijuuri/logs
            $logDir2 = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs';
            $logFile2 = $logDir2 . DIRECTORY_SEPARATOR . 'oas_import_errors.log';
            if (!is_dir($logDir2)) {
                @mkdir($logDir2, 0775, true);
            }
            $w2 = @file_put_contents($logFile2, $payload, FILE_APPEND);

            if ($w2 !== false) {
                $errors[] = "Ensisijainen lokitus epÃ¤onnistui, Fallback OK: " . (realpath($logFile2) ?: $logFile2);
                $errors[] = "__DIR__ = " . (realpath(__DIR__) ?: __DIR__);
            } else {
                $last1 = error_get_last();
                $errors[] = "Lokitiedoston kirjoitus epÃ¤onnistui molempiin kohteisiin.";
                $errors[] = "Yritetty: " . $logFile1 . " sekÃ¤ " . $logFile2;
                if ($last1 && !empty($last1['message'])) {
                    $errors[] = "Viimeisin PHP-virhe: " . $last1['message'];
                }
                $errors[] = "__DIR__ = " . (realpath(__DIR__) ?: __DIR__);
            }
        }
    }

    return [
        'ok' => empty($errors),
        'inserted' => $inserted,
        'skipped_headers' => $skipped_headers,
        'total' => $total,
        'duplicates' => $duplicates,   // voit nÃ¤yttÃ¤Ã¤ UI:ssa "Ohitettu/virhe = duplicates + count($errors)"
        'errors' => $errors,
        'preview' => $preview,
        'preview_raw' => $preview_raw,
        'delimiter' => ($delim === "\t" ? "\\t" : $delim),
        'detected' => ($detected === "\t" ? "\\t" : $detected),
    ];
}
/**
 * OAS-esikatselu (ei talletusta kantaan).
 * Palauttaa JSON:ia varten:
 *  - ok, delimiter, detected, header_raw (jos lÃ¶ytyi), header_norm (vakionimet),
 *  - rows: taulukko raakadata-rivejÃ¤ (max preview_rows),
 *  - note: infotekstiÃ¤
 */
function oas_preview(string $filepath, array $opts): array
{
    $encoding = $opts['encoding'] ?? 'UTF-8';
    $delimiter = $opts['delimiter'] ?? 'auto'; // 'auto' | ',' | ';' | '\t' | '|'
    $hasHeader = !empty($opts['has_header']);
    $previewN = (int) ($opts['preview_rows'] ?? 3);
    $dateFmt = $opts['date_format'] ?? null;

    $raw = @file_get_contents($filepath);
    if ($raw === false) {
        return ['ok' => false, 'error' => 'CSV-tiedostoa ei voitu lukea.'];
    }
    if (strtoupper($encoding) !== 'UTF-8') {
        $raw = @iconv($encoding, 'UTF-8//TRANSLIT', $raw);
        if ($raw === false) {
            return ['ok' => false, 'error' => "Merkistön muunto epäonnistui ($encoding → UTF-8)."];
        }
    }

    $fh = fopen('php://memory', 'w+');
    fwrite($fh, $raw);
    rewind($fh);

    // Autodetect
    $firstLine = '';
    $pos = ftell($fh);
    while (!feof($fh)) {
        $probe = fgets($fh);
        if ($probe !== false && trim($probe) !== '') {
            $firstLine = $probe;
            break;
        }
    }
    fseek($fh, $pos);

    $detected = oas_autodetect_delimiter($firstLine);
    $delim = ($delimiter === 'auto') ? $detected : ($delimiter === '\\t' ? "\t" : $delimiter);

    $rows_raw = [];
    $rows_norm = [];
    $header_raw = null;
    $header_norm = null;
    $firstRowHandled = false;

    // Headerin sarakemäärä ja contract_nr-sarakkeen indeksi (kuten import-funktiossa)
    $headerColCount = null;
    $headerContractIdx = null;

    $take = max(1, $previewN);

    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        // lippu: lisättiinkö tälle riville tyhjä contract_nr
        $contractPadded = false;

        $nonEmpty = array_filter($row, fn($v) => trim((string) $v) !== '');
        if (!$nonEmpty) {
            continue;
        }

        if (!$firstRowHandled) {
            $firstRowHandled = true;
            if ($hasHeader || oas_is_header_row($row)) {
                $header_raw = array_values($row);

                // heuristinen nimikartta
                $lower = array_map(fn($v) => is_string($v) ? strtolower(trim($v)) : $v, $row);
                $map = [];
                foreach ($lower as $c) {
                    $map[] = match (true) {
                        $c === 'account' => 'account',
                        $c === 'campaign' => 'campaign',
                        in_array($c, ['contract nr', 'contract_nr']) => 'contract_nr',
                        in_array($c, ['date', 'date played', 'date_played']) => 'date',
                        in_array($c, ['hour', 'hours']) => 'hours',
                        $c === 'block' => 'block',
                        $c === 'code' => 'code',
                        $c === 'commercial' => 'commercial',
                        in_array($c, ['length', 'length_sec', 'len', 'duration']) => 'length',
                        $c === 'edition' => 'edition',
                        default => $c,
                    };
                }
                $header_norm = $map;

                // talletetaan headerin sarakemäärä ja contract_nr-sarakkeen indeksi
                $headerColCount = count($row);
                foreach ($lower as $idx => $c) {
                    if (in_array($c, ['contract nr', 'contract_nr'], true)) {
                        $headerContractIdx = $idx;
                        break;
                    }
                }

                // header-riviä ei lisätä dataan, jatka seuraavaan riviin
                continue;
            }
        }

        // Jos headerissa on contract_nr ja tällä rivillä on yksi sarake vähemmän,
        // oletetaan että puuttuva sarake on contract_nr ja lisätään tyhjä arvo.
        // Tapaus 2: sama määrä sarakkeita, mutta contract_nr-positio näyttää päivämäärältä.
        $needsPadding = false;

        if ($headerColCount !== null && $headerContractIdx !== null) {
            // Tapaus 1: yksi sarake vähemmän kuin headerissa
            if (count($row) === $headerColCount - 1) {
                $needsPadding = true;
            }
            // Tapaus 2: sama määrä sarakkeita, mutta contract_nr-positiossa on päivämäärä
            elseif (count($row) === $headerColCount && $headerContractIdx < count($row)) {
                $valueAtContractPos = trim((string) ($row[$headerContractIdx] ?? ''));
                if (preg_match('~^\d{1,2}[./]\d{1,2}[./]\d{2,4}$~', $valueAtContractPos)) {
                    $needsPadding = true;
                }
            }
        }

        if ($needsPadding) {
            $fixed = [];
            $j = 0;
            for ($i = 0; $i < $headerColCount; $i++) {
                if ($i === $headerContractIdx) {
                    $fixed[] = ''; // tyhjä contract_nr
                } else {
                    $fixed[] = $row[$j] ?? '';
                    $j++;
                }
            }
            $row = $fixed;
            $contractPadded = true;
        }

        // Raakaesikatselu (padattu rivi)
        if (count($rows_raw) < $take) {
            $rows_raw[] = array_values($row);
        }

        // Normalisoitu esikatselu (samat säännöt kuin importissa)
        if (count($rows_norm) < $take) {
            $errorsTmp = [];
            $norm = oas_normalize_row($row, 0, $errorsTmp, $dateFmt);
            if ($norm) {
                [$account, $campaign, $contractNr, $date, $hours, $block, $code, $commercial, $lengthRaw, $edition] = $norm;
                $dateSql = oas_parse_date($date, $dateFmt);
                $lenSec = oas_parse_length_to_seconds($lengthRaw);
                $edition = ($edition === '') ? null : $edition;

                if ((!$contractNr || $contractNr === '') && $campaign && !$contractPadded) {
                    [$campaign2, $contract2] = oas_split_campaign_contract($campaign);
                    if ($contract2 && !$contractNr) {
                        $campaign = $campaign2;
                        $contractNr = $contract2;
                    }
                }

                $rows_norm[] = [
                    'account' => $account,
                    'campaign' => $campaign,
                    'contract_nr' => $contractNr,
                    'date_played' => $dateSql,
                    'hours' => $hours,
                    'block' => $block,
                    'code' => $code,
                    'commercial' => $commercial,
                    'length_sec' => $lenSec,
                    'edition' => $edition,
                    'raw_length' => $lengthRaw,
                ];
            }
        }

        if (count($rows_raw) >= $take && count($rows_norm) >= $take) {
            break;
        }
    }

    fclose($fh);

    return [
        'ok' => true,
        'delimiter' => ($delim === "\t" ? "\\t" : $delim),
        'detected' => ($detected === "\t" ? "\\t" : $detected),
        'header_raw' => $header_raw ?? [],
        'header_norm' => $header_norm ?? [],
        'rows' => $rows_raw,   // raaka
        'rows_norm' => $rows_norm,  // normalisoitu
        'note' => 'Esikatselu näyttää sekä raakadatan että normalisoidun näytteen samoilla säännöillä kuin import.',
    ];
}
// apuri: tyhjÃ¤ksi merkkijonoksi eikÃ¤ ikinÃ¤ NULL
function nz_str($v): string
{
    $v = is_string($v) ? trim($v) : (string) $v;
    return ($v === '') ? '' : $v;
}

// apuri: aika "HH:MM:SS" tai tyhjÃ¤
// apuri: aika "HH" (vain tunti), tai tyhjÃ¤; hyvÃ¤ksyy myÃ¶s H:MM ja H:MM:SS ja ottaa vain tunnin osan
function nz_time($v): string
{
    $t = is_string($v) ? trim($v) : (string) $v;
    if ($t === '')
        return '';
    // HyvÃ¤ksy pelkkÃ¤ tunti (0â€“23)
    if (preg_match('~^\d{1,2}$~', $t)) {
        return (string) (int) $t;
    }
    // HyvÃ¤ksy muodot H:MM tai H:MM:SS, ja palauta vain tunti
    if (preg_match('~^(\d{1,2}):(\d{2})(?::(\d{2}))?$~', $t, $m)) {
        return (string) (int) $m[1];
    }
    return '';
}

/**
 * Kirjoita loki turvallisesti. Tekee fallbackin /tmp:iin jos projektin logs/ ei ole kirjoitettavissa.
 * Palauttaa lopullisen polun jonne kirjoitettiin (tai heittÃ¤Ã¤ poikkeuksen).
 */
function oas_log_write(string $basename, array $errors): string
{
    if (empty($errors))
        return '';

    // 1) Ensisijainen: PROJECT_ROOT/logs/
    // Oleta ettÃ¤ tÃ¤mÃ¤ tiedosto on polussa .../include/importers/oas_lib.php
    $root = dirname(__DIR__, 2); // kaksi tasoa ylÃ¶s -> projektijuuri
    $logDir = $root . DIRECTORY_SEPARATOR . 'logs';
    $target = $logDir . DIRECTORY_SEPARATOR . $basename;

    // Luo logs/-hakemisto, jos puuttuu
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $ts = date('Y-m-d H:i:s');
    $content = "[$ts] " . count($errors) . " virhettÃ¤ OAS-tuonnissa:\n" .
        implode("\n", $errors) . "\n\n";

    $wrote = false;
    $lastErr = null;

    if (is_dir($logDir) && is_writable($logDir)) {
        $wrote = @file_put_contents($target, $content, FILE_APPEND);
        if ($wrote === false) {
            $lastErr = error_get_last();
        }
    } else {
        $lastErr = ['message' => 'Log directory not writable'];
    }

    // 2) Fallback: /tmp
    if ($wrote === false) {
        $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $fallback = $tmp . DIRECTORY_SEPARATOR . $basename;
        $wrote = @file_put_contents($fallback, $content, FILE_APPEND);
        if ($wrote === false) {
            $lastErr2 = error_get_last();
            // Ã„lÃ¤ hiljennÃ¤: heitÃ¤ poikkeus -> nÃ¤kyy UI:ssa
            throw new RuntimeException(
                'Lokitiedoston kirjoitus epÃ¤onnistui. ' .
                'Yritetty: ' . $target . ' (' . ($lastErr['message'] ?? 'tuntematon virhe') . '), ' .
                'ja fallback: ' . $fallback . ' (' . ($lastErr2['message'] ?? 'tuntematon virhe') . '). ' .
                'Tee logs/-kansiosta palvelimen kÃ¤yttÃ¤jÃ¤lle kirjoitettava.'
            );
        }
        return $fallback;
    }

    return $target;
}


