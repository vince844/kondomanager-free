<?php

namespace App\Services\Installer;

use App\Settings\GeneralSettings;

/**
 * Nome dell'applicazione e lingua scelti all'installazione. Il wizard li fa arrivare a
 * `GeneralSettings` dal file di progresso tramite il listener `MigrationsEnded` (perché li
 * raccoglie PRIMA che la tabella delle impostazioni esista); un'installazione senza wizard li
 * conosce già dopo le migrazioni e li scrive direttamente. Stesso approdo, `GeneralSettings`,
 * che è ciò che `SetLocaleMiddleware`/`SetAppNameMiddleware` leggono a ogni richiesta.
 */
class ScriviImpostazioni
{
    public function esegui(?string $appName, ?string $locale): void
    {
        $settings = app(GeneralSettings::class);
        $cambiato = false;

        if (filled($appName)) {
            $settings->app_name = $appName;
            $cambiato = true;
        }

        if (filled($locale) && in_array($locale, array_keys((array) config('installer.available_locales', [])), true)) {
            $settings->language = $locale;
            $cambiato = true;
        }

        if ($cambiato) {
            $settings->save();
        }
    }
}
