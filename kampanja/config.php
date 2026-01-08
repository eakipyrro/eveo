<?php
// Tietokannan asetukset
define('DB_HOST', 'localhost');
define('DB_USER', 'fissifi_eveo_admin');  // Vaihda tähän tietokantasi käyttäjänimi
define('DB_PASS', '23ufa3}A1CaG%Ica');      // Vaihda tähän tietokantasi salasana
define('DB_NAME', 'fissifi_eveo_myynti_kampanja'); // Vaihda tähän tietokantasi nimi

// Sähköpostin asetukset (voit muuttaa admin-paneelista)
define('SMTP_HOST', 'localhost');
define('SMTP_PORT', 587);
define('SMTP_USER', 'noreply@yourdomain.com');
define('SMTP_PASS', 'password');
define('SMTP_FROM', 'noreply@yourdomain.com');
define('SMTP_FROM_NAME', 'Myyntikampanja');

// Tiedostojen latauspolku
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_FILE_SIZE', 1073741824); // 1GB

// Virheenkäsittelyn asetukset (tuotannossa aseta false)
define('DEBUG_MODE', true);

// Aikavyöhyke
date_default_timezone_set('Europe/Helsinki');

// Yhteys tietokantaan
function getDBConnection() {
    try {
        $conn = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $conn;
    } catch(PDOException $e) {
        if (DEBUG_MODE) {
            die("Tietokantayhteys epäonnistui: " . $e->getMessage());
        } else {
            die("Tietokantayhteys epäonnistui. Ota yhteyttä ylläpitoon.");
        }
    }
}

// Apufunktio turvalliseen HTML-tulostukseen
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Apufunktio JSON-vastaukseen
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
?>
