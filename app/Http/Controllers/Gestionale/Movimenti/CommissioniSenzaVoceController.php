<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Exceptions\Pagamenti\VoceCommissioneNonAmmessaException;
use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Services\Gestionale\CommissioniSenzaVoce;
use App\Services\Gestionale\VoceDellaCommissione;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'elenco delle commissioni scritte senza voce di un esercizio, e «Assegna la voce» in blocco (`CommissioniSenzaVoce`). L'esercizio è nella
 * rotta, dentro il condominio (un esercizio di un altro condominio risponde 404 a tutta la richiesta).
 */
class CommissioniSenzaVoceController extends Controller
{
    use HandleFlashMessages;
    use HasCondomini;

    public function index(Condominio $condominio, Esercizio $esercizio, CommissioniSenzaVoce $commissioni, VoceDellaCommissione $voci): Response
    {
        // La rotta cerca già l'esercizio dentro il condominio; la guardia resta, se un giorno la rotta uscisse dal vincolo.
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);

        $elenco = $commissioni->perEsercizio($condominio, $esercizio);
        $aperto = $esercizio->stato === 'aperto';

        return Inertia::render('gestionale/movimenti/commissioniSenzaVoce/CommissioniSenzaVoce', [
            'condominio' => $condominio,
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio' => ['id' => $esercizio->id, 'nome' => $esercizio->nome, 'stato' => $esercizio->stato, 'aperto' => $aperto],
            'commissioni' => $elenco['commissioni'],
            'totale' => $elenco['totale'],
            'frase_chiuso' => $aperto ? null : CommissioniSenzaVoce::fraseChiuso((string) $esercizio->nome),
            'voci' => $voci->elenco($condominio->id),
            'proposte' => $voci->proposte($condominio->id),
        ]);
    }

    public function assegna(Request $request, Condominio $condominio, Esercizio $esercizio, CommissioniSenzaVoce $commissioni): RedirectResponse
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);

        $validated = $request->validate([
            'commissioni' => ['nullable', 'array'],
            'commissioni.*' => ['integer'],
            'tutte' => ['nullable', 'boolean'],
            'gestione_id' => ['nullable', 'integer', 'required_if:tutte,true'],
            'voce_id' => ['required', 'integer'],
        ], [
            'voce_id.required' => 'Scegli la voce da assegnare alle commissioni.',
            'voce_id.integer' => 'La voce scelta non è valida: scegli una voce dal menu.',
            'gestione_id.required_if' => 'Per assegnare la voce a tutte le commissioni, scegli la gestione.',
        ]);

        $tutte = (bool) ($validated['tutte'] ?? false);
        if (! $tutte && empty($validated['commissioni'])) {
            return back()->withErrors(['commissioni' => 'Nessuna commissione da assegnare: scegline almeno una.']);
        }

        // Un id che non è una riga di questo condominio (di un altro, o che non esiste) è un 404 per tutta la richiesta, come per le ricevute: nessuna
        // riga cambia. Una riga di questo condominio che nel frattempo ha preso una voce resta il rifiuto con la frase («ricarica l'elenco»).
        if (! $tutte) {
            $ids = array_values(array_unique(array_map('intval', $validated['commissioni'])));
            $diQuesto = DB::table('righe_scritture as rs')->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
                ->whereIn('rs.id', $ids)->where('sc.condominio_id', $condominio->id)->count();
            abort_if($diQuesto !== count($ids), 404);
        }

        try {
            $esito = $commissioni->assegna(
                $condominio,
                $esercizio,
                $tutte ? null : array_map('intval', $validated['commissioni']),
                isset($validated['gestione_id']) ? (int) $validated['gestione_id'] : null,
                (int) $validated['voce_id'],
            );
        } catch (VoceCommissioneNonAmmessaException $e) {
            return back()->withErrors(['voce_id' => $e->getMessage()]);
        } catch (\DomainException $e) {
            return back()->withErrors(['commissioni' => $e->getMessage()]);
        }

        Log::info("Voce assegnata a {$esito['numero']} commissioni senza voce da utente #".Auth::id()." — condominio #{$condominio->id}, esercizio #{$esercizio->id}");

        $quante = $esito['numero'] === 1 ? '1 commissione' : "{$esito['numero']} commissioni";

        return back()->with($this->flashSuccess(
            "Voce «{$esito['voce']}» assegnata a {$quante}: il consuntivo della voce cambia di ".MoneyHelper::format($esito['importo']).'.'
        ));
    }
}
