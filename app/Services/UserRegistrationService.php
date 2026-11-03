<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\Users\RegisteredUserNotification;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Enums\Role;
use Spatie\Permission\Models\Role as RoleModel;

class UserRegistrationService
{
    /**
     * Crea l'account di chi si registra dal sito o accetta un invito, con il ruolo dei nuovi iscritti.
     *
     * Con `$daUnInvito` il ruolo dei nuovi iscritti vale solo se è `utente` o `fornitore` (Coda 258): gli inviti
     * portano i codici dei palazzi, cioè sono per i condòmini, e li spedisce chi ha «Crea utenti», collaboratore
     * compreso; la tabella `inviti` non registra chi l'ha spedito. Con un ruolo che dà poteri l'invitato riceve
     * `utente`, chiunque abbia spedito l'invito; un altro ruolo glielo dà l'amministratore dalla scheda. La
     * registrazione dal sito dà il ruolo scelto, qualunque sia (decisione 136.2).
     */
    public function register(array $data, bool $daUnInvito = false): User
    {
        return DB::transaction(function () use ($data, $daUnInvito) {

            $user = User::create([
                'name'           => $data['name'],
                'email'          => $data['email'],
                'password'       => Hash::make($data['password']),
                'remember_token' => Str::random(60),
            ]);

            // 1. Assegnazione del ruolo dinamico dalle Impostazioni Generali.
            //    Se il nome salvato non è più un ruolo (eliminato o rinominato prima di questa versione,
            //    quando la pagina dei ruoli non lo impediva e non aggiornava le impostazioni, Coda 258),
            //    il nuovo iscritto riceve «utente» invece di un errore che blocca ogni registrazione;
            //    l'amministratore ne sceglie uno nelle impostazioni generali.
            $settings = app(GeneralSettings::class);
            $ruolo = RoleModel::where('name', $settings->default_user_role)->where('guard_name', 'web')->first();

            if (! $ruolo) {
                Log::warning('Il ruolo dei nuovi iscritti salvato nelle impostazioni generali non esiste più: assegnato «utente».', [
                    'ruolo_salvato' => $settings->default_user_role,
                ]);
            }

            // Il nome del ruolo trovato, non quello salvato: con il MySQL di serie il confronto per nome non guarda
            // le maiuscole, e la regola degli inviti guarda il ruolo che si assegna davvero.
            $nome = $ruolo?->name ?? Role::UTENTE->value;

            if ($daUnInvito && ! in_array($nome, Role::concedibiliDaTutti(), true)) {
                $nome = Role::UTENTE->value;
            }

            $user->assignRole($nome);

            // 2. L'invito qui non si tocca: lo accetta solo il suo link, in
            //    `InvitoRegisteredUserController::store()`. Fino alla 1.11.0-beta.44 questo
            //    servizio accettava l'invito con la stessa email, e lo usa anche `POST /register`:
            //    con la registrazione pubblica accesa bastava l'email di un invito per consumarlo
            //    (resto del flusso di prima della 1.8.0, Coda 223).

            // 3. Notifica agli amministratori (escludendo l'utente appena registrato)
            $admins = User::role([Role::AMMINISTRATORE->value])
                ->where('id', '!=', $user->id)
                ->get();

            // 4. Invia la notifica solo se ci sono altri amministratori
            if ($admins->isNotEmpty()) {
                Notification::send($admins, new RegisteredUserNotification());
            }

            return $user;
        });
    }
}