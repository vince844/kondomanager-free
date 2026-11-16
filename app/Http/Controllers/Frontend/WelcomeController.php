<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Settings\GeneralSettings;
use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(GeneralSettings $settings): Response
    {
        return Inertia::render('Welcome',[
            'can_register' => $settings->user_frontend_registration,
        ]);
    }
}
