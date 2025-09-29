// columns.ts
import { h } from 'vue'
import { usePermission } from "@/composables/permissions";
import DropdownAction from '@/components/gestionale/esercizi/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/esercizi/DataTableColumnHeader.vue'
import { statusConstants } from '@/lib/gestionale/esercizi/constants';
import type { ColumnDef } from '@tanstack/vue-table'
import type { Esercizio } from '@/types/gestionale/esercizi'
import type { Building } from '@/types/buildings'

const { generateRoute } = usePermission();

export function getColumns(condominio: Building): ColumnDef<Esercizio>[] {
  return [
    {
      accessorKey: 'nome',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Denominazione' }),
      cell: ({ row }) => h('div', { class: 'capitalize font-bold' }, row.getValue('nome')),

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
