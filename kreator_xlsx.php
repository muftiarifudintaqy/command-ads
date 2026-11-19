<?php
/* [kreator 18 Nov, Mufti/Adila] Export Excel "Konten per Kreator <brand> <periode>.xlsx" — ikut filter yang sedang dipilih.
   Sheet: Ringkasan · Detail Konten · Belum ada kreator · Info. Dipanggil dari kreator.php (variabel halaman sudah siap). */
if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) { http_response_code(404); exit; }
require_once '/var/www/skinlyfe-erp/vendor/autoload.php';   // PhpSpreadsheet (vendor ERP, server yang sama)
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$RP = '"Rp"#,##0;-"Rp"#,##0'; $ANGKA = '#,##0'; $ROAS = '0.00';
$hex = function ($c) { return strtoupper(ltrim($c, '#')); };
$brandTxt = $merek === '' ? 'Semua' : ucfirst($merek);
$periodeTxt = date('d-m-Y', strtotime($dari)) . ' sd ' . date('d-m-Y', strtotime($sampai));
$ss = new Spreadsheet();
$ss->getProperties()->setCreator('ads.prepareofficial.com')->setTitle('Konten per Kreator ' . $brandTxt . ' ' . $periodeTxt);

/** tulis tabel: judul kolom + baris; $fmt [kolom => format]; kembalikan baris terakhir */
$tabel = function ($ws, array $kepala, array $baris, array $fmt = [], array $lebar = []) use ($hex) {
    $ws->fromArray($kepala, NULL, 'A1');
    $akhirKol = Coordinate::stringFromColumnIndex(count($kepala));
    $ws->getStyle("A1:{$akhirKol}1")->applyFromArray(['font' => ['bold' => TRUE, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F172A']], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => TRUE]]);
    $ws->getRowDimension(1)->setRowHeight(30);
    $r = 2;
    foreach ($baris as $b) { $ws->fromArray(array_values($b), NULL, 'A' . $r, TRUE); $r++; }
    $akhir = max(2, $r - 1);
    foreach ($fmt as $kol => $f) $ws->getStyle("{$kol}2:{$kol}{$akhir}")->getNumberFormat()->setFormatCode($f);
    foreach (range(1, count($kepala)) as $i) { $k = Coordinate::stringFromColumnIndex($i); $ws->getColumnDimension($k)->setWidth($lebar[$k] ?? 14); }
    $ws->freezePane('A2');
    if ($baris) $ws->setAutoFilter("A1:{$akhirKol}{$akhir}");
    return $akhir;
};
$roasNilai = function ($r) { return is_infinite($r) ? '∞' : round((float)$r, 2); };

/* ---------- Ringkasan ---------- */
$ws = $ss->getActiveSheet(); $ws->setTitle('Ringkasan');
$kep = array_merge(['Kreator', $per === 'konten' ? 'Konten' : 'Iklan'], array_map(function ($k) { return ucwords(strtolower($k)); }, KR_KATEGORI), ['Spent', 'Omset', 'ROAS', 'Purchase', 'CPA']);
$rows = [];
foreach ($rekap as $r) $rows[] = array_merge([$r['nama'], $r['jml']], array_values($r['kat']), [round($r['spend']), round($r['omset']), $r['spend'] > 0 ? round($r['omset'] / $r['spend'], 2) : NULL, $r['purch'], $r['purch'] ? round($r['spend'] / $r['purch']) : NULL]);
$akhir = $tabel($ws, $kep, $rows, ['J' => $RP, 'K' => $RP, 'L' => $ROAS, 'M' => $ANGKA, 'N' => $RP, 'B' => $ANGKA], ['A' => 26, 'J' => 16, 'K' => 18, 'N' => 12]);
foreach (KR_KATEGORI as $i => $k) { $c = Coordinate::stringFromColumnIndex($i + 3);
    $ws->getStyle("{$c}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($hex($WARNA[$k]));
    $ws->getStyle("{$c}2:{$c}{$akhir}")->getFont()->getColor()->setRGB($hex($WARNA[$k])); $ws->getStyle("{$c}2:{$c}{$akhir}")->getFont()->setBold(TRUE); }
$t = $akhir + 1;
$ws->fromArray(array_merge(['TOTAL', $total['jml']], array_values($total['kat']), [round($total['spend']), round($total['omset']), $total['spend'] > 0 ? round($total['omset'] / $total['spend'], 2) : NULL, $total['purch'], $total['purch'] ? round($total['spend'] / $total['purch']) : NULL]), NULL, "A$t", TRUE);
$ws->getStyle("A$t:N$t")->applyFromArray(['font' => ['bold' => TRUE], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']]]);
foreach (['J' => $RP, 'K' => $RP, 'L' => $ROAS, 'M' => $ANGKA, 'N' => $RP] as $k => $f) $ws->getStyle("$k$t")->getNumberFormat()->setFormatCode($f);

/* ---------- Detail Konten ---------- */
$ws = $ss->createSheet(); $ws->setTitle('Detail Konten');
$rows = [];
foreach ($iklan as $a) $rows[] = [$a['kreator']['nama'], $a['kategori'], $a['ad_name'], (string)($a['produk'] ?? ''), ucfirst((string)$a['merek']), $a['akun'], count($a['iklan'] ?? [1]),
    $a['mulai'] ? date('d/m/Y', strtotime($a['mulai'])) : '', round($a['spend']), round($a['omset']), $roasNilai($a['roas']), $a['purch'], $a['purch'] ? round($a['spend'] / $a['purch']) : NULL, $a['atc'], kr_link($a)];
// [naming-produk 19 Nov] kolom Produk (produk per iklan; konten dengan produk beda = baris beda)
$akhir = $tabel($ws, ['Kreator', 'Kategori', $per === 'konten' ? 'Nama konten' : 'Nama iklan', 'Produk', 'Brand', 'Akun', 'Jumlah iklan digabung', 'Mulai tayang', 'Spent', 'Omset', 'ROAS', 'Purchase', 'CPA', 'ATC', 'Link Ads Manager'],
    $rows, ['I' => $RP, 'J' => $RP, 'K' => $ROAS, 'L' => $ANGKA, 'M' => $RP, 'N' => $ANGKA], ['A' => 22, 'B' => 16, 'C' => 40, 'D' => 14, 'F' => 24, 'G' => 11, 'I' => 15, 'J' => 16, 'O' => 30]);
foreach ($iklan as $i => $a) { $r = $i + 2;
    $ws->getStyle("B$r")->applyFromArray(['font' => ['bold' => TRUE, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $hex($WARNA[$a['kategori']])]]]);
    $ws->setCellValue("O$r", 'Buka di Ads Manager'); $ws->getCell("O$r")->getHyperlink()->setUrl(kr_link($a)); $ws->getStyle("O$r")->getFont()->setUnderline(TRUE)->getColor()->setRGB('2563EB'); }

/* ---------- Belum ada kreator ---------- */
$ws = $ss->createSheet(); $ws->setTitle('Belum ada kreator');
$rows = [];
foreach ($belum as $a) $rows[] = [$a['ad_name'], $a['campaign_name'], $a['akun'], ucfirst((string)$a['merek']), $a['mulai'] ? date('d/m/Y', strtotime($a['mulai'])) : '',
    $a['status'] === 'ACTIVE' ? 'Aktif' : kr_status_teks($a['status']), round($a['spend']), round($a['omset']), $roasNilai($a['roas']), $a['purch'], $a['kreator']['sumber'] === 'tidak-dikenal' ? $a['kreator']['nama'] : 'Tanpa kode'];
$tabel($ws, [$per === 'konten' ? 'Nama konten' : 'Nama iklan', 'Campaign', 'Akun', 'Brand', 'Mulai tayang', 'Status', 'Spent', 'Omset', 'ROAS', 'Purchase', 'Keterangan'],
    $rows, ['G' => $RP, 'H' => $RP, 'I' => $ROAS, 'J' => $ANGKA], ['A' => 40, 'B' => 44, 'C' => 24, 'G' => 15, 'H' => 16, 'K' => 24]);

/* ---------- Info ---------- */
$ws = $ss->createSheet(); $ws->setTitle('Info');
$v = $val; $okTxt = function ($t) { return ($t['ok'] ? '✅ cocok' : '❌ beda') . ' · spent ' . number_format($t['sp_i'], 0, ',', '.') . ' vs ' . number_format($t['sp_a'], 0, ',', '.')
    . ' · omset ' . number_format($t['om_i'], 0, ',', '.') . ' vs ' . number_format($t['om_a'], 0, ',', '.') . ' · purchase ' . $t['pu_i'] . ' vs ' . $t['pu_a']; };
$info = [['Konten per Kreator', ''], ['Periode', date('d/m/Y', strtotime($dari)) . ' – ' . date('d/m/Y', strtotime($sampai)) . ' (' . ($PRESET[$pr] ?? $pr) . ')'],
    ['Brand', $brandTxt], ['Kreator', $fk !== '' ? $fk : 'Semua'], ['Hitung per', $per === 'konten' ? 'Konten (iklan dengan nama konten sama digabung: varian B2/B3, Copy, Shopee/VATC; sama dengan sheet Naming)' : 'Iklan (ad ID, = baris Ads Manager)'],
    ['Sumber data', 'Meta Marketing API level iklan per hari (montera_ads.ins_ad, sinkron tiap 10 menit), tanpa tarikan baru. Spent = Amount Spent tanpa pajak. Omset & purchase = katalog CPAS (shared items), atribusi 7 hari klik / 1 hari lihat.'],
    ['Jam tarik data terakhir', $jamTarik ? date('d/m/Y H:i', strtotime($jamTarik)) . ' WIB' : '-'],
    ['Aturan kategori (SOP)', 'Spent < 1 jt: ROAS < 3 Testing · 3–<8 Opportunity · ≥ 8 High Potential'], ['', 'Spent ≥ 1 jt: ROAS < 2 Not Performing · 2–<3 Performing · ≥ 3 Winning (spent 1–5 jt) / Top Winning (> 5 jt)'],
    ['', 'Purchase & CPA = validasi. ROAS ∞ = purchase dari atribusi tanpa belanja di periode ini.'],
    ['Kreator', 'Manual (per iklan > konten > campaign) menang atas kode di nama iklan ("<seri> <nomor><KODE>", mis. Stitch 04R). Tanpa kode = Belum ada kode.'],
    ['Validasi (total iklan vs total akun Meta)', ''], ];
foreach ($v['brand'] as $t) $info[] = ['  ' . $t['nama'], $okTxt($t)];
$info[] = ['  Total', $okTxt($v['total'])];
foreach (array_slice($v['beda'], 0, 30) as $b) $info[] = ['  ' . $b['nama'] . ' ' . date('d/m', strtotime($b['tanggal'])), $sebab($b)];
$info[] = ['Dibuat', date('d/m/Y H:i') . ' WIB oleh ' . $user];
$ws->fromArray($info, NULL, 'A1');
$ws->getColumnDimension('A')->setWidth(38); $ws->getColumnDimension('B')->setWidth(120);
$ws->getStyle('A1')->getFont()->setBold(TRUE)->setSize(14); $ws->getStyle('A2:A' . count($info))->getFont()->setBold(TRUE);
$ws->getStyle('B1:B' . count($info))->getAlignment()->setWrapText(TRUE);

$ss->setActiveSheetIndex(0);
$nama = 'Konten per Kreator ' . $brandTxt . ' ' . $periodeTxt . '.xlsx';
if (PHP_SAPI === 'cli') { (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save(getenv('KREATOR_XLSX') ?: '/tmp/ads-uji/' . $nama); echo $nama, "\n"; return; }
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$nama\"; filename*=UTF-8''" . rawurlencode($nama));
header('Cache-Control: no-store');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
