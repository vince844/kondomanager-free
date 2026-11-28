// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il riquadro «Le letture di fine anno» (`LettureDiFineAnno.vue`) e dove compare nel dettaglio della voce (`DettaglioConto.vue`): solo su una voce di
 * spesa con più tabelle, anche con la ripartizione bloccata. Il caso della bozza approvata: generale 10.000 kWh, ascensore 5.000, impianto termico 2.000,
 * luci per differenza 3.000: 50/20/30.
 *
 * I campi vuoti si evidenziano («da scrivere») e sotto c'è una riga sola con quanti sono e che cosa fare: il consiglio di «Per differenza» solo dove serve
 * (non quando manca solo il contatore generale, né quando una tabella lo è già). Gli altri errori restano scritti per intero. In un esercizio chiuso non si
 * evidenzia niente e non si chiede di scrivere.
 *
 * Cosa resta scoperto: il salvataggio (Inertia e il server, `Beta15LettureDiFineAnnoTest`); il tema scuro.
 */
import { afterEach, describe, expect, test, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

import LettureDiFineAnno from './LettureDiFineAnno.vue'

const DettaglioConto = (await import('./DettaglioConto.vue')).default

const CASO = {
  esercizio_chiuso: false, data_fine: '2026-12-31', salvate: true, da_riscrivere: false, unita: 'kWh', generale: 10000, differenza_lettura: 3000,
  tabelle: [
    { tabella_id: 1, nome: 'Ascensore', preventivo: 40, modo: 'lettura', lettura: 5000, percentuale: null, consuntivo: 50 },
    { tabella_id: 2, nome: 'Impianto termico', preventivo: 20, modo: 'lettura', lettura: 2000, percentuale: null, consuntivo: 20 },
    { tabella_id: 3, nome: 'Luci scale', preventivo: 40, modo: 'differenza', lettura: null, percentuale: null, consuntivo: 30 },
  ],
}

const conFetch = (dati: unknown) => {
  const f = vi.fn(async () => ({ ok: true, json: async () => dati }))
  vi.stubGlobal('fetch', f)

  return f
}

afterEach(() => { vi.unstubAllGlobals() })

const testo = (w: { text: () => string }) => w.text().replace(/\s+/g, ' ')

describe('LettureDiFineAnno', () => {
  test('legge le letture della voce e mostra il preventivo, la lettura al 31/12 e le percentuali del consuntivo', async () => {
    const f = conFetch(CASO)
    const w = mount(LettureDiFineAnno, { props: { condominioId: 1, esercizioId: 2, pianoContoId: 3, contoId: 4 } })
    await flushPromises()

    expect(f).toHaveBeenCalledWith('/admin/gestionale/1/esercizi/2/piani-conti/3/conti/4/letture-di-fine-anno', expect.anything())
    const t = testo(w)
    expect(t).toContain('Le letture di fine anno')
    expect(t).toContain('Il conguaglio divide la spesa con queste percentuali, non con quelle del preventivo.')
    expect(t).toContain('Al 31/12')
    expect(t).toContain('Ascensore')
    expect(t).toMatch(/Ascensore.*40 %.*50 %/)
    // Nella scheda della tabella la cifra «per differenza» sta accanto al modo, sopra il preventivo e il consuntivo.
    expect(t).toMatch(/Luci scale.*3\.000.*40 %.*30 %/)
    expect(t).toContain('Somma 100 % · le letture tornano con il contatore generale')
    expect(t).toContain('Salvate')
  })

  test('una lettura oltre il generale si dice subito, e il salvataggio si spegne', async () => {
    conFetch(CASO)
    const w = mount(LettureDiFineAnno, { props: { condominioId: 1, esercizioId: 2, pianoContoId: 3, contoId: 4 } })
    await flushPromises()

    await w.find('input[aria-label="Lettura di Impianto termico"]').setValue('6.000')

    expect(testo(w)).toContain('Le letture delle tabelle fanno 11.000 kWh e superano il contatore generale, 10.000 kWh: controlla le letture.')
    expect(w.findAll('button').find(b => b.text() === 'Salva le letture')!.attributes('disabled')).toBeDefined()
  })

  const VUOTE = { ...CASO, salvate: false, generale: null, differenza_lettura: null, tabelle: [
    { tabella_id: 1, nome: 'Ascensore', preventivo: 40, modo: 'lettura', lettura: null, percentuale: null, consuntivo: null },
    { tabella_id: 2, nome: 'Impianto termico', preventivo: 20, modo: 'lettura', lettura: null, percentuale: null, consuntivo: null },
    { tabella_id: 3, nome: 'Luci scale', preventivo: 40, modo: 'differenza', lettura: null, percentuale: null, consuntivo: null },
  ] }
  const campo = (w: ReturnType<typeof mount>, etichetta: string) => w.get(`input[aria-label="${etichetta}"]`)
  const monta = async (dati: unknown) => {
    conFetch(dati)
    const w = mount(LettureDiFineAnno, { props: { condominioId: 1, esercizioId: 2, pianoContoId: 3, contoId: 4 } })
    await flushPromises()

    return w
  }

  test('i campi vuoti si evidenziano, e in fondo c\'è una riga sola con quanti sono, non una per campo', async () => {
    const w = await monta({ ...VUOTE, tabelle: [VUOTE.tabelle[0], VUOTE.tabelle[1], { ...VUOTE.tabelle[2], modo: 'percentuale' }] })

    for (const etichetta of ['Lettura del contatore generale', 'Lettura di Ascensore', 'Lettura di Impianto termico', 'Percentuale di Luci scale']) {
      expect(campo(w, etichetta).classes()).toContain('border-amber-400')
      expect(campo(w, etichetta).attributes('placeholder')).toBe('da scrivere')
    }
    expect(testo(w)).toContain('Mancano 4 valori: scrivi i campi evidenziati, o scegli «Per differenza» per una tabella.')
    expect(testo(w)).not.toContain('Manca la lettura di')
    expect(testo(w)).not.toContain('Manca la lettura del contatore generale')
  })

  test('con un solo campo vuoto «Manca 1 valore», e solo quello è evidenziato', async () => {
    const w = await monta({ ...CASO, tabelle: [{ ...CASO.tabelle[0], lettura: null }, CASO.tabelle[1], CASO.tabelle[2]] })

    expect(testo(w)).toContain('Manca 1 valore: scrivi il campo evidenziato.')
    expect(testo(w)).not.toContain('o scegli «Per differenza»')
    expect(campo(w, 'Lettura di Ascensore').classes()).toContain('border-amber-400')
    expect(campo(w, 'Lettura di Impianto termico').classes()).not.toContain('border-amber-400')
    expect(campo(w, 'Lettura del contatore generale').classes()).not.toContain('border-amber-400')
  })

  test('un errore che non è un campo vuoto resta scritto per intero, accanto al conto di quelli vuoti', async () => {
    const w = await monta({ ...CASO, tabelle: [{ ...CASO.tabelle[0], lettura: null }, { ...CASO.tabelle[1], modo: 'differenza' }, CASO.tabelle[2]] })

    expect(testo(w)).toContain('Manca 1 valore')
    expect(testo(w)).toContain('Al più una tabella si calcola «per differenza»: «Impianto termico» e «Luci scale» lo sono tutte e due.')
  })

  test('con una sola tabella vuota e nessuna «per differenza» il consiglio è per quella tabella', async () => {
    const w = await monta({ ...CASO, tabelle: [{ ...CASO.tabelle[0], lettura: null }, CASO.tabelle[1], { ...CASO.tabelle[2], modo: 'percentuale', percentuale: 30 }] })

    expect(testo(w)).toContain('Manca 1 valore: scrivi il campo evidenziato, o scegli «Per differenza» per quella tabella.')
  })

  test('se manca solo il contatore generale lo dice, senza consigliare «Per differenza», che non servirebbe', async () => {
    const w = await monta({ ...CASO, salvate: false, generale: null })

    expect(testo(w)).toContain('Manca la lettura del contatore generale: scrivila nel campo evidenziato.')
    expect(testo(w)).not.toContain('o scegli «Per differenza»')
    expect(testo(w)).not.toContain('Manca 1 valore')
    expect(campo(w, 'Lettura del contatore generale').classes()).toContain('border-amber-400')
  })

  test('con una tabella già «per differenza» non la consiglia di nuovo', async () => {
    const w = await monta({ ...VUOTE, tabelle: [VUOTE.tabelle[0], VUOTE.tabelle[1], VUOTE.tabelle[2]] })

    // VUOTE ha già «Luci scale» per differenza: mancano il contatore generale e due tabelle.
    expect(testo(w)).toContain('Mancano 3 valori: scrivi i campi evidenziati.')
    expect(testo(w)).not.toContain('o scegli «Per differenza»')
  })

  test('senza letture salvate il badge dice di scriverle prima del rendiconto', async () => {
    const w = await monta({ ...CASO, salvate: false })

    expect(testo(w)).toContain('Da scrivere prima del rendiconto')
  })

  test('in un esercizio chiuso niente campi evidenziati, niente riepilogo di quelli che mancano, niente invito a scrivere, e niente pulsanti', async () => {
    const w = await monta({ ...VUOTE, esercizio_chiuso: true })

    const t = testo(w)
    expect(t).toContain('L\'esercizio è chiuso: le letture si leggono e non si cambiano.')
    expect(t).toContain('Non scritte')
    expect(t).not.toContain('Da scrivere prima del rendiconto')
    expect(t).toContain('Le letture di fine anno di questa voce non sono state scritte: la spesa si divide con le percentuali del preventivo.')
    expect(t).not.toContain('con queste percentuali')
    expect(t).not.toMatch(/Manca(no)? /)
    expect(t).not.toContain('Per differenza»')
    expect(t).not.toContain('Al più una tabella si calcola')
    for (const input of w.findAll('input')) {
      expect(input.classes()).not.toContain('border-amber-400')
      expect(input.attributes('placeholder') ?? '').toBe('')
    }
    expect(w.findAll('button').some(b => b.text() === 'Salva le letture')).toBe(false)
  })

  test('in un esercizio chiuso le letture salvate per tabelle poi cambiate non invitano a riscriverle', async () => {
    const w = await monta({ ...CASO, esercizio_chiuso: true, da_riscrivere: true, tabelle: [{ ...CASO.tabelle[0], lettura: null }, CASO.tabelle[1], CASO.tabelle[2]] })

    const t = testo(w)
    expect(t).toContain('Le tabelle della voce sono cambiate dopo le letture: il conguaglio usa le percentuali del preventivo.')
    expect(t).not.toContain('finché non le riscrivi')
    expect(t).not.toContain('Da riscrivere')
    expect(t).toContain('Non usate')
    expect(t).not.toMatch(/Manca(no)? /)
  })

  test('in un esercizio chiuso con le letture che tornano la riga della somma resta', async () => {
    const w = await monta({ ...CASO, esercizio_chiuso: true })

    expect(testo(w)).toContain('Somma 100 % · le letture tornano con il contatore generale')
    expect(testo(w)).toContain('Salvate')
  })
})

describe('DettaglioConto — il riquadro delle letture', () => {
  const voce = (tabelle: number) => ({
    id: 7, nome: 'Energia elettrica', tipo: 'spesa', parent_id: 3, is_capitolo: false, piano_conto_id: 9, importo: '€ 3.000,00', importo_raw: 300000, budget_originale_raw: 300000,
    speso_raw: 0, impegnato: 0, percentuale_copertura: 0, sottoconti: [], blocco: { frase: 'Bloccata' },
    tabelle_millesimali: Array.from({ length: tabelle }, (_, i) => ({ id: i + 1, conto_id: 7, tabella_id: i + 1, coefficiente: 100 / tabelle, tabella: { id: i + 1, nome: `T${i + 1}` }, ripartizioni: [] })),
  })
  const monta = (tabelle: number) => mount(DettaglioConto, {
    props: { conto: voce(tabelle) as never, condominioId: 1, esercizioId: 1, gestioneId: 1 },
    global: { stubs: { Link: { template: '<a><slot /></a>' } } },
  })

  test('con tre tabelle c\'è, anche con la ripartizione bloccata', async () => {
    conFetch(CASO)
    const w = monta(3)
    await flushPromises()
    expect(w.find('[data-testid="letture-di-fine-anno"]').exists()).toBe(true)
  })

  test('con una tabella sola no', () => {
    conFetch(CASO)
    expect(monta(1).find('[data-testid="letture-di-fine-anno"]').exists()).toBe(false)
  })
})
