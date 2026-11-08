<?php

namespace App\Actions\Gestionale\Movimenti;

use App\Enums\TipoMovimentoContabile;
use App\Exceptions\Gestionale\IncassoDaTerziNonAmmessoException;
use App\Helpers\DateHelper;
use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\DoubleEntryValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * L'incasso da terzi: denaro che entra in una cassa reale da chi non è un condòmino (l'affitto dell'antenna, gli interessi del conto, un
 * rimborso), in diminuzione della voce di entrata del piano dei conti, che lo toglie già da quanto si chiede ai condòmini.
 *
 *   DARE   la cassa (banca o contanti)            importo   con chi ha versato
 *   AVERE  il mastro della voce di entrata        importo   con la voce
 *
 * È lo specchio della regolazione immediata (`RegistraRegolazioneImmediataAction`, DARE sul mastro della voce con la voce): la stessa regola
 * per la voce, con i versi rovesciati. Nessun conto di ricavo: la voce di entrata è già ancorata a un mastro di costo, e l'entrata ne riduce i
 * costi; nel consuntivo per voce lo speso della voce di entrata è negativo, e le pagine lo leggono come «incassato».
 *
 * - **Solo una cassa reale** (banca o contanti): il registro di contabilità legge solo quelle, e un'entrata scritta su un fondo non vi
 *   comparirebbe. Un fondo a parte si fa dopo, con un giroconto.
 * - **Chi ha versato** è una persona o una società della rubrica, scritta sulle due righe: il registro la legge come controparte. L'estratto
 *   conto della persona non la vede, perché legge solo i mastri della posizione di condòmino. Si può lasciare vuoto (un accredito senza nome).
 * - **La gestione è quella della voce** (il suo piano dei conti), e deve essere una gestione dell'esercizio.
 * - **Le date**: la data dell'operazione è la competenza; la registrazione è oggi, il giorno dell'amministratore, così la spia dei trenta giorni
 *   del registro si accende per un incasso annotato tardi. L'esercizio è quello aperto, come per ogni movimento.
 */
class RegistraIncassoDaTerziAction
{
    /** Le casse il cui denaro entra nel registro di contabilità: un fondo e una cassa virtuale ne restano fuori. */
    public const CASSE_REALI = ['banca', 'contanti'];

    /** Il rifiuto di una voce di spesa: uno solo per la richiesta (sul campo della voce) e per l'azione. */
    public static function messaggioVoceDiSpesa(string $nome): string
    {
        return "«{$nome}» è una voce di spesa: l'incasso da terzi va su una voce di entrata. Scegli una voce di entrata, o creala con preventivo zero.";
    }

    /** Il rifiuto di una cassa che non è reale. */
    public static function messaggioCassaNonReale(string $nome): string
    {
        return "«{$nome}» è un fondo o una cassa virtuale: l'incasso da terzi entra in una banca o nei contanti, altrimenti il registro di contabilità "
            .'non lo vedrebbe. Registralo sulla banca e, se va accantonato, spostalo nel fondo con un giroconto.';
    }

    /**
     * Le voci su cui un incasso da terzi si scrive: voci di entrata del condominio, di una gestione dell'esercizio, senza sottovoci e con il
     * mastro. È l'elenco del modulo e la regola dell'azione.
     */
    public static function vociAmmesse(Condominio $condominio, Esercizio $esercizio): Builder
    {
        return Conto::query()
            ->whereIn('piano_conto_id', PianoConto::query()
                ->where('condominio_id', $condominio->id)
                ->whereIn('gestione_id', $esercizio->gestioni()->pluck('gestioni.id')->all())
                ->select('id'))
            ->whereNotIn('tipo', Conto::TIPI_DI_SPESA)
            ->whereDoesntHave('sottoconti')
            ->whereNotNull('conto_contabile_id')
            ->visibili();
    }

    public function execute(array $validated, Condominio $condominio, Esercizio $esercizio): ScritturaContabile
    {
        $importoCents = MoneyHelper::toCents($validated['importo']);

        if ($importoCents <= 0) {
            throw new IncassoDaTerziNonAmmessoException("L'importo di un incasso da terzi deve essere maggiore di zero.");
        }

        if ((int) $esercizio->condominio_id !== (int) $condominio->id || $esercizio->stato !== 'aperto') {
            throw new IncassoDaTerziNonAmmessoException("L'esercizio scelto non è un esercizio aperto di questo condominio.");
        }

        return DB::transaction(function () use ($validated, $condominio, $esercizio, $importoCents) {
            $voce = Conto::with('pianoConto')->findOrFail($validated['conto_id']);

            if (! $voce->pianoConto || (int) $voce->pianoConto->condominio_id !== (int) $condominio->id) {
                throw new IncassoDaTerziNonAmmessoException('La voce scelta non appartiene a questo condominio.');
            }

            // DARE la cassa e AVERE la voce: su una voce di spesa sarebbe una spesa che diminuisce senza che nessuno l'abbia restituita.
            if (Conto::segnoDelTipo((string) $voce->tipo) === 1) {
                throw new IncassoDaTerziNonAmmessoException(self::messaggioVoceDiSpesa((string) $voce->nome));
            }

            if (! self::vociAmmesse($condominio, $esercizio)->whereKey($voce->id)->exists()) {
                throw new IncassoDaTerziNonAmmessoException(
                    "La voce «{$voce->nome}» non può ricevere un incasso: deve essere una voce senza sottovoci, con un conto contabile, "
                    ."di una gestione dell'esercizio «{$esercizio->nome}»."
                );
            }

            $cassa = Cassa::with('contoContabile')->findOrFail($validated['cassa_id']);

            if ((int) $cassa->condominio_id !== (int) $condominio->id) {
                throw new IncassoDaTerziNonAmmessoException('La cassa scelta non appartiene a questo condominio.');
            }

            if (! in_array((string) $cassa->tipo, self::CASSE_REALI, true)) {
                throw new IncassoDaTerziNonAmmessoException(self::messaggioCassaNonReale((string) $cassa->nome));
            }

            if (! $cassa->contoContabile) {
                throw new IncassoDaTerziNonAmmessoException("La cassa «{$cassa->nome}» non ha un conto contabile associato.");
            }

            $versante = empty($validated['anagrafica_id']) ? null : Anagrafica::findOrFail($validated['anagrafica_id']);
            $causale = mb_substr((string) $validated['causale'], 0, 255);

            $scrittura = ScritturaContabile::create([
                'condominio_id' => $condominio->id,
                'esercizio_id' => $esercizio->id,
                'gestione_id' => (int) $voce->pianoConto->gestione_id,
                'data_registrazione' => DateHelper::oggiUtente(),
                'data_competenza' => $validated['data_operazione'],
                'causale' => $causale,
                'tipo_movimento' => TipoMovimentoContabile::INCASSO_DIVERSO,
                'stato' => 'registrata',
                'created_by' => auth()->id(),
            ]);

            $scrittura->righe()->create([
                'conto_contabile_id' => $cassa->contoContabile->id,
                'cassa_id' => $cassa->id,
                'tipo_riga' => 'dare',
                'importo' => $importoCents,
                'anagrafica_id' => $versante?->id,
                'note' => 'Incasso da terzi — '.$cassa->nome,
            ]);

            $scrittura->righe()->create([
                'conto_contabile_id' => $voce->conto_contabile_id,
                'voce_spesa_id' => $voce->id,
                'tipo_riga' => 'avere',
                'importo' => $importoCents,
                'anagrafica_id' => $versante?->id,
                'note' => $causale,
            ]);

            DoubleEntryValidator::validateOrFail($scrittura->id);

            return $scrittura;
        });
    }
}
