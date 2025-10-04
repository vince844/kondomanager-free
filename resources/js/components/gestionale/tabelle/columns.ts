// columns.ts
import { h } from 'vue'
import { usePermission } from "@/composables/permissions";
import DropdownAction from '@/components/gestionale/tabelle/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/tabelle/DataTableColumnHeader.vue'
import { typeConstants } from '@/lib/gestionale/tabelle/constants';
import type { ColumnDef } from '@tanstack/vue-table'
import type { Tabella } from '@/types/gestionale/tabelle'
import type { Building } from '@/types/buildings'

const { generateRoute } = usePermission();

export function getColumns(condominio: Building): ColumnDef<Tabella>[] {
  return [
    {
      accessorKey: 'nome',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Denominazione' }),
      cell: ({ row }) => h('div', { class: 'font-bold' }, row.getValue('nome')),

    },
    {
      accessorKey: 'palazzina',
      header: ({ column }) =>

        h(DataTableColumnHeader, { column, title: 'Palazzina' }),

      cell: ({ row }) => {
        const tabella = row.original as Tabella
        const palazzina = tabella.palazzina?.name ?? '-'
        return h('div', { class: 'flex space-x-2' }, [
          h('span', { class: 'capitalize' }, palazzina),
        ])
      }
        
    },
    {
      accessorKey: 'scala',
      header: ({ column }) =>

        h(DataTableColumnHeader, { column, title: 'Scala' }),

      cell: ({ row }) => {
        const tabella = row.original as Tabella
        const scala = tabella.scala?.name ?? '-'
        return h('div', { class: 'flex space-x-2' }, [
          h('span', { class: 'capitalize' }, scala),
        ])
      }
        
    },
    {
      accessorKey: 'tipo',
      header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Tipologia' }),
      cell: ({ row }) => {
  
        const value = row.getValue('tipo');
        const stato = typeConstants.find(p => p.value === value);
    
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
        const tabella = row.original as Tabella
        return h('div', { class: 'relative' },
          h(DropdownAction, { tabella, condominio })
        )
      },
    },
  ]
}
