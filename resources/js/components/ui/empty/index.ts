// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Empty } from "./Empty.vue"
export { default as EmptyContent } from "./EmptyContent.vue"
export { default as EmptyDescription } from "./EmptyDescription.vue"
export { default as EmptyHeader } from "./EmptyHeader.vue"
export { default as EmptyMedia } from "./EmptyMedia.vue"
export { default as EmptyTitle } from "./EmptyTitle.vue"

export const emptyMediaVariants = cva(
  "mb-2 flex shrink-0 items-center justify-center [&_svg]:pointer-events-none [&_svg]:shrink-0",
  {
    variants: {
      variant: {
        default: "bg-transparent",
        icon: "bg-muted text-foreground flex size-10 shrink-0 items-center justify-center rounded-lg [&_svg:not([class*='size-'])]:size-6",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
)

export type EmptyMediaVariants = VariantProps<typeof emptyMediaVariants>
