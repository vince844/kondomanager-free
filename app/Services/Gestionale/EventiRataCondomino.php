<?php

namespace App\Services\Gestionale;

use App\Enums\CategoriaEventoEnum;
use App\Enums\EventoTipo;
use App\Enums\TipoMovimentoContabile;
use App\Enums\VisibilityStatus;
use App\Models\Anagrafica;
use App\Models\CategoriaEvento;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\PianoRate;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Il promemoria di una rata nel portale del condòmino: un `Evento` per (rata, persona), creato quando il piano è
 * approvato (`SyncScadenziarioWithPianoRate`) e poi aggiornato dagli incassi e dall'emissione.
 *
 * È uno **snapshot** delle quote di quella persona su quella rata. Finché le quote di una rata non cambiavano
 * intestatario non serviva altro; dalla B3a (1.11.0-beta.34, decisione 25) una vendita fa passare le bozze a chi
 * entra, e il promemoria deve seguire la quota: a chi esce non deve più ricordare una rata che non è sua, a chi entra
 * sì. La costruzione del promemoria sta qui, in un posto solo, per il listener e per il passaggio.
 *
 * Lo stato del promemoria (importi, `status`, `da_compensare`) si calcola in un posto solo, {@see stato()}, per tutti
 * quelli che lo scrivono: la creazione, il passaggio, la pubblicazione silenziosa e gli incassi, i rimborsi e gli storni.
 */
final class EventiRataCondomino
{
    /** Ciò che precede la frase del credito in un promemoria di credito puro, in ogni versione che l'ha scritto. */
    private const NESSUN_PAGAMENTO = 'Non è richiesto alcun pagamento: ';

    /** La frase del credito puro che chi non ha niente da versare legge adesso. */
    private const FRASE_CREDITO_RESTA = "il credito resta a tuo favore. L'amministratore può usarlo per compensare una tua quota da pagare o restituirtelo.";

    /**
     * Le frasi che la migrazione ha messo nei promemoria di credito puro già salvati, dopo {@see NESSUN_PAGAMENTO}: il credito che resta
     * a favore della persona e il credito già usato o restituito. La frase che il sistema scriveva prima non c'è: la migrazione la
     * sostituisce su ogni installazione, e nessuna frase del prodotto la ripete.
     */
    private const FRASI_DEL_CREDITO_DI_PRIMA = [
        self::FRASE_CREDITO_RESTA,
        'il credito è stato usato o restituito.',
    ];

    /**
     * Crea il promemoria di `$anagrafica` per `$rata`, dalle sue quote su quella rata.
     *
     * @param Collection<int, \App\Models\Gestionale\RataQuote> $quote le quote di quella persona su quella rata
     */
    public function crea(PianoRate $piano, Rata $rata, Anagrafica $anagrafica, Collection $quote, Condominio $condominio, int $creatoDa, string $nomeGestione, int $categoriaId, int $creditoRataZero = 0): Evento
    {
        [, $dettaglioQuote] = $this->dettaglio($quote);
        $stato = $this->stato($quote);
        $descUser = $this->descrizione($anagrafica, $rata, $stato);

        $eventoUser = Evento::create([
            'title'       => $rata->numero_rata == 0 ? "Saldo Iniziale - {$piano->nome}" : "Scadenza rata {$rata->numero_rata} - {$piano->nome}",
            'start_time'  => $rata->data_scadenza->copy()->setTime(0, 0),
            'end_time'    => $rata->data_scadenza->copy()->setTime(23, 59),
            'created_by'  => $creatoDa,
            'description' => $descUser,
            'category_id' => $categoriaId,
            'visibility'  => VisibilityStatus::PRIVATE->value,
            'is_approved' => true,
            'timezone'    => config('app.timezone'),
            'meta'        => [
                'type'              => EventoTipo::SCADENZA_RATA_CONDOMINO->value,
                'is_emitted'        => false,
                'requires_action'   => false,
                'status'            => $stato['status'],
                'importo_originale' => $stato['originale'],
                'importo_pagato'    => $stato['pagato'],
                'importo_versato'   => $stato['versato'],
                'importo_restante'  => $stato['restante'],
                'da_compensare'     => $stato['da_compensare'],
                'dettaglio_quote'   => $dettaglioQuote,
                'gestione'          => $nomeGestione,
                'condominio_nome'   => $condominio->nome,
                'numero_rata'       => $rata->numero_rata,
                'piano_nome'        => $piano->nome,
                'credito_rata_zero' => $creditoRataZero, // NUOVO CAMPO WALLET
                'context' => [
                    'piano_rate_id' => $piano->id,
                    'rata_id'       => $rata->id
                ],
            ],
            'tipo' => EventoTipo::SCADENZA_RATA_CONDOMINO,
        ]);

        $eventoUser->anagrafiche()->attach($anagrafica->id);
        $eventoUser->condomini()->attach($condominio->id);

        return $eventoUser;
    }

    /**
     * Lo stato del promemoria dalle quote **attive** della persona sulla rata (quelle non annullate): il calcolo che tutti
     * gli scrittori condividono.
     *
     * - `originale`: il netto della persona sulla rata, con il segno (un credito sottrae), dalle regole congelate di ogni quota;
     * - `pagato`: quanto le sue quote hanno pagato, con il segno: un credito usato o rimborsato è un pagato negativo, e il
     *   netto versato più il credito usato torna al netto;
     * - `versato`: il contante che la persona ha dato sulle sue quote da pagare, e solo quello ({@see versato()}): il pagato senza
     *   il credito usato su un'altra rata o restituito. Lo dicono la frase «hai versato» e la riga «Già versato» del portale;
     *   `pagato` resta il numero dei conti;
     * - `restante`: ciò che resta da versare. Con il netto positivo non scende sotto zero. Con il netto a credito (zero o negativo)
     *   resta zero o negativo, come alla creazione, finché il credito che la persona ha ancora sulla rata copre la parte da
     *   pagare; se non la copre più (il credito è stato rimborsato o usato altrove) il restante è la parte da pagare meno il
     *   credito, e la persona deve versarlo;
     * - `da_compensare`: quanto di una quota da pagare aspetta ancora il credito che la persona ha su un'altra quota della
     *   stessa rata, e che l'amministratore deve ancora usare;
     * - `status`: `paid` se ogni quota da pagare è pagata; `partial` se la persona ha versato contante, se il pagato netto è
     *   positivo o se il netto è già coperto ma una quota aspetta la compensazione; altrimenti `pending`. Il contante conta
     *   anche quando il pagato netto è zero o negativo, perché un credito uscito dalla rata lo ha ridotto.
     *
     * @param Collection<int, \App\Models\Gestionale\RataQuote> $quote le quote di quella persona su quella rata
     * @return array{originale: int, pagato: int, versato: int, restante: int, da_compensare: int, status: string}
     */
    public function stato(Collection $quote): array
    {
        $attive = $this->quoteAttive($quote);

        [$originale] = $this->dettaglio($attive);
        $pagato = (int) $attive->sum('importo_pagato');
        $daPagare = (int) $attive->filter(fn ($q) => $q->importo > 0)->sum(fn ($q) => max(0, $q->importo - $q->importo_pagato));
        $credito = (int) $attive->sum(fn ($q) => $q->credito_disponibile);

        $versato = $this->versato($attive);

        if ($originale > 0) {
            $restante = max(0, $originale - $pagato);
        } elseif ($daPagare > $credito) {
            // il netto è a credito ma il credito non copre più la parte da pagare: ciò che manca lo deve versare la persona
            $restante = $daPagare - $credito;
        } else {
            $restante = min(0, $originale - $pagato);
        }

        return [
            'originale'     => $originale,
            'pagato'        => $pagato,
            'versato'       => $versato,
            'restante'      => $restante,
            'da_compensare' => min($daPagare, $credito),
            'status'        => $daPagare === 0 ? 'paid' : (($versato > 0 || $pagato > 0 || $restante <= 0) ? 'partial' : 'pending'),
        ];
    }

    /**
     * Il contante che la persona ha versato sulle sue quote da pagare: la somma di `quota_scrittura.importo_pagato` sulle quote a
     * importo positivo, senza le scritture `storno_credito` (la compensazione con un credito: un credito usato su una quota non è
     * contante) e senza le loro rettifiche (lo storno della compensazione e il riallineamento dei crediti, figlie di una compensazione).
     * L'incasso, il suo storno e il rimborso di uno strapagamento restano dentro. Le scritture eliminate non contano, come per il pagato.
     *
     * Non la somma di `importo_pagato` delle quote positive: «Usa credito» scrive il suo importo, positivo, sulla quota da pagare, e
     * contarlo direbbe «hai versato € 25,00» a chi ne ha versati € 20,00.
     *
     * Limite: un versamento fatto da un altro per conto della persona («Versato da» nell'incasso) è contante sulle sue quote e resta contato
     * come suo; il promemoria non distingue chi ha messo i soldi. Una quota senza id (non salvata) non ha movimenti: conta zero.
     *
     * @param Collection<int, \App\Models\Gestionale\RataQuote> $attive le quote non annullate della persona su quella rata
     */
    private function versato(Collection $attive): int
    {
        $ids = $attive->filter(fn ($q) => $q->importo > 0 && $q->getKey() !== null)->map(fn ($q) => $q->getKey())->values()->all();
        if ($ids === []) {
            return 0;
        }

        $compensazione = TipoMovimentoContabile::STORNO_CREDITO->value;

        return (int) DB::table('quota_scrittura')
            ->join('scritture_contabili', 'scritture_contabili.id', '=', 'quota_scrittura.scrittura_contabile_id')
            ->leftJoin('scritture_contabili as padre', 'padre.id', '=', 'scritture_contabili.scrittura_padre_id')
            ->whereIn('quota_scrittura.rate_quota_id', $ids)
            ->whereNull('scritture_contabili.deleted_at')
            ->where('scritture_contabili.tipo_movimento', '!=', $compensazione)
            ->where(fn ($q) => $q->whereNull('padre.tipo_movimento')->orWhere('padre.tipo_movimento', '!=', $compensazione))
            ->sum('quota_scrittura.importo_pagato');
    }

    /**
     * Il testo del promemoria per uno stato; più la nota della rata, se c'è.
     *
     * Le frasi sul credito dicono ciò che il codice garantisce: la compensazione la registra l'amministratore, e il credito
     * che non è stato usato resta a favore della persona. Il credito di un'altra rata, o di un comproprietario, l'amministratore
     * può usarlo comunque e il portale non lo conta: nessuna frase dice «solo».
     *
     * Le frasi sul versamento leggono `versato`, il contante dato dalla persona; senza la chiave (uno stato di prima) vale il pagato.
     *
     * @param array{originale: int, pagato: int, versato?: int, restante: int, da_compensare?: int} $stato
     */
    public function descrizione(Anagrafica $anagrafica, Rata $rata, array $stato): string
    {
        $nome = $anagrafica->nome;
        $n = $rata->numero_rata;
        $restante = (int) $stato['restante'];
        $versato = (int) ($stato['versato'] ?? $stato['pagato']);
        $daCompensare = (int) ($stato['da_compensare'] ?? 0);
        $compensazione = "un tuo credito su un'altra tua quota di questa rata: la compensazione la registra l'amministratore.";

        $scadenza = "Gentile {$nome}, ti ricordiamo la scadenza della rata condominiale n. {$n}.\n\nVerifica il dettaglio quote e il netto da versare nello scontrino qui sotto. Dopo aver effettuato il versamento, potrai segnalarlo all'amministratore cliccando sul pulsante corrispondente.";

        if ($restante < 0 && $daCompensare === 0) {
            $testo = "Gentile {$nome}, questa voce rappresenta un credito a tuo favore registrato nella rata n. {$n}.\n\n" . self::NESSUN_PAGAMENTO . self::FRASE_CREDITO_RESTA;
        } elseif ($restante > 0 && $daCompensare === 0) {
            $testo = $scadenza;
        } elseif ($restante > 0 && $versato <= 0) {
            $testo = $scadenza . "\n\nIl netto da versare tiene conto di un credito di " . $this->euro($daCompensare) . " su un'altra tua quota di questa rata: la compensazione la registra l'amministratore.";
        } elseif ($restante > 0) {
            $testo = "Gentile {$nome}, per la rata n. {$n} hai versato " . $this->euro($versato) . " e restano " . $this->euro($restante) . " da versare. Altri " . $this->euro($daCompensare) . " di una tua quota aspettano la compensazione con un tuo credito su un'altra tua quota di questa rata: la registra l'amministratore.";
        } elseif ($daCompensare > 0) {
            $testo = $versato > 0
                ? "Gentile {$nome}, per la rata n. {$n} hai versato quanto richiesto. Restano " . $this->euro($daCompensare) . " di una tua quota da compensare con " . $compensazione
                : "Gentile {$nome}, per la rata n. {$n} non devi versare altro. Restano " . $this->euro($daCompensare) . " di una tua quota da compensare con " . $compensazione;
        } elseif ($stato['originale'] < 0) {
            $testo = "Gentile {$nome}, per la rata n. {$n} non resta niente da versare né da ricevere: il credito è stato usato o restituito.";
        } else {
            $testo = $scadenza;
        }

        if (!empty($rata->note)) $testo .= "\n\nNote: {$rata->note}";

        return $testo;
    }

    /**
     * Scrive lo stato sul promemoria (senza salvarlo): importi, `status`, `da_compensare` e, se serve, il testo.
     *
     * Il testo si riscrive solo se è ancora quello che il sistema ha scritto: per lo stato di prima, ricostruito dal `meta`, oppure
     * uno dei testi di un credito puro delle versioni passate ({@see testoDelCreditoDiPrima()}). Una descrizione ritoccata a mano
     * non si tocca.
     *
     * Con `$preservaSegnalazione` un promemoria segnalato dalla persona (`reported`) o respinto (`rejected`) conserva il suo
     * stato; i numeri si aggiornano lo stesso.
     *
     * @param array{originale: int, pagato: int, versato?: int, restante: int, da_compensare: int, status: string} $stato
     */
    public function applica(Evento $evento, array $stato, ?Anagrafica $anagrafica, Rata $rata, bool $preservaSegnalazione = false): void
    {
        $meta = $evento->meta ?? [];

        $pagatoPrima = (int) ($meta['importo_pagato'] ?? 0);
        $prima = [
            'originale'     => (int) ($meta['importo_originale'] ?? 0),
            'pagato'        => $pagatoPrima,
            // un promemoria salvato prima non ha il versato: allora la frase leggeva il pagato
            'versato'       => (int) ($meta['importo_versato'] ?? $pagatoPrima),
            'restante'      => (int) ($meta['importo_restante'] ?? 0),
            'da_compensare' => (int) ($meta['da_compensare'] ?? 0),
        ];
        if ($anagrafica !== null && $this->scrittaDalSistema((string) $evento->description, $anagrafica, $rata, $prima)) {
            $evento->description = $this->descrizione($anagrafica, $rata, $stato);
        }

        $meta['importo_originale'] = $stato['originale'];
        $meta['importo_pagato'] = $stato['pagato'];
        $meta['importo_versato'] = $stato['versato'] ?? $stato['pagato'];
        $meta['importo_restante'] = $stato['restante'];
        $meta['da_compensare'] = $stato['da_compensare'];
        if (! ($preservaSegnalazione && in_array($meta['status'] ?? null, ['reported', 'rejected'], true))) {
            $meta['status'] = $stato['status'];
        }

        $evento->meta = $meta;
    }

    /**
     * La descrizione è ancora quella scritta dal sistema: quella che avrebbe scritto per `$stato`, o il testo di un credito puro
     * di una versione passata.
     *
     * @param array{originale: int, pagato: int, versato: int, restante: int, da_compensare: int} $stato
     */
    private function scrittaDalSistema(string $descrizione, Anagrafica $anagrafica, Rata $rata, array $stato): bool
    {
        return $descrizione === $this->descrizione($anagrafica, $rata, $stato) || $this->testoDelCreditoDiPrima($descrizione, $anagrafica, $rata);
    }

    /**
     * La descrizione è il testo di un credito puro che una versione passata ha scritto e la migrazione ha lasciato (o corretto)?
     *
     * Le teste sono due: la 1.8.0 («… (es. avanzo esercizio precedente).», rilasciata con una riga vuota dopo il punto, e in una versione
     * intermedia con un solo a capo) e quella con la rata («… registrato nella rata n. N.», dalla 1.9.0). A ciascuna segue una delle
     * {@see FRASI_DEL_CREDITO_DI_PRIMA}, e in coda la nota della rata se c'è. Sono le frasi con cui la migrazione ha riscritto i testi,
     * senza toccare il resto: senza questo confronto un credito usato o restituito dopo la migrazione resterebbe «a tuo favore».
     */
    private function testoDelCreditoDiPrima(string $descrizione, Anagrafica $anagrafica, Rata $rata): bool
    {
        $nome = $anagrafica->nome;
        $teste = [
            "Gentile {$nome}, questa voce rappresenta un credito a tuo favore (es. avanzo esercizio precedente).\n\n",
            "Gentile {$nome}, questa voce rappresenta un credito a tuo favore (es. avanzo esercizio precedente).\n",
            "Gentile {$nome}, questa voce rappresenta un credito a tuo favore registrato nella rata n. {$rata->numero_rata}.\n\n",
        ];
        $coda = empty($rata->note) ? '' : "\n\nNote: {$rata->note}";

        foreach ($teste as $testa) {
            foreach (self::FRASI_DEL_CREDITO_DI_PRIMA as $frase) {
                if ($descrizione === $testa . self::NESSUN_PAGAMENTO . $frase . $coda) {
                    return true;
                }
            }
        }

        return false;
    }

    private function euro(int $centesimi): string
    {
        return '€ ' . number_format($centesimi / 100, 2, ',', '.');
    }

    /**
     * Il totale e le righe «scontrino» delle quote, dalle regole congelate di ciascuna.
     *
     * @param Collection<int, \App\Models\Gestionale\RataQuote> $quote
     * @return array{0: int, 1: list<array{descrizione: string, importo: int, componente_spesa: int, componente_saldo: int}>}
     */
    public function dettaglio(Collection $quote): array
    {
        $importoVal = 0;

        $dettaglioQuote = $quote->map(function ($q) use (&$importoVal) {
            $immobile = $q->immobile;
            // `etichetta`: senza interno restava «Int.  (Posto auto 3)», con la parentesi orfana.
            // `etichettaEstesa`: qui prima si mostravano interno **e** nome, e applicare la sola
            // `etichetta` avrebbe tolto il nome a ogni installazione esistente (ripasso .58).
            $desc = $immobile ? $immobile->etichettaEstesa : "Unità";

            $componenteSpesa = $q->importo;
            $componenteSaldo = 0;
            $totaleCalcolato = $q->importo;

            // FIX: regole_calcolo è già un array grazie al cast nel modello
            $meta = is_string($q->regole_calcolo) ? json_decode($q->regole_calcolo, true) : $q->regole_calcolo;

            if (is_array($meta)) {
                $componenteSpesa = $meta['importi']['quota_pura_gestione'] ?? $meta['audit']['quota_pura'] ?? $q->importo;
                $componenteSaldo = $meta['importi']['saldo_usato'] ?? $meta['audit']['saldo_usato'] ?? 0;
                $totaleCalcolato = $meta['importi']['totale_calcolato'] ?? ($componenteSpesa + $componenteSaldo);
            }

            $importoVal += $totaleCalcolato;

            return [
                'descrizione' => $desc,
                'importo' => $totaleCalcolato,
                'componente_spesa' => $componenteSpesa,
                'componente_saldo' => $componenteSaldo,
            ];
        })->values()->toArray();

        return [(int) $importoVal, $dettaglioQuote];
    }

    /**
     * Dopo che le quote di alcune rate hanno cambiato intestatario (decisione 25), i promemoria delle persone
     * coinvolte si rimettono in pari con le quote, rata per rata:
     *
     * - chi non ha più quote su quella rata perde il promemoria;
     * - chi le ha e aveva già un promemoria lo ritrova con i nuovi importi (il pagato è quello delle sue quote);
     * - chi le ha e non lo aveva lo riceve, **solo se la rata ha già promemoria nel portale** — cioè il piano è stato
     *   approvato con il listener. Altrimenti li creerà l'approvazione, come per tutti.
     *
     * @param list<int> $rataIds
     * @param list<int> $anagraficaIds
     */
    public function seguonoLeQuote(array $rataIds, array $anagraficaIds, User $utente): void
    {
        $rate = Rata::with(['pianoRate.gestione', 'pianoRate.condominio', 'rateQuote.anagrafica', 'rateQuote.immobile'])->findMany($rataIds);
        $categoria = null;

        foreach ($rate as $rata) {
            $eventi = Evento::where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)
                ->whereJsonContains('meta->context->rata_id', $rata->id)
                ->with('anagrafiche:id')
                ->get();
            if ($eventi->isEmpty()) {
                continue; // il piano non ha (ancora) promemoria nel portale: li crea l'approvazione
            }
            $piano = $rata->pianoRate;

            foreach ($anagraficaIds as $anagraficaId) {
                $quote = $rata->rateQuote->where('anagrafica_id', $anagraficaId)->where('stato', '!=', 'annullata')->values();
                $suo = $eventi->first(fn (Evento $e) => $e->anagrafiche->contains('id', $anagraficaId));

                if ($quote->isEmpty()) {
                    $suo?->delete();
                    continue;
                }

                if ($suo !== null) {
                    [, $dettaglio] = $this->dettaglio($quote);
                    $meta = $suo->meta ?? [];
                    $meta['dettaglio_quote'] = $dettaglio;
                    $suo->meta = $meta;
                    $this->applica($suo, $this->stato($quote), $quote->first()->anagrafica, $rata);
                    $suo->save();
                    continue;
                }

                $anagrafica = $quote->first()->anagrafica;
                if ($anagrafica === null || $piano === null) {
                    continue;
                }
                $categoria ??= CategoriaEvento::firstOrCreate(['name' => CategoriaEventoEnum::SCADENZE_RATE_CONDOMINIALI->value], ['description' => 'Auto']);
                $this->crea($piano, $rata, $anagrafica, $quote, $piano->condominio, $utente->id, $piano->gestione->nome ?? 'Gestione', $categoria->id);
            }
        }

        InboxService::clearAdminCache();
    }

    /**
     * Le coppie (rata, persona) che una scrittura ha **davvero** toccato: le quote pagate dalla scrittura e dalle sue figlie
     * (la compensazione del credito), a credito comprese. Un credito speso su un'altra rata, o rimborsato, cambia il netto
     * di quella rata come un versamento.
     *
     * Dalla 1.11.0-beta.40 (Coda 167) un incasso tocca anche la quota di chi ha versato per un altro, che riceve la parte
     * in più: le coppie non si ricavano più dal pagante e dal payload. Coppie esatte, e non il prodotto rate × persone:
     * il prodotto ricalcolava promemoria di persone che l'incasso non aveva toccato, e sovrascriveva uno «reported»
     * segnalato dal portale (reperti R3 e S8 della Fase 1-bis della .40).
     *
     * Il terzo elemento dice se la coppia è stata toccata da una quota a importo positivo, cioè da un versamento o da uno
     * storno di un versamento: solo allora la segnalazione della persona (`reported`, `rejected`) si riscrive, come sempre.
     * Quando la scrittura ha toccato solo il suo credito (un rimborso, un credito usato) la segnalazione resta com'è e si
     * aggiornano i numeri.
     *
     * @return Collection<int, array{0:int, 1:int, 2:bool}>
     */
    public function coppieToccate(\App\Models\Gestionale\ScritturaContabile $scrittura): Collection
    {
        $scrittura->loadMissing(['quotePagate', 'figlie.quotePagate']);

        return $scrittura->quotePagate
            ->merge($scrittura->figlie->flatMap->quotePagate)
            ->groupBy(fn ($q) => (int) $q->rata_id . '-' . (int) $q->anagrafica_id)
            ->map(fn ($gruppo) => [(int) $gruppo->first()->rata_id, (int) $gruppo->first()->anagrafica_id, $gruppo->contains(fn ($q) => $q->importo > 0)])
            ->values();
    }

    /**
     * Riallinea importi, stato e testo dei promemoria delle coppie date, dalle quote come sono adesso. Lo usano l'incasso, il
     * rimborso e lo storno dell'uno e dell'altro: la stessa regola in tutti i versi.
     *
     * @param  Collection<int, array{0:int, 1:int, 2?:bool}>  $coppie
     */
    public function allineaPagato(Collection $coppie): void
    {
        foreach ($coppie as $coppia) {
            [$rataId, $anagraficaId] = $coppia;
            $toccataDaUnVersamento = $coppia[2] ?? true;

            $rata = Rata::with('rateQuote.immobile')->find($rataId);
            if (! $rata) {
                continue;
            }

            $anagrafica = Anagrafica::find($anagraficaId);
            $stato = $this->stato($rata->rateQuote->where('anagrafica_id', $anagraficaId)->values());

            $eventi = Evento::where('meta->type', EventoTipo::SCADENZA_RATA_CONDOMINO->value)
                ->where(fn ($q) => $q->where('meta->context->rata_id', $rataId)->orWhere('meta->context->rata_id', (string) $rataId))
                ->whereHas('anagrafiche', fn ($q) => $q->where('anagrafica_id', $anagraficaId))
                ->get();

            foreach ($eventi as $evento) {
                $this->applica($evento, $stato, $anagrafica, $rata, preservaSegnalazione: ! $toccataDaUnVersamento);
                $evento->save();
            }
        }
    }

    /**
     * Una rata è saldata quando **ogni** sua quota a importo positivo è pagata — non quando la somma del pagato raggiunge
     * la somma del dovuto: lo strapagamento di uno compensava nella somma il debito di un altro, e la «verifica incassi»
     * si chiudeva mentre qualcuno doveva ancora (reperto S7 della Fase 1-bis della .40). Le quote di credito, negative,
     * non contano, e nemmeno quelle annullate. Lo stato del promemoria ({@see stato()}) lascia fuori solo le annullate, ma dice `paid`
     * con lo stesso criterio (`$daPagare` somma le sole quote positive non pagate): le due risposte restano concordi.
     */
    public function rataSaldata(Rata $rata): bool
    {
        return $this->quoteAttive($rata->rateQuote()->where('importo', '>', 0)->get())
            ->every(fn ($q) => $q->importo_pagato >= $q->importo);
    }

    /**
     * Le quote che contano: quelle non annullate. Il filtro è qui, in un posto solo, per lo stato del promemoria e per la verifica
     * incassi (una quota senza stato conta).
     *
     * @param Collection<int, \App\Models\Gestionale\RataQuote> $quote
     * @return Collection<int, \App\Models\Gestionale\RataQuote>
     */
    private function quoteAttive(Collection $quote): Collection
    {
        return $quote->filter(fn ($q) => $q->stato !== 'annullata')->values();
    }
}
