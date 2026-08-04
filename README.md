IA PHP
------

Cette inteligence artificiel est représenté par un chat sur une map.

Le premier palier est la représentation des bas instincs :
 - Recherche de nouriture
 - Etc...

La démonstration s'affiche en plein écran dans le terminal (php-tui, pas d'extension PHP à compiler).

Lancer le jeu : make run   (dans le conteneur, sans son)
Avec le son    : make play  (PHP de la machine, voir plus bas)
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
 - `r` nouvelle map, `x` persiste la mémoire, `m` coupe le son, `q` quitte

La musique est une boucle 8 bits synthétisée en PHP — quatre voix, comme les
puces de l'époque : mélodie, arpège, basse et bruit — jouée par SDL2 via FFI,
donc sans extension à compiler. Le conteneur n'a pas de carte son, ce qui rend
`make run` muet : le son n'existe que sur `make play`, qui demande `php` et
`libsdl2` sur la machine (`brew install php sdl2`). Sans eux le jeu démarre
quand même, en silence.

Le terrain est généré par bruit fractal : lacs dans les creux, forêts sur les
hauteurs, prairies et parterres de fleurs entre les deux. `--seed 42` rejoue
exactement la même carte.

Voici une capture

![Image](pictures/demo.png)
