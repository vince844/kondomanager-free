import { h, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import type { Comunicazione } from '@/types/comunicazioni';
import type { Building } from '@/types/buildings';
import DropdownAction from '@/components/comunicazioni/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/comunicazioni/DataTableColumnHeader.vue';
import { priorityConstants } from '@/lib/comunicazioni/constants';
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

  export const columns = (): ColumnDef<Comunicazione>[] => [
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
    accessorKey: 'condomini',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Condomini' }),
  
    cell: ({ row }) => {
      const condomini = row.original.condomini;

      if (!condomini?.options?.length || !condomini?.full?.length) return '—';
    
      return h('div', {
        class: 'relative flex items-center h-10' // Ensure proper height and vertical centering
      },
        condomini.options.map((option, index) => {
          const full = condomini.full[index];
          const initials = option.label
            .split(' ')
            .map(word => word[0]?.toUpperCase())
            .join('')
            .slice(0, 2);
    
          return h(HoverCard, {}, {
            default: () => [
              h(HoverCardTrigger, {}, {
                default: () =>
                  h(Badge, {
                    variant: 'default',
                    class: `
                      rounded-full w-8 h-8 flex items-center justify-center font-bold text-xs
                      border border-white shadow absolute
                    `,
                    style: `
                      z-index: ${10 + index};
                      left: ${index * 18}px;
                      top: 50%;
                      transform: translateY(-50%);
                    `
                  }, () => initials)
              }),
              h(HoverCardContent, {
                class: 'w-[300px] p-4 text-sm space-y-1 rounded-xl shadow-md'
              }, {
                default: () => [
                  h('div', [h('strong', 'Nome: '), full.nome]),
                  h('div', [h('strong', 'Indirizzo: '), full.indirizzo]),
                  h('div', [h('strong', 'Email: '), full.email]),
                  h('div', [h('strong', 'Codice Fiscale: '), full.codice_fiscale]),
                  h('div', [h('strong', 'Comune Catasto: '), full.comune_catasto]),
                ]
              })
            ]
          });
        })
      );
    },
  
    filterFn: (row, id, value) => {
      const condomini = row.original.condomini?.options ?? [];
      return condomini.some((condominio: Building) => value.includes(condominio.value));
    },
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
      const comunicazione = row.original
      return h('div', { class: 'relative' }, h(DropdownAction, { comunicazione }))
    },
  },
]