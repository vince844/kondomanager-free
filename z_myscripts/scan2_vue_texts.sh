#!/bin/bash

# =================================================
# CONFIGURAÇÃO BASE
# =================================================
LANG_BASE_DIR="/home/lg/develop/kondomanager-free/lang/pt"

# =================================================
# INPUT
# =================================================
read -p "Indica o caminho do ficheiro .vue a analisar: " VUE_FILE < /dev/tty
[[ -z "$VUE_FILE" || ! -f "$VUE_FILE" ]] && echo "❌ Ficheiro Vue inválido." && exit 1

echo
echo "Seleciona o ficheiro de traduções destino:"
echo

mkdir -p "$LANG_BASE_DIR"
mapfile -t LANG_FILES < <(ls "$LANG_BASE_DIR"/*.php 2>/dev/null | xargs -n 1 basename)

i=1
for file in "${LANG_FILES[@]}"; do
  echo "$i) $file"
  ((i++))
done

echo "$i) Criar novo ficheiro"
read -p "Opção: " FILE_OPTION < /dev/tty

if [[ "$FILE_OPTION" =~ ^[0-9]+$ ]] && (( FILE_OPTION >= 1 && FILE_OPTION < i )); then
  FILE_NAME="${LANG_FILES[$((FILE_OPTION - 1))]}"
elif [[ "$FILE_OPTION" == "$i" ]]; then
  read -p "Indica o nome do novo ficheiro (.php): " FILE_NAME < /dev/tty
  [[ "$FILE_NAME" != *.php ]] && FILE_NAME="$FILE_NAME.php"
else
  echo "❌ Opção inválida."
  exit 1
fi

FILE_NAMESPACE="$(basename "$FILE_NAME" .php)"
OUTPUT_FILE="$LANG_BASE_DIR/$FILE_NAME"

# =================================================
# CRIAR FICHEIRO PHP BASE
# =================================================
if [[ ! -f "$OUTPUT_FILE" ]]; then
cat <<EOF > "$OUTPUT_FILE"
<?php

return [

];
EOF
fi

# =================================================
# UTILS
# =================================================
normalize_key() {
  echo "$1" \
    | tr '[:upper:]' '[:lower:]' \
    | sed 's/[àáâãäå]/a/g; s/[èéêë]/e/g; s/[ìíîï]/i/g;
           s/[òóôõö]/o/g; s/[ùúûü]/u/g; s/ç/c/g' \
    | sed 's/[^a-z0-9 ]//g' \
    | tr ' ' '_'
}

print_context() {
  echo
  echo "────────────────────────────────────────"
  echo "📌 Tipo:     $1"
  echo "📍 Linha:    $2"
  echo "📄 Código:"
  echo "$3"
  echo "➡ Texto:    \"$4\""
  echo "🏷 Contexto: $5"
  echo "🔑 Key:      $FILE_NAMESPACE.$5.$6"
  echo "────────────────────────────────────────"
}

# =================================================
# ASK KEY + VALUE
# =================================================
ask_key_and_confirm() {
  local suggested_key="$1"
  local suggested_value="$2"

  read -p "Criar variável para este texto? (s/n) " confirm < /dev/tty
  [[ "$confirm" != "s" ]] && return 1

  read -e -i "$suggested_key" -p "🔧 Nome da key: " final_key < /dev/tty
  read -e -i "$suggested_value" -p "🌍 Valor traduzido: " final_value < /dev/tty

  final_key="${final_key//|/}"
  final_value="$(echo "$final_value" | tr '\n' ' ')"

  printf '%s|%s\n' "$final_key" "$final_value"
}

# =================================================
# ESCREVER NO PHP
# =================================================
add_translation() {
  local context="$1"
  local key="$2"
  local value="$3"
  local PAD=40

  if awk "/'$context'[[:space:]]*=>[[:space:]]*\[/{f=1}
          f && /'$key'[[:space:]]*=>/{found=1}
          f && /^\s*\],/{f=0}
          END{exit !found}" "$OUTPUT_FILE"; then
    return
  fi

  tmp=$(mktemp)
  in_ctx=false
  inserted=false

  while IFS= read -r line || [[ -n "$line" ]]; do
    if echo "$line" | grep -Eq "'$context'\s*=>\s*\["; then
      in_ctx=true
      echo "$line" >> "$tmp"
      continue
    fi

    if $in_ctx && ! $inserted && echo "$line" | grep -Eq '^\s*\],'; then
      printf "        '%-*s' => \"%s\",\n" "$PAD" "$key" "$value" >> "$tmp"
      inserted=true
      in_ctx=false
    fi

    echo "$line" >> "$tmp"
  done < "$OUTPUT_FILE"

  if ! grep -Eq "'$context'\s*=>\s*\[" "$OUTPUT_FILE"; then
    tmp2=$(mktemp)
    while IFS= read -r line || [[ -n "$line" ]]; do
      if echo "$line" | grep -Eq '^\s*\];'; then
        cat <<EOF >> "$tmp2"

    /* ------------------------------------------------------------------
     | $context
     | ------------------------------------------------------------------ */
    '$context' => [
        '$(printf "%-*s" "$PAD" "$key")' => "$value",
    ],

EOF
      fi
      echo "$line" >> "$tmp2"
    done < "$OUTPUT_FILE"
    mv "$tmp2" "$tmp"
  fi

  mv "$tmp" "$OUTPUT_FILE"
}

# =================================================
# IMPORT trans()
# =================================================
ensure_trans_import() {
  grep -q "laravel-vue-i18n" "$VUE_FILE" && return

  tmp=$(mktemp)
  inserted=false

  while IFS= read -r line || [[ -n "$line" ]]; do
    echo "$line" >> "$tmp"
    if [[ "$inserted" != true && "$line" =~ \<script ]]; then
      echo "import { trans } from 'laravel-vue-i18n';" >> "$tmp"
      inserted=true
    fi
  done < "$VUE_FILE"

  mv "$tmp" "$VUE_FILE"
}

# =================================================
# REGRAS CONFIGURÁVEIS
# =================================================
declare -A TAG_RULES

TAG_RULES["Head.title"]='<Head[^>]*title="[^"]{2,}"|title="([^"]+)"|header|:title="trans(%s)"'
TAG_RULES["Heading.title"]='<Heading[^>]*title="[^"]{2,}"|title="([^"]+)"|header_title|:title="trans(%s)"'
TAG_RULES["Heading.description"]='<Heading[^>]*description="[^"]{2,}"|description="([^"]+)"|header_description|:description="trans(%s)"'
TAG_RULES["Label"]='<Label[^>]*>[^<]{2,}</Label>|<Label[^>]*>([^<]+)</Label>|label|>{{ trans(%s) }}<'
TAG_RULES["Input.placeholder"]='<Input\b|placeholder="([^"]+)"|input|:placeholder="trans(%s)"'
TAG_RULES["Button.text"]='<Button\b[^>]*>|^\s*([^<]{2,})\s*$|button|{{ trans(%s) }}'
TAG_RULES["CommandEmpty.text"]='<CommandEmpty\b[^>]*>[^<]*</CommandEmpty>|<CommandEmpty\b[^>]*>\s*([^<]+)\s*</CommandEmpty>|empty_state|<CommandEmpty>{{ trans(%s) }}</CommandEmpty>'
TAG_RULES["CommandInput.placeholder"]='<CommandInput\b[^>]*placeholder="[^"]{2,}"|placeholder="([^"]+)"|search|:placeholder="trans(%s)"'


# =================================================
# MOTOR GENÉRICO
# =================================================
inside_template=false

process_template_line() {
  local line="$1"
  local line_no="$2"

  [[ "$line" =~ \<template\> ]] && inside_template=true
  [[ "$line" =~ \<\/template\> ]] && inside_template=false
  [[ "$inside_template" != true ]] && return

  for rule in "${!TAG_RULES[@]}"; do
    IFS='|' read -r detect extract context replace_tpl <<< "${TAG_RULES[$rule]}"

    echo "$line" | grep -Eq "$detect" || continue

    # Extrair texto (usando regex apenas para leitura)
    text=$(sed -nE "s/.*$extract.*/\1/p" <<< "$line")
    [[ -z "$text" || "$text" =~ trans\( ]] && continue

    key=$(normalize_key "$text")
    print_context "$rule" "$line_no" "$line" "$text" "$context" "$key"

    result=$(ask_key_and_confirm "$key" "$text") || continue
    final_key="${result%%|*}"
    final_val="${result#*|}"

    add_translation "$context" "$final_key" "$final_val"
    ensure_trans_import

    # -------------------------------------------------
    # SUBSTITUIÇÃO SEGURA (texto literal, não regex)
    # -------------------------------------------------

    replace=$(printf "$replace_tpl" "'$FILE_NAMESPACE.$context.$final_key'")

    # texto original literal (ex: placeholder="Cerca condominio...")
    original=$(printf '%s="%s"' "${extract%%=*}" "$text")

    safe_original=$(printf '%s\n' "$original" | sed 's/[\/&|]/\\&/g')
    safe_replace=$(printf '%s\n' "$replace"  | sed 's/[\/&|]/\\&/g')

    sed -i "s|$safe_original|$safe_replace|g" "$VUE_FILE"
  done
}


# =================================================
# LOOP PRINCIPAL
# =================================================
line_no=0
while IFS= read -r line || [[ -n "$line" ]]; do
  ((line_no++))
  process_template_line "$line" "$line_no"
done < "$VUE_FILE"

echo
echo "✔ Processo concluído com sucesso."
