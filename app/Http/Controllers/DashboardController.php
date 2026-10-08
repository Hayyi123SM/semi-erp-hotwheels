<?php

namespace App\Http\Controllers;

use App\Services\Report\DashboardService;
use App\Services\Report\StockReportService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $viewer = $request->user();
        $dashboard = new DashboardService;

        $opnames = (new StockReportService)->opnames()['sessions'];

        return $this->page('pages.dashboard', [
            'today' => now()->translatedFormat('l, d F Y'),
            'isOwner' => $viewer?->isOwner() ?? false,
            'cards' => $dashboard->cards($viewer),
            'warnings' => $dashboard->warnings(),
            'racks' => $dashboard->rackCapacity(),
            'consignors' => $viewer?->isOwner() ? $dashboard->topConsignors() : [],
            'opnames' => $opnames,
            'activities' => $dashboard->activities(),
        ], 'Dashboard');
    }
}
