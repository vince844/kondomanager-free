<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Enums\Assemblee\EsitoDelibera;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\User;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\CalendarioFiscaleService;
use App\Services\RegistroModifiche\RegistroModifiche;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * I trenta giorni per impugnare (V11; art. 1137 c. 2 c.c., riletto su Normattiva il 20/11/2026: «nel termine perentorio di trenta giorni, che decorre dalla
 * data della deliberazione per i dissenzienti o astenuti e dalla data di comunicazione della deliberazione per gli assenti»).
 *
 * - **Chi c'era**: contrari, astenuti e chi non ha votato (conta come astenuto, decisione 595 (3)) dal giorno della delibera; i favorevoli niente.
 * - **Chi era assente** e riceve il verbale: dal giorno in cui lo riceve, nella lettura prudente ({@see DestinatariDelVerbale::giornoDeiTermini()}): la
 *   consegna; la raccomandata in giacenza dal ritiro, o dalla compiuta giacenza (l'avviso più dieci giorni) se non la ritira; finché non lo riceve, il suo
 *   termine non parte. Chi l'amministratore toglie dalla lista conta come chi c'era e non ha votato.
 * - **Per tutti**: l'ultimo giorno di ognuno, quando ognuno ha la sua data; altrimenti nessuno.
 *
 * **Il conto, nella lettura più prudente** (la legge del conto non è ancora riletta sul testo: l'art. 155 c.p.c. e la sospensione feriale della L. 7 ottobre
 * 1969, n. 742): trenta giorni di calendario dal giorno dopo, **senza contare i giorni dal 1° al 31 agosto**, e l'ultimo giorno che cade di sabato, di
 * domenica o in una festa nazionale **passa al primo giorno lavorativo** dopo. La lettura più prudente per chi amministra è quella che finisce più tardi: una
 * delibera non si tratta come inattaccabile prima del tempo. Il dubbio è fra le domande della tappa.
 *
 * **«Segna impugnata»** scrive il giorno e la nota sulla delibera, con l'atto fra i documenti dell'assemblea: è un segno, non ferma niente (l'azione «non
 * sospende l'esecuzione», art. 1137 c. 3).
 */
final class TerminiDellImpugnazione
{
    public const GIORNI = 30;

    public function __construct(private readonly CalendarioFiscaleService $calendario) {}

    /** L'ultimo giorno per impugnare, dal giorno dato (la delibera, o la ricezione del verbale), nella lettura più prudente. */
    public function finoAl(string $dal): string
    {
        $giorno = CarbonImmutable::parse(substr($dal, 0, 10));
        $contati = 0;
        while ($contati < self::GIORNI) {
            $giorno = $giorno->addDay();
            if ((int) $giorno->format('n') === 8) {
                continue;
            }
            $contati++;
        }

        return $this->calendario->primoGiornoLavorativo($giorno)->format('Y-m-d');
    }

    /**
     * I termini di ogni delibera del verbale: per persona chi può impugnare e fino a quando, e il giorno da cui sono passati per tutti (null se manca una
     * consegna). È la lettura che la tappa delle delibere aspetta (V4 del suo progetto). Si confronta per delibera, mai per numero del punto: il rendiconto e
     * il riparto votati a parte hanno ognuno i suoi termini (Coda 750 B2), e `oggetto` dice quale dei due.
     *
     * @return list<array{delibera_id: int, punto: int, votazione: int, oggetto: ?string, esito: string, deliberata_il: string, chi: list<array<string, mixed>>, per_tutti: ?string}>
     */
    public function perIlVerbale(VerbaleDellAssemblea $verbale): array
    {
        $destinatari = app(DestinatariDelVerbale::class)->per($verbale);
        $uscita = [];
        foreach ($destinatari['punti'] as $p) {
            $chi = [];
            $tutti = true;
            $ultimo = null;
            foreach ($destinatari['persone'] as $persona) {
                foreach ($persona['termini'] as $t) {
                    if (! in_array((int) $p['delibera_id'], $t['delibere'] ?? [], true) || $t['dal'] === null && $t['da_consegnare'] === false) {
                        continue;
                    }
                    $chi[] = ['anagrafica_id' => $persona['anagrafica_id'], 'nome' => $persona['nome'], 'perche' => $t['perche'], 'dal' => $t['dal'],
                        'fino_al' => $t['fino_al'], 'dal_come' => $t['dal_come'] ?? null];
                    if ($t['fino_al'] === null) {
                        $tutti = false;
                    } elseif ($ultimo === null || $t['fino_al'] > $ultimo) {
                        $ultimo = $t['fino_al'];
                    }
                }
            }
            $uscita[] = ['delibera_id' => (int) $p['delibera_id'], 'punto' => (int) $p['numero'], 'votazione' => (int) ($p['votazione'] ?? 1),
                'oggetto' => $p['oggetto'] ?? null, 'esito' => (string) $p['esito'], 'deliberata_il' => (string) $p['deliberata_il'], 'chi' => $chi,
                'per_tutti' => $tutti ? $ultimo : null];
        }

        return $uscita;
    }

    /** Il giorno da cui i trenta giorni sono passati per tutti, per tutte le delibere del verbale; null se una persona non ha ancora la sua data. */
    public function perTuttiIlVerbale(VerbaleDellAssemblea $verbale): ?string
    {
        $termini = $this->perIlVerbale($verbale);
        if ($termini === []) {
            return null;
        }
        $ultimo = null;
        foreach ($termini as $t) {
            if ($t['per_tutti'] === null && $t['chi'] !== []) {
                return null;
            }
            if ($t['per_tutti'] !== null && ($ultimo === null || $t['per_tutti'] > $ultimo)) {
                $ultimo = $t['per_tutti'];
            }
        }

        return $ultimo ?? $termini[0]['deliberata_il'];
    }

    /**
     * I termini di una delibera, dal verbale registrato della sua riunione; null se non c'è. Quando nessuno può impugnarla (`chi` vuoto: tutti presenti e
     * favorevoli) il giorno è quello della delibera, come in {@see perTuttiIlVerbale()}, e `nessuno` lo dice: «La data non c'è» si leggerebbe come un verbale
     * mai consegnato.
     *
     * **Senza termine** (Coda 750 B1): il punto né approvato né respinto (rinviato, trattato senza voto) non ha niente da impugnare. Non è un verbale che manca:
     * `senza_termine` lo dice, con l'esito, e `per_tutti` resta vuoto.
     *
     * @return ?array<string, mixed>
     */
    public function perLaDelibera(Delibera $delibera): ?array
    {
        if (! in_array($delibera->esito, [EsitoDelibera::APPROVATA, EsitoDelibera::APPROVATA_CON_MODIFICHE, EsitoDelibera::RESPINTA], true)) {
            return ['delibera_id' => (int) $delibera->id, 'punto' => $delibera->punto?->numero, 'esito' => $delibera->esito?->value,
                'deliberata_il' => $delibera->deliberata_il?->format('Y-m-d'), 'chi' => [], 'per_tutti' => null, 'nessuno' => false, 'senza_termine' => true];
        }
        $numero = VerbaliDellAssemblea::numeroDelGiorno((int) $delibera->assemblea_id, $delibera->deliberata_il?->format('Y-m-d'));
        if ($numero === null) {
            return null;
        }
        $verbale = VerbaleDellAssemblea::query()->where('assemblea_id', $delibera->assemblea_id)->where('numero', $numero)->first();
        $termini = $verbale === null ? null : collect($this->perIlVerbale($verbale))->firstWhere('delibera_id', (int) $delibera->id);
        if ($termini === null) {
            return null;
        }
        $nessuno = $termini['chi'] === [];

        return ['per_tutti' => $nessuno && $termini['per_tutti'] === null ? $termini['deliberata_il'] : $termini['per_tutti'], 'nessuno' => $nessuno,
            'senza_termine' => false] + $termini;
    }

    /** «Segna impugnata»: il giorno e la nota sulla delibera, e l'atto fra i documenti dell'assemblea. Non ferma niente. */
    public function segnaImpugnata(Assemblea $assemblea, Delibera $delibera, string $giorno, string $nota, ?UploadedFile $atto, User $utente): Delibera
    {
        if ((int) $delibera->assemblea_id !== (int) $assemblea->id) {
            abort(404);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $giorno) !== 1 || $giorno > \App\Helpers\DateHelper::oggiUtente()
            || ($delibera->deliberata_il !== null && $giorno < $delibera->deliberata_il->format('Y-m-d'))) {
            throw ValidationException::withMessages(['giorno' => 'Il giorno dell\'impugnazione è fra il giorno della delibera e oggi.']);
        }
        if (trim($nota) === '') {
            throw ValidationException::withMessages(['nota' => 'Scrivi chi ha impugnato e davanti a chi: resta sulla delibera.']);
        }
        if ($atto !== null) {
            RegistrazioneDelVerbale::controllaLaCopia($atto, 'atto');
        }
        $percorso = null;
        try {
            return RegistroModifiche::durante(\App\Services\RegistroModifiche\GestiDelRegistro::deliberaImpugnata($delibera, $giorno, trim($nota)),
                function () use ($assemblea, $delibera, $giorno, $nota, $atto, $utente, &$percorso) {
                    $delibera->update(['impugnata_il' => $giorno, 'impugnata_nota' => trim($nota)]);
                    if ($atto !== null) {
                        $percorso = app(ArchivioDocumenti::class)->salva($atto);
                        $assemblea->documenti()->create(['name' => $atto->getClientOriginalName(), 'description' => 'L\'impugnazione della delibera del '
                            . $delibera->deliberata_il?->format('d/m/Y') . ($delibera->punto?->numero !== null ? ', punto ' . $delibera->punto->numero : ''),
                            'path' => $percorso, 'mime_type' => (string) $atto->getMimeType(), 'file_size' => (int) $atto->getSize(), 'created_by' => $utente->id,
                            'is_published' => false, 'is_approved' => true]);
                    }

                    return $delibera->refresh();
                });
        } catch (\Throwable $e) {
            if ($percorso !== null) {
                app(ArchivioDocumenti::class)->elimina($percorso);
            }
            throw $e;
        }
    }
}
