<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use App\Models;
use App\Models\Assemblee;
use App\Models\Gestionale;
use App\Services\RegistroModifiche\Schede\SchedaNelRegistro;
use Illuminate\Database\Eloquent\Model;

/**
 * Chi è registrato (§6 del progetto, con P4): **ogni** modello di `app/Models` sta in uno dei tre elenchi, e il test della classificazione
 * (`tests/Feature/RegistroModifiche/ClassificazioneDeiModelliTest.php`) fa rosso per un modello nuovo senza classe.
 *  - `TRATTO`: le schede, con il tratto {@see \App\Traits\RegistraLeModifiche} e la loro dichiarazione.
 *  - `SOLO_GESTO`: i modelli scritti dentro un gesto (le rate, le quote, i passaggi, le scritture), con il tratto leggero {@see \App\Traits\ContaNelGesto}:
 *    dentro un gesto si contano nel lotto, fuori si annotano (P5).
 *  - `FUORI`: con la ragione.
 */
final class CatalogoDelRegistro
{
    /** @var array<class-string<Model>, class-string<SchedaNelRegistro>> */
    public const TRATTO = [
        Models\Anagrafica::class => Schede\AnagraficaNelRegistro::class,
        Models\Fornitore::class => Schede\FornitoreNelRegistro::class,
        Models\AnagraficaFornitore::class => Schede\AnagraficaFornitoreNelRegistro::class,
        Models\ContoCorrente::class => Schede\ContoCorrenteNelRegistro::class,
        Models\CategoriaFornitore::class => Schede\CategoriaFornitoreNelRegistro::class,
        Models\Condominio::class => Schede\CondominioNelRegistro::class,
        Models\Palazzina::class => Schede\PalazzinaNelRegistro::class,
        Models\Scala::class => Schede\ScalaNelRegistro::class,
        Models\Immobile::class => Schede\ImmobileNelRegistro::class,
        Models\TitolaritaImmobile::class => Schede\TitolaritaImmobileNelRegistro::class,
        Models\Tabella::class => Schede\TabellaNelRegistro::class,
        Models\Esercizio::class => Schede\EsercizioNelRegistro::class,
        Models\Gestione::class => Schede\GestioneNelRegistro::class,
        Gestionale\Conto::class => Schede\ContoNelRegistro::class,
        Gestionale\PianoConto::class => Schede\PianoContoNelRegistro::class,
        Gestionale\PianoRate::class => Schede\PianoRateNelRegistro::class,
        Gestionale\RegolaRecupero::class => Schede\RegolaRecuperoNelRegistro::class,
        Gestionale\DispensaSeiMesi::class => Schede\DispensaSeiMesiNelRegistro::class,
        Gestionale\AccordoRientro::class => Schede\AccordoRientroNelRegistro::class,
        Gestionale\FatturaPassiva::class => Schede\FatturaPassivaNelRegistro::class,
        Models\Saldo::class => Schede\SaldoNelRegistro::class,
        Gestionale\Cassa::class => Schede\CassaNelRegistro::class,
        Models\Comunicazione::class => Schede\ComunicazioneNelRegistro::class,
        Models\Segnalazione::class => Schede\SegnalazioneNelRegistro::class,
        Models\Documento::class => Schede\DocumentoNelRegistro::class,
        Models\CategoriaDocumento::class => Schede\CategoriaDocumentoNelRegistro::class,
        Models\ImpostazioniCondominio::class => Schede\ImpostazioniCondominioNelRegistro::class,
        Models\ModelloTesto::class => Schede\ModelloTestoNelRegistro::class,
        Models\CartaStampa::class => Schede\CartaStampaNelRegistro::class,
        // Le assemblee (Coda 679, §6.1 del progetto della tappa 2): le sedute, i punti e le delibere nella «Storia» dell'assemblea, il memo in quella della sua
        // origine o del condominio.
        Assemblee\Assemblea::class => Schede\AssembleaNelRegistro::class,
        Assemblee\SedutaDellAssemblea::class => Schede\SedutaNelRegistro::class,
        Assemblee\PuntoDellOrdineDelGiorno::class => Schede\PuntoNelRegistro::class,
        Assemblee\Delibera::class => Schede\DeliberaNelRegistro::class,
        Assemblee\MemoDellAssemblea::class => Schede\MemoNelRegistro::class,
        // Il verbale (la tappa del verbale): il verbale nella «Storia» dell'assemblea, il registro dei verbali di ogni perimetro e le righe di nomina e
        // revoca in quella del condominio.
        Assemblee\VerbaleDellAssemblea::class => Schede\VerbaleNelRegistro::class,
        Assemblee\RegistroDelPerimetro::class => Schede\RegistroDelPerimetroNelRegistro::class,
        Assemblee\RigaDiNomina::class => Schede\RigaDiNominaNelRegistro::class,
        // Il seguito delle delibere: le cose da fare dopo una delibera, nella «Storia» dell'assemblea.
        Assemblee\CosaDaFareDellaDelibera::class => Schede\CosaDaFareNelRegistro::class,
    ];

    /** @var array<class-string<Model>, array{tipo: string, area: string, ragione: string}> */
    public const SOLO_GESTO = [
        Gestionale\Subentro::class => ['tipo' => 'passaggio', 'area' => 'condominio',
            'ragione' => 'gesto 4: il passaggio si registra e si annulla in un gesto solo; il «prima e dopo» resta in `subentri.registro`'],
        Models\QuotaTabella::class => ['tipo' => 'quota_tabella', 'area' => 'millesimi',
            'ragione' => 'gesto 1: i millesimi si salvano tutti insieme dalla pagina delle quote'],
        Gestionale\ContoTabellaMillesimale::class => ['tipo' => 'tabella_della_voce', 'area' => 'millesimi',
            'ragione' => 'dentro il gesto della voce: le tabelle di una voce si legano dalla voce'],
        Gestionale\ContoTabellaRipartizione::class => ['tipo' => 'ripartizione_della_voce', 'area' => 'millesimi',
            'ragione' => 'dentro il gesto della voce: le ripartizioni di una voce si scrivono dalla voce'],
        Gestionale\Rata::class => ['tipo' => 'rata', 'area' => 'piani_rate', 'ragione' => 'gesto 2: generazione, rigenerazione ed emissione del piano'],
        Gestionale\RataQuote::class => ['tipo' => 'rata_quote', 'area' => 'piani_rate', 'ragione' => 'gesto 2: generazione, rigenerazione ed emissione del piano'],
        Gestionale\RigaRiparto::class => ['tipo' => 'riga_riparto', 'area' => 'piani_rate', 'ragione' => 'gesto 2: il riparto nasce con la generazione del piano'],
        Gestionale\RicorrenzaRata::class => ['tipo' => 'ricorrenza_rata', 'area' => 'piani_rate', 'ragione' => 'gesto 2: il calendario nasce con il piano'],
        Gestionale\CompetenzaCapitolo::class => ['tipo' => 'competenza_capitolo', 'area' => 'piani_rate',
            'ragione' => 'gesto 2: le competenze dei capitoli nascono con il piano'],
        Gestionale\ScritturaContabile::class => ['tipo' => 'scrittura_contabile', 'area' => 'contabilita',
            'ragione' => '§5: la contabilità ha il suo registro; fuori da un gesto una scrittura scrive la riga «Scrittura registrata», dentro si conta'],
        Gestionale\ContributoVersato::class => ['tipo' => 'contributo_versato', 'area' => 'piani_rate',
            'ragione' => 'P4: il «già versato» della voce si salva in un gesto (`ContributoVersatoController::update`)'],
        Gestionale\BudgetMovement::class => ['tipo' => 'spostamento_di_budget', 'area' => 'piani_rate',
            'ragione' => 'P4: lo spostamento e il suo annullamento sono un gesto (`BudgetMovementService`)'],
        Gestionale\NotaSintetica::class => ['tipo' => 'nota_sintetica', 'area' => 'contabilita',
            'ragione' => 'P4: il salvataggio della nota sintetica è un gesto (`RendicontoNotaController`)'],
        Models\DocumentoVersione::class => ['tipo' => 'versione_documento', 'area' => 'documenti',
            'ragione' => '§9: la versione di prima nasce con la sostituzione del file, dentro il gesto «File» del documento (`VersioniDeiDocumenti`)'],
        Assemblee\ConvocatoDellAssemblea::class => ['tipo' => 'convocato', 'area' => 'assemblee',
            'ragione' => 'fissati all\'invio, dentro il gesto «Convocazione spedita»: l\'elenco dei convocati si fissa con la spedizione'],
        // La sala: ogni cosa nasce e cambia dentro un gesto della sala (o in «Deleghe ricevute»), che scrive una riga sola con l'ora del gesto.
        Assemblee\DelegaDellAssemblea::class => ['tipo' => 'delega', 'area' => 'assemblee',
            'ragione' => 'si segna in «Deleghe ricevute» o in un gesto della sala, che scrivono la riga «Delega di … a …», anche rifiutata'],
        Assemblee\PresenzaInSala::class => ['tipo' => 'presenza', 'area' => 'assemblee',
            'ragione' => 'ogni entrata e ogni uscita è un gesto della sala, con la sua riga e l\'ora del gesto'],
        Assemblee\VotazioneDelPunto::class => ['tipo' => 'votazione', 'area' => 'assemblee',
            'ragione' => 'il voto di un punto si apre, si conta e si conferma nei gesti della sala: la conferma scrive una riga con i voti nel lotto'],
        Assemblee\VotoInSala::class => ['tipo' => 'voto', 'area' => 'assemblee',
            'ragione' => 'dentro i gesti della sala e la riga della conferma: una riga per lotto, non una per voto'],
        Assemblee\DichiarazioneAVerbale::class => ['tipo' => 'dichiarazione', 'area' => 'assemblee',
            'ragione' => 'nasce in un gesto della sala, con la sua riga'],
    ];

    /** I modelli «solo gesto» che fuori da un gesto scrivono una riga loro invece di annotarsi. */
    public const CON_RIGA_PROPRIA = [Gestionale\ScritturaContabile::class];

    /** @var array<class-string<Model>, string> */
    public const FUORI = [
        Models\User::class => 'gli atti sulle utenze (§5, Coda ㉗): il modello ha password, token e due fattori, e il registro scrive solo gli atti',
        Models\Invito::class => 'gli atti sulle utenze (§5): l\'invito è un atto, scritto dal suo controller',
        Models\Evento::class => 'decisione 547: i gesti delle persone sull\'agenda e sui compiti li scrivono i controller; ciò che il programma crea e pota no',
        Models\CategoriaEvento::class => 'categorie dell\'agenda: dati di riferimento',
        Models\RicorrenzaEvento::class => 'dentro il gesto dell\'evento (decisione 547)',
        Models\EccezioneEvento::class => 'dentro il gesto dell\'evento (decisione 547)',
        Models\Commento::class => 'i commenti restano nel loro registro di moderazione (§6)',
        Models\ComunicazioneLettura::class => 'le letture le scrive il gesto del condòmino nel portale (l\'apertura, «Confermo di aver letto»); le cancella l\'amministratore dalle impostazioni generali, e il riquadro delle letture della comunicazione dice quante ne restano',
        Gestionale\RegistroInvio::class => 'ha già il suo registro: racconta le consegne',
        Gestionale\SeiMesiDellEsercizio::class => 'l\'esercizio letto come scheda dei sei mesi per il memo e il punto proposto (Coda 736): solo lettura, l\'esercizio si scrive dal suo modello',
        Models\MailLog::class => 'tecnico: il registro delle email inviate',
        Models\Errori\Errore::class => 'tecnico: il registro degli errori, scritto dal programma e tenuto 90 giorni (la 698 del registro delle decisioni)',
        Models\Errori\VoltaDellErrore::class => 'tecnico: una volta di un errore, con chi ha avvisato e quando; la scrive il programma',
        Models\Backup::class => 'tecnico: le copie hanno il loro elenco',
        Models\ImportBatch::class => 'il lotto dell\'importazione: il registro scrive una riga per lotto con il rimando qui',
        Models\ImportBatchItem::class => 'l\'elenco del lotto dell\'importazione',
        Models\ImportFile::class => 'il file dell\'importazione',
        Models\Comune::class => 'dati di riferimento (ISTAT), scritti dal programma',
        Models\CodiceAteco::class => 'dati di riferimento (ISTAT), scritti dal programma',
        Models\TipologiaImmobile::class => 'dati di riferimento',
        Models\NotificationPreference::class => 'preferenze personali di chi riceve le notifiche',
        Models\UserPreference::class => 'preferenze personali dell\'interfaccia',
        Models\PreferenzaTabellaUtente::class => 'preferenze personali delle tabelle',
        Gestionale\RispostaPromemoria::class => 'ha già il suo registro (`RegistroDelGesto`)',
        Gestionale\RendicontoVersione::class => 'ha già le versioni: la versione di prima resta, «sostituita»',
        Gestionale\CreditoResiduo::class => 'calcolato dal programma dalle scritture',
        Gestionale\RigaScrittura::class => 'dentro la sua scrittura contabile (§5)',
        Gestionale\RigaFattura::class => 'dentro la fattura: la fattura ha il tratto con i totali',
        Gestionale\FatturaCopertura::class => 'dentro la fattura e il suo pagamento',
        Gestionale\FatturaScrittura::class => 'il legame fra fattura e scrittura, scritto dal programma',
        Gestionale\PagamentoFornitore::class => 'dentro la scrittura contabile del pagamento, che scrive la riga (§5)',
        Gestionale\DelegaF24::class => 'dentro la scrittura contabile del versamento, che scrive la riga (§5)',
        Gestionale\RigaF24::class => 'dentro la sua delega F24',
        Gestionale\ContoContabile::class => 'il piano dei conti contabile, scritto dal programma con la risorsa e con la voce',
        Gestionale\AccordoRientroRata::class => 'dentro l\'accordo di rientro, che ha il tratto',
        Gestionale\AccordoRientroScadenza::class => 'dentro l\'accordo di rientro, che ha il tratto',
        Models\RegistroModifica::class => 'è il registro',
        Assemblee\OggettoDelPunto::class => 'si scrive solo dentro il campo «Parla di» del punto (`campoDellaScheda`)',
        Assemblee\CollegamentoAllaDelibera::class => 'si scrive solo da `CollegamentiDelleDelibere`, dentro il campo «Delibera» della scheda che collega',
        Gestionale\VersionePreventivo::class => 'ha già le versioni: la versione di prima resta, «sostituita»; fermarla è un gesto dell\'assemblea',
        Assemblee\GestoDellaSala::class => 'è esso stesso il libro dei gesti della sala: ogni gesto applicato scrive la sua riga, con l\'ora del gesto e quella dell\'arrivo',
        Assemblee\ParteDelVerbale::class => 'ha già le versioni: il testo di adesso di una parte del verbale; il registro scrive le versioni, non ogni salvataggio',
        Assemblee\VersioneDelVerbale::class => 'ha già le versioni: è la versione di una parte del verbale, e il gesto che la crea scrive la sua riga',
    ];

    /** @var array<string, SchedaNelRegistro> */
    private static array $istanze = [];

    public static function descrizionePer(Model|string $modello): ?SchedaNelRegistro
    {
        $classe = is_string($modello) ? $modello : $modello::class;
        $descrizione = self::TRATTO[$classe] ?? null;
        if ($descrizione === null) {
            return null;
        }

        return self::$istanze[$descrizione] ??= new $descrizione();
    }

    public static function descrizionePerTipo(string $tipo): ?SchedaNelRegistro
    {
        foreach (self::TRATTO as $classe => $descrizione) {
            if ($descrizione::TIPO === $tipo) {
                return self::descrizionePer($classe);
            }
        }

        return null;
    }

    /** Il nome stabile del tipo di un modello, mai la classe PHP: dalla dichiarazione, dall'elenco «solo gesto», o dalla tabella. */
    public static function tipoDi(Model|string $modello): string
    {
        $classe = is_string($modello) ? $modello : $modello::class;
        if (isset(self::TRATTO[$classe])) {
            return self::TRATTO[$classe]::TIPO;
        }
        if (isset(self::SOLO_GESTO[$classe])) {
            return self::SOLO_GESTO[$classe]['tipo'];
        }

        return (is_string($modello) ? new $modello() : $modello)->getTable();
    }

    /**
     * Le tabelle registrate, per la guardia (P5): quelle dei modelli con il tratto, quelle dei modelli «solo gesto» e le pivot del gesto 7.
     *
     * @return list<string>
     */
    public static function tabelleRegistrate(): array
    {
        $tabelle = [];
        foreach ([...array_keys(self::TRATTO), ...array_keys(self::SOLO_GESTO)] as $classe) {
            $tabelle[] = (new $classe())->getTable();
        }

        return array_values(array_unique([...$tabelle, ...self::PIVOT_DEL_GESTO_7]));
    }

    /** Le pivot senza modello dei legami (gesto 7): persone e condomini, destinatari, capitoli, gestioni, voci e tabelle, quote e pagamenti, ruoli. */
    public const PIVOT_DEL_GESTO_7 = ['anagrafica_condominio', 'anagrafica_comunicazione', 'comunicazione_condominio', 'anagrafica_segnalazione',
        'anagrafica_documento', 'condominio_documento', 'documento_categoria', 'piano_rate_capitoli', 'piano_rate_fatture', 'esercizio_gestione',
        'conto_tabella_millesimale', 'conto_tabella_ripartizioni', 'quota_scrittura', 'dispensa_sei_mesi_anagrafica', 'dispensa_sei_mesi_esercizio',
        'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'roles', 'anagrafica_evento', 'condominio_evento'];

    /** Le dichiarazioni di tutti i tipi, per la «Storia», i filtri delle pagine e l'oscuramento. @return array<string, array<string, mixed>> */
    public static function dichiarazioni(): array
    {
        $tutte = [];
        foreach (array_keys(self::TRATTO) as $classe) {
            $d = self::descrizionePer($classe);
            $tutte[$d->tipo()] = $d->dichiarazione();
        }

        return $tutte;
    }
}
