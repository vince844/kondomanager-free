// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il gesto che sostituisce (N2 del progetto del verbale): per il testo la coda tiene **un solo gesto in attesa per parte**, l'ultimo; quello già partito senza
 * risposta resta, e il nuovo parte dopo la sua risposta. Il nuovo si scrive sul disco prima di togliere quello che sostituisce; un gesto segnato con
 * `parti: false` aspetta che lo si mandi; i gesti senza chiave (la sala) restano tutti.
 */
import { describe, expect, test } from 'vitest';
import { CodaDeiGesti, type OpzioniDellaCoda } from './coda';
import { DepositoInMemoria, type VoceInCoda } from './deposito';
import { ErroreDellInvio, type LottoDaMandare, type RispostaDelServer } from './invio';

const doc = (t: string) => ({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: t }] }] });

/** Il server finto dei testi: risponde con la revisione; con `blocco` trattiene la risposta (il gesto partito senza risposta). */
function server() {
    const s = { rete: true, lotti: [] as LottoDaMandare[], revisione: 3, blocco: null as Promise<void> | null };
    const invia = async (lotto: LottoDaMandare): Promise<RispostaDelServer> => {
        s.lotti.push(JSON.parse(JSON.stringify(lotto)));
        if (!s.rete) throw new ErroreDellInvio('rete');
        if (s.blocco) await s.blocco;
        return { ora_del_server: '2026-12-15T19:31:00Z', risposte: lotto.gesti.map((g) => ({ gesto: g.gesto, tipo: g.tipo, esito: 'applicato', messaggio: null,
            parte: (g.dati as { parte: string }).parte, revisione: ++s.revisione })) };
    };

    return { s, invia };
}

let n = 0;
function coda(deposito: DepositoInMemoria, invia: OpzioniDellaCoda['invia']) {
    return new CodaDeiGesti({ deposito, ambito: 'verbale:12:5', dispositivo: 'portatile di Andrea', invia, numero: () => `00000000-0000-4000-8000-${String(++n).padStart(12, '0')}`,
        schede: async (_n, lavoro) => {
            await lavoro();
            return true;
        } });
}

const testo = (c: CodaDeiGesti, parte: string, t: string, parti = false) => c.segna('testo', { verbale_id: 7, parte, documento: doc(t) }, 3, undefined, { fondi: 'testo:' + parte, parti });

describe('il gesto che sostituisce', () => {
    test('tre salvataggi della stessa parte senza rete lasciano un gesto solo in coda, l\'ultimo; l\'altra parte resta; i gesti senza chiave restano tutti', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        s.rete = false;
        const c = coda(deposito, invia);
        await testo(c, 'punto:4', 'P');
        await testo(c, 'punto:4', 'Pre');
        await testo(c, 'intero', 'Il verbale');
        const ultima = await testo(c, 'punto:4', 'Preventivi');
        await c.segna('voto', { votazione_id: 9, testa: 'p-1', voto: 'favorevole' }, 0, undefined, { parti: false });
        await c.segna('voto', { votazione_id: 9, testa: 'p-2', voto: 'contrario' }, 0, undefined, { parti: false });

        const sulDisco = await deposito.inCoda('verbale:12:5');
        expect(sulDisco.map((v) => [v.tipo, (v.dati as { parte?: string }).parte ?? null])).toEqual([['testo', 'intero'], ['testo', 'punto:4'], ['voto', null], ['voto', null]]);
        expect(sulDisco.find((v) => v.gesto === ultima.gesto)?.dati).toMatchObject({ documento: doc('Preventivi') });
        expect(c.stato.inCoda.map((v) => v.gesto)).toEqual(sulDisco.map((v) => v.gesto));
        expect(s.lotti).toEqual([]);
    });

    test('quello già partito senza risposta resta, e il nuovo parte dopo la sua risposta, nel lotto dopo', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        const c = coda(deposito, invia);
        let lascia!: () => void;
        s.blocco = new Promise<void>((r) => (lascia = r));
        const primo = await testo(c, 'punto:4', 'Uno');
        const inVolo = c.prova();
        await new Promise((r) => setTimeout(r, 0));
        expect(c.partitiSenzaRisposta.has(primo.gesto)).toBe(true);

        // Mentre il primo aspetta la risposta, si scrive ancora: due salvataggi, che si fondono fra loro ma non con il primo.
        await testo(c, 'punto:4', 'Uno due');
        const terzo = await testo(c, 'punto:4', 'Uno due tre');
        expect((await deposito.inCoda('verbale:12:5')).map((v) => v.gesto)).toEqual([primo.gesto, terzo.gesto]);

        s.blocco = null;
        lascia();
        await inVolo;
        await c.prova();
        expect(s.lotti.map((l) => l.gesti.map((g) => g.gesto))).toEqual([[primo.gesto], [terzo.gesto]]);
        expect((s.lotti[1].gesti[0].dati as { documento: unknown }).documento).toEqual(doc('Uno due tre'));
        expect(await deposito.inCoda('verbale:12:5')).toEqual([]);
    });

    test('con parti: false il gesto aspetta che lo si mandi; di serie parte subito', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        const c = coda(deposito, invia);
        await testo(c, 'punto:4', 'Aspetta');
        await new Promise((r) => setTimeout(r, 0));
        expect(s.lotti).toEqual([]);
        await c.prova();
        expect(s.lotti).toHaveLength(1);

        await testo(c, 'punto:4', 'Subito', true);
        await new Promise((r) => setTimeout(r, 0));
        await c.prova();
        expect(s.lotti.map((l) => (l.gesti[0].dati as { documento: unknown }).documento)).toEqual([doc('Aspetta'), doc('Subito')]);
    });

    test('il nuovo si scrive sul disco prima di togliere quello che sostituisce: spegnendo in mezzo, restano tutti e due', async () => {
        const operazioni: string[] = [];
        class DepositoSpia extends DepositoInMemoria {
            async aggiungi(v: VoceInCoda) {
                operazioni.push('aggiungi ' + (v.dati as { documento: { content: { content: { text: string }[] }[] } }).documento.content[0].content[0].text);
                await super.aggiungi(v);
            }

            async togli(gesti: string[]) {
                if (gesti.length > 0) operazioni.push('togli ' + gesti.length);
                await super.togli(gesti);
            }
        }
        const deposito = new DepositoSpia();
        const { s, invia } = server();
        s.rete = false;
        const c = coda(deposito, invia);
        await testo(c, 'punto:4', 'Prima');
        await testo(c, 'punto:4', 'Dopo');
        expect(operazioni).toEqual(['aggiungi Prima', 'aggiungi Dopo', 'togli 1']);
    });

    test('un gesto ritrovato sostituito non si conta più fra i ritrovati', async () => {
        const deposito = new DepositoInMemoria();
        const { s, invia } = server();
        s.rete = false;
        const prima = coda(deposito, invia);
        await testo(prima, 'punto:4', 'Scritto prima dello spegnimento');
        const dopo = coda(deposito, invia);
        await dopo.avvia();
        expect(dopo.stato.ritrovati?.quanti).toBe(1);
        await testo(dopo, 'punto:4', 'Scritto dopo la riaccensione');
        expect(dopo.stato.ritrovati).toBeNull();
        expect((await deposito.inCoda('verbale:12:5')).map((v) => (v.dati as { documento: unknown }).documento)).toEqual([doc('Scritto dopo la riaccensione')]);
    });
});
