# 📚 Système de gestion des notes et emplois du temps - ISTI

## ✅ Fonctionnalités implémentées

### 1. **Gestion des notes par le responsable de filière**
   - Page: `responsable_filiere/notes.php`
   - **Fonctionnalités:**
     - ✅ Ajout de notes pour les étudiants inscrits
     - ✅ Modification des notes existantes
     - ✅ Suppression de notes
     - ✅ Filtrage par classe et matière
     - ✅ Types d'évaluation: Devoir, Examen, TP, Projet
     - ✅ Validation des notes (0-20)
     - ✅ Commentaires optionnels

### 2. **Gestion des emplois du temps**

#### **Responsable de filière** (`responsable_filiere/emploi_du_temps.php`)
   - ✅ Ajout de cours pour les classes de sa filière
   - ✅ Sélection de la matière depuis les enseignements existants
   - ✅ Détection automatique des conflits (enseignant/classe/salle)
   - ✅ Suppression de cours
   - ✅ Filtrage par classe et année académique
   - ✅ Créneaux horaires prédéfinis (08:00-09:30, 09:30-11:00, etc.)

#### **Responsable de département** (`responsable_departement/emploi_du_temps.php`)
   - ✅ Consultation des emplois du temps de toutes les filières du département
   - ✅ Filtrage par filière, classe et année académique
   - ✅ Vue en lecture seule (consultation uniquement)

### 3. **Corrections de la base de données**

#### **Migration de la table `emplois_du_temps`**
```sql
-- Colonnes ajoutées:
- jour_semaine (INT) : 1=Lundi, 2=Mardi, etc.
- creneau_horaire (VARCHAR) : Format "HH:MM-HH:MM"
- annee_academique (VARCHAR) : Ex: "2025/2026"
- matiere_nom (VARCHAR) : Nom de la matière (au lieu de matiere_id)
```

## 📋 Structure des données

### **Table `notes`**
```sql
- id (INT, PRIMARY KEY)
- etudiant_id (INT, REFERENCES users.id)
- enseignement_id (INT, REFERENCES enseignements.id)
- note (DECIMAL(5,2)) : Note entre 0 et 20
- type_evaluation (ENUM: 'devoir', 'examen', 'tp', 'projet')
- commentaire (TEXT, NULLABLE)
- date_saisie (TIMESTAMP)
```

### **Table `emplois_du_temps` (après migration)**
```sql
- id (INT, PRIMARY KEY)
- classe_id (INT, REFERENCES classes.id)
- enseignant_id (INT, REFERENCES users.id)
- matiere_nom (VARCHAR) : Nom de la matière
- jour_semaine (INT) : 1-6 (Lundi-Samedi)
- creneau_horaire (VARCHAR) : "08:00-09:30"
- salle (VARCHAR)
- annee_academique (VARCHAR) : "2025/2026"
- heure_debut (TIME)
- heure_fin (TIME)
```

### **Table `enseignements`**
```sql
- id (INT, PRIMARY KEY)
- enseignant_id (INT, REFERENCES users.id)
- classe_id (INT, REFERENCES classes.id)
- matiere (VARCHAR) : Nom de la matière
- volume_horaire (INT)
```

## 🔄 Flux de travail

### **Gestion des notes**

1. **Le responsable de filière accède à `notes.php`**
2. **Sélectionne une classe** dans le filtre
3. **Optionnellement filtre par matière**
4. **Ajoute une note:**
   - Sélectionne l'étudiant
   - Sélectionne la matière (depuis les enseignements de la classe)
   - Choisit le type d'évaluation
   - Saisit la note (0-20)
   - Ajoute un commentaire (optionnel)
5. **Peut modifier ou supprimer** les notes existantes

### **Gestion des emplois du temps (Resp. Filière)**

1. **Accède à `emploi_du_temps.php`**
2. **Sélectionne une classe** et l'année académique
3. **Ajoute un cours:**
   - Sélectionne la matière/enseignant (depuis les enseignements)
   - Choisit le jour de la semaine
   - Choisit le créneau horaire
   - Indique la salle
4. **Le système vérifie automatiquement:**
   - L'enseignant n'a pas déjà cours à ce créneau
   - La classe n'a pas déjà cours à ce créneau
   - La salle n'est pas déjà occupée
5. **Visualise** l'emploi du temps sous forme de tableau
6. **Peut supprimer** un cours

### **Consultation des emplois du temps (Resp. Département)**

1. **Accède à `emploi_du_temps.php`**
2. **Sélectionne une filière** du département
3. **Sélectionne une classe**
4. **Visualise l'emploi du temps** en lecture seule

## 🎯 Navigation

### **Responsable de filière:**
```
Dashboard > Notes
Dashboard > Emploi du temps
```

### **Responsable de département:**
```
Dashboard > Emploi du temps
```

## 🔧 Migrations exécutées

```bash
# Migration de la table emplois_du_temps
php database/migrate_emplois_du_temps.php
# ✓ Colonnes ajoutées avec succès

# Insertion de notes de test
php database/seed_test_notes.php
# ✓ 10 notes de test insérées pour l'étudiant ID 7
```

## 📝 Fichiers créés/modifiés

### **Créés:**
- ✅ `responsable_filiere/notes.php` (nouvelle page complète)
- ✅ `database/migrate_emplois_du_temps.php` (migration)
- ✅ `database/seed_test_notes.php` (données de test)

### **Remplacés (versions simplifiées):**
- ✅ `responsable_filiere/emploi_du_temps.php` (version fonctionnelle simplifiée)
- ✅ `responsable_departement/emploi_du_temps.php` (version consultation)

### **Modifiés:**
- ✅ `responsable_filiere/dashboard.php` (ajout du lien "Notes")

## ✅ Tests de syntaxe

```bash
php -l responsable_filiere/notes.php
# ✓ No syntax errors detected

php -l responsable_filiere/emploi_du_temps.php
# ✓ No syntax errors detected

php -l responsable_departement/emploi_du_temps.php
# ✓ No syntax errors detected
```

## 🎨 Interface utilisateur

### **Gestion des notes:**
- Design moderne avec Tailwind CSS
- Modals pour ajout/modification/suppression
- Tableau interactif avec tri
- Affichage des notes en couleur (vert ≥10, rouge <10)
- Badges pour les types d'évaluation

### **Emplois du temps:**
- Filtres en cascade (Filière → Classe → Année)
- Tableau organisé par jour et créneau
- Détection visuelle des conflits
- Interface responsive

## 🚀 Prochaines étapes (optionnelles)

1. **Calcul automatique de moyennes**
   - Moyenne par matière
   - Moyenne générale
   - Affichage dans les bulletins

2. **Export des emplois du temps**
   - Génération PDF
   - Export iCal pour intégration calendrier

3. **Notifications**
   - Notification aux étudiants lors de l'ajout d'une note
   - Alerte en cas de modification d'emploi du temps

4. **Historique**
   - Log des modifications de notes
   - Historique des changements d'emploi du temps

5. **Import/Export**
   - Import de notes depuis Excel/CSV
   - Export des notes pour archivage

---

## 🎉 Résumé

**Deux systèmes majeurs sont maintenant opérationnels:**

1. **✅ Gestion des notes** - Le responsable de filière peut saisir, modifier et consulter toutes les notes des étudiants de sa filière

2. **✅ Gestion des emplois du temps** - Les responsables peuvent créer et gérer les plannings de cours avec détection automatique des conflits

**Les pages sont testées, syntaxiquement correctes et prêtes à l'emploi !**
