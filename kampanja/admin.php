<?php
require_once 'config.php';

try {
    $conn = getDBConnection();
    
    // Hae kampanjat
    $sql = "SELECT * FROM kampanjat ORDER BY luotu_pvm DESC";
    $stmt = $conn->query($sql);
    $kampanjat = $stmt->fetchAll();
    
    // Hae tilastot
    $stmt = $conn->query("SELECT * FROM kampanja_tilastot");
    $tilastot = $stmt->fetch();
    
} catch (Exception $e) {
    die("Virhe: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Myyntikampanjat</title>
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

        .nav-links {
            display: flex;
            gap: 20px;
        }

        .nav-links a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            padding: 10px 20px;
            border-radius: 5px;
            transition: background 0.3s;
        }

        .nav-links a:hover {
            background: #f0f0f0;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 30px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-card h3 {
            font-size: 2em;
            margin-bottom: 5px;
        }

        .stat-card p {
            opacity: 0.9;
        }

        .search-box {
            margin-bottom: 20px;
        }

        .search-box input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f8f9fa;
        }

        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e0e0e0;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .view-btn {
            background: #667eea;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: background 0.3s;
        }

        .view-btn:hover {
            background: #5568d3;
        }

        .no-campaigns {
            text-align: center;
            padding: 40px;
            color: #666;
            font-size: 18px;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-sponsorointi {
            background: #e3f2fd;
            color: #1976d2;
        }

        .badge-spottikampanja {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .badge-ohjelma {
            background: #e8f5e9;
            color: #388e3c;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }

            table {
                font-size: 14px;
            }

            th, td {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📊 Myyntikampanjat</h1>
        <div class="nav-links">
            <a href="index.php">← Takaisin lomakkeeseen</a>
            <a href="email-settings.php">⚙️ Sähköpostiasetukset</a>
        </div>
    </div>

    <div class="container">
        <div class="stats">
            <div class="stat-card">
                <h3><?= number_format($tilastot['kampanjoita_yhteensa'] ?? 0) ?></h3>
                <p>Kampanjaa yhteensä</p>
            </div>
            <div class="stat-card">
                <h3><?= number_format($tilastot['kuukauden_kampanjat'] ?? 0) ?></h3>
                <p>Kampanjaa tässä kuussa</p>
            </div>
            <div class="stat-card">
                <h3><?= number_format($tilastot['kokonaisarvo'] ?? 0, 2, ',', ' ') ?> €</h3>
                <p>Kokonaisarvo</p>
            </div>
            <div class="stat-card">
                <h3><?= number_format($tilastot['kuukauden_arvo'] ?? 0, 2, ',', ' ') ?> €</h3>
                <p>Tämän kuun arvo</p>
            </div>
        </div>

        <div class="search-box">
            <input type="text" id="searchInput" placeholder="🔍 Hae kampanjaa nimellä, asiakkaalla tai myyjällä...">
        </div>

        <?php if (empty($kampanjat)): ?>
            <div class="no-campaigns">
                <p>Ei vielä kampanjoita. Täytä ensimmäinen lomake!</p>
            </div>
        <?php else: ?>
            <table id="campaignsTable">
                <thead>
                    <tr>
                        <th>Päivämäärä</th>
                        <th>Kampanja</th>
                        <th>Asiakas</th>
                        <th>Myyjä</th>
                        <th>Tyyppi</th>
                        <th>Nettohinta</th>
                        <th>Toiminnot</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kampanjat as $kampanja): ?>
                        <tr>
                            <td><?= date('d.m.Y', strtotime($kampanja['paivamaara'])) ?></td>
                            <td>
                                <strong><?= h($kampanja['kampanjan_nimi'] ?: 'N/A') ?></strong><br>
                                <small><?= h($kampanja['mainostava_yritys'] ?: '') ?></small>
                            </td>
                            <td><?= h($kampanja['laskutettava_asiakas'] ?: 'N/A') ?></td>
                            <td><?= h($kampanja['myyja'] ?: 'N/A') ?></td>
                            <td>
                                <?php
                                $badgeClass = 'badge-sponsorointi';
                                if ($kampanja['kampanjan_tyyppi'] === 'Spottikampanja') {
                                    $badgeClass = 'badge-spottikampanja';
                                } elseif ($kampanja['kampanjan_tyyppi'] === 'Ohjelma-ajan ostaminen') {
                                    $badgeClass = 'badge-ohjelma';
                                }
                                ?>
                                <span class="badge <?= $badgeClass ?>"><?= h($kampanja['kampanjan_tyyppi'] ?: 'N/A') ?></span>
                            </td>
                            <td><strong><?= number_format($kampanja['nettohinta'] ?? 0, 2, ',', ' ') ?> €</strong></td>
                            <td>
                                <a href="campaign-detail.php?id=<?= $kampanja['id'] ?>" class="view-btn">Näytä</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <script>
        // Hakutoiminto
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#campaignsTable tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
    </script>
</body>
</html>
