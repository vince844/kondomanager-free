<?php

namespace App\Http\Resources\Documenti;

use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Documenti\Categorie\CategoriaDocumentoResource;
use App\Http\Resources\User\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class DocumentoResource extends JsonResource
{
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
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'user'       => new UserResource($this->createdBy),
                    'anagrafica' => $this->createdBy->relationLoaded('anagrafica')
                        ? new AnagraficaResource($this->createdBy->anagrafica)
                        : null,
                ];
            }),
            'condomini' => [
                'options' => CondominioOptionsResource::collection($this->whenLoaded('condomini')),
                'full'    => CondominioResource::collection($this->whenLoaded('condomini')),
            ],
            'anagrafiche' => AnagraficaResource::collection($this->whenLoaded('anagrafiche')),
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
