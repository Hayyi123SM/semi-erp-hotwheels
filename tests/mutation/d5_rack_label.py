#!/usr/bin/env python3
"""Mutation check terfokus untuk D5 (label rak + uji cetak).

D5 menambah dua hal yang mudah lolos dari test biasa: prefiks `RK:` yang
dihitung oleh satu tempat tapi dibaca di beberapa tempat, dan geometri label rak
yang duluan hanya ada di CSS. Keduanya bisa "lulus" kalau testnya memeriksa
string yang salah. Jadi yang diuji di sini bukan "test hijau", tapi "test jadi
merah kalau sumbernya dirusak".

Setiap mutasi MEYAKINKAN berubahnya sumber dulu, lalu menjalankan test. Mutasi
yang gagal terpasang dilaporkan sebagai "TIDAK TERPASANG", bukan "lolos" --
dua hal itu kelihatan sama kalau cuma membaca jumlah test yang gagal, dan di
D3 sempat membuat dua mutasi terlihat lulus padahal tidak pernah berjalan.
"""

import json
import re
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BACKUP = Path("/var/folders/kv/26jhmp2n0q5df0w_2ysb909c0000gn/T/opencode/d5bak")
TEST_FILTER = "LabelGeometryTest|HtmlLabelRendererTest|LabelPageTest|LabelContentTest|RackLabelPrintTest|LabelTestPrintTest"

RACK_CODE = ROOT / "app/Services/Label/RackCode.php"
GEOMETRY = ROOT / "app/Services/Label/LabelGeometry.php"
RENDERER = ROOT / "app/Services/Label/HtmlLabelRenderer.php"
PAGE = ROOT / "app/Services/Label/LabelPage.php"
CONTENT = ROOT / "app/Services/Label/LabelContent.php"
REQUEST = ROOT / "app/Http/Requests/Master/PrintRackLabelsRequest.php"
RACK_CONTROLLER = ROOT / "app/Http/Controllers/Master/RackController.php"
INBOUND_CONTROLLER = ROOT / "app/Http/Controllers/Pages/InboundController.php"

MUTATIONS = [
    # --- Prefiks RK: -------------------------------------------------------
    (
        "kode rak dicetak tanpa prefiks RK:",
        RACK_CODE,
        "return str_starts_with($code, self::PREFIX) ? $code : self::PREFIX.$code;",
        "return $code;",
    ),
    (
        "prefiks RK: digandakan pada kode yang sudah berprefiks",
        RACK_CODE,
        "return str_starts_with($code, self::PREFIX) ? $code : self::PREFIX.$code;",
        "return self::PREFIX.$code;",
    ),
    # --- Geometri label rak ----------------------------------------------
    (
        "label rak 4x3 kehilangan QR-nya (FR-MD-21 jadi tidak bisa discan)",
        GEOMETRY,
        """                gutterCm: 0.10,
                qrSideCm: 1.25,
                rows: [new LabelRow(0.42, 1.10, maxLines: 2, weight: 700, mono: true, caption: 'RAK')],""",
        """                gutterCm: 0.10,
                qrSideCm: 0.0,
                rows: [new LabelRow(0.42, 1.10, maxLines: 2, weight: 700, mono: true, caption: 'RAK')],""",
    ),
    (
        "label rak 3x2 dikecilkan kembali ke font 0.80 satu baris (kode 15 karakter terpotong)",
        GEOMETRY,
        "gutterCm: 0.0,\n                qrSideCm: 0.0,\n                rows: [new LabelRow(0.42, 1.10, maxLines: 2, weight: 700, mono: true, caption: 'RAK')],",
        "gutterCm: 0.0,\n                qrSideCm: 0.0,\n                rows: [new LabelRow(0.80, 1.10, maxLines: 1, weight: 700, mono: true, caption: 'RAK')],",
    ),
    (
        "label rak 3x2 ikut membawa QR sehingga kode rak tidak terbaca",
        GEOMETRY,
        "gutterCm: 0.0,\n                qrSideCm: 0.0,\n                rows: [new LabelRow(0.42, 1.10, maxLines: 2, weight: 700, mono: true, caption: 'RAK')],",
        "gutterCm: 0.10,\n                qrSideCm: 0.60,\n                rows: [new LabelRow(0.42, 1.10, maxLines: 2, weight: 700, mono: true, caption: 'RAK')],",
    ),
    (
        "batas panjang kode rak dihitung tanpa prefiks RK:",
        REQUEST,
        "$printed = mb_strlen(RackCode::printable($code));",
        "$printed = mb_strlen($code);",
    ),
    # --- Renderer ---------------------------------------------------------
    (
        "renderRack berhenti mengirim blok QR ke layout",
        RENDERER,
        """            $this->body(
                $geometry,
                $this->row($geometry, $geometry->rows[0], $code, compact: false),
                $this->qrBlock($code, $geometry),
            ),""",
        """            $this->body(
                $geometry,
                $this->row($geometry, $geometry->rows[0], $code, compact: false),
            ),""",
    ),
    (
        "renderRack mencetak kode rak mentah tanpa prefiks",
        RENDERER,
        "$code = RackCode::printable($rackCode);",
        "$code = $rackCode;",
    ),
    # --- Validasi panjang kode -------------------------------------------
    (
        "validasi berhenti menolak kode rak kepanjangan (label terpotong diam-diam)",
        REQUEST,
        "        $validator->after(function (Validator $validator): void {\n            $this->checkCodesFit($validator);\n        });",
        "",
    ),
    (
        "kode rak tepat di batas kapasitas ikut ditolak",
        REQUEST,
        "if ($printed > $capacity) {",
        "if ($printed >= $capacity) {",
    ),
    # --- Urutan & jumlah label --------------------------------------------
    (
        "label rak keluar menurut urutan id, bukan urutan pilihan operator",
        RACK_CONTROLLER,
        "->sortBy(fn (Rack $rack) => array_search($rack->id, $ids, true))",
        "",
    ),
    (
        "jumlah label rak mengabaikan jumlah salinan",
        RACK_CONTROLLER,
        "'total' => $racks->count() * $copies,",
        "'total' => $racks->count(),",
    ),
    (
        "setiap rak jadi sheet sendiri, jadi operator ganti kertas per label",
        PAGE,
        "        return $this->forRacks(collect([$rack]), $template, $copies, $geometry);",
        "        return $this->wrap([$this->renderer->renderRack($rack->code, $template)]);",
    ),
    # --- Uji cetak (FR-IB-25) --------------------------------------------
    (
        "penanda uji cetak hilang, jadi label contoh terlihat seperti label barang",
        INBOUND_CONTROLLER,
        "            'isTestPrint' => true,",
        "            'isTestPrint' => false,",
    ),
    (
        "isi uji cetak memakai SKU pendek, jadi baris SKU yang meluber tidak terlihat",
        CONTENT,
        "            sku: 'HW-2024-000123X',",
        "            sku: 'HW-1',",
    ),
    (
        "isi uji cetak memakai harga murah, jadi baris harga yang meluber tidak terlihat",
        CONTENT,
        "            listPrice: 100_000_000,",
        "            listPrice: 15_000,",
    ),
    (
        "isi uji cetak memakai kondisi terpendek, jadi pasangan singkatan panjang tidak diuji",
        CONTENT,
        """            cardCondition: CardCondition::NearMint,
            blisterCondition: BlisterCondition::Clear,""",
        """            cardCondition: CardCondition::Mint,
            blisterCondition: BlisterCondition::Clear,""",
    ),
    (
        "jumlah label uji cetak diabaikan, jadi testPrint tidak pernah ikut diuji",
        PAGE,
        "        $content = LabelContent::sample();\n        $labels = array_fill(0, $copies, $this->renderer->render($content, $template, $showPrice));",
        "        $content = LabelContent::sample();\n        $labels = [$this->renderer->render($content, $template, $showPrice)];",
    ),
    # --- Regresi yang baru ketahuan lewat isi uji cetak -------------------
    # Uji cetak memakai kasus terburuk, dan kasus terburuk itu memunculkan
    # cacat yang tidak terlihat dari data contoh: harga tertinggi yang boleh
    # diisi tidak muat, jadi tercetak jadi angka yang salah.
    (
        "font harga label 3x2 dikembalikan ke 0,28 sehingga harga tertinggi terpotong",
        GEOMETRY,
        "                    new LabelRow(0.23, 1.05, maxLines: 1, weight: 700),",
        "                    new LabelRow(0.28, 1.05, maxLines: 1, weight: 700),",
    ),
    (
        "font harga label 4x3 dikembalikan ke 0,36 sehingga harga tertinggi terpotong",
        GEOMETRY,
        "                    new LabelRow(fontSizeCm: 0.31, lineHeight: 1.05, maxLines: 1, weight: 700, caption: 'HARGA'),",
        "                    new LabelRow(fontSizeCm: 0.36, lineHeight: 1.05, maxLines: 1, weight: 700, caption: 'HARGA'),",
    ),
    (
        "baris harga dibolehkan dua baris, jadi harga bisa terpenggal di tengah angka",
        GEOMETRY,
        "                    new LabelRow(0.23, 1.05, maxLines: 1, weight: 700),",
        "                    new LabelRow(0.23, 1.05, maxLines: 2, weight: 700),",
    ),
    (
        "isi uji cetak memakai kode penitip yang tidak pernah ada di master",
        CONTENT,
        "            ownerCode: 'CN999',",
        "            ownerCode: 'CN-0142',",
    ),
]


def apply(path: Path, old: str, new: str) -> bool:
    source = path.read_text(encoding="utf-8")
    if old not in source:
        return False
    path.write_text(source.replace(old, new, 1), encoding="utf-8")
    return True


def run_tests() -> dict:
    hot = ROOT / "public/hot"
    moved = False
    if hot.exists():
        stash = BACKUP / "hot"
        stash.parent.mkdir(parents=True, exist_ok=True)
        shutil.move(str(hot), str(stash))
        moved = True
    try:
        proc = subprocess.run(
            ["php", "artisan", "test", f"--filter={TEST_FILTER}"],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=1200,
        )
    finally:
        if moved:
            shutil.move(str(BACKUP / "hot"), str(hot))
    match = re.search(r"(\{.*\})", proc.stdout, re.S)
    if not match:
        return {"failed": 0, "errors": 0, "names": [], "raw": proc.stdout[-800:]}
    data = json.loads(match.group(1))
    names = [f["test"].split("::")[-1] for f in data.get("failures") or []]
    names += [e["test"].split("::")[-1] for e in data.get("error_details") or []]
    return {
        "failed": data.get("failed") or 0,
        "errors": data.get("errors") or 0,
        "names": names,
        "raw": "",
    }


def main() -> int:
    BACKUP.mkdir(parents=True, exist_ok=True)
    targets = sorted({m[1] for m in MUTATIONS})
    for path in targets:
        shutil.copy2(path, BACKUP / path.name)

    undetected = []
    print(f"Baseline {TEST_FILTER} harus hijau; kalau tidak, mutasi tidak bermakna.\n")

    for label, path, old, new in MUTATIONS:
        if not apply(path, old, new):
            print(f"  TIDAK TERPASANG  {label}")
            print(f"    pola hilang di {path.name}")
            undetected.append(label + " (tidak terpasang)")
            continue

        result = run_tests()
        caught = result["failed"] + result["errors"]

        shutil.copy2(BACKUP / path.name, path)

        if caught:
            print(f"  tertangkap  ({caught})  {label}")
            for name in result["names"][:4]:
                print(f"      - {name}")
        else:
            print(f"  LOLOS            {label}")
            if result["raw"]:
                print(result["raw"])
            undetected.append(label)

    print()
    if undetected:
        print(f"{len(undetected)} mutasi tidak terdeteksi:")
        for item in undetected:
            print(f"  - {item}")
        return 1

    print(f"Semua {len(MUTATIONS)} mutasi tertangkap.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
