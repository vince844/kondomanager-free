import { h } from 'vue'
import { Link } from '@inertiajs/vue3';
import DropdownAction from './DataTableRowActions.vue';
import DataTableColumnHeader from './DataTableColumnHeader.vue';
import { usePermission } from "@/composables/permissions";
import { Badge }  from '@/components/ui/badge';
import type { ColumnDef } from '@tanstack/vue-table'
import type { Building } from '@/types/buildings';

const { generateRoute } = usePermission();

export const columns: ColumnDef<Building>[] = [
  {
    accessorKey: 'nome',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Denominazione' }), 

      cell: ({ row }) => {

      const condominio = row.original
      const label = row.original.codice_identificativo

      return h('div', { class: 'flex items-center space-x-2' }, [
        label ? h(Badge, { variant: 'outline', class: 'rounded-md' }, () => label) : null,
        h(Link, {
          href: route(generateRoute('gestionale.index'), { condominio: condominio.id }),
          class: 'capitalize font-bold ',
        }, () => condominio.nome)
      ]);
    }
  },
  {
    accessorKey: 'indirizzo',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Indirizzo' }), 
    cell: ({ row }) => h('div', { class: 'capitalize' }, row.getValue('indirizzo')),
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const building = row.original
      return h('div', { class: 'relative' }, h(DropdownAction, {
        building,
      }))
    },
  }
]