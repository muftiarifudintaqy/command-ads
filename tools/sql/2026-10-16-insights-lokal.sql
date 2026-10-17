-- Data Meta lokal untuk dashboard ads.skinlyfe.id (diisi /opt/montera-ads/sync-insights.php)
-- Metrik per baris = urutan rowArr() di app.js: spend, impr, link click, ATC, purchase, value, freq, view content, video 3 detik, thruplay
CREATE TABLE IF NOT EXISTS ins_ad (
  act_id        varchar(40)  NOT NULL,
  tanggal       date         NOT NULL,
  ad_id         varchar(32)  NOT NULL,
  ad_name       varchar(500) NOT NULL DEFAULT '',
  adset_id      varchar(32)  NOT NULL DEFAULT '',
  adset_name    varchar(500) NOT NULL DEFAULT '',
  campaign_id   varchar(32)  NOT NULL DEFAULT '',
  campaign_name varchar(500) NOT NULL DEFAULT '',
  spend decimal(16,2) NOT NULL DEFAULT 0, impr bigint NOT NULL DEFAULT 0, clicks bigint NOT NULL DEFAULT 0,
  atc int NOT NULL DEFAULT 0, purch int NOT NULL DEFAULT 0, value decimal(18,2) NOT NULL DEFAULT 0,
  freq decimal(10,4) NOT NULL DEFAULT 0, vc int NOT NULL DEFAULT 0, v3s bigint NOT NULL DEFAULT 0, thru bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (ad_id, tanggal),
  KEY act_tgl (act_id, tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ins_campaign (
  act_id varchar(40) NOT NULL, tanggal date NOT NULL, campaign_id varchar(32) NOT NULL,
  spend decimal(16,2) NOT NULL DEFAULT 0, impr bigint NOT NULL DEFAULT 0, clicks bigint NOT NULL DEFAULT 0,
  atc int NOT NULL DEFAULT 0, purch int NOT NULL DEFAULT 0, value decimal(18,2) NOT NULL DEFAULT 0,
  vc int NOT NULL DEFAULT 0, v3s bigint NOT NULL DEFAULT 0, thru bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (campaign_id, tanggal),
  KEY act_tgl (act_id, tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ins_account (
  act_id varchar(40) NOT NULL, tanggal date NOT NULL,
  spend decimal(16,2) NOT NULL DEFAULT 0, impr bigint NOT NULL DEFAULT 0, clicks bigint NOT NULL DEFAULT 0,
  atc int NOT NULL DEFAULT 0, purch int NOT NULL DEFAULT 0, value decimal(18,2) NOT NULL DEFAULT 0,
  vc int NOT NULL DEFAULT 0, v3s bigint NOT NULL DEFAULT 0, thru bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (act_id, tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- tanggal yang SUDAH berhasil ditarik lengkap (3 level) per akun → dashboard tahu kapan boleh baca lokal
CREATE TABLE IF NOT EXISTS ins_cover (
  act_id varchar(40) NOT NULL, tanggal date NOT NULL, ditarik_at datetime NOT NULL,
  PRIMARY KEY (act_id, tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- struktur akun (campaign/adset/iklan aktif + creative) = acc.raw di app.js
CREATE TABLE IF NOT EXISTS ins_struct (
  act_id varchar(40) NOT NULL PRIMARY KEY, raw mediumtext NOT NULL, diambil_at datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- status terakhir per akun (token terpilih, error terakhir) → kartu akun & badge
CREATE TABLE IF NOT EXISTS ins_status (
  act_id varchar(40) NOT NULL PRIMARY KEY,
  nama varchar(120) NOT NULL DEFAULT '',
  token_key varchar(40) DEFAULT NULL,
  token_status varchar(20) NOT NULL DEFAULT 'ok',      -- ok | invalid (190) | no_access (10/200) | error
  terakhir_ok_at datetime DEFAULT NULL,
  terakhir_coba_at datetime DEFAULT NULL,
  error_terakhir varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- log sync per akun per run
CREATE TABLE IF NOT EXISTS ins_sync_log (
  id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY,
  act_id varchar(40) NOT NULL, mode varchar(20) NOT NULL, since date NOT NULL, until date NOT NULL,
  mulai datetime NOT NULL, selesai datetime DEFAULT NULL,
  baris_ad int DEFAULT NULL, baris_campaign int DEFAULT NULL, baris_account int DEFAULT NULL,
  token_key varchar(40) DEFAULT NULL, cara varchar(120) DEFAULT NULL,
  status varchar(10) NOT NULL DEFAULT 'jalan',          -- jalan | ok | gagal
  error varchar(1000) DEFAULT NULL,
  KEY act_mulai (act_id, mulai)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
