<?php

namespace App\Support\Allegati;

use App\Enums\Permission;
use App\Models\Comunicazione;
use App\Models\Documento;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\Documenti\SpazioDocumenti;
use App\Support\FileComeIlNome;
use App\Support\LimiteCaricamento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

final class RegoleAllegati
{
    public const MASSIMO_PER_ELEMENTO = 5;

    public const FORMATI = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    public const TIPI_INLINE = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public const ACCESSI = ['aperti', 'consultazione', 'riservati'];

    private const PERMESSI = [
        'segnalazioni' => [
            'carica'          => Permission::CREATE_ATTACHMENTS_SEGNALAZIONI->value,
            'pubblica'        => Permission::PUBLISH_ATTACHMENTS_SEGNALAZIONI->value,
            'approva'         => Permission::APPROVE_ATTACHMENTS_SEGNALAZIONI->value,
            'modifica'        => Permission::EDIT_ATTACHMENTS_SEGNALAZIONI->value,
            'modifica_propri' => Permission::EDIT_OWN_ATTACHMENTS_SEGNALAZIONI->value,
            'elimina'         => Permission::DELETE_ATTACHMENTS_SEGNALAZIONI->value,
            'elimina_propri'  => Permission::DELETE_OWN_ATTACHMENTS_SEGNALAZIONI->value,
            'visualizza'      => Permission::VIEW_ATTACHMENTS_SEGNALAZIONI->value,
        ],
        'comunicazioni' => [
            'carica'          => Permission::CREATE_ATTACHMENTS_COMUNICAZIONI->value,
            'pubblica'        => Permission::PUBLISH_ATTACHMENTS_COMUNICAZIONI->value,
            'approva'         => Permission::APPROVE_ATTACHMENTS_COMUNICAZIONI->value,
            'modifica'        => Permission::EDIT_ATTACHMENTS_COMUNICAZIONI->value,
            'modifica_propri' => Permission::EDIT_OWN_ATTACHMENTS_COMUNICAZIONI->value,
            'elimina'         => Permission::DELETE_ATTACHMENTS_COMUNICAZIONI->value,
            'elimina_propri'  => Permission::DELETE_OWN_ATTACHMENTS_COMUNICAZIONI->value,
            'visualizza'      => Permission::VIEW_ATTACHMENTS_COMUNICAZIONI->value,
        ],
    ];

    public static function haPermesso(User $user, string $permesso): bool
    {
        try {
            return $user->hasPermissionTo($permesso);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    public static function haPannello(User $user): bool
    {
        return self::haPermesso($user, Permission::ACCESS_ADMIN_PANEL->value);
    }

    public static function tipo(Model|string $elementoOTipo): string
    {
        if (is_string($elementoOTipo)) {
            if (in_array($elementoOTipo, ['segnalazioni', 'comunicazioni'], true)) {
                return $elementoOTipo;
            }

            return $elementoOTipo === Segnalazione::class ? 'segnalazioni' : 'comunicazioni';
        }

        return $elementoOTipo instanceof Segnalazione ? 'segnalazioni' : 'comunicazioni';
    }

    public static function permesso(Model|string $elementoOTipo, string $azione): string
    {
        return self::PERMESSI[self::tipo($elementoOTipo)][$azione];
    }

    /**
     * Restituisce l'elemento (Segnalazione o Comunicazione) a cui appartiene l'allegato,
     * oppure null se il documento non è un allegato valido o il suo elemento non esiste più.
     */
    public static function elementoDellAllegato(?Documento $allegato): Segnalazione|Comunicazione|null
    {
        if ($allegato === null) {
            return null;
        }

        if (! in_array($allegato->documentable_type, [Segnalazione::class, Comunicazione::class], true)
            || ! $allegato->documentable_id) {
            return null;
        }

        $elemento = $allegato->documentable;

        return ($elemento instanceof Segnalazione || $elemento instanceof Comunicazione) ? $elemento : null;
    }

    public static function apreSchedaPannello(User $user, Segnalazione|Comunicazione $elemento): bool
    {
        $ability = $elemento instanceof Segnalazione ? 'view' : 'show';

        return Gate::forUser($user)->allows($ability, $elemento);
    }

    public static function apreSchedaPortale(User $user, Segnalazione|Comunicazione $elemento): bool
    {
        return Gate::forUser($user)->allows('show', $elemento);
    }

    public static function puoVedere(User $user, Documento $allegato, Segnalazione|Comunicazione|null $elemento = null): bool
    {
        $elemento ??= self::elementoDellAllegato($allegato);
        if ($elemento === null) {
            return false;
        }

        if (! self::haPermesso($user, self::permesso($elemento, 'visualizza'))) {
            return false;
        }

        $pubblicato = (bool) $allegato->is_published && (bool) $allegato->is_approved;
        $eSuo = $allegato->created_by !== null && (int) $allegato->created_by === (int) $user->id;

        if (self::haPannello($user)) {
            if (! self::apreSchedaPannello($user, $elemento)) {
                return false;
            }

            if ($pubblicato || $eSuo) {
                return true;
            }

            return self::haPermesso($user, self::permesso($elemento, 'approva'));
        }

        if (! self::apreSchedaPortale($user, $elemento)) {
            return false;
        }

        if (($elemento->allegati_accesso ?? 'aperti') === 'riservati') {
            return false;
        }

        return $pubblicato || $eSuo;
    }

    public static function puoCaricare(User $user, Segnalazione|Comunicazione $elemento): bool
    {
        if (! self::haPermesso($user, self::permesso($elemento, 'carica'))) {
            return false;
        }

        if ($elemento instanceof Segnalazione) {
            if (self::haPannello($user)) {
                return self::apreSchedaPannello($user, $elemento);
            }

            return ($elemento->allegati_accesso ?? 'aperti') === 'aperti'
                && self::appartieneAlCondominio($user, $elemento->condominio_id)
                && self::apreSchedaPortale($user, $elemento);
        }

        if (self::haPannello($user)) {
            return Gate::forUser($user)->allows('update', $elemento);
        }

        return ($elemento->allegati_accesso ?? 'aperti') === 'aperti'
            && $elemento->created_by !== null
            && (int) $elemento->created_by === (int) $user->id
            && self::apreSchedaPortale($user, $elemento);
    }

    public static function approvatoAllaNascita(User $user, Segnalazione|Comunicazione $elemento): bool
    {
        if (self::haPannello($user) && self::haPermesso($user, self::permesso($elemento, 'approva'))) {
            return true;
        }

        return self::haPermesso($user, self::permesso($elemento, 'pubblica'))
            && ! (bool) ($elemento->allegati_moderati ?? true);
    }

    public static function puoApprovare(User $user, Documento $allegato, Segnalazione|Comunicazione|null $elemento = null): bool
    {
        if (! self::haPannello($user)) {
            return false;
        }

        $elemento ??= self::elementoDellAllegato($allegato);
        if ($elemento === null) {
            return false;
        }

        return self::haPermesso($user, self::permesso($elemento, 'approva'))
            && self::puoVedere($user, $allegato, $elemento);
    }

    public static function puoModificare(User $user, Documento $allegato, Segnalazione|Comunicazione|null $elemento = null): bool
    {
        $elemento ??= self::elementoDellAllegato($allegato);
        if ($elemento === null || ! self::puoVedere($user, $allegato, $elemento)) {
            return false;
        }

        if (self::haPannello($user) && self::haPermesso($user, self::permesso($elemento, 'modifica'))) {
            return true;
        }

        return $allegato->created_by !== null
            && (int) $allegato->created_by === (int) $user->id
            && self::haPermesso($user, self::permesso($elemento, 'modifica_propri'));
    }

    public static function puoEliminare(User $user, Documento $allegato, Segnalazione|Comunicazione|null $elemento = null): bool
    {
        $elemento ??= self::elementoDellAllegato($allegato);
        if ($elemento === null || ! self::puoVedere($user, $allegato, $elemento)) {
            return false;
        }

        if (self::haPannello($user) && self::haPermesso($user, self::permesso($elemento, 'elimina'))) {
            return true;
        }

        return $allegato->created_by !== null
            && (int) $allegato->created_by === (int) $user->id
            && self::haPermesso($user, self::permesso($elemento, 'elimina_propri'));
    }

    public static function puoImpostare(User $user, Segnalazione|Comunicazione $elemento): bool
    {
        return self::haPannello($user)
            && self::haPermesso($user, self::permesso($elemento, 'approva'))
            && self::apreSchedaPannello($user, $elemento);
    }

    public static function puoAprirePagina(User $user): bool
    {
        return self::haPannello($user)
            && (self::haPermesso($user, Permission::VIEW_ATTACHMENTS_SEGNALAZIONI->value)
                || self::haPermesso($user, Permission::VIEW_ATTACHMENTS_COMUNICAZIONI->value));
    }

    /**
     * Gli allegati dell'elemento visibili all'utente, in ordine di caricamento.
     *
     * @return Collection<int, Documento>
     */
    public static function visibiliInScheda(User $user, Segnalazione|Comunicazione $elemento): Collection
    {
        return $elemento->allegati()
            ->with('createdBy.anagrafica')
            ->orderBy('id')
            ->get()
            ->filter(fn (Documento $allegato) => self::puoVedere($user, $allegato, $elemento))
            ->values();
    }

    /**
     * Configurazione degli allegati da passare alla scheda Inertia (`allegati_config`).
     *
     * @return array<string, mixed>
     */
    public static function configScheda(User $user, Segnalazione|Comunicazione $elemento): array
    {
        $totale = $elemento->allegati()->count();
        $massimo = self::massimoPerElemento();

        return [
            'accesso'       => (string) ($elemento->allegati_accesso ?? 'aperti'),
            'moderati'      => (bool) ($elemento->allegati_moderati ?? true),
            'puo_caricare'  => self::puoCaricare($user, $elemento),
            'puo_impostare' => self::puoImpostare($user, $elemento),
            'massimo'       => $massimo,
            'rimasti'       => max(0, $massimo - $totale),
            'limite_file'   => LimiteCaricamento::etichetta(),
            'formati'       => self::FORMATI,
        ];
    }

    public static function massimoPerElemento(): int
    {
        try {
            $valore = (int) app(\App\Settings\GeneralSettings::class)->allegati_massimo_per_elemento;

            return $valore >= 1 ? $valore : self::MASSIMO_PER_ELEMENTO;
        } catch (\Throwable) {
            return self::MASSIMO_PER_ELEMENTO;
        }
    }

    /**
     * Regole di validazione per `allegati` e `allegati.*`.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function regoleValidazioneFile(bool $obbligatorio = true): array
    {
        $regoleArray = [
            $obbligatorio ? 'required' : 'nullable',
            'array',
        ];
        if ($obbligatorio) {
            $regoleArray[] = 'min:1';
        }
        $regoleArray[] = 'max:' . self::massimoPerElemento();

        return [
            'allegati'   => $regoleArray,
            'allegati.*' => [
                'required',
                'file',
                'extensions:' . implode(',', self::FORMATI),
                'max:' . LimiteCaricamento::regolaMax(),
                FileComeIlNome::regola(),
            ],
        ];
    }

    /**
     * Controlla se lo spazio residuo basta per i file caricati.
     *
     * @param  array<int, mixed>  $files
     */
    public static function erroreSpazio(array $files): ?string
    {
        $spazio = app(SpazioDocumenti::class);
        $limite = $spazio->limiteByte();
        if ($limite === null) {
            return null;
        }

        $nuoviByte = 0;
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $nuoviByte += (int) $file->getSize();
            }
        }

        if ($spazio->usatoByte() + $nuoviByte > $limite) {
            return 'Lo spazio per i documenti è esaurito: gli allegati nuovi non si possono caricare. Avvisa l\'amministratore dello studio.';
        }

        return null;
    }

    public static function errorePostiRimasti(int $esistenti, int $nuovi): ?string
    {
        $massimo = self::massimoPerElemento();
        if ($esistenti + $nuovi <= $massimo) {
            return null;
        }

        $rimasti = max(0, $massimo - $esistenti);
        if ($rimasti === 0) {
            return 'È stato raggiunto il limite di ' . $massimo . ' allegati: non se ne possono aggiungere altri.';
        }

        return $rimasti === 1
            ? 'Si può aggiungere ancora 1 solo allegato (massimo ' . $massimo . ').'
            : "Si possono aggiungere ancora {$rimasti} allegati (massimo " . $massimo . ').';
    }

    public static function conteggioInAttesa(?User $user): int
    {
        if ($user === null || ! self::haPannello($user)) {
            return 0;
        }

        $apreTutteSegnalazioni = self::haPermesso($user, Permission::VIEW_SEGNALAZIONI->value);
        $apreProprieSegnalazioni = self::haPermesso($user, Permission::VIEW_OWN_SEGNALAZIONI->value);
        $contaSegnalazioni = self::haPermesso($user, Permission::APPROVE_ATTACHMENTS_SEGNALAZIONI->value)
            && self::haPermesso($user, Permission::VIEW_ATTACHMENTS_SEGNALAZIONI->value)
            && ($apreTutteSegnalazioni || $apreProprieSegnalazioni);

        $contaComunicazioni = self::haPermesso($user, Permission::APPROVE_ATTACHMENTS_COMUNICAZIONI->value)
            && self::haPermesso($user, Permission::VIEW_ATTACHMENTS_COMUNICAZIONI->value);

        if (! $contaSegnalazioni && ! $contaComunicazioni) {
            return 0;
        }

        $morphSegnalazione = (new Segnalazione)->getMorphClass();
        $morphComunicazione = (new Comunicazione)->getMorphClass();

        return Documento::query()
            ->where('is_approved', false)
            ->where(function ($query) use (
                $user,
                $contaSegnalazioni,
                $contaComunicazioni,
                $apreTutteSegnalazioni,
                $morphSegnalazione,
                $morphComunicazione,
            ) {
                if ($contaSegnalazioni) {
                    $query->orWhere(function ($q) use ($user, $apreTutteSegnalazioni, $morphSegnalazione) {
                        $q->where('documentable_type', $morphSegnalazione)
                            ->whereHasMorph('documentable', [Segnalazione::class], function ($s) use ($user, $apreTutteSegnalazioni) {
                                if (! $apreTutteSegnalazioni) {
                                    $s->where('created_by', $user->id);
                                }
                            });
                    });
                }

                if ($contaComunicazioni) {
                    $query->orWhere(function ($q) use ($morphComunicazione) {
                        $q->where('documentable_type', $morphComunicazione)
                            ->whereHasMorph('documentable', [Comunicazione::class]);
                    });
                }
            })
            ->count();
    }

    public static function nomeAutore(Documento $allegato): string
    {
        $autore = $allegato->createdBy;
        $nomeAnagrafica = trim((string) ($autore?->anagrafica?->nome ?? ''));
        if ($nomeAnagrafica !== '') {
            return $nomeAnagrafica;
        }

        $nomeUtente = trim((string) ($autore?->name ?? ''));

        return $nomeUtente !== '' ? $nomeUtente : 'Utente';
    }

    public static function accorciaNomeFile(string $nome, int $massimo = 255): string
    {
        if (mb_strlen($nome) <= $massimo) {
            return $nome;
        }

        $estensione = pathinfo($nome, PATHINFO_EXTENSION);
        $suffisso = $estensione !== '' ? '.' . $estensione : '';
        $lunghezzaBase = max(1, $massimo - mb_strlen($suffisso));
        $base = $estensione !== '' ? mb_substr($nome, 0, mb_strlen($nome) - mb_strlen($suffisso)) : $nome;

        return mb_substr($base, 0, $lunghezzaBase) . $suffisso;
    }

    public static function nomePerFileSystem(string $nome, string $ripiego = 'documento'): string
    {
        $vietati = ['\\', '/', ':', '*', '?', '"', '<', '>', '|'];

        $pulito = str_replace($vietati, '-', $nome);
        $pulito = preg_replace('#[\x00-\x1F\x7F]#u', '-', $pulito) ?? $pulito;
        $pulito = rtrim($pulito, ". \t");

        return $pulito === '' ? $ripiego : $pulito;
    }

    private static function appartieneAlCondominio(User $user, ?int $condominioId): bool
    {
        if ($condominioId === null) {
            return false;
        }

        return $user->anagrafica?->condomini()->whereKey($condominioId)->exists() ?? false;
    }
}

