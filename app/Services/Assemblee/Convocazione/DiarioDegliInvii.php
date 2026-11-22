<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Models\Assemblee\Assemblea;
use App\Models\Gestionale\RegistroInvio;
use Carbon\Carbon;

/**
 * Le righe degli invii nel «Diario» dell'assemblea (§6.4 del progetto della tappa 2, la terza fonte): i fatti della spedizione scritti in `dati.eventi` delle
 * righe del registro degli invii con l'assemblea come documento («Raccomandata a Neri Carla: spedita il 04/12, n. 61 2345 6789»), le prove allegate e le PEC
 * non partite, **senza ripetere ciò che sta già nel registro delle modifiche**: la convocazione spedita, gli esiti e le loro correzioni, il foglio delle
 * consegne a mano. Ogni riga ha la forma di {@see \App\Services\Assemblee\DiarioDellAssemblea::RIGA}; la fonte e il badge «Invio» li aggiunge il diario.
 * Chi ha fatto il fatto è chi l'ha registrato; «Il programma» per ciò che fa da solo (la PEC che il gestore non ha accettato).
 */
class DiarioDegliInvii
{
    private const MEZZI = ['pec' => 'PEC', 'raccomandata' => 'Raccomandata', 'a_mano' => 'Avviso a mano', 'fax' => 'Fax'];

    /**
     * Le righe dalla più recente, prima dell'istante `$dopo` (il cursore di «Mostra altre», ISO 8601), al più `$quante`.
     *
     * @return list<array<string, mixed>>
     */
    public function righe(Assemblea $assemblea, ?string $dopo = null, int $quante = 30): array
    {
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $righe = [];
        $invii = RegistroInvio::query()->with('emessoDa:id,name')->where('documentabile_type', $assemblea->getMorphClass())
            ->where('documentabile_id', $assemblea->id)->whereIn('tipo', RegistroInvio::TIPI_DELL_ASSEMBLEA)->orderBy('id')->get();
        foreach ($invii as $invio) {
            $chi = (string) ($invio->dati['persona']['nome'] ?? 'la persona');
            $mezzo = self::MEZZI[$invio->dati['mezzo'] ?? ''] ?? 'Avviso';
            // La trasmissione del verbale: «Verbale n. 42, raccomandata a Mancini Rosa: spedita il 16/12».
            if ($invio->tipo === RegistroInvio::TIPO_VERBALE) {
                $mezzo = 'Verbale n. ' . ($invio->dati['numero_verbale'] ?? '') . ', ' . (($invio->dati['mezzo'] ?? '') === 'pec' ? 'PEC' : mb_strtolower($mezzo));
            }
            if ($invio->stato === RegistroInvio::STATO_NON_PARTITA) {
                $righe[] = $this->riga($invio->updated_at ?? $invio->emesso_il, 'Il programma', 'PEC a ' . $chi . ': non partita, il gestore non l\'ha accettata', $fuso);
            }
            foreach ((array) ($invio->dati['eventi'] ?? []) as $e) {
                $frase = match ($e['evento'] ?? null) {
                    'spedizione' => $mezzo . ' a ' . $chi . ': spedita il ' . Carbon::parse((string) $e['data'])->format('d/m') . (filled($e['numero'] ?? null) ? ', n. ' . $e['numero'] : ''),
                    'spedizione_corretta' => 'Spedizione dell\'avviso a ' . $chi . ' corretta: ' . Carbon::parse((string) ($e['prima']['data'] ?? $e['data']))->format('d/m')
                        . ' → ' . Carbon::parse((string) $e['data'])->format('d/m'),
                    // «Allega la prova» ha la sua riga nel registro delle modifiche: il fatto segnato `nel_registro` non si ripete.
                    'prova_allegata' => ($e['nel_registro'] ?? false) ? null : 'Prova allegata all\'avviso a ' . $chi . ': ' . ($e['nome_file'] ?? 'un file'),
                    default => null,
                };
                if ($frase !== null && isset($e['il'])) {
                    $righe[] = $this->riga(Carbon::parse((string) $e['il']), (string) ($e['da_nome'] ?? 'Il programma'), $frase, $fuso);
                }
            }
        }
        usort($righe, fn ($a, $b) => strcmp((string) $b['istante'], (string) $a['istante']));
        if ($dopo !== null) {
            $righe = array_values(array_filter($righe, fn ($r) => strcmp((string) $r['istante'], $dopo) < 0));
        }

        return array_slice($righe, 0, max(1, $quante));
    }

    /** @return array{istante: string, giorno: string, ora: string, chi: string, frase: string} */
    private function riga(\DateTimeInterface $quando, string $chi, string $frase, string $fuso): array
    {
        $q = Carbon::instance($quando)->utc();
        $letto = $q->copy()->setTimezone($fuso);

        return ['istante' => $q->toIso8601String(), 'giorno' => $letto->format('d/m/Y'), 'ora' => $letto->format('H:i'), 'chi' => $chi, 'frase' => $frase];
    }
}
