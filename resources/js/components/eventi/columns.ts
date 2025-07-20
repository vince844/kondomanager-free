import { h } from 'vue';
import { router } from '@inertiajs/vue3';
import DropdownAction from '@/components/eventi/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/eventi/DataTableColumnHeader.vue';
import { usePermission } from "@/composables/permissions";
import { ShieldCheck } from 'lucide-vue-next';
import { Permission }  from "@/enums/Permission";
import { Badge } from '@/components/ui/badge';
import type { ColumnDef } from '@tanstack/vue-table';
import type { Evento } from '@/types/eventi';
import type { Building } from '@/types/buildings';

const { hasPermission,  generateRoute } = usePermission();

export const columns: ColumnDef<Evento>[] = [
    {
    accessorKey: 'occurs_at',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Data scadenza' }),
    cell: ({ row }) => {
      // Use ISO date for calculations:
      const occursISO = row.original.occurs as string;
      const occursAt = new Date(occursISO);
      const now = new Date();

      const msPerDay = 1000 * 60 * 60 * 24;
      const daysDiff = Math.floor((occursAt.getTime() - now.getTime()) / msPerDay);

      let colorClass = 'text-green-500'; // default green

      if (daysDiff >= 0 && daysDiff <= 7) {
        colorClass = 'text-red-500';   // next 7 days
      } else if (daysDiff >= 8 && daysDiff <= 14) {
        colorClass = 'text-yellow-500';  // next 8-14 days
      }

      // Display the formatted string occurs_at but color based on occurs:
      return h('div', { class: `font-bold ${colorClass}` }, row.original.occurs_at);
    },
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
    accessorKey: 'title',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Titolo' }),
    cell: ({ row }) => {
      const occursISO = row.original.occurs as string;
      const occursAt = new Date(occursISO);
      const now = new Date();

      const msPerDay = 1000 * 60 * 60 * 24;
      const daysDiff = Math.floor((occursAt.getTime() - now.getTime()) / msPerDay);

      let colorClass = 'text-green-500'; // default green

      if (daysDiff >= 0 && daysDiff <= 7) {
        colorClass = 'text-red-500';   // next 7 days
      } else if (daysDiff >= 8 && daysDiff <= 14) {
        colorClass = 'text-yellow-500';  // next 8-14 days
      }

      return h('div', { class: `font-bold ${colorClass}` }, row.getValue('title'));
    },
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const evento = row.original
      return h('div', { class: 'relative' }, h(DropdownAction, { evento }))
    },
  },
]