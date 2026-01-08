<?php
// Varmista että kirjasto tietää minne lokittaa:
if (!defined('OAS_ERROR_LOG_DIR')) {
    // include/importers/ -kansiosta kaksi pykälää ylöspäin -> projektijuuri
    $fallback = realpath(__DIR__ . '/../../logs');
    if (!$fallback) {
        $fallback = __DIR__ . '/../../logs';
        @mkdir($fallback, 0775, true);
    }
    define('OAS_ERROR_LOG_DIR', $fallback);
}

// Yhtenäinen oas_log()-apuri; ei riko nykyisiä error_log()-kutsuja,
// mutta voit halutessa korvata ne oas_log():lla.
if (!function_exists('oas_log')) {
    function oas_log(string $msg): void
    {
        $file = rtrim(OAS_ERROR_LOG_DIR, '/\\') . '/oas_import_errors.log';
        // Yritä avata; jos ei onnistu, fallback perinteiseen error_logiin
        $prefix = '[OAS] ';
        if (is_dir(OAS_ERROR_LOG_DIR) && is_writable(OAS_ERROR_LOG_DIR)) {
            @file_put_contents($file, $prefix . $msg . PHP_EOL, FILE_APPEND);
        } else {
            @error_log($prefix . $msg);
        }
    }
}

// Kirjaa fataalit shutdownissa, jotta hiljaiset kuolemat näkyvät
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        oas_log('FATAL: ' . json_encode($e, JSON_UNESCAPED_UNICODE));
    }
});
// include/importers/oas_lib.php
// Yhtenäinen OAS CSV -kirjasto (parsinta + normalisointi + tuonti) EVEOlle.

if (!defined('FISSI_EVEO')) {
    define('FISSI_EVEO', true);
}
// Missä virheloki pidetään. Oletus: tämän tiedoston hakemisto (include/importers)
if (!defined('OAS_ERROR_LOG_DIR')) {
    define('OAS_ERROR_LOG_DIR', __DIR__);
}

/**
 * Muuttaa rivin luettavaan tekstiin virhelokia varten.
 */
function oas_row_to_string(array $row): string
{
    // Otetaan arvot järjestyksessä ja muutetaan stringiksi
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

    // Jos preferoitu muoto annettu, kokeile ensin sitä.
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
    if (preg_match('~^(.*?)[\s\-\#]*(\d{4,10})$~u', $f, $m))
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
            "Rivi %d: liian vähän sarakkeita (%d). Raaka: %s",
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

    // CASE B: "Contract puuttuu" – teidän CSV-variantti
    $dateAt2 = oas_parse_date($row[2] ?? null, $preferredDateFmt);
    if ($dateAt2 !== null && $isHour($row[3] ?? null)) {
        $edition = $row[8] ?? null;
        if ($n >= 9) {
            return [$row[0], $row[1], null, $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], $edition];
        } else {
            return [$row[0], $row[1], null, $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], null];
        }
    }

    // CASE A: "Normaali" – contract kolmantena
    $dateAt3 = oas_parse_date($row[3] ?? null, $preferredDateFmt);
    if ($dateAt3 !== null && $isHour($row[4] ?? null)) {
        $edition = $row[9] ?? null;
        return [$row[0], $row[1], $row[2] ?? null, $row[3], $row[4], $row[5], $row[6], $row[7], $row[8] ?? null, $edition];
    }

    //$errors[] = "Rivi {$rowNum}: sarakejärjestystä ei voitu tulkita luotettavasti.";
    $errors[] = sprintf(
        "Rivi %d: sarakejärjestystä ei voitu tlkita luotettavasti. %d Raaka: %s",
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


    // Lue koko tiedosto valittuun merkistöön (UTF-8 sisäisesti)
    $raw = @file_get_contents($filepath);
    if ($raw === false) {
        return ['ok' => false, 'errors' => ['CSV-tiedostoa ei voitu lukea.']];
    }
    if (strtoupper($encoding) !== 'UTF-8') {
        $raw = @iconv($encoding, 'UTF-8//TRANSLIT', $raw);
        if ($raw === false) {
            return ['ok' => false, 'errors' => ["Merkistön muunto epäonnistui ($encoding → UTF-8)."]];
        }
    }

    // Delimiterin automaattinen haku ensimmäisestä ei-tyhjästä rivistä
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

    // INSERT IGNORE, jotta duplikaatit eivät heitä poikkeusta
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

        // Tyhjä rivi pois
        $nonEmpty = array_filter($row, fn($v) => trim((string) $v) !== '');
        if (!$nonEmpty)
            continue;

        // Otsikkorivit pois
        if (($hasHeader && !$firstRowSkipped) || oas_is_header_row($row)) {
            $firstRowSkipped = true;
            $skipped_headers++;
            continue;
        }

        // Ota talteen esikatseluun ensimmäiset N raakadata-riviä
        if (count($preview_raw) < $previewN) {
            $preview_raw[] = array_values($row);
        }

        $norm = oas_normalize_row($row, $rowNum, $errors, $dateFmt);

        if (!$norm)
            continue;

        [$account, $campaign, $contractNr, $date, $hours, $block, $code, $commercial, $lengthRaw, $edition] = $norm;
        $dateSql = oas_parse_date($date, $dateFmt);
        $lenSec = oas_parse_length_to_seconds($lengthRaw);
        $edition = ($edition === '') ? null : $edition;

        if ((!$contractNr || $contractNr === '') && $campaign) {
            [$campaign2, $contract2] = oas_split_campaign_contract($campaign);
            if ($contract2 && !$contractNr) {
                $campaign = $campaign2;
                $contractNr = $contract2;
            }
        }

        $total++; // lasketaan mukaan vain kelvolliset datarivit

        // Esikatselunäyte
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
        // --- normalisoi / validoi ---
        $account = nz_str($account);
        $campaign = nz_str($campaign);
        $contractNr = nz_str($contractNr);
        $hours = nz_time($hours);
        $block = nz_str($block);
        $code = nz_str($code);
        $commercial = nz_str($commercial);
        $edition = nz_str($edition);

        // date_played pitää olla parsittu
        $dateSql = oas_parse_date($date, $dateFmt) ?? '';

        // length_sec sallitaan 0 (ei NULL)
        $lenSec = (int) max(0, (int) ($lenSec ?? 0));

        // Pakolliset: jos jokin näistä puuttuu, SKIP
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
            //            $errors[] = "Rivi {$rowNum}: puuttuu pakollisia kenttiä (NULL-estetty uniikki-indeksiin).";
            $errors[] = sprintf(
                "Rivi %d: puuttuu pakollisia kenttiä (Null-estetty uniikki indeksiin). %d Raaka: %s",
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

            // INSERT IGNORE: 1 = uusi rivi, 0 = duplikaatti (ohitettu)
            if ($stmt->rowCount() === 1) {
                $inserted++;
            } else {
                $duplicates++;
                // Lisätään duplikaatitkin virhelistaan, jotta UI voi näyttää kaikki ohitetut rivit
//                $errors[] = "Rivi {$rowNum}: duplikaatti (ei lisätty; sama rivi jo kannassa).";
                $errors[] = sprintf(
                    "Rivi %d: duplikaatti (ei lisätty; sama rivi jo kannassa) %d. Raaka: %s",
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

    // ÄLÄ rollaa takaisin yksittäisten rivivirheiden takia,
    // ne on jo ohitettu ja raportoitu $errors-taulukossa.
    try {
        $pdo->commit();
    } catch (Throwable $e) {
        // Jos commit oikeasti epäonnistuu, kerrotaan se UI:lle
        $errors[] = "Commit-virhe: " . $e->getMessage();
    }


    /**
     * --- Kirjoita virheet myös lokiin ja KERRO UI:lle minne kirjoitettiin ---
     * 1) Ensisijainen: OAS_ERROR_LOG_DIR/oas_import_errors.log
     * 2) Fallback: <projektijuuri>/logs/oas_import_errors.log  (dirname(__DIR__, 2) . '/logs')
     * UI:n errors-taulukkoon lisätään lopuksi rivi, josta näet polun + tilan.
     */
    if (!empty($errors)) {
        $ts = date('Y-m-d H:i:s');
        $payload = "[$ts] " . count($errors) . " virhettä OAS-tuonnissa:\n"
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
                $errors[] = "Ensisijainen lokitus epäonnistui, Fallback OK: " . (realpath($logFile2) ?: $logFile2);
                $errors[] = "__DIR__ = " . (realpath(__DIR__) ?: __DIR__);
            } else {
                $last1 = error_get_last();
                $errors[] = "Lokitiedoston kirjoitus epäonnistui molempiin kohteisiin.";
                $errors[] = "Yritetty: " . $logFile1 . " sekä " . $logFile2;
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
        'duplicates' => $duplicates,   // voit näyttää UI:ssa "Ohitettu/virhe = duplicates + count($errors)"
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
 *  - ok, delimiter, detected, header_raw (jos löytyi), header_norm (vakionimet),
 *  - rows: taulukko raakadata-rivejä (max preview_rows),
 *  - note: infotekstiä
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

    $take = max(1, $previewN);
    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        $nonEmpty = array_filter($row, fn($v) => trim((string) $v) !== '');
        if (!$nonEmpty)
            continue;

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
                        default => $c
                    };
                }
                $header_norm = $map;
                continue; // seuraavat rivit dataa
            }
        }

        // Raakaesikatselu
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

                if ((!$contractNr || $contractNr === '') && $campaign) {
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

        if (count($rows_raw) >= $take && count($rows_norm) >= $take)
            break;
    }
    fclose($fh);

    return [
        'ok' => true,
        'delimiter' => ($delim === "\t" ? "\\t" : $delim),
        'detected' => ($detected === "\t" ? "\\t" : $detected),
        'header_raw' => $header_raw ?? [],
        'header_norm' => $header_norm ?? [],
        'rows' => $rows_raw,   // raaka
        'rows_norm' => $rows_norm,  // normalisoitu (sis. contract_nr-heuristiikan)
        'note' => 'Esikatselu näyttää sekä raakadatan että normalisoidun näytteen samoilla säännöillä kuin import.',
    ];
}
// apuri: tyhjäksi merkkijonoksi eikä ikinä NULL
function nz_str($v): string
{
    $v = is_string($v) ? trim($v) : (string) $v;
    return ($v === '') ? '' : $v;
}

// apuri: aika "HH:MM:SS" tai tyhjä
// apuri: aika "HH" (vain tunti), tai tyhjä; hyväksyy myös H:MM ja H:MM:SS ja ottaa vain tunnin osan
function nz_time($v): string
{
    $t = is_string($v) ? trim($v) : (string) $v;
    if ($t === '')
        return '';
    // Hyväksy pelkkä tunti (0–23)
    if (preg_match('~^\d{1,2}$~', $t)) {
        return (string) (int) $t;
    }
    // Hyväksy muodot H:MM tai H:MM:SS, ja palauta vain tunti
    if (preg_match('~^(\d{1,2}):(\d{2})(?::(\d{2}))?$~', $t, $m)) {
        return (string) (int) $m[1];
    }
    return '';
}

/**
 * Kirjoita loki turvallisesti. Tekee fallbackin /tmp:iin jos projektin logs/ ei ole kirjoitettavissa.
 * Palauttaa lopullisen polun jonne kirjoitettiin (tai heittää poikkeuksen).
 */
function oas_log_write(string $basename, array $errors): string
{
    if (empty($errors))
        return '';

    // 1) Ensisijainen: PROJECT_ROOT/logs/
    // Oleta että tämä tiedosto on polussa .../include/importers/oas_lib.php
    $root = dirname(__DIR__, 2); // kaksi tasoa ylös -> projektijuuri
    $logDir = $root . DIRECTORY_SEPARATOR . 'logs';
    $target = $logDir . DIRECTORY_SEPARATOR . $basename;

    // Luo logs/-hakemisto, jos puuttuu
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $ts = date('Y-m-d H:i:s');
    $content = "[$ts] " . count($errors) . " virhettä OAS-tuonnissa:\n" .
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
            // Älä hiljennä: heitä poikkeus -> näkyy UI:ssa
            throw new RuntimeException(
                'Lokitiedoston kirjoitus epäonnistui. ' .
                'Yritetty: ' . $target . ' (' . ($lastErr['message'] ?? 'tuntematon virhe') . '), ' .
                'ja fallback: ' . $fallback . ' (' . ($lastErr2['message'] ?? 'tuntematon virhe') . '). ' .
                'Tee logs/-kansiosta palvelimen käyttäjälle kirjoitettava.'
            );
        }
        return $fallback;
    }

    return $target;
}


