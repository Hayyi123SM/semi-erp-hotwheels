#!/usr/bin/env python3
"""Mutation check terfokus untuk D6 (e-receipt WhatsApp konsinyasi).

D6 menambah lapisan yang paling mudah "lulus" tanpa benar: pengiriman pesan
yang isinya salah, dan pengiriman ke orang yang tidak meminta. Test biasa
memeriksa bahwa pesannya terkirim; test ini memeriksa bahwa pesannya ditolak
ketika seharusnya ditolak.

Enam mutasi di bawah masing-masing membuang satu hal yang dijaga D6, satu per
satu:

- syarat opt-in, sehingga nomor yang tidak mencentang ikut diberi tahu;
- pemetaan parameter SRS, sehingga `{{1}}` dan `{{2}}` tertukar;
- backoff SRS, sehingga retry berlari terlalu cepat atau terlalu lambat;
- batas lima percobaan, sehingga notifikasi gagal terus dijadwalkan;
- snapshot body, sehingga bukti "apa yang terkirim" ikut hilang;
- pemisahan dokumen dan notifikasi, sehingga kegagalan pesan ikut membatalkan
  commit barang.

Setiap mutasi MEYAKINKAN berubahnya sumber dulu, lalu menjalankan test. Mutasi
yang gagal terpasang dilaporkan sebagai "TIDAK TERPASANG", bukan "lolos" -- dua
hal itu kelihatan sama kalau cuma membaca jumlah test yang gagal.
"""

import json
import re
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BACKUP = Path("/var/folders/kv/26jhmp2n0q5df0w_2ysb909c0000gn/T/opencode/d6bak")
TEST_FILTER = "NotificationStatusTest|ConsignmentReceiptTest|NotificationSenderTest"

STATUS = ROOT / "app/Enums/NotificationStatus.php"
TEMPLATE = ROOT / "app/Services/Notification/NotificationTemplate.php"
RECEIPT = ROOT / "app/Services/Notification/ConsignmentReceipt.php"
CONTENT = ROOT / "app/Services/Consignment/ReceiptContent.php"
SENDER = ROOT / "app/Services/Notification/NotificationSender.php"
INBOUND = ROOT / "app/Http/Controllers/Pages/InboundController.php"

MUTATIONS = [
    # --- Opt-in: syarat privasi yang paling mudah diloloskan --------------
    (
        "syarat opt-in dilewati: nomor yang tidak mencentang ikut diberi tahu",
        SENDER,
        """        if ($consignor->wa_opt_in_at === null) {
            return $consignor->wa_number === null
                ? 'Penitip belum mengisi nomor WhatsApp.'
                : 'Penitip belum mencentang opt-in WhatsApp.';
        }""",
        """        if ($consignor->wa_number === null) {
            return 'Penitip belum mengisi nomor WhatsApp.';
        }""",
    ),
    (
        "nomor tidak valid dianggap sah: wa.me dibangun dari apa pun yang diketik",
        SENDER,
        "        if (! WhatsappNumber::isValid($consignor->wa_number)) {",
        "        if (false) {",
    ),
    # --- Pemetaan SRS: {{1}} dan {{2}} tertukar ---------------------------
    (
        "urutan parameter SRS tertukar: {{1}} jadi nomor dokumen, nama toko hilang",
        TEMPLATE,
        """            self::ConsignmentReceipt => [
                'store',
                'doc_no',""",
        """            self::ConsignmentReceipt => [
                'doc_no',
                'store',""",
    ),
    (
        "judul memakai nomor dokumen, bukan nama toko",
        TEMPLATE,
        "'*Bukti Terima Titipan — {store}*',",
        "'*Bukti Terima Titipan — {doc_no}*',",
    ),
    # --- Backoff dan batas percobaan -------------------------------------
    (
        "backoff dipercepat jadi satu menit untuk semua percobaan (SRS 1m/5m/30m/2j/6j)",
        SENDER,
        "private const array BACKOFF_MINUTES = [1, 5, 30, 2880, 8640];",
        "private const array BACKOFF_MINUTES = [1, 1, 1, 1, 1];",
    ),
    (
        "retry dijadwalkan tanpa jeda sama sekali",
        SENDER,
        "return now()->addMinutes(self::BACKOFF_MINUTES[$index]);",
        "return now();",
    ),
    (
        "batas lima percobaan dihapus: notifikasi dijadwalkan selamanya",
        SENDER,
        "private const int MAX_ATTEMPTS = 5;",
        "private const int MAX_ATTEMPTS = 5000;",
    ),
    (
        "kegagalan permanen ikut dijadwalkan ulang (opt-in ditunggu tanpa batas)",
        SENDER,
        """        if (! $result->retryable) {
            return $this->markFailed($notification, (string) $result->failure);
        }""",
        """        if (false) {
            return $this->markFailed($notification, (string) $result->failure);
        }""",
    ),
    # --- Pesan yang terkirim ---------------------------------------------
    (
        "isi pesan yang dikirim tidak disimpan, jadi bukti \"apa yang terkirim\" hilang",
        SENDER,
        """        $body = new ConsignmentReceipt($notification->consignment);
        $rendered = $body->body();

        $notification->snapshotBody($rendered);

        return $rendered;""",
        """        return (new ConsignmentReceipt($notification->consignment))->body();""",
    ),
    (
        "retry memakai template terbaru, bukan isi pesan yang gagal",
        SENDER,
        "        $body = $notification->body ?? $this->bodyFor($notification);",
        "        $body = $this->bodyFor($notification);",
    ),
    (
        "data rekening ikut terkirim ke penitip",
        CONTENT,
        "            'detail' => $this->detailText(),",
        "            'detail' => $this->detail($lots).\n            \"\\nRekening: {$this->consignment->consignor?->bank_name} {$this->consignment->consignor?->bank_account}\",",
    ),
    (
        "jumlah item diambil dari baris dokumen, bukan dari lot yang benar-benar ada",
        CONTENT,
        "            'item_count' => (string) $lots->count(),",
        "            'item_count' => (string) $this->consignment->items()->count(),",
    ),
    (
        "varian hilang dari bukti: selisih yang tidak dijelaskan tidak ikut dikirim",
        CONTENT,
        "        $variance = $this->varianceNote();",
        "        $variance = null;",
    ),
    # --- Idempotensi dan urutan kejadian ----------------------------------
    (
        "idempotensi dilonggarkan: satu dokumen bisa punya dua notifikasi",
        SENDER,
        "            ['notification_key' => self::keyFor($template, $consignment)],",
        "            ['notification_key' => self::keyFor($template, $consignment).':'.now()->timestamp],",
    ),
    (
        "pesan yang sudah diserahkan tetap dikirim ulang",
        SENDER,
        """        if (! $notification->status->isOpen()) {
            return $notification;
        }""",
        """        if (false) {
            return $notification;
        }""",
    ),
    (
        "galat dari transport ikut menggagalkan commit barang (penangkapan dipersempit ke Exception)",
        SENDER,
        "        } catch (Throwable $exception) {",
        "        } catch (\\RuntimeException $exception) {",
    ),
    # --- Halaman dokumen --------------------------------------------------
    (
        "halaman dokumen tetap menawarkan tombol kirim untuk nomor tanpa opt-in",
        INBOUND,
        """        if ($consignor?->wa_opt_in_at === null || ! WhatsappNumber::isValid($consignor->wa_number)) {
            return null;
        }""",
        """        if ($consignor?->wa_number === null) {
            return null;
        }""",
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
            timeout=1800,
        )
    finally:
        if moved:
            shutil.move(str(BACKUP / "hot"), str(hot))
    match = re.search(r"(\{.*\})", proc.stdout, re.S)
    if not match:
        return {"failed": 0, "errors": 0, "names": [], "raw": proc.stdout[-800:], "total": 0}
    data = json.loads(match.group(1))
    names = [f["test"].split("::")[-1] for f in data.get("failures") or []]
    names += [e["test"].split("::")[-1] for e in data.get("error_details") or []]
    return {
        "failed": data.get("failed") or 0,
        "errors": data.get("errors") or 0,
        "names": names,
        "raw": "",
        "total": data.get("tests") or 0,
    }


def main() -> int:
    BACKUP.mkdir(parents=True, exist_ok=True)
    targets = sorted({m[1] for m in MUTATIONS})
    for path in targets:
        shutil.copy2(path, BACKUP / path.name)

    undetected = []

    # Baseline WAJIB dicek dan WAJIB hijau sebelum mutasi apa pun dipasang.
    # Kalau dibiarkan begitu saja, satu test yang sudah merah membuat setiap
    # mutasi "tertangkap" -- seluruh hasil check ini jadi tidak berarti. Ini
    # yang terjadi di D3 dan di percobaan pertama D6 ini.
    baseline = run_tests()
    if baseline["failed"] or baseline["errors"] or not baseline["names"] and baseline["raw"]:
        print("Baseline MERAH -- mutasi tidak dijalankan, hasilnya tidak berarti.")
        for name in baseline["names"][:10]:
            print(f"  - {name}")
        if baseline["raw"]:
            print(baseline["raw"])
        return 2
    print(f"Baseline {TEST_FILTER} hijau.\n")

    for label, path, old, new in MUTATIONS:
        if not apply(path, old, new):
            print(f"  TIDAK TERPASANG  {label}")
            print(f"    pola hilang di {path.name}")
            undetected.append(label + " (tidak terpasang)")
            continue

        result = run_tests()
        caught = result["failed"] + result["errors"]
        crashed = result["total"] > 0 and caught >= result["total"]

        shutil.copy2(BACKUP / path.name, path)

        if crashed:
            # Kalau satu mutasi mematikan seluruh suite, yang "tertangkap" cuma
            # prosesnya ikut mati. Itu bukan bukti penjaga di sumber bekerja.
            print(f"  MATI SELURUHNYA  {label}")
            print(f"    {caught}/{result['total']} test gagal sekaligus -- kemungkinan galat sintaks, bukan guard")
            if result["raw"]:
                print(result["raw"])
            undetected.append(label + " (mematikan seluruh suite)")
            continue

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
