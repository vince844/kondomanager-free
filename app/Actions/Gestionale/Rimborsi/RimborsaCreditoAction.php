<?php

namespace App\Actions\Gestionale\Rimborsi;

use App\Exceptions\Gestionale\ContoPassateGestioniMancanteException;
use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\DoubleEntryValidator;
use App\Services\Gestionale\SaldoCassaService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Il rimborso del credito di un condòmino (1.11.0-beta.31, B2 S6, voce 9): denaro che **esce** dalla cassa
 * a fronte di una quota a credito. Nato per chi vende e lascia il condominio con un credito che nessun
 * piano successivo potrà compensare; vale per qualunque quota con `credito_disponibile > 0`.
 *
 * **La contropartita dipende da dove il credito sta adesso** (`RataQuote::creditoSuPassate()`, 1.11.0-beta.49), perché il
 * giornale lo racconta in quattro modi:
 * - **quota strapagata** (`importo > 0`, `importo_pagato > importo`): l'incasso ha già scritto l'eccedenza
 *   in AVERE su «Crediti verso condomini» (1101); il rimborso la rovescia — DARE 1101 / AVERE cassa.
 * - **quota a credito già a giornale** (`importo < 0`, la quota ha la sua scrittura): dalla decisione 71 l'emissione
 *   della rata scrive il credito in AVERE su 1101 a nome del condòmino, e il rimborso lo rovescia lì — DARE
 *   1101 / AVERE cassa.
 * - **credito riportato non ancora a giornale** (il riporto negativo di una quota non ancora a giornale: la rata in bozza, o una
 *   quota rimasta fuori dall'emissione, come la quota ferma di un piano della 1.7.x): su 1101 non c'è
 *   niente da rovesciare, e un DARE 1101 lascerebbe un credito del condominio verso chi lo riceve, letto dallo Stato
 *   patrimoniale come vero. Il credito viene da un esercizio passato: DARE «Passate gestioni» / AVERE cassa, lo
 *   stesso conto con cui l'emissione chiude i riporti. Quando la quota andrà a giornale, la sua scrittura porterà fra i
 *   crediti solo quello che resta (`RataQuote::creditoUscitoDaPassate()`). La nota dice «la sua quota non ancora a
 *   giornale», non «la sua rata» (giro stretto sulle correzioni della Fase 1-bis, LT-5 e G2): con la 76.3 ritoccata la rata
 *   di una quota ferma va a giornale per gli altri, ed è «emessa».
 * - **credito delle entrate dell'anno non ancora a giornale** (la spesa negativa di una voce di tipo «entrata», rilievo K2
 *   della Fase 1-bis): non è un riporto e in «Passate gestioni» non è mai entrato. DARE 1101 / AVERE cassa, come un
 *   incasso su una quota in bozza: l'emissione della rata scrive quel credito in AVERE su 1101 per intero, e lo chiude.
 * Una quota con tutte e due le parti si rimborsa prima dal riporto, poi dalle entrate: due righe in DARE, una per conto.
 *
 * La quota si consuma come fa lo `storno_credito`: pivot `quota_scrittura` con `importo_pagato = −importo`,
 * poi `ricalcolaStato()` — `credito_disponibile` è l'unica verità, lo `stato` della quota non la dice.
 * La riga di cassa porta `cassa_id` e **non** `anagrafica_id`: l'estratto conto della persona filtra le
 * righe di cassa per `cassa_id`, e con l'anagrafica sopra il movimento comparirebbe due volte.
 *
 * Lo storno passa dalla stessa strada degli incassi (`rettifica` + originale `annullata`,
 * `StornoIncassoRateAction`), che qui rovescia anche la pivot: il credito torna disponibile.
 */
final class RimborsaCreditoAction
{
    public function __construct(private readonly SaldoCassaService $saldoCassa = new SaldoCassaService())
    {
    }

    /**
     * @param int $importoCents già in centesimi (la conversione avviene al confine, nella Request)
     */
    public function execute(Condominio $condominio, Esercizio $esercizio, RataQuote $quota, Cassa $cassa, CarbonImmutable $data, int $importoCents, ?string $nota, ?int $utenteId = null): ScritturaContabile
    {
        return DB::transaction(function () use ($condominio, $esercizio, $quota, $cassa, $data, $importoCents, $nota, $utenteId) {
            // La quota, bloccata: due rimborsi concorrenti dello stesso credito non devono passare entrambi.
            $quota = RataQuote::whereKey($quota->id)->with(['rata.pianoRate.gestione', 'anagrafica'])->lockForUpdate()->firstOrFail();
            $piano = $quota->rata?->pianoRate;

            if ($piano === null || (int) $piano->condominio_id !== (int) $condominio->id) {
                throw ValidationException::withMessages(['rata_quote_id' => 'La quota non appartiene a questo condominio.']);
            }
            // Decisione 41 (1.11.0-beta.42): un rimborso è un movimento, e fermerebbe un piano che deve ancora seguire un passaggio.
            if (($frase = $piano->fraseRicalcolaPrima(['un rimborso', 'il rimborso'])) !== null) {
                throw ValidationException::withMessages(['rata_quote_id' => $frase]);
            }
            if ((int) $cassa->condominio_id !== (int) $condominio->id || ! in_array($cassa->tipo, ['banca', 'contanti'], true) || ! $cassa->attiva) {
                throw ValidationException::withMessages(['cassa_id' => 'Scegli una cassa attiva di questo condominio, banca o contanti: un fondo vincolato non rimborsa crediti.']);
            }

            $disponibile = (int) $quota->credito_disponibile;
            if ($disponibile <= 0) {
                throw ValidationException::withMessages(['rata_quote_id' => 'Questa quota non ha più credito da rimborsare: potrebbe essere stato compensato o rimborsato dopo che hai aperto la pagina. Ricarica e riprova.']);
            }
            if ($importoCents <= 0 || $importoCents > $disponibile) {
                throw ValidationException::withMessages(['importo' => sprintf('Puoi rimborsare al massimo %s, il credito disponibile su questa quota.', MoneyHelper::format($disponibile))]);
            }

            $cassa->loadMissing('contoContabile');
            if (! $cassa->contoContabile) {
                throw ValidationException::withMessages(['cassa_id' => 'La cassa scelta non ha un conto contabile: non può registrare uscite.']);
            }
            $capienza = $this->saldoCassa->saldoPerContoContabile((int) $cassa->contoContabile->id);
            if ($capienza < $importoCents) {
                throw ValidationException::withMessages(['cassa_id' => sprintf('La cassa «%s» ha %s: non basta per rimborsare %s.', $cassa->nome, MoneyHelper::format($capienza), MoneyHelper::format($importoCents))]);
            }

            $contoPassate = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'passate_gestioni')->whereNull('deleted_at')->first();
            $contoCrediti = ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'crediti_condomini')->whereNull('deleted_at')->first();
            // Prima il riporto ancora fra le «Passate gestioni», poi il resto fra i crediti verso condomini (rilievo K2).
            $suPassate = min($importoCents, $quota->creditoSuPassate($contoPassate?->id));
            $suCrediti = $importoCents - $suPassate;
            if ($suPassate > 0 && $contoPassate === null) {
                throw ValidationException::withMessages(['rata_quote_id' => ContoPassateGestioniMancanteException::MESSAGGIO]);
            }
            if ($suCrediti > 0 && $contoCrediti === null) {
                throw ValidationException::withMessages(['rata_quote_id' => 'Manca il conto «Crediti verso condomini» del condominio.']);
            }
            ['riporto' => $riporto, 'spesa' => $spesa] = $quota->componenti();
            $aGiornale = $quota->scrittura_contabile_id !== null;

            $nome = $quota->anagrafica?->nome ?? "anagrafica #{$quota->anagrafica_id}";
            $scrittura = ScritturaContabile::create([
                'condominio_id'      => $condominio->id,
                'esercizio_id'       => $esercizio->id,
                'gestione_id'        => $piano->gestione_id,
                'data_registrazione' => now(),
                'data_competenza'    => $data->toDateString(),
                'causale'            => 'Rimborso credito a ' . $nome,
                'descrizione'        => $nota ?: null,
                'tipo_movimento'     => 'rimborso_condomino',
                'stato'              => 'registrata',
                'created_by'         => $utenteId,
                'note'               => match (true) {
                    $suPassate > 0 && $suCrediti > 0 => 'Credito in parte riportato da un esercizio precedente e in parte delle entrate dell\'anno, con la sua quota non ancora a giornale: la parte riportata esce da Passate gestioni, quella delle entrate da Crediti verso condomini, dove la chiude la scrittura della quota quando va a giornale.',
                    $suPassate > 0                   => 'Credito riportato da un esercizio precedente, con la sua quota non ancora a giornale: contropartita Passate gestioni.',
                    (int) $quota->importo >= 0       => 'Credito da eccedenza di versamento: rovescia l\'accredito su Crediti verso condomini.',
                    $aGiornale && $riporto < 0 && $spesa < 0 => 'Credito riportato da un esercizio precedente e delle entrate dell\'anno, portato a giornale con la sua rata: rovescia l\'accredito su Crediti verso condomini.',
                    $aGiornale && $riporto < 0       => 'Credito riportato da un esercizio precedente, portato a giornale con la sua rata: rovescia l\'accredito su Crediti verso condomini.',
                    $aGiornale                       => 'Credito delle entrate dell\'anno (le entrate del piano superano la spesa di questa quota), portato a giornale con la sua rata: rovescia l\'accredito su Crediti verso condomini.',
                    default                          => 'Credito delle entrate dell\'anno (le entrate del piano superano la spesa di questa quota), con la sua quota non ancora a giornale: contropartita Crediti verso condomini, dove la chiude la scrittura della quota quando va a giornale.',
                },
            ]);

            foreach ([[$contoPassate, $suPassate], [$contoCrediti, $suCrediti]] as [$contropartita, $importoRiga]) {
                if ($importoRiga <= 0) {
                    continue;
                }
                $scrittura->righe()->create([
                    'conto_contabile_id' => $contropartita->id,
                    'anagrafica_id'      => $quota->anagrafica_id,
                    'rata_id'            => $quota->rata_id,
                    'immobile_id'        => $quota->immobile_id,
                    'tipo_riga'          => 'dare',
                    'importo'            => $importoRiga,
                    'note'               => 'Rimborso del credito' . ($nota ? ' — ' . $nota : ''),
                ]);
            }
            $scrittura->righe()->create([
                'conto_contabile_id' => $cassa->contoContabile->id,
                'cassa_id'           => $cassa->id,
                'tipo_riga'          => 'avere',
                'importo'            => $importoCents,
                'note'               => 'Rimborso credito a ' . $nome,
            ]);

            $quota->pagamenti()->attach($scrittura->id, [
                'importo_pagato' => -$importoCents,
                'data_pagamento' => $data->toDateString(),
            ]);
            $quota->ricalcolaStato();

            DoubleEntryValidator::validateOrFail($scrittura->id);

            return $scrittura;
        });
    }
}
