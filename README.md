# plugin-multi-packs

Plugin WordPress/WooCommerce ajoutant une interface de commande par lots sous la fiche produit native.

## Fonctionnalités

- Réglages globaux WooCommerce > Multi-Packs pour :
  - définir des paliers par défaut ;
  - injecter du CSS/JS personnalisé sur les fiches produit contenant des packs.
- Meta-box produit **Gestion des Packs** avec groupes et lignes répétables.
- Modes de calcul **BOGO** et **Prix fixe**.
- Tableau front-end injecté après le formulaire d'achat WooCommerce natif.
- Ajout au panier en unités réelles pour préserver le stock du produit parent.

## Fichiers principaux

- `/plugin-multi-packs.php` : point d'entrée du plugin.
- `/includes/class-wc-multi-packs-plugin.php` : logique admin, front-end et panier.
- `/assets/css/wc-multi-packs.css` : styles front-end minimaux.
- `/assets/js/wc-multi-packs.js` : interactions quantité +/- par ligne.
