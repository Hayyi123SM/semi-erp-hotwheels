import { calculateTerms } from './terms-calculator';

/**
 * Grid penerimaan Consignment In: baris, syarat skema, PIN Owner saat commit,
 * dan draft yang tidak hilang saat tab tertutup.
 *
 * Empat hal yang dipegang komponen ini, dan sengaja tidak tersebar ke markup:
 *
 *  - **Default penitip.** Baris yang tidak diisi harga/skema memakai kontrak
 *    penitip yang dipilih, dan baris seperti itu tidak dianggap penyimpangan.
 *  - **Satu token untuk satu commit.** Deviasi di lima baris tetap satu dialog
 *    PIN, bukan lima. Token-nya diletakkan di `pin_token` dan form yang gagal
 *    validasi bisa dikirim ulang dengan token yang sama, selama masih dalam masa
 *    berlaku.
 *  - **Pratinjau bukan tagihan.** `calculateTerms` dipakai untuk menampilkan
 *    angka; server menghitung ulang saat commit dan tetap yang menentukan.
 *  - **Draft dibuat saat dipakai, bukan saat halaman dibuka.** Menyimpan draft
 *    di awal membuat setiap Staff yang sekadar lewat akan meninggalkan jejak
 *    `DRAFT` di server, dan daftar draft akan cepat tidak berguna.
 */

/** Field skema per tipe, cermin `SchemeType::parameterField()` di PHP. */
const PARAMETER_FIELD = {
    PERCENTAGE: 'scheme_rate',
    NETT: 'scheme_amount',
    FLAT: 'scheme_amount',
};

/**
 * Jeda auto-save, sesuai dokumen. Cukup panjang untuk tidak menulis pada tiap
 * ketikan, cukup pendek supaya "~1 menit lalu masih ada di server" tidak jadi
 * janji yang salah.
 */
const AUTOSAVE_DELAY_MS = 10_000;

/** Kunci localStorage, berversi supaya format payload boleh berubah. */
const LOCAL_DRAFT_KEY = 'wms.consignment-in.draft.v1';

const DEFAULT_ROW = () => ({
    product_id: '',
    qty: 1,
    card_condition: 'MINT',
    blister_condition: 'CLEAR',
    rack_id: '',
    list_price: '',
    scheme_type: '',
    scheme_rate: '',
    scheme_amount: '',
    discount_policy: '',
});

/** Baris yang belum diisi produk apa pun tidak layak disimpan. */
function isEmptyRow(row) {
    return row.product_id === '' || row.product_id === null || row.product_id === undefined;
}

export function inboundGrid({
    consignors = {},
    productPrices = {},
    isOwner = false,
    pinContext = 'consignment.scheme-override',
    rows = null,
    itemErrors = {},
    pinToken = '',
    draftId = '',
    resumeDraftId = '',
    // False kalau server baru saja mengembalikan form karena validasi gagal.
    // `old()` di situ sudah berisi apa yang harus benar, dan cermin lokal bisa
    // saja lebih lama -- menimpanya berarti membatalkan perbaikan Staff.
    restorable = true,
    source = '',
    notes = '',
    claimedQty = null,
    varianceNote = '',
} = {}) {
    return {
        consignorId: '',
        consignors,
        productPrices,
        isOwner,
        pinContext,
        // Isian yang dikembalikan server setelah validasi gagal dikembalikan ke
        // grid. Menulai dari baris kosong setiap kali form gagal berarti kasir
        // mengetik ulang seluruh dokumen hanya karena satu baris salah.
        rows: rows && rows.length > 0 ? rows.map((row) => ({ ...DEFAULT_ROW(), ...row })) : [DEFAULT_ROW()],
        // Pesan validasi per sel, dibaca dari `items.{index}.{field}`. Tanpa ini
        // pesan dari server tidak punya tempat tampil: barisnya dirender Alpine,
        // jadi Blade tidak bisa menulis pesan itu di dalam selnya.
        itemErrors,
        // Token yang sudah diperoleh, diteruskan dari form yang ditolak server.
        // Tanpa ini, satu sel yang salah membuat kasir mengetik ulang PIN
        // yang masih sah selama lima menit -- dan "satu dialog untuk satu commit"
        // hanya berlaku selama halamannya tidak pernah dimuat ulang.
        //
        // Token yang sudah ditolak server TIDAK diteruskan: memakainya lagi
        // akan membuat commit berikutnya ditolak dengan alasan yang sama, tanpa
        // pernah membuka dialog, dan formnya tidak akan pernah bisa dikirim.
        pinToken,
        pinError: '',
        submitting: false,
        verified: false,

        // --- Draft ---
        draftId,
        restorable,
        saveState: 'idle',
        savedAt: null,
        source,
        notes,
        claimedEnabled: claimedQty !== null && claimedQty !== undefined && claimedQty !== '',
        claimedQty: claimedQty === null || claimedQty === undefined ? '' : claimedQty,
        varianceNote,
        saveTimer: null,

        init() {
            if (resumeDraftId !== '') {
                this.loadDraft(resumeDraftId);

                return;
            }

            if (this.restorable) {
                this.restoreLocalDraft();
            }
        },

        get consignor() {
            return this.consignorId === '' ? null : (this.consignors[String(this.consignorId)] ?? null);
        },

        get schemeTypes() {
            return Object.keys(PARAMETER_FIELD);
        },

        addRow() {
            this.rows.push(DEFAULT_ROW());
        },

        removeRow(index) {
            if (this.rows.length > 1) {
                this.rows.splice(index, 1);
            }
        },

        /**
         * Pisah satu baris jadi dua, kalau skema atau harganya berubah.
         *
         * Baris hasil pecahan tidak mewarisi qty. Membagi qty secara otomatis akan
         * mengubah total unit yang sudah diverifikasi secara fisik, jadi kasir
         * yang memecah satu baris 10 unit harus mengetik ulang kedua qty-nya --
         * dan itu memang keputusan yang harus diambil manusia.
         */
        splitRow(index) {
            const source = this.rows[index];

            if (!source) {
                return;
            }

            const copy = { ...DEFAULT_ROW(), ...source, qty: 1 };

            this.rows.splice(index + 1, 0, copy);
        },

        /**
         * Pesan validasi untuk satu sel baris, atau string kosong.
         */
        errorFor(index, field) {
            return this.itemErrors[`items.${index}.${field}`] ?? '';
        },

        /**
         * Waktu simpan terakhir dalam bahasa yang enak dibaca.
         *
         * Sengaja "baru saja"/"beberapa menit lalu", bukan jam menit: Staff
         * sedang menghitung barang dan tidak butuh presisi, dan angka presisi
         * di sini hanya menambah kebohongan ketika tab sudah lama terbuka.
         */
        savedAtLabel() {
            if (!this.savedAt) {
                return 'sebentar tadi';
            }

            const seconds = Math.max(0, (Date.now() - new Date(this.savedAt).getTime()) / 1000);

            if (seconds < 45) {
                return 'baru saja';
            }

            if (seconds < 3600) {
                return `${Math.round(seconds / 60)} menit lalu`;
            }

            return new Date(this.savedAt).toLocaleString('id-ID');
        },

        totalQty() {
            return this.rows.reduce((sum, row) => sum + (Number(row.qty) || 0), 0);
        },

        /**
         * Selisih antara yang diklaim penitip dan yang benar-benar dihitung.
         *
         * Angka positif berarti barang lebih banyak dari yang diklaim. Angka ini
         * belum pernah negatif di UI karena `qty_claimed` tidak dibatasi dari
         * bawah di sini; pembatasannya ada di server bersama kolomnya.
         */
        variance() {
            if (this.claimedEnabled === false || this.claimedQty === '') {
                return 0;
            }

            return (Number(this.claimedQty) || 0) - this.totalQty();
        },

        // --- Autosave draft ---

        /**
         * Jadwalkan penyimpanan setelah Staff berhenti mengetik.
         *
         * Dipanggil dari `x-effect` pada baris dan header, jadi tidak ada satu
         * pun field form yang perlu mengingat bahwa ia perlu disimpan.
         */
        scheduleSave() {
            // Setelah server bilang dokumennya sudah di-commit, form ini sudah
            // tidak milik kita lagi. Melanjutkan autosave akan menulis ulang isi
            // form ke dokumen yang sudah jadi SKU.
            if (this.saveState === 'locked') {
                return;
            }

            this.writeLocalDraft();

            if (this.saveTimer !== null) {
                clearTimeout(this.saveTimer);
            }

            // Halaman yang sedang berisi form yang gagal divalidasi tidak perlu
            // disimpan ulang: isinya sudah ada di `old()`, dan autosave di sini
            // hanya akan menimpa draft dengan data yang sama.
            this.saveTimer = setTimeout(() => this.flushSave(), AUTOSAVE_DELAY_MS);
        },

        async flushSave() {
            if (this.saveTimer !== null) {
                clearTimeout(this.saveTimer);
                this.saveTimer = null;
            }

            if (this.submitting || this.isNothingToSave()) {
                return;
            }

            this.saveState = 'saving';

            const url = this.draftId === ''
                ? '/inbound/consignment-in/drafts'
                : `/inbound/consignment-in/drafts/${this.draftId}`;

            try {
                const response = await fetch(url, {
                    method: this.draftId === '' ? 'POST' : 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken(),
                    },
                    body: JSON.stringify(this.draftPayload()),
                });

                if (response.status === 409) {
                    // Dokumennya sudah di-commit dari tab lain. Menimpanya akan
                    // mengarang ulang barang yang sudah jadi SKU.
                    this.saveState = 'locked';
                    this.clearLocalDraft();

                    return;
                }

                if (!response.ok) {
                    throw new Error(`status ${response.status}`);
                }

                const body = await response.json();

                this.draftId = body.draft.draft_id;
                this.savedAt = body.draft.saved_at;
                this.saveState = 'saved';
                this.writeLocalDraft();
            } catch (error) {
                // Autosave yang gagal tidak boleh mengganggu commit. Isinya
                // masih tersimpan di localStorage, jadi tidak ada yang hilang --
                // hanya saja belum sampai ke server.
                this.saveState = 'offline';
            }
        },

        /**
         * Tidak ada yang layak disimpan: form belum punya satu pun baris pun.
         *
         * Tanpa pemeriksaan ini, Staff yang membuka halaman lalu memilih
         * penitip akan meninggalkan draft tanpa barang di server -- dan draft
         * seperti itu tidak bisa di-commit, jadi tidak ada gunanya disimpan.
         */
        isNothingToSave() {
            if (this.verified || this.source !== '' || this.notes !== '') {
                return false;
            }

            if (this.claimedEnabled && (this.claimedQty !== '' || this.varianceNote !== '')) {
                return false;
            }

            return this.rows.every((row) => isEmptyRow(row));
        },

        draftPayload() {
            return {
                consignor_id: this.consignorId === '' ? null : this.consignorId,
                consignment_date: this.consignmentDate(),
                source: this.source,
                notes: this.notes,
                qty_claimed: this.claimedEnabled && this.claimedQty !== '' ? this.claimedQty : null,
                variance_note: this.claimedEnabled ? this.varianceNote : null,
                items: this.rows.filter((row) => !isEmptyRow(row)),
            };
        },

        consignmentDate() {
            const input = document.getElementById('consignment_date');

            return input ? input.value : new Date().toISOString().slice(0, 10);
        },

        csrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        },

        /**
         * Ambil draft dari server dan isi form.
         *
         * Dipakai dari tombol "Lanjutkan" di daftar draft. Kegagalannya
         * ditoleransi karena tiga alasan sekaligus: draft-nya sudah di-commit,
         * sudah di-buang, atau session-nya habis. Ketiganya berakhir sama --
         * Staff mengulang isi formnya, dan mengulang lebih baik daripada form
         * yang terisi setengah tanpa jejak.
         */
        async loadDraft(id) {
            try {
                const response = await fetch(`/inbound/consignment-in/drafts/${id}`, {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    return;
                }

                const { draft } = await response.json();

                this.draftId = draft.draft_id;
                this.consignorId = draft.consignor_id ?? '';
                this.source = draft.source ?? '';
                this.notes = draft.notes ?? '';
                this.claimedQty = draft.qty_claimed ?? '';
                this.claimedEnabled = draft.qty_claimed !== null && draft.qty_claimed !== undefined;
                this.varianceNote = draft.variance_note ?? '';
                this.savedAt = draft.saved_at;
                this.saveState = 'saved';
                this.rows = draft.items.length > 0
                    ? draft.items.map((row) => ({ ...DEFAULT_ROW(), ...row }))
                    : [DEFAULT_ROW()];

                this.syncDateInput(draft.consignment_date);
                this.clearLocalDraft();
            } catch (error) {
                this.saveState = 'idle';
            }
        },

        syncDateInput(value) {
            const input = document.getElementById('consignment_date');

            if (input && value) {
                input.value = value;
            }
        },

        /**
         * Cermin localStorage.
         *
         * Ini yang menutup celah 10 detik: kalau browser ditutup sebelum
         * autosave pertama selesai, isinya masih ada di mesin ini. limitationnya
         * jelas -- localStorage hanya ada di peramban dan peroleh ini, jadi ini
         * jaring pengaman, bukan tempat penyimpanan.
         */
        writeLocalDraft() {
            if (this.draftId === '' && this.isNothingToSave()) {
                return;
            }

            try {
                window.localStorage.setItem(LOCAL_DRAFT_KEY, JSON.stringify({
                    draftId: this.draftId,
                    savedAt: this.savedAt,
                    consignorId: this.consignorId,
                    source: this.source,
                    notes: this.notes,
                    claimedQty: this.claimedEnabled ? this.claimedQty : null,
                    claimedEnabled: this.claimedEnabled,
                    varianceNote: this.varianceNote,
                    rows: this.rows,
                }));
            } catch (error) {
                // Kuota penuh atau mode privat. Autosave server tetap jalan, jadi
                // kegagalan di sini tidak perlu mengganggu siapa pun.
            }
        },

        restoreLocalDraft() {
            let raw = null;

            try {
                raw = window.localStorage.getItem(LOCAL_DRAFT_KEY);
            } catch (error) {
                return;
            }

            if (!raw) {
                return;
            }

            try {
                const draft = JSON.parse(raw);

                this.draftId = draft.draftId ?? '';
                this.consignorId = draft.consignorId ?? '';
                this.source = draft.source ?? '';
                this.notes = draft.notes ?? '';
                this.claimedQty = draft.claimedQty ?? '';
                this.claimedEnabled = draft.claimedEnabled ?? false;
                this.varianceNote = draft.varianceNote ?? '';
                this.rows = Array.isArray(draft.rows) && draft.rows.length > 0
                    ? draft.rows.map((row) => ({ ...DEFAULT_ROW(), ...row }))
                    : [DEFAULT_ROW()];

                if (this.draftId === '') {
                    this.saveState = 'offline';
                } else {
                    this.savedAt = draft.savedAt ?? null;
                    this.saveState = 'saved';
                }
            } catch (error) {
                this.clearLocalDraft();
            }
        },

        clearLocalDraft() {
            try {
                window.localStorage.removeItem(LOCAL_DRAFT_KEY);
            } catch (error) {
                // Tidak ada yang bisa dilakukan, dan tidak berbahaya.
            }
        },

        /**
         * Harga list yang akan dipakai baris ini: yang diketik, atau harga
         * default produk. Null kalau produk belum dipilih, karena angka yang
         * ditampilkan harus berasal dari sesuatu yang benar-benar ada.
         */
        priceFor(row) {
            if (row.list_price !== '' && row.list_price !== null && row.list_price !== undefined) {
                return Number(row.list_price);
            }

            const price = this.productPrices[String(row.product_id)];

            return Number.isFinite(Number(price)) ? Number(price) : null;
        },

        /**
         * Nilai skema yang benar-benar berlaku untuk satu baris.
         *
         * Baris yang tidak mengisi apa pun mewarisi kontrak penitip. Perbedaannya
         * dengan `deviates()` di bawah adalah penting: yang di sini menyatakan
         * "nilai apa yang dipakai", sedangkan yang di sana menyatakan "apakah ini
         * pilihan Staff atau bukan". Baris yang mewarisi termasuk tidak menyimpang.
         */
        effective(row) {
            const consignor = this.consignor;
            const schemeType = row.scheme_type === '' ? (consignor?.schemeType ?? '') : row.scheme_type;
            const parameterField = PARAMETER_FIELD[schemeType];
            const inherited = parameterField ? consignor?.[parameterField] : null;
            const chosen = parameterField ? row[parameterField] : null;

            return {
                listPrice: this.priceFor(row),
                schemeType,
                schemeRate: schemeType === 'PERCENTAGE' ? (numberOrNull(chosen) ?? numberOrNull(inherited)) : null,
                schemeAmount: parameterField === 'scheme_amount' ? (numberOrNull(chosen) ?? numberOrNull(inherited)) : null,
                discountPolicy: row.discount_policy === ''
                    ? (consignor?.discountPolicy ?? 'STORE_BEARS')
                    : row.discount_policy,
            };
        },

        termsFor(row) {
            const effective = this.effective(row);

            return effective.listPrice === null ? null : calculateTerms(effective);
        },

        totals() {
            return this.rows.reduce((sum, row) => {
                const terms = this.termsFor(row);
                const qty = Number(row.qty) || 0;

                if (terms === null) {
                    return sum;
                }

                return {
                    storeFee: sum.storeFee + terms.storeFee * qty,
                    consignorRight: sum.consignorRight + terms.consignorRight * qty,
                    negativeMargin: sum.negativeMargin || terms.negativeMargin,
                };
            }, { storeFee: 0, consignorRight: 0, negativeMargin: false });
        },

        /**
         * Apakah baris ini sebuah keputusan Staff, bukan sekadar default.
         *
         * Baris yang produknya belum dipilih tidak dihitung sebagai apa pun: belum
         * ada isi yang bisa disimpulkan, dan baris seperti itu tidak akan lolos
         * validasi commit.
         */
        deviates(row) {
            const consignor = this.consignor;

            if (consignor === null) {
                return false;
            }

            if (row.list_price !== '' && row.list_price !== null && row.list_price !== undefined) {
                return true;
            }

            if (row.scheme_type === '') {
                return false;
            }

            if (row.scheme_type !== consignor.schemeType) {
                return true;
            }

            const parameterField = PARAMETER_FIELD[row.scheme_type];
            const chosen = numberOrNull(row[parameterField]);

            if (chosen === null) {
                return false;
            }

            return Math.abs(chosen - (numberOrNull(consignor[parameterField]) ?? NaN)) > 0.0001;
        },

        hasDeviation() {
            return this.rows.some((row) => this.deviates(row));
        },

        /**
         * PIN Owner hanya diminta kalau ada baris yang benar-benar menyimpang.
         * Owner tidak diminta untuk aksinya sendiri.
         */
        needsOwnerPin() {
            return !this.isOwner && this.hasDeviation();
        },

        /**
         * Submit tanpa persetujuan komponen ini, dan POST yang sungguhan
         * dilakukan lewat `submit()` yang melewati event -- supaya dialog tidak
         * terbuka dua kali untuk satu klik, dan supaya POST kedua tidak memunculkan
         * dialog lagi.
         */
        async onSubmit(event) {
            if (this.submitting) {
                return;
            }

            if (!(await this.requestPinAndSubmit())) {
                return;
            }

            // Cermin lokal sudah tidak ada gunanya setelah commit: dokumennya
            // berstatus terkunci, dan sisa yang tertinggal hanya akan
            // menawarkan pemulihan yang membingungkan di form yang baru saja kosong.
            this.clearLocalDraft();

            this.submitting = true;
            event.target.submit();
        },

        /**
         * Minta PIN sekali, lalu kirim form dengan token-nya.
         *
         * Token yang sudah ada dipakai ulang supaya validasi yang gagal tidak
         * mengulang dialog -- tokennya sah lima menit dan memang tidak sekali
         * pakai, itu keputusan Phase A.
         */
        async requestPinAndSubmit() {
            if (!this.needsOwnerPin() || this.pinToken !== '') {
                return true;
            }

            const grant = await window.pin.request({
                context: this.pinContext,
                title: 'PIN Owner diperlukan',
                description: 'Ada baris yang memakai harga atau skema di luar default penitip.',
                confirmText: 'Simpan & Lanjutkan',
            });

            if (grant === null) {
                // Batal bukan kesalahan: tidak ada yang terkirim, form tetap
                // utuh, dan kasir masih bisa menyunting barisnya.
                this.pinError = '';

                return false;
            }

            this.pinToken = grant.token;
            this.pinError = '';

            return true;
        },
    };
}

/** Angka yang bisa dihitung, atau null. String kosong bukan nol. */
function numberOrNull(value) {
    if (value === '' || value === null || value === undefined) {
        return null;
    }

    const number = Number(value);

    return Number.isFinite(number) ? number : null;
}
