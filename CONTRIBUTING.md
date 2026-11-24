# Contribuer à App Stats

Merci de votre intérêt pour **App Stats** !

## Principes du projet
1. **Site 100 % PHP pur** : aucune dépendance Composer, aucun Node.js ni Python côté serveur — compatible avec tous les hébergements PHP 8.0+ (mutualisé, Apache, Nginx, Docker).
2. **Zéro friction côté SDK** : chaque SDK tient dans **un seul fichier autonome** facile à copier-coller, sans dépendances lourdes.
3. **Respect de la vie privée** : pas de stockage d'adresse IP, pas de géolocalisation précise, identifiant anonyme haché, et support obligatoire de `APP_STATS_OPTOUT=1` / `DO_NOT_TRACK=1`.
4. **Tolérance aux pannes** : un SDK ne doit **jamais** faire planter ou bloquer l'application hôte si le serveur de stats est hors ligne.

## Lancer les tests en local
```bash
php tests/test_php.php
```

## Ajouter un nouveau langage SDK
1. Créez un dossier `sdks/<langage>/` avec un fichier autonome et un fichier d'exemple.
2. Envoyez un `POST` JSON vers `/api/v1/track` (ou `/api.php`) avec le champ `"sdk": "<langage>"`.
3. Ajoutez un test de compilation et d'exécution dans `.github/workflows/ci.yml`.
4. Documentez son utilisation dans `README.md`.
