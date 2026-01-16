<?php

namespace App\Notifications;

use App\Settings\GeneralSettings;
use Illuminate\Notifications\Notification;

/**
 * Base class for notifications that need to be localized.
 *
 * This class automatically handles the locale for queued notifications
 * by storing it at the time the notification is dispatched. This ensures
 * that queued jobs will use the correct language even though middleware
 * and request context are not available when the job runs.
 */
abstract class LocalizedNotification extends Notification
{
    /**
     * The locale/language code to be used for this notification.
     *
     * @var string
     */
    public $locale;

    /**
     * Constructor.
     *
     * Determines the current application language to use for the notification.
     * Tries to load it from the GeneralSettings (database). If unavailable,
     * falls back to the application's fallback locale.
     *
     * This value is stored in the notification instance so it persists
     * when queued and executed by a worker.
     */
    public function __construct()
    {
        try {
            // Load language from settings or fallback to default
            $this->locale = app(GeneralSettings::class)->language ?? config('app.fallback_locale');
        } catch (\Throwable $e) {
            // In case the DB is unavailable or some error occurs
            $this->locale = config('app.fallback_locale');
        }
    }

    /**
     * Get the locale for the notification.
     *
     * Laravel automatically calls this method when sending notifications,
     * including queued notifications. The returned locale is used by
     * the translation functions (__()) inside the notification.
     *
     * @param  mixed  $notifiable
     * @return string
     */
    public function locale($notifiable)
    {
        return $this->locale;
    }
}
