<?php

namespace App\Services\Gestionale;

use App\Models\Condominio;
use App\Models\Gestionale\ContributoVersato;
use Illuminate\Support\Facades\DB;

/**
 * La vista «di cui» sulla liquidità — D11 di docs/registri_contabili.md: quanta è libera, quanta
 * è accantonata nei fondi, e se i vincoli registrati hanno una cassa che li tenga (R8, D20).
 *
 * IL VINCOLO DI FERRO: NON TOCCA I TOTALI, E NE HA UNO SOLO. La liquidità totale è quella della
 * fotografia — tutti i conti di categoria `liquidita` più i saldi di apertura non ancora a giornale
 * — e la vista la scompone: libera (banca e contanti), nei fondi, e il resto. La revisione della
 * beta.25 ha trovato che una prima versione sommava le sole casse e mostrava DUE liquidità diverse
 * sulla stessa pagina (una cassa virtuale, un conto senza cassa o un saldo non registrato stavano
 * in una e non nell'altra). Se le parti non fanno il totale, è questa vista a essere sbagliata,
 * mai il totale: il resto va mostrato con il suo nome, non nascosto.
 *
 * LE DUE FONTI, LETTE INSIEME. Le casse `fondo` dicono quanta liquidità è materialmente
 * accantonata; `contributi_versati` con `natura = fondo_vincolato` e ancora liquidi dice a che
 * titolo è stata raccolta — e con `cassa_id` dice DOVE è stata messa. È il legame che rende R8 un
 * controllo vero: per ogni fondo, vincolo registrato su quel fondo contro il suo saldo.
 *
 * ⚠️ **Un vincolo registrato su banca non è un errore.** Il software stesso raccomanda di aprire la
 * cassa col saldo dell'estratto conto e dichiarare il già versato come `gia_in_apertura`: quei
 * soldi sono in banca, vincolati sulla carta e non accantonati in un fondo. La prima versione lo
 * dava rosso («nessuna cassa che li tenga»). Ora è una segnalazione: «vincolati e non accantonati,
 * valuta un accantonamento». Rosso solo se un fondo ha meno di quanto gli è stato assegnato.
 *
 * ⚠️ **`sottotipo_fondo` mancante vale «vincolato».** Un fondo legacy mai risalvato ha il sottotipo
 * a NULL; `TreasuryGuardianService` lo conta fra i vincolati, e qui non si legge il contrario:
 * solo il `generico` esplicito è liberamente utilizzabile. Etichetta «sottotipo non indicato».
 *
 * ⚠️ **I contributi versati non hanno una data di competenza.** `created_at` è quando sono stati
 * dichiarati — e viene azzerato da un risalvataggio — quindi non si taglia per data: R8 è un
 * controllo «a oggi». Per un esercizio chiuso resta esposto come segnalazione, con la nota.
 */
class LiquiditaVincolataService
{
    private const SOTTOTIPI = ['generico' => 'generico', 'vincolato_lavori' => 'vincolato ai lavori', 'tfr' => 'TFR', 'morosita' => 'morosità'];

    /**
     * @param  array<int, array<string, mixed>>  $vociAttivo  le voci dell'attivo della fotografia
     */
    public function allaData(Condominio $condominio, array $vociAttivo, int $liquiditaNonContabilizzata, string $statoEsercizio): array
    {
        $saldiPerConto = collect($vociAttivo)->keyBy('id');
        $liquiditaConti = (int) collect($vociAttivo)->where('categoria', 'liquidita')->sum('saldo');
        $totale = $liquiditaConti + $liquiditaNonContabilizzata;

        $casse = DB::table('casse')
            ->where('condominio_id', $condominio->id)
            ->whereNotNull('conto_contabile_id')
            ->get(['id', 'nome', 'tipo', 'sottotipo_fondo', 'conto_contabile_id']);

        $contributi = ContributoVersato::query()
            ->where('condominio_id', $condominio->id)
            ->get(['natura', 'liquidita_stato', 'importo_cents', 'cassa_id']);
        $ancoraLiquidi = $contributi->filter(fn ($c) => in_array($c->liquidita_stato, [
            ContributoVersato::LIQUIDITA_REGISTRATA_IN_CASSA,
            ContributoVersato::LIQUIDITA_GIA_IN_APERTURA,
            null, // dichiarato prima che lo stato esistesse
        ], true));
        $vincolati = $ancoraLiquidi->where('natura', ContributoVersato::NATURA_FONDO_VINCOLATO);

        $libera = 0;
        $fondi = [];
        $inFondi = 0;
        $vincolata = 0;
        $generica = 0;
        $contiClassificati = [];
        $idFondi = $casse->where('tipo', 'fondo')->pluck('id')->map(fn ($i) => (int) $i)->all();

        foreach ($casse as $cassa) {
            $conto = (int) $cassa->conto_contabile_id;
            $saldo = (int) ($saldiPerConto[$conto]['saldo'] ?? 0);
            $contiClassificati[$conto] = true;

            if ($cassa->tipo === 'fondo') {
                $vincolato = $cassa->sottotipo_fondo !== 'generico';
                $vincoloSuQuesto = (int) $vincolati->where('cassa_id', (int) $cassa->id)->sum('importo_cents');
                $fondi[] = [
                    'cassa' => $cassa->nome,
                    'sottotipo' => $cassa->sottotipo_fondo,
                    'sottotipo_label' => self::SOTTOTIPI[$cassa->sottotipo_fondo ?? ''] ?? 'sottotipo non indicato',
                    'saldo' => $saldo,
                    'vincolato' => $vincolato,
                    'vincolo_registrato' => $vincoloSuQuesto,
                    'scoperto' => max(0, $vincoloSuQuesto - $saldo),
                ];
                $inFondi += $saldo;
                if ($vincolato) {
                    $vincolata += $saldo;
                } else {
                    $generica += $saldo;
                }
            } elseif (in_array($cassa->tipo, ['banca', 'contanti'], true)) {
                $libera += $saldo;
            }
        }

        // Ciò che è liquidità e non sta in una cassa reale né in un fondo: casse virtuali, conti
        // di liquidità senza cassa, saldi di apertura non registrati. Va mostrato con il suo nome.
        $altra = $totale - $libera - $inFondi;
        $altraVoci = [];
        if ($liquiditaNonContabilizzata !== 0) {
            $altraVoci[] = ['voce' => 'Saldi di apertura non ancora registrati a giornale', 'saldo' => $liquiditaNonContabilizzata];
        }
        foreach ($vociAttivo as $v) {
            if ($v['categoria'] === 'liquidita' && ! isset($contiClassificati[(int) $v['id']]) && (int) $v['saldo'] !== 0) {
                $altraVoci[] = ['voce' => $v['nome'].' (conto senza cassa)', 'saldo' => (int) $v['saldo']];
            }
        }
        foreach ($casse->where('tipo', 'virtuale') as $cassa) {
            $saldo = (int) ($saldiPerConto[(int) $cassa->conto_contabile_id]['saldo'] ?? 0);
            if ($saldo !== 0) {
                $altraVoci[] = ['voce' => $cassa->nome.' (cassa virtuale)', 'saldo' => $saldo];
            }
        }

        // R8: per fondo, vincolo assegnato contro saldo. I vincoli senza un fondo (in banca, o senza
        // cassa) sono una segnalazione, non un rosso.
        $vincoloTotale = (int) $vincolati->sum('importo_cents');
        $vincoloSuFondi = (int) $vincolati->whereIn('cassa_id', $idFondi)->sum('importo_cents');
        $vincoloNonAccantonato = $vincoloTotale - $vincoloSuFondi;
        $scopertoFondi = (int) array_sum(array_column($fondi, 'scoperto'));

        if ($statoEsercizio === 'chiuso') {
            $esito = 'segnalazione';
        } elseif ($scopertoFondi > 0) {
            $esito = 'scoperto';
        } elseif ($vincoloNonAccantonato > 0) {
            $esito = 'non_accantonato';
        } else {
            $esito = 'quadra';
        }

        return [
            'liquidita_totale' => $totale,
            'libera' => $libera,
            'in_fondi' => $inFondi,
            'vincolata' => $vincolata,
            'generica' => $generica,
            'altra' => $altra,
            'altra_voci' => $altraVoci,
            'fondi' => $fondi,
            'vincolo_registrato' => $vincoloTotale,
            'vincolo_su_fondi' => $vincoloSuFondi,
            'vincolo_non_accantonato' => $vincoloNonAccantonato,
            'scoperto_fondi' => $scopertoFondi,
            'avanzo_registrato' => (int) $ancoraLiquidi->where('natura', ContributoVersato::NATURA_AVANZO)->sum('importo_cents'),
            'gia_speso_acconto' => (int) $contributi->where('liquidita_stato', ContributoVersato::LIQUIDITA_GIA_SPESO_ACCONTO)->sum('importo_cents'),
            'esito_r8' => $esito,
        ];
    }
}
