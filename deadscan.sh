#!/bin/sh
# Ús: ./deadscan.sh [PROJECT_ROOT]
# - PROJECT_ROOT: arrel del projecte Magento (per incloure vendor/). Per defecte, "."

PROJECT_ROOT="${1:-.}"
MODULE_DIR="."  # executa'l des del root del teu mòdul

if [ ! -d "$MODULE_DIR/Api" ] || [ ! -d "$MODULE_DIR/Model" ] || [ ! -d "$MODULE_DIR/etc" ]; then
  echo "Si us plau, executa aquest script al root del mòdul (on hi ha Api/, Model/, etc/)." >&2
  exit 1
fi

echo "== Escanejant INTERFACES a Api/Data =="
find "$MODULE_DIR/Api/Data" -maxdepth 1 -name '*Interface.php' 2>/dev/null | sort | while read -r f; do
  name=$(basename "$f" .php)
  ns=$(grep -E '^namespace ' "$f" | head -n1 | sed -E 's/^namespace ([^;]+);/\1/')
  [ -z "$ns" ] && continue
  fqn="${ns}\\${name}"
  fqn_rg=$(printf '%s' "$fqn" | sed 's/\\/\\\\\\\\/g')

  # Totes les coincidències fora del propi fitxer
  all_hits=$(rg -n --no-heading -S -e "\\b${name}\\b" -e "${fqn_rg}" "$PROJECT_ROOT" | grep -v "^\./$(printf '%s' "$f" | sed 's/[].[^$*\/]/\\&/g')")
  # Filtrat d'etc/di.xml (no és ús real)
  real_hits=$(printf '%s\n' "$all_hits" | grep -v '/etc/di\.xml' | grep -v '^$')

  # Indicadors de type-hints/ús “real”
  sig_hits=$(printf '%s\n' "$real_hits" | grep -E 'implements|function|:|@param|@return|\$[A-Za-z_][A-Za-z0-9_]*\s*:\s*'"$name" 2>/dev/null)

  if [ -z "$real_hits" ]; then
    echo "🗑  ${name}  → SENSE ÚS (només definició i/o di.xml)"
  else
    # Compte: pot ser només PHPDoc
    if printf '%s\n' "$sig_hits" | grep -qE 'implements|function|:'; then
      echo "✅ ${name}  → sembla USAT"
    else
      echo "❓ ${name}  → només docs/imports (revisa)"
    fi
    # Mostra una línia de context
    printf '%s\n' "$real_hits" | head -n 2 | sed 's/^/     • /'
  fi
done

echo
echo "== Escanejant CLASSES a Model/* (possibles DataObject inús) =="
find "$MODULE_DIR/Model" -type f -name '*.php' 2>/dev/null | sort | while read -r f; do
  # Nom de classe (sense namespace): línia class X...
  cname=$(grep -E '^(final\s+)?class\s+[A-Za-z_][A-Za-z0-9_]*' "$f" | head -n1 | sed -E 's/^(final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*).*/\2/')
  [ -z "$cname" ] && continue

  ns=$(grep -E '^namespace ' "$f" | head -n1 | sed -E 's/^namespace ([^;]+);/\1/')
  [ -z "$ns" ] && continue
  fqn="${ns}\\${cname}"
  fqn_rg=$(printf '%s' "$fqn" | sed 's/\\/\\\\\\\\/g')

  all_hits=$(rg -n --no-heading -S -e "\\b${cname}\\b" -e "${fqn_rg}" "$PROJECT_ROOT" | grep -v "^\./$(printf '%s' "$f" | sed 's/[].[^$*\/]/\\&/g')")
  real_hits=$(printf '%s\n' "$all_hits" | grep -v '/etc/di\.xml' | grep -v '^$')

  if [ -z "$real_hits" ]; then
    echo "🗑  ${cname}  → SENSE ÚS (només definició i/o di.xml)"
  else
    echo "✅ ${cname}  → té usos"
    printf '%s\n' "$real_hits" | head -n 2 | sed 's/^/     • /'
  fi
done

echo
echo "Pista: tot el que surti 🗑 és candidat a eliminar. El que surti ❓ val la pena mirar-ho 30s."
