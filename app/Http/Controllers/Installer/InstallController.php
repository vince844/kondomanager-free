<?php

namespace App\Http\Controllers\Installer;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;

class InstallController extends Controller
{
    /**
     * Start or resume the installation wizard.
     */
    public function start()
    {
        $progressFile = config('installer.options.progress_file');

        if (File::exists($progressFile)) {
            $progress = json_decode(File::get($progressFile), true);

            if (!empty($progress['current_step'])) {
                return redirect()->route('install.step', $progress['current_step']);
            }
        }

        $initialData = [
            'current_step' => 'welcome',
            'data' => [],
        ];

        File::put($progressFile, json_encode($initialData, JSON_PRETTY_PRINT));

        return redirect()->route('install.step', 'welcome');
    }
}
