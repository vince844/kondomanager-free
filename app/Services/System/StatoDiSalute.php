<?php

namespace App\Services\System;

use App\Services\Installer\ChiudiInstallazione;
use App\Services\Installer\CreaAmministratore;
use App\Support\PersistenzaStorage;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;

/**
 * Cosa risponde `/up`, e perché non è più la rotta di salute di Laravel (1.11.0-beta.32).
 *
 * Quella rotta rispondeva 200 appena l'applicazione si avviava, senza guardare il database: un
 * container su un database vuoto risultava «sano», e chi lo orchestra avrebbe detto «pronta» al
 * cliente per un'istanza dove il primo clic è un errore. Qui «sana» vuol dire tre cose insieme:
 * installazione chiusa (il lock c'è), database raggiungibile, nessuna migrazione da applicare.
 *
 * Le migrazioni che risultano applicate ma non hanno più un file (rinominate o tolte nel tempo:
 * sul database di sviluppo ce ne sono sei, verificato il 20/09/2026) si contano e si riportano,
 * ma non rendono l'installazione malata: succede a chiunque abbia aggiornato attraverso più
 * versioni, e non è un guasto.
 *
 * «Installata» = c'è il file di lock, oppure c'è un amministratore: la seconda copre chi ha
 * installato dai sorgenti (`migrate` + `db:seed`), che il lock non l'ha mai avuto e non deve
 * ritrovarsi «non disponibile» per questo.
 *
 * Dalla 1.11.0-beta.33 conta anche dove stanno i documenti: `esterni` (S3), `su_volume` o
 * `effimeri`. Solo nel container gli effimeri rendono l'installazione non disponibile.
 */
class StatoDiSalute
{
    public function __construct(
        private readonly ChiudiInstallazione $installazione,
        private readonly CreaAmministratore $amministratore,
        private readonly Migrator $migrator,
    ) {}

    /**
     * @return array{sana: bool, installata: bool, database: string, migrazioni_pendenti: ?int, migrazioni_senza_file: ?int, documenti: string}
     */
    public function rileva(): array
    {
        // Il lock lo scrivono il wizard e `kondomanager:installa`; chi ha installato dai sorgenti
        // non ce l'ha, ma ha un amministratore: anche quella è un'installazione fatta.
        $installata = $this->installazione->installata() || $this->amministratore->esisteUnAmministratore();
        $database = 'ok';
        $pendenti = null;
        $senzaFile = null;

        try {
            DB::connection()->getPdo();

            if ($this->migrator->repositoryExists()) {
                $file = array_keys($this->migrator->getMigrationFiles(
                    array_merge($this->migrator->paths(), [database_path('migrations')])
                ));
                $applicate = $this->migrator->getRepository()->getRan();

                $pendenti = count(array_diff($file, $applicate));
                $senzaFile = count(array_diff($applicate, $file));
            } else {
                $pendenti = count($this->migrator->getMigrationFiles(
                    array_merge($this->migrator->paths(), [database_path('migrations')])
                ));
                $senzaFile = 0;
            }
        } catch (\Throwable) {
            $database = 'errore';
        }

        // Nel container (KM_CONTAINER=1) i documenti effimeri — disco locale senza volume —
        // rendono l'installazione non disponibile: chi orchestra non deve dire «pronta» a
        // un'istanza che perde i file alla prima ricreazione. Fuori dal container si riporta e
        // basta: su un server normale «non su volume» non vuol dire niente.
        $documenti = PersistenzaStorage::statoDocumenti();
        $documentiOk = ! (PersistenzaStorage::inContainer() && $documenti === 'effimeri');

        return [
            'sana' => $installata && $database === 'ok' && $pendenti === 0 && $documentiOk,
            'installata' => $installata,
            'database' => $database,
            'migrazioni_pendenti' => $pendenti,
            'migrazioni_senza_file' => $senzaFile,
            'documenti' => $documenti,
        ];
    }
}
