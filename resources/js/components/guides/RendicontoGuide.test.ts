// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le guide delle otto schede del rendiconto, una per scheda, nella finestra di lato.
 *
 * - Aperta, ogni guida mostra le sue parti, nell'ordine; chiusa non c'è. Dice le frasi che contano per la sua scheda, e le parti comuni in breve con il
 *   rimando alla scheda che le spiega.
 * - Ogni nome tra virgolette basse che la guida cita (un pulsante, una colonna, un titolo, una frase della pagina, un'altra scheda) c'è davvero nei
 *   sorgenti della scheda, dei suoi pezzi, della riga delle schede e dei servizi che mandano le frasi alla pagina: la guida non parla di comandi che la
 *   scheda non ha.
 * - Le parole che il programma non usa non ci sono, e nessun altro programma è nominato.
 *
 * Che cosa NON copre: che ogni frase sia vera sul server; le frasi sono state controllate una per una sul codice quando le guide sono state scritte.
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
// Nei sorgenti l'apostrofo dentro le stringhe è `\'`: si legge com'è a video.
const insieme = (files: string[]) => files.map(leggi).join('\n').replace(/\\'/g, '\'').replace(/\s+/g, ' ');

const SCHEDE = 'resources/js/components/gestionale/movimenti/rendiconto/RendicontoSchede.vue';
const PAGINE = 'resources/js/pages/gestionale/movimenti/rendiconto';
const SERVIZI = 'app/Services/Gestionale';

interface Caso {
    nome: string; file: string; testid: string; parti: string[]; frasi: string[]; sorgenti: string[];
}

const casi: Caso[] = [
    {
        nome: 'Sintesi', file: 'RendicontoSintesiGuide', testid: 'guida-rendiconto-sintesi',
        parti: ['Che cosa mostra', 'Da dove vengono le cifre', 'Il fascicolo', 'Fermare il rendiconto per l\'assemblea', 'Registrare l\'approvazione',
            'Dopo l\'approvazione', 'Eliminare una versione'],
        frasi: [
            'La Sintesi non somma niente di suo',
            'conserva le cifre di oggi e il PDF del fascicolo, con le parti scelte',
            'Nessuna delle due è scelta prima',
            'Lo stato dell\'esercizio non cambia, e niente si blocca.',
            'Perché è cambiato lo sai tu: il programma non lo giudica.',
            'per persona il conguaglio e il saldo finale, approvati e di oggi: un incasso cambia il saldo finale, non il conguaglio.',
            'Una versione approvata, o sostituita, resta: è la delibera dell\'assemblea.',
            // La convocazione (1.12.0-beta.13): per quale assemblea, la riga di stato, la versione partita con la convocazione.
            '«Per quale assemblea»',
            '«In assemblea il 15/12, punto 1 · convocazione spedita il 03/12»',
            'Una versione allegata a una convocazione già partita non si elimina',
        ],
        sorgenti: [`${PAGINE}/Sintesi.vue`, 'resources/js/components/gestionale/movimenti/rendiconto/RendicontoPerLAssemblea.vue', SCHEDE,
            `${SERVIZI}/VersioniDelRendiconto.php`, `${SERVIZI}/ConfrontoDelRendiconto.php`, `${SERVIZI}/SintesiDelRendiconto.php`, `${SERVIZI}/ControlliDelRendiconto.php`],
    },
    {
        nome: 'Riepilogo finanziario', file: 'RendicontoRiepilogoGuide', testid: 'guida-rendiconto-riepilogo',
        parti: ['Per competenza', 'Le colonne delle spese', 'Le entrate e le quote', 'Fuori dai capitoli', 'Il risultato e le righe che legano le cifre',
            'La stampa e la nota'],
        frasi: [
            'Le spese di questo esercizio, pagate o no',
            'il consuntivo meno il deliberato con le rate',
            'sono del condominio da quando sono versati, e riducono le spese da dividere',
            'Queste due righe non hanno un deliberato.',
            'le cinque voci con lo scostamento più grande',
        ],
        sorgenti: [`${PAGINE}/RiepilogoFinanziario.vue`, SCHEDE, `${SERVIZI}/RiepilogoPerCompetenza.php`, `${SERVIZI}/ControlliDelRendiconto.php`],
    },
    {
        nome: 'Situazione patrimoniale', file: 'RendicontoSituazioneGuide', testid: 'guida-rendiconto-situazione',
        parti: ['Una fotografia alla data', 'I quattro numeri', 'Attività e passività', 'Le fatture aperte e gli altri debiti', 'Per gestione, liquidità e fondi',
            'Che cosa la scheda non fa', 'La stampa e i controlli'],
        frasi: [
            'Per l\'esercizio aperto la data è oggi; per uno finito, la sua data di fine.',
            'I costi non ancora conguagliati stanno fra le attività',
            'compare qui sotto la riga, fuori dal giornale e dai totali',
            'Non confronta con l\'estratto conto della banca movimento per movimento.',
        ],
        sorgenti: ['resources/js/pages/gestionale/movimenti/statoPatrimoniale/Show.vue', SCHEDE, `${SERVIZI}/StatoPatrimonialePaginaService.php`],
    },
    {
        nome: 'Cassa', file: 'RendicontoCassaGuide', testid: 'guida-rendiconto-cassa',
        parti: ['Per cassa', 'Il tipo dei fondi e quanto si può usare', 'Il totale e le righe in rosso', 'Il raccordo fra cassa e competenza', 'La stampa e i controlli'],
        frasi: [
            'iniziale più entrate meno uscite uguale finale',
            'Un passaggio da una cassa all\'altra compare nelle due righe ma non nel totale',
            'le sue righe sono già nella riga del conto e non si sommano al totale',
            'che sotto la cifra dice quanto ne è andato nei fondi («messi nei fondi»)',
            'anche per un giorno',
            'La cassa è quella della contabilità',
            'il conto in banca chiude con il saldo della banca, con il fondo dentro',
            'Nella riga del conto questi spostamenti non ci sono',
            'Un clic su un fondo apre il suo mastrino.',
            'la parte libera più i fondi generici e quelli sbloccati dall\'assemblea',
            'Un fondo senza tipo dice «tipo non indicato» e conta come vincolato',
            'da un fondo vincolato, non sbloccato, no',
            'La stampa ha lo stesso gruppo «Come si divide il conto»',
        ],
        sorgenti: [`${PAGINE}/Cassa.vue`, SCHEDE, `${SERVIZI}/StatoPatrimonialePaginaService.php`, `${SERVIZI}/SaldiDelleBanche.php`,
            `${SERVIZI}/FascicoloDelRendiconto.php`, `${PAGINE}/Sintesi.vue`, 'resources/js/pages/gestionale/movimenti/registroContabilita/List.vue',
            `${SERVIZI}/TipoDelFondo.php`, `${SERVIZI}/LiquiditaVincolataService.php`, 'resources/js/pages/gestionale/casse/CasseEdit.vue'],
    },
    {
        nome: 'Condòmini', file: 'RendicontoCondominiGuide', testid: 'guida-rendiconto-condomini',
        parti: ['Due elenchi', 'Le posizioni', 'I crediti per anzianità', 'Le stampe e i dati personali'],
        frasi: [
            'positivo vuol dire che la persona deve, negativo che ha un credito',
            'Gli anticipi sono un debito del condominio verso la persona',
            'Il credito di una persona non si toglie dalle sue rate',
            'una lettera solo stampata, senza la spedizione registrata, non c\'è',
            'Non vanno esposti in bacheca né girati ad altri, nemmeno in una chat.',
            // Il giro delle correzioni della beta.12: per le persone di una successione i due scaduti non sono lo stesso, e la guida dice perché.
            'qui e nelle posizioni resta a nome del defunto, come nel giornale e nella situazione patrimoniale',
            'Lo scaduto di questa pagina segue il giornale; quello di «Rate scadute», chi deve pagare.',
        ],
        sorgenti: [`${PAGINE}/Condomini.vue`, SCHEDE, `${SERVIZI}/PosizioniDeiCondomini.php`, `${SERVIZI}/ControlliDelRendiconto.php`, `${PAGINE}/Sintesi.vue`],
    },
    {
        nome: 'Conguaglio', file: 'RendicontoConguaglioGuide', testid: 'guida-rendiconto-conguaglio',
        parti: ['Una proposta', 'Come si legge una riga', 'Le gestioni e le uscite che nessuno paga', 'Le stampe e i fogli per i condòmini',
            'Il conguaglio nelle altre schede'],
        frasi: [
            'da approvare dall\'assemblea: il programma la calcola e la stampa, non la registra',
            'Il segno più vuol dire da versare, il segno meno a credito.',
            'non c\'è una riga di arrotondamenti',
            'Il programma propone la persona, l\'importo e la causale',
            'le stampe per i condòmini non li hanno',
        ],
        sorgenti: [`${PAGINE}/Conguaglio.vue`, SCHEDE, 'resources/js/components/gestionale/rendiconto/InvioDeiFogli.vue',
            'resources/js/components/gestionale/rendiconto/UltimoInvioDelFoglio.vue', 'resources/js/lib/gestionale/rendiconto/invioDeiFogli.ts',
            'resources/js/components/gestionale/addebiti/NuovoAddebitoDialog.vue', `${SERVIZI}/ConguaglioDelRendiconto.php`, `${SERVIZI}/ControlliDelConguaglio.php`,
            `${PAGINE}/Sintesi.vue`, `${PAGINE}/RiepilogoFinanziario.vue`],
    },
    {
        nome: 'Controlli', file: 'RendicontoControlliGuide', testid: 'guida-rendiconto-controlli',
        parti: ['Che cosa sono', 'Gli esiti', 'I quindici controlli', 'Il saldo della banca', 'Le possibili cause di una differenza', 'Ignora, con una nota',
            'Oltre il confine dell\'esercizio', 'La stampa'],
        frasi: [
            'Non verificano che ogni fattura e ogni entrata sia stata registrata.',
            'Con una differenza è «da leggere», non «non torna».',
            'il programma non corregge niente da solo',
            'La causa ignorata non si propone più.',
            'Il programma non sposta le registrazioni da un esercizio all\'altro',
            'Accanto a «non calcolato», «Scrivi il saldo della banca» apre la nota sul campo del saldo di quel conto.',
            'nemmeno i giroconti fra il conto e i suoi fondi',
            'Il controllo della banca no: la sua spiegazione si scrive sotto il saldo della banca, accanto alle cifre',
        ],
        sorgenti: [`${PAGINE}/Controlli.vue`, SCHEDE, `${SERVIZI}/ControlliDelRendiconto.php`, `${SERVIZI}/ControlliDelConguaglio.php`,
            `${SERVIZI}/StatoPatrimonialePaginaService.php`, `${SERVIZI}/SaldiDelleBanche.php`, `${SERVIZI}/PisteDellaBanca.php`, `${SERVIZI}/AnnoACavallo.php`,
            `${SERVIZI}/NotaSinteticaDelRendiconto.php`],
    },
    {
        nome: 'Nota sintetica', file: 'RendicontoNotaGuide', testid: 'guida-rendiconto-nota',
        parti: ['Che cos\'è', 'I fatti proposti', 'Le cause e le spiegazioni', 'Il saldo della banca', 'Gli elenchi e la dichiarazione', 'Copiare e generare',
            'Il registro', 'Salvare, fermare, approvare'],
        frasi: [
            'Nessun fatto si stampa finché non lo tieni.',
            'mai i fatti, che sono dei conti di quest\'anno',
            'Non scrive niente di suo.',
            'Il programma non sa delle cause: il silenzio non vuol dire che non ce ne sono.',
            'puoi fermarla e approvarla lo stesso',
            'sotto «Il saldo secondo la banca (dal suo estratto conto)»',
            'il saldo che leggi sull\'estratto conto che ti manda la banca',
            'Il controllo della banca non ha un fatto suo: lo spieghi sotto il saldo della banca.',
            'È la spiegazione del controllo della banca: si scrive solo qui.',
            // Con l'assemblea in preparazione che ha il punto del rendiconto, la stampa mette i suoi punti (1.12.0-beta.13).
            'la stampa mette al loro posto i punti del suo ordine del giorno',
        ],
        sorgenti: [`${PAGINE}/NotaSintetica.vue`, 'resources/js/components/gestionale/movimenti/rendiconto/TestoDellaNota.vue', SCHEDE,
            `${SERVIZI}/NotaSinteticaDelRendiconto.php`, `${SERVIZI}/FattiDellaNota.php`, 'resources/js/pages/gestionale/movimenti/statoPatrimoniale/Show.vue',
            'resources/js/components/gestionale/movimenti/rendiconto/RendicontoPerLAssemblea.vue', `${PAGINE}/Sintesi.vue`],
    },
];

const componenti = import.meta.glob('./Rendiconto*Guide.vue');

let w: VueWrapper | null = null;
afterEach(() => {
    w?.unmount();
    w = null;
    document.body.innerHTML = '';
});

describe.each(casi)('la guida della scheda «$nome»', (c) => {
    const sorgenteGuida = () => leggi(`resources/js/components/guides/${c.file}.vue`);

    test('aperta mostra le sue parti e le sue frasi, chiusa non c\'è', async () => {
        const carica = componenti[`./${c.file}.vue`];
        expect(carica, `${c.file}.vue`).toBeDefined();
        const Guida = ((await carica()) as { default: object }).default;
        w = mount(Guida, { attachTo: document.body, props: { open: false } });
        await flushPromises();
        expect(document.body.querySelector(`[data-testid="${c.testid}"]`)).toBeNull();

        await w.setProps({ open: true });
        await flushPromises();
        const guida = document.body.querySelector(`[data-testid="${c.testid}"]`);
        expect(guida).not.toBeNull();
        expect([...guida!.querySelectorAll('h3')].map((h) => (h.textContent ?? '').trim())).toEqual(c.parti);
        const testo = (guida!.textContent ?? '').replace(/\s+/g, ' ');
        for (const frase of c.frasi) {
            expect(testo, frase).toContain(frase);
        }
    });

    test('ogni nome tra virgolette basse c\'è nella scheda', () => {
        const sorgentiPagina = insieme(c.sorgenti);
        const citati = [...new Set([...sorgenteGuida().matchAll(/«([^»]+)»/g)].map((m) => m[1].replace(/\s+/g, ' ')))];
        expect(citati.length).toBeGreaterThan(10);
        expect(citati.filter((nome) => !sorgentiPagina.includes(nome))).toEqual([]);
    });

    test('nessuna parola che il programma non usa, nessun altro programma', () => {
        const testo = sorgenteGuida().replace(/<!--[\s\S]*?-->/g, ' ').replace(/\/\*[\s\S]*?\*\//g, ' ').toLowerCase();
        for (const parola of ['dovut', 'esigibil', 'pronto', 'pronta', 'conforme', 'regolare', 'fisiologic', 'consegnat', 'recapitat', 'notificat', 'garant', 'danea']) {
            expect(testo, parola).not.toContain(parola);
        }
    });
});

describe('le guide del rendiconto', () => {
    test('sono otto, una per scheda, ognuna nel suo file', () => {
        expect(casi.map((c) => c.file).sort()).toEqual(Object.keys(componenti).map((k) => k.replace('./', '').replace('.vue', '')).sort());
        expect(casi).toHaveLength(8);
    });
});
