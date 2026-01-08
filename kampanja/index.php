<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Myyntikampanjan vahvistus</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
        }

        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 2.5em;
        }

        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 1.1em;
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

        label.required::after {
            content: ' *';
            color: #e74c3c;
        }

        input[type="text"],
        input[type="email"],
        input[type="date"],
        input[type="number"],
        input[type="file"],
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

        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 10px;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }

        .checkbox-label input[type="checkbox"] {
            width: auto;
            cursor: pointer;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
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

        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .message {
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 18px;
            display: none;
        }

        .success-message {
            background: #27ae60;
            color: white;
        }

        .error-message {
            background: #e74c3c;
            color: white;
        }

        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }

            h1 {
                font-size: 2em;
            }

            .grid-2 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Myyntikampanjan vahvistus</h1>
        <p class="subtitle">Täytä alla olevat tiedot kampanjan vahvistamiseksi</p>

        <div id="successMessage" class="message success-message"></div>
        <div id="errorMessage" class="message error-message"></div>

        <form id="campaignForm" enctype="multipart/form-data">
            
            <!-- Perustiedot -->
            <div class="section">
                <h2 class="section-title">Perustiedot</h2>
                
                <div class="form-group">
                    <label for="myyja" class="required">Valitse myyjä</label>
                    <select id="myyja" name="myyja" required>
                        <option value="">Valitse...</option>
                        <option value="Tomi">Tomi</option>
                        <option value="Janne">Janne</option>
                        <option value="Mika">Mika</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="paivamaara" class="required">Päivämäärä</label>
                    <input type="date" id="paivamaara" name="paivamaara" required>
                </div>
            </div>

            <!-- Asiakastiedot -->
            <div class="section">
                <h2 class="section-title">Asiakastiedot</h2>
                
                <div class="form-group">
                    <label for="laskutettava_asiakas" class="required">Laskutettava asiakas (kts ytj.fi)</label>
                    <input type="text" id="laskutettava_asiakas" name="laskutettava_asiakas" required>
                </div>

                <div class="form-group">
                    <label for="ytunnus" class="required">Y-tunnus (kts ytj.fi)</label>
                    <input type="text" id="ytunnus" name="ytunnus" required>
                </div>

                <div class="form-group">
                    <label for="laskutusosoite" class="required">Laskutusosoite</label>
                    <input type="text" id="laskutusosoite" name="laskutusosoite" required>
                </div>

                <div class="form-group">
                    <label for="poikkeava_laskutus" class="required">Mahdollinen poikkeava laskutus (= sisäinen)</label>
                    <input type="text" id="poikkeava_laskutus" name="poikkeava_laskutus" required>
                </div>

                <div class="form-group">
                    <label class="required">Asiakkaan laskulle tuleva viitetieto</label>
                    <div class="checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="viitetieto[]" value="Sponsorointi">
                            <span>Sponsorointi</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="viitetieto[]" value="Ohjelmayhteistyö">
                            <span>Ohjelmayhteistyö</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="viitetieto[]" value="Spottikampanja">
                            <span>Spottikampanja</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="viitetieto[]" value="Sisältöyhteistyö">
                            <span>Sisältöyhteistyö</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="viitetieto[]" value="Sosiaalinen media">
                            <span>Sosiaalinen media</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="viitetieto[]" value="Branded program">
                            <span>Branded program</span>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="lisahuomio" class="required">Asiakkaan laskulle tuleva lisähuomio</label>
                    <input type="text" id="lisahuomio" name="lisahuomio" required>
                </div>

                <div class="form-group">
                    <label class="required">Asiakkaan laskutuksen aikaväli</label>
                    <div class="checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="laskutusvali[]" value="Kertamaksulla">
                            <span>Kertamaksulla</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="laskutusvali[]" value="Kuukausittain">
                            <span>Kuukausittain</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="laskutusvali[]" value="Kvartaaleittain">
                            <span>Kvartaaleittain</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="laskutusvali[]" value="Puolivuosittain">
                            <span>Puolivuosittain</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="laskutusvali[]" value="Vuosittain">
                            <span>Vuosittain</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Yhteyshenkilö -->
            <div class="section">
                <h2 class="section-title">Ostajan yhteyshenkilö</h2>
                
                <div class="form-group">
                    <label for="yhteyshenkilo_nimi" class="required">Nimi</label>
                    <input type="text" id="yhteyshenkilo_nimi" name="yhteyshenkilo_nimi" required>
                </div>

                <div class="form-group">
                    <label for="yhteyshenkilo_titteli" class="required">Titteli</label>
                    <input type="text" id="yhteyshenkilo_titteli" name="yhteyshenkilo_titteli" required>
                </div>

                <div class="form-group">
                    <label for="yhteyshenkilo_email" class="required">Sähköposti</label>
                    <input type="email" id="yhteyshenkilo_email" name="yhteyshenkilo_email" required>
                </div>

                <div class="form-group">
                    <label for="yhteyshenkilo_puhelin" class="required">Puhelinnumero</label>
                    <input type="text" id="yhteyshenkilo_puhelin" name="yhteyshenkilo_puhelin" required>
                </div>

                <div class="form-group">
                    <label for="lisasahkoposti">Lisäsähköposti</label>
                    <input type="email" id="lisasahkoposti" name="lisasahkoposti">
                </div>
            </div>

            <!-- Kampanjatiedot -->
            <div class="section">
                <h2 class="section-title">Kampanjatiedot</h2>
                
                <div class="form-group">
                    <label for="mainostava_yritys" class="required">Mainostava yritys</label>
                    <input type="text" id="mainostava_yritys" name="mainostava_yritys" required>
                </div>

                <div class="form-group">
                    <label for="kampanjan_nimi" class="required">Mainoskampanjan nimi</label>
                    <input type="text" id="kampanjan_nimi" name="kampanjan_nimi" required>
                </div>

                <div class="form-group">
                    <label for="kampanjan_tyyppi" class="required">Kampanjan tyyppi</label>
                    <select id="kampanjan_tyyppi" name="kampanjan_tyyppi" required>
                        <option value="">Valitse...</option>
                        <option value="Spottikampanja">Spottikampanja</option>
                        <option value="Sponsorointi">Sponsorointi</option>
                        <option value="Ohjelma-ajan ostaminen">Ohjelma-ajan ostaminen</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="ohjelma">Ohjelma (N / A)</label>
                    <select id="ohjelma" name="ohjelma">
                        <option value="N / A">N / A</option>
                        <option value="Helppoa Arkiruokaa">Helppoa Arkiruokaa</option>
                        <option value="Muu ohjelma">Muu ohjelma</option>
                    </select>
                </div>
            </div>

            <!-- Ajat ja tunnisteet -->
            <div class="section">
                <h2 class="section-title">Ajat ja tunnisteet</h2>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label for="ohy_aloitus">OHY aloituspäivä</label>
                        <input type="date" id="ohy_aloitus" name="ohy_aloitus">
                    </div>

                    <div class="form-group">
                        <label for="ohy_paattyminen">OHY päättymispäivä</label>
                        <input type="date" id="ohy_paattyminen" name="ohy_paattyminen">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="alku_loppu_tunnisteet">Alku- ja lopputunnisteiden määrä</label>
                        <input type="number" id="alku_loppu_tunnisteet" name="alku_loppu_tunnisteet" min="0">
                    </div>

                    <div class="form-group">
                        <label for="katkotunnisteet">Katkotunnisteiden määrä</label>
                        <input type="number" id="katkotunnisteet" name="katkotunnisteet" min="0">
                    </div>
                </div>
            </div>

            <!-- Spottikampanja -->
            <div class="section">
                <h2 class="section-title">Spottikampanja</h2>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label for="spotti_aloitus">Aloituspäivä</label>
                        <input type="date" id="spotti_aloitus" name="spotti_aloitus">
                    </div>

                    <div class="form-group">
                        <label for="spotti_paattyminen">Päättymispäivä</label>
                        <input type="date" id="spotti_paattyminen" name="spotti_paattyminen">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="spotin_pituus_1">Spotin pituus #1</label>
                        <select id="spotin_pituus_1" name="spotin_pituus_1">
                            <option value="">Valitse...</option>
                            <option value="10s">10s</option>
                            <option value="15s">15s</option>
                            <option value="20s">20s</option>
                            <option value="30s">30s</option>
                            <option value="60s">60s</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="spottien_maara_1">Spottien määrä #1</label>
                        <input type="number" id="spottien_maara_1" name="spottien_maara_1" min="0">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="spotin_pituus_2">Spotin pituus #2</label>
                        <select id="spotin_pituus_2" name="spotin_pituus_2">
                            <option value="">Valitse...</option>
                            <option value="10s">10s</option>
                            <option value="15s">15s</option>
                            <option value="20s">20s</option>
                            <option value="30s">30s</option>
                            <option value="60s">60s</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="spottien_maara_2">Spottien määrä #2</label>
                        <input type="number" id="spottien_maara_2" name="spottien_maara_2" min="0">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="spotin_pituus_3">Spotin pituus #3</label>
                        <select id="spotin_pituus_3" name="spotin_pituus_3">
                            <option value="">Valitse...</option>
                            <option value="10s">10s</option>
                            <option value="15s">15s</option>
                            <option value="20s">20s</option>
                            <option value="30s">30s</option>
                            <option value="60s">60s</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="spottien_maara_3">Spottien määrä #3</label>
                        <input type="number" id="spottien_maara_3" name="spottien_maara_3" min="0">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="spotin_pituus_4">Spotin pituus #4</label>
                        <select id="spotin_pituus_4" name="spotin_pituus_4">
                            <option value="">Valitse...</option>
                            <option value="10s">10s</option>
                            <option value="15s">15s</option>
                            <option value="20s">20s</option>
                            <option value="30s">30s</option>
                            <option value="60s">60s</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="spottien_maara_4">Spottien määrä #4</label>
                        <input type="number" id="spottien_maara_4" name="spottien_maara_4" min="0">
                    </div>
                </div>

                <div class="form-group">
                    <label for="ostettu_trp">Ostettu TRP määrä</label>
                    <input type="number" id="ostettu_trp" name="ostettu_trp" min="0">
                </div>
            </div>

            <!-- Lisätiedot -->
            <div class="section">
                <h2 class="section-title">Lisätiedot</h2>
                
                <div class="form-group">
                    <label for="toteutuneet_esitykset">Toteutuneet esitykset (Ytunnisteet)</label>
                    <input type="number" id="toteutuneet_esitykset" name="toteutuneet_esitykset" min="0">
                </div>

                <div class="form-group">
                    <label for="kommentit">Kommentit (tuotantoon/suunnitteluun)</label>
                    <textarea id="kommentit" name="kommentit"></textarea>
                </div>
            </div>

            <!-- Hinnoittelu -->
            <div class="section">
                <h2 class="section-title">Hinnoittelu</h2>
                
                <div class="form-group">
                    <label for="bruttohinta" class="required">Kampanjan bruttohinta (€)</label>
                    <input type="number" id="bruttohinta" name="bruttohinta" step="0.01" required>
                </div>

                <div class="form-group">
                    <label for="mediatoimistoalennus">Mediatoimistoalennus (%)</label>
                    <input type="number" id="mediatoimistoalennus" name="mediatoimistoalennus" step="0.01" min="0" max="100">
                </div>

                <div class="form-group">
                    <label for="asiakasalennus">Asiakasalennus (%)</label>
                    <input type="number" id="asiakasalennus" name="asiakasalennus" step="0.01" min="0" max="100">
                </div>

                <div class="form-group">
                    <label for="muu_alennus">Muu alennus (%)</label>
                    <input type="number" id="muu_alennus" name="muu_alennus" step="0.01" min="0" max="100">
                </div>

                <div class="form-group">
                    <label for="nettohinta" class="required">Kampanjan nettohinta (€)</label>
                    <input type="number" id="nettohinta" name="nettohinta" step="0.01" required>
                </div>
            </div>

            <!-- Liitetiedosto -->
            <div class="section">
                <h2 class="section-title">Liitteet</h2>
                
                <div class="form-group">
                    <label for="tarjous" class="required">Liitä tekemäsi tarjous (pdf, jpg, doc). Max 1GB</label>
                    <input type="file" id="tarjous" name="tarjous" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
                </div>
            </div>

            <button type="submit" class="submit-btn" id="submitBtn">Lähetä vahvistus</button>
        </form>
    </div>

    <script>
        // Aseta tämän päivän päivämäärä oletukseksi
        document.getElementById('paivamaara').valueAsDate = new Date();

        // Lomakkeen lähetys
        document.getElementById('campaignForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = document.getElementById('submitBtn');
            const successMsg = document.getElementById('successMessage');
            const errorMsg = document.getElementById('errorMessage');
            
            // Piilota viestit
            successMsg.style.display = 'none';
            errorMsg.style.display = 'none';
            
            // Disabloi nappi
            submitBtn.disabled = true;
            submitBtn.textContent = 'Lähetetään...';
            
            try {
                const response = await fetch('submit.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    successMsg.textContent = result.message;
                    successMsg.style.display = 'block';
                    this.reset();
                    document.getElementById('paivamaara').valueAsDate = new Date();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                } else {
                    throw new Error(result.message || 'Tuntematon virhe');
                }
            } catch (error) {
                errorMsg.textContent = 'Virhe: ' + error.message;
                errorMsg.style.display = 'block';
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Lähetä vahvistus';
            }
        });
    </script>
</body>
</html>
