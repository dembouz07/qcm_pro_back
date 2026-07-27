<?php

return [
    'pillars' => [
        [
            'key' => 'confidence',
            'label' => 'Confiance',
            'questions' => [
                ['key' => 'confidence_1', 'label' => '1.1', 'body' => "Peux-tu me donner un exemple récent où tu as agi ou décidé sans attendre de validation ? Quel pourcentage de tes actions entre dans cette catégorie ?"],
                ['key' => 'confidence_2', 'label' => '1.2', 'body' => "Que penses-tu qu'il se passerait si tu te trompais en ayant agi en autonomie ? Quel niveau de risque perçois-tu ?"],
                ['key' => 'confidence_3', 'label' => '1.3', 'body' => "Sur une échelle personnelle, dirais-tu qu'on te fait confiance sur le résultat plus que sur la méthode ?"],
                ['key' => 'confidence_4', 'label' => '1.4', 'body' => "Comment évalues-tu ta capacité à dire non ou à challenger une décision de ta hiérarchie si tu la penses non adaptée ?"],
                ['key' => 'confidence_5', 'label' => '1.5', 'body' => "Quel est ton niveau d'aptitude à demander de l'aide sans aucune crainte ?"],
            ],
            'follow_ups' => [
                '0–6 mois — Qu’est-ce qui, dans ce que tu as vu ici, te donne ou non envie de prendre des initiatives ?',
                '1–3 ans — Où t’arrive-t-il de demander une validation dont tu n’as, au fond, pas vraiment besoin ?',
                '5 ans et + — Ta confiance ici a-t-elle été construite ou plutôt abîmée avec le temps ? Pourquoi ?',
                'Managers — Quelle décision prends-tu encore toi-même alors qu’elle pourrait être prise plus bas, plus vite ?',
            ],
        ],
        [
            'key' => 'execution',
            'label' => 'Exécution',
            'questions' => [
                ['key' => 'execution_1', 'label' => '2.1', 'body' => "Quelle est ton appétence à livrer rapidement, en version imparfaite mais utile ? À quoi renoncerais-tu spontanément pour cela ?"],
                ['key' => 'execution_2', 'label' => '2.2', 'body' => "Quel est ton niveau d’anticipation des points ou événements qui bloquent souvent le passage à l’action ?"],
                ['key' => 'execution_3', 'label' => '2.3', 'body' => "À quel niveau prends-tu en compte instantanément les changements pour t’adapter et poursuivre les actions ?"],
                ['key' => 'execution_4', 'label' => '2.4', 'body' => "Quel est ton niveau d’organisation pour prioriser quand tout semble urgent en même temps ?"],
                ['key' => 'execution_5', 'label' => '2.5', 'body' => "À quel niveau es-tu prêt à arrêter une action en cours parce qu’elle n’apporte plus assez de valeur ?"],
            ],
            'follow_ups' => [
                '0–6 mois — Quelle est la plus petite version d’un projet que tu pourrais livrer cette semaine ?',
                '1–3 ans — Ce blocage : est-ce un manque de clarté, la peur de l’échec ou une question de priorité ?',
                '5 ans et + — Que fais-tu aujourd’hui en deux semaines que tu faisais en deux mois il y a cinq ans ?',
                'Managers — Quand as-tu, pour la dernière fois, valorisé publiquement un échec rapide plutôt qu’un succès lent ?',
            ],
        ],
        [
            'key' => 'innovation',
            'label' => 'Innovation',
            'questions' => [
                ['key' => 'innovation_1', 'label' => '3.1', 'body' => "Suis-tu encore des processus ou agis-tu d’une certaine façon simplement parce que « ça a toujours été fait comme ça » ?"],
                ['key' => 'innovation_2', 'label' => '3.2', 'body' => "As-tu des idées que tu n’as pas encore osé partager ?"],
                ['key' => 'innovation_3', 'label' => '3.3', 'body' => "As-tu tendance à remettre en question quelque chose que tu avais toi-même mis en place ?"],
                ['key' => 'innovation_4', 'label' => '3.4', 'body' => "T’arrive-t-il d’aller volontairement chercher de l’inspiration en dehors de ton métier ou de ton secteur ?"],
                ['key' => 'innovation_5', 'label' => '3.5', 'body' => "Quel est ton niveau de contrariété quand une idée que tu proposes est challengée ou rejetée par l’équipe ?"],
            ],
            'follow_ups' => [
                '0–6 mois — Que remarques-tu ici avec un regard neuf, que les autres ne voient plus ?',
                '1–3 ans — Si tu avais carte blanche et zéro risque, que changerais-tu dans ta façon de travailler ?',
                '5 ans et + — Qu’est-ce que les nouveaux arrivants font ou disent qui te bouscule, et qu’en retiens-tu ?',
                'Managers — Que ferais-tu si un collaborateur innovait sans te demander la permission et échouait ?',
            ],
        ],
        [
            'key' => 'value_creation',
            'label' => 'Création de valeur',
            'questions' => [
                ['key' => 'value_creation_1', 'label' => '4.1', 'body' => "Quel est le niveau d’utilité de ta production hebdomadaire ? À qui sert-elle concrètement ?"],
                ['key' => 'value_creation_2', 'label' => '4.2', 'body' => "Quel pourcentage de tes tâches a eu un impact mesurable pour le client ou le business ?"],
                ['key' => 'value_creation_3', 'label' => '4.3', 'body' => "Quelle est la valeur stratégique de ton activité ? Si elle disparaissait demain, qui le remarquerait en premier et pourquoi ?"],
                ['key' => 'value_creation_4', 'label' => '4.4', 'body' => "Comment mesures-tu toi-même la réussite qualitative de ton travail au-delà du fait de l’avoir terminé ?"],
                ['key' => 'value_creation_5', 'label' => '4.5', 'body' => "Arrêtes-tu systématiquement de faire quelque chose parce que cela n’apporte plus de valeur à personne ? De quand date ta dernière décision de ce type ?"],
            ],
            'follow_ups' => [
                '0–6 mois — Comprends-tu déjà à qui profite directement ton travail au quotidien ?',
                '1–3 ans — Fais-tu la différence, dans ta semaine, entre être occupé et être utile ?',
                '5 ans et + — Comment transformes-tu ton expertise historique en valeur pour les clients d’aujourd’hui ?',
                'Managers — Ton équipe crée-t-elle de la valeur visible, ou produit-elle surtout de l’activité ?',
            ],
        ],
    ],
    'score_scale' => [
        ['score' => 1, 'label' => 'Résistance / absence', 'description' => 'Le comportement attendu n’est pas présent, voire activement évité.'],
        ['score' => 2, 'label' => 'Prise de conscience naissante', 'description' => 'Le collaborateur identifie l’enjeu mais n’agit pas encore.'],
        ['score' => 3, 'label' => 'Conscient et ponctuel', 'description' => 'Des actions existent mais restent irrégulières ou hésitantes.'],
        ['score' => 4, 'label' => 'Ancré', 'description' => 'Le comportement est régulier, assumé et visible dans le quotidien.'],
        ['score' => 5, 'label' => 'Exemplaire', 'description' => 'Le comportement est intégré et transmis aux autres.'],
    ],
    'interpretations' => [
        ['min' => 20, 'max' => 40, 'label' => 'Mindset émergent', 'recommendation' => 'Fort accompagnement nécessaire ; poser un cadre de sécurité et des victoires rapides.'],
        ['min' => 41, 'max' => 60, 'label' => 'Mindset en construction', 'recommendation' => 'Accompagnement ciblé sur les piliers les plus faibles ; objectifs courts et suivis.'],
        ['min' => 61, 'max' => 80, 'label' => 'Mindset ancré', 'recommendation' => 'Renforcement, autonomie accrue et responsabilités élargies.'],
        ['min' => 81, 'max' => 100, 'label' => 'Mindset exemplaire', 'recommendation' => 'Rôle de relais et d’ambassadeur du changement auprès des pairs.'],
    ],
];
