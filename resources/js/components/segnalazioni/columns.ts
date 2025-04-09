import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Segnalazione } from '@/types/segnalazioni';
import DropdownAction from '@/components/segnalazioni/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/segnalazioni/DataTableColumnHeader.vue';
import { 
  CircleCheck,
  CircleX, 
  History, 
  CircleArrowUp,
  CircleArrowRight,
  CircleArrowDown,
  CircleAlert,
} from 'lucide-vue-next';

// Enhanced status definitions with colors
const stati = [
  { 
    value: 'aperta', 
    label: 'Aperta', 
    icon: CircleCheck,
    iconClass: 'text-green-500'
  },
  { 
    value: 'in lavorazione', 
    label: 'In lavorazione', 
    icon: History,
    iconClass: 'text-yellow-500'
  },
  { 
    value: 'chiusa', 
    label: 'Chiusa', 
    icon: CircleX,
    iconClass: 'text-gray-500'
  }
]

// Enhanced priority definitions with icons and styling
const priorities = [
  { 
    value: 'bassa', 
    label: 'Bassa', 
    icon: CircleArrowDown,
    iconClass: 'text-green-500',
    textClass: 'text-green-700'
  },
  { 
    value: 'media', 
    label: 'Media', 
    icon: CircleArrowRight,
    iconClass: 'text-blue-500',
    textClass: 'text-blue-700'
  },
  { 
    value: 'alta', 
    label: 'Alta', 
    icon: CircleArrowUp,
    iconClass: 'text-orange-500',
    textClass: 'text-orange-700'
  },
  { 
    value: 'urgente', 
    label: 'Urgente', 
    icon: CircleAlert, 
    iconClass: 'text-red-500', 
    textClass: 'text-red-700'
  }
]

export const columns: ColumnDef<Segnalazione>[] = [
  {
    accessorKey: 'subject',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Titolo' }), 
    cell: ({ row }) => h('div', { class: 'capitalize font-bold' }, row.getValue('subject')),
  },
  {
    accessorKey: 'stato',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Stato' }),
    cell: ({ row }) => {
      const status = stati.find(s => s.value === row.getValue('stato'))
      
      if (!status) return null
      
      return h('div', { class: 'flex items-center gap-2' }, [
        h(status.icon, { class: `h-4 w-4 ${status.iconClass}` }),
        h('span', status.label)
      ])
    },
    filterFn: (row, id, value) => value.includes(row.getValue(id)),
  },
  {
    accessorKey: 'priority',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Priorità' }),
    cell: ({ row }) => {
      const priority = priorities.find(p => p.value === row.getValue('priority'))
      
      if (!priority) return null
      
      return h('div', { 
        class: `flex items-center gap-2 px-3 py-1 w-fit`
      }, [
        h(priority.icon, { class: `h-4 w-4 ${priority.iconClass}` }),
        h('span', priority.label)
      ])
    },
    filterFn: (row, id, value) => value.includes(row.getValue(id)),
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const segnalazione = row.original
      return h('div', { class: 'relative' }, h(DropdownAction, { segnalazione }))
    },
  },
]