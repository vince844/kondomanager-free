<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Import\Livelli;

use App\Enums\RuoloAnagraficaImmobile;
use App\Models\Esercizio;
use App\Models\ImportBatchItem;
use App\Services\Import\Canonical\CanonicalTitolarita;
use App\Services\Import\EsitoCommit;
use App\Services\Import\ImportContext;
use App\Services\Import\LivelloImport;
use App\Services\Import\PartiUguali;
use App\Services\Import\PrerequisitoMancante;
use App\Services\Import\Rilievo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Services\Riparto\RisolutoreTitolari;

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

        // ── Il conflitto della seconda importazione (coda ㉔) ──────────────────────────────
        //
        // La guardia sulla tripla `(immobile, anagrafica, ruolo)` impedisce di riscrivere la
        // **stessa** riga, e fa il suo lavoro. Non vede però il caso vero di una reimportazione:
        // l'anagrafica è **diversa** — la coppia come soggetto unico e i due coniugi separati
        // sono tre `anagrafica_id` distinti — quindi nessuna tripla collide, tutte le righe
        // entrano, e sull'unità la somma delle quote diventa 200. Accertato sul condominio 33:
        // due unità su undici.
        //
        // **Cosa fa questa guardia, e cosa non fa.** Salta l'unità e lo scrive nel rapporto.
        // Non cancella, non sostituisce e non chiede niente: la strada con la scelta
        // *sostituisci/salta* è stata scritta nella beta.52 e ritirata dalla revisione con
        // quattro reperti «alta», il primo dei quali era che la decisione richiesta non era
        // raggiungibile da nessuna schermata. Chi deve correggere una titolarità lo fa sulla
        // scheda dell'unità, dove vede cosa sta cambiando.
        $inConflitto = $this->unitaInConflitto($daScrivere, $soggetti, $unita);

        // ── Le quote che il file non dice (Coda 599, 1.12.0-beta.14) ─────────────────────
        //
        // Danea la quota non la dà mai, e fino alla beta.13 ogni riga senza entrava a 100: due proprietari dello stesso interno facevano 200, e dalla
        // scheda dell'unità la guardia delle quote rifiutava ogni correzione. Ora si guarda il gruppo (unità, ruolo) del file: senza nessuna quota, parti
        // uguali che fanno 100; con alcune quote scritte, le righe vuote si dividono il resto; se le quote scritte fanno già 100, il gruppo si salta come
        // un conflitto, e l'avviso dice di correggere il file.
        [$quote, $senzaResto] = $this->quoteDelFile($daScrivere, $soggetti, $unita, $inConflitto);

        foreach ($daScrivere as $t) {
            $soggetto = $soggetti[$t->soggettoRef];
            $immobile = $unita[$t->immobileRef];

            $gruppo = $immobile->getKey().':'.$t->ruolo->value;

            if (isset($inConflitto[$gruppo]) || isset($senzaResto[$gruppo])) {
                $saltati++;

                // `registra()` prende un'entità, non un testo: il perché del salto sta
                // nell'avviso aggregato, che l'amministratore legge una volta invece di
                // ritrovarselo ripetuto riga per riga.
                $ctx->registra(
                    self::CHIAVE,
                    ImportBatchItem::AZIONE_SALTATO,
                    null,
                    $t->rigaSorgente,
                );

                continue;
            }

            $tripla = $immobile->getKey().':'.$soggetto->getKey().':'.$t->ruolo->value;

            // `attivo` va filtrato anche qui. Senza, una titolarità **cessata** con la stessa
            // terna faceva dire «esiste già» e la riga del file non entrava: l'unità restava
            // senza titolare attivo, in silenzio. La lettura sopra e la guardia sui conflitti
            // guardano entrambe le sole righe attive: questa è la terza, e devono concordare.
            $esiste = isset($vistiInQuestoGiro[$tripla]) || app(RisolutoreTitolari::class)->vincolaQuery(
                DB::table('anagrafica_immobile')
                    ->where('immobile_id', $immobile->getKey())
                    ->where('anagrafica_id', $soggetto->getKey())
                    ->where('tipologia', $t->ruolo->value)
            )->exists();

            if ($esiste) {
                $saltati++;

                continue;
            }

            $vistiInQuestoGiro[$tripla] = true;

            DB::table('anagrafica_immobile')->insertGetId([
                'immobile_id' => $immobile->getKey(),
                'anagrafica_id' => $soggetto->getKey(),
                'tipologia' => $t->ruolo->value,
                // La quota del file, o quella che `quoteDelFile()` dà a chi nel file non ce l'ha (Coda 599). Nei file di Danea la quota vera,
                // quando esiste, sta solo nelle note (§17.4), e il parser la segnala invece di indovinarla.
                'quota' => $quote[$tripla],
                // Solo la data: un Carbon intero su sqlite scrive anche l'ora, e il filtro SQL del risolutore
                // e la collection devono leggere lo stesso giorno (invariante 4).
                'data_inizio' => $esercizio->data_inizio->toDateString(),
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
        if ($inConflitto !== []) {
            $etichette = collect($inConflitto)->unique()->sort()->values()->all();

            $avvisi[] = Rilievo::avviso(
                'titolarita.conflitto_con_archivio',
                sprintf(
                    '%d %s già qualcuno collegato in archivio: %s.',
                    count($etichette),
                    count($etichette) === 1 ? 'unità aveva' : 'unità avevano',
                    implode(', ', $etichette),
                ),
                'Le righe del file non sono entrate e in archivio non è cambiato niente. '
                .'Correggi la titolarità sulla scheda dell\'unità, dove vedi le quote mentre '
                .'le cambi.',
            );
        }

        if ($senzaResto !== []) {
            $etichette = collect($senzaResto)->unique()->sort()->values()->all();

            $avvisi[] = Rilievo::avviso(
                'titolarita.quote_senza_resto',
                sprintf(
                    '%d %s nel file quote che fanno già 100 e altre persone senza quota: %s.',
                    count($etichette),
                    count($etichette) === 1 ? 'unità ha' : 'unità hanno',
                    implode(', ', $etichette),
                ),
                'Le righe di quell\'unità non sono entrate: non so quale quota dare a chi non ce l\'ha. Scrivi la quota di ognuno nel file e '
                .'ricaricalo, oppure registra le persone dalla scheda dell\'unità.',
            );
        }

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
     * Le unità che il file vorrebbe intestare a qualcuno **mentre l'archivio ha già un titolare
     * diverso** per lo stesso ruolo.
     *
     * Il confronto è per **insieme**, non per riga: se le anagrafiche del file per quell'unità e
     * quel ruolo sono tutte già in archivio, non c'è conflitto — è la reimportazione dello stesso
     * file, che deve restare un no-op silenzioso. Basta una sola anagrafica nuova su un'unità già
     * intestata perché il gruppo venga saltato per intero: intestarne metà sarebbe peggio che non
     * intestarne nessuna.
     *
     * Guarda solo le righe **attive**: una titolarità cessata non tiene occupata l'unità. È lo
     * stesso filtro che usa la lettura, e la coerenza fra i due era il quarto reperto della
     * revisione che ritirò la prima versione di questa correzione.
     *
     * @param  list<CanonicalTitolarita>  $daScrivere
     * @param  array<string, Model>  $soggetti
     * @param  array<string, Model>  $unita
     * @return array<string, string> chiave «immobile:ruolo» → etichetta dell'unità
     */
    private function unitaInConflitto(array $daScrivere, array $soggetti, array $unita): array
    {
        if ($daScrivere === []) {
            return [];
        }

        // Cosa chiede il file, raggruppato per unità e ruolo.
        $richieste = [];
        foreach ($daScrivere as $t) {
            $immobile = $unita[$t->immobileRef];
            $gruppo = $immobile->getKey().':'.$t->ruolo->value;
            $richieste[$gruppo]['immobile'] = $immobile;
            $richieste[$gruppo]['ruolo'] = $t->ruolo;
            $richieste[$gruppo]['anagrafiche'][] = $soggetti[$t->soggettoRef]->getKey();
        }

        // Cosa c'è già, in una query sola: una per unità sarebbe una query per riga di file.
        $ids = collect($richieste)->map(fn ($r) => $r['immobile']->getKey())->unique()->values()->all();

        $archivio = app(RisolutoreTitolari::class)->vincolaQuery(
            DB::table('anagrafica_immobile')->whereIn('immobile_id', $ids)
        )
            ->get(['immobile_id', 'anagrafica_id', 'tipologia'])
            ->groupBy(fn ($r) => $r->immobile_id.':'.$r->tipologia)
            ->map(fn ($righe) => $righe->pluck('anagrafica_id')->all());

        $conflitti = [];

        foreach ($richieste as $gruppo => $richiesta) {
            $giaPresenti = $archivio[$gruppo] ?? [];

            if ($giaPresenti === []) {
                continue;
            }

            $nuove = array_diff($richiesta['anagrafiche'], $giaPresenti);

            if ($nuove === []) {
                continue; // stesso file, stesse persone: no-op, non conflitto
            }

            $conflitti[$gruppo] = $this->etichettaDelGruppo($richiesta['immobile'], $richiesta['ruolo']);
        }

        return $conflitti;
    }

    /**
     * Le quote da scrivere, per ogni riga del file (Coda 599, 1.12.0-beta.14), e i gruppi (unità, ruolo) da saltare perché la quota di chi non ce l'ha
     * non si può dare.
     *
     * Per ogni gruppo del file, fuori da quelli in conflitto con l'archivio:
     * - tutte le righe con la quota: quelle, come sono (una somma diversa da 100 può essere giusta, e il parser del modello lo dice già);
     * - nessuna riga con la quota: {@see PartiUguali}, 50 e 50, 33,33 + 33,33 + 33,34;
     * - alcune sì e alcune no: le righe vuote si dividono in parti uguali il resto fino a 100 (60 scritto, la riga vuota prende 40);
     * - le quote scritte fanno già 100 (o non lasciano almeno 0,01 a ogni riga vuota): il gruppo si salta, perché dare zero a qualcuno o sforare 100
     *   sarebbe decidere al posto dell'amministratore.
     *
     * La stessa persona scritta due volte nello stesso gruppo conta una volta sola, con la quota della prima riga: è la riga che il ciclo di scrittura
     * tiene (la seconda è «già vista»).
     *
     * @param  list<CanonicalTitolarita>  $daScrivere
     * @param  array<string, Model>  $soggetti
     * @param  array<string, Model>  $unita
     * @param  array<string, string>  $inConflitto
     * @return array{0: array<string, float>, 1: array<string, string>} tripla «immobile:anagrafica:ruolo» → quota; gruppo «immobile:ruolo» → etichetta
     */
    private function quoteDelFile(array $daScrivere, array $soggetti, array $unita, array $inConflitto): array
    {
        $gruppi = [];

        foreach ($daScrivere as $t) {
            $immobile = $unita[$t->immobileRef];
            $gruppo = $immobile->getKey().':'.$t->ruolo->value;

            if (isset($inConflitto[$gruppo])) {
                continue;
            }

            $tripla = $immobile->getKey().':'.$soggetti[$t->soggettoRef]->getKey().':'.$t->ruolo->value;
            $gruppi[$gruppo]['immobile'] = $immobile;
            $gruppi[$gruppo]['ruolo'] = $t->ruolo;
            $gruppi[$gruppo]['righe'] ??= [];

            if (! array_key_exists($tripla, $gruppi[$gruppo]['righe'])) {
                $gruppi[$gruppo]['righe'][$tripla] = $t->quota;
            }
        }

        $quote = [];
        $senzaResto = [];

        foreach ($gruppi as $gruppo => $g) {
            $vuote = array_keys(array_filter($g['righe'], fn (?float $q) => $q === null));

            if ($vuote === []) {
                foreach ($g['righe'] as $tripla => $q) {
                    $quote[$tripla] = (float) $q;
                }

                continue;
            }

            $resto = 100 - array_sum(array_filter($g['righe'], fn (?float $q) => $q !== null));

            if ((int) round($resto * 100) < count($vuote)) {
                $senzaResto[$gruppo] = $this->etichettaDelGruppo($g['immobile'], $g['ruolo']);

                continue;
            }

            $parti = PartiUguali::di(count($vuote), $resto);

            foreach ($g['righe'] as $tripla => $q) {
                $quote[$tripla] = $q === null ? array_shift($parti) : (float) $q;
            }
        }

        return [$quote, $senzaResto];
    }

    /**
     * «Appartamento 1 int. 1 (proprietario)».
     *
     * Il ruolo entra nell'etichetta perché il file porta anche gli inquilini: senza, «Appartamento 1 aveva già un titolare» mandava a guardare i
     * proprietari quando a non concordare era il conduttore, e la scheda dell'unità li tiene separati.
     */
    private function etichettaDelGruppo(Model $immobile, RuoloAnagraficaImmobile $ruolo): string
    {
        $unitaEtichetta = trim(($immobile->nome ?? 'Unità').' '.($immobile->interno ? 'int. '.$immobile->interno : ''));

        return $unitaEtichetta.' ('.mb_strtolower($ruolo->label()).')';
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
     * @param  array<string, Model>  $unita
     */
    private function unitaSenzaTitolare(array $unita): int
    {
        if ($unita === []) {
            return 0;
        }

        $ids = array_map(fn ($i) => $i->getKey(), array_values($unita));

        $conTitolare = app(RisolutoreTitolari::class)->vincolaQuery(
            DB::table('anagrafica_immobile')->whereIn('immobile_id', $ids)
        )
            ->whereIn('tipologia', array_column(RuoloAnagraficaImmobile::titolariDiDirittoReale(), 'value'))
            ->distinct()
            ->count('immobile_id');

        return count($ids) - $conTitolare;
    }
}
