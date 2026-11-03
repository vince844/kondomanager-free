<?php

use App\Models\Anagrafica;
use App\Models\Evento;
use App\Models\Gestionale\Rata;
use App\Services\Gestionale\EventiRataCondomino;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Tocca il database, ma solo i dati: nessuna colonna, nessuna tabella. Riscrive la frase del promemoria del credito già salvata,
 * senza cambiare stati, importi né date.
 *
 * Dalla 1.8.0 il portale diceva a chi ha una voce a credito «l'importo verrà utilizzato automaticamente per compensare le rate
 * successive». Non è vero: il credito lo usa l'amministratore con «Usa credito» (compensazione) o lo restituisce (rimborso). La
 * frase nuova nasce già giusta nei promemoria che si creano da adesso (`EventiRataCondomino::descrizione()`); questa migrazione
 * riscrive quella dei promemoria esistenti, perché nessun movimento la riscriverebbe: la finestra del portale mostra i promemoria
 * già pagati di qualsiasi età.
 *
 * ## Che cosa tocca, e che cosa no
 *
 * - Solo gli eventi `scadenza_rata_condomino`.
 * - Solo la frase esatta, che nel testo salvato sta in due forme storiche (prima e dopo «registrato nella rata n.»), con o senza la
 *   nota della rata in coda: il resto della descrizione resta com'è. Una descrizione senza la frase esatta non si tocca; in una ritoccata a mano che la contiene ancora si riscrive la frase, e il resto resta com'è.
 * - La frase nuova dipende dal credito della persona su quella rata, che si legge dalle sue quote: se ogni quota a credito è già stata
 *   usata o restituita, la frase dice che il credito è stato usato o restituito, perché «resta a tuo favore» sarebbe falso. Senza il
 *   riferimento alla rata nel `meta` non c'è una quota da leggere e vale la frase di sempre.
 * - Solo la colonna `description`, con una `UPDATE` sul database e non dal modello: `updated_at` non cambia, e il portale mostra i
 *   promemoria pagati per trenta giorni da `updated_at` (`RecurrenceService`): toccarlo li farebbe riapparire per un mese.
 * - Il resto del testo resta quello salvato, nella forma della 1.8.0 («… (es. avanzo esercizio precedente).») come in quella con la rata:
 *   `EventiRataCondomino::applica()` riconosce entrambe, con la frase nuova e con quella del credito usato, come testo scritto dal
 *   sistema, e quando il credito si usa o si restituisce le riscrive per intero.
 *
 * ## I promemoria del caso misto scritti prima della 1.11.0-beta.52 (decisione 90.1)
 *
 * La 1.10.0 e la .51 scrivevano il pagato e il restante delle sole quote positive, senza `importo_versato`; il netto invece conta le quote a
 * credito. Su una persona che sulla rata ha quote dei due segni la finestra del portale legge quei numeri come lo stato di adesso, e sbaglia
 * fino al primo movimento sulla rata: chiede un versamento a chi ha un credito che lo copre, dice usato un credito intatto, o intatto uno
 * usato. Questi promemoria si ricalcolano una volta con lo stesso calcolo degli scrittori (`EventiRataCondomino::stato()` e `applica()`),
 * saldati compresi:
 *
 * - solo gli eventi `scadenza_rata_condomino` senza `importo_versato` (scritti prima della 1.11.0-beta.52: ogni scrittore di quella versione lo scrive), con la
 *   rata nel `meta` e una persona sola, che sulla rata ha quote attive dei due segni;
 * - la segnalazione della persona (`reported`, `rejected`) resta, e si aggiornano i numeri;
 * - il testo si riscrive solo se è ancora quello del sistema (`applica()`): per questo il riallineamento viene dopo la frase del credito;
 * - `meta` e `description` con una `UPDATE`, senza `updated_at`; una riga a registro per ogni promemoria. Rieseguita non trova niente,
 *   perché il promemoria riallineato porta `importo_versato`.
 *
 * Gli altri stati non si ricalcolano. Un promemoria già salvato come «Rata saldata» che ha ancora una quota da pagare e non è misto resta
 * com'è e si riscrive al primo movimento sulla rata: la migrazione ne scrive nel registro soltanto quanti sono, dopo il riallineamento.
 *
 * ## Idempotenza
 *
 * La `WHERE` cerca la frase vecchia: al secondo giro non trova niente. Le righe si leggono a blocchi per `id` (`chunkById`): quelle
 * riscritte escono dal filtro, e un paginatore per posizione ne salterebbe una ogni due blocchi. Ogni riga riscritta va a registro,
 * perché una riparazione dei dati che non si può ispezionare non si può nemmeno correggere a mano.
 */
return new class extends Migration
{
    private const TIPO = 'scadenza_rata_condomino';

    private const VECCHIA = "l'importo verrà utilizzato automaticamente per compensare le rate successive.";

    private const NUOVA = "il credito resta a tuo favore. L'amministratore può usarlo per compensare una tua quota da pagare o restituirtelo.";

    private const NUOVA_CREDITO_USATO = 'il credito è stato usato o restituito.';

    public function up(): void
    {
        if (! Schema::hasTable('eventi')) {
            return;
        }

        $this->riscriviLeDescrizioni();
        $this->riallineaIMistiDiPrima();
        $this->contaISaldatiConUnaQuotaDaPagare();
    }

    /**
     * Non fa niente: la frase vecchia era falsa, e rimetterla non ripara nessun dato.
     */
    public function down(): void
    {
        // Volutamente vuoto.
    }

    private function riscriviLeDescrizioni(): void
    {
        DB::table('eventi')
            ->where('description', 'like', '%'.self::VECCHIA.'%')
            ->chunkById(200, function ($righe) {
                foreach ($righe as $riga) {
                    $meta = $this->meta($riga->meta);
                    if (($meta['type'] ?? $riga->tipo) !== self::TIPO) {
                        continue;
                    }

                    $nuova = str_replace(self::VECCHIA, $this->creditoUsato($riga->id, $meta) ? self::NUOVA_CREDITO_USATO : self::NUOVA, (string) $riga->description);
                    if ($nuova === $riga->description) {
                        continue;
                    }

                    DB::table('eventi')->where('id', $riga->id)->update(['description' => $nuova]);
                    Log::info("Migrazione: descrizione del promemoria {$riga->id} riscritta, la frase del credito non promette più la compensazione automatica.");
                }
            });
    }

    /**
     * I promemoria del caso misto scritti prima della 1.11.0-beta.52 (decisione 90.1): lo stato si ricalcola dalle quote della persona, con lo stesso
     * calcolo degli scrittori. Una riga che non si riallinea lo dice nel registro e non ferma l'aggiornamento: rilanciata, la migrazione la riprova.
     */
    private function riallineaIMistiDiPrima(): void
    {
        if (! Schema::hasTable('rate_quote') || ! Schema::hasTable('anagrafica_evento') || ! Schema::hasTable('rate')) {
            return;
        }

        $servizio = app(EventiRataCondomino::class);

        DB::table('eventi')
            ->where('meta->type', self::TIPO)
            ->chunkById(200, function ($righe) use ($servizio) {
                foreach ($righe as $riga) {
                    $meta = $this->meta($riga->meta);
                    $rataId = (int) ($meta['context']['rata_id'] ?? 0);
                    // Con `importo_versato` il promemoria l'ha scritto la 1.11.0-beta.52 o una versione successiva, o l'ha già riallineato questa migrazione.
                    if (array_key_exists('importo_versato', $meta) || $rataId === 0) {
                        continue;
                    }

                    try {
                        $persone = DB::table('anagrafica_evento')->where('evento_id', $riga->id)->pluck('anagrafica_id');
                        $rata = $persone->count() === 1 ? Rata::with('rateQuote.immobile')->find($rataId) : null;
                        if ($rata === null) {
                            continue;
                        }

                        $quote = $rata->rateQuote->where('anagrafica_id', (int) $persone->first())->values();
                        $attive = $quote->filter(fn ($q) => $q->stato !== 'annullata');
                        if (! $attive->contains(fn ($q) => $q->importo > 0) || ! $attive->contains(fn ($q) => $q->importo < 0)) {
                            continue;
                        }

                        $evento = Evento::find($riga->id);
                        $servizio->applica($evento, $servizio->stato($quote), Anagrafica::find((int) $persone->first()), $rata, preservaSegnalazione: true);
                        if (! $evento->isDirty(['meta', 'description'])) {
                            continue;
                        }

                        $valori = $evento->getAttributes();
                        DB::table('eventi')->where('id', $riga->id)->update(['meta' => $valori['meta'], 'description' => $valori['description']]);
                        Log::info("Migrazione: promemoria {$riga->id} riallineato, il caso misto scritto da una versione precedente ha ora lo stato delle sue quote.");
                    } catch (\Throwable $e) {
                        Log::warning("Migrazione: promemoria {$riga->id} non riallineato: ".$e->getMessage());
                    }
                }
            });
    }

    /**
     * Il credito della persona su quella rata è già stato usato o restituito? Lo dicono le sue quote, non il promemoria: ogni quota
     * a credito (negativa, non annullata) ha pagato per intero.
     *
     * @param array<string, mixed> $meta
     */
    private function creditoUsato(int $eventoId, array $meta): bool
    {
        $rataId = (int) ($meta['context']['rata_id'] ?? 0);
        if ($rataId === 0 || ! Schema::hasTable('rate_quote') || ! Schema::hasTable('anagrafica_evento')) {
            return false;
        }

        $persone = DB::table('anagrafica_evento')->where('evento_id', $eventoId)->pluck('anagrafica_id');
        $crediti = DB::table('rate_quote')
            ->where('rata_id', $rataId)
            ->whereIn('anagrafica_id', $persone)
            ->where('importo', '<', 0)
            ->where(fn ($q) => $q->whereNull('stato')->orWhere('stato', '!=', 'annullata'))
            ->get(['importo', 'importo_pagato']);

        return $crediti->isNotEmpty() && $crediti->every(fn ($q) => abs((int) $q->importo_pagato) >= abs((int) $q->importo));
    }

    /**
     * Quanti promemoria già salvati come saldati hanno ancora una quota da pagare: un conteggio nel registro, per decidere con un
     * numero se serve un riallineamento. Non cambia niente e non può fermare l'aggiornamento: se la lettura fallisce lo dice e basta.
     */
    private function contaISaldatiConUnaQuotaDaPagare(): void
    {
        if (! Schema::hasTable('rate_quote') || ! Schema::hasTable('anagrafica_evento')) {
            return;
        }

        try {
            $quanti = 0;

            DB::table('eventi')
                ->where('meta->type', self::TIPO)
                ->where('meta->status', 'paid')
                ->chunkById(500, function ($righe) use (&$quanti) {
                    $rate = [];
                    foreach ($righe as $riga) {
                        $rataId = (int) ($this->meta($riga->meta)['context']['rata_id'] ?? 0);
                        if ($rataId !== 0) {
                            $rate[$riga->id] = $rataId;
                        }
                    }
                    if ($rate === []) {
                        return;
                    }

                    $persone = DB::table('anagrafica_evento')->whereIn('evento_id', array_keys($rate))->get(['evento_id', 'anagrafica_id']);
                    $daPagare = DB::table('rate_quote')
                        ->whereIn('rata_id', array_unique($rate))
                        ->whereIn('anagrafica_id', $persone->pluck('anagrafica_id')->unique())
                        ->where('importo', '>', 0)
                        ->whereColumn('importo_pagato', '<', 'importo')
                        ->where(fn ($q) => $q->whereNull('stato')->orWhere('stato', '!=', 'annullata'))
                        ->get(['rata_id', 'anagrafica_id'])
                        ->mapWithKeys(fn ($q) => [$q->rata_id.'-'.$q->anagrafica_id => true]);

                    $quanti += $persone->filter(fn ($p) => isset($daPagare[$rate[$p->evento_id].'-'.$p->anagrafica_id]))->pluck('evento_id')->unique()->count();
                });

            Log::info("Migrazione: promemoria già salvati come saldati con una quota ancora da pagare: {$quanti}. Restano come sono e si riscrivono al primo movimento sulla rata.");
        } catch (\Throwable $e) {
            Log::warning('Migrazione: il conteggio dei promemoria saldati con una quota da pagare non è riuscito: '.$e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(mixed $meta): array
    {
        $dati = is_string($meta) ? json_decode($meta, true) : (array) $meta;

        return is_array($dati) ? $dati : [];
    }
};
