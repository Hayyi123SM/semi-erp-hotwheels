/**
 * Form Stock In Pribadi dengan popup pencarian produk.
 *
 * Mengelola daftar item terpilih (cart-like), membuka picker produk,
 * menangani duplikasi (ignore + highlight), serta scan barcode.
 *
 * Picker memakai pencarian level produk (endpoint `inbound.produk.cari`),
 * bukan level lot seperti picker kasir: form ini diisi sebelum barang
 * diterima, jadi produk yang dicari belum tentu punya satu lot pun, dan
 * `products` tidak punya kolom `sku` -- `casting_code` yang dipakai sebagai
 * identitas tampilan.
 */

import { rupiah } from "../format";

export function stockInPribadiForm(options = {}) {
    const lookupUrl = options.lookupUrl ?? "";
    const racks = Array.isArray(options.racks) ? options.racks : [];
    const cardConditions = options.cardConditions ?? {};
    const blisterConditions = options.blisterConditions ?? {};
    const initialItems = Array.isArray(options.initialItems)
        ? options.initialItems
        : [];

    return {
        items: [],
        pickerOpen: false,
        scanCode: "",
        scanError: "",
        lookupUrl,
        racks,
        cardConditions,
        blisterConditions,
        initialItems,
        highlightedIndex: -1,
        highlightTimeout: null,

        init() {
            this.items = initialItems.map((item) => ({
                id: item.id ?? this.generateId(),
                product_id: item.product_id,
                casting_code: item.casting_code ?? "",
                name: item.name ?? "",
                series_code: item.series_code ?? "",
                qty: item.qty ?? 1,
                cost_price: item.cost_price ?? "",
                card_condition: item.card_condition ?? "MINT",
                blister_condition: item.blister_condition ?? "CLEAR",
                rack_id: item.rack_id ?? "",
            }));

            this.scanCode = "";
            this.scanError = "";
            this.highlightedIndex = -1;

            this.$nextTick(() => {
                this.$refs?.scanInput?.focus?.();
            });
        },

        generateId() {
            if (
                typeof crypto !== "undefined" &&
                typeof crypto.randomUUID === "function"
            ) {
                return crypto.randomUUID();
            }

            return `sip-${Date.now().toString(36)}-${Math.random()
                .toString(36)
                .slice(2, 10)}`;
        },

        totalQty() {
            return this.items.reduce(
                (sum, row) => sum + (Number(row.qty) || 0),
                0,
            );
        },

        totalHpp() {
            return this.items.reduce(
                (sum, row) =>
                    sum +
                    (Number(row.qty) || 0) * (Number(row.cost_price) || 0),
                0,
            );
        },

        formatRupiah(value) {
            const num = Number(value) || 0;
            return rupiah(num);
        },

        openPicker() {
            this.pickerOpen = true;
            this.scanError = "";

            // Fokus lewat id, bukan `$refs`: input pencarian berada di dalam
            // `x-data="productPicker(...)"`, jadi ref-nya terdaftar pada root
            // komponen anak dan tidak terlihat dari scope induk ini.
            this.$nextTick(() => {
                document
                    .getElementById("stock-in-picker-search")
                    ?.focus?.();
            });
        },

        closePicker() {
            this.pickerOpen = false;
        },

        focusScan() {
            this.scanError = "";
            this.$nextTick(() => {
                this.$refs?.scanInput?.focus?.();
            });
        },

        clearScanError() {
            this.scanError = "";
        },

        onScanKeydown(event) {
            if (event.key === "Enter") {
                event.preventDefault();
                this.addByBarcode(this.scanCode.trim());
            }
        },

        async addByBarcode(code) {
            const barcode = String(code ?? "").trim();

            if (barcode === "") {
                return;
            }

            this.scanError = "";

            try {
                const response = await fetch(this.lookupUrl, {
                    method: "POST",
                    headers: {
                        Accept: "application/json",
                        "X-CSRF-TOKEN":
                            document
                                .querySelector('meta[name="csrf-token"]')
                                ?.content ?? "",
                    },
                    body: new URLSearchParams({ barcode }),
                });

                if (!response.ok) {
                    throw new Error("Barcode tidak ditemukan");
                }

                const data = await response.json();
                const items = Array.isArray(data.items) ? data.items : [];

                if (items.length === 0) {
                    this.scanError = "Barcode tidak ditemukan";
                    return;
                }

                if (items.length > 1) {
                    // Lebih dari satu produk cocok: memilih sendiri diam-diam
                    // bisa menerima barang yang salah, dan baris yang salah di
                    // sini berarti HPP dan label yang salah pula.
                    this.scanError =
                        "Barcode cocok dengan beberapa produk. Cari lewat tombol Tambah Produk.";
                    return;
                }

                const product = items[0];

                if (!product || !product.product_id) {
                    this.scanError = "Barcode tidak ditemukan";
                    return;
                }

                this.addProduct(product);
                this.scanCode = "";
                this.scanError = "";
            } catch (error) {
                this.scanError = "Barcode tidak ditemukan";
            }
        },

        onProductPicked(event) {
            const detail = event.detail ?? {};
            const productData = detail.item ?? detail;

            if (!productData || !productData.product_id) {
                return;
            }

            const product = {
                product_id: productData.product_id,
                casting_code: productData.casting_code ?? "",
                name: productData.name ?? "",
                series_code: productData.series_code ?? "",
            };

            this.addProduct(product);

            // Tutup popup setelah pilihan diproses -- termasuk saat duplikat,
            // supaya pengguna langsung melihat barisnya (atau highlight baris
            // yang sudah ada) di belakang drawer, bukan layar kosong.
            this.closePicker();
        },

        addProduct(product) {
            if (!product || !product.product_id) {
                return;
            }

            const existingIndex = this.items.findIndex(
                (item) => item.product_id === product.product_id,
            );

            if (existingIndex !== -1) {
                this.highlightExisting(existingIndex);

                if (window.notify && typeof window.notify.warning === "function") {
                    window.notify.warning(
                        "Produk ini sudah ada di daftar. Tidak menambahkan baris baru.",
                    );
                } else if (
                    window.notify &&
                    typeof window.notify.info === "function"
                ) {
                    window.notify.info(
                        "Produk ini sudah ada di daftar. Tidak menambahkan baris baru.",
                    );
                } else {
                    alert(
                        "Produk ini sudah ada di daftar. Tidak menambahkan baris baru.",
                    );
                }

                return;
            }

            this.items.push({
                id: this.generateId(),
                product_id: product.product_id,
                casting_code: product.casting_code ?? "",
                name: product.name ?? "",
                series_code: product.series_code ?? "",
                qty: 1,
                cost_price: "",
                card_condition: "MINT",
                blister_condition: "CLEAR",
                rack_id: "",
            });
        },

        highlightExisting(index) {
            if (index < 0 || index >= this.items.length) {
                return;
            }

            this.highlightedIndex = index;

            // `data-row-idx`, bukan `x-ref` dinamis: Alpine membaca `x-ref`
            // sebagai string literal sehingga `:x-ref="'row-' + idx"` tidak
            // pernah mendaftarkan ref bernama `row-0`, `row-1`, dst.
            this.$nextTick(() => {
                const row = this.$root.querySelector(
                    `[data-row-idx="${index}"]`,
                );
                if (row && typeof row.scrollIntoView === "function") {
                    row.scrollIntoView({ block: "nearest", behavior: "smooth" });
                }
            });

            if (this.highlightTimeout) {
                clearTimeout(this.highlightTimeout);
            }

            this.highlightTimeout = setTimeout(() => {
                this.highlightedIndex = -1;
            }, 1500);
        },

        remove(index) {
            this.items.splice(index, 1);
            if (this.highlightedIndex === index) {
                this.highlightedIndex = -1;
            }
        },

        getConditionOptions(conditions) {
            if (Array.isArray(conditions)) {
                return conditions;
            }

            if (conditions && typeof conditions === "object") {
                return Object.entries(conditions).map(([value, label]) => ({
                    value,
                    label,
                }));
            }

            return [];
        },
    };
}
