#!/bin/bash

echo "=== Application Initialization Script ==="

# Installer les assets des bundles (EasyAdmin, etc.)
# Toujours installer pour s'assurer qu'ils sont à jour (copie par défaut, pas de symlink)
echo "📦 Installing bundle assets (EasyAdmin, etc.)..."
php bin/console assets:install public --env=prod --no-debug 2>&1 | grep -E "(Installing|Bundle|Error|OK)" || echo "Assets installation completed"

# Vérifier que les assets sont bien installés
if [ -d 'public/bundles/easyadmin' ]; then
    echo "✓ EasyAdmin assets directory found"
    CSS_COUNT=$(ls -1 public/bundles/easyadmin/*.css 2>/dev/null | wc -l | tr -d ' ')
    JS_COUNT=$(ls -1 public/bundles/easyadmin/*.js 2>/dev/null | wc -l | tr -d ' ')
    echo "Found $CSS_COUNT CSS files and $JS_COUNT JS files"
    if [ "$CSS_COUNT" -eq 0 ] || [ "$JS_COUNT" -eq 0 ]; then
        echo "⚠️  WARNING: Missing CSS or JS files!"
        echo "Listing EasyAdmin directory contents:"
        ls -la public/bundles/easyadmin/ | head -15
    else
        echo "✓ EasyAdmin assets installed successfully"
        # Afficher quelques exemples de fichiers
        echo "Sample CSS files:"
        ls -1 public/bundles/easyadmin/*.css 2>/dev/null | head -3
        echo "Sample JS files:"
        ls -1 public/bundles/easyadmin/*.js 2>/dev/null | head -3
    fi
else
    echo "❌ ERROR: EasyAdmin assets not found after installation!"
    echo "Listing public/bundles directory:"
    ls -la public/bundles/ 2>/dev/null || echo "public/bundles directory does not exist"
    echo "Trying to create directory and reinstall..."
    mkdir -p public/bundles
    php bin/console assets:install public --env=prod --no-debug
fi

# Vérifier si les assets vendor sont installés (uniquement si nécessaire)
if [ ! -d 'assets/vendor/@hotwired/stimulus' ]; then
    echo "⚠️  Vendor assets not found - installing..."
    
    if command -v node &> /dev/null && command -v npm &> /dev/null; then
        echo "Node.js: $(node --version), npm: $(npm --version)"
        php bin/console importmap:install --env=prod --no-debug || echo "ERROR: importmap:install failed"
        php bin/console asset-map:compile --env=prod --no-debug || echo "ERROR: asset-map:compile failed"
    else
        echo "❌ ERROR: Node.js/npm not found. Cannot install vendor assets."
        exit 1
    fi
fi

# Exécuter les migrations de base de données (toujours nécessaire pour les nouvelles migrations)
echo "Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --env=prod --no-debug || echo "⚠️  WARNING: Migrations failed, continuing anyway..."

# Vider le cache pour s'assurer que Symfony reconnaît les nouveaux assets
echo "Clearing cache..."
php bin/console cache:clear --env=prod --no-debug

# Vérifier les permissions sur les assets
if [ -d 'public/bundles/easyadmin' ]; then
    echo "Setting permissions on EasyAdmin assets..."
    chmod -R 755 public/bundles/easyadmin 2>/dev/null || true
    echo "EasyAdmin assets should be accessible at /bundles/easyadmin/"
fi

echo "=== Initialization complete ==="

# Démarrer FrankenPHP/Caddy
# Essayer différents chemins possibles pour caddy
CADDY_PATH=""
if command -v caddy &> /dev/null; then
    CADDY_PATH="caddy"
elif [ -f "/usr/local/bin/caddy" ]; then
    CADDY_PATH="/usr/local/bin/caddy"
elif [ -f "/usr/bin/caddy" ]; then
    CADDY_PATH="/usr/bin/caddy"
elif [ -f "/bin/caddy" ]; then
    CADDY_PATH="/bin/caddy"
fi

if [ -n "$CADDY_PATH" ]; then
    echo "Starting FrankenPHP server with $CADDY_PATH..."
    exec $CADDY_PATH run --config ./Caddyfile
elif command -v frankenphp &> /dev/null; then
    echo "Starting FrankenPHP server with frankenphp..."
    exec frankenphp run --config ./Caddyfile
else
    echo "WARNING: Cannot find caddy or frankenphp."
    echo "Using PHP built-in server as fallback..."
    echo "Note: This may not support all FrankenPHP features."
    # Utiliser le router pour servir les fichiers statiques directement
    exec php -S 0.0.0.0:${PORT:-8000} -t public public/router.php
fi

