#!/usr/bin/env python3
"""Mutation check terfokus untuk D4 (batas label + PIN Owner).

Setiap mutasi MEYAKINKAN berubahnya sumber dulu, lalu menjalankan test. Mutasi
yang gagal terpasang dilaporkan sebagai "TIDAK TERPASANG", bukan "lolos" --
dua hal itu kelihatan sama kalau cuma membaca jumlah test yang gagal, dan
dulu sempat membuat dua mutasi di D3 terlihat lulus padahal tidak pernah
berjalan sama sekali.
"""

import json
import re
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BACKUP = Path("/var/folders/kv/26jhmp2n0q5df0w_2ysb909c0000gn/T/opencode/d4bak")
TEST_FILTER = "LabelReprintLimitTest"

LIMIT = ROOT / "app/Services/Inventory/ReprintLimit.php"
VERDICT = ROOT / "app/Services/Inventory/ReprintVerdict.php"
SERVICE = ROOT / "app/Services/Inventory/LabelPrintService.php"
REQUEST = ROOT / "app/Http/Requests/Inbound/ReprintLabelsRequest.php"

MUTATIONS = [
    (
        "batas jumlah hanya memakai labels_printed, tidak menghitung cetakan yang masih antre",
        LIMIT,
        "$used = (int) $lot->labels_printed + $inFlight[$lot->id];",
        "$used = (int) $lot->labels_printed;",
    ),
    (
        "jatah harian menghitung label, bukan percobaan",
        LIMIT,
        "'COUNT(*)',",
        "'SUM(copies)',",
    ),
    (
        "label awal ikut menghitung sebagai percobaan cetak ulang",
        LIMIT,
        "->whereIn('reason', LabelReason::reprints())",
        "",
    ),
    (
        "job FAILED ikut memblokir cetakan berikutnya",
        LIMIT,
        """    private const IN_FLIGHT = [
        LabelStatus::Queued->value,
        LabelStatus::Sent->value,
    ];""",
        """    private const IN_FLIGHT = [
        LabelStatus::Queued->value,
        LabelStatus::Sent->value,
        LabelStatus::Failed->value,
    ];""",
    ),
    (
        "Owner ikut diminta PIN atas aksinya sendiri",
        VERDICT,
        "return $this->isBreached() && ! $this->actorIsOwner;",
        "return $this->isBreached();",
    ),
    (
        "service tidak memeriksa ulang batas di dalam transaksi",
        SERVICE,
        """            if ($verdict->needsOwnerPin() && $approvedBy === null) {
                throw new ReprintLimitExceeded($verdict);
            }""",
        "",
    ),
    (
        "approved_by tidak pernah diisi",
        SERVICE,
        "'approved_by' => $isBreached ? $approver : null,",
        "'approved_by' => null,",
    ),
    (
        "token PIN tidak dibatasi ke aksi ini (konteks global)",
        REQUEST,
        "return 'inventory.label-overprint';",
        "return 'global';",
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
        shutil.move(str(hot), str(stash))
        moved = True
    try:
        proc = subprocess.run(
            ["php", "artisan", "test", f"--filter={TEST_FILTER}"],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=900,
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
