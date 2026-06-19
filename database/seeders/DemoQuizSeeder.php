<?php

namespace Database\Seeders;

use App\Models\SchoolClass;
use App\Models\Quiz;
use App\Models\Question;
use App\Models\Choice;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoQuizSeeder extends Seeder
{
    public function run(): void
    {
        $class = SchoolClass::where('name', 'Terminale A')->first();
        $admin = User::where('email', 'admin@example.com')->first();

        if (!$class || !$admin) {
            $this->command->warn('Classe ou admin non trouvé. Exécutez AdminSeeder d\'abord.');
            return;
        }

        // Créer un QCM de démonstration
        $quiz = Quiz::updateOrCreate(
            [
                'title' => 'QCM de Démonstration - Mathématiques',
                'school_class_id' => $class->id,
            ],
            [
                'description' => 'Un QCM de test pour vérifier que tout fonctionne correctement.',
                'created_by' => $admin->id,
                'starts_at' => now()->subHour(), // Déjà ouvert
                'ends_at' => now()->addDays(7), // Ouvert pendant 7 jours
                'is_published' => true,
            ]
        );

        // Question 1
        $question1 = Question::updateOrCreate(
            [
                'quiz_id' => $quiz->id,
                'body' => 'Combien font 2 + 2 ?',
            ],
            [
                'points' => 2,
                'order_index' => 1,
            ]
        );

        Choice::updateOrCreate(
            ['question_id' => $question1->id, 'body' => '3'],
            ['is_correct' => false]
        );

        Choice::updateOrCreate(
            ['question_id' => $question1->id, 'body' => '4'],
            ['is_correct' => true]
        );

        Choice::updateOrCreate(
            ['question_id' => $question1->id, 'body' => '5'],
            ['is_correct' => false]
        );

        // Question 2
        $question2 = Question::updateOrCreate(
            [
                'quiz_id' => $quiz->id,
                'body' => 'Quelle est la capitale du Sénégal ?',
            ],
            [
                'points' => 2,
                'order_index' => 2,
            ]
        );

        Choice::updateOrCreate(
            ['question_id' => $question2->id, 'body' => 'Paris'],
            ['is_correct' => false]
        );

        Choice::updateOrCreate(
            ['question_id' => $question2->id, 'body' => 'Dakar'],
            ['is_correct' => true]
        );

        Choice::updateOrCreate(
            ['question_id' => $question2->id, 'body' => 'Londres'],
            ['is_correct' => false]
        );

        // Question 3
        $question3 = Question::updateOrCreate(
            [
                'quiz_id' => $quiz->id,
                'body' => 'Combien y a-t-il de continents ?',
            ],
            [
                'points' => 1,
                'order_index' => 3,
            ]
        );

        Choice::updateOrCreate(
            ['question_id' => $question3->id, 'body' => '5'],
            ['is_correct' => false]
        );

        Choice::updateOrCreate(
            ['question_id' => $question3->id, 'body' => '6'],
            ['is_correct' => false]
        );

        Choice::updateOrCreate(
            ['question_id' => $question3->id, 'body' => '7'],
            ['is_correct' => true]
        );

        $this->command->info('QCM de démonstration créé avec succès !');
    }
}