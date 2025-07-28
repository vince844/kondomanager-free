import { h } from 'vue';
import { router } from '@inertiajs/vue3';
import DropdownAction from '@/components/eventi/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/eventi/DataTableColumnHeader.vue';
import { usePermission } from "@/composables/permissions";
import { ClockAlert, ClockArrowUp, Clock } from 'lucide-vue-next';
import { Permission } from "@/enums/Permission";
import { Badge } from '@/components/ui/badge';
import type { ColumnDef } from '@tanstack/vue-table';
import type { Evento } from '@/types/eventi';
import type { Building } from '@/types/buildings';

const { hasPermission, generateRoute } = usePermission();

export const columns: ColumnDef<Evento>[] = [
  {
    accessorKey: 'occurs_at',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Data scadenza' }),
    cell: ({ row }) => {
      // No color, just the date
      return h('div', { class: 'font-normal text-gray-800' }, row.original.occurs_at);
    },
  },
  {
    accessorKey: 'categoria',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Categoria' }),
    cell: ({ row }) => {
      const categoria = row.original.categoria;

      return h('div', { class: 'flex space-x-2 items-center' }, [
        categoria
          ? h(Badge, { variant: 'outline', class: 'rounded-md' }, () => categoria.name)
          : null,
      ]);
    },
  },
  {
    accessorKey: 'title',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Titolo' }),
    cell: ({ row }) => {
      
      const occursISO = row.original.occurs as string;
      const occursAt = new Date(occursISO);
      const now = new Date();
      const msPerDay = 1000 * 60 * 60 * 24;
      const daysDiff = Math.floor((occursAt.getTime() - now.getTime()) / msPerDay);

      let IconComponent = Clock;
      let iconColor = 'text-green-500';

      if (daysDiff >= 0 && daysDiff <= 7) {
        IconComponent = ClockAlert;
        iconColor = 'text-red-500';
      } else if (daysDiff >= 8 && daysDiff <= 14) {
        IconComponent = ClockArrowUp;
        iconColor = 'text-yellow-500';
      }

      return h('div', { class: 'flex items-center gap-2 font-medium text-gray-900' }, [
        h(IconComponent, { class: `w-4 h-4 ${iconColor}` }),
        h('span', {}, row.getValue('title')),
      ]);
    },
  },
  {
    accessorKey: 'condomini',
    header: ({ column }) =>
      h(DataTableColumnHeader, { column, title: 'Condomini' }),

    cell: ({ row }) => {
      const condomini = row.original.condomini;

      if (!Array.isArray(condomini) || condomini.length === 0) {
        return '—';
      }

      const maxAvatars = 3;
      const visibleCondomini = condomini.slice(0, maxAvatars);
      const remainingCount = condomini.length - maxAvatars;

      const avatars = visibleCondomini.map((condominio, index) => {
        const initials = condominio.nome
          ?.split(' ')
          .map((word: string) => word[0]?.toUpperCase())
          .join('')
          .slice(0, 2) || '?';

        const tooltip = condominio.nome;

        return h('div', {
          key: `${condominio.label}-${index}`,
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

    filterFn: (row, id, value) => {
      const condomini = row.original.condomini ?? [];
      return condomini.some((condominio) =>
        value.includes(condominio.value)
      );
    },
  },
  {
    accessorKey: 'anagrafiche',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Anagrafiche' }),
  
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
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const evento = row.original;
      return h('div', { class: 'relative' }, h(DropdownAction, { evento }));
    },
  },
];
