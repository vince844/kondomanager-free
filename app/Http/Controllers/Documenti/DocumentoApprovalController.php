<?php

namespace App\Http\Controllers\Documenti;

use App\Http\Controllers\Controller;
use App\Models\Documento;
use App\Traits\HandleFlashMessages;
use App\Traits\ScaricaEApprovaDocumento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class DocumentoApprovalController extends Controller
{
    use HandleFlashMessages, ScaricaEApprovaDocumento;

    /**
     * Handle the incoming request.
     *
     * L'interruttore dell'archivio: lavora sui documenti dell'archivio, quelli senza un proprietario. Un documento di una
     * persona, di un fornitore, di un'unità o di una fattura per questa rotta non c'è (404): si approva dalla pagina del
     * proprietario, con il suo permesso.
     */
    public function __invoke(Documento $documento): RedirectResponse
    {
        Gate::authorize('approve', $documento);

        abort_unless($documento->documentable_type === null, 404);

        return $this->alternaLApprovazione($documento);
    }
}
