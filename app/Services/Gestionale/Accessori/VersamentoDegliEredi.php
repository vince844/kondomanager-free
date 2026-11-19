<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Accessori;

use App\Helpers\DateHelper;
use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decisione 529: il pagamento di un erede si registra a nome suo e copre il suo debito ereditato.
 *
 * Il debito ereditato non è una quota dell'erede: finché un piano non assorbe le righe dell'arretrato, sta nella rata del defunto (il giornale) e nelle righe
 * di `saldi` degli eredi (il Wallet). Il versamento dell'erede chiude il debito dove il giornale lo tiene, la rata del defunto, con il legame a chi ha versato
 * (la stessa riga di «Versato da» della Coda 167: AVERE «Crediti verso condomini», persona il defunto, la sua rata e l'unità, `riferimento` l'erede); e nello
 * stesso passaggio una **coppia nel Wallet legata al passaggio** (`saldi.passaggio_id`): l'erede − y, il defunto + y, stessa gestione, unità ed esercizio delle
 * righe dell'arretrato, non ancora in un piano. Il piano dopo la compensa senza codice nuovo (`GenerateSaldiAction` prende ogni riga libera della gestione):
 * all'erede non chiede di nuovo quello che ha versato, e il defunto resta con il suo. Le letture (la proposta, «Rate scadute», le lettere, i sei mesi, la
 * tesoreria) leggono il versamento dal giornale ({@see DebitoEreditato::versamentiDegliEredi()}). Nessuna migrazione, nessuna scrittura di tipo nuovo.
 *
 * Tre strade, tutte qui:
 * - **pagante l'erede** ({@see registraDellErede()}): il modulo dell'incasso gli offre il suo debito ereditato ({@see apertiDi()}), anche se non ha altre rate;
 * - **pagante il defunto, dopo la successione** ({@see registraAgliEredi()}): il programma avvisa e, se l'amministratore sceglie «agli eredi», il versamento
 *   copre il debito di chi ha versato («Versato da» un erede) o, dal conto del defunto, di tutti per quota, una riga per erede con il suo legame. Con la data
 *   di prima del decesso (un bonifico del defunto registrato dopo la successione) solo la coppia per quota: il capitale al decesso lo conta già;
 * - **lo storno dell'incasso** ({@see storna()}): la coppia si toglie se nessun piano l'ha presa, altrimenti si scrive quella rovescia.
 *
 * Solo finché le righe dell'arretrato non sono in un piano: dopo, il debito è nella rata nuova dell'erede e si incassa lì. Il registro del passaggio tiene le
 * coppie che scrive (`versamenti_degli_eredi`), con l'incasso che le ha portate: lo storno le ritrova da lì, l'annullamento della successione le toglie con le
 * altre righe legate.
 *
 * **La catena** (decisione 530, Q5 della quarta prova della beta.12): l'erede di un erede morto a sua volta (Luca, erede di Marco, che l'aveva ereditato da
 * Ettore) ha le sue righe «ereditato», con la seconda successione; il debito sta sulla rata del primo defunto. Il suo versamento si registra a nome suo come
 * quello di ogni erede: la riga sulla rata del primo defunto con il legame a lui, la coppia legata alla seconda successione (lui − y, il primo defunto + y).
 * «Versato da» lui sulla rata del primo defunto non si divide fra gli altri: si rifiuta, con la frase che porta alla sua riga.
 */
final class VersamentoDegliEredi
{
    /**
     * Perché la coppia nel Wallet non si cambia a mano (il rischio 2 della strada della decisione 529): le letture leggono il versamento dal giornale, il piano
     * dalla coppia; cambiata una sola delle due cose si separano. Le stesse parole nel Wallet, alla modifica e all'eliminazione.
     */
    public const FRASE_COPPIA_NEL_WALLET = 'Questa riga è il versamento di un erede sul debito ereditato, scritta con l\'incasso: non si modifica e non si cancella da sola. '
        . 'Si toglie stornando l\'incasso; se un piano l\'ha già presa, lo storno scrive la riga opposta.';

    /**
     * Decisione 539: a chi è intestata la ricevuta del versamento di un erede sul debito ereditato. Di serie all'erede che ha pagato; l'amministratore la può
     * intestare agli eredi del defunto («Eredi di Massimo Bagnara», una ricevuta unica) o al defunto (un bonifico dal suo conto, come prima). La scelta resta
     * con il versamento, nel registro del passaggio (`versamenti_degli_eredi`, `intestatario_ricevuta`): senza la chiave vale quella di serie.
     */
    public const RICEVUTA_ALL_EREDE = 'erede';

    public const RICEVUTA_AGLI_EREDI = 'eredi';

    public const RICEVUTA_AL_DEFUNTO = 'defunto';

    public const INTESTATARI_DELLA_RICEVUTA = [self::RICEVUTA_ALL_EREDE, self::RICEVUTA_AGLI_EREDI, self::RICEVUTA_AL_DEFUNTO];

    /** Una riga della coppia di un versamento dell'erede: legata al passaggio e scritta dal programma (le righe legate scritte dal Wallet sono «manuale»). */
    public static function rigaDellaCoppia(Saldo $saldo): bool
    {
        return $saldo->passaggio_id !== null && $saldo->origine === 'automatico';
    }

    /**
     * Le righe del Wallet senza le coppie dei versamenti degli eredi ({@see rigaDellaCoppia()}, la stessa regola in una query). Una coppia è legata al
     * passaggio ma non è un saldo della successione: è un versamento, e un controllo sulla parte che passa agli eredi non la conta (l'avviso della competenza
     * del nuovo piano, `DefuntoNelPiano`; visto a video nella beta.12, decisione 538).
     *
     * @template T of \Illuminate\Database\Eloquent\Builder
     * @param T $query
     * @return T
     */
    public static function senzaLeCoppie(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where(fn ($q) => $q->whereNull('passaggio_id')->orWhere('origine', '!=', 'automatico'));
    }

    /**
     * Il debito ereditato di una persona che si può incassare a nome suo: per successione e gestione, le righe «ereditato» di «Rate scadute» alla data (di
     * serie oggi), non ancora in un piano. Anche le righe della catena (decisione 530, Q5 della quarta prova): sono dell'ultimo erede, con la seconda
     * successione, e `da` lo dice («Marco, che l'aveva ereditato da Ettore Fabbri»); ogni riga dice di chi è la rata a giornale (`di`, il primo defunto).
     *
     * @param array<string, mixed>|null $lettura la lettura del debito ereditato alla data, se chi chiama l'ha già ({@see DebitoEreditato::perLaLettura()})
     * `defunto` è il nome del defunto della successione senza la catena, e `catena` dice se una parte del debito gli arrivava da una successione di prima: la
     * pagina dell'incasso offre «Eredi di …» e «… (il defunto)» per la ricevuta solo senza la catena (decisione 539).
     *
     * @return list<array{subentro_id: int, da: string, defunto: string, catena: bool, da_anagrafica_id: int, successione_del: string, gestione_id: int, aperto: int, righe: list<array{rata_id: int, immobile_id: int, parte: int, scadenza: string, di: int}>}>
     */
    public function apertiDi(int $condominioId, int $erede, ?string $alla = null, ?array $lettura = null): array
    {
        $alla ??= DateHelper::oggiUtente();
        $linee = ($lettura ?? (new DebitoEreditato())->perLaLettura($condominioId, $alla))['eredi'][$erede] ?? [];
        $gruppi = [];
        foreach ($linee as $l) {
            $e = $l['ereditato'];
            if ($e['nelle_rate'] !== [] || (int) $e['fuori_rata'] <= 0) {
                continue;
            }
            $k = (int) $e['subentro_id'] . '|' . (int) $l['gestione_id'];
            $daPrima = (string) ($e['catena']['da'] ?? '');
            $da = (string) $e['da'] . ($daPrima !== '' ? sprintf(', che l\'aveva ereditato da %s', $daPrima) : '');
            $gruppi[$k] ??= ['subentro_id' => (int) $e['subentro_id'], 'da' => $da, 'defunto' => (string) $e['da'], 'catena' => false, 'da_anagrafica_id' => (int) $e['da_anagrafica_id'],
                'successione_del' => (string) $e['successione_del'], 'gestione_id' => (int) $l['gestione_id'], 'aperto' => 0, 'righe' => []];
            $gruppi[$k]['catena'] = $gruppi[$k]['catena'] || $daPrima !== '';
            $gruppi[$k]['aperto'] += (int) $e['fuori_rata'];
            $gruppi[$k]['righe'][] = ['rata_id' => (int) $l['rata_id'], 'immobile_id' => (int) $l['immobile_id'], 'parte' => (int) $e['fuori_rata'], 'scadenza' => (string) $l['scadenza'],
                'di' => (int) ($e['defunto_della_rata'] ?? $e['da_anagrafica_id'])];
        }
        // Solo finché nessun piano ha preso le righe dell'arretrato dell'erede in quella gestione.
        $esito = [];
        foreach ($gruppi as $g) {
            $s = Subentro::find($g['subentro_id']);
            if ($s === null || ! $this->arretratoLibero($s, $erede, $g['gestione_id'])) {
                continue;
            }
            $esito[] = $g;
        }

        return $esito;
    }

    /** Le righe dell'arretrato dell'erede in quella gestione non sono ancora in un piano. */
    public function arretratoLibero(Subentro $s, int $erede, int $gestioneId): bool
    {
        return ! Saldo::whereIn('id', $s->saldiDellArretrato() ?: [0])->where('anagrafica_id', $erede)->where('gestione_id', $gestioneId)->where('is_applicato', true)->exists();
    }

    /**
     * Le successioni in cui il pagante è il defunto, con l'arretrato agli eredi non ancora in un piano, sulle cui unità cadono le quote che l'incasso paga: per
     * la domanda «agli eredi» (decisione 529). Con i nomi e la frase delle conseguenze.
     *
     * Q5 della quarta prova (la catena): gli eredi sono quelli di oggi, un erede morto a sua volta con l'arretrato ai suoi eredi lascia il posto a loro, per
     * la sua quota (`eredi`, con `catena`: erede di oggi => la sua successione e da chi il debito gli arriva); la domanda non nomina più chi è morto.
     *
     * @param list<int> $rateIds le rate (non le quote) che l'incasso paga con il denaro
     * @return list<array{successione: Subentro, eredi: array<int, float>, catena: array<int, array{successione: Subentro, da: string}>, nomi: string, defunto: string, decesso: string, unita: list<int>}>
     */
    public function delDefunto(int $condominioId, int $pagante, array $rateIds): array
    {
        if ($rateIds === []) {
            return [];
        }
        $de = new DebitoEreditato();
        $esito = [];
        foreach ($de->successioni($condominioId) as $s) {
            if ((int) $s->anagrafica_uscente_id !== $pagante) {
                continue;
            }
            $unita = $this->unitaDi($s);
            $tocca = RataQuote::whereIn('rata_id', $rateIds)->where('anagrafica_id', $pagante)->whereIn('immobile_id', $unita)->where('importo', '>', 0)->exists();
            if (! $tocca) {
                continue;
            }
            $pesi = [];
            foreach ((array) ($s->registro['eredi'] ?? []) as $e) {
                $pesi[(int) $e['anagrafica_id']] = (float) $e['quota'];
            }
            $liberi = Saldo::whereIn('id', $s->saldiDellArretrato() ?: [0])->whereIn('anagrafica_id', array_keys($pesi) ?: [0])->where('is_applicato', true)->doesntExist();
            if (! $liberi || $pesi === []) {
                continue;
            }
            $oggi = $this->erediDiOggi($de, $s);
            $esito[] = ['successione' => $s, 'eredi' => $oggi['pesi'], 'catena' => $oggi['catena'], 'nomi' => $oggi['nomi'],
                'defunto' => (string) (Anagrafica::find($pagante)?->nome ?? ($s->registro['nomi']['uscente'] ?? '')), 'decesso' => $s->decorrenza->format('Y-m-d'), 'unita' => $unita];
        }

        return $esito;
    }

    /**
     * Decisione 529, dopo il piano: le successioni in cui il pagante è il defunto, sulle cui unità cadono le quote che l'incasso paga, con le righe
     * dell'arretrato degli eredi già prese da un piano. Il debito è nella rata nuova degli eredi: dove (`dove`, «nella Rata 1 — Preventivo 2027») e se la
     * rata è emessa.
     *
     * @param list<int> $rateIds le rate (non le quote) che l'incasso paga con il denaro
     * @return list<array{successione: Subentro, nomi: string, defunto: string, decesso: string, dove: string, emessa: bool}>
     */
    public function nelPianoDegliEredi(int $condominioId, int $pagante, array $rateIds): array
    {
        if ($rateIds === []) {
            return [];
        }
        $esito = [];
        $de = new DebitoEreditato();
        foreach ($de->successioni($condominioId) as $s) {
            if ((int) $s->anagrafica_uscente_id !== $pagante
                || ! RataQuote::whereIn('rata_id', $rateIds)->where('anagrafica_id', $pagante)->whereIn('immobile_id', $this->unitaDi($s))->where('importo', '>', 0)->exists()) {
                continue;
            }
            $dove = $this->doveNelPiano($s);
            if ($dove === null) {
                continue;
            }
            $esito[] = ['successione' => $s, 'nomi' => $this->erediDiOggi($de, $s)['nomi'],
                'defunto' => (string) (Anagrafica::find($pagante)?->nome ?? ($s->registro['nomi']['uscente'] ?? '')), 'decesso' => $s->decorrenza->format('Y-m-d')] + $dove;
        }

        return $esito;
    }

    /**
     * Dov'è il debito ereditato quando un piano ha preso le righe dell'arretrato degli eredi: la rata che porta i saldi, come il piano li divide («nella Rata 1 —
     * Preventivo 2027», «nella Rata 0 (pregresso) — …», «nelle rate di «…»»), e se è emessa. Null se nessun piano le ha prese.
     *
     * @return array{dove: string, emessa: bool}|null
     */
    public function doveNelPiano(Subentro $s): ?array
    {
        $eredi = array_values(array_filter(array_map(fn ($e) => (int) ($e['anagrafica_id'] ?? 0), (array) ($s->registro['eredi'] ?? []))));
        $pianoId = Saldo::whereIn('id', $s->saldiDellArretrato() ?: [0])->whereIn('anagrafica_id', $eredi ?: [0])->where('is_applicato', true)->whereNotNull('piano_rate_id')
            ->orderBy('piano_rate_id')->value('piano_rate_id');
        $piano = $pianoId === null ? null : \App\Models\Gestionale\PianoRate::find((int) $pianoId);
        if ($piano === null) {
            return null;
        }
        $rate = DB::table('rate')->where('piano_rate_id', $piano->id)->orderBy('numero_rata')->get(['numero_rata', 'stato']);
        [$dove, $quali] = match ($piano->metodo_distribuzione) {
            'prima_rata' => ['nella Rata 1 — ' . $piano->nome, $rate->where('numero_rata', 1)],
            'rata_zero' => ['nella Rata 0 (pregresso) — ' . $piano->nome, $rate->where('numero_rata', 0)],
            'tutte_rate' => ['nelle rate di «' . $piano->nome . '»', $rate->where('numero_rata', '>', 0)],
            default => ['nel piano «' . $piano->nome . '»', $rate],
        };

        return ['dove' => $dove, 'emessa' => $quali->isNotEmpty() && $quali->contains(fn ($r) => $r->stato === 'emessa')];
    }

    /**
     * Le parti ancora aperte di ogni erede sulle rate del defunto, alla data (di serie oggi): rata|unità => erede => centesimi. Si leggono prima di scrivere
     * l'incasso, per dividere il versamento senza superare la parte di nessuno. Con la catena anche le parti degli eredi di oggi (la seconda successione)
     * sulle rate di questo defunto.
     *
     * @return array<string, array<int, int>>
     */
    public function partiAperte(int $condominioId, Subentro $s, ?string $alla = null): array
    {
        $alla ??= DateHelper::oggiUtente();
        $esito = [];
        $catena = array_flip(array_map(fn ($c) => (int) $c['successione']->id, $this->erediDiOggi(new DebitoEreditato(), $s)['catena']));
        foreach ((new DebitoEreditato())->perLaLettura($condominioId, $alla)['eredi'] as $erede => $linee) {
            foreach ($linee as $l) {
                $suo = (int) $l['ereditato']['subentro_id'] === (int) $s->id
                    || (isset($catena[(int) $l['ereditato']['subentro_id']]) && (int) ($l['ereditato']['defunto_della_rata'] ?? 0) === (int) $s->anagrafica_uscente_id);
                if (! $suo) {
                    continue;
                }
                $k = \App\Services\Gestionale\Debiti\AccordiRientro::chiave($l['rata_id'], $l['immobile_id']);
                $esito[$k][(int) $erede] = ($esito[$k][(int) $erede] ?? 0) + (int) $l['residuo'];
            }
        }

        return $esito;
    }

    /**
     * La strada dell'erede pagante: il versamento sulla rata del defunto, dalla più vecchia, con il legame all'erede, e la coppia nel Wallet. Dentro la
     * transazione dell'incasso, dopo le sue righe; il tetto (la parte dell'erede ancora aperta) lo ha già controllato l'azione. Con la catena la rata è del
     * primo defunto (`di`), e la coppia è legata alla successione dell'erede (la seconda): lui − y, il primo defunto + y.
     *
     * Decisione 539: `$intestatario` è la scelta di «Intestatario della ricevuta» ({@see INTESTATARI_DELLA_RICEVUTA}); resta con il versamento nel registro
     * del passaggio. Quella di serie (l'erede) non si scrive: senza la chiave vale lei.
     *
     * @param array{subentro_id: int, da: string, righe: list<array{rata_id: int, immobile_id: int, parte: int, di?: int}>} $aperto {@see apertiDi()}
     */
    public function registraDellErede(ScritturaContabile $incasso, int $erede, array $aperto, int $centesimi, string $data, int $contoCrediti, ?string $intestatario = null): void
    {
        $s = Subentro::query()->lockForUpdate()->findOrFail((int) $aperto['subentro_id']);
        $defunto = (int) $s->anagrafica_uscente_id;
        $nomeErede = (string) (Anagrafica::find($erede)?->nome ?? '');
        $resta = $centesimi;
        $perGruppo = [];
        foreach ($aperto['righe'] as $r) {
            if ($resta <= 0) {
                break;
            }
            $y = min($resta, (int) $r['parte']);
            $di = (int) ($r['di'] ?? $defunto) ?: $defunto;
            $quote = RataQuote::with('rata.pianoRate')->where('rata_id', (int) $r['rata_id'])->where('anagrafica_id', $di)->where('immobile_id', (int) $r['immobile_id'])
                ->where('importo', '>', 0)->lockForUpdate()->orderBy('id')->get();
            foreach ($quote as $q) {
                $z = min($y, max(0, (int) $q->importo - (int) $q->importo_pagato));
                if ($z <= 0) {
                    continue;
                }
                $q->pagamenti()->attach($incasso->id, ['importo_pagato' => $z, 'data_pagamento' => $data]);
                $incasso->righe()->create([
                    'conto_contabile_id' => $contoCrediti,
                    'anagrafica_id' => $di,
                    'rata_id' => $q->rata_id,
                    'immobile_id' => $q->immobile_id,
                    'tipo_riga' => 'avere',
                    'importo' => $z,
                    'note' => 'Incasso rata n.' . ($q->rata->numero_rata ?? '') . ", versato da {$nomeErede} sul debito ereditato da " . $aperto['da'],
                    'riferimento_type' => Anagrafica::class,
                    'riferimento_id' => $erede,
                ]);
                $q->ricalcolaStato();
                $gk = (int) $q->rata->pianoRate->gestione_id . '|' . (int) $q->immobile_id . '|' . $di;
                $perGruppo[$gk] = ($perGruppo[$gk] ?? 0) + $z;
                $y -= $z;
                $resta -= $z;
            }
        }
        if ($resta > 0) {
            throw new \App\Exceptions\Gestionale\DebitoEreditatoNonIncassabileException(sprintf('Il debito ereditato di %s da %s è cambiato nel frattempo: rileggi la pagina.',
                $nomeErede, $aperto['da']));
        }
        $coppie = [];
        foreach ($perGruppo as $gk => $cifra) {
            [$g, $u, $di] = array_map('intval', explode('|', $gk));
            $coppie[] = $this->coppia($s, $erede, $g, $u, $cifra, $data, $nomeErede, false, $di);
        }
        $this->ricorda($s, $incasso, $coppie, in_array($intestatario, [self::RICEVUTA_AGLI_EREDI, self::RICEVUTA_AL_DEFUNTO], true) ? $intestatario : null);
    }

    /**
     * La strada del defunto pagante, con la scelta «agli eredi»: il versamento sulle sue rate delle unità del passaggio copre il debito ereditato. Con «Versato
     * da» un erede, la sua parte; senza, o per quello che supera la sua parte, gli eredi per quota, ognuno al più la sua parte aperta (quello che resta oltre
     * resta del defunto). Dal decesso in poi le righe dell'incasso si dividono, una per erede con il suo legame; con la data di prima solo la coppia. Con la
     * catena (Q5 della quarta prova) gli eredi sono quelli di oggi ({@see erediDiOggi()}): la parte di un erede morto va ai suoi eredi, con la loro coppia
     * legata alla loro successione.
     *
     * @param array<string, array<int, int>> $aperte {@see partiAperte()}, lette prima dell'incasso
     */
    public function registraAgliEredi(ScritturaContabile $incasso, Subentro $s, array $aperte, ?int $versatoDa, string $data): void
    {
        $s = Subentro::query()->lockForUpdate()->findOrFail((int) $s->id);
        $defunto = (int) $s->anagrafica_uscente_id;
        ['pesi' => $pesi, 'catena' => $catena] = $this->erediDiOggi(new DebitoEreditato(), $s);
        $dopoIlDecesso = $data >= $s->decorrenza->format('Y-m-d');
        $nomi = Anagrafica::whereIn('id', array_keys($pesi))->pluck('nome', 'id');
        $righe = $incasso->righe()->where('tipo_riga', 'avere')->where('anagrafica_id', $defunto)->whereNotNull('rata_id')->whereIn('immobile_id', $this->unitaDi($s))
            ->with('rata.pianoRate')->orderBy('id')->get();
        $perErede = [];
        foreach ($righe as $riga) {
            $k = \App\Services\Gestionale\Debiti\AccordiRientro::chiave($riga->rata_id, $riga->immobile_id);
            $parti = $dopoIlDecesso ? $this->dividi((int) $riga->importo, $pesi, $aperte[$k] ?? [], $versatoDa)
                : array_filter(array_map('intval', MoneyHelper::ripartisciPerQuote((int) $riga->importo, $pesi)), fn ($y) => $y > 0);
            if ($parti === []) {
                continue;
            }
            foreach ($parti as $erede => $y) {
                $aperte[$k][$erede] = max(0, ($aperte[$k][$erede] ?? 0) - $y);
            }
            $gk = (int) $riga->rata->pianoRate->gestione_id . '|' . (int) $riga->immobile_id;
            foreach ($parti as $erede => $y) {
                $perErede[$erede][$gk] = ($perErede[$erede][$gk] ?? 0) + $y;
            }
            if (! $dopoIlDecesso) {
                continue;
            }
            // La riga dell'incasso si divide: una per erede, con il suo legame; quello che supera le parti resta sulla riga, del defunto.
            $resto = (int) $riga->importo - (int) array_sum($parti);
            $nota = (string) $riga->note;
            $prima = true;
            foreach ($parti as $erede => $y) {
                $dati = ['importo' => $y, 'riferimento_type' => Anagrafica::class, 'riferimento_id' => $erede,
                    'note' => 'Incasso rata n.' . ($riga->rata->numero_rata ?? '') . ', per il debito ereditato di ' . ($nomi[$erede] ?? '')];
                if ($prima && $resto === 0) {
                    $riga->update($dati);
                } else {
                    $incasso->righe()->create(array_merge($riga->only(['conto_contabile_id', 'anagrafica_id', 'rata_id', 'immobile_id', 'tipo_riga']), $dati));
                }
                $prima = false;
            }
            if ($resto > 0 && $parti !== []) {
                // Oltre le parti degli eredi la riga resta del defunto, senza il legame a un erede: le letture non la contano come un suo versamento.
                $riga->update(['importo' => $resto, 'note' => $nota]
                    + (isset($pesi[(int) $riga->riferimento_id]) ? ['riferimento_type' => null, 'riferimento_id' => null] : []));
            }
        }
        $coppie = [];
        $dellaCatena = [];
        foreach ($perErede as $erede => $gruppi) {
            foreach ($gruppi as $gk => $cifra) {
                [$g, $u] = array_map('intval', explode('|', $gk));
                if (isset($catena[(int) $erede])) {
                    $sx = $catena[(int) $erede]['successione'];
                    $dellaCatena[(int) $sx->id][] = $this->coppia($sx, (int) $erede, $g, $u, $cifra, $data, (string) ($nomi[$erede] ?? ''), false, $defunto);

                    continue;
                }
                $coppie[] = $this->coppia($s, (int) $erede, $g, $u, $cifra, $data, (string) ($nomi[$erede] ?? ''));
            }
        }
        $this->ricorda($s, $incasso, $coppie);
        foreach ($dellaCatena as $id => $sue) {
            $this->ricorda(Subentro::query()->lockForUpdate()->findOrFail($id), $incasso, $sue);
        }
    }

    /**
     * Lo storno di un incasso che aveva portato coppie nel Wallet: se nessun piano ha preso le due righe, si tolgono; altrimenti si scrive la coppia rovescia,
     * legata allo stesso passaggio, che il piano dopo compensa. Il registro del passaggio lo dice.
     */
    public function storna(ScritturaContabile $incasso, string $oggi): void
    {
        foreach (Subentro::query()->where('condominio_id', $incasso->condominio_id)->where('tipo_passaggio', 'successione')->whereNull('subentro_padre_id')->get() as $s) {
            $voci = (array) ($s->registro['versamenti_degli_eredi'] ?? []);
            $mie = array_filter($voci, fn ($v) => (int) ($v['scrittura_id'] ?? 0) === (int) $incasso->id && ($v['stornato_il'] ?? null) === null);
            if ($mie === []) {
                continue;
            }
            $s = Subentro::query()->lockForUpdate()->findOrFail($s->id);
            $voci = (array) ($s->registro['versamenti_degli_eredi'] ?? []);
            foreach ($voci as $i => $v) {
                if ((int) ($v['scrittura_id'] ?? 0) !== (int) $incasso->id || ($v['stornato_il'] ?? null) !== null) {
                    continue;
                }
                $rovesce = [];
                foreach ((array) ($v['coppie'] ?? []) as $c) {
                    $righe = Saldo::whereIn('id', array_map('intval', (array) ($c['saldi'] ?? [])))->lockForUpdate()->get();
                    if ($righe->count() === count((array) ($c['saldi'] ?? [])) && $righe->every(fn (Saldo $r) => ! $r->is_applicato)) {
                        Saldo::whereIn('id', $righe->pluck('id')->all())->delete();

                        continue;
                    }
                    $rovesce[] = $this->coppia($s, (int) $c['erede'], (int) $c['gestione_id'], (int) $c['immobile_id'], (int) $c['importo'], $oggi,
                        (string) (Anagrafica::find((int) $c['erede'])?->nome ?? ''), true, isset($c['controparte']) ? (int) $c['controparte'] : null);
                }
                $voci[$i]['stornato_il'] = $oggi;
                if ($rovesce !== []) {
                    $voci[$i]['rovesce'] = $rovesce;
                }
            }
            $registro = $s->registro ?? [];
            $registro['versamenti_degli_eredi'] = array_values($voci);
            $s->forceFill(['registro' => $registro])->save();
        }
    }

    /**
     * Decisione 538: come si legge, nel dettaglio dell'incasso, un versamento che ha scritto le coppie nel Wallet. La scrittura resta quella della decisione
     * 529 (la riga sulla rata del defunto con il legame all'erede): la pagina la leggeva come un versamento del defunto «versato da» l'erede, e diceva
     * «Soggetto pagante: Massimo Bagnara» per un pagamento di Cecilia. Le coppie si leggono dal registro del passaggio, anche se l'incasso è stato poi
     * stornato (la voce resta, con `stornato_il`): il dettaglio di un incasso annullato dice chi aveva pagato.
     *
     * - `erede`: chi ha pagato davvero, quando è un erede solo e tutte le righe dell'incasso sulle quote d'altri portano il legame a lui (la strada
     *   «Debito ereditato da …» dell'erede pagante, o «Per il debito degli eredi» con «Versato da» lui e la sua parte intera). Con il versamento dal conto
     *   del defunto per quota (più eredi), con una parte che resta al defunto, o con la data di prima del decesso (solo la coppia, nessun legame) è null:
     *   ha pagato la posizione, e `eredi` dice di chi è il debito che il versamento copre.
     * - `di`: di chi era il debito ereditato («Massimo Bagnara»; con la catena «Marco, che l'aveva ereditato da Ettore Fabbri»).
     * - `sul_debito_ereditato`: i centesimi che le coppie hanno portato al debito ereditato di chi ha pagato (o di tutti gli eredi, senza un erede pagante).
     *
     * Decisione 539, con un erede pagante (`erede` non null), per la ricevuta: `intestatario` ({@see INTESTATARI_DELLA_RICEVUTA}: la scelta scritta con il
     * versamento, di serie l'erede; con la catena sempre l'erede, perché «Eredi di …» non direbbe di quale dei due defunti), `defunto` (il nome del defunto
     * della successione, senza la catena, per «Eredi di …»), `successione_del` (aaaa-mm-gg) e `quota` (la parte dell'erede in percentuale, scritta come
     * nelle frasi del debito ereditato: «50», «33,33») per «Per il debito ereditato da» della ricevuta. Senza un erede pagante sono null.
     *
     * @return array{erede: ?int, nome_erede: ?string, di: string, eredi: list<int>, nomi_eredi: string, sul_debito_ereditato: int, intestatario: ?string, defunto: ?string, successione_del: ?string, quota: ?string}|null null senza coppie
     */
    public function nelDettaglio(ScritturaContabile $incasso): ?array
    {
        return $this->deiVersamenti([(int) $incasso->id])[(int) $incasso->id] ?? null;
    }

    /**
     * La lettura di {@see nelDettaglio()} per più incassi insieme, con poche query anche su migliaia di incassi: l'elenco degli incassi (decisione 539 (5),
     * le stesse regole del dettaglio) e quello delle ricevute ({@see \App\Services\Gestionale\Ricevute\PersoneDegliIncassi}). Gli incassi senza coppie non ci
     * sono.
     *
     * @param array<int, int> $ids
     * @return array<int, array{erede: ?int, nome_erede: ?string, di: string, eredi: list<int>, nomi_eredi: string, sul_debito_ereditato: int, intestatario: ?string, defunto: ?string, successione_del: ?string, quota: ?string}>
     */
    public function deiVersamenti(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $voluti = array_flip($ids);
        $condomini = [];
        foreach (array_chunk($ids, 500) as $blocco) {
            foreach (DB::table('scritture_contabili')->whereIn('id', $blocco)->distinct()->pluck('condominio_id') as $c) {
                $condomini[(int) $c] = true;
            }
        }
        // Le coppie di ogni incasso, dal registro dei passaggi dei suoi condomini, con la scelta della ricevuta scritta con il versamento.
        $perIncasso = [];
        foreach (Subentro::query()->whereIn('condominio_id', array_keys($condomini) ?: [0])->where('tipo_passaggio', 'successione')->whereNull('subentro_padre_id')
            ->orderBy('id')->get() as $s) {
            foreach ((array) ($s->registro['versamenti_degli_eredi'] ?? []) as $v) {
                $id = (int) ($v['scrittura_id'] ?? 0);
                if (! isset($voluti[$id])) {
                    continue;
                }
                $perIncasso[$id]['intestatario'] ??= in_array($v['intestatario_ricevuta'] ?? null, self::INTESTATARI_DELLA_RICEVUTA, true) ? $v['intestatario_ricevuta'] : null;
                foreach ((array) ($v['coppie'] ?? []) as $c) {
                    $perIncasso[$id]['coppie'][] = ['s' => $s, 'erede' => (int) ($c['erede'] ?? 0), 'importo' => (int) ($c['importo'] ?? 0),
                        'controparte' => isset($c['controparte']) ? (int) $c['controparte'] : null];
                }
            }
        }
        $perIncasso = array_filter($perIncasso, fn (array $x) => ($x['coppie'] ?? []) !== []);
        if ($perIncasso === []) {
            return [];
        }
        $tutte = array_merge(...array_map(fn (array $x) => $x['coppie'], array_values($perIncasso)));
        $nomi = Anagrafica::whereIn('id', array_values(array_unique(array_merge(array_column($tutte, 'erede'), array_filter(array_column($tutte, 'controparte')),
            array_map(fn (array $c) => (int) $c['s']->anagrafica_uscente_id, $tutte)))) ?: [0])->pluck('nome', 'id');
        // Le righe AVERE degli incassi con un erede solo: per sapere se l'ha pagato lui.
        $unoSolo = array_keys(array_filter($perIncasso, fn (array $x) => count(array_unique(array_column($x['coppie'], 'erede'))) === 1));
        $righe = collect();
        foreach (array_chunk($unoSolo, 500) as $blocco) {
            $righe = $righe->concat(DB::table('righe_scritture')->whereIn('scrittura_id', $blocco)->where('tipo_riga', 'avere')->whereNotNull('anagrafica_id')
                ->get(['scrittura_id', 'anagrafica_id', 'riferimento_type', 'riferimento_id']));
        }
        $righe = $righe->groupBy('scrittura_id');

        $esito = [];
        foreach ($perIncasso as $id => $x) {
            $coppie = $x['coppie'];
            $eredi = array_values(array_unique(array_column($coppie, 'erede')));
            $defuntoDi = fn (Subentro $s) => (string) ($s->registro['nomi']['uscente'] ?? ($nomi[(int) $s->anagrafica_uscente_id] ?? ''));
            // Di chi era il debito: il defunto della successione e, con la catena, da chi l'aveva ereditato (la stessa frase di «Debito ereditato da …»).
            $di = array_values(array_unique(array_map(fn (array $c) => $defuntoDi($c['s'])
                . ($c['controparte'] !== null && $c['controparte'] !== (int) $c['s']->anagrafica_uscente_id ? sprintf(', che l\'aveva ereditato da %s', $nomi[$c['controparte']] ?? '') : ''), $coppie)));
            // L'erede ha pagato lui se è uno solo e ogni riga AVERE dell'incasso su quote d'altri porta il legame a lui; le sue quote sono le sue rate.
            $erede = null;
            if (count($eredi) === 1) {
                $dAltri = ($righe[$id] ?? collect())->filter(fn ($r) => (int) $r->anagrafica_id !== $eredi[0]);
                $conIlLegame = fn ($r) => $r->riferimento_type === Anagrafica::class && (int) $r->riferimento_id === $eredi[0];
                if ($dAltri->isNotEmpty() && $dAltri->every($conIlLegame)) {
                    $erede = $eredi[0];
                }
            }
            // Decisione 539: per la ricevuta, la successione dell'erede (la prima delle sue coppie) e la sua quota; con la catena la scelta è sempre l'erede.
            $ricevuta = ['intestatario' => null, 'defunto' => null, 'successione_del' => null, 'quota' => null];
            if ($erede !== null) {
                $s = $coppie[0]['s'];
                $pesi = [];
                foreach ((array) ($s->registro['eredi'] ?? []) as $e) {
                    $pesi[(int) $e['anagrafica_id']] = (float) $e['quota'];
                }
                $somma = array_sum($pesi);
                $catena = collect($coppie)->contains(fn (array $c) => $c['controparte'] !== null && $c['controparte'] !== (int) $c['s']->anagrafica_uscente_id);
                $ricevuta = [
                    'intestatario' => $catena ? self::RICEVUTA_ALL_EREDE : ($x['intestatario'] ?? self::RICEVUTA_ALL_EREDE),
                    'defunto' => $defuntoDi($s),
                    'successione_del' => $s->decorrenza?->format('Y-m-d'),
                    'quota' => DebitoEreditato::percentuale($somma > 0 ? ($pesi[$erede] ?? 0) / $somma * 100 : 0),
                ];
            }

            $esito[$id] = [
                'erede' => $erede,
                'nome_erede' => $erede !== null ? (string) ($nomi[$erede] ?? '') : null,
                'di' => RegoleDegliEredi::elenco($di),
                'eredi' => $eredi,
                'nomi_eredi' => RegoleDegliEredi::elenco(array_map(fn (int $e) => (string) ($nomi[$e] ?? ''), $eredi)),
                'sul_debito_ereditato' => (int) array_sum(array_column($coppie, 'importo')),
            ] + $ricevuta;
        }

        return $esito;
    }

    /**
     * La parte di ogni erede in un versamento sulla rata del defunto: prima chi ha versato, se è un erede, fino alla sua parte; il resto per quota agli altri,
     * ognuno fino alla sua parte. Quello che supera le parti non si divide.
     *
     * @param array<int, float> $pesi
     * @param array<int, int> $aperte erede => parte ancora aperta
     * @return array<int, int>
     */
    private function dividi(int $cifra, array $pesi, array $aperte, ?int $versatoDa): array
    {
        $parti = [];
        if ($versatoDa !== null && isset($pesi[$versatoDa])) {
            $y = min($cifra, (int) ($aperte[$versatoDa] ?? 0));
            if ($y > 0) {
                $parti[$versatoDa] = $y;
                $cifra -= $y;
                $aperte[$versatoDa] -= $y;
            }
        }
        // Per quota, e quello che una parte non prende va alle altre che hanno ancora posto (al più due giri: le parti sono poche).
        for ($giro = 0; $giro < 3 && $cifra > 0; $giro++) {
            $conPosto = array_filter($pesi, fn ($p, $e) => (int) ($aperte[$e] ?? 0) > 0, ARRAY_FILTER_USE_BOTH);
            if ($conPosto === []) {
                break;
            }
            foreach (MoneyHelper::ripartisciPerQuote($cifra, $conPosto) as $erede => $y) {
                $y = min((int) $y, (int) ($aperte[(int) $erede] ?? 0));
                if ($y > 0) {
                    $parti[(int) $erede] = ($parti[(int) $erede] ?? 0) + $y;
                    $aperte[(int) $erede] -= $y;
                    $cifra -= $y;
                }
            }
        }
        ksort($parti);

        return $parti;
    }

    /**
     * Le due righe della coppia nel Wallet, legate al passaggio: l'erede − y, il defunto + y (con `$rovescia` i segni al contrario, per lo storno). L'esercizio
     * è quello delle righe dell'arretrato dell'erede su quella gestione e unità. Con la catena il + va al defunto della rata (`$controparte`, il primo
     * defunto): è il suo giornale che il versamento ha chiuso.
     *
     * @return array{erede: int, gestione_id: int, immobile_id: int, importo: int, saldi: list<int>, controparte?: int}
     */
    private function coppia(Subentro $s, int $erede, int $gestioneId, int $immobileId, int $cifra, string $data, string $nomeErede, bool $rovescia = false,
        ?int $controparte = null): array
    {
        $esercizioId = (int) (Saldo::whereIn('id', $s->saldiDellArretrato() ?: [0])->where('anagrafica_id', $erede)->where('gestione_id', $gestioneId)
            ->where('immobile_id', $immobileId)->value('esercizio_id')
            ?? Saldo::whereIn('id', $s->saldiDellArretrato() ?: [0])->where('gestione_id', $gestioneId)->value('esercizio_id'));
        $defunto = (int) $s->anagrafica_uscente_id;
        $nomeDefunto = (string) ($s->registro['nomi']['uscente'] ?? (Anagrafica::find($defunto)?->nome ?? ''));
        $piu = $controparte !== null && $controparte > 0 ? $controparte : $defunto;
        $descrizione = $rovescia
            ? sprintf('Storno del versamento di %s sul debito ereditato da %s (%s)', $nomeErede, $nomeDefunto, self::giorno($data))
            : sprintf('Versamento di %s sul debito ereditato da %s (%s)', $nomeErede, $nomeDefunto, self::giorno($data));
        $ids = [];
        foreach ([[$erede, -$cifra], [$piu, $cifra]] as [$chi, $importo]) {
            $ids[] = (int) Saldo::create(['esercizio_id' => $esercizioId, 'condominio_id' => (int) $s->condominio_id, 'gestione_id' => $gestioneId,
                'immobile_id' => $immobileId, 'anagrafica_id' => $chi, 'saldo_iniziale' => $rovescia ? -$importo : $importo, 'origine' => 'automatico',
                'is_applicato' => false, 'passaggio_id' => (int) $s->id, 'descrizione' => $descrizione])->id;
        }

        return ['erede' => $erede, 'gestione_id' => $gestioneId, 'immobile_id' => $immobileId, 'importo' => $cifra, 'saldi' => $ids]
            + ($piu !== $defunto ? ['controparte' => $piu] : []);
    }

    /**
     * Gli eredi di oggi di una successione (decisione 530, Q5 della quarta prova): un erede morto a sua volta, con una successione dopo sulle stesse unità con
     * l'arretrato ai suoi eredi, entro oggi, lascia il posto ai suoi eredi, per la sua quota divisa come la divide la seconda successione (fino a cinque giri).
     * `catena`: erede di oggi => la successione da cui gli arriva e da chi («Marco, che l'aveva ereditato da Ettore Fabbri»); `nomi` per le frasi.
     *
     * @return array{pesi: array<int, float>, catena: array<int, array{successione: Subentro, da: string}>, nomi: string}
     */
    public function erediDiOggi(DebitoEreditato $de, Subentro $s, ?string $oggi = null): array
    {
        $oggi ??= DateHelper::oggiUtente();
        $pesi = [];
        foreach ((array) ($s->registro['eredi'] ?? []) as $e) {
            $pesi[(int) $e['anagrafica_id']] = (float) $e['quota'];
        }
        $nomi = Anagrafica::whereIn('id', array_keys($pesi) ?: [0])->pluck('nome', 'id')->map(fn ($n) => (string) $n)->all();
        $catena = [];
        $unita = $this->unitaDi($s);
        $nomeDefunto = (string) ($s->registro['nomi']['uscente'] ?? (Anagrafica::find((int) $s->anagrafica_uscente_id)?->nome ?? ''));
        for ($giro = 0; $giro < 5; $giro++) {
            $cambiato = false;
            foreach (array_keys($pesi) as $erede) {
                $dopo = $de->successioni((int) $s->condominio_id)->first(fn (Subentro $x) => (int) $x->anagrafica_uscente_id === $erede && $x->decorrenza !== null
                    && $x->decorrenza->format('Y-m-d') > $s->decorrenza->format('Y-m-d') && $x->decorrenza->format('Y-m-d') <= $oggi && array_intersect($this->unitaDi($x), $unita) !== []);
                if ($dopo === null) {
                    continue;
                }
                $peso = $pesi[$erede];
                $da = sprintf('%s, che l\'aveva ereditato da %s', $nomi[$erede] ?? '', $catena[$erede]['da'] ?? $nomeDefunto);
                unset($pesi[$erede], $nomi[$erede], $catena[$erede]);
                $suoi = (array) ($dopo->registro['eredi'] ?? []);
                $somma = array_sum(array_map(fn ($e) => (float) $e['quota'], $suoi));
                $nomiSuoi = Anagrafica::whereIn('id', array_map(fn ($e) => (int) $e['anagrafica_id'], $suoi) ?: [0])->pluck('nome', 'id');
                foreach ($suoi as $e) {
                    $id = (int) $e['anagrafica_id'];
                    $pesi[$id] = ($pesi[$id] ?? 0) + ($somma > 0 ? $peso * (float) $e['quota'] / $somma : 0);
                    $nomi[$id] = (string) ($nomiSuoi[$id] ?? '');
                    $catena[$id] = ['successione' => $dopo, 'da' => $da];
                }
                $cambiato = true;
            }
            if (! $cambiato) {
                break;
            }
        }

        return ['pesi' => $pesi, 'catena' => $catena, 'nomi' => RegoleDegliEredi::elenco(array_values(array_map('strval', $nomi)))];
    }

    /** Il registro del passaggio tiene le coppie scritte, con l'incasso che le ha portate e, se non è quella di serie, a chi è intestata la ricevuta (539). */
    private function ricorda(Subentro $s, ScritturaContabile $incasso, array $coppie, ?string $intestatario = null): void
    {
        if ($coppie === []) {
            return;
        }
        $registro = $s->registro ?? [];
        $registro['versamenti_degli_eredi'] = [...(array) ($registro['versamenti_degli_eredi'] ?? []),
            ['scrittura_id' => (int) $incasso->id, 'il' => $incasso->data_competenza?->format('Y-m-d'), 'coppie' => $coppie]
                + ($intestatario !== null ? ['intestatario_ricevuta' => $intestatario] : [])];
        $s->forceFill(['registro' => $registro])->save();
    }

    /** @return list<int> */
    private function unitaDi(Subentro $s): array
    {
        return array_values(array_unique([(int) $s->immobile_id, ...Subentro::query()->where('subentro_padre_id', $s->id)->pluck('immobile_id')->map(fn ($id) => (int) $id)->all()]));
    }

    private static function giorno(string $data): string
    {
        return substr($data, 8, 2) . '/' . substr($data, 5, 2) . '/' . substr($data, 0, 4);
    }
}
