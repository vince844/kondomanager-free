// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le letture di fine anno calcolate nella pagina (`calcolaLetture`): le stesse cifre e le stesse frasi del server (`LettureDiFineAnno::calcola`,
 * `Beta15LettureDiFineAnnoTest`). Il caso: contatore generale 10.000 kWh, ascensore 5.000, impianto termico 2.000, luci per differenza.
 *
 * `mancanti` dice quali campi sono ancora da scrivere, ognuno con la sua frase (che è anche fra gli errori): la pagina li evidenzia senza leggere il testo.
 *
 * Cosa resta scoperto: il salvataggio (lo decide il server).
 */
import { describe, expect, test } from 'vitest'
import { calcolaLetture, type RigaLettura } from './lettureDiFineAnno'

const righe = (per: Partial<Record<number, Partial<RigaLettura>>> = {}): RigaLettura[] => [
  { tabella_id: 1, nome: 'Ascensore', modo: 'lettura', lettura: 5000, percentuale: null, ...per[1] },
  { tabella_id: 2, nome: 'Impianto termico', modo: 'lettura', lettura: 2000, percentuale: null, ...per[2] },
  { tabella_id: 3, nome: 'Luci scale', modo: 'differenza', lettura: null, percentuale: null, ...per[3] },
]

describe('calcolaLetture', () => {
  test('il caso: 50/20/30, e la differenza delle luci è 3.000 kWh', () => {
    expect(calcolaLetture(10000, righe())).toEqual({ percentuali: { 1: 50, 2: 20, 3: 30 }, errori: [], differenzaLettura: 3000, mancanti: [] })
  })

  test('le percentuali a due decimali, con il resto sull\'ultima tabella', () => {
    const esito = calcolaLetture(3, righe({ 1: { lettura: 1 }, 2: { lettura: 1 }, 3: { modo: 'lettura', lettura: 1 } }))
    expect(esito.percentuali).toEqual({ 1: 33.33, 2: 33.33, 3: 33.34 })
  })

  test('la percentuale scritta vale com\'è', () => {
    expect(calcolaLetture(10000, righe({ 2: { modo: 'percentuale', percentuale: 25 } })).percentuali).toEqual({ 1: 50, 2: 25, 3: 25 })
  })

  test('i rifiuti, con le frasi del server', () => {
    expect(calcolaLetture(10000, righe({ 2: { lettura: 6000 } })).errori).toEqual(['Le letture delle tabelle fanno 11.000 kWh e superano il contatore generale, 10.000 kWh: controlla le letture.'])
    expect(calcolaLetture(10000, righe({ 3: { modo: 'lettura', lettura: 2500 } })).errori[0]).toContain('500 kWh non sono di nessuna tabella')
    expect(calcolaLetture(10000, righe({ 2: { modo: 'differenza' } })).errori[0]).toContain('Al più una tabella si calcola «per differenza»')
    expect(calcolaLetture(null, righe()).errori).toEqual(['Manca la lettura del contatore generale: senza, le letture delle tabelle non diventano percentuali.'])
    expect(calcolaLetture(null, righe({ 1: { modo: 'percentuale', percentuale: 40 }, 2: { modo: 'percentuale', percentuale: 20 }, 3: { modo: 'percentuale', percentuale: 39.99 } })).errori)
      .toEqual(['Le percentuali fanno 99,99 %, non 100 %: correggi una tabella, o scegline una «per differenza».'])
  })

  test('i campi da scrivere: il contatore generale (senza tabella) e le tabelle, ognuno con la frase che è fra gli errori', () => {
    const esito = calcolaLetture(null, righe({ 1: { lettura: null }, 2: { modo: 'percentuale', percentuale: Number.NaN } }))

    expect(esito.mancanti.map(m => m.tabella_id)).toEqual([1, 2, null])
    expect(esito.errori).toEqual(esito.mancanti.map(m => m.frase))
    expect(esito.mancanti.map(m => m.frase)).toEqual([
      'Manca la lettura di «Ascensore»: scrivila, o scegli «per differenza».',
      'Manca la percentuale di «Impianto termico»: scrivila, o scegli «per differenza».',
      'Manca la lettura del contatore generale: senza, le letture delle tabelle non diventano percentuali.',
    ])
  })

  test('un contatore generale a zero è da scrivere; un errore che non è un campo vuoto non è fra i mancanti', () => {
    expect(calcolaLetture(0, righe()).mancanti.map(m => m.tabella_id)).toEqual([null])
    const esito = calcolaLetture(10000, righe({ 1: { lettura: null }, 2: { modo: 'differenza' } }))
    expect(esito.mancanti.map(m => m.tabella_id)).toEqual([1])
    expect(esito.errori).toHaveLength(2)
    expect(esito.errori[0]).toContain('Al più una tabella')
    expect(calcolaLetture(10000, righe({ 2: { lettura: 6000 } })).mancanti).toEqual([])
  })
})
