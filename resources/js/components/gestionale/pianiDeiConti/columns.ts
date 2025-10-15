// columns.ts
import { h } from 'vue'
import { Link } from '@inertiajs/vue3';
import DropdownAction from '@/components/gestionale/pianiDeiConti/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/gestionale/pianiDeiConti/DataTableColumnHeader.vue'
import { usePermission } from "@/composables/permissions";
import type { ColumnDef } from '@tanstack/vue-table'
import type { PianoDeiConti } from '@/types/gestionale/piani-dei-conti'
import type { Building } from '@/types/buildings'
import type { Esercizio } from '@/types/gestionale/esercizi';

const { generateRoute } = usePermission();

export const createColumns = (condominio: Building, esercizio: Esercizio): ColumnDef<PianoDeiConti>[] => [
  {
    accessorKey: 'nome',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Denominazione' }),
    cell: ({ row }) => {

      const conto = row.original

      return h('div', { class: 'flex items-center space-x-2' }, [
        h(Link, {
          href: route(generateRoute('gestionale.esercizi.conti.spese.index'), { condominio: condominio.id, esercizio: esercizio.id,  conto: conto.id }),
          class: 'font-bold ',
        }, () => conto.nome)
      ]);
    } 
  },
  {
    accessorKey: 'descrizione',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Descrizione' }),
    cell: ({ row }) => h('div', row.getValue('descrizione')),

  },
  {
    accessorKey: 'esercizio',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Esercizio' }),
    cell: ({ row }) => {
      const pianoDeiConti = row.original
      const gestione = pianoDeiConti.gestione
      
      // Controlla se esistono esercizi
      if (!gestione || !gestione.esercizio || !Array.isArray(gestione.esercizio) || gestione.esercizio.length === 0) {
        return h('span', { class: 'text-gray-400' }, 'N/A')
      }
      
      // Prendi il primo esercizio dall'array
      const primoEsercizio = gestione.esercizio[0]
      
      if (!primoEsercizio) {
        return h('span', { class: 'text-gray-400' }, 'N/A')
      }
      
      return h('div', { class: 'flex flex-col gap-1' }, [
        h('span', primoEsercizio.nome),
      ])
    },
  },
  {
    accessorKey: 'gestione',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Gestione' }),
    cell: ({ row }) => {
      const pianoDeiConti = row.original
      const gestione = pianoDeiConti.gestione
      
      if (!gestione) {
        return h('span', { class: 'text-gray-400' }, 'N/A')
      }
      
      return h('span', gestione.nome)
    },
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const pianoDeiConti = row.original as PianoDeiConti
      return h('div', { class: 'relative' },
        h(DropdownAction, { pianoDeiConti, condominio, esercizio })
      )
    },
   }
]

