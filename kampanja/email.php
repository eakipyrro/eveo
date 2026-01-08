<?php
require_once 'config.php';

function lahetaSahkoposti($kampanja, $liitetiedostoPolku = null) {
    try {
        $conn = getDBConnection();
        
        // Hae sähköpostiasetukset
        $stmt = $conn->prepare("SELECT * FROM sahkoposti_asetukset WHERE aktiivinen = 1 ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $asetukset = $stmt->fetch();
        
        if (!$asetukset || empty($asetukset['vastaanottajat'])) {
            error_log('Ei vastaanottajia määritelty');
            return false;
        }
        
        $vastaanottajat = explode(',', $asetukset['vastaanottajat']);
        $vastaanottajat = array_map('trim', $vastaanottajat);
        $vastaanottajat = array_filter($vastaanottajat);
        
        if (empty($vastaanottajat)) {
            return false;
        }
        
        // Luo sähköpostin sisältö
        $aihe = "Uusi myyntikampanja: " . ($kampanja['kampanjan_nimi'] ?? 'Nimetön kampanja');
        $viesti = luoSahkopostiHTML($kampanja);
        
        // Luo MIME-rajapinta liitetiedostolle
        $boundary = md5(time());
        
        // Headers
        $headers = [];
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";
        $headers[] = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM . ">";
        $headers[] = "Reply-To: " . SMTP_FROM;
        $headers[] = "X-Mailer: PHP/" . phpversion();
        
        // Luo viestin runko
        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $viesti . "\r\n\r\n";
        
        // Lisää liitetiedosto jos on olemassa
        if ($liitetiedostoPolku && file_exists($liitetiedostoPolku)) {
            $tiedostoNimi = basename($liitetiedostoPolku);
            $tiedostoSisalto = file_get_contents($liitetiedostoPolku);
            $tiedostoSisalto = chunk_split(base64_encode($tiedostoSisalto));
            
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: application/octet-stream; name=\"{$tiedostoNimi}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Disposition: attachment; filename=\"{$tiedostoNimi}\"\r\n\r\n";
            $body .= $tiedostoSisalto . "\r\n\r\n";
        }
        
        $body .= "--{$boundary}--";
        
        // Lähetä sähköposti jokaiselle vastaanottajalle
        $onnistui = true;
        foreach ($vastaanottajat as $vastaanottaja) {
            if (!mail($vastaanottaja, $aihe, $body, implode("\r\n", $headers))) {
                $onnistui = false;
                error_log("Sähköpostin lähetys epäonnistui vastaanottajalle: " . $vastaanottaja);
            }
        }
        
        return $onnistui;
        
    } catch (Exception $e) {
        error_log("Virhe sähköpostin lähetyksessä: " . $e->getMessage());
        return false;
    }
}

function luoSahkopostiHTML($kampanja) {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="fi">
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            h2 { color: #2c3e50; }
            h3 { color: #34495e; margin-top: 20px; }
            p { margin: 5px 0; }
            strong { color: #2c3e50; }
            hr { margin: 20px 0; border: none; border-top: 1px solid #ccc; }
            .footer { font-size: 12px; color: #666; margin-top: 30px; }
        </style>
    </head>
    <body>
        <h2>Uusi myyntikampanja vahvistettu</h2>
        
        <h3>Perustiedot</h3>
        <p><strong>Myyjä:</strong> <?= h($kampanja['myyja'] ?? '-') ?></p>
        <p><strong>Päivämäärä:</strong> <?= h($kampanja['paivamaara'] ?? '-') ?></p>
        
        <h3>Asiakas</h3>
        <p><strong>Laskutettava asiakas:</strong> <?= h($kampanja['laskutettava_asiakas'] ?? '-') ?></p>
        <p><strong>Y-tunnus:</strong> <?= h($kampanja['ytunnus'] ?? '-') ?></p>
        <p><strong>Laskutusosoite:</strong> <?= h($kampanja['laskutusosoite'] ?? '-') ?></p>
        <p><strong>Poikkeava laskutus:</strong> <?= h($kampanja['poikkeava_laskutus'] ?? '-') ?></p>
        <p><strong>Viitetieto:</strong> <?= h($kampanja['viitetieto'] ?? '-') ?></p>
        <p><strong>Lisähuomio:</strong> <?= h($kampanja['lisahuomio'] ?? '-') ?></p>
        <p><strong>Laskutusväli:</strong> <?= h($kampanja['laskutusvali'] ?? '-') ?></p>
        
        <h3>Yhteyshenkilö</h3>
        <p><strong>Nimi:</strong> <?= h($kampanja['yhteyshenkilo_nimi'] ?? '-') ?></p>
        <p><strong>Titteli:</strong> <?= h($kampanja['yhteyshenkilo_titteli'] ?? '-') ?></p>
        <p><strong>Sähköposti:</strong> <?= h($kampanja['yhteyshenkilo_email'] ?? '-') ?></p>
        <p><strong>Puhelin:</strong> <?= h($kampanja['yhteyshenkilo_puhelin'] ?? '-') ?></p>
        <?php if (!empty($kampanja['lisasahkoposti'])): ?>
        <p><strong>Lisäsähköposti:</strong> <?= h($kampanja['lisasahkoposti']) ?></p>
        <?php endif; ?>
        
        <h3>Kampanja</h3>
        <p><strong>Mainostava yritys:</strong> <?= h($kampanja['mainostava_yritys'] ?? '-') ?></p>
        <p><strong>Kampanjan nimi:</strong> <?= h($kampanja['kampanjan_nimi'] ?? '-') ?></p>
        <p><strong>Kampanjan tyyppi:</strong> <?= h($kampanja['kampanjan_tyyppi'] ?? '-') ?></p>
        <p><strong>Ohjelma:</strong> <?= h($kampanja['ohjelma'] ?? '-') ?></p>
        
        <h3>Aika ja tunnisteet</h3>
        <p><strong>OHY aloituspäivä:</strong> <?= h($kampanja['ohy_aloitus'] ?? '-') ?></p>
        <p><strong>OHY päättymispäivä:</strong> <?= h($kampanja['ohy_paattyminen'] ?? '-') ?></p>
        <p><strong>Alku-/lopputunnisteet:</strong> <?= h($kampanja['alku_loppu_tunnisteet'] ?? '-') ?></p>
        <p><strong>Katkotunnisteet:</strong> <?= h($kampanja['katkotunnisteet'] ?? '-') ?></p>
        
        <?php if (!empty($kampanja['spotti_aloitus']) || !empty($kampanja['spotin_pituus_1'])): ?>
        <h3>Spottikampanja</h3>
        <p><strong>Aloituspäivä:</strong> <?= h($kampanja['spotti_aloitus'] ?? '-') ?></p>
        <p><strong>Päättymispäivä:</strong> <?= h($kampanja['spotti_paattyminen'] ?? '-') ?></p>
        <?php if (!empty($kampanja['spotin_pituus_1'])): ?>
        <p><strong>Spotin pituus #1:</strong> <?= h($kampanja['spotin_pituus_1']) ?></p>
        <p><strong>Spottien määrä #1:</strong> <?= h($kampanja['spottien_maara_1'] ?? '-') ?></p>
        <?php endif; ?>
        <?php if (!empty($kampanja['spotin_pituus_2'])): ?>
        <p><strong>Spotin pituus #2:</strong> <?= h($kampanja['spotin_pituus_2']) ?></p>
        <p><strong>Spottien määrä #2:</strong> <?= h($kampanja['spottien_maara_2'] ?? '-') ?></p>
        <?php endif; ?>
        <?php if (!empty($kampanja['spotin_pituus_3'])): ?>
        <p><strong>Spotin pituus #3:</strong> <?= h($kampanja['spotin_pituus_3']) ?></p>
        <p><strong>Spottien määrä #3:</strong> <?= h($kampanja['spottien_maara_3'] ?? '-') ?></p>
        <?php endif; ?>
        <?php if (!empty($kampanja['spotin_pituus_4'])): ?>
        <p><strong>Spotin pituus #4:</strong> <?= h($kampanja['spotin_pituus_4']) ?></p>
        <p><strong>Spottien määrä #4:</strong> <?= h($kampanja['spottien_maara_4'] ?? '-') ?></p>
        <?php endif; ?>
        <?php if (!empty($kampanja['ostettu_trp'])): ?>
        <p><strong>Ostettu TRP:</strong> <?= h($kampanja['ostettu_trp']) ?></p>
        <?php endif; ?>
        <?php endif; ?>
        
        <?php if (!empty($kampanja['toteutuneet_esitykset']) || !empty($kampanja['kommentit'])): ?>
        <h3>Lisätiedot</h3>
        <?php if (!empty($kampanja['toteutuneet_esitykset'])): ?>
        <p><strong>Toteutuneet esitykset:</strong> <?= h($kampanja['toteutuneet_esitykset']) ?></p>
        <?php endif; ?>
        <?php if (!empty($kampanja['kommentit'])): ?>
        <p><strong>Kommentit:</strong> <?= h($kampanja['kommentit']) ?></p>
        <?php endif; ?>
        <?php endif; ?>
        
        <h3>Hinnoittelu</h3>
        <p><strong>Bruttohinta:</strong> <?= number_format($kampanja['bruttohinta'] ?? 0, 2, ',', ' ') ?> €</p>
        <?php if (!empty($kampanja['mediatoimistoalennus'])): ?>
        <p><strong>Mediatoimistoalennus:</strong> <?= h($kampanja['mediatoimistoalennus']) ?>%</p>
        <?php endif; ?>
        <?php if (!empty($kampanja['asiakasalennus'])): ?>
        <p><strong>Asiakasalennus:</strong> <?= h($kampanja['asiakasalennus']) ?>%</p>
        <?php endif; ?>
        <?php if (!empty($kampanja['muu_alennus'])): ?>
        <p><strong>Muu alennus:</strong> <?= h($kampanja['muu_alennus']) ?>%</p>
        <?php endif; ?>
        <p><strong>Nettohinta:</strong> <?= number_format($kampanja['nettohinta'] ?? 0, 2, ',', ' ') ?> €</p>
        
        <hr>
        <p class="footer">Tämä viesti on lähetetty automaattisesti myyntikampanjan vahvistusjärjestelmästä.</p>
    </body>
    </html>
    <?php
    return ob_get_clean();
}
?>
