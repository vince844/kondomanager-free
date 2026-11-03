<?php

namespace App\Services\Import\Livelli;

use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\ImportBatchItem;
use App\Services\Import\Canonical\CanonicalCapitolo;
use App\Services\Import\Canonical\CanonicalStrutturaSpese;
use App\Services\Import\EsitoCommit;
use App\Services\Import\ImportContext;
use App\Services\Import\LivelloImport;
use App\Services\Import\PrerequisitoMancante;
use App\Services\Import\Rilievo;

/**
 * Livello 3 — i capitoli di spesa, cioè la struttura su cui si scriverà il preventivo.
 *
 * È il livello che il §7 di `modelli_import_manuale.md` chiamava «il buco»: senza, il canonico
 * non arrivava ai capitoli e il foglio 4 del modello manuale chiedeva all'amministratore un
 * elenco che nessun committer sapeva scrivere.
 *
 * ## Terzo e non ottavo, ed è una scelta misurata
 *
 * `ImportRunner::esegui()` fa `return` al **primo** livello che non passa. Mettendo questo in
 * coda lo si nasconderebbe dietro «Saldi di apertura», che è il livello che si ferma più spesso
 * di tutti — proprio a chi ha i file incompleti, cioè proprio a chi la struttura delle spese
 * servirebbe di più. Le sue dipendenze sono soddisfatte al secondo livello, quindi qui sta bene.
 *
 * Il rovescio della medaglia è che, stando terzo, un suo blocco fermerebbe **tutto il resto**.
 * Per questo il livello **non blocca mai**: se il bilancio non c'è non emette nessun
 * prerequisito mancante, e i problemi di contenuto li segnala il parser come avvisi. È
 * l'importazione di una struttura, non di denaro: sbagliarla non sporca nessun saldo.
 *
 * ## Cosa scrive, e cosa deliberatamente non scrive
 *
 * Scrive `conti`, che è la struttura di **budget** — capitoli e voci — appesa a un `PianoConto`,
 * non `conti_contabili`, che è il piano della partita doppia. Sono due alberi diversi e
 * confonderli metterebbe le voci di spesa dove nessuna schermata le cerca.
 *
 * ⚠️ **Gli importi entrano a zero, e non è una dimenticanza.** `conti.importo` è il
 * **fabbisogno** — quello che si chiede ai condòmini — mentre un consuntivo è la fotografia di
 * quanto si è speso l'anno prima. Scriverci dentro il consuntivo significherebbe deliberare un
 * preventivo che nessuna assemblea ha approvato. La cifra letta finisce invece nella
 * `descrizione`, che nessun calcolo legge: serve all'amministratore come riferimento nel momento
 * in cui scriverà il preventivo vero.
 */
final class LivelloCapitoli implements LivelloImport
{
    public const CHIAVE = 'capitoli';

    public function chiave(): string
    {
        return self::CHIAVE;
    }

    public function etichetta(): string
    {
        return 'Capitoli di spesa';
    }

    public function dipendeDa(): array
    {
        return [LivelloCondominio::CHIAVE, LivelloEsercizi::CHIAVE];
    }

    /**
     * ⚠️ **Nessun `capitoli.dati_assenti`, di proposito.**
     *
     * Gli altri livelli dichiarano mancante il file che li riguarda, e fanno bene: senza l'elenco
     * unità non c'è importazione. Qui no. Il bilancio consuntivo è un file **facoltativo** — la
     * stragrande maggioranza delle importazioni fatte finora non ce l'ha — e dichiararlo mancante
     * trasformerebbe in «si è fermata a Capitoli di spesa» ogni importazione che oggi arriva in
     * fondo. Stando questo livello **terzo**, si fermerebbero anche unità, persone e saldi.
     */
    public function verificaPrerequisiti(ImportContext $ctx): array
    {
        $mancanti = [];

        $condominio = $ctx->risolto(LivelloCondominio::CHIAVE);

        // Non basta che il livello precedente dica di essere andato bene: si controlla che la
        // riga esista **adesso**, a database. È il gate di integrità, come negli altri livelli.
        if ($ctx->canonico(self::CHIAVE) instanceof CanonicalStrutturaSpese
            && (! $condominio instanceof Condominio || ! Condominio::whereKey($condominio->getKey())->exists())
        ) {
            $mancanti[] = new PrerequisitoMancante(
                'capitoli.condominio_mancante',
                'I capitoli di spesa non possono entrare: il condominio a cui appartengono non è in archivio.',
                'Importa prima il livello «Condominio»: un piano dei conti senza condominio non è '
                .'collegabile a niente.',
            );
        }

        return $mancanti;
    }

    public function commit(ImportContext $ctx): EsitoCommit
    {
        $struttura = $ctx->canonico(self::CHIAVE);

        if (! $struttura instanceof CanonicalStrutturaSpese) {
            // Nessun bilancio consuntivo nel lotto: è il caso normale, non un problema.
            // `giaAPosto()` è calcolato — riuscito e zero creati — non un parametro.
            return new EsitoCommit;
        }

        /** @var Condominio $condominio */
        $condominio = $ctx->risolto(LivelloCondominio::CHIAVE);
        $esercizio = $ctx->risolto(LivelloEsercizi::CHIAVE);

        // ── Primo giro: si legge soltanto ──
        //
        // ⚠️ **Tutto ciò che può bloccare va risolto prima di scrivere una sola riga.**
        // `EsitoCommit::bloccato()` è un **ritorno normale**, non un'eccezione: la
        // `DB::transaction` che `ImportRunner` avvolge attorno a questo metodo non viene
        // annullata, quindi qualunque cosa scritta prima del blocco **resta in archivio** — e
        // resta non registrata, cioè invisibile al rapporto e a un futuro annullamento.
        $gestione = $this->gestioneDi($ctx, $esercizio);

        if ($gestione === null) {
            return EsitoCommit::bloccato(Rilievo::errore(
                'capitoli.gestione_assente',
                'L\'esercizio non ha nessuna gestione a cui appendere il piano dei conti.',
                'La gestione è il contenitore in cui vivono i capitoli di spesa. Di norma la crea '
                .'l\'importazione insieme all\'esercizio: se sei arrivato qui, creane una ordinaria '
                .'dal condominio e ricarica il file.',
            ));
        }

        $piano = $gestione->pianoConto;

        // I nomi già presenti nel piano, in minuscolo: il confronto è sul nome perché è l'unica
        // cosa che il file porta. Se il piano non esiste ancora, non c'è niente da confrontare.
        $esistenti = $piano === null
            ? []
            : Conto::where('piano_conto_id', $piano->id)
                ->whereNull('parent_id')
                ->pluck('nome')
                ->map(fn (string $n) => mb_strtolower(trim($n)))
                ->all();

        // ── Secondo giro: si scrive ──
        $piano ??= PianoConto::create([
            'gestione_id' => $gestione->id,
            'condominio_id' => $condominio->id,
            // ⚠️ **Senza l'anno nel nome.** Il piano dei conti appartiene alla **gestione**, non a
            // un esercizio: ce n'è uno solo, ed è quello vivo. Chiamarlo «Preventivo 2024/2025» lo
            // dichiarerebbe di un anno che non è il suo, e l'anno dopo mentirebbe.
            'nome' => 'Piano dei conti',
            'descrizione' => 'Struttura importata dal bilancio consuntivo del vecchio gestionale.',
        ]);

        $creati = 0;
        $saltati = 0;
        $avvisi = [];

        foreach ($struttura->capitoli as $capitolo) {
            if ($capitolo->voci === []) {
                // Un capitolo senza voci non è un contenitore: `is_capitolo = true` su un nodo
                // vuoto è una porta chiusa — non si può budgettare né collegare a una tabella —
                // e `is_capitolo = false` senza tabella millesimale è una voce che il motore non
                // sa ripartire. Meglio non scriverlo e dirlo.
                $avvisi[] = Rilievo::avviso(
                    'capitoli.senza_voci',
                    sprintf('«%s» non ha nessuna voce di spesa sotto di sé: non l\'ho importato.', $capitolo->nome),
                    'Un capitolo vuoto non si può né preventivare né ripartire. Se ti serve, '
                    .'crealo dal piano dei conti con le sue voci.',
                );

                $saltati++;

                continue;
            }

            if (in_array(mb_strtolower(trim($capitolo->nome)), $esistenti, true)) {
                $saltati++;

                continue;
            }

            $creati += $this->scrivi($ctx, $piano, $capitolo);
        }

        // L'avviso sull'ordine, che è l'unica cosa che l'amministratore deve sapere adesso.
        if ($creati > 0) {
            $avvisi[] = Rilievo::avviso(
                'capitoli.senza_preventivo',
                sprintf('%d %s entrat%s senza importi.', $creati, $creati === 1 ? 'voce è' : 'voci sono', $creati === 1 ? 'a' : 'e'),
                'Ho importato la struttura delle spese, non i numeri: quelli di un consuntivo sono '
                .'la fotografia dell\'anno scorso, non il preventivo di quest\'anno. Trovi la cifra '
                .'dell\'anno scorso nella descrizione di ogni voce. Scrivi il preventivo dal piano '
                .'dei conti prima di emettere il piano rate, perché dopo le voci si bloccano.',
            );
        }

        return new EsitoCommit(creati: $creati, saltati: $saltati, avvisi: $avvisi);
    }

    /**
     * Scrive il capitolo e le sue voci, e restituisce quante righe ha creato.
     */
    private function scrivi(ImportContext $ctx, PianoConto $piano, CanonicalCapitolo $capitolo): int
    {
        $padre = Conto::create([
            'piano_conto_id' => $piano->id,
            'parent_id' => null,
            'is_capitolo' => true,
            'nome' => $capitolo->nome,
            'descrizione' => $this->riferimento($capitolo->totaleDichiaratoCents ?? $capitolo->sommaVociCents()),
            'tipo' => 'spesa',
            // Zero, non il consuntivo: vedi la nota in testa alla classe.
            'importo' => 0,
        ]);

        $ctx->registra(self::CHIAVE, ImportBatchItem::AZIONE_CREATO, $padre);

        $creati = 1;

        foreach ($capitolo->voci as $voce) {
            $figlio = Conto::create([
                'piano_conto_id' => $piano->id,
                'parent_id' => $padre->id,
                'is_capitolo' => false,
                'nome' => $voce->nome,
                'descrizione' => $this->riferimento($voce->importoCents),
                'tipo' => 'spesa',
                'importo' => 0,
            ]);

            $ctx->registra(self::CHIAVE, ImportBatchItem::AZIONE_CREATO, $figlio);

            $creati++;
        }

        return $creati;
    }

    /**
     * La cifra del consuntivo, scritta dove nessun calcolo la legge.
     *
     * È l'unico numero che questa stampa porta, e senza un posto in cui metterlo sparirebbe alla
     * conferma: l'anteprima lo mostra e poi non esiste più. `descrizione` è un campo di testo
     * libero che né il motore di riparto né le guardie del piano dei conti guardano, quindi ci
     * sta senza rischiare di diventare un preventivo per sbaglio.
     */
    private function riferimento(int $cents): string
    {
        return sprintf('Consuntivo importato: %s', MoneyHelper::format($cents));
    }

    /**
     * La gestione su cui appendere il piano dei conti.
     *
     * Si preferisce quella che `LivelloEsercizi` ha dichiarato di aver usato: con due gestioni —
     * ordinaria e straordinaria — prendere la prima che capita significherebbe scrivere i
     * capitoli di un consuntivo straordinario dentro l'ordinaria.
     */
    private function gestioneDi(ImportContext $ctx, mixed $esercizio): mixed
    {
        $dichiarata = $ctx->risolto(LivelloEsercizi::GESTIONE);

        if ($dichiarata !== null) {
            return $dichiarata;
        }

        if (! $esercizio instanceof Esercizio) {
            return null;
        }

        return $esercizio->gestioni()->orderBy('id')->first();
    }
}
