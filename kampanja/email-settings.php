<?php
require_once 'config.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = getDBConnection();
        
        $vastaanottajat = $_POST['vastaanottajat'] ?? '';
        $smtp_host = $_POST['smtp_host'] ?? '';
        $smtp_port = intval($_POST['smtp_port'] ?? 587);
        $smtp_secure = isset($_POST['smtp_secure']) && $_POST['smtp_secure'] === '1' ? 1 : 0;
        $smtp_user = $_POST['smtp_user'] ?? '';
        $smtp_pass = $_POST['smtp_pass'] ?? '';
        
        // Päivitä asetukset
        $sql = "UPDATE sahkoposti_asetukset SET 
                vastaanottajat = ?,
                smtp_host = ?,
                smtp_port = ?,
                smtp_secure = ?,
                smtp_user = ?,
                smtp_pass = ?,
                paivitetty_pvm = CURRENT_TIMESTAMP
                WHERE aktiivinen = 1";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $vastaanottajat,
            $smtp_host,
            $smtp_port,
            $smtp_secure,
            $smtp_user,
            $smtp_pass
        ]);
        
        $message = 'Asetukset tallennettu onnistuneesti!';
        $messageType = 'success';
        
    } catch (Exception $e) {
        $message = 'Virhe asetusten tallennuksessa: ' . $e->getMessage();
        $messageType = 'error';
    }
}

try {
    $conn = getDBConnection();
    $stmt = $conn->query("SELECT * FROM sahkoposti_asetukset WHERE aktiivinen = 1 ORDER BY id DESC LIMIT 1");
    $asetukset = $stmt->fetch();
    
    if (!$asetukset) {
        // Luo oletusasetukset jos ei ole olemassa
        $conn->query("INSERT INTO sahkoposti_asetukset (vastaanottajat, smtp_host, smtp_port, smtp_secure, smtp_user, smtp_pass, aktiivinen) VALUES ('', 'localhost', 587, 0, '', '', 1)");
        $asetukset = $conn->query("SELECT * FROM sahkoposti_asetukset WHERE aktiivinen = 1 ORDER BY id DESC LIMIT 1")->fetch();
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
    <title>Sähköpostiasetukset</title>
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
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 40px;
        }

        .message {
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }

        .message.success {
            background: #27ae60;
            color: white;
        }

        .message.error {
            background: #e74c3c;
            color: white;
        }

        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 30px;
        }

        .info-box h3 {
            color: #1976d2;
            margin-bottom: 10px;
        }

        .info-box p {
            color: #555;
            line-height: 1.6;
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

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"],
        select,
        textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.3s;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #667eea;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        .hint {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        .submit-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }

        .smtp-examples {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }

        .smtp-examples h4 {
            color: #333;
            margin-bottom: 15px;
        }

        .smtp-examples ul {
            list-style: none;
            padding-left: 0;
        }

        .smtp-examples li {
            padding: 8px 0;
            color: #555;
        }

        .smtp-examples strong {
            color: #667eea;
        }

        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }

            .header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>⚙️ Sähköpostiasetukset</h1>
        <a href="admin.php" class="back-btn">← Takaisin</a>
    </div>

    <div class="container">
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>">
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <div class="info-box">
            <h3>ℹ️ Tietoa sähköpostiasetuksista</h3>
            <p>Tällä sivulla voit määritellä, kenelle myyntikampanjan vahvistukset lähetetään ja mitkä ovat SMTP-palvelimesi asetukset. Kaikki kampanjat lähetetään automaattisesti näille vastaanottajille.</p>
        </div>

        <form method="POST">
            <!-- Vastaanottajat -->
            <div class="section">
                <h2 class="section-title">Vastaanottajat</h2>
                
                <div class="form-group">
                    <label for="vastaanottajat">Sähköpostiosoitteet (pilkulla eroteltuna)</label>
                    <textarea id="vastaanottajat" name="vastaanottajat" required><?= h($asetukset['vastaanottajat'] ?? '') ?></textarea>
                    <p class="hint">Esim: myynti@yritys.fi, toimisto@yritys.fi, manager@yritys.fi</p>
                </div>
            </div>

            <!-- SMTP-asetukset -->
            <div class="section">
                <h2 class="section-title">SMTP-asetukset</h2>
                
                <div class="form-group">
                    <label for="smtp_host">SMTP-palvelin</label>
                    <input type="text" id="smtp_host" name="smtp_host" value="<?= h($asetukset['smtp_host'] ?? '') ?>" required>
                    <p class="hint">Esim: smtp.gmail.com, smtp.office365.com, localhost</p>
                </div>

                <div class="form-group">
                    <label for="smtp_port">SMTP-portti</label>
                    <input type="number" id="smtp_port" name="smtp_port" value="<?= h($asetukset['smtp_port'] ?? 587) ?>" required>
                    <p class="hint">Tyypillisesti 587 (TLS), 465 (SSL) tai 25 (ei salausta)</p>
                </div>

                <div class="form-group">
                    <label for="smtp_secure">Suojattu yhteys (SSL)</label>
                    <select id="smtp_secure" name="smtp_secure">
                        <option value="0" <?= ($asetukset['smtp_secure'] ?? 0) == 0 ? 'selected' : '' ?>>Ei (TLS/STARTTLS)</option>
                        <option value="1" <?= ($asetukset['smtp_secure'] ?? 0) == 1 ? 'selected' : '' ?>>Kyllä (SSL)</option>
                    </select>
                    <p class="hint">Valitse "Ei" jos käytät porttia 587, "Kyllä" jos käytät porttia 465</p>
                </div>

                <div class="form-group">
                    <label for="smtp_user">Käyttäjätunnus (sähköpostiosoite)</label>
                    <input type="email" id="smtp_user" name="smtp_user" value="<?= h($asetukset['smtp_user'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label for="smtp_pass">Salasana</label>
                    <input type="password" id="smtp_pass" name="smtp_pass" value="<?= h($asetukset['smtp_pass'] ?? '') ?>" required>
                    <p class="hint">Gmail: käytä "App Password" -salasanaa, ei tavallista salasanaasi</p>
                </div>

                <div class="smtp-examples">
                    <h4>Yleisiä SMTP-asetuksia:</h4>
                    <ul>
                        <li><strong>Gmail:</strong> smtp.gmail.com, Portti: 587 (TLS)</li>
                        <li><strong>Outlook/Office365:</strong> smtp.office365.com, Portti: 587 (TLS)</li>
                        <li><strong>Yahoo:</strong> smtp.mail.yahoo.com, Portti: 587 (TLS)</li>
                        <li><strong>Webhotelli (cPanel):</strong> mail.yourdomain.com, Portti: 587 (TLS)</li>
                        <li><strong>Localhost (testaus):</strong> localhost, Portti: 25 (ei salausta)</li>
                    </ul>
                </div>
            </div>

            <button type="submit" class="submit-btn">Tallenna asetukset</button>
        </form>
    </div>
</body>
</html>
