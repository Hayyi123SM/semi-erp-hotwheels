<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Support\MockData;

class InventoryController extends Controller
{
    public function liveStock()
    {
        return $this->page('pages.inventory.live-stock', ['catalog' => MockData::catalog()], 'Live Stock');
    }

    public function karantina()
    {
        return $this->page('pages.inventory.karantina', ['cases' => MockData::quarantineCases()], 'Karantina');
    }

    public function stockOpname()
    {
        return $this->page('pages.inventory.stok-opname', [], 'Stok Opname');
    }

    public function returRtv()
    {
        return $this->page('pages.inventory.retur-rtv', ['consignors' => MockData::consignors()], 'Retur / RTV');
    }
}