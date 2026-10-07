<?php

namespace App\Http\Controllers;

use App\Support\MockData;

class DashboardController extends Controller
{
    public function index()
    {
        return $this->page('pages.dashboard', [
            'activities' => MockData::recentActivities(),
            'racks' => MockData::racks(),
            'consignors' => MockData::consignors(),
            'quarantineOpen' => 3,
            'quarantineAging' => 1,
        ], 'Dashboard');
    }
}
