<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support\Comunicazioni;

use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\ComunicazioneLettura;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LettureComunicazione
{
    /**
     * Registra la prima apertura di una comunicazione da parte di un destinatario nel portale.
     *
     * Nota E4 della decisione 313: un errore qui (il registro delle letture che non si scrive) non fa cadere la pagina che il condòmino sta aprendo;
     * va nel registro degli errori con `report()`, e la comunicazione si apre lo stesso.
     */
    public static function registraApertura(Comunicazione $comunicazione, ?User $user, ?Request $request = null): void
    {
        try {
            self::scriviApertura($comunicazione, $user, $request);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function scriviApertura(Comunicazione $comunicazione, ?User $user, ?Request $request): void
    {
        if ($user === null) {
            return;
        }

        // Prima di tutto guarda l'impostazione generale: spenta, non fa richieste al database.
        if (! (bool) app(GeneralSettings::class)->comunicazioni_registra_aperture) {
            return;
        }

        $request = $request ?? request();
        if (strtolower((string) $request->header('Purpose')) === 'prefetch') {
            return;
        }

        if (! self::isDestinatario($comunicazione, $user)) {
            return;
        }

        $adesso = now();

        $riga = ComunicazioneLettura::firstOrCreate(
            [
                'comunicazione_id' => $comunicazione->id,
                'user_id' => $user->id,
            ],
            [
                'aperta_il' => $adesso,
            ]
        );

        if ($riga->aperta_il === null) {
            ComunicazioneLettura::where('id', $riga->id)
                ->whereNull('aperta_il')
                ->update(['aperta_il' => $adesso]);
        }
    }

    /**
     * Registra la presa visione di una comunicazione da parte di un destinatario nel portale.
     */
    public static function confermaPresaVisione(Comunicazione $comunicazione, User $user): void
    {
        $adesso = now();

        $riga = ComunicazioneLettura::firstOrCreate(
            [
                'comunicazione_id' => $comunicazione->id,
                'user_id' => $user->id,
            ],
            [
                'presa_visione_il' => $adesso,
            ]
        );

        if ($riga->presa_visione_il === null) {
            ComunicazioneLettura::where('id', $riga->id)
                ->whereNull('presa_visione_il')
                ->update(['presa_visione_il' => $adesso]);
        }
    }

    /**
     * I dati della lettura personale per la scheda del portale.
     *
     * @return array{apertura: ?array{il: string, alle: string}, puo_confermare: bool, presa_visione: ?array{il: string, alle: string}}
     */
    public static function perPortale(Comunicazione $comunicazione, ?User $user): array
    {
        if ($user === null || ($comunicazione->created_by !== null && (int) $user->id === (int) $comunicazione->created_by)) {
            return [
                'apertura' => null,
                'puo_confermare' => false,
                'presa_visione' => null,
            ];
        }

        $riga = ComunicazioneLettura::where('comunicazione_id', $comunicazione->id)
            ->where('user_id', $user->id)
            ->first();

        $haAperta = $riga !== null && $riga->aperta_il !== null;
        $haPresaVisione = $riga !== null && $riga->presa_visione_il !== null;

        $puoConfermare = (bool) $comunicazione->chiedi_presa_visione
            && ! $haPresaVisione
            && self::isDestinatario($comunicazione, $user);

        return [
            'apertura' => $haAperta ? self::formattaDataOra($riga->aperta_il) : null,
            'puo_confermare' => $puoConfermare,
            'presa_visione' => $haPresaVisione ? self::formattaDataOra($riga->presa_visione_il) : null,
        ];
    }

    /**
     * I dati del riepilogo e dell'elenco delle letture per la scheda del pannello.
     *
     * @return array{registra_aperture: bool, chiedi_presa_visione: bool, attive: bool, riassunto: ?array{destinatari: int, senza_accesso: int, aperte: int, prese_visione: int}, righe: array<int, array{anagrafica_id: int, nome: string, stato: string, aperta: ?array{il: string, alle: string}, presa_visione: ?array{il: string, alle: string}, manca: bool}>}
     */
    public static function perPannello(Comunicazione $comunicazione): array
    {
        $registraAperture = (bool) app(GeneralSettings::class)->comunicazioni_registra_aperture;
        $chiediPresaVisione = (bool) $comunicazione->chiedi_presa_visione;

        $attive = $registraAperture
            || $chiediPresaVisione
            || ComunicazioneLettura::where('comunicazione_id', $comunicazione->id)->exists();

        if (! $attive) {
            return [
                'registra_aperture' => $registraAperture,
                'chiedi_presa_visione' => $chiediPresaVisione,
                'attive' => false,
                'riassunto' => null,
                'righe' => [],
            ];
        }

        $anagraficheIds = $comunicazione->anagraficheCheLaVedono();
        if ($anagraficheIds->isEmpty()) {
            return [
                'registra_aperture' => $registraAperture,
                'chiedi_presa_visione' => $chiediPresaVisione,
                'attive' => true,
                'riassunto' => [
                    'destinatari' => 0,
                    'senza_accesso' => 0,
                    'aperte' => 0,
                    'prese_visione' => 0,
                ],
                'righe' => [],
            ];
        }

        // Nota E3 della decisione 313: delle persone servono solo il nome e l'account, non tutta la rubrica.
        $query = Anagrafica::query()
            ->select(['id', 'nome', 'user_id'])
            ->with('user:id,suspended_at')
            ->whereIn('id', $anagraficheIds);

        if ($comunicazione->created_by !== null) {
            $query->where(function ($q) use ($comunicazione) {
                $q->whereNull('user_id')
                    ->orWhere('user_id', '!=', $comunicazione->created_by);
            });
        }

        $anagrafiche = $query->get()
            ->sortBy(fn ($a) => mb_strtolower($a->nome ?? ''), SORT_STRING)
            ->values();

        $lettureDb = ComunicazioneLettura::where('comunicazione_id', $comunicazione->id)
            ->get()
            ->keyBy('user_id');

        $righe = [];
        $totSenzaAccesso = 0;
        $totAperte = 0;
        $totPreseVisione = 0;

        foreach ($anagrafiche as $anagrafica) {
            $userId = $anagrafica->user_id;
            $userModel = $anagrafica->user;
            $lettura = $userId !== null ? $lettureDb->get($userId) : null;

            $haAperta = $lettura !== null && $lettura->aperta_il !== null;
            $haPresaVisione = $lettura !== null && $lettura->presa_visione_il !== null;

            if ($haPresaVisione) {
                $stato = 'presa_visione';
            } elseif ($haAperta) {
                $stato = 'aperta';
            } elseif ($userModel === null || $userModel->suspended_at !== null) {
                $stato = 'senza_accesso';
            } elseif ($chiediPresaVisione && ! $registraAperture) {
                $stato = 'non_confermata';
            } else {
                $stato = 'non_aperta';
            }

            if ($chiediPresaVisione) {
                $manca = ! $haPresaVisione;
            } else {
                $manca = ! $haAperta;
            }

            $apertaFormatted = $haAperta ? self::formattaDataOra($lettura->aperta_il) : null;
            $presaVisioneFormatted = $haPresaVisione ? self::formattaDataOra($lettura->presa_visione_il) : null;

            if ($stato === 'senza_accesso') {
                $totSenzaAccesso++;
            }
            if ($apertaFormatted !== null) {
                $totAperte++;
            }
            if ($presaVisioneFormatted !== null) {
                $totPreseVisione++;
            }

            $righe[] = [
                'anagrafica_id' => $anagrafica->id,
                'nome' => $anagrafica->nome,
                'stato' => $stato,
                'aperta' => $apertaFormatted,
                'presa_visione' => $presaVisioneFormatted,
                'manca' => $manca,
            ];
        }

        return [
            'registra_aperture' => $registraAperture,
            'chiedi_presa_visione' => $chiediPresaVisione,
            'attive' => true,
            'riassunto' => [
                'destinatari' => count($righe),
                'senza_accesso' => $totSenzaAccesso,
                'aperte' => $totAperte,
                'prese_visione' => $totPreseVisione,
            ],
            'righe' => $righe,
        ];
    }

    /**
     * Verifica se un utente è destinatario della comunicazione.
     */
    public static function isDestinatario(Comunicazione $comunicazione, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($comunicazione->created_by !== null && (int) $user->id === (int) $comunicazione->created_by) {
            return false;
        }

        if (! $comunicazione->is_published || ! $comunicazione->is_approved) {
            return false;
        }

        $anagraficheVisibili = $comunicazione->anagraficheCheLaVedono();
        if ($anagraficheVisibili->isEmpty()) {
            return false;
        }

        $anagraficaIds = Anagrafica::where('user_id', $user->id)->pluck('id');
        if ($anagraficaIds->isEmpty()) {
            return false;
        }

        return $anagraficheVisibili->intersect($anagraficaIds)->isNotEmpty();
    }

    /**
     * Formatta una data e un'ora nel fuso dell'installazione.
     *
     * @return array{il: string, alle: string}|null
     */
    public static function formattaDataOra(mixed $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $carbon = $data instanceof Carbon ? $data : Carbon::parse($data);
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');

        return [
            'il' => $carbon->copy()->setTimezone($fuso)->format('d/m/Y'),
            'alle' => $carbon->copy()->setTimezone($fuso)->format('H:i'),
        ];
    }

    /**
     * Toglie aperta_il da tutte le righe e cancella quelle che restano senza date.
     *
     * Nota E1 della decisione 313: le due scritture in una transazione, perché un errore fra l'una e l'altra non lasci le aperture tolte a metà.
     */
    public static function cancellaAperture(): void
    {
        DB::transaction(function () {
            ComunicazioneLettura::whereNotNull('aperta_il')->update(['aperta_il' => null]);
            ComunicazioneLettura::whereNull('aperta_il')->whereNull('presa_visione_il')->delete();
        });
    }
}
