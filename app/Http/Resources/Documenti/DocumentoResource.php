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
            'created_at'    => $this->created_at->diffForHumans(),
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
            'categoria' => new CategoriaDocumentoResource($this->whenLoaded('categoria')),
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
