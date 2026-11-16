<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import type { TagsInputInputProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { TagsInputInput, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<TagsInputInputProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <TagsInputInput v-bind="forwardedProps" :class="cn('text-sm min-h-6 focus:outline-none flex-1 bg-transparent px-1', props.class)" />
</template>
