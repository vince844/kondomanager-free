<?php

namespace App\Services\Gestionale;

use App\Enums\TipoMovimentoContabile;
use App\Helpers\DateHelper;
use App\Exceptions\Pagamenti\VoceCommissioneNonAmmessaException;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestione;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Le commissioni dei pagamenti al fornitore scritte senza voce, e l'assegnazione a mano della voce negli esercizi aperti.
 *
 * Fino a questa versione la commissione andava sulle «Spese bancarie» di sistema (ruolo `spese_bancarie`), senza voce: fuori dal consuntivo
 * e dal riparto. Le commissioni nuove hanno la voce (`VoceDellaCommissione`); quelle già scritte **non prendono un valore da una regola nuova**
 * (nessun riempimento): restano come sono, e qui l'amministratore le vede e, se vuole, sceglie la voce.
 *
 * - **Per esercizio**: le righe delle scritture dell'esercizio. «Aperto» è lo stato scritto dell'esercizio, non la data: un esercizio finito
 *   per data e tenuto aperto fino all'assemblea si corregge, uno chiuso no (il suo rendiconto è già stato presentato).
 * - **La commissione e il suo storno stanno insieme** e prendono la voce insieme, così il consuntivo della voce resta giusto. Uno storno il cui
 *   originale sta in un altro esercizio (lo storno di un pagamento di un esercizio chiuso va nell'esercizio aperto) non si offre: metterebbe
 *   −€ 2,50 su una voce dell'anno nuovo contro un originale che resta senza voce.
 * - **L'assegnazione** mette sulla riga la voce e il mastro della voce, come scrive oggi una commissione nuova: il consuntivo della voce sale
 *   dell'importo. Una gestione alla volta (la voce è di una gestione). Se nel frattempo una riga ha già una voce, la richiesta è rifiutata per
 *   intero e nessuna riga cambia.
 */
class CommissioniSenzaVoce
{
    public const ERRORE_GIA_CAMBIATE = 'Alcune commissioni scelte non sono più senza voce, o non sono di questo esercizio: ricarica l\'elenco e riprova.';

    public static function fraseChiuso(string $esercizio): string
    {
        return "L'esercizio «{$esercizio}» è chiuso: le commissioni senza voce restano come sono, e il suo rendiconto le mostra in una riga loro. "
            .'La voce si assegna solo negli esercizi aperti.';
    }

    public static function motivoStornoDiUnAltroEsercizio(string $esercizio): string
    {
        return "È lo storno di una commissione dell'esercizio «{$esercizio}», che è chiuso e resta senza voce: lo storno resta senza voce anche lui.";
    }

    /**
     * Le commissioni senza voce dell'esercizio, ognuna con il suo storno se sta nello stesso esercizio.
     *
     * `assegnabili` conta le sole commissioni a cui la voce si può dare e che cambiano il consuntivo (importo diverso da zero): è il numero che invita
     * ad assegnarla; l'elenco le mostra tutte, con il perché di quelle che non si offrono.
     *
     * @return array{commissioni: list<array<string, mixed>>, totale: array{numero: int, importo: int}, assegnabili: array{numero: int, importo: int}}
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
            $commissioni[] = $this->commissione([$this->riga($s)], $s, $gestioni, false, ($esercizioOriginale->stato ?? 'chiuso') === 'chiuso'
                ? self::motivoStornoDiUnAltroEsercizio($nome)
                : "È lo storno di una commissione dell'esercizio «{$nome}»: la commissione e lo storno stanno in due esercizi, e la voce non si assegna a uno solo dei due.");
        }

        usort($commissioni, fn ($a, $b) => [$a['righe'][0]['data'], $a['id']] <=> [$b['righe'][0]['data'], $b['id']]);

        $daAssegnare = array_values(array_filter($commissioni, fn ($c) => $c['assegnabile'] && (int) $c['importo'] !== 0));

        return [
            'commissioni' => $commissioni,
            'totale' => ['numero' => count($commissioni), 'importo' => (int) array_sum(array_column($commissioni, 'importo'))],
            'assegnabili' => ['numero' => count($daAssegnare), 'importo' => (int) array_sum(array_column($daAssegnare, 'importo'))],
        ];
    }

    /**
     * Assegna la voce alle commissioni scelte (o a tutte quelle assegnabili della gestione): voce e mastro della voce sulle righe, la commissione
     * e il suo storno insieme.
     *
     * @param  list<int>|null  $ids  le commissioni scelte (l'`id` dell'elenco); null con `$gestioneId` vuol dire «tutte»
     * @return array{numero: int, importo: int, voce: string}
     *
     * @throws \DomainException con il messaggio per la pagina
     * @throws VoceCommissioneNonAmmessaException se la voce non è di spesa o non è della gestione delle commissioni
     */
    public function assegna(Condominio $condominio, Esercizio $esercizio, ?array $ids, ?int $gestioneId, int $voceId): array
    {
        if ($esercizio->stato !== 'aperto') {
            throw new \DomainException(self::fraseChiuso((string) $esercizio->nome));
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
                throw new \DomainException('Nessuna commissione da assegnare: scegline almeno una.');
            }
            $nonAssegnabile = $scelte->first(fn ($c) => ! $c['assegnabile']);
            if ($nonAssegnabile) {
                throw new \DomainException($nonAssegnabile['motivo'] ?? 'Una commissione scelta non può prendere la voce.');
            }
            $gestioni = $scelte->pluck('gestione_id')->unique();
            if ($gestioni->count() > 1) {
                throw new \DomainException('Le commissioni scelte sono di gestioni diverse: la voce è di una gestione, assegnala una gestione alla volta.');
            }

            $voce = app(VoceDellaCommissione::class)->verifica((int) $condominio->id, (int) $gestioni->first(), $voceId, 1);

            $righe = $scelte->flatMap(fn ($c) => array_column($c['righe'], 'riga_id'))->map(fn ($id) => (int) $id)->all();
            // Le righe bloccate e rilette: una riga che nel frattempo ha preso una voce non si sovrascrive.
            $libere = DB::table('righe_scritture')->whereIn('id', $righe)->whereNull('voce_spesa_id')->lockForUpdate()->pluck('id')->all();
            if (count($libere) !== count($righe)) {
                throw new \DomainException(self::ERRORE_GIA_CAMBIATE);
            }
            // La traccia della scelta sulla riga del giornale (la pagina della scrittura la mostra): quando, chi, e dove stava prima la commissione.
            $prima = DB::table('righe_scritture as rs')->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')->whereIn('rs.id', $righe)
                ->get(['rs.id', 'rs.note', 'cc.nome'])->keyBy('id');
            $cambiate = DB::table('righe_scritture')->whereIn('id', $righe)->whereNull('voce_spesa_id')
                ->update(['voce_spesa_id' => $voce->id, 'conto_contabile_id' => $voce->conto_contabile_id, 'updated_at' => now()]);
            if ($cambiate !== count($righe)) {
                throw new \DomainException(self::ERRORE_GIA_CAMBIATE);
            }
            $giorno = CarbonImmutable::parse(DateHelper::oggiUtente())->format('d/m/Y');
            $chi = auth()->user()?->name;
            foreach ($prima as $id => $r) {
                $traccia = 'Voce assegnata il '.$giorno.($chi ? ' da '.$chi : '').' (prima senza voce, sulle «'.$r->nome.'» di sistema)';
                DB::table('righe_scritture')->where('id', $id)->update(['note' => trim(((string) $r->note).' '.$traccia)]);
            }

            return ['numero' => $scelte->count(), 'importo' => (int) $scelte->sum('importo'), 'voce' => (string) $voce->nome];
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
            // Con il segno della riga di costo: la commissione in più, lo storno in meno.
            'importo' => $r->tipo_riga === 'dare' ? (int) $r->importo : -(int) $r->importo,
            'tipo' => $r->tipo_movimento === TipoMovimentoContabile::STORNO_PAGAMENTO_FORNITORE->value ? 'storno' : 'commissione',
        ];
    }

    private function commissione(array $righe, object $prima, $gestioni, bool $assegnabile, ?string $motivo): array
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
        ];
    }
}
