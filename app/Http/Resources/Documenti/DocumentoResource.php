<?php

namespace App\Http\Resources\Documenti;

use App\Http\Resources\Anagrafica\AnagraficaConIndirizzoResource;
use App\Http\Resources\Anagrafica\AnagraficaNomeResource;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Documenti\Categorie\CategoriaDocumentoResource;
use App\Http\Resources\User\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class DocumentoResource extends JsonResource
{
    /** Il nome al posto dell'autore, quando l'utente che ha caricato il documento è stato eliminato. */
    public const AUTORE_ELIMINATO = 'utente eliminato';

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => Str::ucfirst($this->name),
            'description'   => $this->description,
            'is_published'  => $this->is_published,
            'is_approved'   => $this->is_approved,
            'mime_type'     => $this->getMimeTypeLabel($this->mime_type),
            'patyh'          => $this->path,
            'file_size'     => $this->file_size,
            // «tre mesi fa»: serve alle schede, dove si legge come una frase.
            'created_at'    => $this->created_at->diffForHumans(),

            // ⚠️ **La data vera, in aggiunta e non al posto.** Una colonna di tabella intitolata
            // «Data» che dice «tre mesi fa» non si può confrontare né ordinare a occhio, ed è
            // proprio quello che serve quando si cerca il verbale di una certa assemblea. Le due
            // convivono perché rispondono a due domande diverse.
            'created_at_data' => $this->created_at->format('d/m/Y'),
            // `when` e non `whenLoaded`: con la relazione caricata e vuota `whenLoaded` restituisce null, e le pagine leggono `created_by.user`.
            'created_by' => $this->when($this->resource->relationLoaded('createdBy'), function () {
                // L'utente che l'ha caricato è stato eliminato: il documento resta, e chi lo legge vede che l'autore non c'è più. La forma è la
                // stessa (le pagine leggono `created_by.user.name` e `created_by.user.id`).
                if ($this->createdBy === null) {
                    return ['user' => ['id' => null, 'name' => self::AUTORE_ELIMINATO], 'anagrafica' => null];
                }

                return [
                    'user'       => new UserResource($this->createdBy),
                    // Della persona in rubrica dell'autore, id e nome (Coda 408): il resto sta nella sua scheda.
                    'anagrafica' => $this->createdBy->relationLoaded('anagrafica')
                        ? new AnagraficaNomeResource($this->createdBy->anagrafica)
                        : null,
                ];
            }),
            'condomini' => [
                'options' => CondominioOptionsResource::collection($this->whenLoaded('condomini')),
                'full'    => CondominioResource::collection($this->whenLoaded('condomini')),
            ],
            // I destinatari: l'elenco dell'archivio li mostra con il nome e, nel pannello, l'indirizzo; la modifica ne legge
            // id e nome (Coda 408).
            'anagrafiche' => AnagraficaConIndirizzoResource::collection($this->whenLoaded('anagrafiche')),
            // ⚠️ **Plurale dalla 1.11.0-beta.10**, e il nome del campo è cambiato di proposito:
            // un `categoria` che continua a esistere restituendo la prima di N sarebbe un dato
            // giusto a metà, e le schermate non convertite non se ne accorgerebbero.
            'categorie' => CategoriaDocumentoResource::collection($this->whenLoaded('categorie')),
        ];
    }

    private function getMimeTypeLabel(string $mimeType): string
    {
        return match ($mimeType) {
            'application/pdf' => 'PDF',
            'application/msword' => 'DOC',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOCX',
            'image/jpeg' => 'JPEG',
            'image/png' => 'PNG',
            default => $mimeType,
        };
    }
}
