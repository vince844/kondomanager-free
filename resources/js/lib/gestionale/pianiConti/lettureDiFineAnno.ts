// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le letture di fine anno di una voce, calcolate nella pagina mentre si scrivono: la stessa regola del server (`LettureDiFineAnno::calcola`), che resta
 * quella che decide al salvataggio. Dalle letture le percentuali (lettura / contatore generale), una percentuale scritta vale com'è, al più una tabella
 * «per differenza» (100 meno le altre); a due decimali, con il resto del centesimo di punto sulla tabella per differenza o sull'ultima.
 */

export type ModoLettura = 'lettura' | 'percentuale' | 'differenza'

export interface RigaLettura {
  tabella_id: number
  nome: string
  modo: ModoLettura
  lettura: number | null
  percentuale: number | null
}

/** Un campo ancora da scrivere: `tabella_id` nullo è il contatore generale. La frase è la stessa che sta fra gli errori. */
export interface CampoMancante {
  tabella_id: number | null
  frase: string
}

export interface EsitoLetture {
  percentuali: Record<number, number>
  errori: string[]
  differenzaLettura: number | null
  mancanti: CampoMancante[]
}

const arrotonda = (v: number, cifre = 2) => Math.round(v * 10 ** cifre) / 10 ** cifre

/**
 * Un numero all'italiana, con il punto delle migliaia anche a quattro cifre («3.000», come il server) e senza decimali inutili: `toLocaleString('it-IT')`
 * scrive «3000», perché in italiano il separatore delle migliaia comincia dalle cinque cifre.
 */
export const numeroItaliano = (v: number, decimali = 3): string => {
  const [intera, frazione = ''] = Math.abs(arrotonda(v, decimali)).toFixed(decimali).split('.')
  const cifre = frazione.replace(/0+$/, '')

  return `${v < 0 ? '-' : ''}${intera.replace(/\B(?=(\d{3})+(?!\d))/g, '.')}${cifre ? `,${cifre}` : ''}`
}

/** «10.000 kWh». */
export const quantita = (v: number, unita = 'kWh') => `${numeroItaliano(v)} ${unita}`

/** «99,99 %». */
export const percento = (v: number) => `${numeroItaliano(v, 2)} %`

export function calcolaLetture(generale: number | null, righe: RigaLettura[], unita = 'kWh'): EsitoLetture {
  const errori: string[] = []
  const mancanti: CampoMancante[] = []
  const manca = (tabella_id: number | null, frase: string) => {
    mancanti.push({ tabella_id, frase })
    errori.push(frase)
  }
  const nome = (r: RigaLettura) => `«${r.nome}»`
  const perDifferenza = righe.filter(r => r.modo === 'differenza')
  const conLettura = righe.filter(r => r.modo === 'lettura')
  if (perDifferenza.length > 1) {
    errori.push(`Al più una tabella si calcola «per differenza»: ${perDifferenza.map(nome).join(' e ')} lo sono tutte e due.`)
  }
  for (const r of righe) {
    const valore = r.modo === 'lettura' ? r.lettura : r.modo === 'percentuale' ? r.percentuale : 0
    if (valore === null || Number.isNaN(valore)) {
      manca(r.tabella_id, `Manca ${r.modo === 'lettura' ? 'la lettura' : 'la percentuale'} di ${nome(r)}: scrivila, o scegli «per differenza».`)
    }
  }
  if (conLettura.length > 0 && (generale === null || !(generale > 0))) {
    manca(null, 'Manca la lettura del contatore generale: senza, le letture delle tabelle non diventano percentuali.')
  }
  if (errori.length > 0) return { percentuali: {}, errori, differenzaLettura: null, mancanti }

  const g = generale ?? 0
  const sommaLetture = conLettura.reduce((s, r) => s + (r.lettura ?? 0), 0)
  if (conLettura.length > 0 && sommaLetture > g + 0.0005) {
    return { percentuali: {}, errori: [`Le letture delle tabelle fanno ${quantita(sommaLetture, unita)} e superano il contatore generale, ${quantita(g, unita)}: controlla le letture.`], differenzaLettura: null, mancanti }
  }

  const grezze: Record<number, number> = {}
  for (const r of righe) {
    if (r.modo === 'lettura') grezze[r.tabella_id] = 100 * (r.lettura ?? 0) / g
    else if (r.modo === 'percentuale') grezze[r.tabella_id] = r.percentuale ?? 0
  }
  const altre = Object.values(grezze).reduce((s, v) => s + v, 0)
  let differenzaLettura: number | null = null
  if (perDifferenza.length === 1) {
    if (arrotonda(altre) > 100) {
      return { percentuali: {}, errori: [`${nome(perDifferenza[0])} per differenza resterebbe sotto zero: le altre tabelle fanno già ${percento(altre)}.`], differenzaLettura: null, mancanti }
    }
    grezze[perDifferenza[0].tabella_id] = 100 - altre
    if (conLettura.length === righe.length - 1) differenzaLettura = arrotonda(g - sommaLetture, 3)
  } else if (Math.abs(arrotonda(altre) - 100) > 0.0001) {
    const errore = conLettura.length > 0 && conLettura.length === righe.length && sommaLetture < g
      ? `Le letture delle tabelle fanno ${quantita(sommaLetture, unita)}, il contatore generale ${quantita(g, unita)}: ${quantita(g - sommaLetture, unita)} non sono di nessuna tabella. Scegli una tabella «per differenza», o controlla le letture.`
      : `Le percentuali fanno ${percento(altre)}, non 100 %: correggi una tabella, o scegline una «per differenza».`

    return { percentuali: {}, errori: [errore], differenzaLettura: null, mancanti }
  }

  const percentuali: Record<number, number> = {}
  for (const r of righe) percentuali[r.tabella_id] = arrotonda(grezze[r.tabella_id] ?? 0)
  const suDiLei = perDifferenza.length === 1 ? perDifferenza[0].tabella_id : righe[righe.length - 1].tabella_id
  const somma = Object.values(percentuali).reduce((s, v) => s + v, 0)
  percentuali[suDiLei] = Math.max(0, arrotonda(percentuali[suDiLei] + 100 - somma))

  return { percentuali, errori: [], differenzaLettura, mancanti }
}
