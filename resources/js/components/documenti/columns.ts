import { h } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import type { Documento } from '@/types/documenti';
import type { Building } from '@/types/buildings';
import DropdownAction from '@/components/documenti/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/documenti/DataTableColumnHeader.vue';
import { publishedConstants } from '@/lib/documenti/constants';
import { usePermission } from "@/composables/permissions";
import { Badge } from '@/components/ui/badge';

const { hasPermission,  generateRoute } = usePermission();

export const columns = (): ColumnDef<Documento>[] => [
  {
    accessorKey: 'name',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Titolo' }),

    cell: ({ row, table }) => {

      const documento = row.original;
    
      return h('div', { class: 'flex items-center space-x-2' }, [
        h('a', {
          href: route(generateRoute('documenti.download'), { documento: documento.id }),
          class: 'hover:text-zinc-500 font-bold transition-colors duration-150',
        }, documento.name)
      ]);
    }
  },
  {
    accessorKey: 'categoria',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Categoria' }),

    cell: ({ row }) => {
      const categoria = row.original.categoria;

      return h('div', { class: 'flex space-x-2 items-center' }, [
        categoria
          ? h(
              Badge,
              { variant: 'outline', class: 'rounded-md' },
              () => categoria.name
            )
          : null,
      ]);
    },
  },
  {
    accessorKey: 'condomini',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Condomini' }),
  
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
    accessorKey: 'is_published',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Stato' }),
    cell: ({ row }) => {

      const value = Boolean(row.getValue('is_published'));
      const stato = publishedConstants.find(p => p.value === value);
  
      if (!stato) return h('span', '–');
  
      return h('div', { class: 'flex items-center gap-2' }, [
        h(stato.icon, { class: `h-4 w-4 ${stato.colorClass}` }),
        h('span', stato.label)
      ]);
    },
    filterFn: (row, id, value) =>
      value.includes(Boolean(row.getValue(id))),
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const documento = row.original
      return h('div', { class: 'relative' }, h(DropdownAction, { documento }))
    },
  },
]