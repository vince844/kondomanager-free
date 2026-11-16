// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// columns.ts
import { h } from 'vue'
import DropdownAction from '@/components/gestionale/esercizi/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/esercizi/DataTableColumnHeader.vue'
import { statusConstants } from '@/lib/gestionale/esercizi/constants';
import { useDateConverter } from '@/composables/useDateConverter';
import type { ColumnDef } from '@tanstack/vue-table'
import type { Esercizio } from '@/types/gestionale/esercizi'
import type { Building } from '@/types/buildings'

const { toItalian } = useDateConverter();

/** Lo stato del rendiconto di un esercizio, come lo manda il server: in bozza, fermato per l'assemblea, approvato. */
export interface StatoRendicontoBreve { stato: 'bozza' | 'presentata' | 'approvata'; frase: string; breve: string }

export function getColumns(condominio: Building, statiRendiconto: Record<number, StatoRendicontoBreve> = {}): ColumnDef<Esercizio>[] {
  return [
    {
      accessorKey: 'nome',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Denominazione' }),
      cell: ({ row }) => h('div', { class: 'font-bold' }, row.getValue('nome')),

    },
    {
      accessorKey: 'descrizione',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Descrizione' }),
      cell: ({ row }) => h('div', row.getValue('descrizione')),

    },
    {
      accessorKey: 'data_inizio',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Inizio' }),
      cell: ({ row }) => h('div', toItalian(row.getValue('data_inizio'))),

    },
    {
      accessorKey: 'data_fine',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Fine' }),
      cell: ({ row }) => h('div', toItalian(row.getValue('data_fine'))),

    },
    {
      accessorKey: 'stato',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Stato' }),
      cell: ({ row }) => {
  
        const value = row.getValue('stato');
        const stato = statusConstants.find(p => p.value === value);
    
        if (!stato) return h('span', '–');
    
        return h('div', { class: 'flex items-center gap-2' }, [
          h(stato.icon, { class: `h-4 w-4 ${stato.colorClass}` }),
          h('span', stato.label)
        ]);
      }
    },
    {
      id: 'rendiconto',
      header: 'Rendiconto',
      cell: ({ row }) => {
        const stato = statiRendiconto[(row.original as Esercizio).id];
        if (!stato) return h('span', '–');
        const colore = { bozza: 'text-slate-500 dark:text-slate-400', presentata: 'text-sky-700 dark:text-sky-300', approvata: 'text-emerald-700 dark:text-emerald-300' }[stato.stato];
        return h('span', { class: colore, title: stato.frase, 'data-stato-rendiconto': stato.stato }, stato.breve);
      },
    },
    {
      id: 'actions',
      enableHiding: false,
      cell: ({ row }) => {
        const esercizio = row.original as Esercizio
        return h('div', { class: 'relative' },
          h(DropdownAction, { esercizio, condominio })
        )
      },
    },
  ]
}
