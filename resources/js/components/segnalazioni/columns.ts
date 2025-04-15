import { h, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import type { Segnalazione } from '@/types/segnalazioni';
import DropdownAction from '@/components/segnalazioni/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/segnalazioni/DataTableColumnHeader.vue';
import { priorityConstants, statoConstants } from '@/lib/segnalazioni/constants';
import { Badge }  from '@/components/ui/badge';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { usePermission } from "@/composables/permissions";

const { hasRole } = usePermission();

// Compute the base URL for different roles (admin, user, manager, etc.)
const rolePrefix = computed(() => {
  if (hasRole(['amministratore'])) {
      return 'admin';
  } else {
      return 'user';
  }
});

  export const columns = (): ColumnDef<Segnalazione>[] => [
  {
    accessorKey: 'subject',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Titolo' }), 
    cell: ({ row }) => {
      const segnalazione = row.original
      return h(Link, {
        href: route(`${rolePrefix.value}.segnalazioni.show`, { id: segnalazione.id }),
        class: 'hover:text-zinc-500 font-bold transition-colors duration-150',
        prefetch: true,
      }, () => segnalazione.subject)
    }
  },
  {
    accessorKey: 'condominio',
    header: ({ column }) =>
      h(DataTableColumnHeader, { column, title: 'Condominio' }),
  
    cell: ({ row }) => {
      const condominio = row.original.condominio;
    
      if (!condominio?.option || !condominio?.full) return '—';
    
      return h(HoverCard, {}, {
        default: () => [
          h(HoverCardTrigger, {}, {
            default: () =>
              h(Badge, {
                variant: 'outline',
                class: 'rounded-md font-bold cursor-pointer'
              }, () => condominio.option.label)
          }),
          h(HoverCardContent, {
            class: 'w-[300px] p-4 text-sm space-y-1 rounded-xl shadow-md'
          }, {
            default: () => [
              h('div', [h('strong', 'Nome: '), condominio.full.nome]),
              h('div', [h('strong', 'Indirizzo: '), condominio.full.indirizzo]),
              h('div', [h('strong', 'Email: '), condominio.full.email]),
              h('div', [h('strong', 'Codice Fiscale: '), condominio.full.codice_fiscale]),
              h('div', [h('strong', 'Comune Catasto: '), condominio.full.comune_catasto]),
            ]
          })
        ]
      });
    },
  
    filterFn: (row, id, value) => {
      const condominio = row.original.condominio;
      return value.includes(condominio?.option?.value);
    },
  },
  {
    accessorKey: 'stato',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Stato' }),
    cell: ({ row }) => {
      const status = statoConstants.find(s => s.value === row.getValue('stato'))
      
      if (!status) return null
      
      return h('div', { class: 'flex items-center gap-2' }, [
        h(status.icon, { class: `h-4 w-4 ${status.colorClass}` }),
        h('span', status.label)
      ])
    },
    filterFn: (row, id, value) => value.includes(row.getValue(id)),
  },
  {
    accessorKey: 'priority',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Priorità' }),
    cell: ({ row }) => {
      const priority = priorityConstants.find(p => p.value === row.getValue('priority'))
      
      if (!priority) return null
      
      return h('div', { 
        class: `flex items-center gap-2`
      }, [
        h(priority.icon, { class: `h-4 w-4 ${priority.colorClass}` }),
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