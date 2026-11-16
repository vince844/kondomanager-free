<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Spatie\LaravelSettings\Migrations\SettingsMigration;

class AddCommentModerationToGeneralSettings extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.force_comment_moderation', false);
    }
};
