# Migration de la base de données - Plateforme ISTI

## Problème
La table `users` du schéma initial était incomplète. Plusieurs colonnes essentielles étaient manquantes :
- `matricule` (numéro d'étudiant)
- `date_naissance` (date de naissance)
- `telephone` (numéro de téléphone)
- `role` (rôle de l'utilisateur)
- `created_at` et `updated_at` (timestamps)

## Solution
Le schéma a été mis à jour et un script de migration automatique a été créé.

## Comment appliquer la migration

### Option 1: Migration automatique (Recommandée)
1. Accédez à l'URL : `http://localhost/plateformeisti/database/migrate.php`
2. Le script détectera et ajoutera automatiquement les colonnes manquantes
3. Suivez les instructions à l'écran

### Option 2: Migration manuelle
Si la migration automatique ne fonctionne pas, exécutez ces commandes SQL dans phpMyAdmin ou votre client MySQL :

```sql
-- Ajouter les colonnes manquantes
ALTER TABLE users ADD COLUMN matricule VARCHAR(20) UNIQUE;
ALTER TABLE users ADD COLUMN date_naissance DATE;
ALTER TABLE users ADD COLUMN telephone VARCHAR(20);
ALTER TABLE users ADD COLUMN role ENUM('admin', 'resp_dept', 'resp_filiere', 'resp_classe', 'etudiant', 'enseignant', 'agent_admin') DEFAULT 'etudiant';
ALTER TABLE users ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE users ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Créer un index pour de meilleures performances
CREATE INDEX idx_users_matricule ON users(matricule);
```

## Vérification
Après la migration, vérifiez que ces fonctionnalités fonctionnent :
- ✅ Génération de relevés de notes
- ✅ Gestion des étudiants avec matricules
- ✅ Import/export de données
- ✅ Attestations et certificats

## Fichiers modifiés
- `database/plateformeisti.sql` - Schéma mis à jour
- `database/migrate.php` - Script de migration automatique
- `database/migration_users_columns.sql` - Script SQL manuel
- `agent_administratif/saisie_donnees.php` - Requêtes corrigées

## Support
Si vous rencontrez des problèmes, vérifiez :
1. Les permissions de la base de données
2. La connexion à MySQL
3. Les logs d'erreur PHP