-- Script de migration pour ajouter les colonnes manquantes à la table users
-- À exécuter après avoir appliqué les changements au schéma principal

-- Ajouter les colonnes manquantes à la table users
ALTER TABLE users ADD COLUMN matricule VARCHAR(20) UNIQUE;
ALTER TABLE users ADD COLUMN date_naissance DATE;
ALTER TABLE users ADD COLUMN telephone VARCHAR(20);
ALTER TABLE users ADD COLUMN role ENUM('admin', 'resp_dept', 'resp_filiere', 'resp_classe', 'etudiant', 'enseignant', 'agent_admin') DEFAULT 'etudiant';
ALTER TABLE users ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE users ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Créer un index sur le matricule pour de meilleures performances
CREATE INDEX idx_users_matricule ON users(matricule);

-- Mettre à jour les rôles existants si nécessaire (à adapter selon vos données)
-- UPDATE users SET role = 'admin' WHERE email = 'admin@isti.edu';