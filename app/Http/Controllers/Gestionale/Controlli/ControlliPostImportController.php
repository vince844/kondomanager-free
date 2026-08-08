<?php

namespace App\Http\Controllers\Gestionale\Controlli;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\ImportBatch;
use App\Services\Import\Controlli\ControlliPostImport;
use App\Services\Import\Controlli\StatoControllo;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Da controllare dopo l'importazione» — i consigli della schermata di esito, ritrovabili.
 *
 * ## Perché vive nel gestionale e non nell'import
 *
 * Perché la domanda arriva **giorni dopo**, quando l'uuid del lotto non ce l'ha più nessuno. La
 * lista descrive lo stato del condominio, non l'importazione: si raggiunge dal condominio.
 *
 * Ne discende il permesso: si eredita quello del gestionale, **non** quello dell'import. Chi non
 * poteva importare deve poter controllare, perché il lavoro di sistemazione è suo. Per la stessa
 * ragione il link al rapporto originale — che sta dietro «Crea condomini» — si disegna solo a chi
 * quel permesso ce l'ha: una lista che offre un collegamento a un 403 è peggio di una lista senza.
 */
class ControlliPostImportController extends Controller
{
    use HandleFlashMessages;

    public function __construct(private readonly ControlliPostImport $controlli) {}

    public function index(Request $request, Condominio $condominio): Response
    {
        $voci = $this->controlli->perCondominio($condominio);

        return Inertia::render('gestionale/controlli/ControlliPostImportList', [
            'condominio' => ['id' => $condominio->id, 'nome' => $condominio->nome],
            'voci' => $voci,
            'aperte' => count(array_filter($voci, fn ($v) => $v['stato'] === StatoControllo::Aperto->value)),
            'puo_vedere_rapporto' => $request->user()?->can('Crea condomini') ?? false,
        ]);
    }

    public function aggiorna(Request $request, Condominio $condominio, ImportBatch $batch)
    {
        abort_unless($batch->condominio_id === $condominio->id, 404);

        $validato = $request->validate([
            'chiave' => ['required', 'string', 'max:190'],
            'azione' => ['required', 'string', 'in:spunta,metti_da_parte,riapri'],
            // Facoltativa di proposito: obbligarla renderebbe «metti da parte» più costoso di
            // «spunta», e spingerebbe a spuntare al posto di mettere da parte — che è peggio,
            // perché «spuntato» significa «l'ho controllato» ed è l'informazione più preziosa.
            'nota' => ['nullable', 'string', 'max:500'],
        ]);

        $voce = collect($this->controlli->perLotto($batch))
            ->firstWhere('chiave', $validato['chiave']);

        abort_if($voce === null, 422, 'Questo controllo non appartiene a questa importazione.');

        if ($validato['azione'] === 'riapri') {
            $batch->riapri($validato['chiave']);

            return back()->with($this->flashSuccess('Controllo riaperto.'));
        }

        // Spuntare a mano una voce che il sistema sa verificare da solo produrrebbe un verde
        // falso destinato a sopravvivere al problema. L'eccezione è la voce «superata»: lì la
        // finestra utile si è chiusa e la spunta è l'unico modo di toglierla di mezzo.
        if ($validato['azione'] === 'spunta' && $voce['verificabile'] && ! $voce['superata']) {
            return back()->with($this->flashError(
                'Questa si ricontrolla da sola: si chiuderà appena l\'avrai sistemata. '
                .'Se non ti riguarda, mettila da parte.',
            ));
        }

        $batch->segna(
            $validato['chiave'],
            $validato['azione'] === 'spunta'
                ? StatoControllo::Spuntato->value
                : StatoControllo::MessoDaParte->value,
            $request->user()?->id,
            $validato['nota'] ?? null,
        );

        return back()->with($this->flashSuccess(
            $validato['azione'] === 'spunta' ? 'Segnato come controllato.' : 'Messo da parte.',
        ));
    }
}
