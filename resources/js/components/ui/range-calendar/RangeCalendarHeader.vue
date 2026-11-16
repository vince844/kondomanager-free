<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script lang="ts" setup>
import type { RangeCalendarHeaderProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { RangeCalendarHeader, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<RangeCalendarHeaderProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <RangeCalendarHeader :class="cn('relative flex w-full items-center justify-between pt-1', props.class)" v-bind="forwardedProps">
    <slot />
  </RangeCalendarHeader>
</template>
