// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La strada dei cinque passi dell'assemblea (la variante A della bozza dei passi guidati, scene 1 e 2), con i passi di Via Roma 12 come li scrive il server:
 * le date e i documenti fatti, la convocazione adesso con due cose da fare, la sala chiusa fino al 14/12, il verbale dopo la sala.
 *
 * - Gli stati: il cerchio verde con la spunta, quello scuro con il numero, il lucchetto con il perché sotto; la linea verde fino al passo di adesso.
 * - Il clic: un passo fatto o quello di adesso è un pulsante e si apre, con `aria-current`; uno chiuso o dopo no.
 * - Al telefono «Passo 3 di 5», il nome, la barra di avanzamento e l'elenco dei passi che si apre con un tocco.
 * - Sotto la strada il titolo, le card di «che cosa manca» con il loro badge, il contenuto del passo; una voce che informa (i
 *   punti proposti che aspettano) non conta e non blocca. Ogni card ha l'icona di ciò che è (il colore dice lo stato: azzurro informa, verde fatto, arancio
 *   da fare), il titolo, la riga dello stato e in fondo un pulsante piccolo con il verbo intero, alla stessa altezza nelle card di una fila; i quattro modi
 *   di oggi (il punto della pagina, la stampa, la pagina del programma, il passo) più la finestra della pagina («Vedi le deleghe») e il pulsante spento con
 *   il perché («Apri la sala» prima del giorno). In fondo «← il passo prima», la frase e «Avanti», acceso quando il passo è fatto
 *   e il dopo si apre. Il «Diario dell'assemblea» non sta più sulla strada: è nel menu «Azioni» della pagina, in un cassetto.
 *
 * Che cosa non copre: le regole dei passi (il server, `PassiDellAssembleaTest`); la larghezza vera a 375 pixel (la prova a video).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import type { PassoDellaStrada } from '@/lib/assemblee/pagine';

vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href" data-link="inertia"><slot /></a>' } }));

const Strada = (await import('./StradaDellAssemblea.vue')).default;

const passo = (p: Partial<PassoDellaStrada> & Pick<PassoDellaStrada, 'chiave' | 'numero' | 'nome' | 'stato'>): PassoDellaStrada => ({
    riga: null, perche: null, manca: [], scopo: null, piede: '', ...p,
});
const VIA_ROMA = (): PassoDellaStrada[] => [
    passo({ chiave: 'date', numero: 1, nome: 'Date e ordine del giorno', stato: 'fatto', riga: '14/12 e 15/12 · 2 punti', piede: 'Il passo è fatto quando le date valgono.' }),
    passo({ chiave: 'documenti', numero: 2, nome: 'Documenti', stato: 'fatto', riga: 'l\'avviso con la delega · nessun allegato', piede: 'Il passo non blocca.' }),
    passo({
        chiave: 'convocazione', numero: 3, nome: 'Convocazione', stato: 'adesso', riga: '5 da spedire · entro il 03/12', scopo: 'prima di spedire',
        piede: 'Il passo è fatto quando tutti i convocati hanno il loro avviso spedito.',
        manca: [
            { chiave: 'avviso', fatto: true, informa: false, titolo: 'Date, luogo e ordine del giorno', dettaglio: 'a posto', collegamento: null },
            { chiave: 'casella', fatto: false, informa: false, titolo: 'La casella PEC dello studio', dettaglio: 'da impostare: le 5 PEC non partono', collegamento: { testo: 'Imposta', url: '/impostazioni/mail?apri=pec', passo: null } },
            { chiave: 'convocati', fatto: false, informa: false, titolo: 'I convocati', dettaglio: '5 PEC non comunicate dalla persona', collegamento: { testo: 'Vedi chi', url: '#convocati', passo: null } },
        ],
    }),
    passo({ chiave: 'sala', numero: 4, nome: 'In sala', stato: 'chiuso', riga: 'si apre il 14/12', perche: 'si apre il 14/12', piede: 'La sala si apre il giorno della prima convocazione.' }),
    passo({ chiave: 'verbale', numero: 5, nome: 'Verbale e trasmissione', stato: 'chiuso', riga: 'dopo la sala', perche: 'dopo la sala', piede: 'Il verbale si scrive dopo la sala.' }),
];

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

function monta(props: Record<string, unknown> = {}, slots: Record<string, string> = {}) {
    w = mount(Strada as never, { attachTo: document.body, props: { passi: VIA_ROMA(), aperto: 'convocazione', ...props } as never,
        slots: { default: '<div data-testid="il-contenuto">Il contenuto di oggi</div>', ...slots } });

    return w;
}
const scrivania = () => w!.get('[data-testid="strada"]');
const sulla = (chiave: string) => scrivania().get(`[data-passo="${chiave}"]`);

describe('la strada dei passi', () => {
    test('gli stati: verde con la spunta, scuro con il numero, il lucchetto con il perché sotto; la linea verde fino al passo di adesso', () => {
        monta();
        expect(['date', 'documenti', 'convocazione', 'sala', 'verbale'].map((c) => sulla(c).attributes('data-stato')))
            .toEqual(['fatto', 'fatto', 'adesso', 'chiuso', 'chiuso']);
        expect(sulla('date').find('[data-testid="spunta"]').exists()).toBe(true);
        expect(sulla('convocazione').get('[data-testid="cerchio"]').text()).toBe('3');
        expect(sulla('sala').find('[data-testid="lucchetto"]').exists()).toBe(true);
        expect(sulla('sala').text()).toContain('In sala');
        expect(sulla('sala').text()).toContain('si apre il 14/12');
        expect(sulla('convocazione').text()).toContain('5 da spedire · entro il 03/12');
        // La linea che arriva a un passo è verde se il passo è fatto o è quello di adesso; il primo non ha linea.
        expect(sulla('date').find('[data-testid="linea"]').exists()).toBe(false);
        expect(['documenti', 'convocazione', 'sala', 'verbale'].map((c) => sulla(c).get('[data-testid="linea"]').attributes('data-verde')))
            .toEqual(['si', 'si', 'no', 'no']);
    });

    test('un clic apre un passo fatto o quello di adesso, con aria-current; i passi chiusi non si aprono', async () => {
        monta();
        expect(sulla('convocazione').get('button').attributes('aria-current')).toBe('step');
        expect(sulla('date').get('button').attributes('aria-current')).toBeUndefined();
        await sulla('date').get('button').trigger('click');
        expect(w!.emitted('apri')).toEqual([['date']]);
        expect(sulla('sala').find('button').exists()).toBe(false);
        expect(sulla('verbale').find('button').exists()).toBe(false);
        await sulla('sala').trigger('click');
        expect(w!.emitted('apri')).toEqual([['date']]);
        // Il passo aperto si riconosce dal grassetto del nome, senza riquadro né linea nera sotto; un passo fatto non è in grassetto.
        expect(sulla('convocazione').get('button').attributes('data-aperto')).toBe('si');
        expect(sulla('date').get('button').attributes('data-aperto')).toBeUndefined();
        expect(w!.find('[data-testid="segno"]').exists()).toBe(false);
        expect(sulla('convocazione').get('button').classes()).not.toContain('bg-slate-50');
        // Nemmeno al passaggio del mouse compare un riquadro: cambia solo il colore del nome.
        expect(sulla('date').get('button').classes().some((c) => c.startsWith('hover:bg-'))).toBe(false);
        const nome = (c: string) => sulla(c).findAll('span').find((x) => x.text() === ({ convocazione: 'Convocazione', date: 'Date e ordine del giorno' } as Record<string, string>)[c])!;
        expect(nome('convocazione').classes()).toContain('font-semibold');
        expect(nome('date').classes()).not.toContain('font-semibold');
    });

    test('sotto la strada: il titolo, il badge di ciò che manca, le card con il pulsante, il contenuto del passo', async () => {
        monta();
        expect(w!.get('[data-testid="titolo-del-passo"]').text()).toBe('Passo 3 · Convocazione');
        // Il badge in alto non c'è più (decisione 648): lo stato sta in ogni card, accanto al titolo.
        expect(w!.find('[data-testid="da-fare"]').exists()).toBe(false);
        const voci = w!.findAll('[data-testid="che-cosa-manca"] li');
        const badge = voci.map((v) => v.get('[data-testid="badge-della-voce"]'));
        expect(badge.map((b) => b.text())).toEqual(['Fatto', 'Da fare', 'Da fare']);
        expect(badge.every((b) => b.classes().includes('rounded-md'))).toBe(true);
        expect(badge[0].classes().join(' ')).toContain('emerald');
        expect(badge[1].classes().join(' ')).toContain('amber');
        expect(voci.map((v) => v.attributes('data-fatto'))).toEqual(['si', 'no', 'no']);
        expect(voci[1].text()).toContain('La casella PEC dello studio');
        const imposta = voci[1].get('[data-testid="pulsante-della-voce"]');
        expect([imposta.element.tagName, imposta.attributes('href'), imposta.attributes('data-link'), imposta.text()])
            .toEqual(['A', '/impostazioni/mail?apri=pec', 'inertia', 'Imposta']);
        // «Vedi chi» porta alla tabella dei convocati nella pagina, senza una visita.
        const vediChi = voci[2].get('[data-testid="pulsante-della-voce"]');
        expect([vediChi.attributes('href'), vediChi.attributes('data-link')]).toEqual(['#convocati', undefined]);
        expect(voci[2].text()).toContain('I convocati');
        // Una voce senza collegamento non ha il pulsante.
        expect(voci[0].find('[data-testid="pulsante-della-voce"]').exists()).toBe(false);
        expect(w!.get('[data-testid="contenuto-del-passo"]').text()).toContain('Il contenuto di oggi');
    });

    test('la card: l\'icona di ciò che è, il colore dello stato, la riga con la maiuscola, il pulsante piccolo in fondo, alla stessa altezza', () => {
        monta();
        const voci = w!.findAll('[data-testid="che-cosa-manca"] li');
        // L'icona dice che cosa è: l'avviso, la casella PEC, i convocati; il colore dice lo stato (verde fatto, arancio da fare).
        expect(voci.map((v) => v.get('[data-testid="icona"]').attributes('data-icona'))).toEqual(['avviso', 'posta', 'convocati']);
        expect(voci.map((v) => v.get('[data-testid="icona"]').attributes('data-colore'))).toEqual(['verde', 'arancio', 'arancio']);
        // La riga dello stato comincia con la maiuscola, anche quando il server la scrive dentro una frase.
        expect(voci[0].get('[data-testid="riga-della-voce"]').text()).toBe('A posto');
        expect(voci[1].get('[data-testid="riga-della-voce"]').text()).toContain('Da impostare: le 5 PEC non partono');
        // La card è una colonna con il pulsante in fondo: le card di una fila hanno la stessa altezza e i pulsanti allineati.
        expect(voci[1].classes()).toEqual(expect.arrayContaining(['flex', 'flex-col', 'justify-between']));
        // Il pulsante piccolo del modulo (come «Revoca»): alto 28 pixel, il testo a 12, il bordo e il fondo bianco; al telefono a tutta riga.
        const pulsante = voci[1].get('[data-testid="pulsante-della-voce"]');
        expect(pulsante.classes()).toEqual(expect.arrayContaining(['h-7', 'px-2.5', 'text-xs', 'border', 'bg-white', 'w-full', 'sm:w-auto']));
        expect(pulsante.classes()).not.toContain('underline');
        expect(w!.get('[data-testid="che-cosa-manca"]').classes()).toEqual(expect.arrayContaining(['grid', 'lg:grid-cols-3']));
    });

    test('le tre card dei passi «In sala» (sala chiusa) e «Verbale»: «La sala» e «Il verbale» hanno la loro icona, i badge dicono lo stato, tre per riga dal tablet', () => {
        const passi = VIA_ROMA();
        passi[3] = { ...passi[3], stato: 'fatto', manca: [
            { chiave: 'sala', fatto: true, informa: false, titolo: 'La sala', dettaglio: 'Chiusa alle 08:00 · costituita in prima convocazione con 3 su 4',
                collegamento: { testo: 'Apri la sala', url: '/admin/gestionale/3/assemblee/9/sala', passo: null } },
            { chiave: 'deleghe', fatto: true, informa: true, titolo: 'Le deleghe', dettaglio: 'Nessuna valida',
                collegamento: { testo: 'Vedi le deleghe', url: null, passo: null, apre: 'deleghe' } },
            { chiave: 'prospetto', fatto: true, informa: true, titolo: 'Le presenze e i voti', dettaglio: 'il prospetto',
                collegamento: { testo: 'Stampa il prospetto', url: '/prospetto', passo: null, stampa: true } },
        ] };
        passi[4] = { ...passi[4], stato: 'adesso', manca: [
            { chiave: 'verbale', fatto: true, informa: false, titolo: 'Il verbale', dettaglio: 'Registrato · n. 1, sottoscritto il 21/11/2026',
                collegamento: { testo: 'Apri il verbale', url: '/verbale', passo: null } },
        ] };
        monta({ passi, aperto: 'sala' });
        const voci = w!.findAll('[data-testid="che-cosa-manca"] li');
        expect(voci.map((v) => v.get('[data-testid="icona"]').attributes('data-icona'))).toEqual(['sala', 'persone', 'stampante']);
        expect(voci.map((v) => v.get('[data-testid="badge-della-voce"]').text())).toEqual(['Fatto', 'Da vedere', 'Da vedere']);
        expect(w!.get('[data-testid="che-cosa-manca"]').classes()).toEqual(expect.arrayContaining(['grid', 'sm:grid-cols-3']));
        expect(w!.get('[data-testid="che-cosa-manca"]').classes()).not.toContain('sm:grid-cols-2');
        w!.unmount();
        monta({ passi, aperto: 'verbale' });
        expect(w!.get('[data-testid="icona"]').attributes('data-icona')).toBe('verbale');
    });

    test('il passo «In sala» prima del giorno: «Vedi le deleghe» apre il cassetto, «Stampa di nuovo» la stampa, «Apri la sala» spento con il perché', async () => {
        const passi = VIA_ROMA();
        const perche = 'La sala si apre il giorno della prima convocazione, il 14/12/2026.';
        passi[3] = { ...passi[3], stato: 'adesso', manca: [
            { chiave: 'deleghe', fatto: true, informa: true, titolo: 'Deleghe ricevute', dettaglio: '1 valida · 1 rifiutata',
                collegamento: { testo: 'Vedi le deleghe', url: null, passo: null, apre: 'deleghe' } },
            { chiave: 'foglio', fatto: true, informa: false, titolo: 'Il foglio della sala', dettaglio: 'stampato il 13/12 alle 00:01, con le deleghe ricevute fino ad allora',
                collegamento: { testo: 'Stampa di nuovo', url: '/admin/gestionale/3/assemblee/9/sala/foglio', passo: null, stampa: true } },
            { chiave: 'apri', fatto: true, informa: true, titolo: 'Apri la sala', dettaglio: 'si accende il 14/12, il giorno della prima convocazione',
                collegamento: { testo: 'Apri la sala', url: null, passo: null, spento: perche } },
        ] };
        monta({ passi, aperto: 'sala' });
        const voci = w!.findAll('[data-testid="che-cosa-manca"] li');
        expect(voci.map((v) => v.get('[data-testid="icona"]').attributes('data-icona'))).toEqual(['persone', 'stampante', 'lucchetto']);
        expect(voci.map((v) => v.get('[data-testid="icona"]').attributes('data-colore'))).toEqual(['azzurro', 'verde', 'azzurro']);
        // Il pulsante spento dice il perché anche a chi legge lo schermo, dentro il pulsante.
        expect(voci.map((v) => v.get('[data-testid="pulsante-della-voce"]').text())).toEqual(['Vedi le deleghe', 'Stampa di nuovo', `Apri la sala (${perche})`]);
        // «Vedi le deleghe» non porta da nessuna parte: chiede alla pagina di aprire il cassetto.
        const vedi = voci[0].get('[data-testid="pulsante-della-voce"]');
        expect([vedi.element.tagName, vedi.attributes('type'), vedi.attributes('href')]).toEqual(['BUTTON', 'button', undefined]);
        await vedi.trigger('click');
        expect(w!.emitted('apre')).toEqual([['deleghe']]);
        expect(w!.emitted('apri')).toBeUndefined();
        // La stampa apre il PDF in una scheda nuova, e lo dice alla pagina (che rilegge i passi quando si torna).
        await voci[1].get('[data-testid="pulsante-della-voce"]').trigger('click');
        expect(w!.emitted('stampa')).toHaveLength(1);
        // «Apri la sala» prima del giorno: il pulsante c'è, spento, e dice il perché.
        const apri = voci[2].get('[data-testid="pulsante-della-voce"]');
        expect([apri.attributes('disabled'), apri.attributes('title')]).toEqual(['', perche]);
        expect(apri.get('.sr-only').text()).toContain(perche);
        expect(voci[2].get('[data-testid="riga-della-voce"]').text()).toBe('Si accende il 14/12, il giorno della prima convocazione');
    });

    test('senza deleghe «Segna una delega ricevuta» chiede la finestra; il foglio da ristampare è in arancio, con «Ristampa il foglio» in arancio', async () => {
        const passi = VIA_ROMA();
        passi[3] = { ...passi[3], stato: 'adesso', manca: [
            { chiave: 'deleghe', fatto: true, informa: true, titolo: 'Deleghe ricevute', dettaglio: 'nessuna, per ora',
                collegamento: { testo: 'Segna una delega ricevuta', url: null, passo: null, apre: 'segna_delega' } },
            { chiave: 'foglio', fatto: false, informa: false, rifare: true, titolo: 'Il foglio della sala',
                dettaglio: 'una delega è cambiata dopo la stampa del 13/12 alle 17:00: ristampalo',
                collegamento: { testo: 'Ristampa il foglio', url: '/admin/gestionale/3/assemblee/9/sala/foglio', passo: null, stampa: true } },
        ] };
        monta({ passi, aperto: 'sala' });
        const voci = w!.findAll('[data-testid="che-cosa-manca"] li');
        await voci[0].get('[data-testid="pulsante-della-voce"]').trigger('click');
        expect(w!.emitted('apre')).toEqual([['segna_delega']]);
        expect(voci[1].get('[data-testid="icona"]').attributes('data-colore')).toBe('arancio');
        expect(voci[1].get('[data-testid="riga-della-voce"]').classes()).toContain('text-amber-800');
        expect(voci[1].get('[data-testid="riga-della-voce"]').text()).toBe('Una delega è cambiata dopo la stampa del 13/12 alle 17:00: ristampalo');
        expect(voci[1].get('[data-testid="pulsante-della-voce"]').classes()).toEqual(expect.arrayContaining(['h-7', 'bg-amber-50', 'text-amber-800']));
        expect(w!.find('[data-testid="da-fare"]').exists()).toBe(false);
        expect(voci[1].get('[data-testid="badge-della-voce"]').text()).toBe('Da fare');
    });

    test('una voce che porta a un altro passo lo apre', async () => {
        const passi = VIA_ROMA();
        passi[2].manca[0] = { chiave: 'avviso', fatto: false, informa: false, titolo: 'Date, luogo e ordine del giorno', dettaglio: 'Manca il luogo.', collegamento: { testo: 'Scrivilo', url: null, passo: 'date' } };
        monta({ passi });
        expect(w!.find('[data-testid="da-fare"]').exists()).toBe(false);
        expect(w!.findAll('[data-testid="badge-della-voce"]').map((b) => b.text())).toEqual(['Da fare', 'Da fare', 'Da fare']);
        await w!.get('[data-testid="che-cosa-manca"] li [data-testid="pulsante-della-voce"]').trigger('click');
        expect(w!.emitted('apri')).toEqual([['date']]);
    });

    test('una voce che informa (la tabella che non copre tutte le unità) non conta fra le cose da fare e non spegne «Avanti»', async () => {
        const passi = VIA_ROMA();
        passi[0].manca = [{ chiave: 'tabella', fatto: false, informa: true, titolo: 'La tabella del valore', dettaglio: '«Proprietà» · 6 unità su 7',
            collegamento: { testo: 'Controllala', url: '#tabella-del-valore', passo: null } }];
        monta({ passi, aperto: 'date' });
        const voce = w!.get('[data-testid="che-cosa-manca"] li');
        expect(voce.attributes('data-fatto')).toBe('informa');
        expect(voce.get('[data-testid="icona"]').attributes('data-colore')).toBe('azzurro');
        expect(voce.get('[data-testid="pulsante-della-voce"]').attributes('href')).toBe('#tabella-del-valore');
        // «Da vedere»: una cosa che non blocca (grigio), mai «Da fare».
        expect(voce.get('[data-testid="badge-della-voce"]').text()).toBe('Da vedere');
        expect(voce.get('[data-testid="badge-della-voce"]').classes().join(' ')).toContain('slate');
        expect(w!.get('[data-testid="avanti"]').attributes('disabled')).toBeUndefined();
    });

    test('una voce che porta a una stampa (il foglio della sala) apre il PDF in una scheda nuova, non con una visita del programma', () => {
        const passi = VIA_ROMA();
        passi[3] = { ...passi[3], stato: 'adesso', manca: [{ chiave: 'foglio', fatto: true, informa: true, titolo: 'Il foglio della sala', dettaglio: 'non ancora stampato',
            collegamento: { testo: 'Stampa il foglio', url: '/admin/gestionale/3/assemblee/9/sala/foglio', passo: null, stampa: true } }] };
        monta({ passi, aperto: 'sala' });
        const a = w!.get('[data-testid="che-cosa-manca"] li [data-testid="pulsante-della-voce"]');
        expect(a.text()).toBe('Stampa il foglio');
        expect([a.attributes('href'), a.attributes('target'), a.attributes('rel'), a.attributes('data-link')])
            .toEqual(['/admin/gestionale/3/assemblee/9/sala/foglio', '_blank', 'noopener', undefined]);
    });

    test('il piede: «← il passo prima», la frase, «Avanti» spento quando il passo non è fatto o il dopo è chiuso, acceso quando è fatto', async () => {
        monta();
        const piede = () => w!.get('[data-testid="piede-del-passo"]');
        expect(piede().text()).toContain('Il passo è fatto quando tutti i convocati hanno il loro avviso spedito.');
        await piede().get('[data-testid="passo-prima"]').trigger('click');
        expect(w!.emitted('apri')).toEqual([['documenti']]);
        expect(piede().get('[data-testid="avanti"]').text()).toBe('Avanti: In sala');
        expect(piede().get('[data-testid="avanti"]').attributes('disabled')).toBeDefined();

        // «Documenti» si può saltare: «Avanti» è acceso, e apre la convocazione.
        await w!.setProps({ aperto: 'documenti' });
        expect(piede().get('[data-testid="avanti"]').text()).toBe('Avanti: Convocazione');
        expect(piede().get('[data-testid="avanti"]').attributes('disabled')).toBeUndefined();
        await piede().get('[data-testid="avanti"]').trigger('click');
        expect(w!.emitted('apri')!.at(-1)).toEqual(['convocazione']);

        // Il primo passo non ha «← il passo prima».
        await w!.setProps({ aperto: 'date' });
        expect(piede().find('[data-testid="passo-prima"]').exists()).toBe(false);

        // La convocazione fatta, con la sala chiusa: «Avanti» resta spento e dice il perché.
        const passi = VIA_ROMA();
        passi[2] = { ...passi[2], stato: 'fatto', manca: [] };
        await w!.setProps({ passi, aperto: 'convocazione' });
        expect(piede().get('[data-testid="avanti"]').attributes('disabled')).toBeDefined();
        expect(piede().get('[data-testid="avanti"]').attributes('title')).toBe('si apre il 14/12');
    });

    test('un passo che viene dopo dice il perché, nel title e a chi legge lo schermo; il riquadro di un passo che non si apre non ha aria-disabled', async () => {
        const passi = VIA_ROMA();
        const perche = 'si apre quando «Date e ordine del giorno» è fatto';
        passi[0] = { ...passi[0], stato: 'adesso' };
        passi[1] = { ...passi[1], stato: 'dopo', perche };
        passi[2] = { ...passi[2], stato: 'dopo', perche, manca: [] };
        monta({ passi, aperto: 'documenti' });
        const documenti = sulla('documenti').get('[data-stato] > div, [data-passo="documenti"] > div');
        expect(documenti.attributes('title')).toBe(perche);
        expect(documenti.attributes('aria-disabled')).toBeUndefined();
        expect(documenti.get('.sr-only').text()).toContain(perche);
        expect(sulla('sala').get('div').attributes('aria-disabled')).toBeUndefined();
        // Un passo chiuso ha già il perché nella riga visibile: niente da ripetere.
        expect(sulla('sala').text()).toContain('si apre il 14/12');
        expect(sulla('sala').find('.sr-only').exists()).toBe(false);
        // Aperto da `?scheda=`, «Avanti» di un passo che viene dopo dice il perché del passo dopo, non la frase del piede.
        expect(w!.get('[data-testid="avanti"]').attributes('disabled')).toBeDefined();
        expect(w!.get('[data-testid="avanti"]').attributes('title')).toBe(perche);
        // Sul passo di adesso non fatto «Avanti» dice che cosa serve per farlo: la frase del piede.
        await w!.setProps({ aperto: 'date' });
        expect(w!.get('[data-testid="avanti"]').attributes('title')).toBe('Il passo è fatto quando le date valgono.');
    });

    test('una card «A posto» con una cosa che non blocca (il preventivo da fermare) è «Da vedere», non «Fatto»', () => {
        const passi = VIA_ROMA();
        passi[2].manca[0] = { chiave: 'avviso', fatto: true, informa: true, titolo: 'Date, luogo e ordine del giorno', dettaglio: 'a posto · il preventivo da fermare prima di spedire',
            collegamento: { testo: 'Apri «Documenti»', url: null, passo: 'documenti' } };
        monta({ passi });
        expect(w!.findAll('[data-testid="badge-della-voce"]')[0].text()).toBe('Da vedere');
    });

    test('al telefono, senza voci, il riquadro del titolo non lascia un vuoto', () => {
        monta({ aperto: 'documenti' });
        expect(w!.get('[data-testid="testa-del-passo"]').classes()).toEqual(expect.arrayContaining(['hidden', 'sm:block']));
        w!.unmount();
        monta();
        expect(w!.get('[data-testid="testa-del-passo"]').classes()).not.toContain('hidden');
    });

    test('«Vedi chi» su `#convocati` già nell\'indirizzo avvisa di nuovo la pagina, che mostra tutte le righe', async () => {
        monta();
        window.location.hash = '#convocati';
        const visto = vi.fn();
        window.addEventListener('hashchange', visto);
        await w!.findAll('[data-testid="che-cosa-manca"] li')[2].get('[data-testid="pulsante-della-voce"]').trigger('click');
        window.removeEventListener('hashchange', visto);
        window.location.hash = '';
        expect(visto).toHaveBeenCalled();
    });

    test('al telefono: «Passo 3 di 5», il nome, la barra di avanzamento; l\'elenco dei passi si apre con un tocco', async () => {
        monta();
        const telefono = w!.get('[data-testid="strada-telefono"]');
        expect(telefono.classes()).toContain('sm:hidden');
        expect(scrivania().classes()).toContain('hidden');
        expect(scrivania().classes()).toContain('sm:grid');
        expect(telefono.text()).toContain('Passo 3 di 5');
        expect(telefono.text()).toContain('Convocazione');
        expect((telefono.get('[data-testid="barra"]').element as HTMLElement).style.width).toBe('40%');
        expect(telefono.find('[data-testid="elenco-dei-passi"]').exists()).toBe(false);
        const tocco = telefono.get('[data-testid="apri-elenco"]');
        expect(tocco.attributes('aria-expanded')).toBe('false');
        await tocco.trigger('click');
        const righe = w!.findAll('[data-testid="elenco-dei-passi"] li');
        expect(righe).toHaveLength(5);
        expect(righe[3].text()).toContain('si apre il 14/12');
        expect(righe[3].find('button').exists()).toBe(false);
        await righe[0].get('button').trigger('click');
        expect(w!.emitted('apri')).toEqual([['date']]);
        // Al telefono «Avanti» è largo quanto lo schermo.
        expect(w!.get('[data-testid="avanti"]').classes()).toContain('w-full');
    });

    test('la strada non ha più il collegamento «Diario dell\'assemblea»: solo i cinque passi', async () => {
        monta();
        expect(w!.find('[data-testid="apri-diario"]').exists()).toBe(false);
        expect(w!.text()).not.toContain('Diario dell\'assemblea');
        expect(w!.findAll('[data-testid="strada"] li')).toHaveLength(5);
    });

    test('l\'azione del piede si cambia con lo slot (in «Prepara l\'assemblea» il pulsante di oggi), senza «Avanti»', () => {
        monta({ aperto: 'date' }, { azione: '<button data-testid="prepara-assemblea">Prepara l\'assemblea</button>' });
        expect(w!.get('[data-testid="piede-del-passo"] [data-testid="prepara-assemblea"]').text()).toBe('Prepara l\'assemblea');
        expect(w!.find('[data-testid="avanti"]').exists()).toBe(false);
    });

    test('niente scritte sotto i 12 pixel, il tema scuro, mai v-html', async () => {
        const { readFileSync } = await import('node:fs');
        const { resolve } = await import('node:path');
        const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/components/assemblee/StradaDellAssemblea.vue'), 'utf8');
        expect(sorgente).not.toMatch(/text-\[(\d|1[01])px\]/);
        expect(sorgente).not.toContain('v-html');
        expect(sorgente).toContain('dark:');
        expect(sorgente).not.toMatch(/rounded-full[^"]*px-/);
    });
});
