<?php

namespace App\Actions\PianoRate;

use App\Enums\RuoloAnagraficaImmobile;
use App\Exceptions\Gestionale\SaldoSolidaleSenzaTitolareException;
use App\Helpers\MoneyHelper;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Saldo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateSaldiAction
{
    /**
     * @return array Formato: [ anagrafica_id => [ immobile_id => [ 'importo' => X, 'meta_storico' => [...] ] ] ]
     */
    public function execute(PianoRate $pianoRate, Gestione $gestione, array $saldiConfig = []): array
    {
        // Un saldo è disponibile per questo piano se è libero, OPPURE se è già
        // intestato proprio a questo piano: senza il secondo caso, ricalcolare
        // un piano che aveva assorbito i pregressi li faceva sparire dalle
        // quote (il piano ripartiva dal solo preventivo) e il lucchetto restava
        // chiuso senza più nessuno in grado di riaprirlo.
        $saldiDaApplicare = Saldo::where('gestione_id', $gestione->id)
            // I debiti verso fornitori vivono nella stessa tabella e non devono
            // MAI essere candidati a un piano rate. Oggi ne restano fuori perché
            // nascono già bloccati: è un'invariante non scritta nello schema, e
            // quindi non è una difesa.
            ->whereNull('fornitore_id')
            ->where(function ($q) use ($pianoRate) {
                $q->where('is_applicato', false)
                  ->orWhere('piano_rate_id', $pianoRate->id);
            })
            ->where('saldo_iniziale', '!=', 0)
            ->get();

        if ($saldiDaApplicare->isEmpty()) {
            return [];
        }

        $distribuzione = [];
        $saldiConfigMap = collect($saldiConfig)->keyBy('saldo_id');

        foreach ($saldiDaApplicare as $saldo) {
            
            // CASO A: Saldo Nominale (Ha già un'anagrafica assegnata)
            if ($saldo->anagrafica_id !== null) {
                $this->assegnaQuota(
                    $distribuzione,
                    $saldo->anagrafica_id,
                    $saldo->immobile_id,
                    $saldo->saldo_iniziale, // Importo in centesimi dal DB
                    $this->creaMeta($saldo, 'nominale')
                );
                continue;
            }

            // CASO B: Saldo Solidale (Art. 63) - anagrafica_id è NULL
            $configCustom = $saldiConfigMap->get($saldo->id);

            if ($configCustom && !empty($configCustom['ripartizioni'])) {
                // B1. Riparto Manuale (L'utente ha forzato le quote da Vue)

                // Il verso lo detta la FONTE, non quello che è arrivato dal form. La casella
                // del riparto manuale ha `disableNegative: true` e mostra il totale da
                // distribuire in valore assoluto (`PianiRateNew.vue:122` e `:1287`): il meno
                // l'utente non può digitarlo, ed è giusto così. Ma nessuno lo rimetteva —
                // `CreatePianoRateRequest` valida l'importo come stringa senza vincoli di
                // segno, `MoneyHelper::toCents()` di una stringa positiva fa un intero
                // positivo, e qui il valore veniva usato com'era. Un credito dell'unità da
                // 600,00 € diviso fra due comproprietari usciva come **due debiti da
                // 300,00 €**: non un importo storto, il verso opposto.
                $segno = $saldo->saldo_iniziale < 0 ? -1 : 1;

                foreach ($configCustom['ripartizioni'] as $rip) {
                    $this->assegnaQuota(
                        $distribuzione,
                        $rip['anagrafica_id'],
                        $saldo->immobile_id,
                        // CENTESIMI, non euro. Il chiamante ha già convertito con
                        // MoneyHelper::toCents (PianoRateController::store), l'unico
                        // che sa leggere la stringa mascherata "1.200,50" del form.
                        // Il vecchio `* 100` qui la convertiva una seconda volta:
                        // un riparto manuale di 250,00 € addebitava 25.000,00 €.
                        $segno * abs((int) $rip['importo']),
                        $this->creaMeta($saldo, 'solidale_manuale')
                    );
                }
            } else {
                // B2. Riparto Automatico sui titolari di un diritto reale, scelti per natura
                // della gestione — vedi `risolviTitolari()` per la regola e il perché.
                [$ruoloRisolto, $titolari] = $this->risolviTitolari($saldo, $gestione);

                $quote = MoneyHelper::ripartisciPerQuote(
                    $saldo->saldo_iniziale,
                    $titolari->pluck('quota', 'anagrafica_id')
                        ->map(fn ($quota): float => (float) $quota)
                        ->all()
                );

                foreach ($titolari as $titolare) {
                    $importoProQuota = $quote[$titolare->anagrafica_id] ?? 0;

                    // Uno zero può essere legittimo — un centesimo fra tre persone ne lascia
                    // due a mani vuote — e una quota da zero non va scritta.
                    if ($importoProQuota !== 0) {
                        $this->assegnaQuota(
                            $distribuzione,
                            $titolare->anagrafica_id,
                            $saldo->immobile_id,
                            $importoProQuota,
                            $this->creaMeta($saldo, 'solidale_automatico', (float) $titolare->quota, $ruoloRisolto)
                        );
                    }
                }
            }
        }

        Log::info("Saldi elaborati e distribuiti su " . count($distribuzione) . " anagrafiche.");
        return $distribuzione;
    }

    /**
     * Chi pagherebbe cosa, se il piano venisse generato adesso. **Sola lettura.**
     *
     * Serve all'anteprima nella creazione del piano: fino alla beta.43 il riparto automatico
     * di un saldo solidale era invisibile, e l'amministratore scopriva chi era stato addebitato
     * solo dopo aver generato. Non è un dettaglio di comodità — è il requisito di trasparenza
     * del §4 di `cascata_risoluzione_ruolo_coerenza_ruoli.md`: *quello che l'amministratore
     * legge è quello che viene addebitato*, e senza vederlo il riparto manuale non è una scelta
     * ma un ripiego di chi ha già sbagliato una volta.
     *
     * Passa **dalle stesse funzioni** del generatore, `risolviTitolari()` e
     * `MoneyHelper::ripartisciPerQuote()`. Non è una scorciatoia: un'anteprima che ricalcola a
     * modo suo diverge dal calcolo autoritativo al primo cambio, ed è il difetto che nella
     * beta.35 è costato un centesimo sul netto da pagare.
     *
     * A differenza della generazione **non solleva**: la catena esaurita qui non è un errore
     * ma la cosa più utile da mostrare, perché si è ancora in tempo per censire il proprietario
     * mancante invece di scoprirlo a piano emesso.
     *
     * @return array{risolvibile: bool, ruolo: ?string, ruolo_label: ?string, motivo: ?string, quote: array<int, array{anagrafica_id: int, nome: string, quota: float, importo: int}>}
     */
    public function anteprimaSolidale(Saldo $saldo, Gestione $gestione): array
    {
        try {
            [$ruolo, $titolari] = $this->risolviTitolari($saldo, $gestione);
        } catch (SaldoSolidaleSenzaTitolareException $e) {
            return [
                'risolvibile' => false,
                'ruolo' => null,
                'ruolo_label' => null,
                'motivo' => $e->getMessage(),
                'quote' => [],
            ];
        }

        $importi = MoneyHelper::ripartisciPerQuote(
            $saldo->saldo_iniziale,
            $titolari->pluck('quota', 'anagrafica_id')->map(fn ($quota): float => (float) $quota)->all()
        );

        $nomi = DB::table('anagrafiche')
            ->whereIn('id', $titolari->pluck('anagrafica_id'))
            ->pluck('nome', 'id');

        return [
            'risolvibile' => true,
            'ruolo' => $ruolo,
            'ruolo_label' => RuoloAnagraficaImmobile::from($ruolo)->label(),
            'motivo' => null,
            'quote' => $titolari->map(fn ($titolare): array => [
                'anagrafica_id' => (int) $titolare->anagrafica_id,
                'nome' => $nomi[$titolare->anagrafica_id] ?? "#{$titolare->anagrafica_id}",
                'quota' => (float) $titolare->quota,
                'importo' => $importi[$titolare->anagrafica_id] ?? 0,
            ])->values()->all(),
        ];
    }

    private function assegnaQuota(array &$distribuzione, int $anagraficaId, ?int $immobileId, int $importo, array $meta): void
    {
        $immobileKey = $immobileId ?? 0; // Usiamo 0 come fallback se il saldo non è legato a un immobile

        if (!isset($distribuzione[$anagraficaId])) {
            $distribuzione[$anagraficaId] = [];
        }
        
        if (!isset($distribuzione[$anagraficaId][$immobileKey])) {
            // FIX: Inizializziamo esplicitamente 'importo' a 0 prima di sommarlo
            $distribuzione[$anagraficaId][$immobileKey] = [
                'importo' => 0,
                'meta_storico' => []
            ];
        }

        // FIX: Sommiamo l'importo calcolato alla chiave corretta
        $distribuzione[$anagraficaId][$immobileKey]['importo'] += $importo;
        $distribuzione[$anagraficaId][$immobileKey]['meta_storico'][] = $meta;
    }

    /**
     * Chi si fa carico del pregresso intestato all'unità, e con che ruolo.
     *
     * Un saldo solidale (art. 63 disp. att. c.c.) è un debito **dell'unità**: il condominio lo
     * riscuote da chi su quell'unità ha un diritto reale. L'inquilino non è mai fra questi —
     * il suo rapporto è con il locatore (gli oneri accessori dell'art. 9 L. 392/1978), e il
     * condominio non ha titolo per escuterlo. Fino alla beta.43 questa query non guardava
     * affatto il ruolo: prendeva ogni occupante attivo, addebitava l'inquilino e **diluiva la
     * quota del proprietario**, perché il denominatore sommava anche i millesimi di chi non
     * doveva pagare. Misurato: su un pregresso di 1.000,00 € con proprietario e inquilino
     * entrambi censiti, il proprietario ne pagava 500,00 e l'inquilino gli altri 500,00.
     *
     * Fra i titolari decide la **natura della gestione** da cui il pregresso proviene: le
     * spese di ordinaria amministrazione sono dell'usufruttuario (art. 1004 c.c.), le
     * riparazioni straordinarie del proprietario (art. 1005 c.c.). La catena sta in
     * `RuoloAnagraficaImmobile::catenaSaldoSolidale()`, che è anche l'unico posto in cui
     * questa regola è scritta.
     *
     * **È un default, non un obbligo.** Verso il condominio usufruttuario e nudo proprietario
     * rispondono in solido (art. 67 co. 8 disp. att. c.c.): questa è la ripartizione interna
     * fra loro, che un patto può avere deciso altrimenti. Per quel caso c'è il riparto
     * manuale — il ramo B1 — e per questo la catena non va irrigidita in una guardia.
     *
     * @return array{0: string, 1: \Illuminate\Support\Collection}
     */
    private function risolviTitolari(Saldo $saldo, Gestione $gestione): array
    {
        $occupanti = DB::table('anagrafica_immobile')
            ->where('immobile_id', $saldo->immobile_id)
            ->where('attivo', true)
            ->get();

        foreach (RuoloAnagraficaImmobile::catenaSaldoSolidale($gestione->tipo) as $ruolo) {
            $titolari = $occupanti->where('tipologia', $ruolo->value)->values();

            if ($titolari->isNotEmpty()) {
                return [$ruolo->value, $titolari];
            }
        }

        // Catena esaurita. Prima di adesso qui non succedeva niente: la collection era vuota,
        // il `foreach` non girava, e il saldo spariva dalla distribuzione **senza eccezione e
        // senza log** — mentre il lucchetto si chiudeva lo stesso e la gestione registrava un
        // importo «processato» che non esisteva in nessuna quota. L'ADR-001 lo prevedeva
        // bloccante fin dalla prima stesura; non era mai stato scritto.
        $nomeImmobile = DB::table('immobili')->where('id', $saldo->immobile_id)->value('nome')
            ?? "#{$saldo->immobile_id}";

        Log::warning('GenerateSaldiAction: saldo solidale senza titolari di diritto reale.', [
            'saldo_id' => $saldo->id,
            'immobile_id' => $saldo->immobile_id,
            'gestione_tipo' => $gestione->tipo,
            'importo_cents' => $saldo->saldo_iniziale,
            'ruoli_attivi' => $occupanti->pluck('tipologia')->unique()->values()->all(),
        ]);

        throw new SaldoSolidaleSenzaTitolareException(
            $saldo->id,
            $saldo->immobile_id,
            $nomeImmobile,
            $saldo->saldo_iniziale
        );
    }

    private function creaMeta(
        Saldo $saldo,
        string $tipoRiparto,
        ?float $quotaUsata = null,
        ?string $ruoloRisolto = null
    ): array {
        return [
            'saldo_origine_id' => $saldo->id,
            'gestione_origine_id' => $saldo->gestione_id,
            'tipo_riparto' => $tipoRiparto,
            'importo_originale' => $saldo->saldo_iniziale,
            'quota_applicata' => $quotaUsata,
            // Congelato nel riparto, non ricalcolato a runtime: è il requisito di trasparenza
            // del §4 di `cascata_risoluzione_ruolo_coerenza_ruoli.md`. Quello che
            // l'amministratore ha letto nell'anteprima è quello che è stato addebitato.
            'ruolo_risolto' => $ruoloRisolto,
        ];
    }
}