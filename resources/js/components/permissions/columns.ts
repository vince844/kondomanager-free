// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { h } from 'vue'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Permission } from '@/types/permissions';
import DataTableColumnHeader from '@/components/permissions/DataTableColumnHeader.vue';

export const columns: ColumnDef<Permission>[] = [
    {
        accessorKey: 'name',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Permesso' }), 
        cell: ({ row }) => h('div',{class: "font-bold"}, row.getValue('name')),
      },
    {
        accessorKey: 'description',
        header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Descrizione' }), 
        cell: ({ row }) => h('div', row.getValue('description')),
      },
]