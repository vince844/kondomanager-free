<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailLog extends Model
{
    protected $fillable = [
        'recipient', 
        'subject', 
        'mailer', 
        'status', 
        'error_message', 
        'sent_at'
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];
}
