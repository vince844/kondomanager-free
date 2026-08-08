<?php

namespace App\Services\Import\Livelli;

use App\Enums\RuoloAnagraficaImmobile;
use App\Models\Esercizio;
use App\Models\ImportBatchItem;
use App\Services\Import\Canonical\CanonicalTitolarita;
use App\Services\Import\EsitoCommit;
use App\Services\Import\ImportContext;
use App\Services\Import\LivelloImport;
use App\Services\Import\PrerequisitoMancante;
use App\Services\Import\Rilievo;
use Illuminate\Support\Facades\DB;

/**
 * Livello 6 — **chi possiede cosa**. È la ragione per cui esiste tutto il resto.
 *
 * Un'unità senza titolare non riceve rate, non compare in morosità e non entra in nessun
 * riparto: senza questo livello l'importazione riesce tecnicamente e fallisce operativamente.
 * È il punto in cui il concorrente si ferma (§0.3-D).
 *
 * ## Perché dipende dall'esercizio
 *
 * `anagrafica_immobile.data_inizio` è **NOT NULL**, e l'export di Danea non porta la decorrenza
 * del ruolo — quella esiste solo nel database nativo, nella colonna `DAL` (§17.7). La data va
 * quindi presa da qualche parte, e l'unica scelta difendibile è **l'inizio dell'esercizio che
 * si sta importando**: è il momento da cui quella situazione vale nei nostri conti.
 *
 * Metterci `oggi` sarebbe più comodo e sbagliato: farebbe risultare tutti i titolari entrati il
 * giorno della migrazione, e qualsiasi calcolo pro-rata a ritroso ne uscirebbe falsato.
 */
final class LivelloTitolarita implements LivelloImport
{
    public const CHIAVE = 'titolarita';

    public function chiave(): string
    {
        return self::CHIAVE;
    }

    public function etichetta(): string
    {
        return 'Chi possiede cosa';
    }

    public function dipendeDa(): array
    {
        return [LivelloEsercizi::CHIAVE, LivelloSoggetti::CHIAVE, LivelloUnita::CHIAVE];
    }

    public function verificaPrerequisiti(ImportContext $ctx): array
    {
        $mancanti = [];

        $esercizio = $ctx->risolto(LivelloEsercizi::CHIAVE);

        if (! $esercizio instanceof Esercizio || ! Esercizio::whereKey($esercizio->getKey())->exists()) {
            $mancanti[] = new PrerequisitoMancante(
                'titolarita.esercizio_mancante',
                'Non posso registrare chi possiede cosa senza sapere da quando.',
                'Serve l\'esercizio: la decorrenza dei ruoli non è nell\'export di Danea, e la '
                .'prendo dall\'inizio dell\'esercizio che stai importando.',
            );
        }

        if (! $ctx->haRisolto(LivelloSoggetti::CHIAVE)) {
            $mancanti[] = new PrerequisitoMancante(
                'titolarita.soggetti_mancanti',
                'Le persone non sono ancora in archivio.',
                'Importa prima il livello «Persone».',
            );
        }

        if (! $ctx->haRisolto(LivelloUnita::CHIAVE)) {
            $mancanti[] = new PrerequisitoMancante(
                'titolarita.unita_mancanti',
                'Le unità immobiliari non sono ancora in archivio.',
                'Importa prima il livello «Unità immobiliari».',
            );
        }

        return $mancanti;
    }

    public function commit(ImportContext $ctx): EsitoCommit
    {
        /** @var list<CanonicalTitolarita> $titolarita */
        $titolarita = $ctx->canonico(self::CHIAVE) ?? [];
        $soggetti = $ctx->risoltiMolti(LivelloSoggetti::CHIAVE);
        $unita = $ctx->risoltiMolti(LivelloUnita::CHIAVE);
        /** @var Esercizio $esercizio */
        $esercizio = $ctx->risolto(LivelloEsercizi::CHIAVE);

        $rilievi = [];
        $avvisi = [];
        /** @var list<CanonicalTitolarita> $daScrivere */
        $daScrivere = [];

        // Primo giro: solo lettura. `insertGetId()` viveva **dentro** questo ciclo, prima del
        // controllo su $rilievi — trovato dalla revisione avversariale, stesso schema del bug
        // già corretto in LivelloSoggetti. Una riga con riferimento irrisolto a metà file
        // lasciava comunque scritte in `anagrafica_immobile` le titolarità delle righe
        // precedenti, con il livello segnato «bloccato»: la promessa di `ImportRunner` («se
        // salta qui, il livello non è entrato affatto») non teneva.
        foreach ($titolarita as $t) {
            $soggetto = $soggetti[$t->soggettoRef] ?? null;
            $immobile = $unita[$t->immobileRef] ?? null;

            // Chiave irrisolvibile = errore di riga, mai un `NULL` silenzioso (§8.2). È la
            // regola che impedisce il caso peggiore: una titolarità che entra senza sapere
            // di chi è.
            if ($soggetto === null || $immobile === null) {
                $rilievi[] = Rilievo::errore(
                    'titolarita.riferimento_irrisolto',
                    sprintf(
                        'Non trovo %s per questa riga.',
                        $soggetto === null ? 'la persona' : 'l\'unità',
                    ),
                    'Succede quando il livello precedente ha saltato quella riga. Torna indietro '
                    .'e risolvi prima le decisioni sulle persone e sulle unità.',
                    $t->rigaSorgente,
                );

                continue;
            }

            $daScrivere[] = $t;
        }

        if ($rilievi !== []) {
            return EsitoCommit::bloccato(...$rilievi);
        }

        // Secondo giro: si scrive solo ora. Il controllo «esiste già» non può più contare su
        // vedere in tempo reale le righe appena scritte da questo stesso giro (prima poteva,
        // perché scriveva mentre leggeva): un Set locale tiene lo stesso effetto per due righe
        // del file che puntano alla stessa tripla — caso raro ma non impossibile, e senza un
        // indice unico su `anagrafica_immobile` sarebbe un doppione silenzioso in tabella.
        $creati = 0;
        $saltati = 0;
        $vistiInQuestoGiro = [];

        foreach ($daScrivere as $t) {
            $soggetto = $soggetti[$t->soggettoRef];
            $immobile = $unita[$t->immobileRef];

            $tripla = $immobile->getKey().':'.$soggetto->getKey().':'.$t->ruolo->value;

            $esiste = isset($vistiInQuestoGiro[$tripla]) || DB::table('anagrafica_immobile')
                ->where('immobile_id', $immobile->getKey())
                ->where('anagrafica_id', $soggetto->getKey())
                ->where('tipologia', $t->ruolo->value)
                ->exists();

            if ($esiste) {
                $saltati++;

                continue;
            }

            $vistiInQuestoGiro[$tripla] = true;

            DB::table('anagrafica_immobile')->insertGetId([
                'immobile_id' => $immobile->getKey(),
                'anagrafica_id' => $soggetto->getKey(),
                'tipologia' => $t->ruolo->value,
                // Lo schema ha default 100.00: la quota vera, quando esiste, sta solo nelle
                // note del file (§17.4), e il parser la segnala invece di indovinarla.
                'quota' => $t->quota ?? 100.00,
                'data_inizio' => $esercizio->data_inizio,
                'attivo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $ctx->registra(
                self::CHIAVE,
                ImportBatchItem::AZIONE_CREATO,
                null,
                $t->rigaSorgente,
            );

            $creati++;
        }

        // Il controllo che il concorrente non fa, e che è il senso di questo livello: se dopo
        // aver scritto tutto restano unità senza nessun titolare, l'importazione è riuscita e
        // **inutile**. Non blocca — l'amministratore può volerlo sapere e proseguire — ma è
        // l'avviso che la schermata di conferma mostra per primo, in rosso.
        $senzaTitolare = $this->unitaSenzaTitolare($unita);

        if ($senzaTitolare > 0) {
            $avvisi[] = Rilievo::avviso(
                'titolarita.unita_senza_titolare',
                sprintf(
                    '%d unità %s senza un titolare di diritto reale.',
                    $senzaTitolare,
                    $senzaTitolare === 1 ? 'è rimasta' : 'sono rimaste',
                ),
                'Un\'unità senza titolare non riceve rate, non compare in morosità e non entra '
                .'nei riparti. Un inquilino non basta: verso il condominio risponde chi ha la '
                .'proprietà, la nuda proprietà o l\'usufrutto. Assegnali prima di emettere il '
                .'primo piano rate.',
            );
        }

        return new EsitoCommit(creati: $creati, saltati: $saltati, avvisi: $avvisi);
    }

    /**
     * Quante unità restano senza **un titolare di diritto reale**.
     *
     * Non «senza una riga qualsiasi nella pivot»: il conteggio guardava ogni riga di
     * `anagrafica_immobile`, inquilino compreso, e un'unità con il solo conduttore risultava
     * a posto. Per il motore di riparto però è invisibile — `CalcoloQuoteService` esaurisce la
     * cascata e mette il peso fra gli scoperti, `GenerateSaldiAction` solleva
     * `SaldoSolidaleSenzaTitolareException` — cioè esattamente ciò che questo avviso esiste
     * per prevenire, mancato proprio nel caso in cui serviva.
     *
     * La regola sta in `RuoloAnagraficaImmobile::titolariDiDirittoReale()` dalla beta.43, ed è
     * l'unico posto in cui è scritta: qui la si usa, non la si riscrive.
     *
     * @param  array<string, \Illuminate\Database\Eloquent\Model>  $unita
     */
    private function unitaSenzaTitolare(array $unita): int
    {
        if ($unita === []) {
            return 0;
        }

        $ids = array_map(fn ($i) => $i->getKey(), array_values($unita));

        $conTitolare = DB::table('anagrafica_immobile')
            ->whereIn('immobile_id', $ids)
            ->where('attivo', true)
            ->whereIn('tipologia', array_column(RuoloAnagraficaImmobile::titolariDiDirittoReale(), 'value'))
            ->distinct()
            ->count('immobile_id');

        return count($ids) - $conTitolare;
    }
}
