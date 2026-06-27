<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

class AddCommentModerationToGeneralSettings extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.force_comment_moderation', false);
    }
};
