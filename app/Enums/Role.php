<?php

namespace App\Enums;

use App\Enums\Permission as PermissionEnum;

/**
 * Enum Role
 *
 * Defines application roles and their associated permissions.
 *
 * @package App\Enums
 */
enum Role: string
{
    case AMMINISTRATORE = 'amministratore';
    case COLLABORATORE = 'collaboratore';
    case FORNITORE = 'fornitore';
    case UTENTE = 'utente';

    /**
     * I soli ruoli che concede anche chi non è amministratore, e i soli che un account può avere per
     * restare nella sua portata (decisione 134.1).
     *
     * Sono i ruoli del portale: creare e correggere le utenze dei condòmini e dei fornitori è il mestiere
     * del collaboratore. Ogni altro ruolo — `amministratore`, `collaboratore`, un ruolo su misura — dà
     * poteri sull'installazione, e lo concede, lo toglie e lo gestisce solo un amministratore.
     *
     * @return array<int, string>
     */
    public static function concedibiliDaTutti(): array
    {
        return [
            self::UTENTE->value,
            self::FORNITORE->value,
        ];
    }

    /**
     * Get a textual description of the role.
     *
     * @return string A human-readable description for this role.
     */
    public function description(): string
    {
        return match ($this) {
            self::AMMINISTRATORE => 'Questo è il ruolo di default amministratore',
            self::COLLABORATORE => 'Questo è il ruolo di default collaboratore',
            self::FORNITORE => 'Questo è il ruolo di default fornitore',
            self::UTENTE => 'Questo è il ruolo di default utente',
        };
    }

    /**
     * Get the list of permissions associated with the role.
     *
     * @return PermissionEnum[] An array of permission enums tied to this role.
     */
    public function permissions(): array
    {
        return match ($this) {
            self::AMMINISTRATORE => PermissionEnum::cases(),

            self::COLLABORATORE => [
                PermissionEnum::ACCESS_ADMIN_PANEL,
                PermissionEnum::CREATE_USERS,
                PermissionEnum::EDIT_USERS,
                PermissionEnum::VIEW_USERS,
                PermissionEnum::CREATE_ANAGRAFICHE,
                PermissionEnum::EDIT_ANAGRAFICHE,
                PermissionEnum::VIEW_ANAGRAFICHE,
                PermissionEnum::CREATE_CONDOMINI,
                PermissionEnum::EDIT_CONDOMINI,
                PermissionEnum::VIEW_CONDOMINI,
                PermissionEnum::CREATE_COMUNICAZIONI,
                PermissionEnum::PUBLISH_COMUNICAZIONI,
                PermissionEnum::EDIT_COMUNICAZIONI,
                PermissionEnum::VIEW_COMUNICAZIONI,
                PermissionEnum::COMMENT_COMUNICAZIONI,
                PermissionEnum::PUBLISH_COMMENTS_COMUNICAZIONI,
                PermissionEnum::EDIT_COMMENTS_COMUNICAZIONI,
                PermissionEnum::VIEW_COMMENTS_COMUNICAZIONI,
                PermissionEnum::CREATE_SEGNALAZIONI,
                PermissionEnum::PUBLISH_SEGNALAZIONI,
                PermissionEnum::EDIT_SEGNALAZIONI,
                PermissionEnum::VIEW_SEGNALAZIONI,
                PermissionEnum::COMMENT_SEGNALAZIONI,
                PermissionEnum::EDIT_COMMENTS_SEGNALAZIONI,
                PermissionEnum::PUBLISH_COMMENTS_SEGNALAZIONI,
                PermissionEnum::VIEW_COMMENTS_SEGNALAZIONI,
                PermissionEnum::VIEW_ARCHIVE_DOCUMENTS,
                PermissionEnum::CREATE_ARCHIVE_DOCUMENTS,
                PermissionEnum::EDIT_ARCHIVE_DOCUMENTS,
                PermissionEnum::PUBLISH_ARCHIVE_DOCUMENTS,
                PermissionEnum::APPROVE_ARCHIVE_DOCUMENTS,
                PermissionEnum::VIEW_EVENTS,
                PermissionEnum::CREATE_EVENTS,
                PermissionEnum::EDIT_EVENTS,
                PermissionEnum::PUBLISH_EVENTS,
                PermissionEnum::APPROVE_EVENTS,
            ],

            self::FORNITORE => [
                PermissionEnum::VIEW_COMUNICAZIONI,
                PermissionEnum::COMMENT_COMUNICAZIONI,
                PermissionEnum::VIEW_COMMENTS_COMUNICAZIONI,
                PermissionEnum::VIEW_SEGNALAZIONI,
                PermissionEnum::COMMENT_SEGNALAZIONI,
                PermissionEnum::PUBLISH_COMMENTS_SEGNALAZIONI,
                PermissionEnum::EDIT_OWN_COMMENTS_SEGNALAZIONI,
                PermissionEnum::DELETE_OWN_COMMENTS_SEGNALAZIONI,
                PermissionEnum::VIEW_COMMENTS_SEGNALAZIONI,
                PermissionEnum::VIEW_ARCHIVE_DOCUMENTS,
                PermissionEnum::VIEW_EVENTS,
            ],

            self::UTENTE => [
                PermissionEnum::VIEW_COMUNICAZIONI,
                PermissionEnum::COMMENT_COMUNICAZIONI,
                PermissionEnum::VIEW_COMMENTS_COMUNICAZIONI,
                PermissionEnum::CREATE_SEGNALAZIONI,
                PermissionEnum::VIEW_SEGNALAZIONI,
                PermissionEnum::COMMENT_SEGNALAZIONI,
                PermissionEnum::PUBLISH_COMMENTS_SEGNALAZIONI,     // commenti pubblicati subito
                PermissionEnum::EDIT_OWN_COMMENTS_SEGNALAZIONI,    // può modificare i propri
                PermissionEnum::DELETE_OWN_COMMENTS_SEGNALAZIONI,  // può eliminare i propri
                PermissionEnum::VIEW_COMMENTS_SEGNALAZIONI,
                PermissionEnum::VIEW_ARCHIVE_DOCUMENTS,
                PermissionEnum::VIEW_EVENTS,
            ],
        };
    }
}
