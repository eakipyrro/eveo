# Pika-aloitus - Myyntikampanjan vahvistusjärjestelmä

## 🚀 Asennus 5 minuutissa

### 1. Lataa tiedostot webhotelliin

Lataa kaikki tiedostot webhotellisi **public_html** -kansioon (tai **www** / **htdocs**).

### 2. Luo tietokanta

**phpMyAdminissa:**
1. Avaa phpMyAdmin
2. Klikkaa "New" / "Uusi"
3. Anna nimi: `myyntikampanja`
4. Valitse "utf8mb4_unicode_ci"
5. Luo tietokanta
6. Avaa tietokanta
7. Klikkaa "SQL"-välilehti
8. Kopioi `database.sql` tiedoston sisältö
9. Klikkaa "Go" / "Suorita"

### 3. Muokkaa config.php

Avaa `config.php` ja muokkaa:

```php
define('DB_HOST', 'localhost');           // Yleensä localhost
define('DB_USER', 'sinun_kayttajanimesi'); // Tietokantasi käyttäjä
define('DB_PASS', 'sinun_salasanasi');     // Tietokantasi salasana
define('DB_NAME', 'myyntikampanja');       // Tietokantasi nimi
```

**💡 Löydät nämä tiedot:**
- cPanel → MySQL Databases
- Webhotellin ohjeista
- Webhotellin tekniseltä tuelta

### 4. Luo uploads-kansio

**cPanelin File Managerissa:**
1. Navigoi oikean kansion sisään
2. Klikkaa "+ Folder"
3. Nimi: `uploads`
4. Tallenna
5. Valitse kansio → Permissions → Aseta: **755**

**FTP:llä:**
```bash
mkdir uploads
chmod 755 uploads
```

### 5. Testaa!

Avaa selaimessa: `http://yourdomain.com/index.php`

Jos lomake näkyy, asennus onnistui! 🎉

---

## ⚙️ Määritä sähköposti (tärkeä!)

### 1. Avaa sähköpostiasetukset

Mene: `http://yourdomain.com/email-settings.php`

### 2. Lisää vastaanottajat

```
myynti@yritys.fi, toimisto@yritys.fi, johtaja@yritys.fi
```

### 3. Täytä SMTP-asetukset

**Jos käytät webhotellin sähköpostia (suositeltu):**

- **SMTP-palvelin:** `mail.yourdomain.com` (kysy webhotellin tekniseltä tuelta)
- **Portti:** `587`
- **Suojattu yhteys:** Ei (TLS)
- **Käyttäjä:** `info@yourdomain.com` (tai muu sähköpostiosoite)
- **Salasana:** Sähköpostisi salasana

**Jos käytät Gmailia:**

1. Ota käyttöön 2-vaiheinen vahvistus Gmailissa
2. Luo App Password: https://myaccount.google.com/apppasswords
3. Käytä asetuksia:
   - **SMTP-palvelin:** `smtp.gmail.com`
   - **Portti:** `587`
   - **Suojattu yhteys:** Ei (TLS)
   - **Käyttäjä:** `sinun@gmail.com`
   - **Salasana:** App Password (16 merkkiä, EI tavallinen salasana!)

### 4. Testaa lähetys

1. Täytä lomake: `http://yourdomain.com/`
2. Lähetä
3. Tarkista että sähköposti tuli perille!

---

## 🔐 Vaihda oletussalasana!

**TÄRKEÄÄ!** Admin-paneelissa on oletussalasana:

- **Käyttäjä:** `admin`
- **Salasana:** `admin123`

**Vaihda se HETI!** Käytä phpMyAdminia:

1. Avaa `admin_kayttajat` -taulu
2. Muokkaa admin-riviä
3. Vaihda salasana (käytä salasanan hashia)

**Uuden salasanan hash:**
```php
<?php
echo password_hash('uusi_salasana', PASSWORD_DEFAULT);
?>
```

---

## 📱 Käyttö

### Lomake
👉 `http://yourdomain.com/`
- Täytä kampanjan tiedot
- Liitä tarjoustiedosto
- Lähetä

### Admin-paneeli
👉 `http://yourdomain.com/admin.php`
- Näe kaikki kampanjat
- Hae ja suodata
- Avaa yksittäinen kampanja

### Sähköpostiasetukset
👉 `http://yourdomain.com/email-settings.php`
- Muokkaa vastaanottajia
- Päivitä SMTP-asetukset

---

## 🔧 Yleisimmät ongelmat

### ❌ "Tietokantayhteys epäonnistui"

**Ratkaisu:**
1. Tarkista `config.php` tiedot
2. Varmista että tietokanta on luotu
3. Tarkista käyttäjäoikeudet phpMyAdminissa

### ❌ "Tiedostoa ei voi ladata"

**Ratkaisu:**
1. Varmista että `uploads/` kansio on olemassa
2. Aseta kansion oikeudet: **755** tai **777**
3. Tarkista PHP:n asetukset (kysy webhotellin tuelta)

### ❌ "Sähköpostia ei lähde"

**Ratkaisu:**
1. Tarkista SMTP-asetukset
2. Kokeile eri SMTP-palvelinta
3. Varmista että portti 587 on auki
4. Kysy apua webhotellin tekniseltä tuelta
5. Testaa yksinkertainen PHP mail() -toiminto

### ❌ "Admin-sivu näyttää tyhjän"

**Ratkaisu:**
1. Varmista että `database.sql` on suoritettu
2. Tarkista PHP:n virheloki
3. Lisää `config.php`:hen: `define('DEBUG_MODE', true);`

---

## 📞 Tarvitsetko apua?

1. **README.md** - Kattavat ohjeet
2. **Webhotellin tuki** - SMTP ja PHP-asetukset
3. **phpMyAdmin** - Tietokannan ongelmat
4. **PHP error_log** - Virheviestit

---

## ✅ Tarkistuslista

- [ ] Tiedostot ladattu webhotelliin
- [ ] Tietokanta luotu
- [ ] `database.sql` suoritettu
- [ ] `config.php` muokattu
- [ ] `uploads/` kansio luotu (755)
- [ ] Lomake toimii (`index.php`)
- [ ] Admin-paneeli toimii (`admin.php`)
- [ ] Sähköpostiasetukset määritetty
- [ ] Testisähköposti lähetetty
- [ ] Admin-salasana vaihdettu

---

**Valmista! Sovellus on nyt käytössä! 🎉**

Kysymyksiä? Tarkista README.md tai ota yhteyttä webhotellin tekniseen tukeen!
