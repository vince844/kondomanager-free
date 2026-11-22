<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Ripristino;

use RuntimeException;

/** «Ripristina» non si può, con la frase che la finestra mostra (il controller la rende con un 422). */
final class RipristinoNonPossibile extends RuntimeException {}
