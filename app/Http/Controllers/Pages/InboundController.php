<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Support\MockData;

class InboundController extends Controller
{
    public function stockInPribadi()
    {
        return $this->page('pages.inbound.stock-in-pribadi', ['consignors' => MockData::consignors()], 'Stock In Pribadi');
    }

    public function consignmentIn()
    {
        return $this->page('pages.inbound.consignment-in', ['consignors' => MockData::consignors()], 'Consignment In');
    }

    public function cetakLabel()
    {
        return $this->page('pages.inbound.cetak-label', ['catalog' => MockData::catalog()], 'Cetak Label');
    }
}