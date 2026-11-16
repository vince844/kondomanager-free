// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il risultato del rendiconto a parole, una sola volta per tutte le pagine: zero è «in pareggio», sotto zero «disavanzo», sopra «avanzo». Le stampe
 * dicono lo stesso con `RiepilogoPerCompetenza::comeRisultato()`: due funzioni, le stesse tre parole (Coda 560).
 */
export const comeRisultato = (risultato: number): string => (risultato === 0 ? 'in pareggio' : (risultato < 0 ? 'disavanzo' : 'avanzo'));
