<?php

namespace App\Console\Commands;

use App\Enums\RuoloAnagraficaImmobile;
use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnosi dei riparti automatici dei saldi solidali già emessi — **sola lettura**.
 *
 * La beta.43 ha corretto `GenerateSaldiAction`, che ripartiva il pregresso intestato
 * all'unità (art. 63 disp. att. c.c.) fra **tutti** gli occupanti attivi, senza guardare il
 * ruolo: l'inquilino riceveva una quota che non gli spetta — verso il condominio non è
 * debitore — e il proprietario ne pagava meno del dovuto, perché il denominatore sommava
 * anche i millesimi di chi non doveva pagare.
 *
 * La correzione vale per i piani **futuri**. Le quote già generate sono snapshot a database:
 * nessun ricalcolo le tocca, e chi ha già emesso resta con quegli addebiti addosso senza
 * modo di saperlo. Questo comando è quel modo.
 *
 * ## Perché non ripara
 *
 * Correggere significa rigenerare le quote di un piano **già emesso**, cioè toccare scritture
 * contabili. La strada esiste ed è quella che il gestionale già offre — annulla l'emissione,
 * ricalcola, riemetti — ed è una decisione dell'amministratore, non di un comando che gira
 * di notte. Qui si dice **se** e **dove**: piano, unità, persona, ruolo attuale, importo.
 *
 * Emette anche i riparti che non quadrano al centesimo: fino alla beta.43 ogni quota usciva
 * da un `round()` indipendente, senza redistribuzione del resto, quindi la somma poteva non
 * coincidere col saldo di partenza.
 */
class VerificaSaldiSolidaliCommand extends Command
{
    protected $signature = 'kondomanager:verifica-saldi-solidali
                            {--condominio= : ID del condominio (omesso = tutti)}';

    protected $description = 'Elenca i riparti automatici di saldi solidali già emessi che la beta.43 avrebbe fatto diversamente. Non modifica nulla.';

    public function handle(): int
    {
        $condomini = $this->option('condominio')
            ? Condominio::where('id', $this->option('condominio'))->get()
            : Condominio::all();

        if ($condomini->isEmpty()) {
            $this->error('Nessun condominio trovato.');

            return self::FAILURE;
        }

        $totaleSegnalazioni = 0;

        foreach ($condomini as $condominio) {
            $righe = $this->analizza($condominio);

            if ($righe === []) {
                $this->line("«{$condominio->nome}»: nessun riparto solidale da ricontrollare.");

                continue;
            }

            $totaleSegnalazioni += count($righe);

            $this->newLine();
            $this->warn("«{$condominio->nome}» — " . count($righe) . ' addebiti da ricontrollare:');
            $this->table(
                ['Piano', 'Unità', 'Persona', 'Ruolo oggi', 'Addebitato', 'Perché'],
                $righe
            );

            // Righe corte di proposito: a terminale una frase lunga va a capo dove capita.
            $this->line('  inquilino non debitore — rapporto col locatore, non col condominio');
            $this->line('  art. 1005 — le straordinarie restano del proprietario');
            $this->line('  art. 1004 — le ordinarie sono dell\'usufruttuario');
            $this->line('  non più attivo — verifica il subentro sull\'unità');
        }

        $this->newLine();

        if ($totaleSegnalazioni === 0) {
            $this->info('Nessun riparto da ricontrollare. Niente da fare.');

            return self::SUCCESS;
        }

        $this->warn("Totale: {$totaleSegnalazioni} addebiti da ricontrollare.");
        $this->line('Nessun dato è stato modificato. Per correggere un piano: annulla le emissioni,');
        $this->line('ricalcola il piano e riemetti — le quote si rigenerano con la regola corretta.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function analizza(Condominio $condominio): array
    {
        $righe = [];
        $distribuitoPerSaldo = [];
        $contestoSaldo = [];

        // Le quote portano il riparto nel loro snapshot JSON. Si scorre in streaming e si
        // decodifica in PHP invece di interrogare il JSON in SQL: la struttura ha più forme
        // storiche e il comportamento delle funzioni JSON diverge fra i driver — la stessa
        // ragione per cui `PianoRateQuoteService` non usa un `exists()` sul JSON.
        $quote = DB::table('rate_quote')
            ->join('rate', 'rate_quote.rata_id', '=', 'rate.id')
            ->join('piani_rate', 'rate.piano_rate_id', '=', 'piani_rate.id')
            ->join('gestioni', 'piani_rate.gestione_id', '=', 'gestioni.id')
            ->where('piani_rate.condominio_id', $condominio->id)
            ->whereNotNull('rate_quote.regole_calcolo')
            ->select([
                'rate_quote.anagrafica_id',
                'rate_quote.immobile_id',
                'rate_quote.importo',
                'rate_quote.regole_calcolo',
                'piani_rate.nome as piano',
                'gestioni.tipo as tipo_gestione',
            ])
            ->cursor();

        foreach ($quote as $quota) {
            $snapshot = json_decode((string) $quota->regole_calcolo, true);

            foreach ($snapshot['dettagli_saldo'] ?? [] as $meta) {
                if (($meta['tipo_riparto'] ?? null) !== 'solidale_automatico') {
                    continue;
                }

                $saldoId = $meta['saldo_origine_id'] ?? null;
                if ($saldoId === null) {
                    continue;
                }

                // Serve per il controllo di quadratura, più sotto.
                $distribuitoPerSaldo[$saldoId] = ($distribuitoPerSaldo[$saldoId] ?? 0) + (int) $quota->importo;
                $contestoSaldo[$saldoId] ??= [
                    'piano' => $quota->piano,
                    'originale' => (int) ($meta['importo_originale'] ?? 0),
                ];

                $ruolo = $this->ruoloAttuale($quota->anagrafica_id, $quota->immobile_id);
                $motivo = $this->motivoDelSospetto($ruolo, (string) $quota->tipo_gestione);

                if ($motivo === null) {
                    continue;
                }

                $righe[] = [
                    $quota->piano,
                    $this->nomeImmobile($quota->immobile_id),
                    $this->nomeAnagrafica($quota->anagrafica_id),
                    $ruolo !== null ? RuoloAnagraficaImmobile::from($ruolo)->label() : 'non più censito',
                    MoneyHelper::format((int) $quota->importo),
                    $motivo,
                ];
            }
        }

        foreach ($distribuitoPerSaldo as $saldoId => $distribuito) {
            $originale = $contestoSaldo[$saldoId]['originale'] ?? 0;

            if ($originale === 0 || $distribuito === $originale) {
                continue;
            }

            $righe[] = [
                $contestoSaldo[$saldoId]['piano'],
                "saldo #{$saldoId}",
                '—',
                '—',
                MoneyHelper::format($distribuito),
                'la somma ripartita non torna al saldo di partenza ('
                    . MoneyHelper::format($originale) . ')',
            ];
        }

        return $righe;
    }

    /**
     * Il motivo per cui questo addebito va ricontrollato, o `null` se è corretto anche con
     * la regola nuova. Il ruolo è quello **di oggi**: se nel frattempo la persona è cambiata
     * di ruolo o è uscita, va detto invece di tacere — chi legge deve poter decidere.
     */
    private function motivoDelSospetto(?string $ruolo, string $tipoGestione): ?string
    {
        // Etichette corte: una cella di tabella che va a capo diventa illeggibile a terminale.
        // La spiegazione per esteso è nella legenda sotto la tabella, stampata una volta sola.
        if ($ruolo === null) {
            return 'non più attivo';
        }

        if ($ruolo === RuoloAnagraficaImmobile::INQUILINO->value) {
            return 'inquilino non debitore';
        }

        if ($ruolo === RuoloAnagraficaImmobile::USUFRUTTUARIO->value && $tipoGestione === 'straordinaria') {
            return 'art. 1005';
        }

        if ($ruolo === RuoloAnagraficaImmobile::NUDA_PROPRIETA->value && $tipoGestione !== 'straordinaria') {
            return 'art. 1004';
        }

        return null;
    }

    private function ruoloAttuale(?int $anagraficaId, ?int $immobileId): ?string
    {
        if ($anagraficaId === null || $immobileId === null) {
            return null;
        }

        return DB::table('anagrafica_immobile')
            ->where('anagrafica_id', $anagraficaId)
            ->where('immobile_id', $immobileId)
            ->where('attivo', true)
            ->value('tipologia');
    }

    private function nomeImmobile(?int $immobileId): string
    {
        return DB::table('immobili')->where('id', $immobileId)->value('nome') ?? "#{$immobileId}";
    }

    private function nomeAnagrafica(?int $anagraficaId): string
    {
        return DB::table('anagrafiche')->where('id', $anagraficaId)->value('nome') ?? "#{$anagraficaId}";
    }
}
