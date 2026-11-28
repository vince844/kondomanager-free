<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Enums\TipoAllocazioneFattura;
use App\Enums\TipoMovimentoContabile;
use App\Helpers\DateHelper;
use App\Helpers\MoneyHelper;
use App\Models\Assemblee\Delibera;
use App\Models\Evento;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\FatturaCopertura;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RispostaPromemoria;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use App\Models\Tabella;
use App\Models\User;
use App\Services\Assemblee\ChiusuraDeiCompiti;
use App\Services\RegistroModifiche\Gesto;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Coda 743 (decisioni 655 e 676 (1)): «Prepara il piano di ripianamento» del compito «Deficit da recuperare», dall'Action inbox e dal seguito della delibera.
 *
 * Il debito ereditato verso un fornitore è chiuso dalla fattura pregressa sul Fondo Passate Gestioni (la copertura «rata 0»); la parte che i pregressi chiesti
 * ai condòmini non coprono è lo scoperto, che nessuna rata chiede. Il ripianamento fa con la pregressa già registrata **lo stesso gesto** della registrazione
 * divisa (rata 0 + sopravvenienza, `FatturaPassivaService::registraFattura`), tre cose insieme:
 *
 * - la voce «Ripianamento del debito verso …», tecnica, con la tabella scelta e a carico dei proprietari (il motore riparte la pregressa da qui). Una voce
 *   con la stessa tabella si riusa, e così una rimasta senza coperture dopo un piano eliminato: non nascono voci a € 0 una dopo l'altra;
 * - la copertura «sopravvenienza» della cifra, con il segno del piano ({@see segno()}), e la «rata 0» che scende della stessa cifra (le coperture sommano
 *   sempre il totale: lo storno le rilegge). Il motore riparte per ogni piano solo le coperture con il suo segno: due ripianamenti con due tabelle non si
 *   mescolano (`CalcoloQuoteService::calcolaDaFattureStraordinarie`);
 * - la scrittura del ripianamento (`TipoMovimentoContabile::RIPIANAMENTO_DEBITO`): DARE Sopravvenienze passive (con la voce) / AVERE Fondo Passate Gestioni,
 *   legata alla fattura con il legame `rettifica`, che il rendiconto legge come una parte del costo della fattura: «da pagare» finché il fornitore non è
 *   pagato (`PagatoAllaData`). Annullata, il suo storno (`STORNO_RIPIANAMENTO_DEBITO`): non una rettifica, che il programma legge come lo storno di un incasso.
 *
 * **Si scrive solo quando si salva il piano**, nella stessa transazione ({@see scrivi()}): niente copertura senza piano (decisione 676 (1)). Eliminato il piano,
 * si toglie ({@see togli()}).
 *
 * **Quanto.** Lo scoperto si legge **al momento** ({@see scopertoDiOggi()}), non dal compito, che lo fotografa alla registrazione della pregressa: i debiti
 * ereditati verso i fornitori dell'esercizio, meno i pregressi chiesti ai condòmini (anche quelli scritti dopo la pregressa), meno quanto le pregresse di quei
 * debiti hanno già coperto altrimenti (le sopravvenienze, della registrazione o di un ripianamento, e il fondo di riserva). Con più debiti ereditati lo
 * scoperto è uno solo per tutti: il programma non sa quale debito coprono i pregressi, e la cifra di ogni debito la decide l'amministratore, al massimo la sua
 * pregressa ancora a carico delle passate gestioni. Oltre lo scoperto i condòmini pagherebbero due volte la parte che i pregressi già chiedono (€ 2.800,00 per
 * un debito di € 2.000,00, nella prova). Il disavanzo che resta lo ricordano i compiti del deficit, ognuno con la sua parte ({@see allineaIDeficit()}). Lo
 * scoperto non vede il denaro ereditato in banca: la pagina lo dice. Chi legge la cifra del compito (l'Action inbox, la tesoreria, l'ordine del giorno) la legge
 * oggi ({@see cifraDiOggi()}), come la pagina. I pregressi scritti dopo un ripianamento possono rendere il piano più grande dello scoperto: il programma non lo
 * ricalcola, lo dice alla scrittura del pregresso ({@see dopoUnSaldo()}) e nel compito, che torna aperto. Tutto in centesimi interi.
 *
 * **Dove.** Il piano del ripianamento si prepara nell'esercizio della pregressa, sulla sua gestione: il rendiconto di quell'esercizio legge il pagato del
 * fornitore anche sulla parte messa a rate (`PagatoAllaData`), quello di un altro esercizio no. Altrove il programma si ferma e lo dice ({@see altrove()}).
 */
final class RipianamentoDelDeficit
{
    public const TIPO_DEL_COMPITO = 'pianifica_ripianamento_deficit';

    /** L'inizio del segno delle coperture e della causale della scrittura: li riconosce {@see togli()}. */
    public const CAUSALE = 'Ripianamento del debito ereditato';

    /**
     * La proposta del compito per la pagina del nuovo piano e per la richiesta: null se il compito non è un deficit aperto del condominio, o se non resta niente
     * da ripianare. Con `$esercizioId` (l'esercizio della pagina o del piano) `altrove` dice perché lì il ripianamento non si prepara, nullo se si prepara.
     * Senza niente da ripianare ma con il debito a rate oltre lo scoperto (i pregressi scritti dopo il ripianamento) la proposta c'è, e `altrove` lo dice
     * ({@see fraseDellOltre()}): la pagina e la richiesta si fermano.
     *
     * @return ?array{compito_id: int, fornitore: string, voce: string, scoperto: int, debiti: int, pregressi: int, coperto: int, piu_debiti: bool, ripianato: int,
     *   massimo: int, proposto: int, limite: string, frase_del_massimo: string, gestione_id: int, gestione: string, esercizio_id: int, esercizio: string,
     *   esercizio_aperto: bool, altrove: ?string, fatture: list<array{id: int, numero: string, rata_0: int}>, tabelle: list<array{id: int, nome: string}>}
     */
    public static function proposta(int $condominioId, int $compitoId, ?int $esercizioId = null): ?array
    {
        $compito = self::compito($condominioId, $compitoId);
        if ($compito === null || $compito->is_completed) {
            return null;
        }
        $limiti = self::limiti($condominioId, $compito);
        if ($limiti === null) {
            return null;
        }
        if ($limiti['massimo'] <= 0) {
            // Niente da ripianare, ma il debito è a rate oltre lo scoperto (i pregressi scritti dopo il ripianamento): la pagina aperta dal compito e la
            // richiesta si fermano e lo dicono, senza i campi; il piano non si ricalcola.
            $diviso = self::partiDiOggi($condominioId, (int) (((array) (((array) $compito->meta)['context'] ?? []))['saldo_id'] ?? 0));
            if (($diviso['oltre'][(int) $compito->id] ?? 0) <= 0) {
                return null;
            }
            $limiti['altrove'] = self::fraseDellOltre($condominioId, $diviso['oggi']);

            return $limiti;
        }
        $limiti['altrove'] = $esercizioId === null ? null : self::altrove($limiti, $esercizioId);

        return $limiti;
    }

    /**
     * La cifra di oggi di un compito del deficit, per chi la legge fuori dalla pagina del piano: l'Action inbox («Scoperto di …»), la tesoreria e il punto
     * proposto dell'ordine del giorno. È la sua parte dello scoperto di oggi ({@see partiDiOggi()}), come la pagina e la richiesta: il compito la scrive
     * quando nasce e quando un piano del ripianamento si salva o si elimina, e un pregresso scritto dopo la cambia. `oltre` è quanto il suo debito è a rate
     * oltre lo scoperto. Null se il compito non si legge oggi (senza il debito o senza la pregressa): chi legge usa la cifra scritta nel compito.
     *
     * @param  array<string, mixed>  $meta  i dati del compito
     * @return ?array{parte: int, oltre: int}
     */
    public static function cifraDiOggiDi(int $condominioId, int $compitoId, array $meta): ?array
    {
        $saldoId = (int) (((array) ($meta['context'] ?? []))['saldo_id'] ?? 0);
        if ($saldoId <= 0 || self::fattureDelDebito($condominioId, $saldoId)->isEmpty()) {
            return null;
        }
        $diviso = self::partiDiOggi($condominioId, $saldoId);

        return ['parte' => $diviso['parti'][$compitoId] ?? 0, 'oltre' => $diviso['oltre'][$compitoId] ?? 0];
    }

    /** {@see cifraDiOggiDi()} per il compito, nel suo condominio. @return ?array{parte: int, oltre: int} */
    public static function cifraDiOggi(Evento $compito): ?array
    {
        $condominioId = $compito->relationLoaded('condomini')
            ? $compito->condomini->sortBy('id')->first()?->id
            : $compito->condomini()->orderBy('condomini.id')->value('condomini.id');

        return $condominioId === null ? null : self::cifraDiOggiDi((int) $condominioId, (int) $compito->id, (array) $compito->meta);
    }

    /**
     * La riga sotto il titolo del compito nell'Action inbox: lo scoperto di oggi ({@see cifraDiOggi()}), o quanto il debito è a rate oltre lo scoperto; senza
     * una cifra che si legga oggi, quella scritta nel compito; senza cifra, vuota.
     */
    public static function sottoDelCompito(Evento $compito): string
    {
        $oggi = self::cifraDiOggi($compito);
        if ($oggi !== null && $oggi['oltre'] > 0) {
            return MoneyHelper::format($oggi['oltre']) . ' a rate oltre lo scoperto: i condòmini li pagherebbero due volte';
        }
        $cifra = $oggi['parte'] ?? (((array) $compito->meta)['importo_buco'] ?? null);

        return is_numeric($cifra) ? 'Scoperto di ' . MoneyHelper::format((int) $cifra) : '';
    }

    /**
     * Dopo un saldo iniziale scritto, modificato o eliminato dal Wallet. Se i piani di ripianamento dell'esercizio mettono a rate più dello scoperto di oggi (i
     * pregressi scritti dopo il ripianamento), il compito del debito torna aperto se lo aveva chiuso il programma, e la frase con i numeri va nell'avviso; il
     * piano non cambia, che fare lo decide l'amministratore. Nell'altro verso (un pregresso tolto o abbassato dopo il ripianamento) lo scoperto torna più
     * grande del ripianato: i compiti si allineano ({@see allineaIDeficit()}), quello chiuso dal programma si riapre con la parte che nessuno chiede, e la
     * frase lo dice ({@see fraseDelloScopertoRiaperto()}). Con il compito chiuso dall'amministratore («Fatto», «Non serve») l'avviso tace: è una sua
     * decisione. Null se non c'è niente da dire.
     */
    public static function dopoUnSaldo(int $condominioId, ?int $esercizioId): ?string
    {
        if ($esercizioId === null) {
            return null;
        }
        $debiti = DB::table('saldi')->where('condominio_id', $condominioId)->where('esercizio_id', $esercizioId)->whereNotNull('fornitore_id')
            ->where('saldo_iniziale', '<', 0)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $compito = $debiti === [] ? null : Evento::query()->where('meta->type', self::TIPO_DEL_COMPITO)->whereIn('meta->context->saldo_id', $debiti)
            ->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominioId))->orderBy('id')->first();
        if ($compito === null) {
            return null;
        }
        $diviso = self::partiDiOggi($condominioId, (int) (((array) (((array) $compito->meta)['context'] ?? []))['saldo_id'] ?? 0));
        if ($diviso['oggi']['oltre'] <= 0) {
            $chiusiPrima = $diviso['compiti']->filter(fn (Evento $uno) => (bool) $uno->is_completed)->pluck('id')->map(fn ($id) => (int) $id)->all();
            self::allineaIDeficit($condominioId, $compito, null);
            $riaperti = $chiusiPrima === [] ? 0 : Evento::query()->whereIn('id', $chiusiPrima)->where('is_completed', false)->count();

            return $riaperti > 0 && $diviso['oggi']['ripianato'] > 0 && $diviso['oggi']['scoperto'] > 0
                ? self::fraseDelloScopertoRiaperto($condominioId, $diviso['oggi']) : null;
        }
        // L'oltre va solo ai compiti che l'amministratore non ha chiuso ({@see partiDiOggi()}): se li ha chiusi tutti, l'avviso tace.
        if (array_sum($diviso['oltre']) <= 0) {
            return null;
        }
        foreach ($diviso['compiti'] as $uno) {
            if (($diviso['oltre'][(int) $uno->id] ?? 0) > 0) {
                self::riapriSeLoHaChiusoIlProgramma($uno);
            }
        }

        return self::fraseDellOltre($condominioId, $diviso['oggi']);
    }

    /**
     * La frase dei piani di ripianamento oltre lo scoperto, con i numeri ({@see scopertoDiOggi()}): per l'avviso alla scrittura del pregresso, per la pagina
     * del nuovo piano aperta dal compito e per la richiesta. Il programma non ricalcola il piano: lo dice, e decide l'amministratore.
     *
     * @param  array<string, mixed>  $oggi  da {@see scopertoDiOggi()}
     */
    private static function fraseDellOltre(int $condominioId, array $oggi): string
    {
        $piani = PianoRate::query()->where('condominio_id', $condominioId)->whereIn('id', $oggi['piani'])->orderBy('id')->pluck('nome')
            ->map(fn ($nome) => '«' . $nome . '»')->all();
        $quali = $piani === [] ? '' : ' (' . (count($piani) === 1 ? 'il piano ' : 'i piani ') . implode(', ', $piani) . ')';
        $fornitore = $oggi['piu_debiti'] ? null : DB::table('saldi')->join('fornitori', 'fornitori.id', '=', 'saldi.fornitore_id')
            ->whereIn('saldi.id', $oggi['saldi'])->value('fornitori.ragione_sociale');
        $apre = $fornitore === null
            ? 'I debiti ereditati sono già a rate per ' . MoneyHelper::format($oggi['ripianato']) . $quali
            : 'Il debito ereditato verso ' . $fornitore . ' è già a rate per ' . MoneyHelper::format($oggi['ripianato']) . $quali;

        return $apre . ': con i pregressi chiesti ai condòmini (' . MoneyHelper::format($oggi['pregressi']) . ') lo scoperto è ' . MoneyHelper::format($oggi['senza'])
            . ', e i condòmini pagherebbero ' . MoneyHelper::format($oggi['oltre']) . ' due volte. Il programma non cambia il piano: decidi tu se correggere i '
            . 'pregressi o rifare il piano del ripianamento con la cifra giusta (riportalo in bozza ed eliminalo, poi preparalo di nuovo dal compito «Deficit da '
            . 'recuperare»); se l\'assemblea ha deciso così, segna il compito come fatto.';
    }

    /**
     * La frase dello scoperto che torna più grande del ripianato, dopo un pregresso tolto o abbassato: nessuno chiede la differenza, e il compito «Deficit da
     * recuperare» è di nuovo aperto.
     *
     * @param  array<string, mixed>  $oggi  da {@see scopertoDiOggi()}
     */
    private static function fraseDelloScopertoRiaperto(int $condominioId, array $oggi): string
    {
        $piani = PianoRate::query()->where('condominio_id', $condominioId)->whereIn('id', $oggi['piani'])->orderBy('id')->pluck('nome')
            ->map(fn ($nome) => '«' . $nome . '»')->all();
        $quali = $piani === [] ? '' : ' (' . (count($piani) === 1 ? 'il piano ' : 'i piani ') . implode(', ', $piani) . ')';
        $fornitore = $oggi['piu_debiti'] ? null : DB::table('saldi')->join('fornitori', 'fornitori.id', '=', 'saldi.fornitore_id')
            ->whereIn('saldi.id', $oggi['saldi'])->value('fornitori.ragione_sociale');
        $apre = $fornitore === null
            ? 'I debiti ereditati sono a rate per ' . MoneyHelper::format($oggi['ripianato']) . $quali
            : 'Il debito ereditato verso ' . $fornitore . ' è a rate per ' . MoneyHelper::format($oggi['ripianato']) . $quali;

        return $apre . ': con i pregressi chiesti ai condòmini (' . MoneyHelper::format($oggi['pregressi']) . ') lo scoperto è ' . MoneyHelper::format($oggi['senza'])
            . ', e ' . MoneyHelper::format($oggi['scoperto']) . ' non li chiede nessuno: il compito «Deficit da recuperare» è di nuovo aperto.';
    }

    /**
     * Perché la richiesta non trova un debito da ripianare nel compito ({@see proposta()} nulla). Con più debiti ereditati lo scoperto è uno per tutti e lo
     * ricordano i compiti in ordine di nascita ({@see partiDiOggi()}): se la parte di questo debito l'ha presa un altro compito, la frase dice quale, la strada
     * vera. Altrimenti la frase di sempre.
     */
    public static function fraseSenzaProposta(int $condominioId, int $compitoId): string
    {
        $sempre = 'Il debito ereditato non è più da ripianare: il compito è chiuso, o la fattura pregressa è stata stornata o è già tutta in un piano.';
        $compito = self::compito($condominioId, $compitoId);
        $saldoId = $compito === null ? 0 : (int) (((array) (((array) $compito->meta)['context'] ?? []))['saldo_id'] ?? 0);
        if ($saldoId <= 0 || self::chiusoDallAmministratore($compito) || self::fattureDelDebito($condominioId, $saldoId)->sum(fn (FatturaPassiva $f) => self::rataZero($f)) <= 0) {
            return $sempre;
        }
        $diviso = self::partiDiOggi($condominioId, $saldoId);
        $altri = array_filter($diviso['parti'], fn (int $parte, int $id) => $id !== $compitoId && $parte > 0, ARRAY_FILTER_USE_BOTH);
        if ($altri === []) {
            return $sempre;
        }
        $fornitori = [];
        foreach ($diviso['compiti']->whereIn('id', array_keys($altri)) as $altro) {
            $fornitori[] = (string) (self::fattureDelDebito($condominioId, (int) (((array) (((array) $altro->meta)['context'] ?? []))['saldo_id'] ?? 0))->first()?->fornitore?->ragione_sociale
                ?? 'un altro fornitore');
        }

        return 'Su questo debito il programma non ricorda più niente da ripianare, ma lo scoperto che resta, ' . MoneyHelper::format(array_sum($altri)) . ', lo ricorda '
            . (count($fornitori) === 1 ? 'il compito del debito verso ' . $fornitori[0] : 'i compiti dei debiti verso ' . implode(' e ', $fornitori))
            . ': prepara il piano da lì. Con più debiti ereditati lo scoperto è uno per tutti, e il programma lo conta una volta sola, nei compiti in ordine di nascita.';
    }

    /**
     * Perché il ripianamento non si prepara nell'esercizio dato, o null se si prepara lì: si prepara nell'esercizio della pregressa, aperto. In un altro
     * esercizio il rendiconto direbbe la parte messa a rate «da pagare» anche dopo il pagamento del fornitore: il programma si ferma e dice dove.
     *
     * @param  array{fornitore: string, esercizio_id: int, esercizio: string, esercizio_aperto: bool}  $limiti
     */
    public static function altrove(array $limiti, int $esercizioId): ?string
    {
        $dove = 'Il debito ereditato verso ' . $limiti['fornitore'] . ' è registrato nell\'esercizio «' . $limiti['esercizio'] . '»';
        if (! $limiti['esercizio_aperto']) {
            return $dove . ', che è chiuso: il ripianamento si prepara solo in quell\'esercizio, perché il rendiconto legga il pagato del fornitore anche sulla '
                . 'parte messa a rate, e in un esercizio chiuso non si prepara un piano. Qui il programma non lo prepara: decidi con l\'assemblea come chiedere la cifra.';
        }
        if ($esercizioId !== $limiti['esercizio_id']) {
            return $dove . ': il ripianamento si prepara in quell\'esercizio, perché il rendiconto legga il pagato del fornitore anche sulla parte messa a rate. '
                . 'Apri il nuovo piano da «' . $limiti['esercizio'] . '».';
        }

        return null;
    }

    /**
     * I parametri dell'indirizzo di «Prepara il piano di ripianamento» (il compito nell'Action inbox, la cosa da fare del seguito), se c'è qualcosa da
     * ripianare: l'esercizio della pregressa (`esercizio`, se è aperto), la sua gestione e il compito. Con l'esercizio della pregressa chiuso, il solo compito:
     * la pagina si apre nell'esercizio di oggi e dice perché lì non si prepara ({@see altrove()}). Senza niente da ripianare nessuno, e la pagina si apre
     * come prima.
     *
     * @return array<string, int>
     */
    public static function parametriDelCompito(?Evento $compito): array
    {
        if ($compito === null || (((array) $compito->meta)['type'] ?? null) !== self::TIPO_DEL_COMPITO) {
            return [];
        }
        $condominioId = $compito->condomini()->orderBy('condomini.id')->value('condomini.id');
        $proposta = $condominioId === null ? null : self::proposta((int) $condominioId, (int) $compito->id);
        if ($proposta === null) {
            return [];
        }

        return $proposta['esercizio_aperto']
            ? ['esercizio' => $proposta['esercizio_id'], 'gestione_id' => $proposta['gestione_id'], 'ripianamento' => $proposta['compito_id']]
            : ['ripianamento' => $proposta['compito_id']];
    }

    /**
     * La frase del massimo, per la richiesta e per la pagina, con i numeri di oggi: lo scoperto (i debiti ereditati meno i pregressi chiesti ai condòmini e
     * meno quanto è già a rate o coperto altrimenti), o la parte della pregressa ancora a carico delle passate gestioni se è minore.
     *
     * @param  array{massimo: int, limite: string, fornitore: string, debiti: int, pregressi: int, coperto: int, piu_debiti: bool}  $limiti
     */
    public static function fraseDelMassimo(array $limiti): string
    {
        $apre = 'Il debito ereditato si può mettere a rate al massimo per ' . MoneyHelper::format($limiti['massimo']) . ': ';
        if ($limiti['limite'] !== 'scoperto') {
            return $apre . 'è la parte della fattura pregressa ancora a carico delle passate gestioni.';
        }
        $coperto = $limiti['coperto'] > 0 ? ', e meno quanto è già a rate o coperto altrimenti (' . MoneyHelper::format($limiti['coperto']) . ')' : '';
        if (! $limiti['piu_debiti']) {
            return $apre . 'è lo scoperto di oggi: il debito verso ' . $limiti['fornitore'] . ' (' . MoneyHelper::format($limiti['debiti']) . ') meno i pregressi chiesti ai '
                . 'condòmini (' . MoneyHelper::format($limiti['pregressi']) . ')' . $coperto . '. Oltre, i condòmini pagherebbero due volte la parte dei pregressi.';
        }

        return $apre . 'è lo scoperto di oggi: i debiti ereditati (' . MoneyHelper::format($limiti['debiti']) . ') meno i pregressi chiesti ai condòmini ('
            . MoneyHelper::format($limiti['pregressi']) . ')' . $coperto . '. Con più debiti il programma non sa quale coprono i pregressi: quanto mettere a rate '
            . 'su ogni debito lo decide l\'assemblea, e il resto resta da ripianare.';
    }

    /**
     * Scrive il ripianamento per il piano appena nato, nella sua transazione: la voce, le coperture e la scrittura. Rende le fatture con quanto il piano ne mette
     * a rate, per la pivot del piano. La cifra si ricontrolla con le fatture bloccate (il compito può essere già chiuso dal seguito della delibera, collegata
     * prima: qui conta lo scoperto, non lo stato del compito).
     *
     * @param array<string, mixed> $proposta da {@see proposta()}
     * @return array<int, int> fattura => centesimi
     */
    public static function scrivi(PianoRate $piano, array $proposta, int $importo, int $tabellaId, ?Delibera $delibera, ?User $utente): array
    {
        $condominioId = (int) $piano->condominio_id;
        FatturaPassiva::query()->whereIn('id', array_column($proposta['fatture'], 'id'))->orderBy('id')->lockForUpdate()->get();
        $compito = self::compito($condominioId, (int) $proposta['compito_id']);
        $limiti = $compito === null ? null : self::limiti($condominioId, $compito);
        if ($limiti !== null && ($altrove = self::altrove($limiti, (int) $piano->esercizio_id)) !== null) {
            throw ValidationException::withMessages(['ripianamento' => $altrove]);
        }
        if ($limiti === null || $importo <= 0 || $importo > $limiti['massimo']) {
            throw ValidationException::withMessages(['ripianamento.importo' => $importo <= 0 || $limiti === null || $limiti['massimo'] <= 0
                ? 'Scrivi quanto del debito ereditato mettere a rate.' : self::fraseDelMassimo($limiti)]);
        }
        $fatture = FatturaPassiva::query()->whereIn('id', array_column($limiti['fatture'], 'id'))->orderBy('id')->get()->load(['coperture', 'fornitore']);
        $fornitore = (string) ($fatture->first()?->fornitore?->ragione_sociale ?? $limiti['fornitore']);
        $esercizio = $piano->esercizio_id === null ? null : \App\Models\Esercizio::query()->find($piano->esercizio_id);
        $giorno = self::giornoNellEsercizio($esercizio);
        $passate = ContoContabile::query()->where('condominio_id', $condominioId)->where('ruolo', 'passate_gestioni')->whereNull('deleted_at')->firstOrFail();

        $nomeFattura = 'Fattura n. ' . ($fatture->first()?->numero_documento ?? 'S/N') . ' di ' . $fornitore;
        $gesto = Gesto::sulModello($fatture->first())
            ->riepilogo($nomeFattura . ': ' . MoneyHelper::format($importo) . ' del debito ereditato messi a rate nel piano «' . $piano->nome . '», come spesa di quest\'anno sulla voce «'
                . self::nomeDellaVoce($fornitore) . '»')
            ->rimando('piano_rate', (int) $piano->id, 'Piano rate «' . $piano->nome . '»');

        return RegistroModifiche::durante($gesto, function () use ($piano, $fatture, $importo, $tabellaId, $delibera, $utente, $fornitore, $esercizio, $giorno, $passate, $condominioId) {
            $voce = self::voceDelRipianamento($condominioId, (int) $piano->gestione_id, (int) $fatture->first()->fornitore_id, $fornitore, $tabellaId, $importo, $delibera, $piano);

            $scrittura = ScritturaContabile::create([
                'condominio_id' => $condominioId,
                'esercizio_id' => $esercizio?->id,
                'gestione_id' => (int) $piano->gestione_id,
                'data_registrazione' => DateHelper::oggiUtente(),
                'data_competenza' => $giorno,
                'causale' => mb_substr(self::segno($piano) . ' verso ' . $fornitore . ' — «' . $piano->nome . '»', 0, 255),
                'tipo_movimento' => TipoMovimentoContabile::RIPIANAMENTO_DEBITO,
                'stato' => 'registrata',
                'created_by' => $utente?->id,
            ]);
            $scrittura->righe()->create(['conto_contabile_id' => $voce->conto_contabile_id, 'tipo_riga' => 'dare', 'importo' => $importo, 'voce_spesa_id' => $voce->id,
                'note' => 'Il debito ereditato scoperto diventa una spesa di quest\'anno, messa a rate']);
            $scrittura->righe()->create(['conto_contabile_id' => $passate->id, 'tipo_riga' => 'avere', 'importo' => $importo,
                'note' => 'La parte del debito ereditato che non resta più a carico delle passate gestioni']);
            DoubleEntryValidator::validateOrFail($scrittura->id);

            // La cifra si prende dalle rate 0 delle pregresse, in ordine: ogni fattura toccata ha la sua copertura e la sua parte nel piano.
            $resta = $importo;
            $nelPiano = [];
            foreach ($fatture as $f) {
                $daQui = min($resta, self::rataZero($f));
                if ($daQui <= 0) {
                    continue;
                }
                self::spostaDallaRataZero($f, $daQui);
                FatturaCopertura::create(['fattura_passiva_id' => $f->id, 'tipo_copertura' => 'sopravvenienza', 'importo' => $daQui, 'stato' => 'pianificata',
                    'conto_id' => $voce->id, 'nota_amministratore' => self::segno($piano)]);
                $f->scritture()->attach($scrittura->id, ['importo_allocato' => $daQui, 'tipo' => TipoAllocazioneFattura::RETTIFICA->value]);
                $nelPiano[(int) $f->id] = $daQui;
                $resta -= $daQui;
                if ($resta === 0) {
                    break;
                }
            }

            return $nelPiano;
        });
    }

    /**
     * Dopo il salvataggio, con la delibera già collegata: lo scoperto scende di quanto il piano mette a rate, e i compiti del deficit dello stesso esercizio
     * si allineano ({@see allineaIDeficit()}): chi non ha più niente da ricordare si chiude con la frase, chi ha ancora una parte resta aperto (e se il seguito
     * della delibera lo ha appena chiuso, si riapre): il resto non si perde.
     */
    public static function allineaIlCompito(int $condominioId, int $compitoId, PianoRate $piano): void
    {
        $compito = self::compito($condominioId, $compitoId);
        if ($compito === null) {
            return;
        }
        self::allineaIDeficit($condominioId, $compito, 'Chiuso: piano «' . $piano->nome . '» creato il ' . Carbon::parse(DateHelper::oggiUtente())->format('d/m/Y') . '.');
    }

    /**
     * Eliminato il piano (in bozza: l'eliminazione lo pretende), il ripianamento che era suo si toglie: lo storno della sua scrittura, la rata 0 rimessa, le
     * coperture tolte, la voce che scende della stessa cifra. Il compito del deficit torna aperto se lo aveva chiuso il programma (un piano, il seguito della
     * delibera); se lo ha chiuso l'amministratore («Fatto», «Non serve») resta chiuso: è una sua decisione. Solo le coperture con il segno di questo piano
     * ({@see segno()}). Rende quante ne ha tolte.
     */
    public static function togli(PianoRate $piano, ?User $utente): int
    {
        $fattureIds = DB::table('piano_rate_fatture')->where('piano_rate_id', $piano->id)->pluck('fattura_passiva_id')->map(fn ($id) => (int) $id)->all();
        if ($fattureIds === []) {
            return 0;
        }
        $coperture = FatturaCopertura::query()->whereIn('fattura_passiva_id', $fattureIds)->where('tipo_copertura', 'sopravvenienza')
            ->where('nota_amministratore', self::segno($piano))->orderBy('id')->get();
        if ($coperture->isEmpty()) {
            return 0;
        }

        // Un gesto sulla fattura («ripianamento tolto»): dentro l'eliminazione del piano si conta nel suo lotto.
        $prima = FatturaPassiva::query()->find($coperture->first()->fattura_passiva_id);
        $gesto = Gesto::sulModello($prima ?? $piano)->riepilogo(($prima === null ? 'Piano «' . $piano->nome . '»' : 'Fattura n. ' . ($prima->numero_documento ?? 'S/N'))
            . ': il ripianamento del debito ereditato è tolto con il piano «' . $piano->nome . '» eliminato; il debito torna a carico delle passate gestioni');

        return RegistroModifiche::durante($gesto, function () use ($piano, $coperture, $fattureIds, $utente) {
            $storni = [];
            foreach (self::scrittureDelPiano($piano) as $originale) {
                $storno = ScritturaContabile::create([
                    'condominio_id' => $originale->condominio_id,
                    'esercizio_id' => $originale->esercizio_id,
                    'gestione_id' => $originale->gestione_id,
                    'scrittura_padre_id' => $originale->id,
                    'data_registrazione' => DateHelper::oggiUtente(),
                    'data_competenza' => $originale->data_competenza ?? DateHelper::oggiUtente(),
                    'causale' => mb_substr('Annullato: ' . lcfirst(self::segno($piano)) . ' — piano «' . $piano->nome . '» eliminato', 0, 255),
                    'tipo_movimento' => TipoMovimentoContabile::STORNO_RIPIANAMENTO_DEBITO,
                    'stato' => 'registrata',
                    'created_by' => $utente?->id,
                ]);
                foreach ($originale->righe as $riga) {
                    $storno->righe()->create(['conto_contabile_id' => $riga->conto_contabile_id, 'tipo_riga' => $riga->tipo_riga === 'dare' ? 'avere' : 'dare',
                        'importo' => (int) $riga->importo, 'voce_spesa_id' => $riga->voce_spesa_id,
                        'note' => $riga->tipo_riga === 'dare' ? 'La spesa del ripianamento si toglie con il suo piano'
                            : 'Il debito ereditato torna a carico delle passate gestioni: il piano del ripianamento è stato eliminato']);
                }
                DoubleEntryValidator::validateOrFail($storno->id);
                $storni[] = $storno;
            }

            $tolte = 0;
            foreach ($coperture as $copertura) {
                $fattura = FatturaPassiva::query()->lockForUpdate()->find($copertura->fattura_passiva_id);
                if ($fattura !== null) {
                    self::rimettiNellaRataZero($fattura, (int) $copertura->importo);
                    foreach ($storni as $storno) {
                        $fattura->scritture()->attach($storno->id, ['importo_allocato' => -1 * (int) $copertura->importo, 'tipo' => TipoAllocazioneFattura::RETTIFICA->value]);
                    }
                }
                $copertura->delete();
                $tolte++;
            }
            foreach ($coperture->groupBy('conto_id') as $contoId => $diQuestaVoce) {
                $voce = Conto::query()->find((int) $contoId);
                $voce?->update(['importo' => max(0, (int) $voce->importo - (int) $diQuestaVoce->sum('importo'))]);
            }

            // I compiti del deficit: lo scoperto torna su, e chi ha di nuovo una parte si riapre se lo aveva chiuso il programma.
            $condominioId = (int) $piano->condominio_id;
            $saldi = FatturaPassiva::query()->whereIn('id', $fattureIds)->whereNotNull('saldo_patrimoniale_id')->pluck('saldo_patrimoniale_id')->unique()->all();
            $compito = Evento::query()->where('meta->type', self::TIPO_DEL_COMPITO)->whereIn('meta->context->saldo_id', $saldi)
                ->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominioId))->orderBy('id')->first();
            if ($compito !== null) {
                self::allineaIDeficit($condominioId, $compito, 'Chiuso il ' . Carbon::parse(DateHelper::oggiUtente())->format('d/m/Y')
                    . ': con il piano «' . $piano->nome . '» eliminato, su questo debito non resta niente da ripianare (lo scoperto di oggi, se c\'è, è nei compiti '
                    . 'degli altri debiti ereditati).');
            }
            InboxService::clearAdminCache();

            return $tolte;
        });
    }

    /** Il segno della copertura scritta dal piano, nella sua nota: «Ripianamento del debito ereditato (piano n. 12)». Il numero del piano non cambia con il nome. */
    public static function segno(PianoRate $piano): string
    {
        return self::CAUSALE . ' (piano n. ' . $piano->id . ')';
    }

    /** La nota di una copertura scritta da un ripianamento (di un piano qualunque). */
    public static function eDiUnRipianamento(?string $nota): bool
    {
        return $nota !== null && str_starts_with($nota, self::CAUSALE . ' (piano n. ');
    }

    /** «Ripianamento del debito verso Idraulica Bianchi»: il nome della voce. */
    public static function nomeDellaVoce(string $fornitore): string
    {
        return mb_substr('Ripianamento del debito verso ' . $fornitore, 0, 255);
    }

    /**
     * I numeri del compito, aperto o chiuso: le pregresse del debito sulla gestione della prima, la rata 0 di ognuna, lo scoperto di oggi ({@see scopertoDiOggi()}),
     * il massimo (il minore fra lo scoperto e la rata 0 di questo debito), l'esercizio della pregressa.
     */
    private static function limiti(int $condominioId, Evento $compito): ?array
    {
        $meta = (array) $compito->meta;
        $saldoId = (int) (((array) ($meta['context'] ?? []))['saldo_id'] ?? 0);
        if ($saldoId <= 0) {
            return null;
        }
        $delDebito = self::fattureDelDebito($condominioId, $saldoId);
        if ($delDebito->isEmpty()) {
            return null;
        }
        $gestioneId = $delDebito->first()->gestioneDellaFattura();
        $gestione = $gestioneId === null ? null : Gestione::query()->where('condominio_id', $condominioId)->find($gestioneId);
        $esercizio = \App\Models\Esercizio::query()->where('condominio_id', $condominioId)->find($delDebito->first()->esercizio_id);
        if ($gestione === null || $esercizio === null) {
            return null;
        }
        // Una gestione sola: il piano del ripianamento è sulla gestione della voce nuova (il conguaglio conta la spesa e le rate della stessa gestione).
        $fatture = $delDebito->filter(fn (FatturaPassiva $f) => $f->gestioneDellaFattura() === (int) $gestione->id)->values();
        $righe = $fatture->map(fn (FatturaPassiva $f) => ['id' => (int) $f->id, 'numero' => (string) ($f->numero_documento ?? 'S/N'), 'rata_0' => self::rataZero($f)])
            ->filter(fn ($r) => $r['rata_0'] > 0)->values()->all();
        $rataZero = (int) array_sum(array_column($righe, 'rata_0'));
        $oggi = self::scopertoDiOggi($condominioId, $saldoId);
        $massimo = min($rataZero, $oggi['scoperto']);
        $fornitore = (string) ($delDebito->first()->fornitore?->ragione_sociale ?? 'il fornitore');

        $limiti = [
            'compito_id' => (int) $compito->id,
            'fornitore' => $fornitore,
            'voce' => self::nomeDellaVoce($fornitore),
            'scoperto' => $oggi['scoperto'],
            'debiti' => $oggi['debiti'],
            'pregressi' => $oggi['pregressi'],
            'coperto' => $oggi['coperto'],
            'piu_debiti' => $oggi['piu_debiti'],
            'ripianato' => self::ripianato($delDebito),
            'massimo' => $massimo,
            'proposto' => $massimo,
            'limite' => $massimo < $oggi['scoperto'] ? 'fattura' : 'scoperto',
            'gestione_id' => (int) $gestione->id,
            'gestione' => (string) $gestione->nome,
            'esercizio_id' => (int) $esercizio->id,
            'esercizio' => (string) $esercizio->nome,
            'esercizio_aperto' => (string) $esercizio->stato === 'aperto',
            'fatture' => $righe,
            // Le tabelle attive del condominio: una tabella spenta non divide più niente (la regola della richiesta è la stessa).
            'tabelle' => Tabella::query()->where('condominio_id', $condominioId)->where('attiva', true)->orderBy('nome')->get(['id', 'nome'])
                ->map(fn ($t) => ['id' => (int) $t->id, 'nome' => (string) $t->nome])->values()->all(),
        ];
        $limiti['frase_del_massimo'] = self::fraseDelMassimo($limiti);

        return $limiti;
    }

    /**
     * Lo scoperto di oggi dell'esercizio del debito, letto ora e non dal compito (che lo fotografa alla registrazione della pregressa): i debiti ereditati
     * verso i fornitori (i saldi iniziali negativi con il fornitore), meno i pregressi chiesti ai condòmini (i saldi iniziali positivi, con la stessa regola del
     * compito, `SyncScadenziarioWithFattura`: senza la coppia di un passaggio), meno quanto le pregresse di quei debiti hanno già coperto altrimenti (le coperture
     * che non sono la «rata 0»: le sopravvenienze, della registrazione o di un ripianamento, e il fondo di riserva). Uno per tutti i debiti dell'esercizio:
     * i pregressi non sono di un debito.
     *
     * `oltre` è quanto i piani di ripianamento (`ripianato`, con i loro `piani`) mettono a rate oltre lo scoperto che c'era senza di loro (`senza`): i
     * pregressi scritti dopo il ripianamento lo fanno crescere, e quella parte i condòmini la pagherebbero due volte. Il programma non ricalcola il piano: lo
     * dice ({@see fraseDellOltre()}).
     *
     * @return array{scoperto: int, debiti: int, pregressi: int, coperto: int, piu_debiti: bool, saldi: list<int>, ripianato: int, senza: int, oltre: int,
     *   piani: list<int>, esercizio_id: int}
     */
    private static function scopertoDiOggi(int $condominioId, int $saldoId): array
    {
        $esercizioId = DB::table('saldi')->where('id', $saldoId)->value('esercizio_id');
        $debiti = DB::table('saldi')->where('condominio_id', $condominioId)->where('esercizio_id', $esercizioId)->whereNotNull('fornitore_id')
            ->where('saldo_iniziale', '<', 0)->get(['id', 'saldo_iniziale']);
        $saldi = $debiti->pluck('id')->map(fn ($id) => (int) $id)->push($saldoId)->unique()->values()->all();
        $pregressi = (int) DB::table('saldi')->where('condominio_id', $condominioId)->where('esercizio_id', $esercizioId)->whereNull('fornitore_id')
            ->whereNull('subentro_id')->whereNull('passaggio_id')->where('saldo_iniziale', '>', 0)->sum('saldo_iniziale');
        $coperture = fn () => DB::table('fattura_coperture')->join('fatture_passive', 'fatture_passive.id', '=', 'fattura_coperture.fattura_passiva_id')
            ->where('fatture_passive.condominio_id', $condominioId)->whereIn('fatture_passive.saldo_patrimoniale_id', $saldi)->where('fatture_passive.is_pregresso', true)
            ->where('fatture_passive.tipo_documento', 'fattura')->where('fatture_passive.stato_approvazione', '!=', 'contestata')
            ->where('fatture_passive.stato_pagamento', '!=', 'stornata');
        $coperto = (int) $coperture()->where('fattura_coperture.tipo_copertura', '!=', 'rata_0')->sum('fattura_coperture.importo');
        $note = $coperture()->where('fattura_coperture.tipo_copertura', 'sopravvenienza')->where('fattura_coperture.nota_amministratore', 'like', self::CAUSALE . ' (piano n. %')
            ->get(['fattura_coperture.importo', 'fattura_coperture.nota_amministratore']);
        $ripianato = (int) $note->sum('importo');
        $piani = $note->map(fn ($c) => preg_match('/\(piano n\. (\d+)\)$/', (string) $c->nota_amministratore, $m) === 1 ? (int) $m[1] : null)->filter()->unique()->values()->all();
        $totale = (int) $debiti->sum(fn ($d) => abs((int) $d->saldo_iniziale));
        $senza = max(0, $totale - $pregressi - ($coperto - $ripianato));

        return ['scoperto' => max(0, $totale - $pregressi - $coperto), 'debiti' => $totale, 'pregressi' => $pregressi, 'coperto' => $coperto,
            'piu_debiti' => $debiti->count() > 1, 'saldi' => $saldi, 'ripianato' => $ripianato, 'senza' => $senza, 'oltre' => max(0, $ripianato - $senza),
            'piani' => $piani, 'esercizio_id' => (int) $esercizioId];
    }

    /**
     * Lo scoperto di oggi dell'esercizio diviso fra i suoi compiti del deficit, come lo scrive {@see allineaIDeficit()}: in ordine di nascita, ognuno al
     * massimo la sua pregressa ancora a carico delle passate gestioni; un compito chiuso dall'amministratore («Fatto», «Non serve») non prende una parte. Allo
     * stesso modo `oltre` ({@see scopertoDiOggi()}) va ai compiti dei debiti già ripianati, al massimo quanto i piani ne mettono a rate.
     *
     * @return array{oggi: array<string, mixed>, compiti: \Illuminate\Database\Eloquent\Collection<int, Evento>, parti: array<int, int>, oltre: array<int, int>}
     */
    private static function partiDiOggi(int $condominioId, int $saldoId): array
    {
        $oggi = self::scopertoDiOggi($condominioId, $saldoId);
        $resto = $oggi['scoperto'];
        $oltre = $oggi['oltre'];
        $compiti = Evento::query()->where('meta->type', self::TIPO_DEL_COMPITO)->whereIn('meta->context->saldo_id', $oggi['saldi'])
            ->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominioId))->orderBy('id')->get();
        $parti = [];
        $oltrePer = [];
        foreach ($compiti as $uno) {
            if (self::chiusoDallAmministratore($uno)) {
                continue;
            }
            $delDebito = self::fattureDelDebito($condominioId, (int) (((array) (((array) $uno->meta)['context'] ?? []))['saldo_id'] ?? 0));
            $parti[(int) $uno->id] = min($resto, (int) $delDebito->sum(fn (FatturaPassiva $f) => self::rataZero($f)));
            $resto -= $parti[(int) $uno->id];
            $oltrePer[(int) $uno->id] = min($oltre, self::ripianato($delDebito));
            $oltre -= $oltrePer[(int) $uno->id];
        }

        return ['oggi' => $oggi, 'compiti' => $compiti, 'parti' => $parti, 'oltre' => $oltrePer];
    }

    /** Lo scoperto scritto alla nascita del compito: quello di prima del primo ripianamento, o quello del compito (`SyncScadenziarioWithFattura`). */
    private static function scopertoEreditato(array $meta): int
    {
        return max(0, (int) ($meta['importo_buco_ereditato'] ?? $meta['importo_buco'] ?? 0));
    }

    /** Quanto dei debiti di queste pregresse i piani di ripianamento mettono già a rate: le coperture con il segno di un piano. */
    private static function ripianato(Collection $fatture): int
    {
        if ($fatture->isEmpty()) {
            return 0;
        }

        return (int) FatturaCopertura::query()->whereIn('fattura_passiva_id', $fatture->pluck('id')->all())->where('tipo_copertura', 'sopravvenienza')
            ->where('nota_amministratore', 'like', self::CAUSALE . ' (piano n. %')->sum('importo');
    }

    /**
     * I compiti del deficit dell'esercizio di `$compito` (uno per debito ereditato), allineati allo scoperto di oggi: ognuno scrive in `importo_buco` la sua
     * parte del disavanzo che resta, in ordine di nascita e al massimo la sua pregressa ancora a carico delle passate gestioni, così la somma dei compiti aperti
     * è il disavanzo, contato una volta (l'Action inbox «Scoperto di …», la tesoreria e il punto proposto dell'ordine del giorno leggono `importo_buco`). Chi
     * non ha più una parte si chiude con `$frase` (se è nulla resta com'è); chi ne ha una e lo aveva chiuso il programma si riapre: il resto non si perde.
     * Un compito chiuso dall'amministratore («Fatto», «Non serve») resta com'è e non prende una parte: è una sua decisione, e il resto lo ricordano gli altri.
     */
    private static function allineaIDeficit(int $condominioId, Evento $compito, ?string $frase): void
    {
        $saldoId = (int) (((array) (((array) $compito->meta)['context'] ?? []))['saldo_id'] ?? 0);
        if ($saldoId <= 0) {
            return;
        }
        $diviso = self::partiDiOggi($condominioId, $saldoId);
        foreach ($diviso['compiti'] as $uno) {
            if (! array_key_exists((int) $uno->id, $diviso['parti'])) {
                continue;
            }
            $parte = $diviso['parti'][(int) $uno->id];
            $meta = (array) $uno->meta;
            $ereditato = self::scopertoEreditato($meta);
            $descrizione = self::descrizioneSenzaCifra((string) $uno->description);
            if (($meta['importo_buco_ereditato'] ?? null) !== $ereditato || (int) ($meta['importo_buco'] ?? -1) !== $parte || $descrizione !== (string) $uno->description) {
                $meta['importo_buco_ereditato'] = $ereditato;
                $meta['importo_buco'] = $parte;
                $uno->update(['meta' => $meta, 'description' => $descrizione]);
            }
            // Il compito resta aperto anche con una parte nulla se il suo debito è a rate oltre lo scoperto: lo ricorda finché l'amministratore decide.
            if ($parte > 0 || ($diviso['oltre'][(int) $uno->id] ?? 0) > 0) {
                self::riapriSeLoHaChiusoIlProgramma($uno->fresh());
            } elseif ($frase !== null && ! $uno->is_completed) {
                ChiusuraDeiCompiti::chiudi($uno->fresh(), $frase);
            }
        }
    }

    /**
     * La descrizione del compito senza la cifra della sua nascita: la cifra di oggi la dice la riga sotto il titolo ({@see sottoDelCompito()}), e il cruscotto
     * mostra le due insieme. Una descrizione scritta in un altro modo resta com'è.
     */
    private static function descrizioneSenzaCifra(string $descrizione): string
    {
        return (string) preg_replace('/\) è scoperto per € [0-9.,]+: è la parte che/u', ') ha una parte scoperta: è quella che', $descrizione, 1);
    }

    /** Riapre il compito chiuso dal programma (un piano, il seguito della delibera); quello chiuso dall'amministratore resta com'è. */
    private static function riapriSeLoHaChiusoIlProgramma(Evento $compito): void
    {
        if (! $compito->is_completed || self::chiusoDallAmministratore($compito)) {
            return;
        }
        $compito->update(['is_completed' => false, 'completed_at' => null]);
        InboxService::clearAdminCache();
    }

    /** Il compito chiuso dall'amministratore («Fatto», «Non serve»: la risposta ha chi l'ha data), non dal programma. */
    private static function chiusoDallAmministratore(Evento $compito): bool
    {
        if (! $compito->is_completed) {
            return false;
        }
        $risposta = RispostaPromemoria::query()->where('chiave', RispostePromemoria::chiaveDelCompito((int) $compito->id))->first();

        return $risposta !== null && $risposta->aggiornata_da !== null;
    }

    /** Le scritture del ripianamento di questo piano non ancora stornate. @return Collection<int, ScritturaContabile> */
    private static function scrittureDelPiano(PianoRate $piano): Collection
    {
        return ScritturaContabile::query()->with('righe')->where('condominio_id', $piano->condominio_id)
            ->where('tipo_movimento', TipoMovimentoContabile::RIPIANAMENTO_DEBITO->value)
            ->where('causale', 'like', self::segno($piano) . ' %')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('scritture_contabili as storno')->whereColumn('storno.scrittura_padre_id', 'scritture_contabili.id')
                ->where('storno.tipo_movimento', TipoMovimentoContabile::STORNO_RIPIANAMENTO_DEBITO->value)->whereNull('storno.deleted_at'))
            ->orderBy('id')->get();
    }

    /**
     * La voce del ripianamento: una «Ripianamento del debito verso …» della gestione con la stessa tabella (le si aggiunge la cifra), o una rimasta senza
     * coperture dopo un piano eliminato (prende la tabella e la cifra); altrimenti una nuova, con il numero se il nome c'è già.
     */
    private static function voceDelRipianamento(int $condominioId, int $gestioneId, int $fornitoreId, string $fornitore, int $tabellaId, int $importo, ?Delibera $delibera,
        PianoRate $piano): Conto
    {
        $nome = self::nomeDellaVoce($fornitore);
        $pianoConto = PianoConto::query()->where('gestione_id', $gestioneId)->first();
        $voci = $pianoConto === null ? collect() : Conto::query()->where('piano_conto_id', $pianoConto->id)->where('is_tecnico', true)->orderBy('id')->get()
            ->filter(fn (Conto $c) => $c->nome === $nome || preg_match('/^' . preg_quote($nome, '/') . ' \(\d+\)$/u', (string) $c->nome) === 1)->values();

        foreach ($voci as $v) {
            $tabelle = DB::table('conto_tabella_millesimale')->where('conto_id', $v->id)->pluck('tabella_id')->map(fn ($id) => (int) $id)->all();
            if ($tabelle === [$tabellaId]) {
                $v->update(['importo' => (int) $v->importo + $importo]);

                return $v;
            }
        }
        foreach ($voci as $v) {
            if (! FatturaCopertura::query()->where('conto_id', $v->id)->exists()) {
                self::impostaLaTabella($v, $tabellaId);
                $v->update(['importo' => $importo]);

                return $v;
            }
        }

        $contoId = app(FatturaPassivaService::class)->creaContoDinamicoSopravvenienza($condominioId, $gestioneId, $fornitoreId, [
            'nome_voce' => $voci->isEmpty() ? $nome : mb_substr($nome, 0, 240) . ' (' . ($voci->count() + 1) . ')',
            'tipo_ripartizione' => 'millesimale',
            'origine_decisionale' => $delibera !== null ? 'delibera_assembleare' : 'gestione_corrente',
            'motivazione_sforo' => 'Debito ereditato scoperto, messo a rate con il piano «' . $piano->nome . '»',
            'tabella_millesimale_id' => $tabellaId,
            'percentuale_proprietario' => 100,
        ], $importo);

        return Conto::query()->findOrFail($contoId);
    }

    /**
     * La tabella della voce, a carico dei proprietari, come la scrive `FatturaPassivaService::creaContoDinamicoSopravvenienza`. Il registro delle modifiche
     * ha la riga delle tabelle della voce, come «Associa tabella» (`GestiDelRegistro::scriviTabelleDellaVoce`), dentro il gesto del ripianamento.
     */
    private static function impostaLaTabella(Conto $voce, int $tabellaId): void
    {
        $prima = \App\Services\RegistroModifiche\GestiDelRegistro::tabelleDellaVoce((int) $voce->id);
        $legami = DB::table('conto_tabella_millesimale')->where('conto_id', $voce->id)->pluck('id')->all();
        DB::table('conto_tabella_ripartizioni')->whereIn('conto_tabella_millesimale_id', $legami)->delete();
        DB::table('conto_tabella_millesimale')->whereIn('id', $legami)->delete();
        $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $voce->id, 'tabella_id' => $tabellaId, 'coefficiente' => 100.00,
            'created_at' => now(), 'updated_at' => now()]);
        // Tutto ai proprietari: la somma delle percentuali è 100, come ogni porta che scrive le ripartizioni deve verificare.
        $ripartizioni = [['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]];
        if (abs(array_sum(array_column($ripartizioni, 'percentuale')) - 100) > 0.001) {
            throw new \LogicException('Le ripartizioni della voce del ripianamento non sommano a 100.');
        }
        DB::table('conto_tabella_ripartizioni')->insert($ripartizioni);
        \App\Services\RegistroModifiche\GestiDelRegistro::scriviTabelleDellaVoce($voce, $prima);
    }

    private static function compito(int $condominioId, int $compitoId): ?Evento
    {
        return Evento::query()->whereKey($compitoId)->where('meta->type', self::TIPO_DEL_COMPITO)
            ->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominioId))->first();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, FatturaPassiva> */
    private static function fattureDelDebito(int $condominioId, int $saldoId)
    {
        return FatturaPassiva::query()->with(['coperture', 'fornitore'])->where('condominio_id', $condominioId)->where('saldo_patrimoniale_id', $saldoId)
            ->where('is_pregresso', true)->where('tipo_documento', 'fattura')->where('stato_approvazione', '!=', 'contestata')
            ->where('stato_pagamento', '!=', 'stornata')->orderBy('id')->get();
    }

    /** La parte della pregressa ancora chiusa sul Fondo Passate Gestioni: le coperture «rata 0». */
    private static function rataZero(FatturaPassiva $f): int
    {
        return (int) $f->coperture->where('tipo_copertura', 'rata_0')->sum(fn ($c) => max(0, (int) $c->importo));
    }

    /** Toglie la cifra dalle coperture «rata 0» della fattura, dall'ultima: una che arriva a zero si toglie. */
    private static function spostaDallaRataZero(FatturaPassiva $f, int $importo): void
    {
        $resta = $importo;
        foreach ($f->coperture->where('tipo_copertura', 'rata_0')->sortByDesc('id') as $c) {
            $qui = min($resta, max(0, (int) $c->importo));
            if ($qui <= 0) {
                continue;
            }
            ((int) $c->importo - $qui) === 0 ? $c->delete() : $c->update(['importo' => (int) $c->importo - $qui]);
            $resta -= $qui;
            if ($resta === 0) {
                return;
            }
        }
    }

    /** Rimette la cifra nella copertura «rata 0» della fattura, sul suo debito; se non c'è più, la ricrea. */
    private static function rimettiNellaRataZero(FatturaPassiva $f, int $importo): void
    {
        $c = FatturaCopertura::query()->where('fattura_passiva_id', $f->id)->where('tipo_copertura', 'rata_0')->orderBy('id')->first();
        if ($c !== null) {
            $c->update(['importo' => (int) $c->importo + $importo]);

            return;
        }
        FatturaCopertura::create(['fattura_passiva_id' => $f->id, 'tipo_copertura' => 'rata_0', 'importo' => $importo, 'stato' => 'pianificata',
            'saldo_id' => $f->saldo_patrimoniale_id]);
    }

    /** Il giorno della scrittura: oggi, dentro l'esercizio del piano. */
    private static function giornoNellEsercizio(?\App\Models\Esercizio $esercizio): string
    {
        $oggi = DateHelper::oggiUtente();
        if ($esercizio === null) {
            return $oggi;
        }
        $inizio = substr((string) $esercizio->data_inizio, 0, 10);
        $fine = substr((string) $esercizio->data_fine, 0, 10);

        return $oggi < $inizio ? $inizio : ($oggi > $fine ? $fine : $oggi);
    }
}
