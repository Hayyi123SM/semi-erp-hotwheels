/**
 * Membawa satu nilai dari pemanggil ke komponen Alpine di dalam `<template>`.
 *
 * `notify.templateModal` membaca `template.innerHTML` dan membangun pohon Alpine
 * baru di `didOpen`, jadi nilai apa pun yang dibawa harus sudah ada pada template
 * itu sendiri. Alternatifnya adalah `dispatchEvent`, tapi itu balapan: trees
 * dibangun setelah pemanggil melanjutkan, sehingga peristiwa yang dikirim saat itu
 * hilang tanpa ada yang mendengar.
 *
 * `<template>` sudah ada di DOM sebelum Alpine berjalan, jadi `dataset` di atasnya
 * bertahan, dan component `init()` membacanya tepat ketika pohonnya ada.
 *
 * @param {string} templateId
 * @param {string} key
 * @param {string} value
 */
export function seedTemplate(templateId, key, value) {
    const template = document.getElementById(templateId);

    if (template === null) {
        return;
    }

    template.dataset[key] = value;
}

/**
 * Baca nilai yang ditanam `seedTemplate`, sekali saja lalu dibersihkan.
 *
 * Nilai lama harus dihapus setelah dibaca. Kalau tidak, kode yang gagal dipindai
 * lima menit lalu akan muncul lagi setiap kali picker dibuka tanpa pencarian baru,
 * dan kasir akan mengira barang itu masih satu-satunya kandidat.
 *
 * @param {string} templateId
 * @param {string} key
 * @returns {string}
 */
export function readTemplateSeed(templateId, key) {
    const template = document.getElementById(templateId);

    if (template === null) {
        return "";
    }

    const value = template.dataset[key] ?? "";
    delete template.dataset[key];

    return value;
}
