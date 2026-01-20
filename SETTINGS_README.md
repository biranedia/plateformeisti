# Paramètres Système - Plateforme ISTI

## Vue d'ensemble
Le système de paramètres permet de gérer facilement la configuration de la plateforme sans modifier le code source.

## Table `settings`
```sql
CREATE TABLE settings (
    id SERIAL PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string',
    description TEXT,
    category VARCHAR(50) DEFAULT 'general',
    is_system BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

## Fonctions utilitaires

### `getSetting($key, $default = null, $forceReload = false)`
Récupère la valeur d'un paramètre système.

**Paramètres :**
- `$key` : Clé du paramètre
- `$default` : Valeur par défaut si le paramètre n'existe pas
- `$forceReload` : Forcer le rechargement depuis la base de données

**Exemples :**
```php
// Paramètre string
$siteName = getSetting('site_name', 'Mon Site');

// Paramètre boolean
$registrationOpen = getSetting('registration_open', false);

// Paramètre integer
$maxFileSize = getSetting('max_file_size', 10485760);

// Forcer le rechargement
$value = getSetting('my_param', 'default', true);
```

### `setSetting($key, $value, $type = 'string')`
Définit la valeur d'un paramètre système.

**Paramètres :**
- `$key` : Clé du paramètre
- `$value` : Nouvelle valeur
- `$type` : Type de la valeur ('string', 'integer', 'boolean', 'json')

**Exemples :**
```php
// Paramètre string
setSetting('site_name', 'Nouvelle Plateforme ISTI');

// Paramètre boolean
setSetting('maintenance_mode', true, 'boolean');

// Paramètre integer
setSetting('max_file_size', 20971520, 'integer');

// Paramètre JSON
setSetting('allowed_domains', ['isti.edu', 'example.com'], 'json');
```

### `getAllSettings()`
Récupère tous les paramètres organisés par catégorie.

**Retour :** Array associatif organisé par catégorie

**Exemple :**
```php
$allSettings = getAllSettings();
foreach ($allSettings as $category => $settings) {
    echo "Catégorie: $category\n";
    foreach ($settings as $key => $setting) {
        echo "- $key: {$setting['value']} ({$setting['type']})\n";
    }
}
```

## Paramètres par défaut

### Catégorie `general`
- `site_name` : Nom du site web
- `site_description` : Description du site

### Catégorie `contact`
- `admin_email` : Email de l'administrateur

### Catégorie `upload`
- `max_file_size` : Taille maximale des fichiers (en octets)
- `allowed_file_types` : Types de fichiers autorisés

### Catégorie `academic`
- `academic_year_start` : Début de l'année académique (MM-JJ)
- `academic_year_end` : Fin de l'année académique (MM-JJ)
- `registration_open` : Inscriptions ouvertes (boolean)

### Catégorie `system`
- `timezone` : Fuseau horaire
- `language` : Langue par défaut
- `maintenance_mode` : Mode maintenance (boolean)

### Catégorie `notification`
- `notification_email` : Activer les notifications par email (boolean)

## Utilisation dans le code

```php
<?php
require_once 'config/utils.php';

// Vérifier si les inscriptions sont ouvertes
if (getSetting('registration_open', true)) {
    // Afficher le formulaire d'inscription
}

// Récupérer la taille maximale des fichiers
$maxSize = getSetting('max_file_size', 10485760);

// Vérifier le mode maintenance
if (getSetting('maintenance_mode', false)) {
    // Afficher page de maintenance
    exit;
}
?>
```

## Cache
Les paramètres sont mis en cache pour optimiser les performances. Le cache se rafraîchit automatiquement toutes les 5 minutes ou peut être forcé avec `$forceReload = true`.