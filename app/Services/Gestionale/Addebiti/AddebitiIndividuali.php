<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Addebiti;

use App\Enums\CategoriaEventoEnum;
use App\Enums\EventoTipo;
use App\Enums\NaturaGestione;
use App\Enums\StatoPianoRate;
use App\Helpers\DateHelper;
use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\CategoriaEvento;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Evento;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RigaRiparto;
use App\Models\Gestionale\RigaScrittura;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Services\Gestionale\Accessori\AccessoriDaIncassare;
use App\Services\Gestionale\Accessori\BaseDegliAccessori;
use App\Services\Gestionale\EventiRataCondomino;
use App\Services\Gestionale\InboxService;
use App\Services\Gestionale\ScritturaDiEmissione;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gli addebiti a una persona sola: una somma chiesta a lei, fuori dal riparto. Ogni addebito è una rata del piano degli addebiti della sua gestione
 * nell'esercizio ({@see PianoRate::NATURA_ADDEBITI}), con una quota sola, di quella persona su quell'unità, emessa quando si registra: DARE «Crediti
 * verso condomini» con la persona, l'unità e la rata, AVERE «Gestione rate», come le rate. Così la lettura comune dei debiti, l'estratto conto, le
 * lettere, l'incasso, la ricevuta e il portale lo leggono come una rata, senza una seconda strada.
 *
 * La spesa di una sola unità già fatturata (una riga di fattura con l'unità, anticipata dal condominio) porta nel piano degli addebiti la sua riga del
 * riparto «ad personam» con la riga di fattura: l'emissione chiude allora l'anticipazione dell'unità invece di contare la spesa fra le quote, e il
 * conguaglio la conta già chiesta, una volta sola.
 *
 * Un accessorio senza fattura (gli interessi di mora, una spesa pagata dal condominio senza una riga sull'unità) ha la sua contropartita su «Accessori
 * da incassare», non su «Gestione rate», e una voce che riceve il denaro quando la persona lo versa ({@see AccessoriDaIncassare}). Porta il tipo, la
 * base dichiarata con il riferimento e, per gli interessi, il calcolo con i suoi tratti.
 *
 * Un addebito non si riscrive: si annulla finché non ha movimenti, e l'annullamento toglie la scrittura e cancella la rata e la quota (in bozza
 * entrerebbero nell'ordine dei versamenti e nei passaggi di proprietà). Con un incasso, prima si storna l'incasso.
 *
 * I dati dell'addebito stanno nelle regole della quota (`regole_calcolo.addebito`): la natura, la causale, la quota non assegnata da cui viene (il
 * piano e la chiave), la gestione e l'esercizio di origine, la voce, la riga di fattura. Nessuna parola di effetto legale: niente «dovuto», niente
 * «titolo»; `stato_legale` e `origine_tipo` non si scrivono.
 */
final class AddebitiIndividuali
{
    public const NATURA_CONTRIBUTO = 'contributo';

    public const NATURA_ACCESSORIO = 'accessorio';

    /** Le quote non assegnate senza un'unità: l'intera voce, o la parte di una tabella senza millesimi. Non sono la quota di nessuno. */
    public const MOTIVI_SENZA_UNITA = ['conto_senza_tabella', 'tabella_senza_millesimi', 'millesimo_non_compilato'];

    public const FRASE_SENZA_UNITA = 'Questa quota è l\'intera voce, o la parte di una tabella, non la quota di un\'unità: non si addebita a una persona. '
        . 'Sistema la tabella della voce e ricalcola il piano.';

    public const FRASE_NON_ANNULLABILE = 'Questo addebito ha già un incasso, o un credito usato: storna prima l\'incasso dalla pagina degli incassi, poi annulla l\'addebito.';

    public const FRASE_INQUILINO = 'Un addebito va a chi è o è stato proprietario, nudo proprietario o usufruttuario dell\'unità: non a un inquilino, né a chi '
        . 'con l\'unità non ha avuto niente.';

    /** L'annullamento di un addebito di un esercizio chiuso: come le altre scritture di quell'esercizio, non si toglie. */
    public const FRASE_ESERCIZIO_CHIUSO = 'L\'esercizio «%s» è chiuso: l\'addebito resta nei suoi conti.';

    /**
     * L'annullamento di un addebito che una successione ha passato agli eredi (diviso fra loro sulla stessa rata, o con le righe dell'arretrato): le quote e
     * le righe degli eredi resterebbero senza l'addebito, o se ne andrebbero senza il passaggio.
     */
    public const FRASE_PASSATO_AGLI_EREDI = 'Passato agli eredi con la successione del %s: per annullarlo, annulla prima il passaggio.';

    /** L'annullamento di un addebito che un accordo di rientro riconosce, in corso o chiuso: la riga dell'accordo se ne andrebbe con lui. */
    public const FRASE_IN_ACCORDO = 'Questo addebito è nell\'accordo di rientro del %s, che lo riconosce: annullarlo toglierebbe la sua riga dall\'accordo, e un '
        . 'accordo non si cancella. L\'addebito resta.';

    public function __construct(
        private readonly ScritturaDiEmissione $scrittura,
        private readonly EventiRataCondomino $eventi,
        private readonly PersoneDellAddebito $persone,
    ) {}

    /** Il piano degli addebiti della gestione nell'esercizio, se c'è. */
    public function pianoDi(int $gestioneId, int $esercizioId): ?PianoRate
    {
        return PianoRate::degliAddebiti()->where('gestione_id', $gestioneId)->where('esercizio_id', $esercizioId)->first();
    }

    /**
     * Registra gli addebiti dati, ognuno una rata con una quota sola, emessa subito; tutti o nessuno.
     *
     * Ogni voce: `anagrafica_id`, `immobile_id`, `importo` (centesimi, più di zero), `causale`, `scadenza` (Y-m-d); facoltative `chiave` con
     * `piano_origine_id` (la quota non assegnata da cui viene), `riga_fattura_id` (la spesa di una sola unità già fatturata), `conto_id`, `natura`
     * (contributo, di serie; accessorio solo con la riga di fattura), `gestione_origine_id` ed `esercizio_origine_id` (le spese non attribuite del
     * conguaglio da cui viene).
     *
     * @param list<array<string, mixed>> $voci
     * @return list<Rata>
     */
    public function registra(Condominio $condominio, Gestione $gestione, Esercizio $esercizio, array $voci, ?int $utenteId = null, ?string $data = null): array
    {
        if ($voci === []) {
            throw ValidationException::withMessages(['addebiti' => 'Scegli almeno una persona da addebitare.']);
        }
        if (($esercizio->stato ?? 'aperto') !== 'aperto') {
            throw ValidationException::withMessages(['addebiti' => 'L\'esercizio «' . $esercizio->nome . '» è chiuso: l\'addebito si registra nell\'esercizio aperto.']);
        }
        $legata = DB::table('esercizio_gestione')->where('esercizio_id', $esercizio->id)->where('gestione_id', $gestione->id)->exists();
        abort_unless($legata && (int) $gestione->condominio_id === (int) $condominio->id && (int) $esercizio->condominio_id === (int) $condominio->id, 404);

        $conti = ContoContabile::where('condominio_id', $condominio->id)->whereIn('ruolo', ['crediti_condomini', 'gestione_rate', 'passate_gestioni'])
            ->whereNull('deleted_at')->get()->keyBy('ruolo');
        if (! $conti->has('crediti_condomini') || ! $conti->has('gestione_rate')) {
            throw ValidationException::withMessages(['addebiti' => 'Mancano i conti contabili «Crediti verso condomini» o «Gestione rate»: l\'addebito non si scrive.']);
        }

        $data = $this->dataDellAddebito($esercizio, $data);
        $pronte = array_map(fn (array $v) => $this->verifica($condominio, $v, $gestione), $voci);
        // La spesa di una sola unità già fatturata si chiede nell'esercizio della fattura, con la sua gestione: il conguaglio di quell'esercizio la
        // conta chiesta solo dai piani fino a lui.
        foreach ($pronte as $v) {
            $f = $v['fattura'];
            if ($f !== null && $f['aperto'] && ($f['esercizio_id'] !== (int) $esercizio->id || ($f['gestione_id'] !== null && $f['gestione_id'] !== (int) $gestione->id))) {
                throw ValidationException::withMessages(['addebiti' => sprintf('La fattura n. %s è dell\'esercizio «%s»%s: l\'addebito della sua spesa si scrive lì, '
                    . 'così il conguaglio di quell\'esercizio la conta chiesta, una volta sola.', $f['numero'], $f['esercizio'], $f['gestione'] !== null ? ', gestione «' . $f['gestione'] . '»' : '')]);
            }
        }
        // Due voci sulla stessa quota non assegnata nella stessa richiesta: insieme non superano quello che resta.
        $perChiave = [];
        foreach ($pronte as $v) {
            if ($v['chiave'] !== null) {
                $k = $v['piano_origine_id'] . '|' . $v['chiave'];
                $perChiave[$k] = ($perChiave[$k] ?? 0) + $v['importo'];
                if ($perChiave[$k] > $v['resta']) {
                    throw ValidationException::withMessages(['addebiti' => 'Della quota non assegnata «' . $v['causale'] . '» si possono addebitare al più ' . MoneyHelper::format($v['resta']) . '.']);
                }
            }
        }

        // Il registro delle modifiche: gli addebiti registrati insieme sono un gesto solo (le rate, le quote e le scritture nel lotto).
        $gesto = \App\Services\RegistroModifiche\Gesto::su('piani_rate', 'addebiti', null, 'Addebiti individuali')->nelCondominio($condominio)
            ->riepilogo(fn ($lotto, $rate) => 'Addebiti individuali registrati: ' . count($pronte) . (count($pronte) === 1 ? ' addebito' : ' addebiti') . ', '
                . \App\Helpers\MoneyHelper::format((int) collect($pronte)->sum('importo')));
        $rate = \App\Services\RegistroModifiche\RegistroModifiche::durante($gesto, function () use ($condominio, $gestione, $esercizio, $pronte, $conti, $data, $utenteId) {
            $piano = $this->pianoPer($condominio, $gestione, $esercizio, $utenteId);
            $this->ricontrollaIlTetto($pronte);
            $categoria = CategoriaEvento::firstOrCreate(['name' => CategoriaEventoEnum::SCADENZE_RATE_CONDOMINIALI->value], ['description' => 'Auto'])->id;
            $rate = [];
            // Gli accessori senza fattura hanno la loro contropartita: il conto nasce alla prima richiesta.
            if (collect($pronte)->contains(fn (array $v) => self::senzaFattura($v))) {
                $conti->put(AccessoriDaIncassare::RUOLO, AccessoriDaIncassare::conto((int) $condominio->id));
            }
            foreach ($pronte as $v) {
                $rate[] = $this->scrivi($condominio, $piano, $esercizio, $v, $conti, $data, $utenteId, $categoria);
            }
            $piano->numero_rate = $piano->rate()->count();
            $piano->save();

            return $rate;
        });

        $origini = array_values(array_unique(array_filter(array_column($pronte, 'piano_origine_id'))));
        foreach ($origini as $pianoId) {
            $this->aggiornaIlCompito((int) $pianoId);
        }
        InboxService::clearAdminCache();

        return $rate;
    }

    /**
     * La data dell'addebito, che è la data di competenza della sua emissione: quella scelta, dentro l'esercizio; di serie oggi, o la fine
     * dell'esercizio se oggi è dopo (il rendiconto dell'anno prima si prepara l'anno dopo), o il suo inizio se oggi è prima.
     */
    public static function dataDiSerie(Esercizio $esercizio, ?string $oggi = null): string
    {
        $oggi ??= DateHelper::oggiUtente();
        $inizio = (string) $esercizio->data_inizio?->format('Y-m-d');
        $fine = (string) $esercizio->data_fine?->format('Y-m-d');

        return $fine !== '' && $oggi > $fine ? $fine : ($inizio !== '' && $oggi < $inizio ? $inizio : $oggi);
    }

    private function dataDellAddebito(Esercizio $esercizio, ?string $data): string
    {
        if ($data === null || $data === '') {
            return self::dataDiSerie($esercizio);
        }
        $inizio = (string) $esercizio->data_inizio?->format('Y-m-d');
        $fine = (string) $esercizio->data_fine?->format('Y-m-d');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || ($inizio !== '' && $data < $inizio) || ($fine !== '' && $data > $fine)) {
            throw ValidationException::withMessages(['data' => sprintf('La data dell\'addebito va dentro l\'esercizio «%s», dal %s al %s: è la data della sua scrittura.',
                $esercizio->nome, self::giorno($inizio), self::giorno($fine))]);
        }

        return $data;
    }

    private static function giorno(string $data): string
    {
        return $data === '' ? '' : substr($data, 8, 2) . '/' . substr($data, 5, 2) . '/' . substr($data, 0, 4);
    }

    /**
     * Il tetto di ogni quota non assegnata, riletto con il piano degli addebiti già bloccato: fra la verifica e la scrittura un'altra richiesta può aver
     * addebitato la stessa quota.
     *
     * @param list<array<string, mixed>> $pronte
     */
    private function ricontrollaIlTetto(array $pronte): void
    {
        $perChiave = [];
        foreach ($pronte as $v) {
            if ($v['chiave'] !== null) {
                $perChiave[(int) $v['piano_origine_id']][(string) $v['chiave']] = ($perChiave[(int) $v['piano_origine_id']][(string) $v['chiave']] ?? 0) + (int) $v['importo'];
            }
        }
        foreach ($perChiave as $pianoId => $chiavi) {
            $origine = PianoRate::find($pianoId);
            $gia = $origine === null ? [] : $this->addebitatoPerChiave($origine);
            $voci = collect($origine?->quote_non_assegnate['voci'] ?? [])->keyBy('chiave');
            foreach ($chiavi as $chiave => $importo) {
                $resta = max(0, (int) ($voci[$chiave]['importo_cents'] ?? 0) - ($gia[$chiave] ?? 0));
                if ($importo > $resta) {
                    throw ValidationException::withMessages(['addebiti' => 'Della quota non assegnata si possono addebitare al più ' . MoneyHelper::format($resta) . '.']);
                }
            }
        }
    }

    /**
     * Annulla un addebito senza movimenti: toglie la scrittura dell'emissione e la riga del riparto, cancella il promemoria, la rata e la quota. Si
     * rifiuta con l'esercizio del piano chiuso (la scrittura è di quell'esercizio), con un accordo di rientro che lo riconosce (la riga dell'accordo
     * se ne andrebbe con la rata), con una successione che lo ha passato agli eredi (prima si annulla il passaggio), con un incasso o un credito usato
     * (prima si storna).
     */
    public function annulla(Rata $rata): void
    {
        $rata->loadMissing('pianoRate', 'rateQuote');
        abort_unless($rata->pianoRate?->eDegliAddebiti(), 404);

        $quote = $rata->rateQuote;
        if (($perche = $this->perCheResta([(int) $rata->id => $rata->pianoRate], $quote)[(int) $rata->id] ?? null) !== null) {
            throw ValidationException::withMessages(['addebito' => $perche]);
        }

        $origine = null;
        // Il registro delle modifiche: l'annullamento è un gesto solo; le scritture tolte per sempre sono nel lotto (rischio 6 del contesto).
        $gesto = \App\Services\RegistroModifiche\Gesto::su('piani_rate', 'addebiti', (int) $rata->id, 'Addebito individuale')
            ->nelCondominio((int) $rata->pianoRate->condominio_id)
            ->riepilogo('Addebito annullato: rata n. ' . $rata->numero_rata . ' del piano «' . $rata->pianoRate->nome . '»')
            ->rimando('piano_rate', (int) $rata->piano_rate_id, 'Piano rate «' . $rata->pianoRate->nome . '»');
        \App\Services\RegistroModifiche\RegistroModifiche::durante($gesto, function () use ($rata, $quote, &$origine) {
            $scritture = $quote->pluck('scrittura_contabile_id')->filter()->unique()->values();
            if ($scritture->isNotEmpty()) {
                $scritture = $scritture->merge(ScritturaContabile::whereIn('scrittura_padre_id', $scritture)->where('tipo_movimento', 'emissione_rata')->pluck('id'))->unique()->values();
                RataQuote::whereIn('id', $quote->pluck('id'))->update(['scrittura_contabile_id' => null]);
                ScritturaContabile::whereIn('id', $scritture)->whereNotNull('scrittura_padre_id')->update(['scrittura_padre_id' => null]);
                RigaScrittura::whereIn('scrittura_id', $scritture)->delete();
                ScritturaContabile::whereIn('id', $scritture)->forceDelete();
            }
            foreach ($quote as $q) {
                $dati = self::dati($q);
                if (($dati['riga_riparto_id'] ?? null) !== null) {
                    RigaRiparto::whereKey((int) $dati['riga_riparto_id'])->where('piano_rate_id', $rata->piano_rate_id)->delete();
                }
                $origine ??= isset($dati['piano_origine_id']) ? (int) $dati['piano_origine_id'] : null;
            }
            $rataId = (int) $rata->id;
            Evento::where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)
                ->where(fn ($q) => $q->where('meta->context->rata_id', $rataId)->orWhere('meta->context->rata_id', (string) $rataId))
                ->get()->each(function (Evento $e) {
                    $e->anagrafiche()->detach();
                    $e->condomini()->detach();
                    $e->delete();
                });
            RataQuote::whereIn('id', $quote->pluck('id'))->delete();
            $rata->delete();
            $piano = $rata->pianoRate;
            $piano->numero_rate = $piano->rate()->count();
            $piano->save();
        });

        if ($origine !== null) {
            $this->aggiornaIlCompito($origine);
        }
        InboxService::clearAdminCache();
    }

    /**
     * Perché un addebito non si annulla, per rata (assente se si annulla): l'esercizio del suo piano chiuso, un accordo di rientro che lo riconosce
     * (in corso o chiuso: un accordo non si cancella), una successione che lo ha passato agli eredi (la data è quella del passaggio), un incasso o un
     * credito usato. Come l'annullamento dell'emissione di una rata conta il versato netto: un incasso stornato non lo ferma più, e le sue righe nel
     * giornale restano, senza la rata, e si annullano fra loro.
     *
     * @param array<int, PianoRate> $pianoDi il piano di ogni rata (rata => piano)
     * @param Collection<int, RataQuote> $quote le quote di quelle rate
     * @return array<int, string>
     */
    private function perCheResta(array $pianoDi, Collection $quote): array
    {
        $esito = [];
        $esercizi = Esercizio::whereIn('id', collect($pianoDi)->pluck('esercizio_id')->filter()->unique()->values()->all() ?: [0])->get()->keyBy('id');
        $accordi = DB::table('accordo_rientro_rate as ar')->join('accordi_rientro as a', 'a.id', '=', 'ar.accordo_rientro_id')
            ->whereIn('ar.rata_id', array_keys($pianoDi) ?: [0])->orderBy('a.data_accordo')->get(['ar.rata_id', 'a.data_accordo'])
            ->groupBy('rata_id')->map(fn ($g) => substr((string) $g->first()->data_accordo, 0, 10));
        $passaggi = \App\Models\Gestionale\Subentro::passaggiDegliAddebiti(array_keys($pianoDi),
            collect($pianoDi)->pluck('condominio_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all());
        foreach ($quote->groupBy('rata_id') as $rataId => $sue) {
            $esercizio = $esercizi[(int) (($pianoDi[(int) $rataId] ?? null)?->esercizio_id ?? 0)] ?? null;
            if ($esercizio !== null && ($esercizio->stato ?? 'aperto') !== 'aperto') {
                $esito[(int) $rataId] = sprintf(self::FRASE_ESERCIZIO_CHIUSO, $esercizio->nome);
            } elseif (isset($accordi[(int) $rataId])) {
                $esito[(int) $rataId] = sprintf(self::FRASE_IN_ACCORDO, self::giorno($accordi[(int) $rataId]));
            } elseif (isset($passaggi[(int) $rataId])) {
                $esito[(int) $rataId] = sprintf(self::FRASE_PASSATO_AGLI_EREDI, $passaggi[(int) $rataId]->decorrenza?->format('d/m/Y') ?? '?');
            } elseif ($sue->contains(fn (RataQuote $q) => (int) $q->importo_pagato !== 0)) {
                $esito[(int) $rataId] = self::FRASE_NON_ANNULLABILE;
            }
        }

        return $esito;
    }

    /**
     * Quanto è già addebitato di ogni quota non assegnata di un piano (chiave => centesimi), contando gli addebiti di tutti i piani degli addebiti
     * del condominio: una quota del 2026 addebitata nel 2027 sta nel piano del 2027, ma è sempre di quella chiave.
     *
     * @return array<string, int>
     */
    public function addebitatoPerChiave(PianoRate $origine): array
    {
        $esito = [];
        foreach ($this->quoteDegliAddebiti((int) $origine->condominio_id) as $q) {
            $d = self::dati($q);
            if ((int) ($d['piano_origine_id'] ?? 0) === (int) $origine->id && isset($d['chiave'])) {
                $esito[(string) $d['chiave']] = ($esito[(string) $d['chiave']] ?? 0) + (int) $q->importo;
            }
        }

        return $esito;
    }

    /**
     * Gli addebiti che coprono le spese non attribuite di una gestione in un esercizio: quelli legati a una quota non assegnata di un suo piano di
     * quell'esercizio e quelli registrati dalla scheda «Conguaglio» per quella gestione e quell'esercizio, dovunque stiano (un addebito registrato
     * l'anno dopo, con l'esercizio chiuso, sta nel piano dell'anno dopo ma copre i giorni di quest'anno).
     *
     * @return list<array{anagrafica_id: int, immobile_id: ?int, conto_id: ?int, importo: int}>
     */
    public function copertiNellEsercizio(int $condominioId, int $gestioneId, int $esercizioId): array
    {
        $quote = $this->quoteDegliAddebiti($condominioId);
        if ($quote->isEmpty()) {
            return [];
        }
        $origini = [];
        $pianiIds = $quote->map(fn (RataQuote $q) => self::dati($q)['piano_origine_id'] ?? null)->filter()->unique()->values()->all();
        foreach (PianoRate::whereIn('id', $pianiIds)->get(['id', 'gestione_id', 'esercizio_id']) as $p) {
            $origini[(int) $p->id] = [(int) $p->gestione_id, (int) $p->esercizio_id];
        }
        $esito = [];
        foreach ($quote as $q) {
            $d = self::dati($q);
            if (($d['natura'] ?? self::NATURA_CONTRIBUTO) !== self::NATURA_CONTRIBUTO || ($d['riga_fattura_id'] ?? null) !== null) {
                continue;
            }
            [$g, $e] = isset($d['piano_origine_id']) ? ($origini[(int) $d['piano_origine_id']] ?? [0, 0]) : [(int) ($d['gestione_origine_id'] ?? 0), (int) ($d['esercizio_origine_id'] ?? 0)];
            if ($g === $gestioneId && $e === $esercizioId) {
                $esito[] = ['anagrafica_id' => (int) $q->anagrafica_id, 'immobile_id' => $q->immobile_id === null ? null : (int) $q->immobile_id,
                    'conto_id' => isset($d['conto_id']) ? (int) $d['conto_id'] : null, 'importo' => (int) $q->importo];
            }
        }

        return $esito;
    }

    /**
     * Gli addebiti di una persona nel condominio, dal più recente: la causale, l'unità, l'importo, il versato, la scadenza, la natura, il piano e se si
     * può annullare, o perché resta.
     *
     * @return list<array<string, mixed>>
     */
    public function dellaPersona(int $condominioId, int $anagraficaId): array
    {
        $sue = $this->quoteDegliAddebiti($condominioId)->where('anagrafica_id', $anagraficaId)->values();
        $perche = $this->perCheResta($sue->mapWithKeys(fn (RataQuote $q) => [(int) $q->rata_id => $q->rata?->pianoRate])->all(), $sue);

        return $sue->sortByDesc(fn (RataQuote $q) => [$q->rata?->data_scadenza?->format('Y-m-d'), $q->id])
            ->map(function (RataQuote $q) use ($perche) {
                $d = self::dati($q);

                return [
                    'rata_id' => (int) $q->rata_id,
                    'causale' => (string) ($q->rata?->descrizione ?? ''),
                    'unita' => $q->immobile?->etichetta,
                    'importo' => (int) $q->importo,
                    'versato' => (int) $q->importo_pagato,
                    'scadenza' => $q->rata?->data_scadenza?->format('Y-m-d'),
                    'natura' => (string) ($d['natura'] ?? self::NATURA_CONTRIBUTO),
                    'tipo' => $d['tipo'] ?? null,
                    'base' => BaseDegliAccessori::testo($d['base'] ?? null, $d['riferimento'] ?? null),
                    'avvisi' => BaseDegliAccessori::avvisi($d['tipo'] ?? null, $d['base'] ?? null, $d['interessi']['fonte_delibera'] ?? false ? 'delibera' : null),
                    'spesa_fatturata' => ($d['riga_fattura_id'] ?? null) !== null,
                    'piano' => (string) ($q->rata?->pianoRate?->nome ?? ''),
                    'annullabile' => ! isset($perche[(int) $q->rata_id]),
                    'perche_resta' => $perche[(int) $q->rata_id] ?? null,
                    // Decisione 493 (10): gli interessi registrati con una cifra più bassa della proposta: le due cifre, il giorno e il perché.
                    'cifre_cambiate' => self::cifreCambiate($d),
                ];
            })->values()->all();
    }

    /**
     * «Proposti € 0,37, registrati € 0,30 il 05/01/2027: «…».»: gli interessi registrati con una cifra cambiata prima di registrare (decisione 493 (10));
     * null altrimenti. Con il tasso cambiato (decisione 512) anche i tassi: «Proposti € 0,37 al 2,00 %, registrati € 0,18 all'1,00 % il 05/01/2027: «…».».
     */
    public static function cifreCambiate(array $dati): ?string
    {
        $cambiati = $dati['interessi']['cambiati'] ?? null;
        if (! is_array($cambiati)) {
            return null;
        }
        $righe = (array) ($dati['interessi']['righe'] ?? []);
        $proposti = (int) array_sum(array_map(fn ($r) => (int) ($r['proposti'] ?? $r['interessi'] ?? 0), $righe));
        $registrati = (int) array_sum(array_map(fn ($r) => (int) ($r['interessi'] ?? 0), $righe));
        $il = (string) ($cambiati['il'] ?? '');
        $alProposto = '';
        $alRegistrato = '';
        if (collect($righe)->contains(fn ($r) => isset($r['tasso']['etichetta']))) {
            // «al 2,00 %», «all'1,00 %»: l'articolo davanti al primo tasso, poi «e» fra i tassi.
            $tassi = function (array $lista): string {
                $lista = array_values(array_unique($lista));
                $intero = (int) preg_replace('/\D.*$/u', '', (string) ($lista[0] ?? ''));
                $elide = in_array($intero, [1, 8, 11], true) || ($intero >= 80 && $intero <= 89);

                return ($elide ? ' all\'' : ' al ') . implode(' e ', $lista);
            };
            $alProposto = $tassi(collect($righe)->flatMap(fn ($r) => \App\Services\Gestionale\Accessori\PropostaInteressi::tassiDeiTratti((array) ($r['tratti'] ?? [])))->all());
            $alRegistrato = $tassi(collect($righe)->flatMap(fn ($r) => isset($r['tasso']['etichetta']) ? [(string) $r['tasso']['etichetta']]
                : \App\Services\Gestionale\Accessori\PropostaInteressi::tassiDeiTratti((array) ($r['tratti'] ?? [])))->all());
        }

        return sprintf('Proposti %s%s, registrati %s%s%s: «%s».', MoneyHelper::format($proposti), $alProposto, MoneyHelper::format($registrati), $alRegistrato,
            $il !== '' ? ' il ' . self::giorno($il) : '', (string) ($cambiati['motivo'] ?? ''));
    }

    /** I dati dell'addebito nelle regole della quota. */
    public static function dati(RataQuote $quota): array
    {
        $regole = is_string($quota->regole_calcolo) ? json_decode($quota->regole_calcolo, true) : $quota->regole_calcolo;

        return (array) ($regole['addebito'] ?? []);
    }

    /**
     * Le quote non assegnate di un piano che si possono addebitare (quelle con l'unità), con quanto è già addebitato e quanto resta.
     *
     * @return list<array<string, mixed>>
     */
    public function quoteDaAddebitare(PianoRate $origine): array
    {
        $gia = $this->addebitatoPerChiave($origine);

        return array_values(array_map(fn (array $v) => $v + [
            'addebitabile' => self::addebitabile($v),
            'addebitato_cents' => $gia[(string) ($v['chiave'] ?? '')] ?? 0,
            'resta_cents' => max(0, (int) ($v['importo_cents'] ?? 0) - ($gia[(string) ($v['chiave'] ?? '')] ?? 0)),
        ], $origine->quote_non_assegnate['voci'] ?? []));
    }

    /** Una quota non assegnata si addebita se è di un'unità, ha una cifra ed è una spesa (non il credito di una voce di entrata). */
    public static function addebitabile(array $voce): bool
    {
        return ($voce['immobile_id'] ?? null) !== null && ! in_array($voce['motivo'] ?? null, self::MOTIVI_SENZA_UNITA, true)
            && (int) ($voce['importo_cents'] ?? 0) > 0 && (int) ($voce['segno'] ?? 1) > 0;
    }

    /** Le quote degli addebiti del condominio, con la rata, il piano e l'unità. */
    private function quoteDegliAddebiti(int $condominioId): Collection
    {
        return RataQuote::with(['rata.pianoRate', 'immobile'])
            ->whereIn('rata_id', Rata::whereIn('piano_rate_id', PianoRate::degliAddebiti()->where('condominio_id', $condominioId)->select('id'))->select('id'))
            ->orderBy('id')->get();
    }

    /**
     * Il piano degli addebiti della gestione nell'esercizio; nasce al primo addebito, approvato senza delibera. Il nome dice l'anno una volta sola:
     * «Addebiti individuali – Ordinaria 2026» quando il nome della gestione lo porta già, «Addebiti individuali 2026 – Ordinaria» quando no. Non è
     * il preventivo della gestione: nasce «libero», e la pagina di un piano nuovo non lo conta come il primo dell'anno.
     */
    private function pianoPer(Condominio $condominio, Gestione $gestione, Esercizio $esercizio, ?int $utenteId): PianoRate
    {
        $piano = PianoRate::degliAddebiti()->where('gestione_id', $gestione->id)->where('esercizio_id', $esercizio->id)->lockForUpdate()->first();
        if ($piano !== null) {
            return $piano;
        }
        $inizio = substr((string) $esercizio->data_inizio, 0, 4);
        $fine = substr((string) $esercizio->data_fine, 0, 4);
        $anno = $fine !== '' && $fine !== $inizio ? $inizio . '/' . $fine : $inizio;
        $nome = 'Addebiti individuali ' . ($anno !== '' && str_contains((string) $gestione->nome, $anno) ? '' : $anno . ' ') . '– ' . $gestione->nome;
        // Il nome è unico nella gestione: una gestione su più esercizi ha un piano degli addebiti per esercizio.
        if (PianoRate::where('gestione_id', $gestione->id)->where('nome', $nome)->exists()) {
            $nome .= ' (' . $esercizio->nome . ')';
        }

        $piano = new PianoRate([
            'gestione_id' => $gestione->id,
            'esercizio_id' => $esercizio->id,
            'condominio_id' => $condominio->id,
            'nome' => $nome,
            'descrizione' => 'Le somme chieste a singole persone, fuori dal riparto: una rata per addebito.',
            'metodo_distribuzione' => 'prima_rata',
            'applica_saldi' => false,
            'numero_rate' => 0,
            'giorno_scadenza' => 1,
            'stato' => StatoPianoRate::APPROVATO,
            'approvato_da_user_id' => $utenteId,
            'approvato_il' => now(),
            'tipo' => NaturaGestione::daStringa($gestione->tipo) === NaturaGestione::Straordinaria ? 'straordinario' : 'ordinario',
        ]);
        $piano->forceFill(['natura' => PianoRate::NATURA_ADDEBITI, 'contesto_creazione' => 'libero_manuale']);
        $piano->save();

        return $piano;
    }

    /**
     * Controlla una voce prima di scrivere: la persona e l'unità del condominio, la persona titolare di un diritto reale sull'unità, l'importo, la
     * quota non assegnata da cui viene (con l'unità, e non oltre quanto ne resta), la riga di fattura (dell'unità, e non già chiesta).
     *
     * @return array<string, mixed>
     */
    private function verifica(Condominio $condominio, array $v, Gestione $gestione): array
    {
        $anagraficaId = (int) ($v['anagrafica_id'] ?? 0);
        $immobileId = (int) ($v['immobile_id'] ?? 0);
        $importo = (int) ($v['importo'] ?? 0);
        $causale = trim((string) ($v['causale'] ?? ''));
        $scadenza = (string) ($v['scadenza'] ?? '');
        abort_unless(Immobile::whereKey($immobileId)->where('condominio_id', $condominio->id)->exists(), 404);
        abort_unless(Anagrafica::whereKey($anagraficaId)->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominio->id))->exists(), 404);
        if (! $this->persone->puoRicevere($anagraficaId, $immobileId)) {
            throw ValidationException::withMessages(['addebiti' => self::FRASE_INQUILINO]);
        }
        if ($importo < 1) {
            throw ValidationException::withMessages(['addebiti' => 'Scrivi l\'importo dell\'addebito, più di zero.']);
        }
        if ($causale === '') {
            throw ValidationException::withMessages(['addebiti' => 'Scrivi la causale: è la riga che la persona legge nell\'estratto conto e nella ricevuta.']);
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $scadenza)) {
            throw ValidationException::withMessages(['addebiti' => 'Scrivi la scadenza dell\'addebito.']);
        }

        $natura = (string) ($v['natura'] ?? self::NATURA_CONTRIBUTO);
        if (! in_array($natura, [self::NATURA_CONTRIBUTO, self::NATURA_ACCESSORIO], true)) {
            throw ValidationException::withMessages(['addebiti' => 'La natura di un addebito è contributo o spesa.']);
        }
        $chiave = isset($v['chiave']) && $v['chiave'] !== '' ? (string) $v['chiave'] : null;
        $pianoOrigine = null;
        $resta = null;
        // La voce viene dalla quota non assegnata, non dalla richiesta; un addebito libero non ne ha una.
        $contoId = null;
        $rigaFattura = isset($v['riga_fattura_id']) ? (int) $v['riga_fattura_id'] : null;
        if ($chiave !== null) {
            $pianoOrigine = PianoRate::whereKey((int) ($v['piano_origine_id'] ?? 0))->where('condominio_id', $condominio->id)->delRiparto()->first();
            abort_if($pianoOrigine === null, 404);
            $voce = collect($pianoOrigine->quote_non_assegnate['voci'] ?? [])->first(fn ($x) => ($x['chiave'] ?? null) === $chiave);
            abort_if($voce === null, 404);
            if (! self::addebitabile($voce)) {
                throw ValidationException::withMessages(['addebiti' => self::FRASE_SENZA_UNITA]);
            }
            if ((int) $voce['immobile_id'] !== $immobileId) {
                throw ValidationException::withMessages(['addebiti' => 'La quota non assegnata è di un\'altra unità.']);
            }
            $resta = max(0, (int) $voce['importo_cents'] - ($this->addebitatoPerChiave($pianoOrigine)[$chiave] ?? 0));
            $contoId = isset($voce['conto_id']) ? (int) $voce['conto_id'] : null;
            if (($voce['motivo'] ?? null) === 'ad_personam_senza_titolare') {
                $rigaFattura ??= $this->rigaDellaQuota($pianoOrigine, $voce);
                if ($rigaFattura === null) {
                    throw ValidationException::withMessages(['addebiti' => 'La riga di fattura di questa spesa non si ritrova fra le fatture del piano: addebitala dall\'estratto conto della persona.']);
                }
            }
            if ($importo > $resta) {
                throw ValidationException::withMessages(['addebiti' => 'Della quota non assegnata si possono addebitare al più ' . MoneyHelper::format($resta) . '.']);
            }
        }
        $accessorio = $natura === self::NATURA_ACCESSORIO ? $this->verificaAccessorio($v, $rigaFattura === null, $gestione) : null;
        if ($accessorio !== null && $rigaFattura === null) {
            $contoId = $accessorio['conto_id'];
        }
        // Le spese non attribuite di una gestione in un esercizio da cui viene l'addebito (la scheda «Conguaglio»): tutte e due del condominio, o nessuna.
        $origine = ['gestione' => null, 'esercizio' => null];
        if ($pianoOrigine === null && isset($v['gestione_origine_id'], $v['esercizio_origine_id'])) {
            $g = (int) $v['gestione_origine_id'];
            $e = (int) $v['esercizio_origine_id'];
            abort_unless(DB::table('gestioni')->where('id', $g)->where('condominio_id', $condominio->id)->exists()
                && DB::table('esercizi')->where('id', $e)->where('condominio_id', $condominio->id)->exists(), 404);
            $origine = ['gestione' => $g, 'esercizio' => $e];
        }
        $descrizioneRiga = null;
        $fattura = null;
        if ($rigaFattura !== null) {
            $riga = DB::table('righe_fattura as rf')->join('fatture_passive as fp', 'fp.id', '=', 'rf.fattura_passiva_id')
                ->where('rf.id', $rigaFattura)->where('fp.condominio_id', $condominio->id)->first(['rf.id', 'rf.immobile_id', 'rf.descrizione', 'rf.importo_imponibile', 'rf.importo_iva']);
            abort_if($riga === null, 404);
            if ((int) $riga->immobile_id !== $immobileId) {
                throw ValidationException::withMessages(['addebiti' => 'La riga di fattura è di un\'altra unità.']);
            }
            $fattura = self::esercizioDellaRiga((int) $condominio->id, $rigaFattura);
            // Con l'esercizio della fattura chiuso, il suo conguaglio ha già chiesto la spesa a chi era titolare. Una quota non assegnata del piano è
            // invece rimasta senza titolare, e quel conguaglio non l'ha chiesta a nessuno: si addebita nell'esercizio aperto.
            if ($fattura !== null && ! $fattura['aperto'] && $chiave === null) {
                throw ValidationException::withMessages(['addebiti' => sprintf('La fattura n. %s è dell\'esercizio «%s», chiuso: la spesa è fra le spese personali del suo '
                    . 'conguaglio, che la chiede già.', $fattura['numero'], $fattura['esercizio'])]);
            }
            $lordo = (int) $riga->importo_imponibile + (int) $riga->importo_iva;
            $chiesta = (int) RigaRiparto::where('tipo', RigaRiparto::TIPO_AD_PERSONAM)->where('riga_fattura_id', $rigaFattura)->sum('importo');
            if ($importo > $lordo - $chiesta) {
                throw ValidationException::withMessages(['addebiti' => 'Di questa spesa restano da chiedere ' . MoneyHelper::format(max(0, $lordo - $chiesta)) . ': il resto è già chiesto con le rate o con un altro addebito.']);
            }
            $descrizioneRiga = $riga->descrizione;
        }

        return [
            'anagrafica_id' => $anagraficaId, 'immobile_id' => $immobileId, 'importo' => $importo, 'causale' => mb_substr($causale, 0, 255), 'scadenza' => $scadenza,
            'natura' => $natura, 'chiave' => $chiave, 'piano_origine_id' => $pianoOrigine?->id, 'resta' => $resta, 'conto_id' => $contoId,
            'riga_fattura_id' => $rigaFattura, 'riga_descrizione' => $descrizioneRiga, 'fattura' => $fattura,
            'gestione_origine_id' => $origine['gestione'],
            'esercizio_origine_id' => $origine['esercizio'],
            'accessorio' => $accessorio === null ? null : array_diff_key($accessorio, ['conto_id' => true]),
        ];
    }

    /**
     * Un accessorio: il tipo, la base dichiarata con il riferimento e, senza fattura, la voce che riceve il denaro quando la persona lo versa (una voce
     * della gestione dell'addebito, con il suo mastro; per gli interessi una voce di entrata). Il tipo e la base senza fattura si scelgono: nessuno è già
     * scelto. Con la fattura restano facoltativi. Gli avvisi della base li dice chi mostra l'addebito: qui non bloccano.
     *
     * @return array{tipo: ?string, base: ?string, riferimento: ?string, conto_id: ?int, interessi: ?array}
     */
    private function verificaAccessorio(array $v, bool $senzaFattura, Gestione $gestione): array
    {
        $tipo = isset($v['tipo_accessorio']) && $v['tipo_accessorio'] !== '' ? (string) $v['tipo_accessorio'] : null;
        $base = isset($v['base']) && $v['base'] !== '' ? (string) $v['base'] : null;
        if ($tipo !== null && ! isset(BaseDegliAccessori::TIPI[$tipo])) {
            throw ValidationException::withMessages(['addebiti' => 'Il tipo della spesa è uno di: ' . implode(', ', array_map('mb_strtolower', BaseDegliAccessori::TIPI)) . '.']);
        }
        if ($base !== null && ! isset(BaseDegliAccessori::BASI[$base])) {
            throw ValidationException::withMessages(['addebiti' => 'La base è una di: ' . implode(', ', BaseDegliAccessori::BASI) . '.']);
        }
        $riferimento = trim((string) ($v['riferimento'] ?? ''));
        $esito = ['tipo' => $tipo, 'base' => $base, 'riferimento' => $riferimento === '' ? null : mb_substr($riferimento, 0, 255), 'conto_id' => null,
            'interessi' => isset($v['interessi']) && is_array($v['interessi']) ? $v['interessi'] : null];
        if (! $senzaFattura) {
            return $esito;
        }
        if ($tipo === null) {
            throw ValidationException::withMessages(['addebiti' => 'Scegli che cos\'è la spesa: interessi di mora, spese di sollecito, spese legali o altro.']);
        }
        if ($base === null) {
            throw ValidationException::withMessages(['addebiti' => 'Scegli la base della spesa: la legge, il regolamento, una delibera, un provvedimento del giudice o nessuna.']);
        }
        $voce = isset($v['conto_id']) ? \App\Models\Gestionale\Conto::with('pianoConto')->find((int) $v['conto_id']) : null;
        $entrata = $tipo === 'interessi';
        $frase = 'Scegli la voce che riceve il denaro quando la persona lo versa: ' . ($entrata ? 'una voce di entrata' : 'una voce') . ' della gestione «'
            . $gestione->nome . '». Se non c\'è, creala nel piano dei conti con il preventivo a zero.';
        abort_if($voce !== null && (int) ($voce->pianoConto?->condominio_id ?? 0) !== (int) $gestione->condominio_id, 404);
        if ($voce === null || (int) $voce->pianoConto?->gestione_id !== (int) $gestione->id || $voce->conto_contabile_id === null
            || $voce->sottoconti()->exists() || ($entrata && \App\Models\Gestionale\Conto::segnoDelTipo((string) $voce->tipo) === 1)) {
            throw ValidationException::withMessages(['addebiti' => $frase]);
        }
        // Il denaro versato va alla voce e il conguaglio lo divide sulle sue tabelle: una voce senza tabella (un capitolo vuoto, una voce scritta fuori dal
        // modulo) lo lascerebbe fra le spese non attribuite.
        if (! $voce->tabelleMillesimali()->exists()) {
            throw ValidationException::withMessages(['addebiti' => 'La voce «' . trim((string) $voce->nome) . '» non ha una tabella millesimale: il denaro versato non si '
                . 'dividerebbe nel conguaglio. Collega una tabella alla voce nel piano dei conti, o scegli un\'altra voce.']);
        }
        $esito['conto_id'] = (int) $voce->id;

        return $esito;
    }

    /** Un accessorio senza fattura: la sua contropartita è «Accessori da incassare». */
    private static function senzaFattura(array $v): bool
    {
        return $v['natura'] === self::NATURA_ACCESSORIO && $v['riga_fattura_id'] === null;
    }

    /**
     * Dove sta la spesa di una riga di fattura: l'esercizio e la gestione della scrittura di competenza della fattura, se è aperto, il numero della
     * fattura. Null se la fattura non ha una scrittura di competenza.
     *
     * @return array{esercizio_id: int, esercizio: string, aperto: bool, gestione_id: ?int, gestione: ?string, numero: string}|null
     */
    public static function esercizioDellaRiga(int $condominioId, int $rigaFatturaId): ?array
    {
        $r = DB::table('righe_fattura as rf')->join('fatture_passive as fp', 'fp.id', '=', 'rf.fattura_passiva_id')
            ->join('fattura_scrittura as fs', fn ($j) => $j->on('fs.fattura_passiva_id', '=', 'fp.id')->where('fs.tipo', 'competenza'))
            ->join('scritture_contabili as sc', fn ($j) => $j->on('sc.id', '=', 'fs.scrittura_contabile_id')->whereNull('sc.deleted_at'))
            ->join('esercizi as e', 'e.id', '=', 'sc.esercizio_id')
            ->leftJoin('gestioni as g', 'g.id', '=', 'sc.gestione_id')
            ->where('rf.id', $rigaFatturaId)->where('fp.condominio_id', $condominioId)->orderByDesc('sc.id')
            ->first(['e.id as esercizio_id', 'e.nome as esercizio', 'e.stato', 'g.id as gestione_id', 'g.nome as gestione', 'fp.numero_documento']);

        return $r === null ? null : ['esercizio_id' => (int) $r->esercizio_id, 'esercizio' => (string) $r->esercizio, 'aperto' => ($r->stato ?? 'aperto') === 'aperto',
            'gestione_id' => $r->gestione_id === null ? null : (int) $r->gestione_id, 'gestione' => $r->gestione === null ? null : (string) $r->gestione,
            'numero' => (string) ($r->numero_documento ?? 's/n')];
    }

    /** Scrive un addebito: la rata, la quota, la riga del riparto della spesa fatturata, l'emissione con la data dell'addebito, il promemoria del portale. */
    private function scrivi(Condominio $condominio, PianoRate $piano, Esercizio $esercizio, array $v, Collection $conti, string $data, ?int $utenteId, int $categoria): Rata
    {
        $numero = (int) ($piano->rate()->max('numero_rata') ?? 0) + 1;
        $rata = Rata::create([
            'piano_rate_id' => $piano->id, 'conto_id' => null, 'numero_rata' => $numero, 'data_scadenza' => $v['scadenza'], 'data_emissione' => $data,
            'descrizione' => $v['causale'], 'importo_totale' => $v['importo'], 'stato' => 'bozza',
        ]);

        $rigaRiparto = null;
        if ($v['riga_fattura_id'] !== null) {
            $rigaRiparto = RigaRiparto::create([
                'piano_rate_id' => $piano->id, 'tipo' => RigaRiparto::TIPO_AD_PERSONAM, 'anagrafica_id' => $v['anagrafica_id'], 'immobile_id' => $v['immobile_id'],
                'riga_fattura_id' => $v['riga_fattura_id'], 'riga_descrizione' => $v['riga_descrizione'], 'importo' => $v['importo'],
                'versione_calcolo' => mb_substr((string) config('app.version', ''), 0, 20),
            ]);
        }

        $quota = new RataQuote([
            'rata_id' => $rata->id, 'anagrafica_id' => $v['anagrafica_id'], 'immobile_id' => $v['immobile_id'], 'importo' => $v['importo'], 'importo_pagato' => 0,
            'stato' => 'da_pagare', 'data_scadenza' => $v['scadenza'],
            'regole_calcolo' => [
                'addebito' => array_filter([
                    'natura' => $v['natura'], 'causale' => $v['causale'], 'chiave' => $v['chiave'], 'piano_origine_id' => $v['piano_origine_id'],
                    'gestione_origine_id' => $v['gestione_origine_id'], 'esercizio_origine_id' => $v['esercizio_origine_id'], 'conto_id' => $v['conto_id'],
                    'riga_fattura_id' => $v['riga_fattura_id'], 'riga_riparto_id' => $rigaRiparto?->id,
                    'tipo' => $v['accessorio']['tipo'] ?? null, 'base' => $v['accessorio']['base'] ?? null, 'riferimento' => $v['accessorio']['riferimento'] ?? null,
                    'interessi' => $v['accessorio']['interessi'] ?? null,
                    'registrato_il' => now()->toIso8601String(), 'registrato_da' => $utenteId,
                ], fn ($x) => $x !== null),
                // La spesa della quota e la sua parte di una sola unità: con la riga di fattura l'emissione chiude l'anticipazione dell'unità invece di
                // contarla fra le quote; senza, la parte è zero, e l'emissione non cerca un registro del riparto per questa persona e unità.
                'importi' => ['quota_pura_gestione' => $v['importo'], 'saldo_usato' => 0, 'totale_calcolato' => $v['importo'],
                    'addebito_diretto' => $v['riga_fattura_id'] !== null ? $v['importo'] : 0],
            ],
        ]);
        $quota->forceFill(['tipo' => $v['natura'] === self::NATURA_ACCESSORIO ? RataQuote::TIPO_ACCESSORIO : RataQuote::TIPO_ADDEBITO])->save();
        $rata->setRelation('rateQuote', collect([$quota]));

        if ($v['riga_fattura_id'] !== null
            && ($motivo = RataQuote::motivoBloccoEmissioneAdPersonam($piano, [$rata], $conti->get('passate_gestioni')?->id, '')) !== null) {
            throw ValidationException::withMessages(['addebiti' => trim($motivo)]);
        }

        // La contropartita: «Gestione rate» per un contributo, come le rate; «Accessori da incassare» per un accessorio senza fattura, che diventa del
        // condominio solo quando è versato.
        $contropartita = self::senzaFattura($v) ? $conti->get(AccessoriDaIncassare::RUOLO) : $conti->get('gestione_rate');
        $scrittura = $this->scrittura->scrivi((int) $condominio->id, (int) $esercizio->id, (int) $piano->gestione_id, $rata, collect([$quota]), $data,
            'Addebito: ' . $v['causale'], $conti->get('crediti_condomini'), $contropartita, $conti->get('passate_gestioni'));
        abort_if($scrittura === null, 500);
        $rata->update(['stato' => 'emessa']);

        // Il promemoria nel portale nasce con l'addebito, già emesso: il piano non passa da un'approvazione che lo crei.
        $persona = Anagrafica::find($v['anagrafica_id']);
        $quota->setRelation('immobile', Immobile::find($v['immobile_id']));
        $this->eventi->creaDellAddebito($piano, $rata, $persona, collect([$quota]), $condominio, $utenteId ?? 1, $categoria);

        return $rata->fresh('rateQuote');
    }

    /**
     * La riga di fattura di una spesa di una sola unità rimasta senza titolare: fra le righe delle fatture del piano sull'unità, quella con la
     * descrizione della quota (la chiave ne porta l'impronta, e un numero quando due righe sono uguali).
     */
    private function rigaDellaQuota(PianoRate $origine, array $voce): ?int
    {
        $pezzi = explode('|', (string) ($voce['chiave'] ?? ''));
        [$impronta, $ordine] = array_pad(explode('#', (string) ($pezzi[5] ?? ''), 2), 2, '1');
        $righe = DB::table('piano_rate_fatture as prf')->join('righe_fattura as rf', 'rf.fattura_passiva_id', '=', 'prf.fattura_passiva_id')
            ->where('prf.piano_rate_id', $origine->id)->where('rf.immobile_id', (int) $voce['immobile_id'])->orderBy('rf.id')->get(['rf.id', 'rf.descrizione'])
            ->filter(fn ($r) => $impronta === '' || substr(md5((string) $r->descrizione), 0, 10) === $impronta)->values();

        return isset($righe[(int) $ordine - 1]) ? (int) $righe[(int) $ordine - 1]->id : null;
    }

    /**
     * Il compito «Quote non assegnate» del piano: chiuso quando gli addebiti sulle sue quote ne coprono l'importo, con la nota; riaperto se un
     * annullamento lo scopre di nuovo. Finché resta aperto dice quanto è coperto e quanto manca.
     */
    private function aggiornaIlCompito(int $pianoId): void
    {
        $piano = PianoRate::find($pianoId);
        if ($piano === null) {
            return;
        }
        $coperto = (int) array_sum($this->addebitatoPerChiave($piano));
        foreach (Evento::where('tipo', EventoTipo::SCOPERTO_DOCUMENTATO->value)
            ->where(fn ($q) => $q->where('meta->context->piano_rate_id', $pianoId)->orWhere('meta->context->piano_rate_id', (string) $pianoId))->get() as $compito) {
            $meta = $compito->meta;
            $importo = (int) ($meta['context']['importo_cents'] ?? 0);
            $meta['descrizione_di_partenza'] ??= (string) $compito->description;
            $meta['coperto_da_addebiti_cents'] = $coperto;
            if ($importo > 0 && $coperto >= $importo) {
                $meta['requires_action'] = false;
                $meta['chiuso_da_addebiti'] = true;
                $compito->update(['meta' => $meta, 'is_completed' => true, 'completed_at' => now(),
                    'description' => $meta['descrizione_di_partenza'] . ' Coperto da addebiti: ' . MoneyHelper::format($coperto) . '.']);
            } else {
                $riapri = (bool) ($meta['chiuso_da_addebiti'] ?? false);
                $meta['requires_action'] = true;
                $meta['chiuso_da_addebiti'] = false;
                $compito->update(['meta' => $meta] + ($riapri ? ['is_completed' => false, 'completed_at' => null] : [])
                    + ['description' => $meta['descrizione_di_partenza'] . ($coperto > 0 ? ' Coperti da addebiti ' . MoneyHelper::format($coperto) . ': mancano '
                        . MoneyHelper::format(max(0, $importo - $coperto)) . '.' : '')]);
            }
        }
    }
}
