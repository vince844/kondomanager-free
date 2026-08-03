<script setup lang="ts" generic="TData, TValue">
/**
 * L'elenco delle deleghe F24.
 *
 * Stessa struttura del `Datatable` dei pagamenti — ordinamento, paginazione lato server,
 * stato vuoto — perché una pagina che si comporta come le altre non va imparata. La riga
 * intera porta alla scheda, come nel resto del gestionale.
 */
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FlexRender, getCoreRowModel, getSortedRowModel, useVueTable } from '@tanstack/vue-table';
import { Empty, EmptyHeader, EmptyMedia, EmptyTitle, EmptyDescription } from '@/components/ui/empty';
import { valueUpdater } from '@/lib/utils';
import DataTablePagination from '@/components/DataTablePagination.vue';
import DataTableToolbar from '@/components/gestionale/movimenti/f24/DataTableToolbar.vue';
import { usePermission } from '@/composables/permissions';
import { Receipt } from 'lucide-vue-next';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import type { Building } from '@/types/buildings';

const props = defineProps<{
    columns: ColumnDef<any, any>[];
    data: any[];
    condominio: Building;
    meta: { current_page: number; per_page: number; last_page: number; total: number };
}>();

const { generateRoute } = usePermission();
const sorting = ref<SortingState>([]);
const isPending = ref(false);

const table = useVueTable({
    get data() { return props.data ?? []; },
    get columns() { return props.columns ?? []; },
    pageCount: props.meta.last_page,
    state: {
        pagination: {
            pageIndex: props.meta.current_page - 1,
            pageSize: props.meta.per_page,
        },
        get sorting() { return sorting.value; },
    },
    manualPagination: true,
    onPaginationChange: (updater) => {
        if (isPending.value) return;
        isPending.value = true;

        const nextPage = typeof updater === 'function'
            ? updater(table.getState().pagination).pageIndex
            : updater.pageIndex;

        router.get(
            route(generateRoute('gestionale.f24.index'), { condominio: props.condominio.id }),
            { page: nextPage + 1, per_page: table.getState().pagination.pageSize },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => { isPending.value = false; },
            },
        );
    },
    onSortingChange: (updaterOrValue) => valueUpdater(updaterOrValue, sorting),
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
});

const apriDelega = (id: number) => {
    router.visit(route(generateRoute('gestionale.f24.show'), { condominio: props.condominio.id, delega: id }));
};
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center">
            <DataTableToolbar :table="table">
                <template #azioni><slot name="azioni" /></template>
            </DataTableToolbar>
        </div>

        <div class="overflow-hidden rounded-md border bg-white">
            <Table v-if="table.getRowModel().rows?.length > 0" class="w-full table-fixed">
                <TableHeader>
                    <TableRow v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id" class="bg-gray-50/50">
                        <TableHead
                            v-for="header in headerGroup.headers"
                            :key="header.id"
                            class="px-4"
                            :style="{ width: header.getSize() + 'px' }"
                        >
                            <FlexRender
                                v-if="!header.isPlaceholder"
                                :render="header.column.columnDef.header"
                                :props="header.getContext()"
                            />
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="row in table.getRowModel().rows"
                        :key="row.id"
                        class="cursor-pointer transition-colors hover:bg-gray-50/50"
                        @click="apriDelega(row.original.id)"
                    >
                        <TableCell
                            v-for="cell in row.getVisibleCells()"
                            :key="cell.id"
                            class="px-4 py-3"
                            :style="{ width: cell.column.getSize() + 'px' }"
                        >
                            <FlexRender :render="cell.column.columnDef.cell" :props="cell.getContext()" />
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <Empty v-else class="bg-slate-50/50 py-12">
                <EmptyHeader class="max-w-4xl">
                    <EmptyMedia variant="icon" class="bg-indigo-50/50 text-indigo-500 dark:bg-indigo-900/20">
                        <Receipt class="h-8 w-8" />
                    </EmptyMedia>
                    <EmptyTitle>Nessuna ritenuta da versare</EmptyTitle>
                    <EmptyDescription>
                        Quando pagherai una fattura di un fornitore soggetto a ritenuta, qui comparirà
                        quando e quanto versare. <br>
                        Se hai appena registrato dei pagamenti, premi «Aggiorna scadenze».
                    </EmptyDescription>
                </EmptyHeader>
            </Empty>
        </div>

        <div v-if="table.getRowModel().rows?.length > 0 && props.meta.last_page > 1" class="flex items-center justify-end">
            <DataTablePagination :table="table" :meta="props.meta" />
        </div>
    </div>
</template>
