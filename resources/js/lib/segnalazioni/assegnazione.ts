// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

export interface AssegnatarioFiltro {
  id: number;
  name: string;
  attivo?: boolean;
}

export interface OpzioneFiltroAssegnazione {
  value: string;
  label: string;
}

export function opzioniFiltroAssegnazione(
  assegnatari?: AssegnatarioFiltro[] | null,
  io?: number | null
): OpzioneFiltroAssegnazione[] {
  const opzioni: OpzioneFiltroAssegnazione[] = [
    { value: 'mie', label: 'segnalazioni.assignment.filter_mine' },
    { value: 'nessuno', label: 'segnalazioni.assignment.filter_nobody' },
  ];

  if (!assegnatari || !Array.isArray(assegnatari)) {
    return opzioni;
  }

  for (const persona of assegnatari) {
    if (io !== undefined && io !== null && persona.id === io) {
      continue;
    }
    opzioni.push({
      value: String(persona.id),
      label: persona.name,
    });
  }

  return opzioni;
}

export interface AssegnataAInfo {
  id?: number;
  name?: string;
  attivo?: boolean;
  da?: string | null;
  il?: string | null;
  alle?: string | null;
}

export interface RigaAssegnazione {
  chiave: string;
  argomenti: {
    user?: string;
    date: string;
    time: string;
  };
}

export function rigaAssegnazione(
  assegnataA?: AssegnataAInfo | null
): RigaAssegnazione | null {
  if (!assegnataA || !assegnataA.il) {
    return null;
  }

  const date = assegnataA.il;
  const time = assegnataA.alle ?? '';

  if (assegnataA.da) {
    return {
      chiave: 'segnalazioni.assignment.assigned_by',
      argomenti: {
        user: assegnataA.da,
        date,
        time,
      },
    };
  }

  return {
    chiave: 'segnalazioni.assignment.assigned_on',
    argomenti: {
      date,
      time,
    },
  };
}
