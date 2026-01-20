# Système de validation des documents d'inscription - Guide complet

## 📋 Vue d'ensemble du workflow

Le système implémente un processus de validation en 3 étapes pour les documents d'inscription (relevé BAC et diplôme BAC) :

### Étape 1 : Inscription de l'étudiant
- L'étudiant s'inscrit via `shared/register.php`
- Il upload ses documents (relevé BAC + diplôme BAC)
- Les documents reçoivent automatiquement le statut **"soumis"**
- L'étudiant peut se connecter mais n'a pas encore d'inscription à une classe

### Étape 2 : Validation par l'agent administratif
- L'agent voit les documents en attente sur son dashboard
- Il accède à `agent_administratif/validation_documents.php`
- Pour chaque document, il peut :
  * **Valider** : Le document est accepté (statut = "valide")
  * **Rejeter** : Le document est refusé avec commentaire (statut = "rejete")

### Étape 3 : Création de l'inscription
- L'agent accède à `agent_administratif/nouvelles_inscriptions.php`
- Il voit la liste des étudiants avec **tous leurs documents validés**
- Il peut créer l'inscription à une classe pour ces étudiants

## 📁 Fichiers modifiés/créés

### Fichiers modifiés :
1. **shared/register.php**
   - Ajout du statut 'soumis' lors de l'upload des documents
   - Message spécifique pour les étudiants sur l'attente de validation

2. **agent_administratif/dashboard.php**
   - Affichage des documents en attente de validation
   - Carte "Prêts inscription" avec le nombre d'étudiants validés
   - Liens vers validation_documents.php et nouvelles_inscriptions.php

### Nouveaux fichiers créés :
1. **etudiant/statut_inscription.php** (338 lignes)
   - Interface pour que l'étudiant voie le statut de ses documents
   - Affiche les documents soumis, validés ou rejetés
   - Indication claire si l'inscription est complète ou en attente

2. **agent_administratif/nouvelles_inscriptions.php** (343 lignes)
   - Liste des étudiants avec documents validés et sans inscription
   - Modal pour créer une inscription (choix de classe + année)
   - Vérification qu'un étudiant n'a qu'une inscription par année

3. **check_documents_structure.php** (39 lignes)
   - Script de vérification de la structure de la table documents_inscription

## 🔄 Flux de données

```
ÉTUDIANT                    AGENT ADMIN                 SYSTÈME
   |                            |                          |
   |--- S'inscrit + Upload ---->|                          |
   |                            |                          |
   |                            |<--- Documents en         |
   |                            |     attente (dashboard)  |
   |                            |                          |
   |                            |--- Valide/Rejette ------>|
   |                            |                          |
   |<--- Voir statut ---------->|                          |
   | (statut_inscription.php)   |                          |
   |                            |                          |
   |                            |<--- Étudiants prêts      |
   |                            | (nouvelles_inscriptions) |
   |                            |                          |
   |                            |--- Crée inscription ---->|
   |                            |                          |
   |<--- Inscription confirmée -|                          |
```

## 🗄️ Structure de la base de données

### Table : documents_inscription
- **statut** : enum('soumis', 'valide', 'rejete')
  - `soumis` : Document uploadé, en attente de validation
  - `valide` : Document accepté par l'agent
  - `rejete` : Document refusé (avec commentaire)

## 🎯 Points d'accès pour l'agent administratif

### Navigation dans le menu :
1. **Dashboard** : Vue d'ensemble + stats + documents en attente
2. **Validation documents** : Valider/rejeter les documents soumis
3. **Nouvelles inscriptions** : Créer inscriptions pour étudiants validés
4. **Toutes les inscriptions** : Voir et gérer toutes les inscriptions

### Dashboard - Statistiques affichées :
- Total inscriptions
- Inscrits
- Réinscrits
- Abandons
- **⭐ Prêts inscription** (nouveau) : Étudiants avec docs validés

## 🎓 Points d'accès pour l'étudiant

### Navigation dans le menu :
1. **Dashboard** : Vue d'ensemble de son inscription
2. **Statut inscription** (nouveau) : Voir le statut de validation des documents
3. **Mes documents** : Voir tous les documents uploadés

### Statut inscription - Informations affichées :
- État global du dossier (4 cas possibles) :
  * ✅ Inscription complète
  * 🔵 Documents validés - En attente d'inscription
  * 🟠 Documents manquants
  * 🟡 Validation en cours
  
- Liste détaillée des documents avec :
  * Nom du document
  * Date de soumission
  * Statut (soumis/validé/rejeté)
  * Commentaire de l'agent (en cas de rejet)

## ✅ Avantages du système

1. **Contrôle qualité** : L'agent vérifie chaque document avant l'inscription
2. **Traçabilité** : Historique complet de qui a validé quoi et quand
3. **Transparence** : L'étudiant voit en temps réel où en est son dossier
4. **Sécurité** : Pas d'inscription possible sans validation des documents
5. **Workflow clair** : Processus en 3 étapes bien défini

## 🚀 Pour tester le système

### Test complet du workflow :

1. **Créer un nouveau compte étudiant** :
   ```
   Aller sur shared/register.php
   Remplir le formulaire avec rôle "Étudiant"
   Uploader 2 documents (relevé + diplôme BAC)
   ```

2. **Se connecter en tant qu'agent administratif** :
   ```
   Aller sur agent_administratif/dashboard.php
   Vérifier la carte "Prêts inscription" (devrait être 0)
   Vérifier la section "Documents en attente" (devrait voir 2 docs)
   ```

3. **Valider les documents** :
   ```
   Cliquer sur "Voir et valider" ou aller sur validation_documents.php
   Valider les 2 documents du nouvel étudiant
   ```

4. **Créer l'inscription** :
   ```
   Aller sur nouvelles_inscriptions.php
   Voir l'étudiant dans la liste
   Cliquer sur "Inscrire"
   Choisir une classe et confirmer
   ```

5. **Vérifier côté étudiant** :
   ```
   Se connecter avec le compte étudiant
   Aller sur statut_inscription.php
   Voir que tous les documents sont validés
   Voir que l'inscription est complète
   ```

## 📝 Notes importantes

- Un étudiant ne peut avoir qu'**une inscription par année académique**
- Il faut au minimum **2 documents validés** (relevé + diplôme) pour permettre l'inscription
- Les documents rejetés peuvent être re-soumis (l'étudiant doit contacter l'admin)
- L'inscription_id est automatiquement lié aux documents lors de la création de l'inscription

## 🐛 Dépannage

### L'étudiant n'apparaît pas dans "Nouvelles inscriptions" :
- Vérifier que les 2 documents sont validés (statut = 'valide')
- Vérifier qu'il n'a pas déjà une inscription pour l'année en cours

### Le dashboard ne montre pas les documents en attente :
- Vérifier que les documents ont le statut 'soumis'
- Vérifier la requête SQL dans dashboard.php (ligne ~50)

### Erreur lors de la création d'inscription :
- Vérifier que la classe_id existe
- Vérifier le format de l'année académique (ex: 2025/2026)
