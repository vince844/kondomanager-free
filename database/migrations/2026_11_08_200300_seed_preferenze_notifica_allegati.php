<?php

use App\Enums\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** preferenza nuova => preferenza dei commenti da cui eredita lo stato. */
    private const EREDITA_DA = [
        NotificationType::NEW_ATTACHMENT->value              => NotificationType::NEW_COMMENT->value,
        NotificationType::ATTACHMENT_UNDER_MODERATION->value => NotificationType::COMMENT_UNDER_MODERATION->value,
        NotificationType::ATTACHMENT_APPROVED->value         => NotificationType::COMMENT_APPROVED->value,
        NotificationType::ATTACHMENT_DELETED->value          => NotificationType::COMMENT_DELETED->value,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_preferences')) {
            return;
        }

        foreach (self::EREDITA_DA as $nuova => $sorella) {
            $giaPresenti = DB::table('notification_preferences')
                ->where('type', $nuova)
                ->pluck('user_id')
                ->all();

            $daCreare = DB::table('notification_preferences')
                ->where('type', $sorella)
                ->when($giaPresenti !== [], fn ($q) => $q->whereNotIn('user_id', $giaPresenti))
                ->get(['user_id', 'enabled']);

            if ($daCreare->isEmpty()) {
                continue;
            }

            DB::table('notification_preferences')->insert(
                $daCreare->map(fn ($r) => [
                    'user_id'    => $r->user_id,
                    'type'       => $nuova,
                    'enabled'    => $r->enabled,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all()
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_preferences')) {
            return;
        }

        DB::table('notification_preferences')
            ->whereIn('type', array_keys(self::EREDITA_DA))
            ->delete();
    }
};

