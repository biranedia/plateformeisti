# 📋 Fonctionnalité: Gestion des Documents d'Inscription (BAC)

## Vue d'ensemble
Cette fonctionnalité permet aux **étudiants** de soumettre leurs documents essentiels (relevé BAC et diplôme BAC) lors de leur inscription sur la plateforme ISTI. Les **agents administratifs** peuvent ensuite valider ou rejeter ces documents.

## 🎯 Processus Complet

### 1️⃣ Étudiant - Inscription avec Documents

#### Page: [shared/register.php](shared/register.php)
- **Modification**: Ajout de champs de téléchargement de fichiers
- **Documents requis pour les étudiants**:
  - Relevé de notes du BAC (PDF, JPG, PNG - max 5MB)
  - Diplôme du BAC (PDF, JPG, PNG - max 5MB)

**Fonctionnalités**:
- ✅ Upload au moment de l'inscription
- ✅ Drag-and-drop support
- ✅ Validation du type de fichier (MIME type)
- ✅ Limitation de taille (5MB)
- ✅ Validation côté client et serveur

**Flux**:
```
1. Étudiant remplit le formulaire d'inscription
2. Sélectionne "Étudiant" comme rôle
3. Champs de documents apparaissent
4. Upload des fichiers (drag-and-drop ou clic)
5. Validation et création du compte
6. Documents stockés et enregistrés en BD
```

### 2️⃣ Agent Admin - Validation des Documents

#### Page: [agent_administratif/validation_documents.php](agent_administratif/validation_documents.php) (Nouveau)
- **Accès**: Réservé aux agents administratifs
- **Fonctionnalité**: Gérer et valider les documents des étudiants

**Interface**:
- Tableau de bord avec statistiques
- Filtres par statut (En attente, Validés, Rejetés)
- Liste des documents à valider
- Validation avec commentaires
- Rejet avec raison

**Actions disponibles**:
- 👁️ Voir le document (ouverture dans nouvel onglet)
- ✅ Valider (avec commentaire optionnel)
- ❌ Rejeter (avec raison obligatoire)

**Statuts**:
- 🟡 **soumis**: Document en attente de validation
- 🟢 **valide**: Document validé par l'administration
- 🔴 **rejete**: Document rejeté

### 3️⃣ Étudiant - Suivi des Documents

#### Page: [etudiant/documents.php](etudiant/documents.php) (Nouveau)
- **Accès**: Réservé à l'étudiant propriétaire
- **Fonctionnalité**: Consulter le statut de ses documents

**Affichage**:
- Liste de tous les documents soumis
- Statut de validation
- Commentaires/raisons du rejet
- Option de téléchargement

## 📊 Structure de Base de Données

### Table: `documents_inscription`

```sql
CREATE TABLE documents_inscription (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,                    -- Étudiant
    inscription_id INT,                      -- Inscription créée
    type_document ENUM(
        'releve_bac',
        'diplome_bac',
        'certificat',
        'autre'
    ) NOT NULL,
    nom_fichier VARCHAR(255) NOT NULL,       -- Nom original du fichier
    chemin_fichier VARCHAR(500) NOT NULL,    -- Chemin relatif
    type_mime VARCHAR(100),                  -- application/pdf, image/jpeg, etc.
    taille_fichier INT,                      -- Taille en bytes
    date_upload TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    statut ENUM('soumis', 'valide', 'rejete') DEFAULT 'soumis',
    commentaire_validation TEXT NULL,        -- Commentaire ou raison
    valide_par INT,                          -- Agent admin qui a validé
    date_validation TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_inscription (inscription_id),
    INDEX idx_type (type_document),
    INDEX idx_statut (statut)
)
```

## 📁 Structure de Fichiers

```
documents/
└── inscriptions/
    ├── user_1/
    │   ├── doc_releve_bac_1674124800.pdf
    │   └── doc_diplome_bac_1674124801.pdf
    ├── user_2/
    │   ├── doc_releve_bac_1674124900.pdf
    │   └── doc_diplome_bac_1674124901.pdf
    └── ...
```

## 🔒 Sécurité

### Validations Implémentées

1. **Type MIME**
   - Acceptés: PDF, JPEG, PNG
   - Refusés: Exécutables, scripts, etc.

2. **Taille des fichiers**
   - Maximum: 5MB par fichier
   - Validation côté serveur

3. **Authentification**
   - Inscription: Pas besoin d'être authentifié
   - Validation: Agent admin requis
   - Consultation: Étudiant propriétaire requis

4. **Stockage des fichiers**
   - Répertoire en dehors du web root
   - Noms de fichiers uniques
   - Permissions appropriées (chmod 0777)

### Protection des Uploads

```php
// Validation du MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $file['tmp_name']);

// Vérification du size
if ($file['size'] > $max_size) {
    // Rejet
}

// Stockage sécurisé
$safe_filename = 'doc_' . $doc_type . '_' . time() . '.' . $extension;
```

## 📝 Migration Effectuée

### Fichier: [database/migrate_documents_inscription.php](database/migrate_documents_inscription.php)

```bash
php database/migrate_documents_inscription.php
```

✅ Exécution réussie:
- Table `documents_inscription` créée
- Indexes créés pour performance
- Types ENUM définis

## 🎨 Interface Utilisateur

### Formulaire d'Upload (Inscription)

**Champs affichés si rôle = "Étudiant"**:

1. **Relevé de notes du BAC**
   - Zone drag-and-drop
   - Prévisualisation du fichier
   - Affichage du statut

2. **Diplôme du BAC**
   - Zone drag-and-drop
   - Prévisualisation du fichier
   - Affichage du statut

**JavaScript Features**:
- Drag-and-drop support
- Affichage des fichiers sélectionnés
- Validation côté client
- Messages d'erreur clairs

### Dashboard Agent Admin

**Statistiques**:
- Total documents
- En attente
- Validés
- Rejetés

**Filtres**:
- Par statut (En attente, Validés, Rejetés)
- Par type de document

**Tableau**:
- Informations étudiant
- Type et date du document
- Statut avec badge coloré
- Liens d'action

### Dashboard Étudiant

**Affichage**:
- Tous ses documents
- Statuts de validation
- Commentaires/raisons de rejet
- Taille des fichiers
- Dates d'upload

## 📊 Statistiques

La table suit les documents avec:
- **Total soumis**: Nombre de documents envoyés
- **En validation**: Documents en attente (statut = 'soumis')
- **Validés**: Documents acceptés
- **Rejetés**: Documents refusés

## 🔄 Flux de Notification (Optionnel à implémenter)

```
Étudiant soumet documents
    ↓
Email: "Documents reçus, en attente de validation"
    ↓
Agent admin valide/rejette
    ↓
Email: "Document validé" ou "Document rejeté - Raison: ..."
    ↓
Étudiant consulte son statut
```

## ✅ Points de Contrôle

- [x] Migration table créée
- [x] Formulaire d'upload sécurisé
- [x] Gestion des fichiers avec dossiers utilisateur
- [x] Validation côté client ET serveur
- [x] Interface agent admin
- [x] Interface étudiant pour suivi
- [x] Statuts et commentaires
- [x] Drag-and-drop support

## 🚀 Utilisation

### Pour un Étudiant

1. Accéder à [shared/register.php](shared/register.php)
2. Remplir le formulaire
3. Sélectionner "Étudiant"
4. Uploader relevé BAC et diplôme
5. Valider l'inscription
6. Message: "Documents reçus, en attente de validation"
7. Consulter le statut via [etudiant/documents.php](etudiant/documents.php)

### Pour un Agent Admin

1. Accéder à [agent_administratif/validation_documents.php](agent_administratif/validation_documents.php)
2. Consulter la liste des documents en attente
3. Cliquer sur "Voir" pour examiner le document
4. Cliquer sur "Valider" ou "Rejeter"
5. Ajouter commentaire/raison
6. Soumettre

## 📦 Fichiers Créés/Modifiés

| Fichier | Type | Statut |
|---------|------|--------|
| [shared/register.php](shared/register.php) | Modifié | ✅ |
| [agent_administratif/validation_documents.php](agent_administratif/validation_documents.php) | Créé | ✅ |
| [etudiant/documents.php](etudiant/documents.php) | Créé | ✅ |
| [database/migrate_documents_inscription.php](database/migrate_documents_inscription.php) | Créé | ✅ |

---

**Statut**: ✅ Complètement implémentée  
**Date**: 20 janvier 2026  
**Version**: 1.0
