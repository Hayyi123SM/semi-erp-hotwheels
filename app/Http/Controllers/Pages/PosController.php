<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Support\MockData;

class PosController extends Controller
{
    public function kasir()
    {
        return $this->page('pages.pos.kasir', [
            'catalog' => MockData::catalog(),
            'activeBank' => MockData::activeBankItems(),
        ], 'Kasir');
    }

    public function riwayat()
    {
        return $this->page('pages.pos.riwayat-transaksi', ['transactions' => MockData::transactions()], 'Riwayat Transaksi');
    }

    public function shiftKasir()
    {
        return $this->page('pages.pos.shift-kasir', [], 'Shift Kasir');
    }
}