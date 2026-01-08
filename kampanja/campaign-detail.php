<?php
require_once 'config.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Virheellinen kampanja-ID");
}

try {
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("SELECT * FROM kampanjat WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $kampanja = $stmt->fetch();
    
    if (!$kampanja) {
        die("Kampanjaa ei löytynyt");
    }
    
} catch (Exception $e) {
    die("Virhe: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($kampanja['kampanjan_nimi']) ?> - Kampanjan tiedot</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            padding: 20px;
        }

        .header {
            background: white;
            padding: 20px 40px;
            margin-bottom: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        h1 {
            color: #333;
            font-size: 2em;
        }

        .back-btn {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            padding: 10px 20px;
            border-radius: 5px;
            transition: background 0.3s;
        }

        .back-btn:hover {
            background: #f0f0f0;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 40px;
        }

        .meta-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .meta-item h3 {
            font-size: 0.9em;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .meta-item p {
            font-size: 1.3em;
            font-weight: 600;
        }

        .section {
            margin-bottom: 40px;
        }

        .section-title {
            color: #667eea;
            font-size: 1.5em;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid #667eea;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .info-item {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }

        .info-item label {
            display: block;
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
            font-size: 0.9em;
        }

        .info-item p {
            color: #333;
            font-size: 1.1em;
        }

        .file-link {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            transition: background 0.3s;
        }

        .file-link:hover {
            background: #5568d3;
        }

        .price-highlight {
            background: #fff3cd;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #ffc107;
        }

        .price-highlight h3 {
            color: #856404;
            margin-bottom: 10px;
        }

        .price-highlight p {
            font-size: 2em;
            color: #856404;
            font-weight: 700;
        }

        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }

            .meta-info {
                grid-template-columns: 1fr;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📄 <?= h($kampanja['kampanjan_nimi'] ?: 'Kampanjan tiedot') ?></h1>
        <a href="admin.php" class="back-btn">← Takaisin listaan</a>
    </div>

    <div class="container">
        <div class="meta-info">
            <div class="meta-item">
                <h3>Luotu</h3>
                <p><?= date('d.m.Y H:i', strtotime($kampanja['luotu_pvm'])) ?></p>
            </div>
            <div class="meta-item">
                <h3>Myyjä</h3>
                <p><?= h($kampanja['myyja'] ?: 'N/A') ?></p>
            </div>
            <div class="meta-item">
                <h3>Kampanjan tyyppi</h3>
                <p><?= h($kampanja['kampanjan_tyyppi'] ?: 'N/A') ?></p>
            </div>
            <div class="meta-item">
                <h3>ID</h3>
                <p>#<?= $kampanja['id'] ?></p>
            </div>
        </div>

        <!-- Asiakastiedot -->
        <div class="section">
            <h2 class="section-title">Asiakastiedot</h2>
            <div class="info-grid">
                <div class="info-item">
                    <label>Laskutettava asiakas</label>
                    <p><?= h($kampanja['laskutettava_asiakas'] ?: 'N/A') ?></p>
                </div>
                <div class="info-item">
                    <label>Y-tunnus</label>
                    <p><?= h($kampanja['ytunnus'] ?: 'N/A') ?></p>
                </div>
                <div class="info-item">
                    <label>Laskutusosoite</label>
                    <p><?= h($kampanja['laskutusosoite'] ?: 'N/A') ?></p>
                </div>
                <div class="info-item">
                    <label>Poikkeava laskutus</label>
                    <p><?= h($kampanja['poikkeava_laskutus'] ?: 'N/A') ?></p>
                </div>
                <div class="info-item">
                    <label>Viitetieto</label>
                    <p><?= h($kampanja['viitetieto'] ?: 'N/A') ?></p>
                </div>
                <div class="info-item">
                    <label>Lisähuomio</label>
                    <p><?= h($kampanja['lisahuomio'] ?: 'N/A') ?></p>
                </div>
                <div class="info-item">
                    <label>Laskutusväli</label>
                    <p><?= h($kampanja['laskutusvali'] ?: 'N/A') ?></p>
                </div>
            </div>
        </div>

        <!-- Yhteyshenkilö -->
        <div class="section">
            <h2 class="section-title">Yhteyshenkilö</h2>
            <div class="info-grid">
                <div class="info-item">
                    <label>Nimi</label>
                    <p><?= h($kampanja['yhteyshenkilo_nimi'] ?: 'N/A') ?></p>
                </div>
                <div class="info-item">
                    <label>Titteli</label>
                    <p><?= h($kampanja['yhteyshenkilo_titteli'] ?: 'N/A') ?></p>
                </div>
                <div class="info-item">
                    <label>Sähköposti</label>
                    <p><?= h($kampanja['yhteyshenkilo_email'] ?: 'N/A') ?></p>
                </div>
                <div class="info-item">
                    <label>Puhelinnumero</label>
                    <p><?= h($kampanja['yhteyshenkilo_puhelin'] ?: 'N/A') ?></p>
                </div>
                <?php if (!empty($kampanja['lisasahkoposti'])): ?>
                <div class="info-item">
                    <label>Lisäsähköposti</label>
                    <p><?= h($kampanja['lisasahkoposti']) ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Kampanja -->
        <div class="section">
            <h2 class="section-title">Kampanja</h2>
            <div class="info-grid">
                <div class="info-item">
                    <label>Mainostava yritys</label>
                    <p><?= h($kampanja['mainostava_yritys'] ?: 'N/A') ?></p>
                </div>
                <div class="info-item">
                    <label>Kampanjan nimi</label>
                    <p><strong><?= h($kampanja['kampanjan_nimi'] ?: 'N/A') ?></strong></p>
                </div>
                <div class="info-item">
                    <label>Kampanjan tyyppi</label>
                    <p><?= h($kampanja['kampanjan_tyyppi'] ?: 'N/A') ?></p>
                </div>
                <div class="info-item">
                    <label>Ohjelma</label>
                    <p><?= h($kampanja['ohjelma'] ?: 'N/A') ?></p>
                </div>
            </div>
        </div>

        <!-- Ajat ja tunnisteet -->
        <div class="section">
            <h2 class="section-title">Ajat ja tunnisteet</h2>
            <div class="info-grid">
                <div class="info-item">
                    <label>OHY aloituspäivä</label>
                    <p><?= $kampanja['ohy_aloitus'] ? date('d.m.Y', strtotime($kampanja['ohy_aloitus'])) : 'N/A' ?></p>
                </div>
                <div class="info-item">
                    <label>OHY päättymispäivä</label>
                    <p><?= $kampanja['ohy_paattyminen'] ? date('d.m.Y', strtotime($kampanja['ohy_paattyminen'])) : 'N/A' ?></p>
                </div>
                <div class="info-item">
                    <label>Alku-/lopputunnisteet</label>
                    <p><?= h($kampanja['alku_loppu_tunnisteet'] ?? 'N/A') ?></p>
                </div>
                <div class="info-item">
                    <label>Katkotunnisteet</label>
                    <p><?= h($kampanja['katkotunnisteet'] ?? 'N/A') ?></p>
                </div>
            </div>
        </div>

        <!-- Spottikampanja -->
        <?php if (!empty($kampanja['spotti_aloitus']) || !empty($kampanja['spotin_pituus_1'])): ?>
        <div class="section">
            <h2 class="section-title">Spottikampanja</h2>
            <div class="info-grid">
                <div class="info-item">
                    <label>Aloituspäivä</label>
                    <p><?= $kampanja['spotti_aloitus'] ? date('d.m.Y', strtotime($kampanja['spotti_aloitus'])) : 'N/A' ?></p>
                </div>
                <div class="info-item">
                    <label>Päättymispäivä</label>
                    <p><?= $kampanja['spotti_paattyminen'] ? date('d.m.Y', strtotime($kampanja['spotti_paattyminen'])) : 'N/A' ?></p>
                </div>
                <?php if (!empty($kampanja['spotin_pituus_1'])): ?>
                <div class="info-item">
                    <label>Spotin pituus #1</label>
                    <p><?= h($kampanja['spotin_pituus_1']) ?></p>
                </div>
                <div class="info-item">
                    <label>Spottien määrä #1</label>
                    <p><?= h($kampanja['spottien_maara_1'] ?? 'N/A') ?></p>
                </div>
                <?php endif; ?>
                <?php if (!empty($kampanja['spotin_pituus_2'])): ?>
                <div class="info-item">
                    <label>Spotin pituus #2</label>
                    <p><?= h($kampanja['spotin_pituus_2']) ?></p>
                </div>
                <div class="info-item">
                    <label>Spottien määrä #2</label>
                    <p><?= h($kampanja['spottien_maara_2'] ?? 'N/A') ?></p>
                </div>
                <?php endif; ?>
                <?php if (!empty($kampanja['spotin_pituus_3'])): ?>
                <div class="info-item">
                    <label>Spotin pituus #3</label>
                    <p><?= h($kampanja['spotin_pituus_3']) ?></p>
                </div>
                <div class="info-item">
                    <label>Spottien määrä #3</label>
                    <p><?= h($kampanja['spottien_maara_3'] ?? 'N/A') ?></p>
                </div>
                <?php endif; ?>
                <?php if (!empty($kampanja['spotin_pituus_4'])): ?>
                <div class="info-item">
                    <label>Spotin pituus #4</label>
                    <p><?= h($kampanja['spotin_pituus_4']) ?></p>
                </div>
                <div class="info-item">
                    <label>Spottien määrä #4</label>
                    <p><?= h($kampanja['spottien_maara_4'] ?? 'N/A') ?></p>
                </div>
                <?php endif; ?>
                <?php if (!empty($kampanja['ostettu_trp'])): ?>
                <div class="info-item">
                    <label>Ostettu TRP</label>
                    <p><?= h($kampanja['ostettu_trp']) ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Lisätiedot -->
        <?php if (!empty($kampanja['toteutuneet_esitykset']) || !empty($kampanja['kommentit'])): ?>
        <div class="section">
            <h2 class="section-title">Lisätiedot</h2>
            <div class="info-grid">
                <?php if (!empty($kampanja['toteutuneet_esitykset'])): ?>
                <div class="info-item">
                    <label>Toteutuneet esitykset</label>
                    <p><?= h($kampanja['toteutuneet_esitykset']) ?></p>
                </div>
                <?php endif; ?>
                <?php if (!empty($kampanja['kommentit'])): ?>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>Kommentit</label>
                    <p><?= nl2br(h($kampanja['kommentit'])) ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Hinnoittelu -->
        <div class="section">
            <h2 class="section-title">Hinnoittelu</h2>
            <div class="info-grid">
                <div class="info-item">
                    <label>Bruttohinta</label>
                    <p><?= number_format($kampanja['bruttohinta'] ?? 0, 2, ',', ' ') ?> €</p>
                </div>
                <?php if (!empty($kampanja['mediatoimistoalennus'])): ?>
                <div class="info-item">
                    <label>Mediatoimistoalennus</label>
                    <p><?= h($kampanja['mediatoimistoalennus']) ?>%</p>
                </div>
                <?php endif; ?>
                <?php if (!empty($kampanja['asiakasalennus'])): ?>
                <div class="info-item">
                    <label>Asiakasalennus</label>
                    <p><?= h($kampanja['asiakasalennus']) ?>%</p>
                </div>
                <?php endif; ?>
                <?php if (!empty($kampanja['muu_alennus'])): ?>
                <div class="info-item">
                    <label>Muu alennus</label>
                    <p><?= h($kampanja['muu_alennus']) ?>%</p>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="price-highlight" style="margin-top: 20px;">
                <h3>Kampanjan nettohinta</h3>
                <p><?= number_format($kampanja['nettohinta'] ?? 0, 2, ',', ' ') ?> €</p>
            </div>
        </div>

        <!-- Liitetiedosto -->
        <?php if (!empty($kampanja['tarjous_tiedosto'])): ?>
        <div class="section">
            <h2 class="section-title">Liitteet</h2>
            <a href="uploads/<?= h($kampanja['tarjous_tiedosto']) ?>" class="file-link" download>
                📎 Lataa tarjoustiedosto
            </a>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
