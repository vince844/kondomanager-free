<?php

namespace App\Services\Gestionale;

use App\Enums\TipoMovimentoContabile;
use App\Helpers\DateHelper;
use App\Exceptions\Pagamenti\VoceCommissioneNonAmmessaException;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestione;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Le commissioni dei pagamenti al fornitore scritte senza voce, gli storni senza voce di registrazioni di un esercizio chiuso, e l'assegnazione a mano
 * della voce negli esercizi aperti.
 *
 * Fino a questa versione la commissione andava sulle «Spese bancarie» di sistema (ruolo `spese_bancarie`), senza voce: fuori dal consuntivo
 * e dal riparto. Le commissioni nuove hanno la voce (`VoceDellaCommissione`); quelle già scritte **non prendono un valore da una regola nuova**
 * (nessun riempimento): restano come sono, e qui l'amministratore le vede e, se vuole, sceglie la voce.
 *
 * - **Per esercizio**: le righe delle scritture dell'esercizio. «Aperto» è lo stato scritto dell'esercizio, non la data: un esercizio finito
 *   per data e tenuto aperto fino all'assemblea si corregge, uno chiuso no (il suo rendiconto è già stato presentato).
 * - **La commissione e il suo storno stanno insieme** e prendono la voce insieme, così il consuntivo della voce resta giusto. Uno storno il cui
 *   originale sta in un altro esercizio (lo storno di un pagamento di un esercizio chiuso va nell'esercizio aperto) non si offre: metterebbe
 *   −€ 2,50 su una voce dell'anno nuovo contro un originale che resta senza voce. Tranne quando la commissione di allora aveva una voce, di una
 *   gestione che questo esercizio non ha (lo storno è senza voce per questo, `PagamentoFornitoreService::stornaPagamento()`): l'originale è già
 *   sulla sua voce dell'anno prima, e lo storno si assegna a una voce di un'ordinaria di questo esercizio (decisione 253, D7), che così rende il
 *   rimborso ai suoi condòmini; le altre voci della gestione del pagamento no (`solo_ordinarie`).
 * - **Gli storni di una regolazione immediata o di un incasso da terzi** nell'anno dopo, senza voce sul conto della registrazione
 *   ({@see VoceNelloStorno::righeSenzaVoce()}, la stessa regola del riepilogo), stanno nello stesso elenco (`natura` «registrazione») e si assegnano
 *   come lo storno di una commissione (decisione 255, D1): se la registrazione di allora aveva una voce, a una voce di un'ordinaria di questo esercizio
 *   (`solo_ordinarie`), che così li porta nel consuntivo della voce e nel riparto; se era già senza voce, no, come lo storno di una commissione senza
 *   voce. Il loro numero da assegnare è `storni_assegnabili`, a parte da quello delle commissioni. Lo storno di una regolazione va su una voce di spesa;
 *   quello di un incasso da terzi su una voce di **entrata** (`voce_di_entrata`) che non sta sotto un capitolo di spesa: il riepilogo legge le righe
 *   degli incassi da terzi e dei loro storni come l'incassato della loro voce ({@see SpesaPerVoceService::perTipo()}), e su una voce di spesa, o su
 *   una voce di entrata dentro un capitolo di spesa, la riga non starebbe in nessuna riga del riepilogo.
 * - **L'assegnazione** mette sulla riga la voce e il mastro della voce, come scrive oggi una commissione nuova: il consuntivo della voce cambia
 *   dell'importo. Una gestione alla volta (la voce è di una gestione). Se nel frattempo una riga ha già una voce, la richiesta è rifiutata per
 *   intero e nessuna riga cambia.
 */
class CommissioniSenzaVoce
{
    /** Le frasi degli errori dell'assegnazione dicono «righe» quando valgono per tutte e due: la scelta può avere commissioni e storni. */
    public const ERRORE_GIA_CAMBIATE = 'Alcune righe scelte non sono più senza voce, o non sono di questo esercizio: ricarica l\'elenco e riprova.';

    public const NESSUNA_RIGA_SCELTA = 'Nessuna riga da assegnare: scegline almeno una.';

    /** La voce che il server rifiuta per lo storno di una regolazione: la regola, per tutti i motivi. */
    public const VOCE_DELLO_STORNO = 'La voce scelta non si dà a questo storno: lo storno di una regolazione immediata di un esercizio chiuso si assegna a una '
        .'voce di spesa di un\'ordinaria di questo esercizio, attiva, senza sottovoci e con il suo conto.';

    /** La voce che il server rifiuta per lo storno di un incasso da terzi: la regola, per tutti i motivi. */
    public const VOCE_DELLO_STORNO_DI_UN_INCASSO = 'La voce scelta non si dà a questo storno: lo storno di un incasso da terzi di un esercizio chiuso si assegna a '
        .'una voce di entrata di un\'ordinaria di questo esercizio, attiva, senza sottovoci, con il suo conto e non sotto un capitolo di spesa: l\'entrata torna indietro.';

    public const STORNI_DI_DUE_TIPI = 'Lo storno di un incasso da terzi va su una voce di entrata, le commissioni e gli altri storni su una voce di spesa: si '
        .'assegnano in due volte.';

    /** `$conStorni`: l'elenco ha anche gli storni senza voce di registrazioni di un esercizio chiuso. */
    public static function fraseChiuso(string $esercizio, bool $conStorni = false): string
    {
        return "L'esercizio «{$esercizio}» è chiuso: le commissioni senza voce restano come sono, e il suo rendiconto le mostra in una riga loro. "
            .($conStorni ? 'Gli storni senza voce restano fra i costi non classificati. ' : '')
            .'La voce si assegna solo negli esercizi aperti.';
    }

    /** La frase dell'esercizio chiuso per le righe dell'elenco ({@see perEsercizio()}): la stessa nella pagina e nella risposta di «Assegna la voce». */
    public static function fraseChiusoPerLElenco(string $esercizio, array $commissioni): string
    {
        return self::fraseChiuso($esercizio, collect($commissioni)->contains(fn ($c) => $c['natura'] === 'registrazione'));
    }

    public static function motivoStornoDiUnAltroEsercizio(string $esercizio): string
    {
        return "È lo storno di una commissione dell'esercizio «{$esercizio}», che è chiuso e resta senza voce: lo storno resta senza voce anche lui.";
    }

    /**
     * Lo storno nell'anno dopo di una commissione che aveva una voce di una gestione che questo esercizio non ha (`PagamentoFornitoreService`, lo storno della
     * Variante B1): la voce di allora, per sapere a che cosa si riferisce, e dove si assegna.
     */
    public static function motivoStornoDiUnaVoceDiUnAltroEsercizio(string $esercizio, string $voce, string $gestione, bool $aperto = true): string
    {
        return "È lo storno di una commissione dell'esercizio «{$esercizio}», che era sulla voce «{$voce}» della gestione «{$gestione}»: questo esercizio non ha "
            .'quella gestione. '.($aperto
                ? "Si assegna a una voce di un'ordinaria di questo esercizio, che ne riceve il rimborso; senza voce resta in una riga sua del rendiconto."
                : "L'esercizio è chiuso: lo storno resta senza voce, in una riga sua del rendiconto.");
    }

    /**
     * Lo storno nell'anno dopo di una regolazione immediata o di un incasso da terzi, senza voce ({@see VoceNelloStorno}): che cos'era e dove si assegna.
     * `$voce` e `$gestione` sono quelle della registrazione di allora (null se era già senza voce).
     */
    public static function motivoStornoDiUnaRegistrazione(string $che, string $esercizio, ?string $voce, ?string $gestione, bool $aperto, bool $entrata = false): string
    {
        if ($voce === null) {
            return "È lo storno di {$che} dell'esercizio «{$esercizio}», che era già senza voce: lo storno resta senza voce anche lui, fra i costi non classificati "
                .'del rendiconto.';
        }

        return "È lo storno di {$che} dell'esercizio «{$esercizio}», che era sulla voce «{$voce}» della gestione «{$gestione}»: questo esercizio non ha quella "
            .'gestione. '.($aperto
                ? 'Si assegna a una voce di '.($entrata ? 'entrata' : 'spesa')." di un'ordinaria di questo esercizio, che così entra nel riparto; senza voce resta fra i costi non classificati del rendiconto."
                : "L'esercizio è chiuso: lo storno resta senza voce, fra i costi non classificati del rendiconto.");
    }

    /**
     * Le commissioni senza voce dell'esercizio, ognuna con il suo storno se sta nello stesso esercizio.
     *
     * `assegnabili` conta le sole commissioni a cui la voce si può dare e che cambiano il consuntivo (importo diverso da zero): è il numero che invita
     * ad assegnarla; l'elenco le mostra tutte, con il perché di quelle che non si offrono. Gli storni senza voce di registrazioni di un esercizio chiuso
     * stanno nello stesso elenco, e il loro numero da assegnare è `storni_assegnabili`.
     *
     * @return array{commissioni: list<array<string, mixed>>, totale: array{numero: int, importo: int}, assegnabili: array{numero: int, importo: int}, storni_assegnabili: array{numero: int, importo: int}}
     */
    public function perEsercizio(Condominio $condominio, Esercizio $esercizio): array
    {
        $righe = $this->righeSenzaVoce($condominio)->where('sc.esercizio_id', $esercizio->id)->get();
        $aperto = $esercizio->stato === 'aperto';
        $gestioni = Gestione::query()->where('condominio_id', $condominio->id)->pluck('nome', 'id');

        $originali = $righe->where('tipo_movimento', TipoMovimentoContabile::PAGAMENTO_FORNITORE->value);
        $storni = $righe->where('tipo_movimento', TipoMovimentoContabile::STORNO_PAGAMENTO_FORNITORE->value)->keyBy('scrittura_padre_id');
        $usati = [];
        $commissioni = [];

        foreach ($originali as $o) {
            $storno = $storni->get($o->scrittura_id);
            $righeVoce = [$this->riga($o)];
            if ($storno) {
                $righeVoce[] = $this->riga($storno);
                $usati[$storno->riga_id] = true;
            }
            // Uno storno di questa commissione in un altro esercizio: si assegnano insieme solo nello stesso esercizio.
            $stornoAltrove = $storno === null && $this->stornoInAltroEsercizio((int) $o->scrittura_id, (int) $esercizio->id);
            $commissioni[] = $this->commissione($righeVoce, $o, $gestioni, $aperto && ! $stornoAltrove,
                $stornoAltrove ? 'Il suo storno sta in un altro esercizio: la commissione e lo storno prendono la voce insieme, nello stesso esercizio.' : null);
        }

        foreach ($storni as $s) {
            if (isset($usati[$s->riga_id])) {
                continue;
            }
            $esercizioOriginale = DB::table('scritture_contabili as p')->join('esercizi as e', 'e.id', '=', 'p.esercizio_id')
                ->where('p.id', $s->scrittura_padre_id)->first(['e.nome', 'e.stato']);
            $nome = (string) ($esercizioOriginale->nome ?? '—');
            // La commissione di allora aveva una voce (Coda 553: una voce dell'ordinaria di quell'anno, che questo esercizio non ha): la si nomina.
            $voceDiAllora = DB::table('righe_scritture as rs')->join('conti', 'conti.id', '=', 'rs.voce_spesa_id')
                ->join('piani_conti as pc', 'pc.id', '=', 'conti.piano_conto_id')->join('gestioni as g', 'g.id', '=', 'pc.gestione_id')
                ->where('rs.scrittura_id', $s->scrittura_padre_id)->where('rs.tipo_riga', 'dare')->orderBy('rs.id')->first(['conti.nome as voce', 'g.nome as gestione']);
            $commissioni[] = $this->commissione([$this->riga($s)], $s, $gestioni, $aperto && $voceDiAllora !== null, match (true) {
                $voceDiAllora !== null => self::motivoStornoDiUnaVoceDiUnAltroEsercizio($nome, (string) $voceDiAllora->voce, (string) $voceDiAllora->gestione, $aperto),
                ($esercizioOriginale->stato ?? 'chiuso') === 'chiuso' => self::motivoStornoDiUnAltroEsercizio($nome),
                default => "È lo storno di una commissione dell'esercizio «{$nome}»: la commissione e lo storno stanno in due esercizi, e la voce non si assegna a uno solo dei due.",
            }, soloOrdinarie: $voceDiAllora !== null);
        }

        // Gli storni nell'anno dopo di una regolazione immediata o di un incasso da terzi, senza voce sul conto della registrazione.
        foreach ($this->righeDegliStorni((int) $esercizio->id) as $s) {
            // La voce di allora: quella della riga dell'originale sullo stesso conto (lo storno ricopia il conto, e toglie la voce che questo esercizio non ha).
            $voceDiAllora = DB::table('righe_scritture as rs')->join('conti', 'conti.id', '=', 'rs.voce_spesa_id')
                ->join('piani_conti as pc', 'pc.id', '=', 'conti.piano_conto_id')->join('gestioni as g', 'g.id', '=', 'pc.gestione_id')
                ->where('rs.scrittura_id', $s->scrittura_padre_id)->where('rs.conto_contabile_id', $s->conto_contabile_id)->orderBy('rs.id')
                ->first(['conti.nome as voce', 'g.nome as gestione']);
            $incasso = $s->tipo_movimento === TipoMovimentoContabile::STORNO_INCASSO_DIVERSO->value;
            $commissioni[] = $this->commissione([$this->riga($s)], $s, $gestioni, $aperto && $voceDiAllora !== null,
                self::motivoStornoDiUnaRegistrazione($incasso ? 'un incasso da terzi' : 'una regolazione immediata', (string) ($s->esercizio_originale ?? '—'),
                    $voceDiAllora?->voce, $voceDiAllora?->gestione, $aperto, $incasso),
                soloOrdinarie: $voceDiAllora !== null, natura: 'registrazione', voceDiEntrata: $incasso);
        }

        usort($commissioni, fn ($a, $b) => [$a['righe'][0]['data'], $a['id']] <=> [$b['righe'][0]['data'], $b['id']]);

        $daAssegnare = fn (string $natura) => array_values(array_filter($commissioni, fn ($c) => $c['natura'] === $natura && $c['assegnabile'] && (int) $c['importo'] !== 0));
        $conta = fn (array $lista) => ['numero' => count($lista), 'importo' => (int) array_sum(array_column($lista, 'importo'))];

        return [
            'commissioni' => $commissioni,
            'totale' => $conta($commissioni),
            'assegnabili' => $conta($daAssegnare('commissione')),
            'storni_assegnabili' => $conta($daAssegnare('registrazione')),
        ];
    }

    /**
     * Assegna la voce alle commissioni scelte (o a tutte quelle assegnabili della gestione): voce e mastro della voce sulle righe, la commissione
     * e il suo storno insieme.
     *
     * @param  list<int>|null  $ids  le commissioni scelte (l'`id` dell'elenco); null con `$gestioneId` vuol dire «tutte»
     * @return array{numero: int, importo: int, voce: string, storni: int, entrata: bool} `storni`: quanti degli scelti sono uno storno da solo (di una
     *     registrazione, o di una commissione il cui originale sta in un altro esercizio; una commissione con il suo storno è una commissione);
     *     `entrata`: la voce è di entrata (lo storno di un incasso da terzi), e l'incassato della voce cambia di meno l'importo
     *
     * @throws \DomainException con il messaggio per la pagina
     * @throws VoceCommissioneNonAmmessaException se la voce non è di spesa o non è della gestione delle commissioni
     */
    public function assegna(Condominio $condominio, Esercizio $esercizio, ?array $ids, ?int $gestioneId, int $voceId): array
    {
        if ($esercizio->stato !== 'aperto') {
            throw new \DomainException(self::fraseChiusoPerLElenco((string) $esercizio->nome, $this->perEsercizio($condominio, $esercizio)['commissioni']));
        }

        return DB::transaction(function () use ($condominio, $esercizio, $ids, $gestioneId, $voceId) {
            $elenco = collect($this->perEsercizio($condominio, $esercizio)['commissioni']);

            $scelte = $ids === null
                ? $elenco->filter(fn ($c) => $c['assegnabile'] && (int) $c['gestione_id'] === (int) $gestioneId)
                : $elenco->whereIn('id', array_map('intval', $ids));

            if ($ids !== null && $scelte->count() !== count(array_unique(array_map('intval', $ids)))) {
                throw new \DomainException(self::ERRORE_GIA_CAMBIATE);
            }
            if ($scelte->isEmpty()) {
                throw new \DomainException(self::NESSUNA_RIGA_SCELTA);
            }
            // In un esercizio aperto ogni riga che non si assegna ha il suo motivo: senza motivo è solo la commissione di un esercizio chiuso, che si
            // ferma sopra con la frase dell'esercizio chiuso.
            $nonAssegnabile = $scelte->first(fn ($c) => ! $c['assegnabile']);
            if ($nonAssegnabile) {
                throw new \DomainException((string) $nonAssegnabile['motivo']);
            }
            $gestioni = $scelte->pluck('gestione_id')->unique();
            if ($gestioni->count() > 1) {
                throw new \DomainException(($scelte->contains(fn ($c) => $c['natura'] === 'registrazione') ? 'Le righe scelte' : 'Le commissioni scelte')
                    .' sono di gestioni diverse: si assegnano una gestione alla volta, anche con la stessa voce.');
            }

            // Lo storno di una commissione che aveva una voce di una gestione che questo esercizio non ha: solo una voce di un'ordinaria di questo esercizio
            // (nessuna gestione «del pagamento» per la verifica).
            $soloOrdinarie = $scelte->contains(fn ($c) => $c['solo_ordinarie']);
            $storni = $scelte->where('natura', 'registrazione')->count();
            $storniDaSoli = $scelte->filter(fn ($c) => $c['righe'][0]['tipo'] === 'storno')->count();
            $entrate = $scelte->where('voce_di_entrata', true)->count();
            if ($entrate > 0 && $entrate !== $scelte->count()) {
                throw new \DomainException(self::STORNI_DI_DUE_TIPI);
            }
            if ($entrate > 0) {
                $voce = $this->voceDiEntrata((int) $condominio->id, $voceId, (int) $esercizio->id);
            } else {
                try {
                    $voce = app(VoceDellaCommissione::class)->verifica((int) $condominio->id, $soloOrdinarie ? 0 : (int) $gestioni->first(), $voceId, 1, (int) $esercizio->id);
                } catch (VoceCommissioneNonAmmessaException $e) {
                    // Le frasi della verifica parlano della commissione: per lo storno di una regolazione la regola detta per lo storno.
                    throw $storni > 0 ? new VoceCommissioneNonAmmessaException(self::VOCE_DELLO_STORNO) : $e;
                }
            }

            $righe = $scelte->flatMap(fn ($c) => array_column($c['righe'], 'riga_id'))->map(fn ($id) => (int) $id)->all();
            // Le righe bloccate e rilette: una riga che nel frattempo ha preso una voce non si sovrascrive.
            $libere = DB::table('righe_scritture')->whereIn('id', $righe)->whereNull('voce_spesa_id')->lockForUpdate()->pluck('id')->all();
            if (count($libere) !== count($righe)) {
                throw new \DomainException(self::ERRORE_GIA_CAMBIATE);
            }
            // La traccia della scelta sulla riga del giornale (la pagina della scrittura la mostra): quando, chi, e dove stava prima la commissione.
            $prima = DB::table('righe_scritture as rs')->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')->whereIn('rs.id', $righe)
                ->get(['rs.id', 'rs.note', 'cc.nome', 'cc.ruolo'])->keyBy('id');
            $cambiate = DB::table('righe_scritture')->whereIn('id', $righe)->whereNull('voce_spesa_id')
                ->update(['voce_spesa_id' => $voce->id, 'conto_contabile_id' => $voce->conto_contabile_id, 'updated_at' => now()]);
            if ($cambiate !== count($righe)) {
                throw new \DomainException(self::ERRORE_GIA_CAMBIATE);
            }
            $giorno = CarbonImmutable::parse(DateHelper::oggiUtente())->format('d/m/Y');
            $chi = auth()->user()?->name;
            foreach ($prima as $id => $r) {
                $traccia = 'Voce assegnata il '.$giorno.($chi ? ' da '.$chi : '')
                    .($r->ruolo === 'spese_bancarie' ? ' (prima senza voce, sulle «'.$r->nome.'» di sistema)' : ' (prima senza voce, sul conto «'.$r->nome.'»)');
                DB::table('righe_scritture')->where('id', $id)->update(['note' => trim(((string) $r->note).' '.$traccia)]);
            }

            return ['numero' => $scelte->count(), 'importo' => (int) $scelte->sum('importo'), 'voce' => (string) $voce->nome, 'storni' => $storniDaSoli, 'entrata' => $entrate > 0];
        });
    }

    /** Le righe sulle «Spese bancarie» di sistema senza voce, dei pagamenti e dei loro storni, con il fornitore. */
    private function righeSenzaVoce(Condominio $condominio)
    {
        $sistema = ContoContabile::query()->where('condominio_id', $condominio->id)->where('ruolo', 'spese_bancarie')->pluck('id')->all();

        return DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
            ->leftJoin('pagamenti_fornitori as pf', 'pf.scrittura_contabile_id', '=', 'sc.id')
            ->leftJoin('fornitori as f', 'f.id', '=', 'pf.fornitore_id')
            ->where('sc.condominio_id', $condominio->id)
            ->whereNull('sc.deleted_at')
            ->whereIn('rs.conto_contabile_id', $sistema === [] ? [0] : $sistema)
            ->whereNull('rs.voce_spesa_id')
            ->whereIn('sc.tipo_movimento', [TipoMovimentoContabile::PAGAMENTO_FORNITORE->value, TipoMovimentoContabile::STORNO_PAGAMENTO_FORNITORE->value])
            ->orderBy('sc.data_competenza')
            ->orderBy('rs.id')
            ->select([
                'rs.id as riga_id', 'rs.tipo_riga', 'rs.importo', 'sc.id as scrittura_id', 'sc.scrittura_padre_id', 'sc.numero_protocollo',
                'sc.data_competenza', 'sc.tipo_movimento', 'sc.gestione_id', 'f.ragione_sociale as fornitore',
            ]);
    }

    /**
     * La voce di entrata per lo storno di un incasso da terzi: del condominio, senza sottovoci, con il mastro e non sotto un capitolo di spesa
     * ({@see VoceDellaCommissione::vociAmmesse()}), di una gestione ordinaria attiva e legata all'esercizio con un legame attivo.
     *
     * @throws VoceCommissioneNonAmmessaException
     */
    private function voceDiEntrata(int $condominioId, int $voceId, int $esercizioId): Conto
    {
        $voce = app(VoceDellaCommissione::class)->vociAmmesse($condominioId, entrate: true)->with('pianoConto.gestione')->find($voceId);
        $gestione = $voce?->pianoConto?->gestione;
        if ($gestione === null || $gestione->tipo !== 'ordinaria' || ! $gestione->attiva || ! VoceDellaCommissione::legataAllEsercizio((int) $gestione->id, $esercizioId)) {
            throw new VoceCommissioneNonAmmessaException(self::VOCE_DELLO_STORNO_DI_UN_INCASSO);
        }

        return $voce;
    }

    /** Le righe degli storni senza voce di registrazioni di un altro esercizio ({@see VoceNelloStorno::righeSenzaVoce()}), con l'esercizio dell'originale. */
    private function righeDegliStorni(int $esercizioId)
    {
        return VoceNelloStorno::righeSenzaVoce($esercizioId)
            ->leftJoin('esercizi as eo', 'eo.id', '=', 'originale.esercizio_id')
            ->orderBy('sc.data_competenza')
            ->orderBy('rs.id')
            ->get([
                'rs.id as riga_id', 'rs.tipo_riga', 'rs.importo', 'rs.conto_contabile_id', 'sc.id as scrittura_id', 'sc.scrittura_padre_id', 'sc.numero_protocollo',
                'sc.data_competenza', 'sc.tipo_movimento', 'sc.gestione_id', DB::raw('NULL as fornitore'), 'eo.nome as esercizio_originale',
            ]);
    }

    private function stornoInAltroEsercizio(int $scritturaId, int $esercizioId): bool
    {
        return DB::table('scritture_contabili')
            ->where('scrittura_padre_id', $scritturaId)
            ->where('tipo_movimento', TipoMovimentoContabile::STORNO_PAGAMENTO_FORNITORE->value)
            ->where('esercizio_id', '!=', $esercizioId)
            ->whereNull('deleted_at')
            ->exists();
    }

    /** @return array{riga_id: int, scrittura_id: int, protocollo: ?string, data: ?string, importo: int, tipo: string} */
    private function riga(object $r): array
    {
        return [
            'riga_id' => (int) $r->riga_id,
            'scrittura_id' => (int) $r->scrittura_id,
            'protocollo' => $r->numero_protocollo,
            'data' => $r->data_competenza ? substr((string) $r->data_competenza, 0, 10) : null,
            // Con il segno della riga di costo: la commissione in più, lo storno in meno (lo storno di un incasso da terzi in più: l'entrata torna indietro).
            'importo' => $r->tipo_riga === 'dare' ? (int) $r->importo : -(int) $r->importo,
            'tipo' => $r->tipo_movimento === TipoMovimentoContabile::PAGAMENTO_FORNITORE->value ? 'commissione' : 'storno',
        ];
    }

    /**
     * `solo_ordinarie`: la voce si sceglie fra quelle delle ordinarie di questo esercizio, non fra quelle della gestione del pagamento. `natura`:
     * «commissione» (una commissione, o il suo storno) o «registrazione» (lo storno di una regolazione immediata o di un incasso da terzi). `voce_di_entrata`:
     * lo storno di un incasso da terzi, che si assegna a una voce di entrata.
     */
    private function commissione(array $righe, object $prima, $gestioni, bool $assegnabile, ?string $motivo, bool $soloOrdinarie = false, string $natura = 'commissione',
        bool $voceDiEntrata = false): array
    {
        return [
            'id' => (int) $righe[0]['riga_id'],
            'righe' => $righe,
            'fornitore' => $prima->fornitore,
            'gestione_id' => $prima->gestione_id === null ? null : (int) $prima->gestione_id,
            'gestione' => $prima->gestione_id === null ? null : ($gestioni[$prima->gestione_id] ?? null),
            'importo' => (int) array_sum(array_column($righe, 'importo')),
            'assegnabile' => $assegnabile,
            'motivo' => $motivo,
            'solo_ordinarie' => $soloOrdinarie,
            'natura' => $natura,
            'voce_di_entrata' => $voceDiEntrata,
        ];
    }
}
