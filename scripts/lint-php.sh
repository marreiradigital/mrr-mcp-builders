#!/usr/bin/env bash
#
# Lint de sintaxe (php -l) em todos os arquivos PHP do plugin.
#
# Rodar com a MENOR versao de PHP suportada (o "Requires PHP" do header) e o
# que impede um construto novo demais — union type, enum, readonly, tipo
# literal `true` — de entrar no codigo e so estourar em producao, no site do
# usuario final. Foi exatamente esse buraco que deixou passar um `true|\WP_Error`
# (PHP 8.2+) num plugin que se declarava PHP 8.0.
#
# Uso:
#   bash scripts/lint-php.sh            # usa o `php` do PATH
#   PHP_BIN=/caminho/php7.4 bash scripts/lint-php.sh
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$REPO_ROOT/marreira-mcp-builders"
PHP_BIN="${PHP_BIN:-php}"

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    echo "PHP nao encontrado: $PHP_BIN" >&2
    exit 1
fi

if [ ! -d "$PLUGIN_DIR" ]; then
    echo "Pasta do plugin nao encontrada: $PLUGIN_DIR" >&2
    exit 1
fi

echo "Lint de sintaxe com PHP $("$PHP_BIN" -r 'echo PHP_VERSION;')"
echo "Pasta: ${PLUGIN_DIR#"$REPO_ROOT"/}"
echo

fail=0
total=0

while IFS= read -r file; do
    total=$((total + 1))
    if ! out="$("$PHP_BIN" -l "$file" 2>&1)"; then
        fail=$((fail + 1))
        echo "FALHOU: ${file#"$REPO_ROOT"/}"
        printf '%s\n' "$out" | sed 's/^/    /'
    fi
done < <(find "$PLUGIN_DIR" -type f -name '*.php' | sort)

echo "------------------------------------------------------------"
if [ "$fail" -gt 0 ]; then
    echo "$fail de $total arquivo(s) com erro de sintaxe."
    exit 1
fi

echo "OK: $total arquivo(s) sem erro de sintaxe."
