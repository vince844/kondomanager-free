<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_user'        => 'Il nuovo utente è stato creato con successo.',
    'error_create_user'          => 'Si è verificato un errore durante la creazione del nuovo utente.',
    'success_update_user'        => 'L\'utente è stato aggiornato con successo',
    'error_update_user'          => 'Si è verificato un errore durante l\'aggiornamento dell\'utente.',
    'success_delete_user'        => 'L\'utente è stato eliminato con successo.',
    'error_delete_user'          => 'Si è verificato un errore durante l\'eliminazione dell\'utente.',
    'success_send_user_invite'   => 'L\'invito è stato inviato con successo.',
    'error_send_user_invite'     => 'Si è verificato un errore durante l\'invio dell\'invito all\'utente.',
    'success_delete_user_invite' => 'L\'invito è stato eliminato con successo.',
    'error_delete_user_invite'   => 'Si è verificato un errore durante l\'eliminazione dell\'invito.',
    'success_suspend_user'       => 'L\'utente è stato sospeso con successo.',
    'error_suspend_user'         => 'Si è verificato un errore durante il tentativo di sospendere l\'utente.',
    'success_unsuspend_user'     => 'L\'utente è stato riattivato con successo.',
    'error_unsuspend_user'       => 'Si è verificato un errore durante il tentativo di riattivare l\'utente.',
    'success_verify_user'        => 'L\'utente è stato verificato con successo.',
    'success_revoke_verify_user' => 'La verifica dell\'utente è stata revocata.',
    'error_verify_user'          => 'Si è verificato un errore durante la verifica dell\'utente.',
    'error_email_not_sent'       => 'L\'utente è stato creato correttamente, ma non è stato possibile inviare l\'email di invito.',
    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_users_head'                    => 'Elenco utenti',
        'edit_user_head'                     => 'Modifica utente',
        'new_user_head'                      => 'Crea nuovo utente',
        'new_user_title'                     => 'Crea nuovo utente',
        'new_user_description'               => 'Di seguito è possibile creare un nuovo utente, puoi assegnare un ruolo, un\'anagrafica e dei permessi specifici per questo utente',
        'permissions_title'                  => 'Permessi ereditati dal ruolo',
        'permissions_description_line_1'     => 'Questi permessi sono ereditati tramite il ruolo',
        'permissions_description_line_2'     => 'e verranno assegnati automaticamente all\'utente',
        'additional_permissions_title'       => 'Permessi aggiuntivi',
        'additional_permissions_description' => 'Permessi assegnati direttamente all\'utente, oltre a quelli del ruolo',
    ],
    /* ------------------------------------------------------------------
     | Table 
     | ------------------------------------------------------------------ */
    'table' => [
        'name'               => 'Nome e cognome',
        'email'              => 'Indirizzo email',
        'role'               => 'Ruolo',
        'permissions'        => 'Permessi',
        'status'             => 'Stato',
        'suspended'          => 'Sospeso',
        'active'             => 'Attivo',
        'actions'            => 'Azioni',
        'filter'             => 'Filtra per nome...',
        'no_permissions'     => 'Nessun permesso',
        'verified_tooltip'   => 'Utente verificato - clicca per revocare verifica',
        'unverified_tooltip' => 'Utente non verificato - clicca per verificare',
    ],
    /* ------------------------------------------------------------------
     | Labels for fields
     | ------------------------------------------------------------------ */
    'label' => [
        'name'                         => 'Nome e cognome',
        'email'                        => 'Indirizzo email',
        'role'                         => 'Ruolo utente',
        'resident'                     => 'Anagrafica associata',
        'permissions'                  => 'Permessi aggiuntivi',
        'permission_notice'            => 'I permessi del ruolo selezionato sono ereditati automaticamente',
        'permissions_assigned'         => 'Permessi utente',
        'permissions_assigned_to_user' => 'Permessi assegnati a :name',
        'permissions_count'            => ':count permessi',
    ],
    /* ------------------------------------------------------------------
     | Placeholders
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'name'        => 'Inserisci nome e cognome',
        'email'       => 'Inserisci indirizzo email',
        'role'        => 'Seleziona ruolo utente',
        'resident'    => 'Seleziona anagrafica',
        'permissions' => 'Seleziona permessi aggiuntivi',
    ],
    /* ------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_user'      => 'Crea utente',
        'edit_user'     => 'Modifica',
        'delete_user'   => 'Elimina',
        'suspend_user'  => 'Sospendi',
        'activate_user' => 'Attiva',
        'invite_user'   => 'Reinvita',
    ],
    /* ------------------------------------------------------------------
     | Tooltips / Hover cards
     | ------------------------------------------------------------------ */
    'tooltip' => [
        'role_line_1' => 'Seleziona il ruolo da assegnare all\'utente, scegli tra i ruoli di default oppure uno di quelli da te creati.',
        'role_line_2' => 'I permessi associati al ruolo verranno ereditati automaticamente.',
        'resident'    => 'Seleziona l\'anagrafica da associare all\'utente. L\'anagrafica associata potrà accedere al sistema con le credenziali dell\'utente creato e consultare i propri dati e quelli a lui collegati.',
        'permissions' => 'Seleziona permessi specifici da assegnare all\'utente oltre a quelli ereditati dal ruolo selezionato.',
    ],
    /* ------------------------------------------------------------------
     | Dialogs
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'no_users_created'         => 'Nessun utente ancora creato',
        'delete_user_title'        => 'Sei sicuro di volere eliminare questo utente?',
        'delete_user_description'  => 'Questa azione non è reversibile. Eliminerà l\'utente e tutti i dati ad esso associati.',
        'invite_user_title'        => 'Sei sicuro di volere invitare nuovamente questo utente?',
        'invite_user_description'  => 'L\'utente riceverà una email con un nuovo link per la creazione di una nuova password.',
    ],
    /* ------------------------------------------------------------------
     | Empty states
     | ------------------------------------------------------------------ */
    'empty_state' => [
        'inherited_permissions'   => 'Nessun permesso ereditato dal ruolo',
        'additional_permissions'  => 'Nessun permesso aggiuntivo assegnato',
        'no_assigned_permissions' => 'Nessun permesso assegnato',
    ],
    /* ------------------------------------------------------------------
     | Badges (etichette/stati)
     | ------------------------------------------------------------------ */
    'badge' => [
        'previously_direct' => 'in precedenza assegnato',
    ],
    /* ------------------------------------------------------------------
     | Layout 
     | ------------------------------------------------------------------ */
    'layout' => [
        'heading_title'       => 'Gestione utenti',
        'heading_description' => 'Di seguito un elenco degli utenti registrati, ruoli, permessi e inviti',
    ],
];