<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;

class SettingController extends Controller
{
    public function pengguna()
    {
        return $this->page('pages.settings.pengguna', [], 'Pengguna & Role');
    }

    public function perangkat()
    {
        return $this->page('pages.settings.perangkat', [], 'Perangkat');
    }

    public function waTemplate()
    {
        return $this->page('pages.settings.wa-template', [], 'Template WhatsApp');
    }

    public function parameter()
    {
        return $this->page('pages.settings.parameter', [], 'Parameter Aplikasi');
    }
}