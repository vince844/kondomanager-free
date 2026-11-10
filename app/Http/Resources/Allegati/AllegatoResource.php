<?php

namespace App\Http\Resources\Allegati;

use App\Models\Comunicazione;
use App\Models\Documento;
use App\Models\Segnalazione;
use App\Models\User;
use App\Support\Allegati\RegoleAllegati;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Documento
 */
class AllegatoResource extends JsonResource
{
    public function __construct(
        Documento $resource,
        private readonly ?User $viewer = null,
        private readonly Segnalazione|Comunicazione|null $elemento = null,
        private readonly string $porta = 'user',
    ) {
        parent::__construct($resource);
    }

    /**
     * @param   iterable<int, Documento>  $allegati
     * @return array<int, array<string, mixed>>
     */
    public static function perScheda(
        iterable $allegati,
        User $viewer,
        Segnalazione|Comunicazione $elemento,
        string $porta = 'user',
    ): array {
        $risultato = [];
        foreach ($allegati as $allegato) {
            $risultato[] = (new self($allegato, $viewer, $elemento, $porta))->toArray(request());
        }

        return $risultato;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Documento $documento */
        $documento = $this->resource;
        $viewer = $this->viewer ?? $request->user();
        $elemento = $this->elemento ?? RegoleAllegati::elementoDellAllegato($documento);
        $porta = in_array($this->porta, ['admin', 'user'], true) ? $this->porta : 'user';

        $creatore = $documento->relationLoaded('createdBy') ? $documento->createdBy : $documento->createdBy()->with('anagrafica')->first();
        $autore = $creatore?->anagrafica?->nome ?: ($creatore?->name ?? '');

        $pubblicato = (bool) $documento->is_published && (bool) $documento->is_approved;

        return [
            'id'             => (int) $documento->id,
            'nome'           => (string) $documento->name,
            'mime_type'      => (string) $documento->mime_type,
            'dimensione'     => (int) $documento->file_size,
            'stato'          => $pubblicato ? 'pubblicato' : 'in_attesa',
            'autore'         => (string) $autore,
            'caricato_il'    => $documento->created_at?->toISOString(),
            'url_apri'       => route("{$porta}.allegati.apri", ['allegato' => $documento->id]),
            'url_scarica'    => route("{$porta}.allegati.scarica", ['allegato' => $documento->id]),
            'puo_modificare' => $viewer && $elemento ? RegoleAllegati::puoModificare($viewer, $documento, $elemento) : false,
            'puo_eliminare'  => $viewer && $elemento ? RegoleAllegati::puoEliminare($viewer, $documento, $elemento) : false,
            'puo_approvare'  => $viewer && $elemento && ! $pubblicato ? RegoleAllegati::puoApprovare($viewer, $documento, $elemento) : false,
        ];
    }
}

