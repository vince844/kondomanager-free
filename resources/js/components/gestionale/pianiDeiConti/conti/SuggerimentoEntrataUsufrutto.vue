<script setup lang="ts">
import { Info } from 'lucide-vue-next'
import { trans } from 'laravel-vue-i18n'
</script>

<template>
  <div role="note" class="flex items-start gap-2 rounded-md border border-sky-200 bg-sky-50 p-3 text-xs leading-relaxed text-sky-900 dark:border-sky-800/50 dark:bg-sky-900/20 dark:text-sky-200">
    <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
    <p>{{ trans('gestionale.list_pages.piani_conti.show.new_entry_modal.help.income_usufruct') }}</p>
  </div>
</template>
