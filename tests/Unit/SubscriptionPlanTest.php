<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

class SubscriptionPlanTest extends TestCase
{
    public function test_plan_prices_match_the_three_offers(): void
    {
        $plans = User::subscriptionPlans();

        $this->assertSame(0, $plans[User::PLAN_FREE]['price']);
        $this->assertSame(3000, $plans[User::PLAN_ESSENTIAL]['price']);
        $this->assertSame(5000, $plans[User::PLAN_PREMIUM]['price']);
        $this->assertSame(50000, $plans[User::PLAN_PREMIUM]['annual_price']);
        $this->assertSame(25000, $plans[User::PLAN_ENTERPRISE]['price']);
        $this->assertSame(75000, $plans[User::PLAN_ENTERPRISE_TEAM]['price']);
    }

    public function test_free_plan_only_exposes_the_three_qcm_creation_modes(): void
    {
        $user = new User([
            'role' => 'admin',
            'subscription_plan' => User::PLAN_FREE,
            'subscription_status' => 'active',
        ]);

        $this->assertSame(User::PLAN_FREE, $user->effectiveSubscriptionPlan());
        $this->assertTrue($user->hasFeature('quiz_manual'));
        $this->assertTrue($user->hasFeature('quiz_import'));
        $this->assertTrue($user->hasFeature('quiz_progressive'));
        $this->assertFalse($user->hasFeature('quiz_smart'));
        $this->assertFalse($user->hasFeature('surveys'));
        $this->assertFalse($user->hasFeature('wrong_question_stats'));
    }

    public function test_essential_plan_excludes_surveys_and_wrong_question_stats(): void
    {
        $user = new class extends User {
            public function isPaidSubscriptionActive(): bool
            {
                return true;
            }
        };
        $user->fill([
            'role' => 'admin',
            'subscription_plan' => User::PLAN_ESSENTIAL,
            'subscription_status' => 'active',
        ]);

        $this->assertSame(User::PLAN_ESSENTIAL, $user->effectiveSubscriptionPlan());
        $this->assertTrue($user->hasFeature('quiz_smart'));
        $this->assertFalse($user->hasFeature('surveys'));
        $this->assertFalse($user->hasFeature('wrong_question_stats'));
    }

    public function test_premium_plan_contains_every_restricted_feature(): void
    {
        $user = new class extends User {
            public function isPaidSubscriptionActive(): bool
            {
                return true;
            }
        };
        $user->fill([
            'role' => 'admin',
            'subscription_plan' => User::PLAN_PREMIUM,
            'subscription_status' => 'active',
        ]);

        $this->assertSame(User::PLAN_PREMIUM, $user->effectiveSubscriptionPlan());
        $this->assertTrue($user->hasFeature('quiz_smart'));
        $this->assertTrue($user->hasFeature('surveys'));
        $this->assertTrue($user->hasFeature('wrong_question_stats'));
    }

    public function test_expired_paid_plan_falls_back_to_free_access(): void
    {
        $user = new class extends User {
            public function isPaidSubscriptionActive(): bool
            {
                return false;
            }
        };
        $user->fill([
            'role' => 'admin',
            'subscription_plan' => User::PLAN_PREMIUM,
            'subscription_status' => 'active',
        ]);

        $this->assertSame(User::PLAN_FREE, $user->effectiveSubscriptionPlan());
        $this->assertTrue($user->hasFeature('quiz_manual'));
        $this->assertFalse($user->hasFeature('surveys'));
    }
}
