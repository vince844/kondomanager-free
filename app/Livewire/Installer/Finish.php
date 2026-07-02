<?php

namespace App\Livewire\Installer;

use Eii\Installer\Livewire\Install\Finish as BaseFinish;

/**
 * Override del componente Finish del vendor eii/laravel-installer.
 *
 * Il vendor carica l'intero payload del progress file (incluse le password in
 * chiaro raccolte negli step precedenti: admin, db, mail) in $this->settings,
 * una proprietà pubblica Livewire. Questo la espone sia nello snapshot Livewire
 * incluso nell'HTML della pagina sia nel file .txt scaricabile via
 * downloadSettings(). Nessuna versione 1.x del pacchetto risolve il problema
 * (risolto solo in v2.0.0, che richiede Livewire 4 non ancora adottato qui).
 *
 * Redigiamo qui i campi sensibili subito dopo il caricamento, prima che lo
 * stato venga serializzato: downloadSettings() è ereditato invariato e legge
 * $this->settings già redatto ad ogni richiesta successiva (Livewire
 * ri-idrata il componente dallo snapshot già ripulito).
 */
class Finish extends BaseFinish
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'db_password',
        'mail_password',
    ];

    public function mount(): void
    {
        parent::mount();

        $this->settings = $this->redact($this->settings);
    }

    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif (in_array($key, self::SENSITIVE_KEYS, true) && $value !== null && $value !== '') {
                $data[$key] = '••••••••';
            }
        }

        return $data;
    }
}
