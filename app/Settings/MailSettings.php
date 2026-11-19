<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class MailSettings extends Settings
{

    public bool $mail_enabled = false;
    public ?string $mail_host = null;
    public ?int $mail_port = 587;
    public ?string $mail_username = null;
    public ?string $mail_password = null;
    public ?string $mail_encryption = 'tls';
    public ?string $mail_from_address = null;
    public string $mail_from_name = 'KondoManager';
    public string $mail_driver = 'smtp';       // 'smtp' | 'sendmail'

    public static function group(): string
    {
        return 'mail';
    }
}