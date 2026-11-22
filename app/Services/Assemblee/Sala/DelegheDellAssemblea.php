<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Sala;

use App\Enums\Assemblee\ArrivoDellaDelega;
use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Assemblee\StatoDelega;
use App\Enums\Role;
use App\Helpers\DateHelper;
use App\Models\Anagrafica;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\DelegaDellAssemblea;
use App\Models\User;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use App\Settings\AssembleeSettings;
use App\Settings\PrintSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le deleghe dell'assemblea (R7; art. 67 c. 1 e 5 disp. att.).
 *
 * - **Dove nascono**: «Deleghe ricevute» nel passo «In sala» prima dell'assemblea (`carta_prima`, con la scansione facoltativa), o in sala (`in_sala`, un
 *   gesto). Il portale dei condòmini le manderà con `portale`, senza migrazioni: la richiesta e la firma andranno in `dati`.
 * - **Il quinto** si conta **nel momento in cui si segna**, sulle teste e sul valore della fotografia (provvisoria prima dell'apertura), e solo con più di
 *   venti teste: le teste che il delegato rappresenta per delega, senza la sua. Oltre il quinto la delega si rifiuta con l'articolo, oppure, con la scelta
 *   «Decide il presidente», passa con il suo motivo; prima della sala si segna e la decide il presidente all'apertura.
 * - **Mai all'amministratore**: il nome dello studio nelle stampe, o una persona con il ruolo di amministratore.
 * - **Una persona, una delega valida per assemblea**: una seconda sostituisce la prima, che resta «revocata».
 * - **All'apertura** le deleghe valide si ricontano con la fotografia della seduta: quella che ora porta il delegato oltre il quinto si rifiuta (o la decide
 *   il presidente).
 *
 * Ogni delega, anche rifiutata, è una riga del registro.
 */
final class DelegheDellAssemblea
{
    public const ARTICOLO = 'art. 67 c. 1 disp. att.';

    public const FRASE_AMMINISTRATORE = 'All\'amministratore non possono essere conferite deleghe (art. 67 c. 5 disp. att.).';

    public const ESTENSIONI_DELLA_SCANSIONE = ['pdf', 'jpg', 'jpeg', 'png'];

    public function __construct(private readonly FotografiaDellaSeduta $fotografie) {}

    /**
     * «Deleghe ricevute»: la delega su carta prima dell'assemblea. Dall'assemblea convocata fino alla chiusura.
     *
     * @param array{delegante_anagrafica_id: int, delegato: array{anagrafica_id?: ?int, nome?: ?string}, ricevuta_il?: ?string, punti?: ?list<int>,
     *     motivo?: ?string} $dati
     */
    public function ricevuta(Assemblea $assemblea, array $dati, User $utente, ?UploadedFile $scansione = null): DelegaDellAssemblea
    {
        if (! in_array($assemblea->stato, [StatoAssemblea::CONVOCATA, StatoAssemblea::IN_CORSO], true)) {
            throw ValidationException::withMessages(['delegante_anagrafica_id' => 'Le deleghe si segnano dalla prima spedizione della convocazione fino alla chiusura.']);
        }
        $ricevuta = (string) ($dati['ricevuta_il'] ?? DateHelper::oggiUtente());
        if ($ricevuta > DateHelper::oggiUtente()) {
            throw ValidationException::withMessages(['ricevuta_il' => 'Il giorno in cui lo studio l\'ha ricevuta non può essere dopo oggi.']);
        }

        return $this->registra($assemblea, $dati, ArrivoDellaDelega::CARTA_PRIMA, $utente, CarbonImmutable::now(), null, $ricevuta, $scansione, false);
    }

    /** La delega segnata in sala: un gesto. */
    public function gestoInSala(GestoInArrivo $g): array
    {
        $seduta = StatiDellaSala::sedutaApertaDelGesto($g, false);
        $delega = $this->registra($g->assemblea, $g->dati, ArrivoDellaDelega::IN_SALA, $g->utente, $g->segnatoIl, $g->numero, $g->giorno(), null,
            $g->dalFoglio($seduta));

        return ['versione' => null, 'messaggio' => $delega->stato === StatoDelega::RIFIUTATA ? $delega->motivo : null, 'delega_id' => (int) $delega->id];
    }

    /** «Revoca»: la delega non vale più (il delegante la ritira), con il motivo. */
    public function revoca(Assemblea $assemblea, DelegaDellAssemblea $delega, string $motivo, User $utente, \DateTimeInterface $quando, bool $dalFoglio = false): void
    {
        abort_unless((int) $delega->assemblea_id === (int) $assemblea->id, 404);
        if ($delega->stato !== StatoDelega::VALIDA) {
            throw ValidationException::withMessages(['delega' => 'Si revoca solo una delega valida.']);
        }
        if (trim($motivo) === '') {
            throw ValidationException::withMessages(['motivo' => 'Scrivi perché la delega si revoca.']);
        }
        RegistroModifiche::durante(GestiDelRegistro::dellaSala($assemblea, 'delega di ' . $delega->delegante_nome . ' a ' . $delega->delegato_nome . ' revocata',
            $quando, $dalFoglio, trim($motivo), array_filter([$delega->delegante_anagrafica_id, $delega->delegato_anagrafica_id])),
            fn () => $delega->update(['stato' => StatoDelega::REVOCATA->value, 'revocata_il' => $quando, 'motivo' => trim($motivo)]));
    }

    public function revocaInSala(GestoInArrivo $g): array
    {
        $delega = DelegaDellAssemblea::query()->where('assemblea_id', $g->assemblea->id)->whereKey($g->id('delega_id'))->first()
            ?? throw ValidationException::withMessages(['gesto' => 'La delega non è di questa assemblea.']);
        $seduta = StatiDellaSala::sedutaApertaDelGesto($g, false);
        $this->revoca($g->assemblea, $delega, $g->testo('motivo'), $g->utente, $g->segnatoIl, $g->dalFoglio($seduta));

        return ['versione' => null, 'messaggio' => null];
    }

    /**
     * Il presidente decide una delega oltre il quinto (con «Decide il presidente»): la accetta o la rifiuta, sempre con il motivo. Accettata, conta da quel
     * momento (`decisa_il`): finché era da decidere non contava, né nella barra né nel voto.
     */
    public function decidi(GestoInArrivo $g): array
    {
        $delega = DelegaDellAssemblea::query()->where('assemblea_id', $g->assemblea->id)->whereKey($g->id('delega_id'))->first()
            ?? throw ValidationException::withMessages(['gesto' => 'La delega non è di questa assemblea.']);
        $motivo = $g->testo('motivo');
        if ($motivo === '') {
            throw ValidationException::withMessages(['gesto' => 'Scrivi il motivo della scelta del presidente.']);
        }
        if ($delega->stato !== StatoDelega::VALIDA) {
            throw ValidationException::withMessages(['gesto' => 'Si decide solo una delega ancora valida.']);
        }
        $accetta = (bool) $g->dato('accetta', false);
        $seduta = StatiDellaSala::sedutaApertaDelGesto($g, false);
        RegistroModifiche::durante(GestiDelRegistro::dellaSala($g->assemblea, 'delega di ' . $delega->delegante_nome . ' a ' . $delega->delegato_nome
            . ($accetta ? ' accettata dal presidente' : ' rifiutata dal presidente'), $g->segnatoIl, $g->dalFoglio($seduta), $motivo,
            array_filter([$delega->delegante_anagrafica_id, $delega->delegato_anagrafica_id])),
            fn () => $delega->update(['stato' => $accetta ? StatoDelega::VALIDA->value : StatoDelega::RIFIUTATA->value,
                'motivo' => ($accetta ? 'Accettata dal presidente: ' : 'Rifiutata dal presidente: ') . $motivo,
                'dati' => array_diff_key((array) $delega->dati, ['da_decidere' => true]) + ['decisa_il' => $g->segnatoIl->toIso8601ZuluString()]]));

        return ['versione' => null, 'messaggio' => null];
    }

    /**
     * All'apertura, con la fotografia della seduta: le deleghe valide si ricontano in ordine di arrivo, per delegato; quella che lo porta oltre il quinto si
     * rifiuta con l'articolo, o con «Decide il presidente» resta da decidere. Rende le frasi per il controllo dell'apertura; con `$perIlRegistro` (e
     * `$soloLeggi`) le frasi per il perché della riga dell'apertura e per il prospetto, una per delega: «Delega di … a … rifiutata: all'apertura, …».
     *
     * @param array<string, mixed> $fotografia
     * @return list<string>
     */
    public function allApertura(Assemblea $assemblea, array $fotografia, \DateTimeInterface $quando, bool $soloLeggi = false, bool $perIlRegistro = false): array
    {
        $frasi = [];
        $decide = ImpostazioniDellaSala::dellaFotografia($fotografia)['delega_oltre_il_quinto'] === AssembleeSettings::DECIDE_IL_PRESIDENTE;
        $perDelegato = DelegaDellAssemblea::query()->where('assemblea_id', $assemblea->id)->where('stato', StatoDelega::VALIDA->value)->orderBy('id')->get()
            ->groupBy(fn (DelegaDellAssemblea $d) => self::chiDelDelegato($d->delegato_anagrafica_id, $d->delegato_nome));
        foreach ($perDelegato as $deleghe) {
            $finora = [];
            foreach ($deleghe as $d) {
                $quinto = self::quintoDi($fotografia, [...$finora, (int) $d->delegante_anagrafica_id], $d->delegato_anagrafica_id);
                // Accettata dal presidente oltre il quinto (`delega_decidi`): la sua decisione resta, e non si chiede di nuovo.
                if (! $quinto['oltre'] || ($decide && isset($d->dati['decisa_il']))) {
                    $finora[] = (int) $d->delegante_anagrafica_id;
                    continue;
                }
                $frase = 'All\'apertura, con la fotografia della seduta, ' . $d->delegato_nome . ' rappresenterebbe ' . $quinto['frase'] . ': oltre un quinto '
                    . self::diChe($quinto) . ' (' . self::ARTICOLO . ').';
                $frasi[] = match (true) {
                    $perIlRegistro => 'Delega di ' . $d->delegante_nome . ' a ' . $d->delegato_nome . ($decide ? ' da decidere dal presidente: ' : ' rifiutata: ')
                        . lcfirst($frase),
                    $decide => $frase . ' Decide il presidente.',
                    default => $frase,
                };
                if ($soloLeggi) {
                    continue;
                }
                if ($decide) {
                    $d->update(['dati' => (array) $d->dati + ['da_decidere' => true, 'quinto_all_apertura' => $quinto]]);
                } else {
                    $d->update(['stato' => StatoDelega::RIFIUTATA->value, 'motivo' => $frase, 'dati' => (array) $d->dati + ['quinto_all_apertura' => $quinto]]);
                }
            }
        }

        return $frasi;
    }

    /**
     * Il quinto di un delegato: le teste della costituzione che porta per delega (quelle dei deleganti dati), senza la sua, e il loro valore.
     *
     * @param array<string, mixed> $fotografia
     * @param list<int> $deleganti
     * @return array<string, mixed> il conto di {@see ContiDellaSala::quinto()}
     */
    public static function quintoDi(array $fotografia, array $deleganti, ?int $delegato): array
    {
        $teste = 0;
        $valore = 0;
        foreach ((array) $fotografia['costituzione']['teste'] as $t) {
            $persone = array_map('intval', $t['persone']);
            if ($delegato !== null && in_array($delegato, $persone, true)) {
                continue;
            }
            if (array_intersect($persone, $deleganti) !== []) {
                $teste++;
                $valore += (int) $t['millesimi'];
            }
        }

        return ContiDellaSala::quinto((int) $fotografia['costituzione']['totale_teste'], (int) $fotografia['costituzione']['totale_valore'], $teste, $valore);
    }

    /**
     * La delega, controllata e scritta con la sua riga: chi delega è una persona della fotografia, il delegato non è l'amministratore né chi delega, il quinto
     * al momento; la delega di prima della stessa persona si revoca quando questa è valida.
     *
     * @param array<string, mixed> $dati
     */
    private function registra(Assemblea $assemblea, array $dati, ArrivoDellaDelega $arrivo, User $utente, \DateTimeInterface $quando, ?string $numero,
        string $ricevuta, ?UploadedFile $scansione, bool $dalFoglio): DelegaDellAssemblea
    {
        $fotografia = $this->fotografiaDiAdesso($assemblea);
        $deleganteId = (int) ($dati['delegante_anagrafica_id'] ?? 0);
        $persone = (array) $fotografia['persone'];
        if (! isset($persone[$deleganteId])) {
            throw ValidationException::withMessages([$arrivo === ArrivoDellaDelega::IN_SALA ? 'gesto' : 'delegante_anagrafica_id' =>
                'Chi delega non è fra i condòmini di questa assemblea.']);
        }
        $deleganteNome = (string) $persone[$deleganteId]['nome'];
        $chiave = $arrivo === ArrivoDellaDelega::IN_SALA ? 'gesto' : 'delegato';
        $delegato = (array) ($dati['delegato'] ?? []);
        $delegatoId = isset($delegato['anagrafica_id']) && (int) $delegato['anagrafica_id'] > 0 ? (int) $delegato['anagrafica_id'] : null;
        $delegatoNome = $delegatoId !== null ? (string) (Anagrafica::query()->whereKey($delegatoId)->value('nome') ?? '') : trim((string) ($delegato['nome'] ?? ''));
        if ($delegatoNome === '') {
            throw ValidationException::withMessages([$chiave => 'Scrivi a chi è la delega: un condòmino, o il nome di una persona.']);
        }
        if ($delegatoId === $deleganteId) {
            throw ValidationException::withMessages([$chiave => 'Chi delega non può delegare sé stesso.']);
        }
        if (self::eLAmministratore($delegatoNome, $delegatoId)) {
            throw ValidationException::withMessages([$chiave => self::FRASE_AMMINISTRATORE]);
        }
        $punti = array_values(array_unique(array_map('intval', (array) ($dati['punti'] ?? []))));
        if ($punti !== [] && $assemblea->punti()->whereIn('id', $punti)->count() !== count($punti)) {
            throw ValidationException::withMessages([$chiave => 'I punti della delega non sono di questa assemblea.']);
        }
        $unita = [];
        foreach ((array) $fotografia['unita'] as $id => $u) {
            if (in_array($deleganteId, array_map(fn ($t) => (int) $t['anagrafica_id'], (array) $u['titolari']), true)) {
                $unita[] = (int) $id;
            }
        }
        sort($unita);

        // Il quinto con questa delega: le altre deleghe valide allo stesso delegato, senza quella di prima di chi delega (questa la sostituisce).
        $altre = DelegaDellAssemblea::query()->where('assemblea_id', $assemblea->id)->where('stato', StatoDelega::VALIDA->value)
            ->where('delegante_anagrafica_id', '!=', $deleganteId)->get()
            ->filter(fn ($d) => self::chiDelDelegato($d->delegato_anagrafica_id, $d->delegato_nome) === self::chiDelDelegato($delegatoId, $delegatoNome))
            ->pluck('delegante_anagrafica_id')->map(fn ($id) => (int) $id)->all();
        $quinto = self::quintoDi($fotografia, [...$altre, $deleganteId], $delegatoId);
        $motivo = trim((string) ($dati['motivo'] ?? ''));
        $stato = StatoDelega::VALIDA;
        $nota = null;
        $daDecidere = false;
        if ($quinto['oltre']) {
            $oltre = 'Con questa delega ' . $delegatoNome . ' rappresenterebbe ' . $quinto['frase'] . ': oltre un quinto ' . self::diChe($quinto) . ' (' . self::ARTICOLO . ').';
            if (ImpostazioniDellaSala::dellaFotografia($fotografia)['delega_oltre_il_quinto'] !== AssembleeSettings::DECIDE_IL_PRESIDENTE) {
                $stato = StatoDelega::RIFIUTATA;
                $nota = $oltre;
            } elseif ($motivo !== '') {
                $nota = 'Oltre un quinto ' . self::diChe($quinto) . ', accettata dal presidente: ' . $motivo;
            } elseif ($arrivo === ArrivoDellaDelega::IN_SALA) {
                throw ValidationException::withMessages(['gesto' => $oltre . ' Decide il presidente: scrivi il motivo per accettarla.']);
            } else {
                $daDecidere = true;
                $nota = $oltre . ' La decide il presidente all\'apertura.';
            }
        }

        $frase = 'delega di ' . $deleganteNome . ' a ' . $delegatoNome . match (true) {
            $stato === StatoDelega::RIFIUTATA => ', rifiutata: oltre un quinto ' . self::diChe($quinto),
            $arrivo === ArrivoDellaDelega::IN_SALA => ', segnata in sala alle ' . CarbonImmutable::instance($quando)->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('H:i'),
            default => ', ricevuta su carta il ' . CarbonImmutable::parse($ricevuta)->format('d/m/Y'),
        };

        return RegistroModifiche::durante(GestiDelRegistro::dellaSala($assemblea, $frase, $quando, $dalFoglio, $nota, array_filter([$deleganteId, $delegatoId])),
            function () use ($assemblea, $deleganteId, $deleganteNome, $unita, $delegatoId, $delegatoNome, $punti, $arrivo, $ricevuta, $quando, $stato, $nota,
                $quinto, $daDecidere, $numero, $utente, $scansione) {
                $delega = DelegaDellAssemblea::create([
                    'assemblea_id' => $assemblea->id, 'delegante_anagrafica_id' => $deleganteId, 'delegante_nome' => $deleganteNome, 'unita' => $unita,
                    'delegato_anagrafica_id' => $delegatoId, 'delegato_nome' => $delegatoNome, 'punti' => $punti === [] ? null : $punti,
                    'arrivata_come' => $arrivo->value, 'ricevuta_il' => $ricevuta, 'segnata_il' => $arrivo === ArrivoDellaDelega::IN_SALA ? $quando : null,
                    'stato' => $stato->value, 'motivo' => $nota, 'dati' => ['quinto' => $quinto] + ($daDecidere ? ['da_decidere' => true] : []), 'gesto' => $numero,
                    'created_by' => $utente->id,
                ]);
                if ($scansione !== null) {
                    $delega->update(['documento_id' => $this->scansione($assemblea, $delega, $scansione, $utente)]);
                }
                if ($stato === StatoDelega::VALIDA) {
                    foreach (DelegaDellAssemblea::query()->where('assemblea_id', $assemblea->id)->where('delegante_anagrafica_id', $deleganteId)
                        ->where('stato', StatoDelega::VALIDA->value)->where('id', '!=', $delega->id)->get() as $prima) {
                        $prima->update(['stato' => StatoDelega::REVOCATA->value, 'revocata_il' => $quando,
                            'motivo' => 'Sostituita dalla delega a ' . $delegatoNome . '.']);
                    }
                }

                return $delega->refresh();
            });
    }

    /** La fotografia per le deleghe: quella della seduta aperta, o la provvisoria. @return array<string, mixed> */
    private function fotografiaDiAdesso(Assemblea $assemblea): array
    {
        $aperta = StatiDellaSala::sedutaAperta($assemblea);

        return $aperta?->fotografia ?? $this->fotografie->provvisoria($assemblea);
    }

    /** La scansione della delega fra i documenti dell'assemblea, fuori dall'archivio e dal portale: PDF o immagine, al più 10 MB. */
    private function scansione(Assemblea $assemblea, DelegaDellAssemblea $delega, UploadedFile $file, User $utente): int
    {
        $estensione = mb_strtolower($file->getClientOriginalExtension());
        if (! in_array($estensione, self::ESTENSIONI_DELLA_SCANSIONE, true) || $file->getSize() > \App\Support\LimiteCaricamento::regolaMax(10.0) * 1024) {
            throw ValidationException::withMessages(['scansione' => 'La scansione della delega è un PDF o un\'immagine (pdf, jpg, png), al più 10 MB.']);
        }
        $percorso = app(ArchivioDocumenti::class)->salva($file);
        DB::afterRollBack(fn () => app(ArchivioDocumenti::class)->elimina($percorso));
        $documento = $assemblea->documenti()->create([
            'name' => 'Delega di ' . $delega->delegante_nome . ' a ' . $delega->delegato_nome, 'description' => 'La delega ricevuta per l\'assemblea',
            'path' => $percorso, 'mime_type' => (string) $file->getMimeType(), 'file_size' => (int) $file->getSize(), 'created_by' => $utente->id,
            'is_published' => false, 'is_approved' => true,
        ]);

        return (int) $documento->id;
    }

    /** Il delegato come chiave: l'anagrafica, o il nome scritto (in minuscolo e senza spazi doppi). */
    public static function chiDelDelegato(?int $anagraficaId, ?string $nome): string
    {
        return $anagraficaId !== null ? 'anagrafica:' . $anagraficaId : 'nome:' . self::normale((string) $nome);
    }

    /** «dei condòmini», «del valore», «dei condòmini e del valore». @param array<string, mixed> $quinto */
    private static function diChe(array $quinto): string
    {
        return match (true) {
            $quinto['oltre_teste'] && $quinto['oltre_valore'] => 'dei condòmini e del valore',
            $quinto['oltre_teste'] => 'dei condòmini',
            default => 'del valore',
        };
    }

    /** Il delegato è l'amministratore: il nome dello studio nelle stampe, o una persona con il ruolo di amministratore (anche l'utente dell'anagrafica). */
    private static function eLAmministratore(string $nome, ?int $anagraficaId): bool
    {
        $normale = self::normale($nome);
        $studio = self::normale((string) app(PrintSettings::class)->studio_nome);
        if ($studio !== '' && $normale === $studio) {
            return true;
        }
        $amministratori = User::query()->whereHas('roles', fn ($q) => $q->where('name', Role::AMMINISTRATORE->value))->get(['id', 'name']);
        if ($amministratori->contains(fn (User $u) => self::normale((string) $u->name) === $normale)) {
            return true;
        }
        $utente = $anagraficaId === null ? null : Anagrafica::query()->whereKey($anagraficaId)->value('user_id');

        return $utente !== null && $amministratori->contains('id', (int) $utente);
    }

    private static function normale(string $testo): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $testo)));
    }
}
