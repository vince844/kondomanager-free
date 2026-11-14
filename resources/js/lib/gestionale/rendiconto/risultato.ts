/**
 * Il risultato del rendiconto a parole, una sola volta per tutte le pagine: zero è «in pareggio», sotto zero «disavanzo», sopra «avanzo». Le stampe
 * dicono lo stesso con `RiepilogoPerCompetenza::comeRisultato()`: due funzioni, le stesse tre parole (Coda 560).
 */
export const comeRisultato = (risultato: number): string => (risultato === 0 ? 'in pareggio' : (risultato < 0 ? 'disavanzo' : 'avanzo'));
