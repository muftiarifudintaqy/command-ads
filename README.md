# Montera Ads — Command Center

Dashboard internal untuk memantau semua akun Meta Ads (CPAS) dalam satu halaman + analisis Montera AI.

## Cara menjalankan (lokal)
```bash
cp config.example.php config.php   # lalu isi token Meta & API key AI di config.php
PHP_CLI_SERVER_WORKERS=8 php -S localhost:8000
```
Buka http://localhost:8000

## Catatan keamanan
- `config.php` berisi token Meta & API key — **jangan pernah di-commit** (sudah di `.gitignore`).
- Kalau dipasang di server, isi `app_password` di `config.php`.