<?php

namespace App\Console\Commands;

use App\Support\PersistenzaStorage;
use Illuminate\Console\Command;

/**
 * Dice se i documenti caricati sopravvivono al prossimo rebuild del contenitore.
 *
 * Nasce da una domanda di Vincenzo del 18/08/2026: su Coolify il volume per `storage/app` si dichiara
 * a mano, ed è il passaggio che si dimentica. Questo comando lo trasforma in una risposta: si lancia
 * dopo il primo deploy — o dal comando post-deploy — e dice la verità con i numeri davanti.
 */
class VerificaPersistenzaCommand extends Command
{
    protected $signature = 'kondomanager:verifica-persistenza {--rigoroso : esce con errore se non è persistente, per il comando post-deploy}';

    protected $description = 'Verifica se storage/app è su un volume persistente e quanto ci sarebbe da perdere';

    public function handle(): int
    {
        $esito = PersistenzaStorage::verdetto();

        $this->newLine();
        $this->line('  Percorso controllato: <fg=cyan>'.$esito['percorso'].'</>');
        $this->newLine();

        $totaleFile = 0;
        $totaleByte = 0;

        foreach ($esito['cartelle'] as $nome => $m) {
            $totaleFile += $m['file'];
            $totaleByte += $m['byte'];
            $this->line(sprintf('  %-10s %5d file  %s', $nome, $m['file'], $this->leggibile($m['byte'])));
        }

        $this->newLine();

        if ($esito['persistente']) {
            $this->info('  ✓ storage/app è su un volume separato: i file sopravvivono alla ricreazione del contenitore.');
            $this->newLine();

            return self::SUCCESS;
        }

        // Sviluppo in locale: la cartella sta nel progetto e va benissimo così.
        if (app()->environment('local', 'testing')) {
            $this->comment('  In sviluppo non serve un volume: la cartella vive nel progetto.');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->error('  ✗ storage/app NON è su un volume separato.');
        $this->newLine();
        $this->line('  Vive nel livello scrivibile del contenitore, quindi <fg=red>alla prossima ricreazione</>');
        $this->line('  spariscono '.$totaleFile.' file per '.$this->leggibile($totaleByte).' — documenti, backup e allegati.');
        $this->newLine();
        $this->line('  Si risolve dichiarando il volume <fg=yellow>dove il contenitore viene eseguito</>:');
        $this->line('    · Coolify → Persistent Storage → '.$esito['percorso']);
        $this->line('    · docker compose → un volume con un nome mappato su '.$esito['percorso']);
        $this->newLine();
        $this->comment('  Nota: `VOLUME` nel Dockerfile non basta — crea un volume anonimo diverso a ogni contenitore.');
        $this->newLine();

        // ⚠️ Codice di errore **solo se richiesto**. La revisione della beta.58 ha fatto notare che
        // mettere questo comando nel post-deploy con l'uscita 1 di default farebbe risultare fallito
        // un deploy che è andato benissimo — e un rilevamento che sbaglia costa più di uno assente.
        // Chi vuole il deploy bloccante lo chiede con `--rigoroso`, sapendo cosa sta chiedendo.
        return $this->option('rigoroso') ? self::FAILURE : self::SUCCESS;
    }

    private function leggibile(int $byte): string
    {
        if ($byte < 1024) {
            return $byte.' B';
        }

        $mb = $byte / 1048576;

        return $mb >= 1
            ? number_format($mb, 1, ',', '.').' MB'
            : number_format($byte / 1024, 0, ',', '.').' KB';
    }
}
