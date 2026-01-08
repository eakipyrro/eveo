-- Myyntikampanjan tietokanta
-- Suorita tämä skripti phpMyAdminissa tai MySQL-komentoriviltä

CREATE DATABASE IF NOT EXISTS myyntikampanja CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE myyntikampanja;

-- Kampanjat-taulu
CREATE TABLE IF NOT EXISTS kampanjat (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Perustiedot
    myyja VARCHAR(100),
    paivamaara DATE,
    
    -- Asiakastiedot
    laskutettava_asiakas VARCHAR(255),
    ytunnus VARCHAR(50),
    laskutusosoite TEXT,
    poikkeava_laskutus TEXT,
    viitetieto TEXT,
    lisahuomio TEXT,
    laskutusvali TEXT,
    
    -- Yhteyshenkilö
    yhteyshenkilo_nimi VARCHAR(255),
    yhteyshenkilo_titteli VARCHAR(255),
    yhteyshenkilo_email VARCHAR(255),
    yhteyshenkilo_puhelin VARCHAR(50),
    lisasahkoposti VARCHAR(255),
    
    -- Kampanja
    mainostava_yritys VARCHAR(255),
    kampanjan_nimi VARCHAR(255),
    kampanjan_tyyppi VARCHAR(100),
    ohjelma VARCHAR(255),
    
    -- Ajat ja tunnisteet
    ohy_aloitus DATE,
    ohy_paattyminen DATE,
    alku_loppu_tunnisteet INT,
    katkotunnisteet INT,
    
    -- Spottikampanja
    spotti_aloitus DATE,
    spotti_paattyminen DATE,
    spotin_pituus_1 VARCHAR(20),
    spottien_maara_1 INT,
    spotin_pituus_2 VARCHAR(20),
    spottien_maara_2 INT,
    spotin_pituus_3 VARCHAR(20),
    spottien_maara_3 INT,
    spotin_pituus_4 VARCHAR(20),
    spottien_maara_4 INT,
    ostettu_trp INT,
    
    -- Lisätiedot
    toteutuneet_esitykset INT,
    kommentit TEXT,
    
    -- Hinnoittelu
    bruttohinta DECIMAL(10,2),
    mediatoimistoalennus DECIMAL(5,2),
    asiakasalennus DECIMAL(5,2),
    muu_alennus DECIMAL(5,2),
    nettohinta DECIMAL(10,2),
    
    -- Liitetiedosto
    tarjous_tiedosto VARCHAR(255),
    
    -- Metatiedot
    luotu_pvm TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paivitetty_pvm TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_myyja (myyja),
    INDEX idx_paivamaara (paivamaara),
    INDEX idx_asiakas (laskutettava_asiakas),
    INDEX idx_kampanja (kampanjan_nimi),
    INDEX idx_luotu (luotu_pvm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sähköpostiasetukset-taulu
CREATE TABLE IF NOT EXISTS sahkoposti_asetukset (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vastaanottajat TEXT,
    smtp_host VARCHAR(255),
    smtp_port INT DEFAULT 587,
    smtp_secure TINYINT(1) DEFAULT 0,
    smtp_user VARCHAR(255),
    smtp_pass VARCHAR(255),
    aktiivinen TINYINT(1) DEFAULT 1,
    luotu_pvm TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paivitetty_pvm TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lisää oletusasetukset
INSERT INTO sahkoposti_asetukset (vastaanottajat, smtp_host, smtp_port, smtp_secure, smtp_user, smtp_pass, aktiivinen)
VALUES ('', 'localhost', 587, 0, '', '', 1);

-- Admin-käyttäjät taulu (valinnainen, jos haluat sisäänkirjautumisen)
CREATE TABLE IF NOT EXISTS admin_kayttajat (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kayttajanimi VARCHAR(50) UNIQUE NOT NULL,
    salasana_hash VARCHAR(255) NOT NULL,
    sahkoposti VARCHAR(255),
    luotu_pvm TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    viimeksi_kirjautunut TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lisää oletuskäyttäjä (käyttäjä: admin, salasana: admin123)
-- VAIHDA SALASANA HETI KUN OLET KIRJAUTUNUT SISÄÄN!
INSERT INTO admin_kayttajat (kayttajanimi, salasana_hash, sahkoposti)
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@example.com');

-- Näkymä tilastojen hakuun
CREATE OR REPLACE VIEW kampanja_tilastot AS
SELECT 
    COUNT(*) as kampanjoita_yhteensa,
    SUM(nettohinta) as kokonaisarvo,
    COUNT(CASE WHEN MONTH(paivamaara) = MONTH(CURDATE()) AND YEAR(paivamaara) = YEAR(CURDATE()) THEN 1 END) as kuukauden_kampanjat,
    SUM(CASE WHEN MONTH(paivamaara) = MONTH(CURDATE()) AND YEAR(paivamaara) = YEAR(CURDATE()) THEN nettohinta ELSE 0 END) as kuukauden_arvo
FROM kampanjat;
