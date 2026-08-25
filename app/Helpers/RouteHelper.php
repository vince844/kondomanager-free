<?php

namespace App\Helpers;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;

class RouteHelper
{
    /**
     * Get the route prefix for a given user based on their role or permissions.
     *
     * If the user has the role of 'amministratore' or 'collaboratore', or has the
     * 'Accesso pannello amministratore' permission, the method returns 'admin'.
     * Otherwise, it defaults to 'user'.
     *
     * @param mixed $notifiable Typically a User instance.
     * @return string Either 'admin' or 'user' as the route prefix.
     */
    public static function getRoutePrefixForUser(mixed $notifiable): string
    {
        if (
            $notifiable instanceof User &&
            (
                $notifiable->hasRole([
                    Role::AMMINISTRATORE->value,
                    Role::COLLABORATORE->value
                ]) ||
                $notifiable->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value)
            )
        ) {
            return 'admin';
        }

        return 'user';
    }

    /**
     * L'indirizzo dell'archivio documenti **come lo vede chi riceve la notifica**.
     *
     * ## Perché non basta il prefisso
     *
     * Le tre notifiche sui documenti costruivano il collegamento concatenando a mano il prefisso
     * a un percorso — `url("/{$prefix}/categorie-documenti/")` — e il percorso era diverso da
     * quello che esiste davvero: **tre indirizzi su ventiquattro** non rispondevano, e tutti e
     * tre stavano qui. L'amministratore veniva mandato su `/admin/categorie-documenti`, che non è
     * mai esistito (di là è `/admin/categorie`); il condòmino su `/user/documenti`, che fino alla
     * beta.62 rispondeva **500** perché la rotta era registrata verso un metodo assente.
     *
     * Chi riceve una notifica e clicca «vai all'archivio» trova una pagina che non c'è: è il
     * genere di difetto che non arriva mai come segnalazione, perché chi lo incontra pensa di
     * aver sbagliato qualcosa lui.
     *
     * ## Perché per nome e non per percorso
     *
     * `route()` fallisce **rumorosamente** su un nome che non esiste, mentre una stringa
     * concatenata è sempre sintatticamente valida — che è esattamente il presupposto sbagliato di
     * questa famiglia di difetti: *un indirizzo che si scrive non è un indirizzo che risponde*.
     * Da qui in poi il nome è verificato da `RotteSenzaMetodoTest` (che la rotta punti a un
     * metodo vero) e il collegamento da `LinkDelleNotificheTest`.
     *
     * Le due destinazioni sono diverse perché sono diverse le due schermate: l'amministratore ha
     * l'elenco documenti con i filtri, il condòmino sfoglia **per categoria** e un elenco unico
     * non ce l'ha.
     */
    public static function urlArchivioDocumenti(mixed $notifiable): string
    {
        return self::getRoutePrefixForUser($notifiable) === 'admin'
            ? route('admin.documenti.index')
            : route('user.categorie-documenti.index');
    }
}
