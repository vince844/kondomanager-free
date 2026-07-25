<?php

namespace App\Http\Controllers\Gestionale\Contributi;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Registrazione di quanto ciascuna unità ha GIÀ VERSATO verso una voce di spesa.
 *
 * Serve quando un condominio arriva da un altro gestionale portandosi dietro un
 * accantonamento già raccolto: senza questo dato il motore di riparto richiede
 * l'intera spesa una seconda volta (docs/fondo_accantonato_e_quadratura_sp.md §4).
 */
class ContributoVersatoController extends Controller
{
    /** Elenco delle voci di spesa con lo stato della copertura già versata. */
    public function index(Condominio $condominio): Response
    {
        $voci = Conto::query()
            ->whereHas('pianoConto', fn ($q) => $q->where('condominio_id', $condominio->id))
            ->where('tipo', 'spesa')
            ->where('attivo', true)
            // I capitoli sono contenitori, non spese: non si versa nulla "verso" un
            // capitolo, si versa verso i sottoconti che lo compongono (beta.22).
            ->where('is_capitolo', false)
            ->with('pianoConto.gestione')
            ->get();

        $coperture = ContributoVersato::query()
            ->where('condominio_id', $condominio->id)
            ->where('target_type', Conto::class)
            ->selectRaw('target_id, SUM(importo_cents) as totale, COUNT(DISTINCT immobile_id) as unita')
            ->groupBy('target_id')
            ->get()
            ->keyBy('target_id');

        return Inertia::render('gestionale/contributi/ContributiList', [
            'condominio' => $condominio,
            'voci' => $voci->map(function (Conto $c) use ($coperture) {
                $cop = $coperture->get($c->id);

                return [
                    'id'              => $c->id,
                    'nome'            => $c->nome,
                    'gestione'        => $c->pianoConto?->gestione?->nome,
                    'gestione_tipo'   => $c->pianoConto?->gestione?->tipo,
                    'importo_cents'   => (int) $c->importo,
                    'coperto_cents'   => (int) ($cop->totale ?? 0),
                    'unita_coperte'   => (int) ($cop->unita ?? 0),
                ];
            })->values(),
        ]);
    }

    /** Form di inserimento: unità, millesimi, quota lorda e contributo già versato. */
    public function edit(Condominio $condominio, Conto $conto): Response
    {
        abort_unless($conto->pianoConto?->condominio_id === $condominio->id, 404);
        abort_if((bool) $conto->is_capitolo, 404);

        $conto->load('tabelleMillesimali.tabella.quote.immobile');

        $importo = (int) $conto->importo;

        // Peso di ciascuna unità sulla spesa, mediando le tabelle collegate secondo
        // il loro coefficiente: è la stessa base che usa il motore di riparto.
        $pesi = [];
        $immobili = [];

        foreach ($conto->tabelleMillesimali as $ctm) {
            $tabella = $ctm->tabella;
            $coeff   = (float) $ctm->coefficiente;

            if (! $tabella || $coeff <= 0) {
                continue;
            }

            $somma = (float) $tabella->quote->sum('valore');
            if ($somma <= 0) {
                continue;
            }

            foreach ($tabella->quote as $q) {
                if (! $q->immobile || (float) $q->valore <= 0) {
                    continue;
                }

                $id = $q->immobile->id;
                $immobili[$id] ??= [
                    'id'        => $id,
                    'nome'      => $q->immobile->nome,
                    'interno'   => $q->immobile->interno,
                    'millesimi' => 0.0,
                ];
                $immobili[$id]['millesimi'] += (float) $q->valore;

                $pesi[$id] = ($pesi[$id] ?? 0) + ((float) $q->valore / $somma) * ($coeff / 100);
            }
        }

        $pesoTotale = array_sum($pesi) ?: 1.0;

        $giaVersato = ContributoVersato::query()
            ->where('target_type', Conto::class)
            ->where('target_id', $conto->id)
            ->get()
            ->keyBy('immobile_id');

        $righe = collect($immobili)->map(function ($im) use ($pesi, $pesoTotale, $importo, $giaVersato) {
            $peso  = ($pesi[$im['id']] ?? 0) / $pesoTotale;
            $lordo = (int) round($importo * $peso);
            $cv    = $giaVersato->get($im['id']);

            return [
                'immobile_id'    => $im['id'],
                'nome'           => $im['nome'],
                'interno'        => $im['interno'],
                'millesimi'      => round($im['millesimi'], 2),
                'quota_lorda'    => $lordo,
                'gia_versato'    => (int) ($cv->importo_cents ?? 0),
                'contributo_id'  => $cv->id ?? null,
            ];
        })->sortBy('interno')->values();

        $naturaCorrente = $giaVersato->first()?->natura ?? ContributoVersato::NATURA_FONDO_VINCOLATO;

        return Inertia::render('gestionale/contributi/ContributiEdit', [
            'condominio' => $condominio,
            'voce' => [
                'id'            => $conto->id,
                'nome'          => $conto->nome,
                'importo_cents' => $importo,
                'gestione'      => $conto->pianoConto?->gestione?->nome,
            ],
            'righe'  => $righe,
            'natura' => $naturaCorrente,
        ]);
    }

    /** Salva i contributi: una riga per unità, sostituendo quanto già presente. */
    public function update(Request $request, Condominio $condominio, Conto $conto)
    {
        abort_unless($conto->pianoConto?->condominio_id === $condominio->id, 404);
        abort_if((bool) $conto->is_capitolo, 404);

        $dati = $request->validate([
            'natura'                 => ['required', 'in:fondo_vincolato,avanzo'],
            'righe'                  => ['required', 'array'],
            // Scopato al condominio della rotta: senza questo vincolo un
            // amministratore multi-condominio potrebbe (per errore o payload
            // manomesso) registrare una copertura sull'immobile di UN ALTRO
            // condominio — il netting non la applicherebbe mai a nulla, ma la
            // riga resterebbe orfana nel ledger, invisibile e cross-tenant.
            'righe.*.immobile_id'    => [
                'required', 'integer',
                Rule::exists('immobili', 'id')->where('condominio_id', $condominio->id),
            ],
            'righe.*.gia_versato'    => ['required', 'integer', 'min:0'],
            'descrizione'            => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($dati, $condominio, $conto) {
            // Sostituzione integrale: l'insieme inviato è la nuova verità per questa voce.
            ContributoVersato::where('target_type', Conto::class)
                ->where('target_id', $conto->id)
                ->delete();

            foreach ($dati['righe'] as $riga) {
                if ((int) $riga['gia_versato'] <= 0) {
                    continue;
                }

                ContributoVersato::create([
                    'condominio_id' => $condominio->id,
                    'target_type'   => Conto::class,
                    'target_id'     => $conto->id,
                    'immobile_id'   => $riga['immobile_id'],
                    'importo_cents' => (int) $riga['gia_versato'],
                    'natura'        => $dati['natura'],
                    'origine'       => 'migrazione',
                    'descrizione'   => $dati['descrizione'] ?? null,
                ]);
            }
        });

        return back()->with('message', [
            'type' => 'success',
            'text' => 'Contributi già versati aggiornati: il riparto chiederà solo il residuo.',
        ]);
    }
}
