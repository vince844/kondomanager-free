<?php

namespace App\Services\Import;

/**
 * Un livello dell'importazione guidata (§5): condominio, esercizi, soggetti, unità, titolarità…
 *
 * L'ordine dei livelli non è cosmetico: ognuno dichiara **da cosa dipende**, e il committer
 * rifiuta di partire finché quelle dipendenze non sono soddisfatte **nel database**, non nel
 * file (§10.6).
 */
interface LivelloImport
{
    /** La chiave del livello: 'condominio', 'esercizi', … */
    public function chiave(): string;

    /** Come si chiama nella schermata di verifica. */
    public function etichetta(): string;

    /**
     * Le chiavi dei livelli che devono essere già passati.
     *
     * @return list<string>
     */
    public function dipendeDa(): array;

    /**
     * Verifica sullo **stato reale del database** che tutto il necessario esista.
     *
     * Il contratto è quello del §10.6, ed è l'unico punto in cui vale la pena essere pedanti:
     * qui non si guarda cosa dice il file, si guarda cosa c'è. Un livello che controllasse il
     * proprio input avrebbe già fallito allo scopo.
     *
     * @return list<PrerequisitoMancante>  vuoto = si può procedere
     */
    public function verificaPrerequisiti(ImportContext $ctx): array;

    /**
     * Scrive in archivio ciò che il livello ha letto. Chiamato **dentro** una transazione.
     */
    public function commit(ImportContext $ctx): EsitoCommit;
}
