# Myyntikampanjan vahvistusjärjestelmä (PHP/MySQL)

Tämä sovellus kerää myyntikampanjoiden tiedot, tallentaa ne MySQL-tietokantaan ja lähettää tiedot automaattisesti määritellyille sähköpostiosoitteille.

## 🎯 Ominaisuudet

### Lomake
- ✅ Kattava lomake kaikille kampanjan tiedoille
- ✅ Tiedostojen lataus (max 1GB)
- ✅ Responsiivinen design - toimii mobiililaitteilla
- ✅ Automaattinen vahvistus lomakkeen lähetyksestä

### Admin-paneeli
- ✅ Kampanjalista - kaikki lähetetyt kampanjat
- ✅ Haku ja suodatus
- ✅ Yksityiskohtainen näkymä
- ✅ Tilastot (kampanjoiden määrä ja kokonaisarvo)
- ✅ Sähköpostiasetusten hallinta

### Sähköpostitoiminnot
- ✅ Automaattinen lähetys heti tallennuksen jälkeen
- ✅ HTML-muotoiltu sähköposti
- ✅ Liitetiedostot mukana
- ✅ Määriteltävät vastaanottajat

## 📋 Vaatimukset

- PHP 7.4 tai uudempi
- MySQL 5.7 tai uudempi / MariaDB 10.2 tai uudempi
- Webhotelli jossa PHP ja MySQL käytössä
- `mail()` -funktio toiminnassa (tai SMTP-palvelin)

## 🚀 Asennus

### Vaihe 1: Lataa tiedostot

Lataa kaikki tiedostot webhotellisi public_html -kansioon (tai vastaavaan):

```
public_html/
├── config.php
├── database.sql
├── index.php
├── submit.php
├── email.php
├── admin.php
├── campaign-detail.php
├── email-settings.php
└── uploads/
```

### Vaihe 2: Luo tietokanta

1. Avaa webhotellisi phpMyAdmin
2. Luo uusi tietokanta (esim. `myyntikampanja`)
3. Valitse tietokanta
4. Avaa "SQL"-välilehti
5. Kopioi ja suorita `database.sql` -tiedoston sisältö

### Vaihe 3: Määritä tietokanta-asetukset

Muokkaa `config.php` -tiedostoa ja päivitä tietokantasi tiedot:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'tietokantasi_kayttaja');
define('DB_PASS', 'tietokantasi_salasana');
define('DB_NAME', 'tietokantasi_nimi');
```

### Vaihe 4: Luo uploads-kansio

Varmista että `uploads/` -kansio on olemassa ja sillä on kirjoitusoikeudet:

```bash
mkdir uploads
chmod 755 uploads
```

Tai cPanelin File Managerissa: luo kansio ja aseta oikeudet 755.

### Vaihe 5: Testaa asennus

1. Avaa `http://yourdomain.com/index.php`
2. Täytä lomake
3. Tarkista että tallentuu tietokantaan
4. Avaa admin: `http://yourdomain.com/admin.php`

## ⚙️ Sähköpostin asetukset

### Vaihtoehto 1: Webhotellin SMTP (suositeltu)

Mene osoitteeseen: `http://yourdomain.com/email-settings.php`

Täytä asetukset:
- **SMTP-palvelin:** `mail.yourdomain.com` (tai webhotellisi SMTP)
- **Portti:** `587` (TLS)
- **Käyttäjä:** sähköpostiosoitteesi
- **Salasana:** sähköpostisi salasana

### Vaihtoehto 2: Gmail

Jos haluat käyttää Gmailia:

1. Ota käyttöön 2-vaiheinen vahvistus
2. Luo App Password: https://myaccount.google.com/apppasswords
3. Käytä asetuksissa:
   - **SMTP-palvelin:** `smtp.gmail.com`
   - **Portti:** `587`
   - **Käyttäjä:** gmail-osoitteesi
   - **Salasana:** App Password (EI tavallinen salasana!)

### Vaihtoehto 3: PHP:n mail()-funktio

Jos webhotellisi tukee PHP:n `mail()`-funktiota:

```php
// Muokkaa config.php:
define('SMTP_HOST', 'localhost');
define('SMTP_PORT', 25);
```

## 📁 Tiedostojen rakenne

```
myyntikampanja/
├── config.php              # Tietokanta- ja asetukset
├── database.sql            # Tietokannan rakenne
├── index.php               # Lomakesivu
├── submit.php              # Lomakkeen käsittelijä
├── email.php               # Sähköpostin lähetys
├── admin.php               # Admin-listaus
├── campaign-detail.php     # Yksittäinen kampanja
├── email-settings.php      # Sähköpostiasetukset
├── uploads/                # Ladatut tiedostot
└── README.md               # Tämä tiedosto
```

## 🔐 Tietoturva

### Tärkeät turvatoimenpiteet:

1. **Vaihda oletussalasana!**
   - Oletuskäyttäjä: `admin`
   - Oletussalasana: `admin123`
   - Vaihda heti käytön aloittamisen jälkeen!

2. **Suojaa admin-sivut**
   
   Lisää `.htaccess` -tiedosto suojaamaan admin-sivuja:
   
   ```apache
   # Suojaa admin-sivut
   <FilesMatch "(admin|campaign-detail|email-settings)\.php$">
       AuthType Basic
       AuthName "Admin Area"
       AuthUserFile /path/to/.htpasswd
       Require valid-user
   </FilesMatch>
   ```

3. **HTTPS**
   
   Käytä aina HTTPS-yhteyttä! Useimmat webhotellit tarjoavat ilmaisen SSL-sertifikaatin (Let's Encrypt).

4. **Tiedostojen lataus**
   
   Rajoita sallittuja tiedostotyyppejä ja kokoja `config.php`:ssa.

## 🗄️ Tietokantarakenne

Tietokannassa on kolme päätaulua:

1. **kampanjat** - Kaikki kampanjatiedot
2. **sahkoposti_asetukset** - Sähköpostin asetukset
3. **admin_kayttajat** - Admin-käyttäjät (valinnainen)

## 📧 Sähköpostin lähetys

Sovellus käyttää PHP:n `mail()`-funktiota sähköpostien lähettämiseen. Voit muokata `email.php` -tiedostoa käyttämään PHPMailer-kirjastoa, jos haluat paremmat SMTP-ominaisuudet.

### PHPMailer-integraatio (valinnainen):

```bash
composer require phpmailer/phpmailer
```

Muokkaa `email.php` käyttämään PHPMaileria SMTP-yhteyksien hallintaan.

## 🔧 Ongelmien ratkaisut

### Sähköpostia ei lähde

1. Tarkista SMTP-asetukset
2. Varmista että webhotellisi sallii sähköpostien lähetyksen
3. Tarkista että portti 587 tai 465 on auki
4. Kokeile eri SMTP-palvelinta
5. Tarkista PHP:n error_log virheviestejä

### Tiedostoja ei voi ladata

1. Tarkista että `uploads/`-kansio on olemassa
2. Varmista että kansiolla on kirjoitusoikeudet (755 tai 777)
3. Tarkista PHP:n `upload_max_filesize` ja `post_max_size` asetukset
4. Webhotellissa voi olla rajoituksia - kysy tekniseltä tuelta

### Tietokantayhteys ei toimi

1. Tarkista `config.php`:n tietokanta-asetukset
2. Varmista että käyttäjällä on oikeudet tietokantaan
3. Tarkista että tietokanta on olemassa
4. Kokeile yhteyttä phpMyAdminista

### Admin-sivut eivät näy

1. Varmista että olet suorittanut `database.sql`
2. Tarkista PHP:n virheloki
3. Varmista että tiedostot ovat oikeassa hakemistossa

## 📊 Käyttö

### Lomakkeen täyttö

1. Avaa: `http://yourdomain.com/`
2. Täytä kampanjan tiedot
3. Liitä tarjoustiedosto
4. Lähetä lomake
5. Kampanja tallentuu ja sähköposti lähtee automaattisesti

### Admin-paneeli

1. Avaa: `http://yourdomain.com/admin.php`
2. Näet listan kaikista kampanjoista
3. Hae kampanjoita hakukentällä
4. Klikkaa "Näytä" nähdäksesi yksityiskohdat

### Sähköpostiasetukset

1. Avaa: `http://yourdomain.com/email-settings.php`
2. Määritä vastaanottajat (pilkulla eroteltuna)
3. Täytä SMTP-asetukset
4. Tallenna

## 🔄 Päivitykset ja varmuuskopiot

### Varmuuskopiot

Ota säännöllisesti varmuuskopiot:

1. **Tietokanta**: phpMyAdminista "Export"
2. **Tiedostot**: Lataa `uploads/` -kansio
3. **Asetukset**: Kopioi `config.php`

### Päivitykset

Kun päivität sovellusta:

1. Ota varmuuskopio ensin!
2. Lataa uudet tiedostot
3. Säilytä `config.php` ja `uploads/` kansio
4. Suorita mahdolliset SQL-päivitykset

## 🆘 Tuki

Jos tarvitset apua:

1. Tarkista `README.md` lisäohjeita varten
2. Tarkista PHP:n error_log virheviestien varalta
3. Webhotellin tekninen tuki voi auttaa SMTP-ongelmissa

## 📝 Lisenssi

Tämä projekti on vapaasti käytettävissä omiin tarkoituksiin.

## 🎉 Valmista!

Sovellus on nyt käytössä! 

- **Lomake:** http://yourdomain.com/
- **Admin:** http://yourdomain.com/admin.php
- **Asetukset:** http://yourdomain.com/email-settings.php
