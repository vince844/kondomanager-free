<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Providers\AppServiceProvider;
use App\Providers\AuditServiceProvider;
use App\Providers\BackupServiceProvider;
use App\Providers\MailConfigServiceProvider;
use App\Providers\RegistroModificheServiceProvider;
use App\Providers\UpgradePatchServiceProvider;

return [
    AppServiceProvider::class,
    AuditServiceProvider::class,
    BackupServiceProvider::class,
    MailConfigServiceProvider::class,
    RegistroModificheServiceProvider::class,
    UpgradePatchServiceProvider::class,
];
