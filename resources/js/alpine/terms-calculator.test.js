import { describe, it, expect } from 'vitest';

import { calculateTerms } from './terms-calculator.js';

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Sisi browser dari vektor bersama.
 *
 * Dua salinan aritmetika hanya bisa dipercaya kalau keduanya diuji dengan angka
 * yang sama. Server yang menentukan angka tersimpan; ini yang ditampilkan ke
 * kasir. Kalau hanya salah satu yang diuji, sisi yang lain bisa melenceng
 * tanpa terdeteksi -- dan yang salah justru yang dilihat orang.
 *
 * Angkanya dibaca dari `tests/fixtures/terms-vectors.json` yang juga dipakai
 * `TermsCalculatorTest.php`, bukan ditulis di sini. Dulu vektor yang sama ditulis
 * dua kali, dan literal yang berbeda di kedua file itu tetap hijau di kedua test.
 *
 * File-nya dibaca lewat `fs` daripada di-import: Vite di sini menolak import
 * atribut JSON, dan memindahkan fixture ke `resources/js` supaya bisa di-import
 * akan menyimpannya dari test PHP yang juga membacanya. Jalur diselesaikan dari
 * root proyek karena `import.meta.url` di Vitest bukan URL `file:`.
 */
const vectors = JSON.parse(
    readFileSync(resolve(process.cwd(), 'tests/fixtures/terms-vectors.json'), 'utf8'),
);

describe('calculateTerms', () => {
    describe('the shared vectors', () => {
        it.each(vectors.vectors)('agrees with the server on: $name', (vector) => {
            const terms = calculateTerms({
                listPrice: vector.listPrice,
                schemeType: vector.schemeType,
                schemeRate: vector.schemeRate ?? null,
                schemeAmount: vector.schemeAmount ?? null,
                discountPolicy: vector.discountPolicy,
                discount: vector.discount,
            });

            expect(terms.storeFee).toBe(vector.storeFee);
            expect(terms.consignorRight).toBe(vector.consignorRight);
            expect(terms.negativeMargin).toBe(vector.negativeMargin);
        });

        it.each(vectors.vectors)('never loses a rupiah on: $name', (vector) => {
            const terms = calculateTerms({
                listPrice: vector.listPrice,
                schemeType: vector.schemeType,
                schemeRate: vector.schemeRate ?? null,
                schemeAmount: vector.schemeAmount ?? null,
                discountPolicy: vector.discountPolicy,
                discount: vector.discount,
            });

            expect(terms.storeFee + terms.consignorRight).toBe(terms.sellPrice);
        });
    });

    describe('the three documented examples', () => {
        it('percentage: 20% of Rp50.000', () => {
            expect(
                calculateTerms({ listPrice: 50_000, schemeType: 'PERCENTAGE', schemeRate: 20, discountPolicy: 'STORE_BEARS' }),
            ).toMatchObject({ storeFee: 10_000, consignorRight: 40_000 });
        });

        it('nett: Rp38.000 of Rp50.000', () => {
            expect(
                calculateTerms({ listPrice: 50_000, schemeType: 'NETT', schemeAmount: 38_000, discountPolicy: 'STORE_BEARS' }),
            ).toMatchObject({ storeFee: 12_000, consignorRight: 38_000 });
        });

        it('flat: Rp8.000 of Rp50.000', () => {
            expect(
                calculateTerms({ listPrice: 50_000, schemeType: 'FLAT', schemeAmount: 8_000, discountPolicy: 'STORE_BEARS' }),
            ).toMatchObject({ storeFee: 8_000, consignorRight: 42_000 });
        });
    });

    it('always splits the selling price down the middle (BR-05)', () => {
        const cases = [
            { schemeType: 'PERCENTAGE', schemeRate: 20, schemeAmount: null },
            { schemeType: 'NETT', schemeRate: null, schemeAmount: 38_000 },
            { schemeType: 'FLAT', schemeRate: null, schemeAmount: 8_000 },
        ];

        for (const discountPolicy of ['STORE_BEARS', 'SHARED']) {
            for (const discount of [0, 1_000, 5_000, 12_345]) {
                for (const scheme of cases) {
                    const terms = calculateTerms({ listPrice: 50_000, ...scheme, discountPolicy, discount });

                    expect(terms.storeFee + terms.consignorRight).toBe(terms.sellPrice);
                }
            }
        }
    });

    it('leaves the whole discount on the store when STORE_BEARS', () => {
        expect(
            calculateTerms({ listPrice: 50_000, schemeType: 'FLAT', schemeAmount: 10_000, discountPolicy: 'STORE_BEARS', discount: 5_000 }),
        ).toMatchObject({ storeFee: 5_000, consignorRight: 40_000 });
    });

    it('splits the discount between both sides when SHARED', () => {
        expect(
            calculateTerms({ listPrice: 50_000, schemeType: 'FLAT', schemeAmount: 10_000, discountPolicy: 'SHARED', discount: 5_000 }),
        ).toMatchObject({ storeFee: 10_000, consignorRight: 35_000 });
    });

    it('rounds the percentage fee up before multiplying by qty', () => {
        // Rp999 x 15% = Rp149,85 -> Rp150 per unit, lalu dikali 3. Bulatkan
        // belakangan akan menghasilkan Rp300 -- dan selisih Rp150 itu jadi
        // tagihan yang tidak ada dasarnya.
        const terms = calculateTerms({ listPrice: 999, schemeType: 'PERCENTAGE', schemeRate: 15, discountPolicy: 'STORE_BEARS' });

        expect(terms.storeFee).toBe(150);
        expect(terms.storeFee * 3).toBe(450);
        expect(terms.consignorRight * 3).toBe(2_547);
    });

    it('rounds a half rupiah up rather than to even', () => {
        // 2,5 harus menjadi 3. Pembulatan bankir akan mengubahnya menjadi 2, dan
        // itulah yang membuat selisih tak dijelaskan muncul di satu skema saja.
        expect(
            calculateTerms({ listPrice: 10, schemeType: 'PERCENTAGE', schemeRate: 25, discountPolicy: 'STORE_BEARS' }).storeFee,
        ).toBe(3);
    });

    it('flags a store fee that went negative rather than hiding it', () => {
        const terms = calculateTerms({ listPrice: 50_000, schemeType: 'FLAT', schemeAmount: 8_000, discountPolicy: 'STORE_BEARS', discount: 45_000 });

        expect(terms.negativeMargin).toBe(true);
        expect(terms.storeFee).toBe(-37_000);
    });

    it('does not flag a split that is merely thin', () => {
        expect(
            calculateTerms({ listPrice: 50_000, schemeType: 'FLAT', schemeAmount: 8_000, discountPolicy: 'STORE_BEARS', discount: 5_000 })
                .negativeMargin,
        ).toBe(false);
    });

    describe('returns nothing rather than a number it cannot stand behind', () => {
        it.each([
            ['no list price', { schemeType: 'FLAT', schemeAmount: 8_000 }],
            ['a negative list price', { listPrice: -1, schemeType: 'FLAT', schemeAmount: 8_000 }],
            ['a list price that is not a number', { listPrice: 'abc', schemeType: 'FLAT', schemeAmount: 8_000 }],
            ['an unknown scheme', { listPrice: 50_000, schemeType: 'SOMETHING_ELSE', schemeAmount: 8_000 }],
            ['a missing scheme', { listPrice: 50_000 }],
            ['a percentage with no rate', { listPrice: 50_000, schemeType: 'PERCENTAGE' }],
            ['a percentage over 100', { listPrice: 50_000, schemeType: 'PERCENTAGE', schemeRate: 100.5 }],
            ['a percentage of zero', { listPrice: 50_000, schemeType: 'PERCENTAGE', schemeRate: 0 }],
            ['a flat fee of zero', { listPrice: 50_000, schemeType: 'FLAT', schemeAmount: 0 }],
            ['a discount above the list price', { listPrice: 50_000, schemeType: 'FLAT', schemeAmount: 8_000, discount: 50_001 }],
        ])('%s', (_label, row) => {
            // A number shown for a half-filled row is worse than no number: the
            // cashier reads it, and nothing on the form says it is provisional.
            expect(calculateTerms(row)).toBeNull();
        });
    });

    it('treats an unknown discount policy as SHARED, which is the one that needs no correction', () => {
        // The server has exactly two policies, so this cannot happen there. Here
        // it can, and a third default -- say silently reading it as STORE_BEARS --
        // would show a number the server never produces.
        const terms = calculateTerms({
            listPrice: 50_000,
            schemeType: 'FLAT',
            schemeAmount: 10_000,
            discountPolicy: 'TYPO_HERE',
            discount: 5_000,
        });

        expect(terms.storeFee).toBe(10_000);
    });
});
