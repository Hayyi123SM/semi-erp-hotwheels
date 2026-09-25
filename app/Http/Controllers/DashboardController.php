<?php

namespace App\Http\Controllers;

class DashboardController extends Controller
{
    public function index()
    {
        return $this->page('pages.dashboard', [
            'activities' => \App\Support\MockData::recentActivities(),
            'racks' => \App\Support\MockData::racks(),
            'consignors' => \App\Support\MockData::consignors(),
            'quarantineOpen' => 3,
            'quarantineAging' => 1,
        ], 'Dashboard');
    }
}