<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Support\MockData;

class ReportController extends Controller
{
    public function settlement()
    {
        return $this->page('pages.reports.settlement', ['consignors' => MockData::consignors()], 'Settlement Consignor');
    }

    public function margin()
    {
        return $this->page('pages.reports.margin', ['consignors' => MockData::consignors()], 'Profit Margin');
    }

    public function laporan()
    {
        return $this->page('pages.reports.laporan', ['transactions' => MockData::transactions()], 'Laporan Penjualan & Stok');
    }

    public function auditLog()
    {
        return $this->page('pages.reports.audit-log', [], 'Audit Log');
    }
}