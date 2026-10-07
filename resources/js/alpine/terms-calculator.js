/**
 * Pratinjau angka skema di layar, sebelum commit.
 *
 * Aritmetika ini sengaja menggandakan `TermsCalculator` di PHP. Yang menentukan
 * angka yang tersimpan adalah server, dan server menghitung ulang apa pun yang
 * dikirim form; ini hanya supaya kasir melihat angkanya sebelum menekan Commit,
 * dan angka di sini tidak pernah jadi sumber tagihan.
 *
 * Karena ada dua salinan, keduanya harus diuji dengan vektor yang sama persis,
 * dan vektornya diletakkan di satu file: `tests/fixtures/terms-vectors.json`,
 * yang dibaca `terms-calculator.test.js` dan `TermsCalculatorTest.php`. Dulu
 * angkanya ditulis dua kali, dan perbedaan harfalah di kedua file itu tetap hijau
 * di kedua test. Kalau satu sisi bergeser, kasir melihat angka yang berbeda dari
 * yang tersimpan -- dan yang tersimpan itulah yang jadi tagihan.
 */

/** Pembulatan setengah ke atas, sama seperti `PHP_ROUND_HALF_UP`. */
function roundHalfUp(value) {
    return Math.floor(value + 0.5);
}

/**
 * @param {object} row
 * @param {number} row.listPrice
 * @param {'PERCENTAGE'|'NETT'|'FLAT'} row.schemeType
 * @param {number|null} row.schemeRate
 * @param {number|null} row.schemeAmount
 * @param {'STORE_BEARS'|'SHARED'} row.discountPolicy
 * @param {number} [row.discount]
 * @returns {{listPrice: number, discount: number, sellPrice: number, storeFee: number,
 *            consignorRight: number, negativeMargin: boolean}|null}
 *          null saat input belum cukup untuk dihitung
 */
export function calculateTerms(row) {
    const listPrice = Number(row?.listPrice);
    const scheme = row?.schemeType;
    const discount = Number(row?.discount ?? 0);

    if (!Number.isFinite(listPrice) || listPrice < 0) {
        return null;
    }

    if (!Number.isFinite(discount) || discount < 0 || discount > listPrice) {
        return null;
    }

    if (scheme !== 'PERCENTAGE' && scheme !== 'NETT' && scheme !== 'FLAT') {
        return null;
    }

    const sellPrice = listPrice - discount;
    const schemeFee = schemeFeeFor(sellPrice, scheme, row.schemeRate, row.schemeAmount);

    if (schemeFee === null) {
        return null;
    }

    // BR-04: diskon ditanggung seluruhnya oleh toko, atau dibagi berdua. Nama
    // enum di server adalah `STORE_BEARS`; nilai di luar itu diperlakukan
    // sebagai `SHARED` supaya pratinjau tidak diam-diam menampilkan angka yang
    // berbeda dari yang akan dihitung server untuk nilai tak dikenal.
    const storeFee = row.discountPolicy === 'STORE_BEARS' ? schemeFee - discount : schemeFee;

    return {
        listPrice,
        discount,
        sellPrice,
        storeFee,
        consignorRight: sellPrice - storeFee,
        negativeMargin: storeFee < 0,
    };
}

/**
 * @returns {number|null} null saat parameter skema tidak ada atau tidak positif
 */
function schemeFeeFor(sellPrice, scheme, rate, amount) {
    if (scheme === 'PERCENTAGE') {
        const parsed = Number(rate);

        if (!Number.isFinite(parsed) || parsed <= 0 || parsed > 100) {
            return null;
        }

        return roundHalfUp(sellPrice * (parsed / 100));
    }

    const parsed = Number(amount);

    if (!Number.isFinite(parsed) || parsed <= 0) {
        return null;
    }

    return scheme === 'NETT' ? sellPrice - parsed : parsed;
}
