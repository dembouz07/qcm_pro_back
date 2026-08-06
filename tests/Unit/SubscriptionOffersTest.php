<?php

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\TestCase;

class SubscriptionOffersTest extends TestCase
{
    public function test_trainer_offer_contains_every_qcm_feature_for_five_thousand_fcfa(): void
    {
        $plan = User::subscriptionPlans()[User::PLAN_PREMIUM];

        $this->assertSame('Formateur', $plan['name']);
        $this->assertSame(5000, $plan['price']);
        $this->assertSame(50000, $plan['annual_price']);
        $this->assertEqualsCanonicalizing([
            'quiz_manual',
            'quiz_import',
            'quiz_progressive',
            'quiz_smart',
            'surveys',
            'wrong_question_stats',
        ], $plan['features']);
    }

    public function test_enterprise_offer_is_limited_to_soft_skills_for_twenty_five_thousand_fcfa(): void
    {
        $plan = User::subscriptionPlans()[User::PLAN_ENTERPRISE];

        $this->assertSame('Entreprise Essentiel', $plan['name']);
        $this->assertSame(25000, $plan['price']);
        $this->assertSame(25, $plan['employee_limit']);
        $this->assertEqualsCanonicalizing([
            'company_employees',
            'mindset_assessments',
            'mindset_progress',
        ], $plan['features']);
        $this->assertEmpty(array_intersect($plan['features'], User::PLAN_FEATURES[User::PLAN_PREMIUM]));
    }

    public function test_enterprise_team_offer_extends_capacity_without_mixing_qcm_features(): void
    {
        $plan = User::subscriptionPlans()[User::PLAN_ENTERPRISE_TEAM];

        $this->assertSame('Entreprise Équipe', $plan['name']);
        $this->assertSame(75000, $plan['price']);
        $this->assertSame(100, $plan['employee_limit']);
        $this->assertEqualsCanonicalizing(
            User::PLAN_FEATURES[User::PLAN_ENTERPRISE],
            $plan['features'],
        );
        $this->assertEmpty(array_intersect($plan['features'], User::PLAN_FEATURES[User::PLAN_PREMIUM]));
    }

    public function test_each_account_type_only_sees_its_available_paid_offer(): void
    {
        $student = new User(['role' => 'student']);
        $trainer = new User(['role' => 'admin']);
        $enterprise = new User(['role' => 'enterprise']);

        $this->assertSame([], $student->availableSubscriptionPlans());
        $this->assertSame([User::PLAN_PREMIUM], array_keys($trainer->availableSubscriptionPlans()));
        $this->assertSame(
            [User::PLAN_ENTERPRISE, User::PLAN_ENTERPRISE_TEAM],
            array_keys($enterprise->availableSubscriptionPlans()),
        );
    }
}
