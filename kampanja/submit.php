<?php
require_once 'config.php';
require_once 'email.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Virheellinen pyyntö'], 405);
}

try {
    $conn = getDBConnection();
    
    // Käsittele tiedoston lataus
    $tiedostoNimi = null;
    if (isset($_FILES['tarjous']) && $_FILES['tarjous']['error'] === UPLOAD_ERR_OK) {
        $tiedosto = $_FILES['tarjous'];
        
        // Tarkista tiedoston koko
        if ($tiedosto['size'] > MAX_FILE_SIZE) {
            throw new Exception('Tiedosto on liian suuri. Maksimikoko on 1GB.');
        }
        
        // Tarkista tiedostotyyppi
        $sallitutTyypit = ['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 
                           'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tiedosto['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $sallitutTyypit)) {
            throw new Exception('Virheellinen tiedostotyyppi. Sallitut: PDF, JPG, PNG, DOC, DOCX');
        }
        
        // Luo uploads-kansio jos ei ole olemassa
        if (!file_exists(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0755, true);
        }
        
        // Generoi uniikki tiedostonimi
        $ext = pathinfo($tiedosto['name'], PATHINFO_EXTENSION);
        $tiedostoNimi = uniqid('tarjous_', true) . '.' . $ext;
        $kohde = UPLOAD_DIR . $tiedostoNimi;
        
        if (!move_uploaded_file($tiedosto['tmp_name'], $kohde)) {
            throw new Exception('Tiedoston lataus epäonnistui');
        }
    } else if (!isset($_FILES['tarjous']) || $_FILES['tarjous']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Liitetiedosto on pakollinen');
    }
    
    // Käsittele checkboxit
    $viitetieto = isset($_POST['viitetieto']) && is_array($_POST['viitetieto']) 
        ? implode(', ', $_POST['viitetieto']) 
        : '';
    
    $laskutusvali = isset($_POST['laskutusvali']) && is_array($_POST['laskutusvali'])
        ? implode(', ', $_POST['laskutusvali'])
        : '';
    
    // Tallenna tietokantaan
    $sql = "INSERT INTO kampanjat (
        myyja, paivamaara, laskutettava_asiakas, ytunnus, laskutusosoite, 
        poikkeava_laskutus, viitetieto, lisahuomio, laskutusvali,
        yhteyshenkilo_nimi, yhteyshenkilo_titteli, yhteyshenkilo_email, 
        yhteyshenkilo_puhelin, lisasahkoposti,
        mainostava_yritys, kampanjan_nimi, kampanjan_tyyppi, ohjelma,
        ohy_aloitus, ohy_paattyminen, alku_loppu_tunnisteet, katkotunnisteet,
        spotti_aloitus, spotti_paattyminen,
        spotin_pituus_1, spottien_maara_1, spotin_pituus_2, spottien_maara_2,
        spotin_pituus_3, spottien_maara_3, spotin_pituus_4, spottien_maara_4,
        ostettu_trp, toteutuneet_esitykset, kommentit,
        bruttohinta, mediatoimistoalennus, asiakasalennus, muu_alennus, nettohinta,
        tarjous_tiedosto
    ) VALUES (
        :myyja, :paivamaara, :laskutettava_asiakas, :ytunnus, :laskutusosoite,
        :poikkeava_laskutus, :viitetieto, :lisahuomio, :laskutusvali,
        :yhteyshenkilo_nimi, :yhteyshenkilo_titteli, :yhteyshenkilo_email,
        :yhteyshenkilo_puhelin, :lisasahkoposti,
        :mainostava_yritys, :kampanjan_nimi, :kampanjan_tyyppi, :ohjelma,
        :ohy_aloitus, :ohy_paattyminen, :alku_loppu_tunnisteet, :katkotunnisteet,
        :spotti_aloitus, :spotti_paattyminen,
        :spotin_pituus_1, :spottien_maara_1, :spotin_pituus_2, :spottien_maara_2,
        :spotin_pituus_3, :spottien_maara_3, :spotin_pituus_4, :spottien_maara_4,
        :ostettu_trp, :toteutuneet_esitykset, :kommentit,
        :bruttohinta, :mediatoimistoalennus, :asiakasalennus, :muu_alennus, :nettohinta,
        :tarjous_tiedosto
    )";
    
    $stmt = $conn->prepare($sql);
    
    // Bind parameters
    $params = [
        ':myyja' => $_POST['myyja'] ?? '',
        ':paivamaara' => $_POST['paivamaara'] ?? null,
        ':laskutettava_asiakas' => $_POST['laskutettava_asiakas'] ?? '',
        ':ytunnus' => $_POST['ytunnus'] ?? '',
        ':laskutusosoite' => $_POST['laskutusosoite'] ?? '',
        ':poikkeava_laskutus' => $_POST['poikkeava_laskutus'] ?? '',
        ':viitetieto' => $viitetieto,
        ':lisahuomio' => $_POST['lisahuomio'] ?? '',
        ':laskutusvali' => $laskutusvali,
        ':yhteyshenkilo_nimi' => $_POST['yhteyshenkilo_nimi'] ?? '',
        ':yhteyshenkilo_titteli' => $_POST['yhteyshenkilo_titteli'] ?? '',
        ':yhteyshenkilo_email' => $_POST['yhteyshenkilo_email'] ?? '',
        ':yhteyshenkilo_puhelin' => $_POST['yhteyshenkilo_puhelin'] ?? '',
        ':lisasahkoposti' => $_POST['lisasahkoposti'] ?? null,
        ':mainostava_yritys' => $_POST['mainostava_yritys'] ?? '',
        ':kampanjan_nimi' => $_POST['kampanjan_nimi'] ?? '',
        ':kampanjan_tyyppi' => $_POST['kampanjan_tyyppi'] ?? '',
        ':ohjelma' => $_POST['ohjelma'] ?? null,
        ':ohy_aloitus' => !empty($_POST['ohy_aloitus']) ? $_POST['ohy_aloitus'] : null,
        ':ohy_paattyminen' => !empty($_POST['ohy_paattyminen']) ? $_POST['ohy_paattyminen'] : null,
        ':alku_loppu_tunnisteet' => !empty($_POST['alku_loppu_tunnisteet']) ? $_POST['alku_loppu_tunnisteet'] : null,
        ':katkotunnisteet' => !empty($_POST['katkotunnisteet']) ? $_POST['katkotunnisteet'] : null,
        ':spotti_aloitus' => !empty($_POST['spotti_aloitus']) ? $_POST['spotti_aloitus'] : null,
        ':spotti_paattyminen' => !empty($_POST['spotti_paattyminen']) ? $_POST['spotti_paattyminen'] : null,
        ':spotin_pituus_1' => $_POST['spotin_pituus_1'] ?? null,
        ':spottien_maara_1' => !empty($_POST['spottien_maara_1']) ? $_POST['spottien_maara_1'] : null,
        ':spotin_pituus_2' => $_POST['spotin_pituus_2'] ?? null,
        ':spottien_maara_2' => !empty($_POST['spottien_maara_2']) ? $_POST['spottien_maara_2'] : null,
        ':spotin_pituus_3' => $_POST['spotin_pituus_3'] ?? null,
        ':spottien_maara_3' => !empty($_POST['spottien_maara_3']) ? $_POST['spottien_maara_3'] : null,
        ':spotin_pituus_4' => $_POST['spotin_pituus_4'] ?? null,
        ':spottien_maara_4' => !empty($_POST['spottien_maara_4']) ? $_POST['spottien_maara_4'] : null,
        ':ostettu_trp' => !empty($_POST['ostettu_trp']) ? $_POST['ostettu_trp'] : null,
        ':toteutuneet_esitykset' => !empty($_POST['toteutuneet_esitykset']) ? $_POST['toteutuneet_esitykset'] : null,
        ':kommentit' => $_POST['kommentit'] ?? null,
        ':bruttohinta' => $_POST['bruttohinta'] ?? 0,
        ':mediatoimistoalennus' => !empty($_POST['mediatoimistoalennus']) ? $_POST['mediatoimistoalennus'] : null,
        ':asiakasalennus' => !empty($_POST['asiakasalennus']) ? $_POST['asiakasalennus'] : null,
        ':muu_alennus' => !empty($_POST['muu_alennus']) ? $_POST['muu_alennus'] : null,
        ':nettohinta' => $_POST['nettohinta'] ?? 0,
        ':tarjous_tiedosto' => $tiedostoNimi
    ];
    
    $stmt->execute($params);
    $kampanjaId = $conn->lastInsertId();
    
    // Hae tallennettu kampanja
    $stmt = $conn->prepare("SELECT * FROM kampanjat WHERE id = ?");
    $stmt->execute([$kampanjaId]);
    $kampanja = $stmt->fetch();
    
    // Lähetä sähköposti
    $emailSent = lahetaSahkoposti($kampanja, UPLOAD_DIR . $tiedostoNimi);
    
    jsonResponse([
        'success' => true,
        'message' => 'Kampanja tallennettu ja sähköposti lähetetty!',
        'id' => $kampanjaId,
        'email_sent' => $emailSent
    ]);
    
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
}
?>
