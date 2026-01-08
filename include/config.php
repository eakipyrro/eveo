<?php
date_default_timezone_set('Europe/Helsinki');

// Yhden istunnon kesto ja evästeasetuksia (tarvittaessa):
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
    // tarvittaessa myös:
    // ini_set('session.use_strict_mode', '1');
    // ini_set('session.cookie_samesite', 'Lax'); // tai 'None' jos käytät kolmannen osapuolen evästeitä ja HTTPS
}
// DB-asetukset
define('DB_HOST', 'www.fissi.fi');
define('DB_NAME', 'fissifi_eveo');
define('DB_USER', 'fissifi_eveo_admin');
define('DB_PASS', '23ufa3}A1CaG%Ica');

// CSRF token -nimi
define('CSRF_KEY', 'csrf_global');

// Kirjautumisen throttle: sallitaan 5 epäonnistunutta / 10 min sähköpostia kohden
define('LOGIN_MAX_FAILS', 5);
define('LOGIN_WINDOW_MIN', 10);

// Roolit – voit käyttää suoraan merkkijonoja, mutta nämä helpottavat keskitystä
const ROLES = ['user', 'eveo', 'manager', 'admin'];
?>