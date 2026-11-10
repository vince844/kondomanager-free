<?php

namespace App\Services\Allegati;

use App\Enums\Permission;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\Documenti\ArchivioDocumenti;
use App\Support\Allegati\RegoleAllegati;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class AllegatoService
{
    public function __construct(
        private readonly ArchivioDocumenti $archivio,
        private readonly AllegatoEventoService $eventoService,
        private readonly AllegatoNotificationService $notificationService,
    ) {}

    /**
     * Carica uno o più file come allegati di una segnalazione o comunicazione.
     *
     * @param  array<int, UploadedFile>  $files
     * @return Collection<int, Documento>
     *
     * @throws ValidationException
     */
    public function carica(
        User $user,
        Segnalazione|Comunicazione $elemento,
        array $files,
        bool $inCreazione = false,
    ): Collection {
        if ($erroreSpazio = RegoleAllegati::erroreSpazio($files)) {
            throw ValidationException::withMessages(['allegati' => $erroreSpazio]);
        }

        $percorsiSalvati = [];

        try {
            $creati = DB::transaction(function () use ($user, $elemento, $files, &$percorsiSalvati): Collection {
                /** @var Segnalazione|Comunicazione $bloccato */
                $bloccato = $elemento::query()->whereKey($elemento->getKey())->lockForUpdate()->firstOrFail();

                $esistenti = $bloccato->allegati()->count();
                if ($errorePosti = RegoleAllegati::errorePostiRimasti($esistenti, count($files))) {
                    throw ValidationException::withMessages(['allegati' => $errorePosti]);
                }

                if ($erroreSpazio = RegoleAllegati::erroreSpazio($files)) {
                    throw ValidationException::withMessages(['allegati' => $erroreSpazio]);
                }

                $approvato = RegoleAllegati::approvatoAllaNascita($user, $bloccato);
                $cartella = 'documenti/allegati/' . RegoleAllegati::tipo($bloccato) . '/' . $bloccato->getKey();
                $creati = collect();

                foreach ($files as $file) {
                    $path = $this->archivio->salvaConLEstensioneDelNome($file, $cartella);
                    $percorsiSalvati[] = $path;

                    $creati->push($bloccato->allegati()->create([
                        'name'         => RegoleAllegati::accorciaNomeFile($file->getClientOriginalName()),
                        'path'         => $path,
                        'mime_type'    => (string) $file->getMimeType(),
                        'file_size'    => (int) $file->getSize(),
                        'created_by'   => $user->id,
                        'is_published' => true,
                        'is_approved'  => $approvato,
                    ]));
                }

                return $creati;
            });
        } catch (\Throwable $e) {
            foreach ($percorsiSalvati as $percorso) {
                $this->archivio->elimina($percorso);
            }

            throw $e;
        }

        if ($creati->contains(fn (Documento $d) => ! $d->is_approved)) {
            $this->eventoService->sincronizza($elemento, $user);
        }

        $this->notificationService->dopoCaricamento($user, $elemento, $creati, $inCreazione);

        return $creati;
    }

    public function approva(User $moderatore, Documento $allegato, Segnalazione|Comunicazione $elemento): void
    {
        if ((bool) $allegato->is_published && (bool) $allegato->is_approved) {
            return;
        }

        DB::transaction(function () use ($moderatore, $allegato) {
            $allegato->forceFill([
                'is_published' => true,
                'is_approved'  => true,
                'moderato_da'  => $moderatore->id,
                'moderato_at'  => now(),
            ])->save();
        });

        $this->eventoService->sincronizza($elemento, $moderatore);
        $this->notificationService->dopoApprovazione($moderatore, $elemento, collect([$allegato]));
    }

    public function rinomina(User $user, Documento $allegato, string $nome): void
    {
        $allegato->forceFill([
            'name'       => $nome,
            'updated_by' => $user->id,
        ])->save();
    }

    public function elimina(User $user, Documento $allegato, Segnalazione|Comunicazione $elemento): void
    {
        $percorso = $allegato->path;

        DB::transaction(function () use ($allegato) {
            $allegato->delete();
        });

        $this->archivio->elimina($percorso);
        $this->eventoService->sincronizza($elemento, $user);
        $this->notificationService->dopoEliminazione($user, $elemento, $allegato);
    }

    /** @var array<string, array{allegati_ids: array<int, int>, percorsi: array<int, string>}> */
    private static array $inPuliziaPerElemento = [];

    /** @var array<string, array{segnalazioni: Collection<int, Segnalazione>, allegati_ids: array<int, int>, percorsi: array<int, string>}> */
    private static array $inPuliziaPerCondominio = [];

    /** @var array<string, array{segnalazioni: Collection<int, Segnalazione>, comunicazioni: Collection<int, Comunicazione>, allegati_ids: array<int, int>, percorsi: array<int, string>, elementi_toccati: array<string, Segnalazione|Comunicazione>}> */
    private static array $inPuliziaPerUtente = [];

    public function preparaPuliziaPerElemento(Segnalazione|Comunicazione $elemento): void
    {
        $key = $elemento::class . ':' . $elemento->getKey();
        $allegati = $elemento->allegati()->get(['id', 'path']);

        self::$inPuliziaPerElemento[$key] = [
            'allegati_ids' => $allegati->pluck('id')->all(),
            'percorsi'     => $allegati->pluck('path')->filter()->values()->all(),
        ];
    }

    public function completaPuliziaPerElemento(Segnalazione|Comunicazione $elemento): void
    {
        $key = $elemento::class . ':' . $elemento->getKey();
        if (! isset(self::$inPuliziaPerElemento[$key])) {
            $this->preparaPuliziaPerElemento($elemento);
        }

        $dati = self::$inPuliziaPerElemento[$key];
        unset(self::$inPuliziaPerElemento[$key]);

        if (! empty($dati['allegati_ids'])) {
            Documento::query()->whereIn('id', $dati['allegati_ids'])->delete();
        }

        $this->eventoService->chiudiPerElemento($elemento);

        if (! empty($dati['percorsi'])) {
            $percorsi = $dati['percorsi'];
            DB::afterCommit(function () use ($percorsi) {
                foreach ($percorsi as $percorso) {
                    $this->archivio->elimina($percorso);
                }
            });
        }
    }

    public function pulisciPerElemento(Segnalazione|Comunicazione $elemento): void
    {
        $this->preparaPuliziaPerElemento($elemento);
        $this->completaPuliziaPerElemento($elemento);
    }

    public function preparaPuliziaPerUtente(User $user): void
    {
        $key = 'user:' . $user->getKey();

        $segnalazioni = Segnalazione::query()->where('created_by', $user->id)->get();
        $comunicazioni = Comunicazione::query()->where('created_by', $user->id)->get();

        $allegatiIds = [];
        $percorsi = [];

        foreach ($segnalazioni as $segnalazione) {
            foreach ($segnalazione->allegati()->get(['id', 'path']) as $allegato) {
                $allegatiIds[] = $allegato->id;
                if ($allegato->path) {
                    $percorsi[] = $allegato->path;
                }
            }
        }

        foreach ($comunicazioni as $comunicazione) {
            foreach ($comunicazione->allegati()->get(['id', 'path']) as $allegato) {
                $allegatiIds[] = $allegato->id;
                if ($allegato->path) {
                    $percorsi[] = $allegato->path;
                }
            }
        }

        $allegatiSuAltriElementi = Documento::query()
            ->where('created_by', $user->id)
            ->whereIn('documentable_type', [
                (new Segnalazione)->getMorphClass(),
                (new Comunicazione)->getMorphClass(),
            ])
            ->get();

        $elementiToccati = [];
        foreach ($allegatiSuAltriElementi as $allegato) {
            $allegatiIds[] = $allegato->id;
            if ($allegato->path) {
                $percorsi[] = $allegato->path;
            }

            $elemento = RegoleAllegati::elementoDellAllegato($allegato);
            if ($elemento !== null && $elemento->created_by !== $user->id) {
                $elementiToccati[$elemento::class . ':' . $elemento->getKey()] = $elemento;
            }
        }

        self::$inPuliziaPerUtente[$key] = [
            'segnalazioni'     => $segnalazioni,
            'comunicazioni'    => $comunicazioni,
            'allegati_ids'     => array_values(array_unique($allegatiIds)),
            'percorsi'         => array_values(array_unique($percorsi)),
            'elementi_toccati' => $elementiToccati,
        ];
    }

    public function completaPuliziaPerUtente(User $user): void
    {
        $key = 'user:' . $user->getKey();
        if (! isset(self::$inPuliziaPerUtente[$key])) {
            $this->preparaPuliziaPerUtente($user);
        }

        $dati = self::$inPuliziaPerUtente[$key];
        unset(self::$inPuliziaPerUtente[$key]);

        if (! empty($dati['allegati_ids'])) {
            Documento::query()->whereIn('id', $dati['allegati_ids'])->delete();
        }

        foreach ($dati['segnalazioni'] as $segnalazione) {
            $this->eventoService->chiudiPerElemento($segnalazione);
        }

        foreach ($dati['comunicazioni'] as $comunicazione) {
            $this->eventoService->chiudiPerElemento($comunicazione);
        }

        foreach ($dati['elementi_toccati'] as $elemento) {
            $this->eventoService->sincronizza($elemento);
        }

        if (! empty($dati['percorsi'])) {
            $percorsi = $dati['percorsi'];
            DB::afterCommit(function () use ($percorsi) {
                foreach ($percorsi as $percorso) {
                    $this->archivio->elimina($percorso);
                }
            });
        }
    }

    public function pulisciPerUtente(User $user): void
    {
        $this->preparaPuliziaPerUtente($user);
        $this->completaPuliziaPerUtente($user);
    }

    public function preparaPuliziaPerCondominio(Condominio $condominio): void
    {
        $key = 'condominio:' . $condominio->getKey();

        $segnalazioni = Segnalazione::query()->where('condominio_id', $condominio->id)->get();
        $allegatiIds = [];
        $percorsi = [];

        foreach ($segnalazioni as $segnalazione) {
            foreach ($segnalazione->allegati()->get(['id', 'path']) as $allegato) {
                $allegatiIds[] = $allegato->id;
                if ($allegato->path) {
                    $percorsi[] = $allegato->path;
                }
            }
        }

        self::$inPuliziaPerCondominio[$key] = [
            'segnalazioni' => $segnalazioni,
            'allegati_ids' => $allegatiIds,
            'percorsi'     => array_values(array_unique($percorsi)),
        ];
    }

    public function completaPuliziaPerCondominio(Condominio $condominio): void
    {
        $key = 'condominio:' . $condominio->getKey();
        if (! isset(self::$inPuliziaPerCondominio[$key])) {
            $this->preparaPuliziaPerCondominio($condominio);
        }

        $dati = self::$inPuliziaPerCondominio[$key];
        unset(self::$inPuliziaPerCondominio[$key]);

        if (! empty($dati['allegati_ids'])) {
            Documento::query()->whereIn('id', $dati['allegati_ids'])->delete();
        }

        foreach ($dati['segnalazioni'] as $segnalazione) {
            $this->eventoService->chiudiPerElemento($segnalazione);
        }

        if (! empty($dati['percorsi'])) {
            $percorsi = $dati['percorsi'];
            DB::afterCommit(function () use ($percorsi) {
                foreach ($percorsi as $percorso) {
                    $this->archivio->elimina($percorso);
                }
            });
        }
    }

    public function pulisciPerCondominio(Condominio $condominio): void
    {
        $this->preparaPuliziaPerCondominio($condominio);
        $this->completaPuliziaPerCondominio($condominio);
    }

    public function aggiornaImpostazioni(
        Segnalazione|Comunicazione $elemento,
        string $accesso,
        bool $moderati,
    ): void {
        $elemento->update([
            'allegati_accesso'  => $accesso,
            'allegati_moderati' => $moderati,
        ]);
    }

    /**
     * @param  array<int, int>  $ids
     */
    public function approvaInBlocco(User $moderatore, array $ids): void
    {
        $elementiEAllegati = $this->risolviBlocco($ids, fn (Documento $d, Segnalazione|Comunicazione $el) => RegoleAllegati::puoApprovare($moderatore, $d, $el));
        $daApprovare = array_values(array_filter(
            $elementiEAllegati,
            fn (array $v) => ! ((bool) $v['allegato']->is_published && (bool) $v['allegato']->is_approved),
        ));

        if ($daApprovare === []) {
            return;
        }

        DB::transaction(function () use ($moderatore, $daApprovare) {
            foreach ($daApprovare as ['allegato' => $allegato]) {
                $allegato->forceFill([
                    'is_published' => true,
                    'is_approved'  => true,
                    'moderato_da'  => $moderatore->id,
                    'moderato_at'  => now(),
                ])->save();
            }
        });

        $perElemento = collect($daApprovare)->groupBy(fn (array $v) => $v['elemento']::class . ':' . $v['elemento']->getKey());
        foreach ($perElemento as $gruppo) {
            /** @var Segnalazione|Comunicazione $elemento */
            $elemento = $gruppo->first()['elemento'];
            $approvati = $gruppo->pluck('allegato');
            $this->eventoService->sincronizza($elemento, $moderatore);
            $this->notificationService->dopoApprovazione($moderatore, $elemento, $approvati);
        }
    }

    /**
     * @param  array<int, int>  $ids
     */
    public function eliminaInBlocco(User $user, array $ids): void
    {
        $elementiEAllegati = $this->risolviBlocco($ids, fn (Documento $d, Segnalazione|Comunicazione $el) => RegoleAllegati::puoEliminare($user, $d, $el));

        foreach ($elementiEAllegati as ['allegato' => $allegato, 'elemento' => $elemento]) {
            $this->elimina($user, $allegato, $elemento);
        }
    }

    /**
     * @param  array<string, mixed>  $filtri
     */
    public function queryPerPagina(User $user, array $filtri = []): Builder
    {
        $apreTutteSegnalazioni = RegoleAllegati::haPermesso($user, Permission::VIEW_SEGNALAZIONI->value);
        $apreProprieSegnalazioni = RegoleAllegati::haPermesso($user, Permission::VIEW_OWN_SEGNALAZIONI->value);

        $vedeSegnalazioni = RegoleAllegati::haPermesso($user, Permission::VIEW_ATTACHMENTS_SEGNALAZIONI->value)
            && ($apreTutteSegnalazioni || $apreProprieSegnalazioni);
        $vedeComunicazioni = RegoleAllegati::haPermesso($user, Permission::VIEW_ATTACHMENTS_COMUNICAZIONI->value)
            && RegoleAllegati::haPannello($user);

        $approvaSegnalazioni = RegoleAllegati::haPermesso($user, Permission::APPROVE_ATTACHMENTS_SEGNALAZIONI->value);
        $approvaComunicazioni = RegoleAllegati::haPermesso($user, Permission::APPROVE_ATTACHMENTS_COMUNICAZIONI->value);

        $morphSegnalazione = (new Segnalazione)->getMorphClass();
        $morphComunicazione = (new Comunicazione)->getMorphClass();

        $query = Documento::query()
            ->with(['documentable', 'createdBy.anagrafica'])
            ->whereHasMorph('documentable', [Segnalazione::class, Comunicazione::class])
            ->where(function (Builder $visibilita) use (
                $user,
                $vedeSegnalazioni,
                $vedeComunicazioni,
                $approvaSegnalazioni,
                $approvaComunicazioni,
                $apreTutteSegnalazioni,
                $morphSegnalazione,
                $morphComunicazione,
            ) {
                if (! $vedeSegnalazioni && ! $vedeComunicazioni) {
                    $visibilita->whereRaw('1 = 0');

                    return;
                }

                if ($vedeSegnalazioni) {
                    $visibilita->orWhere(function (Builder $q) use ($user, $approvaSegnalazioni, $apreTutteSegnalazioni, $morphSegnalazione) {
                        $q->where('documentable_type', $morphSegnalazione);
                        if (! $apreTutteSegnalazioni) {
                            $q->whereHasMorph('documentable', [Segnalazione::class], fn (Builder $s) => $s->where('created_by', $user->id));
                        }
                        if (! $approvaSegnalazioni) {
                            $q->where(fn (Builder $stato) => $stato
                                ->where(fn (Builder $pub) => $pub->where('is_published', true)->where('is_approved', true))
                                ->orWhere('created_by', $user->id));
                        }
                    });
                }

                if ($vedeComunicazioni) {
                    $visibilita->orWhere(function (Builder $q) use ($user, $approvaComunicazioni, $morphComunicazione) {
                        $q->where('documentable_type', $morphComunicazione);
                        if (! $approvaComunicazioni) {
                            $q->where(fn (Builder $stato) => $stato
                                ->where(fn (Builder $pub) => $pub->where('is_published', true)->where('is_approved', true))
                                ->orWhere('created_by', $user->id));
                        }
                    });
                }
            });

        if (! empty($filtri['stato'])) {
            if ($filtri['stato'] === 'in_attesa') {
                $query->where(fn (Builder $q) => $q->where('is_approved', false)->orWhere('is_published', false));
            } elseif ($filtri['stato'] === 'pubblicato') {
                $query->where('is_published', true)->where('is_approved', true);
            }
        }

        if (! empty($filtri['tipo'])) {
            if ($filtri['tipo'] === 'segnalazioni') {
                $query->where('documentable_type', $morphSegnalazione);
            } elseif ($filtri['tipo'] === 'comunicazioni') {
                $query->where('documentable_type', $morphComunicazione);
            }
        }

        if (! empty($filtri['condominio_id'])) {
            $condominioId = (int) $filtri['condominio_id'];
            $query->whereHasMorph('documentable', [Segnalazione::class, Comunicazione::class], function (Builder $q, string $type) use ($condominioId) {
                if ($type === Segnalazione::class) {
                    $q->where('condominio_id', $condominioId);
                } else {
                    $q->whereHas('condomini', fn (Builder $c) => $c->where('condomini.id', $condominioId));
                }
            });
        }

        if (! empty($filtri['autore']) && is_string($filtri['autore'])) {
            $autore = trim($filtri['autore']);
            $query->whereHas('createdBy', function (Builder $u) use ($autore) {
                $u->where(function (Builder $q) use ($autore) {
                    $q->where('name', 'like', "%{$autore}%")
                        ->orWhereHas('anagrafica', function (Builder $a) use ($autore) {
                            $a->where('nome', 'like', "%{$autore}%");
                        });
                });
            });
        }

        if (! empty($filtri['dal']) && is_string($filtri['dal']) && strtotime($filtri['dal']) !== false) {
            $query->whereDate('created_at', '>=', $filtri['dal']);
        }

        if (! empty($filtri['al']) && is_string($filtri['al']) && strtotime($filtri['al']) !== false) {
            $query->whereDate('created_at', '<=', $filtri['al']);
        }

        return $query;
    }

    public function rigaPagina(User $user, Documento $allegato): array
    {
        /** @var Segnalazione|Comunicazione $elemento */
        $elemento = $allegato->documentable;
        $tipo = RegoleAllegati::tipo($elemento);
        $pubblicato = (bool) $allegato->is_published && (bool) $allegato->is_approved;

        $condomini = $elemento instanceof Segnalazione
            ? array_values(array_filter([$elemento->condominio?->nome]))
            : $elemento->condomini->pluck('nome')->values()->all();

        return [
            'id'            => (int) $allegato->id,
            'nome'          => (string) $allegato->name,
            'tipo'          => $tipo,
            'elemento'      => (string) $elemento->subject,
            'url_elemento'  => $elemento instanceof Segnalazione
                ? route('admin.segnalazioni.show', $elemento)
                : route('admin.comunicazioni.show', $elemento),
            'condomini'     => $condomini,
            'autore'        => RegoleAllegati::nomeAutore($allegato),
            'caricato_il'   => $allegato->created_at?->toISOString(),
            'stato'         => $pubblicato ? 'pubblicato' : 'in_attesa',
            'dimensione'    => (int) $allegato->file_size,
            'url_apri'      => route('admin.allegati.apri', ['allegato' => $allegato->id]),
            'url_scarica'   => route('admin.allegati.scarica', ['allegato' => $allegato->id]),
            'puo_approvare' => ! $pubblicato && RegoleAllegati::puoApprovare($user, $allegato, $elemento),
            'puo_eliminare' => RegoleAllegati::puoEliminare($user, $allegato, $elemento),
        ];
    }

    /**
     * @param  array<string, mixed>  $filtri
     */
    public function creaZipTemporaneo(User $user, array $filtri = []): string
    {
        $allegati = $this->queryPerPagina($user, $filtri)
            ->orderBy('documentable_id')
            ->orderBy('id')
            ->get();

        $tmp = tempnam(sys_get_temp_dir(), 'allegati_zip_');
        if ($tmp === false) {
            throw new \RuntimeException('Impossibile creare il file temporaneo per lo ZIP.');
        }

        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Impossibile aprire il file ZIP temporaneo.');
        }

        $righeCsv = [
            ['Condominio', 'Tipo', 'Elemento', 'File', 'Autore', 'Caricato il', 'Stato', 'Percorso nello ZIP'],
        ];
        $percorsiUsati = [];
        $cartelleAssegnate = [];
        $cartelleUsate = [];
        $fileTemporanei = [];
        $filtroCondominioId = ! empty($filtri['condominio_id']) ? (int) $filtri['condominio_id'] : null;

        try {
            foreach ($allegati as $allegato) {
                $elemento = RegoleAllegati::elementoDellAllegato($allegato);
                if ($elemento === null || ! RegoleAllegati::puoVedere($user, $allegato, $elemento)) {
                    continue;
                }

                if ($elemento instanceof Segnalazione) {
                    $condomini = $elemento->condominio ? collect([$elemento->condominio]) : collect();
                } else {
                    $condomini = $elemento->condomini()->orderBy('condomini.nome')->get();
                    if ($filtroCondominioId !== null) {
                        $condomini = $condomini->where('id', $filtroCondominioId)->values();
                    }
                }

                $etichettaTipo = $elemento instanceof Segnalazione ? 'Segnalazioni' : 'Comunicazioni';
                $dataCreazione = ($elemento->created_at ?? now())->format('Y-m-d');
                $oggettoCartella = RegoleAllegati::nomePerFileSystem((string) $elemento->subject, 'Elemento');
                $cartellaBase = "{$dataCreazione} {$oggettoCartella}";
                $chiaveElemento = $elemento::class . ':' . $elemento->getKey();
                $nomeBaseFile = $allegato->nomeDiScaricamento();
                $autore = RegoleAllegati::nomeAutore($allegato);
                $caricatoIl = $allegato->created_at ? $allegato->created_at->format('Y-m-d H:i:s') : '';
                $esisteSulDisco = $this->archivio->esiste($allegato->path);
                $statoCsv = ! $esisteSulDisco
                    ? 'file mancante'
                    : (($allegato->is_published && $allegato->is_approved) ? 'pubblicato' : 'in attesa');

                $tmpFile = null;
                if ($esisteSulDisco) {
                    $tmpFile = $this->copiaInTemporaneoLocale($allegato->path, (int) $allegato->file_size);
                    if ($tmpFile !== null) {
                        $fileTemporanei[] = $tmpFile;
                    } else {
                        $statoCsv = 'file mancante';
                    }
                }

                foreach ($condomini as $condominio) {
                    $nomeCondominio = RegoleAllegati::nomePerFileSystem((string) $condominio->nome, 'Condominio');
                    $prefisso = "{$nomeCondominio}/{$etichettaTipo}";
                    $cartella = $this->cartellaPerElemento(
                        $prefisso,
                        $cartellaBase,
                        $chiaveElemento,
                        $cartelleAssegnate,
                        $cartelleUsate,
                    );

                    if ($tmpFile === null) {
                        $righeCsv[] = [
                            (string) $condominio->nome,
                            $etichettaTipo,
                            (string) $elemento->subject,
                            $nomeBaseFile,
                            $autore,
                            $caricatoIl,
                            'file mancante',
                            '',
                        ];

                        continue;
                    }

                    $nomeFileZip = $this->nomeUnivocoPerCartella($cartella, $nomeBaseFile, $percorsiUsati);
                    $percorsoZip = "{$cartella}/{$nomeFileZip}";
                    $percorsiUsati[$percorsoZip] = true;

                    $aggiunto = $zip->addFile($tmpFile, $percorsoZip);
                    if (! $aggiunto) {
                        $righeCsv[] = [
                            (string) $condominio->nome,
                            $etichettaTipo,
                            (string) $elemento->subject,
                            $nomeFileZip,
                            $autore,
                            $caricatoIl,
                            'file mancante',
                            '',
                        ];

                        continue;
                    }

                    $righeCsv[] = [
                        (string) $condominio->nome,
                        $etichettaTipo,
                        (string) $elemento->subject,
                        $nomeFileZip,
                        $autore,
                        $caricatoIl,
                        $statoCsv,
                        $percorsoZip,
                    ];
                }
            }

            $fp = fopen('php://temp', 'r+');
            if ($fp === false) {
                throw new \RuntimeException('Impossibile preparare elenco.csv.');
            }
            fwrite($fp, "\xEF\xBB\xBF");
            foreach ($righeCsv as $indiceRiga => $riga) {
                $celle = $indiceRiga === 0
                    ? $riga
                    : array_map(fn ($v) => $this->cellaCsvSicura((string) $v), $riga);
                fputcsv($fp, $celle, ';', '"', '');
            }
            rewind($fp);
            $csvContenuto = (string) stream_get_contents($fp);
            fclose($fp);

            $zip->addFromString('elenco.csv', $csvContenuto);
            if ($zip->close() !== true) {
                @unlink($tmp);
                throw new \RuntimeException('Impossibile finalizzare il file ZIP temporaneo.');
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        } finally {
            foreach ($fileTemporanei as $fileTmp) {
                @unlink($fileTmp);
            }
        }

        return $tmp;
    }

    private function copiaInTemporaneoLocale(string $path, ?int $dimensioneAttesa = null): ?string
    {
        $sorgente = $this->archivio->disco()->readStream($path);
        if (! is_resource($sorgente)) {
            return null;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'allegato_src_');
        if ($tmpFile === false) {
            fclose($sorgente);

            return null;
        }

        $destinazione = fopen($tmpFile, 'wb');
        if ($destinazione === false) {
            fclose($sorgente);
            @unlink($tmpFile);

            return null;
        }

        $copiati = stream_copy_to_stream($sorgente, $destinazione);
        fclose($destinazione);
        fclose($sorgente);

        if ($copiati === false) {
            @unlink($tmpFile);

            return null;
        }

        if ($dimensioneAttesa !== null && $dimensioneAttesa > 0 && $copiati < $dimensioneAttesa) {
            @unlink($tmpFile);

            return null;
        }

        return $tmpFile;
    }

    private function cellaCsvSicura(string $valore): string
    {
        return preg_match('/^[=+\-@\t\r]/', $valore) === 1 ? "'" . $valore : $valore;
    }

    /**
     * @param  array<string, string>  $cartelleAssegnate
     * @param  array<string, bool>  $cartelleUsate
     */
    private function cartellaPerElemento(
        string $prefisso,
        string $cartellaBase,
        string $chiaveElemento,
        array &$cartelleAssegnate,
        array &$cartelleUsate,
    ): string {
        $chiave = "{$prefisso}|{$chiaveElemento}";
        if (isset($cartelleAssegnate[$chiave])) {
            return $cartelleAssegnate[$chiave];
        }

        $candidata = "{$prefisso}/{$cartellaBase}";
        $indice = 2;
        while (isset($cartelleUsate[$candidata])) {
            $candidata = "{$prefisso}/{$cartellaBase} ({$indice})";
            $indice++;
        }

        $cartelleUsate[$candidata] = true;
        $cartelleAssegnate[$chiave] = $candidata;

        return $candidata;
    }

    /**
     * @param  array<int, int>  $ids
     * @param  callable(Documento, Segnalazione|Comunicazione): bool  $controlloPermesso
     * @return array<int, array{allegato: Documento, elemento: Segnalazione|Comunicazione}>
     */
    private function risolviBlocco(array $ids, callable $controlloPermesso): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        abort_if($ids === [], 422);

        $documenti = Documento::query()->whereIn('id', $ids)->get()->keyBy('id');
        abort_if($documenti->count() !== count($ids), 404);

        $risultati = [];
        foreach ($ids as $id) {
            /** @var Documento|null $allegato */
            $allegato = $documenti->get($id);
            $elemento = RegoleAllegati::elementoDellAllegato($allegato);
            abort_if($allegato === null || $elemento === null, 404);
            abort_unless($controlloPermesso($allegato, $elemento), 403);

            $risultati[] = [
                'allegato' => $allegato,
                'elemento' => $elemento,
            ];
        }

        return $risultati;
    }

    /**
     * @param  array<string, bool>  $percorsiUsati
     */
    private function nomeUnivocoPerCartella(string $cartella, string $nomeBase, array $percorsiUsati): string
    {
        if (! isset($percorsiUsati["{$cartella}/{$nomeBase}"])) {
            return $nomeBase;
        }

        $estensione = pathinfo($nomeBase, PATHINFO_EXTENSION);
        $senzaEstensione = $estensione !== ''
            ? substr($nomeBase, 0, -strlen($estensione) - 1)
            : $nomeBase;

        $indice = 2;
        while (true) {
            $candidato = $estensione !== ''
                ? "{$senzaEstensione} ({$indice}).{$estensione}"
                : "{$senzaEstensione} ({$indice})";

            if (! isset($percorsiUsati["{$cartella}/{$candidato}"])) {
                return $candidato;
            }

            $indice++;
        }
    }
}
