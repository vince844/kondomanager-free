<?php

namespace App\Services\Import\Controlli;

use App\Services\Import\Controlli\Verificatori\CodiceFiscaleDelCondominio;
use App\Services\Import\Controlli\Verificatori\CodiciFiscaliDeiSoggetti;
use App\Services\Import\Controlli\Verificatori\TabelleCollegateAiCapitoli;
use App\Services\Import\Controlli\Verificatori\TitolaritaDeiSaldi;
use App\Services\Import\Controlli\Verificatori\UnitaConTitolare;
use Illuminate\Support\Facades\Route;

/**
 * Cosa sappiamo su ciascun codice di avviso: dove si va a sistemarlo, e chi risponde da solo alla
 * domanda «è a posto adesso?».
 *
 * ## L'unico cancello
 *
 * Ogni codice passa **di qui**, e la regola di forma è: **o un verificatore, o un `perche`
 * scritto**. Il ramo di default non fa sparire un codice nuovo in silenzio — lo fa fallire il
 * test di esaustività. È l'unica difesa contro il decadimento: senza, il prossimo livello
 * aggiunge un avviso e la lista ricomincia a essere una todolist che mente.
 *
 * ## Perché alcuni restano a mano *di proposito*
 *
 * `soggetto.recapito_gia_usato` è l'esempio da tenere a mente. Una query sulle anagrafiche del
 * lotto senza email risponderebbe a una domanda **diversa** da quella che ha generato l'avviso:
 * quale persona ha perso il recapito, il rilievo non lo dice. Inventare la query darebbe un
 * verde falso, che è peggio di una spunta a mano.
 */
final class CatalogoControlli
{
    /**
     * Codice → cosa ne sappiamo.
     *
     * @return array<string, VoceCatalogo>
     */
    public static function voci(): array
    {
        $immobili = 'admin.gestionale.immobili.index';
        $saldi = 'admin.gestionale.saldi.index';
        $tabelle = 'admin.gestionale.tabelle.index';
        $struttura = 'admin.gestionale.struttura.index';

        return [
            // ── Verificabili: il sistema riguarda e chiude da solo ──
            'tabelle.non_collegate_ai_capitoli' => new VoceCatalogo(
                verificatore: TabelleCollegateAiCapitoli::class,
                rotta: $tabelle,
                etichettaAzione: 'Vai alle tabelle',
            ),
            'titolarita.unita_senza_titolare' => new VoceCatalogo(
                verificatore: UnitaConTitolare::class,
                rotta: $immobili,
                etichettaAzione: 'Assegna i titolari',
            ),
            'saldi.titolarita_non_corrispondente' => new VoceCatalogo(
                verificatore: TitolaritaDeiSaldi::class,
                rotta: $saldi,
                etichettaAzione: 'Vai ai saldi',
            ),
            'condominio.cf_mancante' => new VoceCatalogo(
                verificatore: CodiceFiscaleDelCondominio::class,
                rotta: $struttura,
                etichettaAzione: 'Completa i dati del condominio',
            ),
            'condominio.cf_malformato' => new VoceCatalogo(
                verificatore: CodiceFiscaleDelCondominio::class,
                rotta: $struttura,
                etichettaAzione: 'Correggi il codice fiscale',
            ),
            'soggetto.cf_mancante' => new VoceCatalogo(
                verificatore: CodiciFiscaliDeiSoggetti::class,
                rotta: $immobili,
                etichettaAzione: 'Vai alle anagrafiche',
            ),

            // ── A mano, e ognuno dice perché ──
            'saldi.quadratura_non_verificabile' => new VoceCatalogo(
                rotta: $saldi,
                etichettaAzione: 'Vai ai saldi',
                perche: 'Il confronto è con l\'ultimo rendiconto approvato, che è un documento fuori '
                    .'da Kondomanager: solo tu puoi dire se torna.',
            ),
            'riparto.totale_assente' => new VoceCatalogo(
                rotta: $saldi,
                etichettaAzione: 'Vai ai saldi',
                perche: 'Il file non portava il totale di controllo, quindi non c\'è niente con cui '
                    .'ricontrollare da soli.',
            ),
            'riparto.titolarita_cambiata_in_corso_anno' => new VoceCatalogo(
                rotta: $immobili,
                etichettaAzione: 'Vai alle unità',
                perche: 'Kondomanager non gestisce ancora il subentro: se una spesa va divisa '
                    .'pro-rata fra vecchio e nuovo titolare, quella parte va fatta a mano — e '
                    .'nessuna query può stabilire da sola se serviva.',
            ),
            'riparto.saldi_di_titolari_cessati' => new VoceCatalogo(
                rotta: $saldi,
                etichettaAzione: 'Vai ai saldi',
                perche: 'Se quel debito vada chiesto al vecchio proprietario o al nuovo è una '
                    .'valutazione giuridica, non una query.',
            ),
            'saldi.da_nome_diviso' => new VoceCatalogo(
                rotta: $saldi,
                etichettaAzione: 'Vai ai saldi',
                perche: 'Il riparto intesta la posizione alle due persone insieme e non dice in che '
                    .'proporzione dividerla: spezzarla a metà sarebbe inventare una ripartizione '
                    .'su denaro altrui.',
            ),
            'saldi.soggetto_da_nome_composto' => new VoceCatalogo(
                rotta: $saldi,
                etichettaAzione: 'Vai ai saldi',
                perche: 'Se le due persone in una cella sola vadano divise, e in che proporzione, '
                    .'lo sai solo tu.',
            ),
            'esercizio.straordinario_in_gestione_dedicata' => new VoceCatalogo(
                rotta: $struttura,
                etichettaAzione: 'Vai alle gestioni',
                perche: 'La gestione straordinaria è stata creata e le spese ci sono finite dentro, '
                    .'ma se quella separazione corrisponda a come è stato deliberato lo sai solo tu: '
                    .'il file dichiara «straordinario», non quale delibera ci sta sotto.',
            ),
                        'capitoli.spese_personali_non_importate' => new VoceCatalogo(
                rotta: $saldi,
                etichettaAzione: 'Vai ai saldi',
                perche: 'Non c\'è niente da rimettere a posto: quelle spese sono già dentro i saldi '
                    .'di apertura di chi le deve. La voce resta per dire dove sono finite, perché '
                    .'chi confronta il bilancio vecchio con l\'archivio nuovo le cercherebbe fra i '
                    .'capitoli e non le troverebbe.',
            ),
            'capitoli.senza_preventivo' => new VoceCatalogo(
                rotta: $struttura,
                etichettaAzione: 'Vai al piano dei conti',
                perche: 'Quanto chiedere ai condòmini quest\'anno lo decide l\'assemblea, non il '
                    .'consuntivo dell\'anno scorso: nessuna query può scrivere un preventivo al posto '
                    .'di chi lo delibera.',
            ),
            'capitoli.senza_voci' => new VoceCatalogo(
                rotta: $struttura,
                etichettaAzione: 'Vai al piano dei conti',
                perche: 'Se quel capitolo serva davvero, e con quali voci sotto, lo sa solo chi '
                    .'gestisce il condominio: il file lo porta senza contenuto, e un contenitore '
                    .'vuoto non si può né preventivare né ripartire.',
            ),
            'bilancio.non_quadra' => new VoceCatalogo(
                rotta: $struttura,
                etichettaAzione: 'Vai al piano dei conti',
                perche: 'La differenza è fra i numeri del file e il totale che il file stesso '
                    .'dichiara: solo chi ha quella stampa in mano può dire quale dei due è giusto.',
            ),
            'capitolo.non_quadra' => new VoceCatalogo(
                rotta: $struttura,
                etichettaAzione: 'Vai al piano dei conti',
                perche: 'Come sopra, ma su un capitolo solo. Gli importi di un consuntivo non entrano '
                    .'in archivio come preventivo, quindi la differenza non sposta nessun numero: '
                    .'resta però un segnale che quella stampa va guardata.',
            ),
                        'tabella.parziale' => new VoceCatalogo(
                rotta: $tabelle,
                etichettaAzione: 'Vai alle tabelle',
                perche: 'Una tabella parziale è un modo normale di ripartire: solo tu sai se quelle '
                    .'unità dovevano parteciparvi.',
            ),
            'tabella.parti_uguali' => new VoceCatalogo(
                rotta: $tabelle,
                etichettaAzione: 'Vai alle tabelle',
                perche: 'Ripartire in parti uguali può essere voluto: nessuna query può distinguerlo '
                    .'da un millesimo sbagliato.',
            ),
            'soggetto.recapito_gia_usato' => new VoceCatalogo(
                rotta: $immobili,
                etichettaAzione: 'Vai alle anagrafiche',
                perche: 'Quale delle due persone debba tenere quel recapito il file non lo dice, e '
                    .'una query risponderebbe a una domanda diversa.',
            ),
            // Coda ㉔: la reimportazione ha trovato l'unità già intestata e non ha toccato niente.
            // Non è ricontrollabile da sola — «chi possiede cosa» ha due versioni e sceglierne
            // una al posto dell'amministratore è esattamente ciò che questa correzione evita.
            'titolarita.conflitto_con_archivio' => new VoceCatalogo(
                rotta: $immobili,
                etichettaAzione: 'Vai alle unità',
                perche: 'Il file e l\'archivio non concordano su chi è collegato a quell\'unità, e '
                    .'quale delle due versioni sia quella giusta lo sai solo tu.',
            ),
            'titolarita.quota_solo_nelle_note' => new VoceCatalogo(
                rotta: $immobili,
                etichettaAzione: 'Vai alle unità',
                perche: 'La quota sta scritta a parole nelle note del file: interpretarla al posto '
                    .'tuo significherebbe indovinare su chi paga cosa.',
            ),
            'unita.interno_dedotto' => new VoceCatalogo(
                rotta: $immobili,
                etichettaAzione: 'Vai alle unità',
                perche: 'L\'interno l\'abbiamo dedotto dal progressivo: è quasi sempre giusto, e '
                    .'quel «quasi» lo verifichi solo tu guardando la targa.',
            ),
            'unita.tipologia_non_mappata' => new VoceCatalogo(
                rotta: $immobili,
                etichettaAzione: 'Vai alle unità',
                perche: 'Il tipo scritto da Danea non corrisponde a nessuna delle nostre tipologie: '
                    .'sceglierne una al posto tuo cambierebbe come l\'unità viene trattata.',
            ),
            'condominio.nome_corrotto' => new VoceCatalogo(
                rotta: $struttura,
                etichettaAzione: 'Correggi il nome',
                perche: 'Il nome arriva con caratteri illeggibili: come si scriva davvero lo sai tu.',
            ),
            'esercizi.sovrapposto' => new VoceCatalogo(
                rotta: 'admin.gestionale.index',
                etichettaAzione: 'Vai al condominio',
                perche: 'Due esercizi che si accavallano possono avere una ragione contabile che il '
                    .'file non racconta.',
            ),
            'soggetti.non_ricavabili_da_questo_report' => new VoceCatalogo(
                perche: 'Il file caricato non conteneva le persone: la strada è un altro export, non '
                    .'una correzione in archivio.',
            ),
        ];
    }

    public static function per(string $codice): VoceCatalogo
    {
        return self::voci()[$codice] ?? new VoceCatalogo(
            perche: 'Non ancora classificata: spuntala quando l\'hai guardata.',
        );
    }

    /**
     * L'indirizzo dove si va a sistemare — o `null`.
     *
     * `Route::has()` davanti perché **meglio nessun pulsante che un pulsante verso un 404**: è
     * già successo con «Vai al condominio» nella schermata di esito, costruito a mano senza
     * accorgersi che le rotte del gestionale vivono sotto `admin`.
     */
    public static function destinazione(string $codice, int $condominioId): ?array
    {
        $voce = self::per($codice);

        if ($voce->rotta === null || ! Route::has($voce->rotta)) {
            return null;
        }

        return [
            'etichetta' => $voce->etichettaAzione ?? 'Vai',
            'url' => route($voce->rotta, $condominioId),
        ];
    }
}
