<?php

// The cadastral municipality lookup (beta.59). The field stays free: these strings describe a
// helper, not a requirement.

return [
    'button_title' => 'Search the municipality in the ISTAT list',
    'button_label' => 'Search the municipality',
    'dialog_title' => 'Search the municipality',
    'dialog_description' => 'Type the municipality name, or its cadastral code. The field stays writable by hand: if the municipality is missing or has changed, close this and type it yourself.',
    'placeholder' => 'e.g. Roma, or H501',
    'searching' => 'Searching…',
    'min_chars' => 'Type at least two characters.',
    'not_found' => 'No municipality found. Try a single word, or type it by hand in the field: if it was merged or renamed after the date below, it is not in the list.',
    'error' => 'The municipality list cannot be queried right now. Try again, or type the municipality by hand in the field.',
    'truncated' => 'Showing the first :mostrati of :totale. Type a few more letters to narrow it down.',
    'source_date' => 'ISTAT list updated to :data.',
    'empty_list' => 'The municipality list has not been loaded on this installation yet.',
];
