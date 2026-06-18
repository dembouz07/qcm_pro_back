<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class QuizConverterController extends Controller
{
    public function convert(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:doc,docx,pdf', 'max:10240'],
        ]);

        $extension = strtolower($request->file('file')->getClientOriginalExtension());

        try {
            $text = match ($extension) {
                'pdf' => $this->extractFromPdf($request),
                'doc', 'docx' => $this->extractFromWord($request),
                default => throw new \Exception('Format non supporté')
            };

            $questions = $this->parseText($text);

            // Générer le JSON
            $json = [
                'title' => $request->input('title', 'QCM Importé'),
                'description' => $request->input('description', ''),
                'school_class_id' => $request->input('school_class_id', 1),
                'starts_at' => $request->input('starts_at', now()->format('Y-m-d H:i:s')),
                'ends_at' => $request->input('ends_at'),
                'questions' => $questions
            ];

            return response()->json([
                'success' => true,
                'json' => $json,
                'preview' => [
                    'total_questions' => count($questions),
                    'total_choices' => array_sum(array_map(fn($q) => count($q['choices']), $questions)),
                    'questions_preview' => array_slice($questions, 0, 3)
                ]
            ]);

        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'file' => 'Erreur de conversion: ' . $e->getMessage()
            ]);
        }
    }

    private function extractFromWord(Request $request): string
    {
        $path = $request->file('file')->getRealPath();
        $phpWord = WordIOFactory::load($path);
        
        $text = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $class = get_class($element);
                
                if (method_exists($element, 'getText')) {
                    $text .= $element->getText() . "\n";
                }
                elseif (method_exists($element, 'getElements')) {
                    foreach ($element->getElements() as $childElement) {
                        if (method_exists($childElement, 'getText')) {
                            $text .= $childElement->getText();
                        } elseif (method_exists($childElement, 'getContent')) {
                            $text .= $childElement->getContent();
                        }
                    }
                    $text .= "\n";
                }
                elseif ($class === 'PhpOffice\PhpWord\Element\Table') {
                    foreach ($element->getRows() as $row) {
                        foreach ($row->getCells() as $cell) {
                            foreach ($cell->getElements() as $cellElement) {
                                if (method_exists($cellElement, 'getText')) {
                                    $text .= $cellElement->getText() . " ";
                                }
                            }
                        }
                        $text .= "\n";
                    }
                }
            }
        }
        
        if (empty(trim($text))) {
            throw new \Exception('Le document Word semble vide');
        }
        
        return $text;
    }

    private function extractFromPdf(Request $request): string
    {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($request->file('file')->getRealPath());
        return $pdf->getText();
    }

    private function parseText(string $text): array
    {
        $questions = [];
        $lines = explode("\n", $text);
        $currentQuestion = null;
        $currentChoices = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Ignorer les titres
            if (mb_strlen($line) > 10 && strtoupper($line) === $line && !str_contains($line, '?')) {
                continue;
            }
            
            // Détecter une question
            if (str_contains($line, '?')) {
                if ($currentQuestion !== null && !empty($currentChoices)) {
                    $questions[] = $this->finalizeQuestion($currentQuestion, $currentChoices);
                }
                
                $cleanQuestion = preg_replace('/^\d+[\.\)\:]?\s*/', '', $line);
                $cleanQuestion = preg_replace('/^Question\s+\d+\s*[\:\-\.]?\s*/i', '', $cleanQuestion);
                $currentQuestion = trim($cleanQuestion);
                $currentChoices = [];
            }
            else {
                $choice = $this->parseChoice($line);
                if ($choice !== null && $currentQuestion !== null) {
                    $currentChoices[] = $choice;
                }
            }
        }
        
        if ($currentQuestion !== null && !empty($currentChoices)) {
            $questions[] = $this->finalizeQuestion($currentQuestion, $currentChoices);
        }
        
        if (empty($questions)) {
            throw new \Exception('Aucune question détectée. Format: "1. Question ?" puis "A. Choix" ou "[x] Choix"');
        }
        
        foreach ($questions as $index => $question) {
            if (count($question['choices']) < 2) {
                throw new \Exception('Question ' . ($index + 1) . ': minimum 2 choix requis');
            }
        }
        
        return $questions;
    }
    
    private function parseChoice(string $line): ?array
    {
        // Pattern 1: [x] ou [ ]
        if (preg_match('/^\[([x\s\*✓☑])\]\s*(.+)$/i', $line, $matches)) {
            return [
                'body' => trim($matches[2]),
                'is_correct' => preg_match('/[x\*✓☑]/i', $matches[1]) ? true : false
            ];
        }
        
        // Pattern 2: A. ou A)
        if (preg_match('/^([A-Z])[\.\)]\s*(.+)$/i', $line, $matches)) {
            return [
                'body' => trim($matches[2]),
                'is_correct' => false
            ];
        }
        
        // Pattern 3: ☑ ou ☐
        if (preg_match('/^[☑☐]\s*(.+)$/u', $line, $matches)) {
            return [
                'body' => trim($matches[1]),
                'is_correct' => str_starts_with($line, '☑')
            ];
        }
        
        // Pattern 4: tiret ou bullet
        if (preg_match('/^[\-\•\*]\s*(.+)$/', $line, $matches)) {
            return [
                'body' => trim($matches[1]),
                'is_correct' => false
            ];
        }
        
        return null;
    }
    
    private function finalizeQuestion(string $questionText, array $choices): array
    {
        $hasCorrect = false;
        foreach ($choices as $choice) {
            if ($choice['is_correct']) {
                $hasCorrect = true;
                break;
            }
        }
        
        if (!$hasCorrect && !empty($choices)) {
            $choices[0]['is_correct'] = true;
        }
        
        return [
            'body' => $questionText,
            'points' => 1,
            'choices' => $choices
        ];
    }
}
