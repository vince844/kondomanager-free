<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * La nota legale e la firma delle stampe.
 *
 * Una classe senza nome e rieseguibile, come le migrazioni che sono venute dopo: una classe con nome, caricata una seconda volta nello stesso
 * processo, non si può dichiarare di nuovo; e `add()` lancia `SettingAlreadyExists` se la proprietà c'è già. Una proprietà già scritta non si
 * tocca. Un'installazione che l'ha già eseguita non la rilancia: la tabella delle migrazioni la ricorda per il nome del file.
 */
return new class extends SettingsMigration
{
    /** @var array<string, mixed> */
    private const PROPRIETA = [
        'print.nota_legale_stampe' => 'Professione esercitata ai sensi della legge 14 gennaio 2013, n.4',
        'print.firma_stampe_path'  => null,
    ];

    public function up(): void
    {
        foreach (self::PROPRIETA as $nome => $valore) {
            if (! $this->migrator->exists($nome)) {
                $this->migrator->add($nome, $valore);
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::PROPRIETA) as $nome) {
            $this->migrator->deleteIfExists($nome);
        }
    }
};
