import { h } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import DropdownAction from '@/components/segnalazioni/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/segnalazioni/DataTableColumnHeader.vue';
import { priorityConstants, statoConstants, publishedConstants } from '@/lib/segnalazioni/constants';
import { usePermission } from "@/composables/permissions";
import { ShieldCheck } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import type { ColumnDef } from '@tanstack/vue-table';
import type { Segnalazione } from '@/types/segnalazioni';

const { hasPermission, generateRoute } = usePermission();

export const columns: ColumnDef<Segnalazione>[] = [
  {
    accessorKey: 'subject',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('segnalazioni.table.title') }), 
    cell: ({ row, table }) => {

      const segnalazione = row.original

      const toggleApproval = () => {

          router.put(route(generateRoute('segnalazioni.toggle-approval'), { id: segnalazione.id }), {}, {
            preserveScroll: true,
            only: ['stats', 'segnalazioni', 'flash'],
            onSuccess: () => {
              // Manually update the specific item
              segnalazione.is_approved = !segnalazione.is_approved;
              segnalazione.is_published = segnalazione.is_approved;

                 // Update the row data in the table
              table.options.meta?.updateData(row.index, {
                ...segnalazione,
                is_published: segnalazione.is_approved
              });

            }
          });

      };
      
      const tooltip = segnalazione.is_approved
        ? trans('segnalazioni.table.approved_tooltip')
        : trans('segnalazioni.table.unapproved_tooltip');
      
        const shieldIcon = hasPermission(['Approva segnalazioni'])
        ? h('div', {
            class: 'cursor-pointer',
            title: tooltip,
            onClick: toggleApproval,
          }, [
            h(ShieldCheck, {
              class: segnalazione.is_approved ? 'w-4 h-4 text-green-500' : 'w-4 h-4 text-red-500',
            }),
          ])
        : null;

        return h('div', { class: 'flex items-center space-x-2' }, [
          shieldIcon,
          h(Link, {
            href: route(generateRoute('segnalazioni.show'), { id: segnalazione.id }),
            class: 'hover:text-zinc-500 font-bold transition-colors duration-150',
          }, () => segnalazione.subject)
        ]);
    }
  },
  {
    accessorKey: 'condominio',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('segnalazioni.table.buildings') }),
  
    cell: ({ row }) => {
      const condomini = row.original.condominio;
  
      if (!condomini || !condomini.option || !condomini.full) {
        return '—';
      }
  
      // Extract the condominio option and full details
      const condominio = condomini.option;
      const fullDetails = condomini.full;
  
      // Create initials from the condominio's label for the avatar
      const initials = condominio.label
        .split(' ')
        .map((word: string) => word[0]?.toUpperCase()) 
        .join('')
        .slice(0, 2);
  
      // Create the Tooltip and Badge for the condominio
      const avatars = [
        h('div', {
          key: `condominio-avatar`,
          title: fullDetails.nome || condominio.label,
          class: `
            absolute w-8 h-8 rounded-full bg-gray-200 text-gray-800 text-xs font-bold
            flex items-center justify-center border border-white shadow
          `,
          style: `
            z-index: 10;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
          `,
        }, initials)
      ];
  
      return h('div', {
        class: 'relative flex items-center h-10',
      }, avatars);
    },
  
    filterFn: (row, id, value) => {
      const condomini = row.original.condominio?.option ?? {};
      return value.includes(condomini.value);
    },
  },
  {
    accessorKey: 'anagrafiche',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('segnalazioni.table.residents') }),
  
    cell: ({ row }) => {
      const anagrafiche = row.original.anagrafiche;
    
      if (!Array.isArray(anagrafiche) || anagrafiche.length === 0) {
        return '—';
      }
    
      const maxAvatars = 3;
      const visibleAnagrafiche = anagrafiche.slice(0, maxAvatars);
      const remainingCount = anagrafiche.length - maxAvatars;
    
      const avatars = visibleAnagrafiche.map((item, index) => {
        const initials = item.nome
          ?.split(' ')
          .map(word => word[0]?.toUpperCase())
          .join('')
          .slice(0, 2) || '?';
    
          const tooltip = item.nome || '—';
    
        return h('div', {
          key: `${item.nome}-${index}`,
          title: tooltip,
          class: `
            absolute w-8 h-8 rounded-full bg-gray-200 text-gray-800 text-xs font-bold
            flex items-center justify-center border border-white shadow
          `,
          style: `
            z-index: ${10 + index};
            left: ${index * 18}px;
            top: 50%;
            transform: translateY(-50%);
          `,
        }, initials);
      });
    
      if (remainingCount > 0) {
        avatars.push(
          h('div', {
            key: 'more-anagrafiche',
            title: `+${remainingCount} altre persone`,
            class: `
              absolute w-8 h-8 rounded-full bg-gray-300 text-gray-800 text-xs font-bold
              flex items-center justify-center border border-white shadow
            `,
            style: `
              z-index: ${10 + maxAvatars};
              left: ${maxAvatars * 18}px;
              top: 50%;
              transform: translateY(-50%);
            `,
          }, `+${remainingCount}`)
        );
      }
    
      return h('div', {
        class: 'relative flex items-center h-10',
      }, avatars);
    },

  },
  {
    accessorKey: 'stato',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('segnalazioni.table.status') }),
    cell: ({ row }) => {
      const status = statoConstants.find(s => s.value === row.getValue('stato'))
      
      if (!status) return null
      
      return h('div', { class: 'flex items-center gap-2' }, [
        h(status.icon, { class: `h-4 w-4 ${status.colorClass}` }),
        h('span', trans(status.label))
      ])
    },
    filterFn: (row, id, value) => value.includes(row.getValue(id)),
  },
  {
    accessorKey: 'priority',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('segnalazioni.table.priority') }),
    cell: ({ row }) => {
      
      const priority = priorityConstants.find(p => p.value === row.getValue('priority'))
      
      if (!priority) return null
      
      return h('div', { 
        class: `flex items-center gap-2`
      }, [
        h(priority.icon, { class: `h-4 w-4 ${priority.colorClass}` }),
        h('span', trans(priority.label))
      ])
    },
    filterFn: (row, id, value) => value.includes(row.getValue(id)),
  },
  {
    accessorKey: 'is_published',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('segnalazioni.table.visibility') }),
    cell: ({ row }) => {
      const value = Boolean(row.getValue('is_published'));
      const stato = publishedConstants.find(p => p.value === value);
  
      if (!stato) return h('span', '–');
  
      return h('div', { class: 'flex items-center gap-2' }, [
        h(stato.icon, { class: `h-4 w-4 ${stato.colorClass}` }),
        h('span', trans(stato.label))
      ]);
    },
    filterFn: (row, id, value) =>
      value.includes(Boolean(row.getValue(id))),
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