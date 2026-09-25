<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Support\MockData;

class MasterController extends Controller
{
    public function penitip()
    {
        return $this->page('pages.master.penitip', ['consignors' => MockData::consignors()], 'Data Penitip');
    }

    public function katalogProduk()
    {
        return $this->page('pages.master.katalog-produk', ['products' => MockData::catalog()], 'Katalog Produk');
    }

    public function lokasiRak()
    {
        return $this->page('pages.master.lokasi-rak', ['racks' => MockData::racks()], 'Lokasi Rak');
    }
}