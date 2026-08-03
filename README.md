IA PHP
------

Cette inteligence artificiel est représenté par un chat sur une map.

Le premier palier est la représentation des bas instincs :
 - Recherche de nouriture
 - Etc...

La démonstration s'affiche en plein écran dans le terminal (php-tui, pas d'extension PHP à compiler).

Lancer le jeu : make run
Lancer les tests : make test
Voir les logs  : make logs
Autres cibles  : make help

L'écran est découpé en trois : la carte, un panneau à droite avec un onglet par IA
(estomac et objectifs en cours) plus la consommation mémoire en direct, et une
barre de contrôle du temps en bas.

Touches :
 - `espace` lecture/pause, `n` avance d'un tick, `-`/`+` vitesse
 - `t` time machine, puis `p`/`a` pour naviguer dans les snapshots
 - `tab` ou `1`..`9` pour changer d'onglet IA
 - `r` nouvelle map, `x` persiste la mémoire, `q` quitte

Le terrain est généré par bruit fractal : lacs dans les creux, forêts sur les
hauteurs, prairies et parterres de fleurs entre les deux. `--seed 42` rejoue
exactement la même carte.

Voici une capture

![Image](pictures/demo.png)
