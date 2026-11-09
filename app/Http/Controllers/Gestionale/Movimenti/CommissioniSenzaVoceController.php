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
 * L'elenco delle commissioni scritte senza voce di un esercizio, con gli storni senza voce di registrazioni di un esercizio chiuso, e «Assegna la voce»
 * in blocco (`CommissioniSenzaVoce`). L'esercizio è nella rotta, dentro il condominio (un esercizio di un altro condominio risponde 404 a tutta la richiesta).
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
            'frase_chiuso' => $aperto ? null : CommissioniSenzaVoce::fraseChiusoPerLElenco((string) $esercizio->nome, $elenco['commissioni']),
            'voci' => $voci->elenco($condominio->id),
            // Le voci di entrata, per lo storno di un incasso da terzi: si assegna a una voce di entrata di un'ordinaria dell'esercizio.
            'voci_di_entrata' => $voci->elenco($condominio->id, entrate: true),
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
            'voce_id.required' => 'Scegli la voce da assegnare alle righe scelte.',
            'voce_id.integer' => 'La voce scelta non è valida: scegli una voce dal menu.',
            'gestione_id.required_if' => 'Per assegnare la voce a tutte le righe, scegli la gestione.',
        ]);

        $tutte = (bool) ($validated['tutte'] ?? false);
        if (! $tutte && empty($validated['commissioni'])) {
            return back()->withErrors(['commissioni' => CommissioniSenzaVoce::NESSUNA_RIGA_SCELTA]);
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

        $quante = self::quante($esito['numero'] - $esito['storni'], $esito['storni']);
        Log::info("Voce assegnata a {$quante} senza voce da utente #".Auth::id()." — condominio #{$condominio->id}, esercizio #{$esercizio->id}");

        return back()->with($this->flashSuccess(
            "Voce «{$esito['voce']}» assegnata a {$quante}: ".($esito['entrata']
                ? 'l\'incassato della voce cambia di '.MoneyHelper::format(-$esito['importo'])
                : 'il consuntivo della voce cambia di '.MoneyHelper::format($esito['importo'])).'.'
        ));
    }

    /** «1 commissione», «2 storni», «1 commissione e 1 storno»: le commissioni (con i loro storni) e gli storni da soli. */
    private static function quante(int $commissioni, int $storni): string
    {
        return implode(' e ', array_filter([
            $commissioni > 0 ? ($commissioni === 1 ? '1 commissione' : "{$commissioni} commissioni") : null,
            $storni > 0 ? ($storni === 1 ? '1 storno' : "{$storni} storni") : null,
        ]));
    }
}
