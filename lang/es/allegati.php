<?php

return [
    'title' => 'Adjuntos',
    'subtitle' => 'Adjuntos de incidencias y avisos de todos los edificios',
    'empty' => 'No hay adjuntos.',
    'empty_description' => 'Cuando se suban archivos en las incidencias o en los avisos, aparecerán aquí.',
    'empty_filtered_title' => 'Ningún adjunto coincide con estos filtros',
    'empty_filtered_description' => 'Prueba a cambiar los criterios seleccionados o restablece los filtros para ver la lista completa.',
    'empty_element_description' => 'Aún no se han subido archivos para este elemento.',
    'pending_badge' => 'Pendiente de aprobación',
    'published_badge' => 'Publicado',

    'header' => [
        'list_head' => 'Lista de adjuntos',
        'list_title' => 'Lista de adjuntos',
        'list_description' => 'A continuación la tabla con los adjuntos de incidencias y avisos de todos los edificios',
    ],

    'guides' => [
        'overview_title' => 'Lista única',
        'overview_desc' => 'Consulta en un solo lugar los archivos adjuntos a incidencias y avisos, con enlace directo a su ficha.',
        'moderation_title' => 'Moderación en bloque',
        'moderation_desc' => 'Aprueba o elimina los adjuntos pendientes también seleccionándolos en bloque desde la tabla.',
        'export_title' => 'Exportación en ZIP',
        'export_desc' => 'Descarga los adjuntos filtrados en un archivo ordenado por edificio, tipo y elemento, con el resumen elenco.csv.',
    ],

    'upload' => [
        'label' => 'Añadir adjuntos',
        'hint' => '{0} Formatos permitidos: :formati, máximo :limite por archivo. No se pueden añadir más archivos.|{1} Formatos permitidos: :formati, máximo :limite por archivo. Se puede añadir 1 archivo más (máximo :massimo).|[2,*] Formatos permitidos: :formati, máximo :limite por archivo. Se pueden añadir :rimasti archivos más (máximo :massimo).',
        'no_slots' => 'Se ha alcanzado el límite de :massimo adjuntos.',
        'button' => 'Subir adjuntos',
        'uploading' => 'Subiendo…',
        'selected_files' => 'Archivos seleccionados',
        'remove_file' => 'Quitar',
    ],

    'actions' => [
        'open' => 'Abrir',
        'download' => 'Descargar',
        'rename' => 'Renombrar',
        'save_name' => 'Guardar',
        'cancel' => 'Cancelar',
        'delete' => 'Eliminar',
        'approve' => 'Aprobar',
        'approve_selected' => 'Aprobar seleccionados',
        'delete_selected' => 'Eliminar seleccionados',
        'download_zip' => 'Descargar en ZIP',
        'save_settings' => 'Guardar configuración',
    ],

    'confirm_delete' => [
        'title' => 'Eliminar adjunto',
        'description' => '¿Quieres eliminar definitivamente el adjunto «:nome»? Esta acción no se puede deshacer.',
        'bulk_title' => 'Eliminar adjuntos seleccionados',
        'bulk_description' => '¿Quieres eliminar definitivamente los adjuntos seleccionados? Esta acción no se puede deshacer.',
    ],

    'settings' => [
        'title' => 'Configuración de adjuntos',
        'dialog_description' => 'Configura la visibilidad y moderación de los archivos adjuntos de este elemento.',
        'access_label' => 'Acceso a los adjuntos',
        'access_aperti' => 'Abiertos',
        'access_consultazione' => 'Solo consulta',
        'access_riservati' => 'Solo administración',
        'hint_aperti' => 'Quien ve el elemento ve y descarga los archivos. Desde el portal, añade nuevos quien está en el edificio para las incidencias y quien la escribió para los comunicados.',
        'hint_consultazione' => 'En el portal los archivos se ven y se descargan, pero no se añaden nuevos. Quien subió un archivo aún puede renombrarlo o eliminarlo.',
        'hint_riservati' => 'Los archivos son visibles y gestionables solo por la administración.',
        'moderation_label' => 'En moderación',
        'moderation_hint' => 'Activada: los archivos nuevos esperan aprobación, salvo los de quienes pueden aprobarlos. Desactivada: salen enseguida también los de quienes tienen permiso para publicarlos.',
    ],

    'filters' => [
        'status' => 'Estado',
        'status_all' => 'Todos los estados',
        'status_pending' => 'Pendiente de aprobación',
        'status_published' => 'Publicados',
        'type' => 'Tipo',
        'type_all' => 'Todos los tipos',
        'type_segnalazioni' => 'Incidencias',
        'type_comunicazioni' => 'Avisos',
        'condominio' => 'Edificio',
        'condominio_all' => 'Todos los edificios',
        'author' => 'Autor',
        'author_placeholder' => 'Buscar por autor…',
        'author_tooltip' => 'La columna muestra el nombre de la ficha vinculada (o el nombre de la cuenta si no hay ficha). La búsqueda encuentra tanto el nombre de la ficha como el de la cuenta de usuario.',
        'date_from' => 'Desde',
        'date_to' => 'Hasta',
        'reset' => 'Restablecer filtros',
    ],

    'table' => [
        'name' => 'Archivo',
        'element' => 'Elemento',
        'condominio' => 'Edificio',
        'author' => 'Autor',
        'date' => 'Subido el',
        'status' => 'Estado',
        'size' => 'Tamaño',
        'actions' => 'Acciones',
    ],
];

