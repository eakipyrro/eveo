<?php
$path = __DIR__ . '/oas_import_errors.log';
$ok = file_put_contents($path, "[" . date('H:i:s') . "] test\n", FILE_APPEND);
if ($ok === false) {
    echo "Kirjoitus epäonnistui! Tarkista oikeudet.\n";
} else {
    echo "Kirjoitettu tiedostoon: $path\n";
}
?>
