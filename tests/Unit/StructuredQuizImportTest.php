<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Admin\QuizImportController;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

class StructuredQuizImportTest extends TestCase
{
    public function test_structured_text_supports_answer_keys_and_inline_markers(): void
    {
        $result = $this->parse(<<<'TEXT'
            1. Quelle est la capitale du Sénégal ?
            A) Thiès
            B) Dakar
            C) Saint-Louis
            Réponse : B

            2. Quels langages sont utilisés sur le Web ?
            A) JavaScript (bonne réponse)
            B) HTML (bonne)
            C) Cobol
            TEXT);

        $this->assertCount(2, $result['questions']);
        $this->assertSame([false, true, false], array_column($result['questions'][0]['choices'], 'is_correct'));
        $this->assertSame([true, true, false], array_column($result['questions'][1]['choices'], 'is_correct'));
        $this->assertSame('JavaScript', $result['questions'][1]['choices'][0]['body']);
    }

    public function test_structured_text_refuses_to_invent_a_correct_answer(): void
    {
        $this->expectException(ValidationException::class);
        $this->parse(<<<'TEXT'
            1. Question sans corrigé ?
            A) Premier choix
            B) Deuxième choix
            TEXT);
    }

    private function parse(string $text): array
    {
        $controller = new QuizImportController();
        $method = new ReflectionMethod($controller, 'parseStructuredText');

        return $method->invoke($controller, $text);
    }
}
