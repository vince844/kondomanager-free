#!/bin/bash

# ------------------------------------------------------------------
# CONFIGURAÇÃO BASE DO PROJETO
# ------------------------------------------------------------------
PROJECT_ROOT="/home/lg/develop/kondomanager-free"

echo "🔍 Assistente de internacionalização (Vue → Laravel i18n)"
echo "📦 Projeto: $PROJECT_ROOT"
echo

# ------------------------------------------------------------------
# MENU
# ------------------------------------------------------------------
echo "O que pretendes fazer?"
echo "1) Analisar ficheiro Vue, detetar textos e criar mapa i18n (assistido)"
echo "0) Sair"
read -rp "👉 Opção: " OPTION

if [ "$OPTION" != "1" ]; then
  echo "⛔ Operação cancelada."
  exit 0
fi

# ------------------------------------------------------------------
# INPUT DO UTILIZADOR
# ------------------------------------------------------------------
read -rp "📄 Caminho do ficheiro .vue (relativo ou absoluto): " VUE_FILE

if [[ "$VUE_FILE" != /* ]]; then
  VUE_FILE="$PROJECT_ROOT/$VUE_FILE"
fi

if [[ "$VUE_FILE" != $PROJECT_ROOT/* ]]; then
  echo "❌ O ficheiro tem de estar dentro do projeto"
  exit 1
fi

if [ ! -f "$VUE_FILE" ]; then
  echo "❌ Ficheiro não encontrado"
  exit 1
fi

export VUE_FILE

# ------------------------------------------------------------------
# EXECUÇÃO PHP (INTERATIVA E ROBUSTA)
# ------------------------------------------------------------------
php <<'PHP'
<?php

$file = getenv('VUE_FILE');
$content  = file_get_contents($file);
$original = $content;

// Stream seguro para input
$stdin = fopen("php://stdin", "r");

// Inferir módulo pela pasta (ex: fornitori)
$module = basename(dirname($file));

$translations = [];

/* ---------------------------------------------------------
 | FUNÇÃO AUXILIAR
 | ---------------------------------------------------------*/
function askKey(string $suggested, $stdin): string {
    echo "Variável i18n [$suggested]: ";
    $input = trim(fgets($stdin));
    return $input !== '' ? $input : $suggested;
}

/* ---------------------------------------------------------
 | 1. ATRIBUTOS HTML COM TEXTO
 | ---------------------------------------------------------*/
preg_match_all(
    '/<([A-Za-z0-9]+)[^>]*\s(title|placeholder|label)="([^"]+)"/',
    $content,
    $matches,
    PREG_SET_ORDER
);

foreach ($matches as $m) {
    [$full, $tag, $attr, $text] = $m;

    $text = trim($text);

    if (mb_strlen($text) < 3) continue;
    if (preg_match('/[<>{}:]/', $text)) continue;

    $block = strtolower($tag);
    $suggestedKey = "$module.$block.$attr";

    echo "\n🔹 Texto encontrado em <$tag $attr>: \"$text\"\n";
    $key = askKey($suggestedKey, $stdin);

    $translations[$key] = $text;

    $content = str_replace(
        "$attr=\"$text\"",
        ":$attr=\"trans('$key')\"",
        $content
    );
}

/* ---------------------------------------------------------
 | 2. TEXTO DIRETO ENTRE TAGS (<span>, <h3>, etc.)
 | ---------------------------------------------------------*/
preg_match_all(
    '/<([a-z0-9]+)[^>]*>([^<{]{3,})<\/\1>/i',
    $content,
    $blocks,
    PREG_SET_ORDER
);

foreach ($blocks as $b) {
    [$full, $tag, $text] = $b;

    $text = trim($text);

    if (mb_strlen($text) < 3) continue;
    if (preg_match('/{{|}}/', $text)) continue;

    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $text));
    $suggestedKey = "$module.$tag.$slug";

    echo "\n🔹 Texto encontrado em <$tag>: \"$text\"\n";
    $key = askKey($suggestedKey, $stdin);

    $translations[$key] = $text;

    $content = str_replace(
        $text,
        "{{ trans('$key') }}",
        $content
    );
}

/* ---------------------------------------------------------
 | 3. STRINGS FALLBACK (?? 'Texto')
 | ---------------------------------------------------------*/
preg_match_all(
    "/\\?\\?\\s*'([^']{3,})'/",
    $content,
    $fallbacks,
    PREG_SET_ORDER
);

foreach ($fallbacks as $f) {
    $text = trim($f[1]);

    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $text));
    $suggestedKey = "$module.fallback.$slug";

    echo "\n🔹 Texto fallback encontrado: \"$text\"\n";
    $key = askKey($suggestedKey, $stdin);

    $translations[$key] = $text;

    $content = str_replace(
        "'$text'",
        "trans('$key')",
        $content
    );
}

/* ---------------------------------------------------------
 | OUTPUT
 | ---------------------------------------------------------*/
if ($content !== $original) {
    file_put_contents($file . '.refactored.vue', $content);
    echo "\n📝 Vue refatorado (preview): {$file}.refactored.vue\n";
}

if (!empty($translations)) {

    $grouped = [];

    foreach ($translations as $key => $value) {
        [$mod, $section, $name] = array_pad(explode('.', $key, 3), 3, 'general');
        $grouped[$section][$name] = $value;
    }

    $php = "<?php\n\nreturn [\n";

    foreach ($grouped as $section => $items) {
        $php .= "\n    /* ------------------------------------------------------------------\n";
        $php .= "     | $section\n";
        $php .= "     | ------------------------------------------------------------------ */\n";
        $php .= "    '$section' => [\n";
        foreach ($items as $k => $v) {
            $php .= "        '$k' => '" . addslashes($v) . "',\n";
        }
        $php .= "    ],\n";
    }

    $php .= "];\n";

    $outFile = dirname($file) . "/_i18n_interactive_$module.php";
    file_put_contents($outFile, $php);

    echo "📝 Mapa i18n gerado: $outFile\n";
}

echo "\n🎯 Processo concluído. Revê os ficheiros antes de integrar.\n";
PHP
