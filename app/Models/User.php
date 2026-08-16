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
