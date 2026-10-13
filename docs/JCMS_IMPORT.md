# Import JCMS (hors génotypage)

L’import est volontairement en deux temps : un export Access en lecture seule,
puis une revue des CSV avant leur chargement. Le fichier Access n’est jamais
modifié et les tables de génotypage (`Gene`, `Allele`, `Genotype`, demandes et
plaques) sont exclues.

## Export

Installez `mdbtools`, puis exécutez depuis la racine du projet :

```bash
tools/jcms_export.sh JCMS-fr8b.mdb jcms-export
```

Les fichiers produits couvrent les souches, pièces/cages et leur historique,
animaux, accouplements, portées, plans/tests/données expérimentales, usages
planifiés et niveaux sanitaires de pièces.

## Chargement dans la base locale

Après avoir appliqué la migration 013, copiez l’export dans le conteneur web
et lancez l’importateur. L’option `--apply` est obligatoire ; sans elle,
l’outil refuse d’écrire. Toute l’opération est une transaction : une erreur
annule l’import complet.

```bash
docker cp jcms-export myanimlab-local-web:/tmp/jcms-export
docker exec myanimlab-local-web php /var/www/html/tools/import_jcms.php \
  --apply /tmp/jcms-export JCMS-fr8b.mdb
```

L’importateur conserve l’identifiant JCMS affichable dans `mouse_id`, crée un
identifiant interne déterministe `JCMS-M…`, et enregistre toutes les
correspondances dans `jcms_import_map`. Il peut ainsi être audité et repris
sans ambiguïté.

La création groupée quotidienne ne crée pas de cages vides : dans
`Create Animals & Cages in Bulk`, saisissez par exemple **40**, sélectionnez
la ligne **SPD**, **male**, et **3 animaux par cage**. MyVivarium crée alors
les 40 animaux identifiés et les répartit automatiquement dans 14 cages.
Vous pouvez également fixer un minimum par cage : la création est bloquée si
aucune répartition ne peut respecter ce minimum et la capacité maximale.

## Correspondance à valider avant chargement

| JCMS | MyVivarium | Traitement |
|---|---|---|
| `Strain` | `strains`, `animal_lines` | Une ligne animale JCMS est créée par souche importée. |
| `Container`, `ContainerHistory`, `Room` | `cages`, `cage_lifecycle` | Numéros préfixés `JCMS-C` pour éviter toute collision ; salle et dernier statut conservés. |
| `Mouse` | `mice`, `animal_movements`, `animal_events` | L’identifiant original est conservé dans `mouse_id`; le placement JCMS devient un mouvement initial. |
| `Mating`, `MatingUnitLink` | `breeding`, `breeding_animals` | Sire/dam existants seulement ; aucune nouvelle souris n’est créée. |
| `Litter` | `litters`, `litter_events` | Dates, effectifs, sexe et sevrage sont conservés. |
| `ExpPlan`, `ExpTest`, `ExpData` | `trials`, `protocols`, `experiments`, suivis | Les colonnes libres `d1…d30` deviennent des observations textuelles datées. |
| `MouseUsage` | `animal_use_schedule` | Usage prévu/réalisé, date et commentaire. |
| `HealthLevelHistory` | `room_health_history` | Historique sanitaire par salle. |

## Sécurité et reprise

Appliquez d’abord `database/migrations/013_jcms_import_and_scheduling.sql`
sur une copie de la base. Les tables `jcms_import_runs` et `jcms_import_map`
sont prévues pour tracer chaque source et empêcher les doublons lors des
reprises. Vérifiez les identifiants non vides, les statuts de vie et les
numéros de cages dans les CSV avant toute insertion en production.
