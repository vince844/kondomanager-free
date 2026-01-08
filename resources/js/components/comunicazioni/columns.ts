import { h } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import DropdownAction from '@/components/comunicazioni/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/comunicazioni/DataTableColumnHeader.vue';
import { priorityConstants, publishedConstants } from '@/lib/comunicazioni/constants';
import { usePermission } from "@/composables/permissions";
import { trans } from 'laravel-vue-i18n';
import { ShieldCheck } from 'lucide-vue-next';
import { Permission }  from "@/enums/Permission";
import type { ColumnDef } from '@tanstack/vue-table';
import type { Comunicazione } from '@/types/comunicazioni';
import type { Building } from '@/types/buildings';

const { hasPermission,  generateRoute } = usePermission();

export const columns: ColumnDef<Comunicazione>[] = [
  {
    accessorKey: 'subject',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('comunicazioni.table.title') }),

    cell: ({ row, table }) => {

      const comunicazione = row.original;

      const toggleApproval = () => {

          router.put(route(generateRoute('comunicazioni.toggle-approval'), { id: comunicazione.id }), {}, {
            preserveScroll: true,
            only: ['stats', 'comunicazioni', 'flash'],
            onSuccess: () => {
              // Manually update the specific item
              comunicazione.is_approved = !comunicazione.is_approved;
              comunicazione.is_published = comunicazione.is_approved;

              // Update the row data in the table
              table.options.meta?.updateData(row.index, {
                ...comunicazione,
                is_published: comunicazione.is_approved
              });

            }
          });

      };
    
      const tooltip = comunicazione.is_approved
        ? trans('comunicazioni.table.approved_tooltip')
        : trans('comunicazioni.table.unapproved_tooltip');
    
      const shieldIcon = hasPermission([Permission.APPROVE_COMUNICAZIONI])
        ? h('div', {
            class: 'cursor-pointer',
            title: tooltip,
            onClick: toggleApproval,
          }, [
            h(ShieldCheck, {
              class: comunicazione.is_approved ? 'w-4 h-4 text-green-500' : 'w-4 h-4 text-red-500',
            }),
          ])
        : null;
    
      return h('div', { class: 'flex items-center space-x-2' }, [
        shieldIcon,
        h(Link, {
          href: route(generateRoute('comunicazioni.show'), { id: comunicazione.id }),
          class: 'hover:text-zinc-500 font-bold transition-colors duration-150',
        }, () => comunicazione.subject)
      ]);
    }
  },
  {
    accessorKey: 'condomini',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('comunicazioni.table.buildings') }),
  
    cell: ({ row }) => {
      const condomini = row.original.condomini;

      if (!condomini || !Array.isArray(condomini.options) || !Array.isArray(condomini.full) || !condomini.options.length) {
        return '—';
      }
    
      const maxAvatars = 3;
      const visibleCondomini = condomini.options.slice(0, maxAvatars);
      const remainingCount = condomini.options.length - maxAvatars;
    
      const avatars = visibleCondomini.map((option, index) => {
        const initials = option.label
          .split(' ')
          .map(word => word[0]?.toUpperCase())
          .join('')
          .slice(0, 2);
    
        const tooltip = condomini.full[index]?.nome || option.label;
    
        return h('div', {
          key: `${option.label}-${index}`,
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
            key: 'more-condomini',
            title: `+${remainingCount} altri condomini`,
            class: `
              absolute w-8 h-8 rounded-full bg-gray-300 text-gray-800 text-xs font-bold
              flex items-center justify-center border border-white shadow
            `,
            style: `
              z-index: 13;
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
  
    filterFn: (row, id, value) => {
      const condomini = row.original.condomini?.options ?? [];
      return condomini.some((condominio: Building) => value.includes(condominio.value));
    },
  },
  {
    accessorKey: 'anagrafiche',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('comunicazioni.table.residents') }),
  
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
    accessorKey: 'priority',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('comunicazioni.table.priority') }),
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
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('comunicazioni.table.status') }),
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
      const comunicazione = row.original
      return h('div', { class: 'relative' }, h(DropdownAction, { comunicazione }))
    },
  },
]