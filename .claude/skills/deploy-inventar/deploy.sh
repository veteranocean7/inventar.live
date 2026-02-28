#!/bin/bash
# Deploy script pentru inventar.live
# Utilizare: ./deploy.sh [fisier|all]

FTP_HOST="ftp.inventar.live"
FTP_USER="inventar"
FTP_PASS="fhU&5wmciL7f"
REMOTE_BASE="/public_html"

# Fișiere excluse din deploy
EXCLUDED=(
    "config.php"
    "config_central.php"
    "config_claude.php"
    "google-vision-key.json"
    "*.log"
    "vendor/*"
    "imagini_obiecte/*"
    "imagini_decupate/*"
    ".git/*"
    ".idea/*"
    ".claude/*"
)

# Funcție pentru upload
upload_file() {
    local file="$1"
    local remote_path="$REMOTE_BASE/$file"

    # Verifică dacă fișierul este exclus
    for pattern in "${EXCLUDED[@]}"; do
        if [[ "$file" == $pattern ]]; then
            echo "⊘ SKIP: $file (exclus)"
            return 0
        fi
    done

    # Verifică dacă fișierul există
    if [[ ! -f "$file" ]]; then
        echo "✗ EROARE: $file nu există"
        return 1
    fi

    # Upload
    NETRC=$(mktemp)
    echo -e "machine $FTP_HOST\nlogin $FTP_USER\npassword $FTP_PASS" > "$NETRC"

    if curl -s -n --netrc-file "$NETRC" -T "$file" "ftp://$FTP_HOST$remote_path" 2>/dev/null; then
        local size=$(du -h "$file" | cut -f1)
        echo "✓ $file ($size)"
        rm -f "$NETRC"
        return 0
    else
        echo "✗ EROARE la upload: $file"
        rm -f "$NETRC"
        return 1
    fi
}

# Main
echo "Deploy inventar.live"
echo "===================="
echo ""

if [[ "$1" == "all" ]]; then
    # Deploy toate fișierele modificate în git
    echo "Căutare fișiere modificate..."
    files=$(git status --porcelain 2>/dev/null | grep -E '^\s*M|^\s*A' | awk '{print $2}')

    if [[ -z "$files" ]]; then
        echo "Nu există fișiere modificate."
        exit 0
    fi

    count=0
    success=0

    while IFS= read -r file; do
        ((count++))
        if upload_file "$file"; then
            ((success++))
        fi
    done <<< "$files"

    echo ""
    echo "Deploy complet! $success/$count fișiere urcate."

elif [[ -n "$1" ]]; then
    # Deploy fișier sau director specific
    if [[ -d "$1" ]]; then
        # Director
        echo "Deploy director: $1"
        find "$1" -type f | while read -r file; do
            upload_file "$file"
        done
    else
        # Fișier
        upload_file "$1"
    fi
else
    echo "Utilizare:"
    echo "  ./deploy.sh fisier.php     - Deploy fișier specific"
    echo "  ./deploy.sh director/      - Deploy tot directorul"
    echo "  ./deploy.sh all            - Deploy toate fișierele modificate în git"
fi
