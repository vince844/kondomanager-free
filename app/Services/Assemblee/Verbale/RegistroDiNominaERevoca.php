<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\EventoNomina;
use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\StatoNomina;
use App\Enums\Assemblee\StatoVerbale;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\RigaDiNomina;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Models\Condominio;
use App\Models\User;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\PDF\CartaDiStampa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Il registro di nomina e revoca dell'amministratore (V9; art. 1130 n. 7 c.c.: «in ordine cronologico, le date della nomina e della revoca di ciascun
 * amministratore del condominio, nonché gli estremi del decreto in caso di provvedimento giudiziale»). È **uno, del condominio** (decisione 598).
 *
 * - **La nomina in corso**, la prima volta (decisione 595 (7)): chi amministra oggi, da quando, da dove viene (una delibera su carta con gli estremi, un verbale
 *   registrato qui, un decreto con l'autorità, il numero e la data), il documento facoltativo.
 * - **La riga proposta dalla delibera**: registrato un verbale con una delibera approvata della materia «Nomina o revoca dell'amministratore», il programma
 *   propone «Nomina: Andrea Costa, dal 15/12/2026, verbale n. 42, punto 6»; finché l'amministratore non la conferma resta «Proposta» e non va nella stampa;
 *   la correzione cambia la proposta (una delibera che revoca e nomina un altro: la revoca, poi la nomina aggiunta). Una delibera respinta non propone niente.
 * - **Una riga sbagliata si annulla con il perché**: non si cancella. Ogni gesto è una riga del registro delle modifiche, nella «Storia» del condominio.
 */
final class RegistroDiNominaERevoca
{
    public function __construct(private readonly ArchivioDocumenti $archivio) {}

    /** Le righe del registro, per data: le confermate, le proposte e le annullate. @return list<array<string, mixed>> */
    public function righe(Condominio $condominio, bool $soloConfermate = false): array
    {
        return RigaDiNomina::query()->where('condominio_id', $condominio->id)->when($soloConfermate, fn ($q) => $q->where('stato', StatoNomina::CONFERMATA->value))
            ->with(['verbale:id,numero', 'documento:id,name'])->orderBy('dal')->orderBy('id')->get()->map(fn (RigaDiNomina $r) => self::riga($r))->values()->all();
    }

    /**
     * Le righe della stampa del registro (decisione da confermare, proposta (a) della domanda sulla stampa): le confermate, e quelle che erano confermate e
     * sono state annullate, con il perché. Una riga si annulla, non si cancella: la stampa la tiene. Le proposte mai confermate non sono nel registro.
     *
     * @return list<array<string, mixed>>
     */
    public function righePerLaStampa(Condominio $condominio): array
    {
        return RigaDiNomina::query()->where('condominio_id', $condominio->id)
            ->where(fn ($q) => $q->where('stato', StatoNomina::CONFERMATA->value)->orWhere(fn ($a) => $a->where('stato', StatoNomina::ANNULLATA->value)
                ->whereNotNull('confermata_il')))
            ->with(['verbale:id,numero', 'documento:id,name'])->orderBy('dal')->orderBy('id')->get()->map(fn (RigaDiNomina $r) => self::riga($r))->values()->all();
    }

    /** @return array<string, mixed> */
    public static function riga(RigaDiNomina $r): array
    {
        return ['id' => (int) $r->id, 'evento' => $r->evento?->value, 'evento_parola' => $r->evento?->parola(), 'amministratore' => $r->amministratore,
            'dal' => $r->dal?->format('Y-m-d'), 'da_dove' => $r->da_dove, 'estremi' => $r->estremi, 'decreto' => $r->decreto, 'delibera_id' => $r->delibera_id,
            'verbale' => $r->verbale === null ? null : ['id' => (int) $r->verbale->id, 'numero' => $r->verbale->numero],
            'documento' => $r->documento === null ? null : ['id' => (int) $r->documento->id, 'nome' => (string) $r->documento->name], 'nota' => $r->nota,
            'stato' => $r->stato?->value, 'stato_parola' => $r->stato?->parola(), 'annullata_motivo' => $r->annullata_motivo,
            'annullata_il' => $r->annullata_il?->format('Y-m-d')];
    }

    /** Il registro non ha ancora la nomina in corso: la prima volta la scheda la chiede. */
    public function senzaLaNominaInCorso(Condominio $condominio): bool
    {
        return ! RigaDiNomina::query()->where('condominio_id', $condominio->id)->where('stato', '!=', StatoNomina::ANNULLATA->value)->exists();
    }

    /**
     * Una riga scritta a mano (la nomina in corso la prima volta, o una nomina o revoca di prima): confermata, con il documento facoltativo.
     *
     * @param array{evento?: ?string, amministratore: string, dal: string, da_dove: string, estremi?: ?string, decreto?: ?array, nota?: ?string} $dati
     */
    public function aMano(Condominio $condominio, array $dati, ?UploadedFile $documento, User $utente): RigaDiNomina
    {
        $campi = $this->campi($dati);
        $percorso = null;
        try {
            if ($documento !== null) {
                RegistrazioneDelVerbale::controllaLaCopia($documento, 'documento');
            }

            return DB::transaction(function () use ($condominio, $campi, $documento, $utente, &$percorso) {
                $riga = RigaDiNomina::create($campi + ['condominio_id' => $condominio->id, 'stato' => StatoNomina::CONFERMATA->value,
                    'confermata_il' => now(), 'confermata_da' => $utente->id, 'created_by' => $utente->id]);
                if ($documento !== null) {
                    // Il documento della riga (la scansione della delibera, il decreto): fuori dall'archivio e dal portale, come la copia del verbale.
                    $percorso = $this->archivio->salva($documento);
                    $doc = $riga->documenti()->create(['name' => $documento->getClientOriginalName(), 'description' => 'Registro di nomina e revoca: '
                        . $campi['evento'] . ' di ' . $campi['amministratore'], 'path' => $percorso, 'mime_type' => (string) $documento->getMimeType(),
                        'file_size' => (int) $documento->getSize(), 'created_by' => $utente->id, 'is_published' => false, 'is_approved' => true]);
                    $riga->update(['documento_id' => $doc->id]);
                }

                return $riga->refresh();
            });
        } catch (\Throwable $e) {
            if ($percorso !== null) {
                $this->archivio->elimina($percorso);
            }
            throw $e;
        }
    }

    /**
     * Registrato un verbale, le righe proposte dalle sue delibere della materia «Nomina o revoca dell'amministratore» approvate: una nomina, con chi amministra
     * oggi (l'ultima nomina confermata, o lo studio delle impostazioni di stampa), dal giorno della delibera, con il verbale e il punto. Si confermano o si
     * correggono; la registrazione non aspetta.
     *
     * @return list<RigaDiNomina>
     */
    public function proponiDalVerbale(VerbaleDellAssemblea $verbale, ?User $utente): array
    {
        $punti = VotazioneDelPunto::query()->where('seduta_id', $verbale->seduta_id)->pluck('punto_id');
        $delibere = Delibera::query()->where('assemblea_id', $verbale->assemblea_id)->whereIn('punto_id', $punti)
            ->whereIn('esito', [EsitoDelibera::APPROVATA->value, EsitoDelibera::APPROVATA_CON_MODIFICHE->value])->with('punto')->get()
            ->filter(fn (Delibera $d) => $d->punto?->materia === MateriaPunto::NOMINA);
        $proposte = [];
        foreach ($delibere as $d) {
            // La riga confermata con il verbale di una registrazione annullata: la registrazione giusta le dà il numero nuovo (la riga non cita un verbale annullato).
            $confermata = RigaDiNomina::query()->where('delibera_id', $d->id)->where('stato', StatoNomina::CONFERMATA->value)->first();
            if ($confermata !== null && $confermata->verbale_id !== null && (int) $confermata->verbale_id !== (int) $verbale->id
                && VerbaleDellAssemblea::query()->whereKey($confermata->verbale_id)->first()?->stato === StatoVerbale::ANNULLATO) {
                $confermata->update(['verbale_id' => $verbale->id, 'estremi' => 'verbale n. ' . $verbale->numero . ', punto ' . $d->punto->numero]);
            }
            if (RigaDiNomina::query()->where('delibera_id', $d->id)->where('stato', '!=', StatoNomina::ANNULLATA->value)->exists()) {
                continue;
            }
            // Dentro il gesto della registrazione: la riga proposta si conta nel suo lotto (la registrazione la dice).
            $proposte[] = RigaDiNomina::create(['condominio_id' => $verbale->condominio_id, 'evento' => EventoNomina::NOMINA->value,
                'amministratore' => self::nominatoInSala($d) ?? $this->amministratoreDiOggi($verbale->condominio_id), 'dal' => $d->deliberata_il?->format('Y-m-d'),
                'da_dove' => RigaDiNomina::DA_VERBALE, 'estremi' => 'verbale n. ' . $verbale->numero . ', punto ' . $d->punto->numero,
                'delibera_id' => $d->id, 'verbale_id' => $verbale->id, 'stato' => StatoNomina::PROPOSTA->value, 'created_by' => $utente?->id]);
        }

        return $proposte;
    }

    /** Il nome del nuovo amministratore detto in sala alla conferma del voto del punto di nomina, se c'è. */
    public static function nominatoInSala(Delibera $d): ?string
    {
        $nome = trim((string) (VotazioneDelPunto::query()->where('delibera_id', $d->id)->orderByDesc('id')->first()?->scelte['nomina']['amministratore'] ?? ''));

        return $nome === '' ? null : $nome;
    }

    /** «Conferma la riga»: la proposta entra nel registro e nella sua stampa. */
    public function conferma(RigaDiNomina $riga, User $utente): RigaDiNomina
    {
        if ($riga->stato !== StatoNomina::PROPOSTA) {
            throw ValidationException::withMessages(['riga' => 'Si conferma una riga proposta.']);
        }
        $riga->update(['stato' => StatoNomina::CONFERMATA->value, 'confermata_il' => now(), 'confermata_da' => $utente->id]);

        return $riga->refresh();
    }

    /** «Correggi» una proposta (o una riga a mano): che cosa, chi, da quando, gli estremi. @param array<string, mixed> $dati */
    public function correggi(RigaDiNomina $riga, array $dati, User $utente): RigaDiNomina
    {
        if ($riga->stato === StatoNomina::ANNULLATA) {
            throw ValidationException::withMessages(['riga' => 'Una riga annullata non si corregge: scrivine una nuova.']);
        }
        $dati['da_dove'] = ($dati['da_dove'] ?? null) ?: $riga->da_dove;
        $dati['evento'] = ($dati['evento'] ?? null) ?: $riga->evento?->value;
        $campi = $this->campi($dati);
        $riga->update($campi);

        return $riga->refresh();
    }

    /** «Annulla» una riga con il perché: resta nel registro, annullata. */
    public function annulla(RigaDiNomina $riga, string $motivo, User $utente): RigaDiNomina
    {
        if ($riga->stato === StatoNomina::ANNULLATA) {
            throw ValidationException::withMessages(['motivo' => 'Questa riga è già annullata.']);
        }
        if (mb_strlen(trim($motivo)) < 3) {
            throw ValidationException::withMessages(['motivo' => 'Scrivi perché annulli la riga: resta nel registro.']);
        }
        $riga->update(['stato' => StatoNomina::ANNULLATA->value, 'annullata_il' => now(), 'annullata_motivo' => trim($motivo), 'annullata_da' => $utente->id]);

        return $riga->refresh();
    }

    /**
     * Annullata la registrazione di un verbale, le righe che proponeva e non sono confermate si annullano con il perché; quelle confermate restano (la nomina
     * c'è, la delibera non cambia) e gli estremi dicono che il verbale è annullato, finché la registrazione giusta non dà il numero nuovo.
     */
    public function dopoLAnnullamento(VerbaleDellAssemblea $verbale, User $utente): void
    {
        foreach (RigaDiNomina::query()->where('verbale_id', $verbale->id)->where('stato', StatoNomina::PROPOSTA->value)->get() as $riga) {
            $riga->update(['stato' => StatoNomina::ANNULLATA->value, 'annullata_il' => now(), 'annullata_motivo' => 'la registrazione del verbale n. '
                . $verbale->numero . ' è annullata', 'annullata_da' => $utente->id]);
        }
        foreach (RigaDiNomina::query()->where('verbale_id', $verbale->id)->where('stato', StatoNomina::CONFERMATA->value)->get() as $riga) {
            $riga->update(['estremi' => mb_substr(trim((string) $riga->estremi) . ' (il verbale n. ' . $verbale->numero . ' è annullato: il numero nuovo arriva con la '
                . 'registrazione giusta)', 0, 255)]);
        }
    }

    /** Chi amministra oggi: l'ultima nomina confermata del condominio, altrimenti lo studio delle impostazioni di stampa, altrimenti «L'amministratore». */
    public function amministratoreDiOggi(int $condominioId): string
    {
        $ultima = RigaDiNomina::query()->where('condominio_id', $condominioId)->where('stato', StatoNomina::CONFERMATA->value)
            ->where('evento', EventoNomina::NOMINA->value)->orderByDesc('dal')->orderByDesc('id')->value('amministratore');

        return (string) ($ultima ?? CartaDiStampa::studio()['nome'] ?? 'L\'amministratore');
    }

    /** I campi controllati. @param array<string, mixed> $dati @return array<string, mixed> */
    private function campi(array $dati): array
    {
        $evento = EventoNomina::tryFrom((string) ($dati['evento'] ?? EventoNomina::NOMINA->value))
            ?? throw ValidationException::withMessages(['evento' => 'Scegli nomina o revoca.']);
        $chi = trim((string) ($dati['amministratore'] ?? ''));
        if ($chi === '') {
            throw ValidationException::withMessages(['amministratore' => 'Scrivi chi è l\'amministratore.']);
        }
        $dal = (string) ($dati['dal'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dal) !== 1) {
            throw ValidationException::withMessages(['dal' => 'Scrivi da quando.']);
        }
        $daDove = (string) ($dati['da_dove'] ?? '');
        if (! in_array($daDove, RigaDiNomina::DA_DOVE, true)) {
            throw ValidationException::withMessages(['da_dove' => 'Scegli da dove viene: una delibera su carta, un verbale registrato qui, un decreto del tribunale.']);
        }
        $decreto = null;
        if ($daDove === RigaDiNomina::DA_DECRETO) {
            $d = (array) ($dati['decreto'] ?? []);
            if (trim((string) ($d['autorita'] ?? '')) === '' || trim((string) ($d['numero'] ?? '')) === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['data'] ?? '')) !== 1) {
                throw ValidationException::withMessages(['decreto' => 'Scrivi gli estremi del decreto: l\'autorità, il numero e la data (art. 1130 n. 7 c.c.).']);
            }
            $decreto = ['autorita' => trim((string) $d['autorita']), 'numero' => trim((string) $d['numero']), 'data' => (string) $d['data']];
        }
        $estremi = trim((string) ($dati['estremi'] ?? ''));

        return ['evento' => $evento->value, 'amministratore' => mb_substr($chi, 0, 255), 'dal' => $dal, 'da_dove' => $daDove,
            'estremi' => $estremi !== '' ? mb_substr($estremi, 0, 255) : ($decreto !== null ? 'decreto n. ' . $decreto['numero'] . ' del '
                . \Carbon\Carbon::parse($decreto['data'])->format('d/m/Y') . ', ' . $decreto['autorita'] : null),
            'decreto' => $decreto, 'nota' => filled($dati['nota'] ?? null) ? trim((string) $dati['nota']) : null];
    }
}
