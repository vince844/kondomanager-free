<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\CustomResetPasswordNotification;
use App\Notifications\CustomVerifyEmailNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'suspended_at',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * L'impronta che lega il link del primo accesso allo stato dell'utente quando è partito: l'email e
     * l'ultima modifica. Il link la porta fra i parametri firmati, e il controller la ricalcola: se
     * l'amministratore ha corretto l'email o il nome, ha fatto «reinvia», ha sospeso o riattivato
     * l'utente o ne ha cambiato la verifica, il link di prima non vale più e serve un nuovo invio
     * (ruoli, permessi e anagrafica non toccano la riga dell'utente, e non lo cambiano). Senza,
     * il link firmava solo l'`id` e restava buono per un account che nel frattempo era cambiato
     * (Fase 1-bis della 1.11.0-beta.45, rilievo X1).
     */
    public function improntaPrimoAccesso(): string
    {
        return sha1($this->email.'|'.$this->updated_at?->getTimestamp());
    }

    /**
     * La password per i controlli del framework: una stringa vuota quando l'account non ne ha una
     * (creato e in attesa del primo accesso, o dopo «reinvia»), mai `null`.
     *
     * Il cookie «ricordami» vale solo se l'impronta della password che porta è quella dell'account
     * (`SessionGuard::userFromRecaller`). Con Laravel 13.30 il confronto (`hash_equals`) riceveva
     * `null` e finiva in un errore a ogni pagina di quel browser; dalla 13.38 il framework scarta da sé
     * una password che non è una stringa, prima del confronto, e il browser torna a essere quello di un
     * ospite anche con `null`. La stringa vuota resta come difesa che non dipende dalla versione del
     * framework: con lei il confronto fallisce e basta. Per gli altri lettori non cambia niente: il login
     * e la conferma della password rifiutano una password vuota come rifiutavano `null` (i
     * `Hasher::check` del framework), e `AuthenticateSession` la tratta come prima, «nessuna
     * password» (Coda 202, punto 1).
     */
    public function getAuthPassword()
    {
        return parent::getAuthPassword() ?? '';
    }

    /**
     * Get the anagrafica associated with the user.
     */
    public function anagrafica()
    {
        return $this->hasOne(Anagrafica::class); 
    }

    /**
     * Check if the user account is suspended
     */
    public function suspended(): bool
    {
        return !is_null($this->suspended_at);
    }

    /**
     * Chi può toccare chi (Coda 258, decisione 134.1): questo utente può agire sull'account `$bersaglio`?
     *
     * È la regola sola di tutte le porte che toccano un altro account — la scheda (email, ruoli, permessi
     * diretti), «reinvia», la verifica dell'email, la revoca di un permesso, sospendere, riattivare,
     * eliminare — e delle pagine che lo dicono prima (l'elenco e la scheda degli utenti):
     *
     * - l'amministratore agisce su tutti; le invarianti «non sé stessi» e «mai senza amministratori»
     *   stanno a parte, dove servono;
     * - chi non è amministratore agisce solo su un account con i soli ruoli `utente` e `fornitore` (o
     *   nessuno) e senza permessi diretti che lui non ha. Un ruolo diverso porta poteri sull'installazione,
     *   e quei poteri li gestisce solo l'amministratore; un permesso diretto che lui non ha non saprebbe
     *   rimetterlo.
     *
     * I permessi che vengono dai ruoli del bersaglio non contano: con i soli ruoli `utente` e `fornitore`
     * sono quelli del portale, e chi gestisce gli utenti non deve averli per gestire un condòmino. Non
     * contano nemmeno i permessi diretti sui propri contenuti (gli `*_OWN_*`): non danno potere su nessun
     * altro.
     */
    public function haNellaPortata(User $bersaglio): bool
    {
        if ($this->hasRole(\App\Enums\Role::AMMINISTRATORE->value)) {
            return true;
        }

        $soloRuoliDelPortale = $bersaglio->getRoleNames()
            ->diff(\App\Enums\Role::concedibiliDaTutti())
            ->isEmpty();

        if (! $soloRuoliDelPortale) {
            return false;
        }

        return $bersaglio->getDirectPermissions()
            ->pluck('name')
            ->diff($this->getAllPermissions()->pluck('name'))
            ->diff(static::permessiSulProprioContenuto())
            ->isEmpty();
    }

    /**
     * I permessi sui propri contenuti (gli `*_OWN_*` dell'enum): danno a chi li ha poteri sui contenuti che ha
     * scritto lui — comunicazioni (anche pubblicarle), segnalazioni, commenti alle segnalazioni, documenti
     * dell'archivio, scadenze in agenda — e su nessun altro.
     *
     * La portata non li conta (`haNellaPortata()`), un account nella portata li perde anche da chi non li ha
     * (`RevokePermissionFromUserController`), concederli non chiede la password, e nemmeno cambiare l'email di chi
     * ha soltanto questi come permessi aggiuntivi (Coda 261, `ValidaConcessioneRuoli`): una definizione sola per
     * tutti questi posti.
     *
     * @return array<int, string> I nomi dei permessi.
     */
    public static function permessiSulProprioContenuto(): array
    {
        return collect(\App\Enums\Permission::cases())
            ->filter(fn (\App\Enums\Permission $permesso) => str_contains($permesso->name, '_OWN_'))
            ->map(fn (\App\Enums\Permission $permesso) => $permesso->value)
            ->values()
            ->all();
    }

    /**
     * È l'unico amministratore ancora attivo dell'installazione?
     *
     * Serve a impedire i tre modi di restare senza amministratori — sospendere, eliminare,
     * degradare — perché da quello stato **non si esce dall'interfaccia**: servirebbe `tinker`
     * o una query a mano. Guarda gli amministratori **attivi**: un amministratore sospeso non
     * tiene aperta la porta, quindi non conta come sostituto.
     */
    public function isUltimoAmministratoreAttivo(): bool
    {
        return static::unicoAmministratoreAttivoId() === $this->getKey();
    }

    /**
     * L'id dell'unico amministratore attivo, se ne è rimasto uno solo; `null` altrimenti.
     *
     * Una query per rispondere a tutte le righe di un elenco: chiamare `isUltimoAmministratoreAttivo()`
     * riga per riga costerebbe una query per utente.
     */
    public static function unicoAmministratoreAttivoId(): ?int
    {
        $attivi = static::role(\App\Enums\Role::AMMINISTRATORE->value)
            ->whereNull('suspended_at')
            ->limit(2)
            ->pluck('id');

        return $attivi->count() === 1 ? (int) $attivi->first() : null;
    }

    /**
     * Send the password reset notification.
     *
     * @param  string  $token
     * @return void
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token)
    {
        $this->notify(new CustomResetPasswordNotification($token));
    }

    /**
     * Send the email verification notification.
     *
     * @return void
     */
    public function sendEmailVerificationNotification()
    {
        $this->notify(new CustomVerifyEmailNotification);
    }

    public function notificationPreferences()
    {
        return $this->hasMany(NotificationPreference::class);
    }

/*     public function prefers(string $type): bool
    {
        return $this->notificationPreferences()->where('type', $type)->value('enabled') ?? false;
    } */

    public function userPreferences()
    {
        return $this->hasOne(UserPreference::class)->withDefault([
            'open_condominio_on_login' => false,
            'default_condominio_id' => null,
        ]);
    }

    /**
     * Le righe-per-pagina scelte elenco per elenco.
     *
     * ⚠️ Deliberatamente **non** caricata di default e non esposta da `UserResource`: sono dati che
     * servono al controller nel momento in cui pagina, e a nessun altro. `auth.user` viaggia in
     * ogni risposta Inertia, e appenderci una lista che cresce a ogni elenco visitato si
     * pagherebbe su tutte le pagine del programma, comprese quelle senza tabelle.
     */
    public function preferenzeTabelle()
    {
        return $this->hasMany(PreferenzaTabellaUtente::class);
    }

}
