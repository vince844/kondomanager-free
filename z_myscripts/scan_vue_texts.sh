#!/bin/bash

LANG_BASE_DIR="/home/lg/develop/kondomanager-free/lang/pt"

# =================================================
# INPUT
# =================================================
read -p "Indica o caminho do ficheiro .vue a analisar: " VUE_FILE < /dev/tty
[[ -z "$VUE_FILE" || ! -f "$VUE_FILE" ]] && echo "❌ Ficheiro Vue inválido." && exit 1

echo
echo "Seleciona o ficheiro de traduções destino:"
echo

# garantir que a pasta existe
mkdir -p "$LANG_BASE_DIR"

# listar ficheiros existentes
mapfile -t LANG_FILES < <(ls "$LANG_BASE_DIR"/*.php 2>/dev/null | xargs -n 1 basename)

i=1
for file in "${LANG_FILES[@]}"; do
  echo "$i) $file"
  ((i++))
done

echo "$i) Criar novo ficheiro"
read -p "Opção: " FILE_OPTION < /dev/tty

# -------------------------------------------------
# Escolha do ficheiro
# -------------------------------------------------
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
  local type="$1"
  local line_no="$2"
  local code="$3"
  local text="$4"
  local context="$5"
  local key="$6"

  echo
  echo "────────────────────────────────────────"
  echo "📌 Tipo:     $type"
  echo "📍 Linha:    $line_no"
  echo "📄 Código:"
  echo "$code"
  echo "➡ Texto:    \"$text\""
  echo "🏷 Contexto: $context"
  echo "🔑 Key:      $FILE_NAMESPACE.$context.$key"
  echo "────────────────────────────────────────"
}

# =================================================
# ASK KEY + VALUE
# =================================================
ask_key_and_confirm() {
  local suggested_key="$1"
  local suggested_value="$2"
  local final_key
  local final_value
  local confirm

  read -p "Criar variável para este texto? (s/n) " confirm < /dev/tty
  [[ "$confirm" != "s" ]] && return 1

  read -e -i "$suggested_key" -p "🔧 Nome da key (Enter para manter): " final_key < /dev/tty
  [[ -z "$final_key" ]] && final_key="$suggested_key"

  # segurança: a key nunca pode conter '|'
  final_key="${final_key//|/}"

  read -e -i "$suggested_value" -p "🌍 Valor traduzido: " final_value < /dev/tty
  [[ -z "$final_value" ]] && final_value="$suggested_value"

  # segurança: remover quebras de linha
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
  local KEY_PAD_WIDTH=40  # largura fixa para alinhamento

  # -------------------------------------------------
  # Se a key já existir dentro do contexto, não faz nada
  # -------------------------------------------------
  if awk "/'$context'[[:space:]]*=>[[:space:]]*\[/{flag=1}
          flag && /'$key'[[:space:]]*=>/{found=1}
          flag && /^\s*\],/{flag=0}
          END{exit !found}" "$OUTPUT_FILE"; then
    return
  fi

  tmp=$(mktemp)
  in_context=false
  inserted=false

  while IFS= read -r line || [[ -n "$line" ]]; do

    # entrou no contexto
    if echo "$line" | grep -Eq "'$context'\s*=>\s*\["; then
      in_context=true
      echo "$line" >> "$tmp"
      continue
    fi

    # inserir antes de fechar o contexto
    if $in_context && ! $inserted && echo "$line" | grep -Eq '^\s*\],'; then
      printf "        '%-*s' => \"%s\",\n" \
        "$key" "$KEY_PAD_WIDTH" "$value" >> "$tmp"

      inserted=true
      in_context=false
      echo "$line" >> "$tmp"
      continue
    fi

    echo "$line" >> "$tmp"
  done < "$OUTPUT_FILE"

  # -------------------------------------------------
  # Se o contexto ainda não existir, cria-o formatado
  # -------------------------------------------------
  if ! grep -Eq "'$context'\s*=>\s*\[" "$OUTPUT_FILE"; then
    tmp2=$(mktemp)

    while IFS= read -r line || [[ -n "$line" ]]; do
      if echo "$line" | grep -Eq '^[[:space:]]*\];[[:space:]]*$'; then

        printf "\n    /* ------------------------------------------------------------------\n" >> "$tmp2"
        printf "     | %s\n" "$context" >> "$tmp2"
        printf "     | ------------------------------------------------------------------ */\n" >> "$tmp2"
        printf "    '%s' => [\n" "$context" >> "$tmp2"
        printf "        '%-*s' => \"%s\",\n" \
          "$KEY_PAD_WIDTH" "$key" "$value" >> "$tmp2"
        printf "    ],\n\n" >> "$tmp2"

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
# PROCESSAMENTO TEMPLATE
# =================================================
inside_template=false
in_p_block=false
p_text=""
p_open_line=""
in_h4_block=false
h4_text=""
h4_open_line=""
in_input_block=false
input_block=""
in_link_block=false
link_block=""
in_tablecell_block=false
tablecell_text=""
tablecell_open_line=""

process_template_line() {
  local line="$1"
  local line_number="$2"

  # controlar entrada/saída do template
  [[ "$line" =~ \<template\> ]] && inside_template=true
  [[ "$line" =~ \<\/template\> ]] && inside_template=false
  [[ "$inside_template" != true ]] && return

  # -------------------------------------------------
  # <Head title="..."/>
  # -------------------------------------------------
  if echo "$line" | grep -Eq '<Head[^>]*title="[^"]{2,}"'; then
    text=$(sed -nE 's/.*title="([^"]+)".*/\1/p' <<< "$line")
    [[ -z "$text" || "$text" =~ trans\( ]] && return

    context="header"
    suggested_key=$(normalize_key "$text")

    print_context "header.title" "$line_number" "$line" "$text" "$context" "$suggested_key"

    result=$(ask_key_and_confirm "$suggested_key" "$text") || return
    final_key="${result%%|*}"
    final_value="${result#*|}"

    add_translation "$context" "$final_key" "$final_value"
    ensure_trans_import

    search='title="'$text'"'
    replace=':title="trans('$FILE_NAMESPACE.$context.$final_key')"'
    sed -i "s|$search|$replace|g" "$VUE_FILE"
    return
  fi

  # -------------------------------------------------
  # <Heading title="" description=""/>
  # -------------------------------------------------
  if echo "$line" | grep -Eq '<Heading'; then
    for attr in title description; do
      text=$(grep -oP "$attr=\"\K[^\"]+" <<< "$line")

      [[ -z "$text" || "$text" =~ trans\( ]] && continue

      context="header_$attr"
      suggested_key=$(normalize_key "$text")

      print_context "header.$attr" "$line_number" "$line" "$text" "$context" "$suggested_key"

      result=$(ask_key_and_confirm "$suggested_key" "$text") || continue
      final_key="${result%%|*}"
      final_value="${result#*|}"

      add_translation "$context" "$final_key" "$final_value"
      ensure_trans_import

      search=$attr'="'$text'"'
      replace=':'$attr'="trans('\''$FILE_NAMESPACE.$context.$final_key'\'')"'
      sed -i "s|$search|$replace|g" "$VUE_FILE"
    done
    return
  fi


  # -------------------------------------------------
  # <Label>Texto</Label>
  # -------------------------------------------------
  if echo "$line" | grep -Eq '<Label[^>]*>[^<]{2,}</Label>'; then
    text=$(sed -nE 's/.*<Label[^>]*>([^<]+)<\/Label>.*/\1/p' <<< "$line" | xargs)
    [[ -z "$text" || "$text" =~ trans\( ]] && return

    context="label"
    suggested_key=$(normalize_key "$text")

    print_context "label" "$line_number" "$line" "$text" "$context" "$suggested_key"

    result=$(ask_key_and_confirm "$suggested_key" "$text") || return
    final_key="${result%%|*}"
    final_value="${result#*|}"

    add_translation "$context" "$final_key" "$final_value"
    ensure_trans_import

    search='>'$text'<'
    replace='>{{ trans('$FILE_NAMESPACE.$context.$final_key') }}<'

    sed -i "s|$search|$replace|g" "$VUE_FILE"
    return
  fi

  # -------------------------------------------------
  # <Input ... />  (placeholder multi-linha)
  # -------------------------------------------------
  if echo "$line" | grep -Eq '<Input\b'; then
    in_input_block=true
    input_block="$line"
    return
  fi

  if [[ "$in_input_block" == true ]]; then
    input_block="$input_block $line"

    # fim do componente
    if echo "$line" | grep -Eq '/>'; then
      in_input_block=false

      # extrair placeholder
      text=$(sed -nE 's/.*placeholder="([^"]+)".*/\1/p' <<< "$input_block")

      [[ -z "$text" || "$text" =~ trans\( ]] && return

      context="input"
      suggested_key=$(normalize_key "$text")

      print_context "input.placeholder" "$line_number" "<Input ... />" "$text" "$context" "$suggested_key"

      result=$(ask_key_and_confirm "$suggested_key" "$text") || return
      final_key="${result%%|*}"
      final_value="${result#*|}"

      add_translation "$context" "$final_key" "$final_value"
      ensure_trans_import

      # substituir placeholder por binding com trans()
      search='placeholder="'$text'"'
      replace=':placeholder="trans('$FILE_NAMESPACE.$context.$final_key')"'

      sed -i "s|$search|$replace|g" "$VUE_FILE"
    fi
    return
  fi

  # -------------------------------------------------
  # <Link> ... <span>Texto</span> ... </Link>
  # -------------------------------------------------
  if echo "$line" | grep -Eq '<Link\b'; then
    in_link_block=true
    link_block="$line"
    return
  fi

  if [[ "$in_link_block" == true ]]; then
    link_block="$link_block $line"

    if echo "$line" | grep -Eq '</Link>'; then
      in_link_block=false

      text=$(sed -nE 's/.*<span[^>]*>([^<]+)<\/span>.*/\1/p' <<< "$link_block")
      [[ -z "$text" || "$text" =~ trans\( ]] && return

      context="button"
      suggested_key=$(normalize_key "$text")

      print_context "link.button" "$line_number" "<Link> … </Link>" "$text" "$context" "$suggested_key"

      result=$(ask_key_and_confirm "$suggested_key" "$text") || return
      final_key="${result%%|*}"
      final_value="${result#*|}"

      add_translation "$context" "$final_key" "$final_value"
      ensure_trans_import

      search="<span>$text</span>"
      replace="<span>{{ trans('$FILE_NAMESPACE.$context.$final_key') }}</span>"

      sed -i "s|$search|$replace|g" "$VUE_FILE"
    fi
    return
  fi


  # -------------------------------------------------
  # <h4> MULTI-LINHA
  # -------------------------------------------------
  if echo "$line" | grep -Eq '<h4[^>]*>'; then
    in_h4_block=true
    h4_text=""
    h4_open_line="$line"
    return
  fi

  if [[ "$in_h4_block" == true ]]; then
    if echo "$line" | grep -Eq '</h4>'; then
      in_h4_block=false
      text=$(echo "$h4_text" | xargs)
      [[ -z "$text" || "$text" =~ trans\( ]] && return

      context="ui"
      suggested_key=$(normalize_key "$text")

      print_context "h4 (multi-linha)" "$line_number" "$h4_open_line … </h4>" "$text" "$context" "$suggested_key"

      result=$(ask_key_and_confirm "$suggested_key" "$text") || return
      final_key="${result%%|*}"
      final_value="${result#*|}"

      add_translation "$context" "$final_key" "$final_value"
      ensure_trans_import

      search="$text"
      replace="{{ trans('$FILE_NAMESPACE.$context.$final_key') }}"
      sed -i "s|$search|$replace|g" "$VUE_FILE"
    else
      h4_text="$h4_text $line"
    fi
    return
  fi

  # -------------------------------------------------
  # <TableCell> MULTI-LINHA
  # -------------------------------------------------
  if echo "$line" | grep -Eq '<TableCell\b'; then
    in_tablecell_block=true
    tablecell_text=""
    tablecell_open_line="$line"
    return
  fi

  if [[ "$in_tablecell_block" == true ]]; then
    if echo "$line" | grep -Eq '</TableCell>'; then
      in_tablecell_block=false
      text=$(echo "$tablecell_text" | xargs)

      [[ -z "$text" || "$text" =~ trans\( ]] && return

      context="ui"
      suggested_key=$(normalize_key "$text")

      print_context "tablecell (multi-linha)" "$line_number" "$tablecell_open_line … </TableCell>" "$text" "$context" "$suggested_key"

      result=$(ask_key_and_confirm "$suggested_key" "$text") || return
      final_key="${result%%|*}"
      final_value="${result#*|}"

      add_translation "$context" "$final_key" "$final_value"
      ensure_trans_import

      search="$text"
      replace="{{ trans('\''$FILE_NAMESPACE.$context.$final_key'\'') }}"

      sed -i "s|$search|$replace|g" "$VUE_FILE"
    else
      tablecell_text="$tablecell_text $line"
    fi
    return
  fi

  # -------------------------------------------------
  # <p> MULTI-LINHA
  # -------------------------------------------------
  if echo "$line" | grep -Eq '<p[^>]*>'; then
    in_p_block=true
    p_text=""
    p_open_line="$line"
    return
  fi

  if $in_p_block; then
    if echo "$line" | grep -Eq '</p>'; then
      in_p_block=false
      text=$(echo "$p_text" | xargs)
      [[ -z "$text" || "$text" =~ trans\( ]] && return

      context="ui"
      suggested_key=$(normalize_key "$text")

      print_context "p (multi-linha)" "$line_number" "$p_open_line … </p>" "$text" "$context" "$suggested_key"

      result=$(ask_key_and_confirm "$suggested_key" "$text") || return
      final_key="${result%%|*}"
      final_value="${result#*|}"

      add_translation "$context" "$final_key" "$final_value"
      ensure_trans_import

      search=$text
      replace='{{ trans('\'''"$FILE_NAMESPACE.$context.$final_key"'\'''') }}'

      sed -i "s|$search|$replace|g" "$VUE_FILE"
    else
      p_text="$p_text $line"
    fi
  fi

}



# =================================================
# PROCESSAMENTO JS
# =================================================
process_js_strings() {
  local line="$1"
  local line_number="$2"

  [[ "$inside_template" == true ]] && return
  echo "$line" | grep -Eq '^\s*(import|export|return|\})' && return

  # LABEL
  if echo "$line" | grep -Eq 'label\s*:\s*['\''"]'; then
    text=$(sed -nE "s/.*label\s*:\s*['\"]([^'\"]+)['\"].*/\1/p" <<< "$line")
    [[ -z "$text" || "$text" =~ trans\( ]] && return

    context="label"
    suggested_key=$(normalize_key "$text")

    print_context "js.label" "$line_number" "$line" "$text" "$context" "$suggested_key"

    result=$(ask_key_and_confirm "$suggested_key" "$text") || return
    final_key="${result%%|*}"
    final_value="${result#*|}"

    add_translation "$context" "$final_key" "$final_value"
    ensure_trans_import
    sed -i "s/label\s*:\s*['\"]$text['\"]/label: trans('$FILE_NAMESPACE.$context.$final_key')/" "$VUE_FILE"
    return
  fi

  # STRINGS JS GENÉRICAS
  #while read -r text; do
  #  [[ "$text" =~ trans\( ]] && continue
  #  [[ ${#text} -lt 3 ]] && continue

  #  context="js"
 #   suggested_key=$(normalize_key "$text")

  #  print_context "js.string" "$line_number" "$line" "$text" "$context" "$suggested_key"
 #
 #   result=$(ask_key_and_confirm "$suggested_key" "$text") || continue
  #  final_key="${result%%|*}"
   # final_value="${result#*|}"

    #add_translation "$context" "$final_key" "$final_value"
    #ensure_trans_import
    #sed -i "s/['\"]$text['\"]/trans('$FILE_NAMESPACE.$context.$final_key')/" "$VUE_FILE"

  #done < <(
  #  echo "$line" | sed -nE "s/.*['\"]([^'\"]{3,})['\"].*/\1/p"
  #)

} 

# =================================================
# PROCESSAMENTO LINHA SIMPLES
# =================================================

process_single_line_string() {
  local line="$1"
  local line_number="$2"

  # procurar string literal simples
  if echo "$line" | grep -Eq "['\"][^'\"]{3,}['\"]"; then
    text=$(sed -nE "s/.*['\"]([^'\"]{3,})['\"].*/\1/p" <<< "$line")
    [[ -z "$text" || "$text" =~ trans\( ]] && return

    context="js"
    suggested_key=$(normalize_key "$text")

    print_context "single.line" "$line_number" "$line" "$text" "$context" "$suggested_key"

    result=$(ask_key_and_confirm "$suggested_key" "$text") || return
    final_key="${result%%|*}"
    final_value="${result#*|}"

    add_translation "$context" "$final_key" "$final_value"
    ensure_trans_import

    # substituir apenas esta string literal
    sed -i "${line_number}s|['\"][^'\"]*['\"]|trans('$FILE_NAMESPACE.$context.$final_key')|" "$VUE_FILE"

  fi
}


# =================================================
# MENU + LOOP PRINCIPAL
# =================================================
while true; do
  echo
  echo "Escolhe o modo de leitura:"
  echo "1) Ler o ficheiro completo"
  echo "2) Ler uma linha específica do ficheiro"
  echo "0) Sair"
  read -p "Opção (0/1/2): " READ_MODE < /dev/tty

  case "$READ_MODE" in
    1)
      # ---------------------------------------------
      # Ler ficheiro completo (executa uma vez e sai)
      # ---------------------------------------------
      line_number=0

      while IFS= read -r line || [[ -n "$line" ]]; do
        ((line_number++))
        process_template_line "$line" "$line_number"
        process_js_strings "$line" "$line_number"
      done < "$VUE_FILE"

      echo
      echo "✔ Ficheiro completo analisado."
      break
      ;;

    2)
      # ---------------------------------------------
      # Ler linha específica (modo interativo)
      # ---------------------------------------------
      read -p "Indica o número da linha a analisar (ou Enter para voltar): " TARGET_LINE < /dev/tty

      [[ -z "$TARGET_LINE" ]] && continue

      if ! [[ "$TARGET_LINE" =~ ^[0-9]+$ ]]; then
        echo "❌ Número de linha inválido."
        continue
      fi

      line=$(sed -n "${TARGET_LINE}p" "$VUE_FILE")

      if [[ -z "$line" ]]; then
        echo "❌ Linha $TARGET_LINE não existe no ficheiro."
        continue
      fi

      echo
      echo "📍 A processar linha $TARGET_LINE:"
      echo "$line"
      echo

      process_single_line_string "$line" "$TARGET_LINE"
      ;;
      
    0)
      # ---------------------------------------------
      # Sair
      # ---------------------------------------------
      echo
      echo "👋 A sair."
      break
      ;;

    *)
      echo "❌ Opção inválida."
      ;;
  esac
done

echo
echo "✔ Processo concluído com sucesso."


