<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import type { NavigationMenuRootEmits, NavigationMenuRootProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import {
  NavigationMenuRoot,
  useForwardPropsEmits,
} from "reka-ui"
import { cn } from "@/lib/utils"
import NavigationMenuViewport from "./NavigationMenuViewport.vue"

const props = defineProps<NavigationMenuRootProps & { class?: HTMLAttributes["class"] }>()

const emits = defineEmits<NavigationMenuRootEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <NavigationMenuRoot
    v-bind="forwarded"
    :class="cn('relative z-10 flex max-w-max flex-1 items-center justify-center', props.class)"
  >
    <slot />
    <NavigationMenuViewport />
  </NavigationMenuRoot>
</template>
