// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La issue 58 (Coda 174, decisione 674) nelle tre guide in pagina che parlano dei saldi intestati all'unità: la guida del piano rate («Nominali e
 * solidali»), quella del Wallet («A chi è intestato») e quella del passaggio («Chi resta obbligato», «Chi vende ha solo una quota»). Fino alla
 * 1.12.0-beta.13 dicevano che quei saldi «restano attaccati all'appartamento anche se cambia il proprietario», che si ripartiscono «fra chi ha un
 * diritto reale» (sottinteso: oggi) e che «si addebitano quando si genera il piano»; e la guida del passaggio diceva che sull'unità mista i
 * saldi pregressi, «per ora, no». Con la regola vanno a chi ne era titolare il primo giorno del loro esercizio, e dove un passaggio li tocca
 * l'amministratore sceglie nel nuovo piano.
 *
 * Si montano le guide con i segnaposto del foglio, delle schede e del cassetto, e si legge il testo con gli spazi normalizzati.
 * Cosa resta scoperto: la traduzione (le guide sono solo in italiano).
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import PianoRateGuide from './PianoRateGuide.vue';
import SaldiGuide from './SaldiGuide.vue';
import PassaggioProprietaGuide from './PassaggioProprietaGuide.vue';
import RipartoUsufruttoGuide from './RipartoUsufruttoGuide.vue';

const passa = { template: '<div><slot /></div>' };
const stubs = {
    Sheet: passa, SheetContent: passa, SheetHeader: passa, SheetTitle: passa, SheetDescription: passa,
    Tabs: passa, TabsList: passa, TabsTrigger: passa, TabsContent: passa,
    Accordion: passa, AccordionItem: passa, AccordionTrigger: passa, AccordionContent: passa,
};
const testo = (guida: unknown) => mount(guida as never, { props: { open: true }, global: { stubs } }).text().replace(/\s+/g, ' ');

describe('la regola dei saldi dell\'unità nelle guide', () => {
    test('guida del piano rate — vanno a chi era titolare il primo giorno del loro esercizio, il giorno prima se il passaggio è di quel giorno, e la scelta resta scritta', () => {
        const t = testo(PianoRateGuide);

        expect(t).toContain('Vanno a chi ne era titolare il primo giorno del loro esercizio, anche se dopo l\'appartamento ha cambiato proprietario.');
        expect(t).toContain('Se il passaggio è proprio di quel giorno, a chi era titolare il giorno prima.');
        expect(t).toContain('Dove un passaggio li tocca, «Configura riparto saldi» lo dice e ti lascia scegliere');
        expect(t).not.toContain('restano attaccati all\'appartamento anche se cambia il proprietario');
    });

    test('guida del Wallet — fra chi ha un diritto reale il primo giorno del suo esercizio; chi compra risponde in solido ma non gli si addebita da sé', () => {
        const t = testo(SaldiGuide);

        expect(t).toContain('in automatico fra chi ha un diritto reale sull\'unità il primo giorno del suo esercizio');
        expect(t).toContain('chi compra ne risponde in solido verso il condominio, ma il programma non glielo addebita da sé.');
        expect(t).toContain('il nuovo piano lo dice e ti lascia scegliere a chi va');
    });

    test('guida del passaggio — la frase di «Chi resta obbligato» è quella dell\'anteprima, e sull\'unità mista i saldi pregressi si dividono con le quote del primo giorno', () => {
        const t = testo(PassaggioProprietaGuide);

        expect(t).toContain('e i saldi intestati all\'unità restano a chi ne era titolare il primo giorno del loro esercizio, salvo una tua scelta diversa nel piano rate');
        expect(t).toContain('I saldi pregressi intestati all\'unità si dividono fra chi ne era titolare il primo giorno del loro esercizio, con le quote di quel giorno.');
        expect(t).not.toContain('che si addebitano quando si genera il piano');
        expect(t).not.toContain('per ora, no');
    });
});

/**
 * Il giro di correzioni dopo lo scettico della tappa 1 della 1.12.0-beta.14. Rilievo 8: nessuna guida diceva la regola della successione
 * (decisioni 685 (1) e 689 (2)), e «vanno a chi ne era titolare il primo giorno» con l'arretrato agli eredi non è vero. Rilievo 16: l'altra
 * scelta della finestra può essere chi esce, non solo «chi è entrato con il passaggio». E la guida «Ruoli e usufrutto» diceva che i saldi
 * pregressi di un'unità diventata mista con un passaggio, «per ora», si ripartiscono come prima: dalla issue 58 si guardano il primo giorno.
 */
describe('la successione e le scelte, nelle guide', () => {
    const successione = 'con l\'arretrato agli eredi vanno agli eredi, per le loro quote; con l\'arretrato a nome del defunto restano al defunto';

    test('rilievo 8 — la guida del piano rate e quella del Wallet dicono la regola della successione', () => {
        expect(testo(PianoRateGuide)).toContain(`Con una successione: ${successione}.`);
        expect(testo(SaldiGuide)).toContain('Con una successione: con l\'arretrato agli eredi va agli eredi, per le loro quote; con l\'arretrato a nome del defunto resta al defunto.');
    });

    test('rilievo 8 — la guida del passaggio, nella successione, dice dove vanno i saldi intestati all\'unità', () => {
        expect(testo(PassaggioProprietaGuide)).toContain('I saldi intestati all\'unità seguono la stessa scelta quando il piano rate li applica: con l\'arretrato agli eredi vanno agli eredi, per le loro quote; con l\'arretrato a nome del defunto restano al defunto.');
    });

    test('rilievo 8 del secondo scettico — le tre guide dicono il confine della decisione 691 (1): al defunto solo se è morto dall\'inizio dell\'esercizio prima', () => {
        const confine = 'Al defunto solo se è morto dall\'inizio dell\'esercizio prima di quello del saldo: con una morte di prima, o con una successione '
            + 'registrata senza la scelta sull\'arretrato, vanno agli eredi.';
        expect(testo(PianoRateGuide)).toContain(confine);
        expect(testo(SaldiGuide)).toContain(confine.replace('vanno agli eredi', 'va agli eredi'));
        expect(testo(PassaggioProprietaGuide)).toContain(confine);
    });

    test('rilievi 2 e 7 del secondo scettico — la guida del piano rate dice che cosa fa il ricalcolo, anche togliendo una voce, quando la scelta non vale più', () => {
        expect(testo(PianoRateGuide)).toContain('La scelta resta scritta nel piano, e un ricalcolo la rifà finché il passaggio resta com\'era. Se il passaggio è stato '
            + 'annullato o cambiato, il ricalcolo, anche togliendo una voce, torna alla regola, e se il pregresso va a un\'altra persona l\'esito lo dice.');
    });

    test('rilievo 16 — l\'altra scelta è chi era titolare prima o dopo il passaggio, non solo chi è entrato', () => {
        for (const guida of [PianoRateGuide, SaldiGuide]) {
            expect(testo(guida)).toContain('la regola, chi era titolare prima o dopo il passaggio, o un riparto a mano');
            expect(testo(guida)).not.toContain('chi è entrato con il passaggio');
        }
    });

    test('rilievo 8 — la guida «Ruoli e usufrutto» non dice più che i saldi pregressi dell\'unità diventata mista si ripartiscono come prima', () => {
        const t = testo(RipartoUsufruttoGuide);

        expect(t).not.toContain('Fanno eccezione, per ora, i saldi pregressi');
        expect(t).toContain('I saldi pregressi intestati all\'unità si dividono come l\'unità era il primo giorno del loro esercizio: se è diventata così con un passaggio dopo, quel giorno non lo era.');
    });
});
